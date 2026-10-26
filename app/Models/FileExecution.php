<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['unique_key', 'watched_folder_id', 'automation_rule_id', 'file_path', 'modified_at', 'checksum', 'status', 'attempts', 'last_error', 'sweep_id', 'processed_at'])]
class FileExecution extends Model
{
    protected function casts(): array
    {
        return [
            'modified_at' => 'integer',
            'attempts' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WatchedFolder, $this>
     */
    public function watchedFolder(): BelongsTo
    {
        return $this->belongsTo(WatchedFolder::class);
    }

    /**
     * @return BelongsTo<AutomationRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
