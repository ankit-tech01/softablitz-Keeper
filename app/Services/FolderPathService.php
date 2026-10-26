<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class FolderPathService
{
    public function resolveWatchedFolder(string $path, string $errorKey = 'folder_path'): string
    {
        $resolvedPath = realpath(trim($path));

        if ($resolvedPath === false || ! is_dir($resolvedPath)) {
            throw ValidationException::withMessages([
                $errorKey => 'Choose an existing folder on this device.',
            ]);
        }

        $normalizedPath = rtrim(str_replace('\\', '/', $resolvedPath), '/');

        if ($normalizedPath === '' && DIRECTORY_SEPARATOR !== '\\') {
            $normalizedPath = '/';
        }
        $comparisonPath = strtolower(
            DIRECTORY_SEPARATOR === '\\'
                ? (preg_replace('/^[a-z]:/i', '', $normalizedPath) ?? $normalizedPath)
                : $normalizedPath,
        );
        $protectedPaths = DIRECTORY_SEPARATOR === '\\'
            ? ['/', '/windows', '/program files', '/program files (x86)', '/programdata']
            : ['/', '/bin', '/boot', '/dev', '/etc', '/lib', '/lib64', '/proc', '/root', '/run', '/sbin', '/sys', '/usr', '/var'];

        foreach ($protectedPaths as $protectedPath) {
            $isDriveRoot = DIRECTORY_SEPARATOR === '\\'
                && preg_match('/^[a-z]:$/i', $normalizedPath) === 1;
            $isProtectedPath = $comparisonPath === $protectedPath
                || str_starts_with($comparisonPath, $protectedPath.'/');

            if ($isDriveRoot || $isProtectedPath) {
                throw ValidationException::withMessages([
                    $errorKey => 'System and root folders cannot be watched.',
                ]);
            }
        }

        return $resolvedPath;
    }
}
