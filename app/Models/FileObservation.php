<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['watched_folder_id', 'path_hash', 'file_path', 'file_size', 'modified_at', 'stable_scans', 'last_seen_at'])]
class FileObservation extends Model
{
    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'modified_at' => 'integer',
            'stable_scans' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WatchedFolder, $this>
     */
    public function watchedFolder(): BelongsTo
    {
        return $this->belongsTo(WatchedFolder::class);
    }
}
