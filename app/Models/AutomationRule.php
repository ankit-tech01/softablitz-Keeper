<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<int, array<string, mixed>> $conditions
 * @property string $logic
 */
#[Fillable(['name', 'conditions', 'logic', 'action', 'target', 'schedule', 'last_scheduled_at', 'priority', 'is_enabled', 'is_approved'])]
class AutomationRule extends Model
{
    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'is_enabled' => 'boolean',
            'is_approved' => 'boolean',
            'last_scheduled_at' => 'datetime',
        ];
    }
}
