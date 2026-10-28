<?php

namespace App\Services;

use App\Models\LedgerEntry;
use Illuminate\Support\Facades\File;
use RuntimeException;

class KeeperTrashService
{
    /**
     * @return array<int, array{path: string, name: string, size: int, modified_at: int, ledger_entry_id: int|null}>
     */
    public function files(): array
    {
        $trashPath = storage_path('app/private/keeper-trash');
        $trashRoot = $this->trashRoot();

        if ($trashRoot === null) {
            return [];
        }

        $paths = [];

        foreach (File::allFiles($trashRoot, true) as $file) {
            $paths[] = $file->getPathname();
        }

        $ledgerEntries = LedgerEntry::query()
            ->where('action', 'trash')
            ->where('status', 'completed')
            ->whereNull('undone_at')
            ->whereNotNull('destination_path')
            ->get(['id', 'destination_path']);

        foreach ($ledgerEntries as $entry) {
            $paths[] = $entry->destination_path;
        }

        $entryIdsByPath = [];

        foreach ($ledgerEntries as $entry) {
            $resolvedPath = $this->resolveTrashFile($entry->destination_path);

            if ($resolvedPath !== null) {
                $entryIdsByPath[$this->pathKey($resolvedPath)] = $entry->id;
            }
        }

        $files = [];

        foreach (array_unique($paths) as $path) {
            $resolvedPath = $this->resolveTrashFile($path);

            if ($resolvedPath === null) {
                continue;
            }

            $size = filesize($resolvedPath);
            $modifiedAt = filemtime($resolvedPath);

            if ($size === false || $modifiedAt === false) {
                continue;
            }

            $files[$this->pathKey($resolvedPath)] = [
                'path' => $resolvedPath,
                'name' => basename($resolvedPath),
                'size' => $size,
                'modified_at' => $modifiedAt,
                'ledger_entry_id' => $entryIdsByPath[$this->pathKey($resolvedPath)] ?? null,
            ];
        }

        return array_values($files);
    }

    public function deleteFile(string $path): void
    {
        $resolvedPath = $this->resolveTrashFile($path);

        if ($resolvedPath === null) {
            throw new RuntimeException('This file is not available in Keeper trash.');
        }

        if (! File::delete($resolvedPath)) {
            throw new RuntimeException('The trash file could not be deleted. Check its permissions and try again.');
        }
    }

    public function empty(): int
    {
        $trashPath = storage_path('app/private/keeper-trash');
        $files = $this->files();

        foreach ($files as $file) {
            $this->deleteFile($file['path']);
        }

        if (is_dir($trashPath) && (! File::cleanDirectory($trashPath)
            || File::allFiles($trashPath, true) !== []
            || File::directories($trashPath, []) !== [])) {
            throw new RuntimeException('Keeper trash could not be fully emptied. Check the file permissions and try again.');
        }

        return count($files);
    }

    private function trashRoot(): ?string
    {
        $trashPath = storage_path('app/private/keeper-trash');

        if (file_exists($trashPath) && is_link($trashPath)) {
            throw new RuntimeException('Keeper trash is not a valid directory.');
        }

        if (! file_exists($trashPath)) {
            return null;
        }

        if (! is_dir($trashPath)) {
            throw new RuntimeException('Keeper trash is not a valid directory.');
        }

        return realpath($trashPath) ?: null;
    }

    private function resolveTrashFile(string $path): ?string
    {
        $trashRoot = $this->trashRoot();
        $resolvedPath = realpath($path);

        if ($trashRoot === null || $resolvedPath === false || ! is_file($resolvedPath)) {
            return null;
        }

        $normalizedRoot = rtrim($this->pathKey($trashRoot), '/').'/';
        $normalizedPath = $this->pathKey($resolvedPath);

        return str_starts_with($normalizedPath, $normalizedRoot) ? $resolvedPath : null;
    }

    private function pathKey(string $path): string
    {
        $normalizedPath = str_replace('\\', '/', $path);

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalizedPath) : $normalizedPath;
    }
}
