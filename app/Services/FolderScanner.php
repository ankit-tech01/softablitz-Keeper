<?php

namespace App\Services;

use App\Models\FileObservation;
use App\Models\WatchedFolder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FolderScanner
{
    /**
     * @return array<int, array{path: string, size: int, modified_at: int}>
     */
    public function scan(WatchedFolder $folder): array
    {
        if (! is_dir($folder->path)) {
            $folder->update(['is_active' => false]);
            Log::warning('A watched folder is unavailable; scanning will resume when it returns.', [
                'folder_id' => $folder->id,
                'path' => $folder->path,
            ]);

            return [];
        }

        $stableFiles = [];
        $seenHashes = [];

        try {
            foreach (new \DirectoryIterator($folder->path) as $file) {
                if ($file->isDot() || ! $file->isFile() || $file->isLink()) {
                    continue;
                }

                $path = $file->getRealPath();

                if ($path === false || $this->isTemporaryFile($file->getFilename())) {
                    continue;
                }

                $pathHash = hash('sha256', $this->normalizePath($path));
                $seenHashes[] = $pathHash;
                $size = $file->getSize();
                $modifiedAt = $file->getMTime();

                $observation = FileObservation::query()->firstOrNew([
                    'watched_folder_id' => $folder->id,
                    'path_hash' => $pathHash,
                ]);

                if ($observation->exists
                    && $observation->file_size === $size
                    && $observation->modified_at === $modifiedAt) {
                    $observation->stable_scans = min($observation->stable_scans + 1, 255);
                } else {
                    $observation->stable_scans = 1;
                }

                $observation->fill([
                    'file_path' => $path,
                    'file_size' => $size,
                    'modified_at' => $modifiedAt,
                    'last_seen_at' => Carbon::now(),
                ]);
                $observation->save();

                if ($observation->stable_scans >= 2) {
                    $stableFiles[] = [
                        'path' => $path,
                        'size' => $size,
                        'modified_at' => $modifiedAt,
                    ];
                }
            }
        } catch (\UnexpectedValueException $exception) {
            Log::warning('A watched folder could not be scanned.', [
                'folder_id' => $folder->id,
                'path' => $folder->path,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        DB::transaction(function () use ($folder, $seenHashes): void {
            $folder->update([
                'is_active' => true,
                'last_scanned_at' => Carbon::now(),
            ]);

            if ($seenHashes === []) {
                $folder->observations()->delete();

                return;
            }

            $folder->observations()->whereNotIn('path_hash', $seenHashes)->delete();
        });

        return $stableFiles;
    }

    private function isTemporaryFile(string $filename): bool
    {
        return preg_match('/\.(tmp|part|crdownload)$/i', $filename) === 1
            || str_starts_with($filename, '.');
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }
}
