<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['inventory_scan_id', 'path', 'name', 'extension', 'size', 'created_at_timestamp', 'modified_at_timestamp'])]
class InventoryFile extends Model
{
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'created_at_timestamp' => 'integer',
            'modified_at_timestamp' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<InventoryScan, $this>
     */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(InventoryScan::class, 'inventory_scan_id');
    }
}
