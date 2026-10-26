<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['entry_uuid', 'file_execution_id', 'automation_rule_id', 'sweep_id', 'action', 'source_path', 'destination_path', 'checksum', 'result_checksum', 'metadata', 'status', 'error_message', 'completed_at', 'undone_at'])]
class LedgerEntry extends Model
{
    protected static function booted(): void
    {
        static::updating(function (LedgerEntry $entry): void {
            $immutableFields = [
                'entry_uuid',
                'file_execution_id',
                'automation_rule_id',
                'sweep_id',
                'action',
                'source_path',
                'destination_path',
                'checksum',
                'metadata',
                'created_at',
            ];

            if (array_intersect(array_keys($entry->getDirty()), $immutableFields) !== []) {
                throw new LogicException('Ledger action details are append-only.');
            }

            if ($entry->isDirty('result_checksum')
                && ($entry->getOriginal('status') !== 'pending' || $entry->status !== 'completed')) {
                throw new LogicException('The ledger result checksum can only be recorded when an action completes.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Ledger entries cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'completed_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FileExecution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(FileExecution::class, 'file_execution_id');
    }

    /**
     * @return BelongsTo<AutomationRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }
}
