<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'path', 'path_hash', 'is_active', 'last_scanned_at'])]
class WatchedFolder extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_scanned_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<FileObservation, $this>
     */
    public function observations(): HasMany
    {
        return $this->hasMany(FileObservation::class);
    }

    /**
     * @return HasMany<FileExecution, $this>
     */
    public function executions(): HasMany
    {
        return $this->hasMany(FileExecution::class);
    }
}
