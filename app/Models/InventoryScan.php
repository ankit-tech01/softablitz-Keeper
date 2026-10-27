<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['roots', 'status', 'files_count', 'total_bytes', 'inaccessible_directories', 'error_message', 'started_at', 'completed_at'])]
class InventoryScan extends Model
{
    protected function casts(): array
    {
        return [
            'roots' => 'array',
            'files_count' => 'integer',
            'total_bytes' => 'integer',
            'inaccessible_directories' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<InventoryFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(InventoryFile::class);
    }
}
