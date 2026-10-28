<?php

namespace App\Services;

use App\Models\InventoryScan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SplFileInfo;
use UnexpectedValueException;

class DeviceInventoryScanner
{
    /**
     * @param  array<int, string>  $roots
     */
    public function scan(InventoryScan $scan, array $roots): void
    {
        $scan->update([
            'status' => 'scanning',
            'roots' => $roots,
            'started_at' => now(),
            'error_message' => null,
        ]);

        $directories = array_reverse($roots);
        $batch = [];
        $filesCount = 0;
        $totalBytes = 0;
        $inaccessibleDirectories = 0;

        while ($directories !== []) {
            $directory = array_pop($directories);

            try {
                foreach (new \DirectoryIterator($directory) as $entry) {
                    if ($entry->isDot() || $entry->isLink()) {
                        continue;
                    }

                    if ($entry->isDir()) {
                        $childPath = $entry->getPathname();

                        if ($childPath !== $directory) {
                            $directories[] = $childPath;
                        }

                        continue;
                    }

                    if (! $entry->isFile()) {
                        continue;
                    }

                    $file = $this->fileRecord($entry);

                    if ($file === null) {
                        continue;
                    }

                    $batch[] = [
                        'inventory_scan_id' => $scan->id,
                        ...$file,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    $filesCount++;
                    $totalBytes += $file['size'];

                    if (count($batch) >= 100) {
                        $this->saveBatch($batch);
                        $batch = [];
                        $scan->update([
                            'files_count' => $filesCount,
                            'total_bytes' => $totalBytes,
                        ]);
                    }
                }
            } catch (UnexpectedValueException $exception) {
                $inaccessibleDirectories++;
                Log::warning('A directory could not be read during the device inventory scan.', [
                    'scan_id' => $scan->id,
                    'path' => $directory,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($batch !== []) {
            $this->saveBatch($batch);
        }

        DB::transaction(function () use ($scan, $filesCount, $totalBytes, $inaccessibleDirectories): void {
            $scan->update([
                'status' => 'completed',
                'files_count' => $filesCount,
                'total_bytes' => $totalBytes,
                'inaccessible_directories' => $inaccessibleDirectories,
                'completed_at' => now(),
            ]);

            InventoryScan::query()->whereKeyNot($scan->id)->delete();
        });
    }

    /**
     * @return array{path: string, name: string, extension: string, size: int, created_at_timestamp: int, modified_at_timestamp: int}|null
     */
    private function fileRecord(SplFileInfo $file): ?array
    {
        $path = $file->getRealPath();
        $size = $file->getSize();
        $createdAt = $file->getCTime();
        $modifiedAt = $file->getMTime();

        if ($path === false || $size === false || $createdAt === false || $modifiedAt === false) {
            return null;
        }

        return [
            'path' => $path,
            'name' => $file->getFilename(),
            'extension' => strtolower($file->getExtension()),
            'size' => $size,
            'created_at_timestamp' => $createdAt,
            'modified_at_timestamp' => $modifiedAt,
        ];
    }

    /**
     * @param  array<int, array<string, int|string>>  $batch
     */
    private function saveBatch(array $batch): void
    {
        DB::table('inventory_files')->insert($batch);
    }
}
