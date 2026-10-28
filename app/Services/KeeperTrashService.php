<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;

class KeeperTrashService
{
    public function empty(): int
    {
        $trashPath = storage_path('app/private/keeper-trash');

        if (! is_dir($trashPath)) {
            return 0;
        }

        if (is_link($trashPath)) {
            throw new RuntimeException('Keeper trash is not a valid directory.');
        }

        $files = File::allFiles($trashPath, true);

        foreach ($files as $file) {
            if (! File::delete($file->getPathname())) {
                throw new RuntimeException('A trash file could not be deleted. Check the file permissions and try again.');
            }
        }

        if (! File::cleanDirectory($trashPath)
            || File::allFiles($trashPath, true) !== []
            || File::directories($trashPath, []) !== []) {
            throw new RuntimeException('Keeper trash could not be fully emptied. Check the file permissions and try again.');
        }

        return count($files);
    }
}
