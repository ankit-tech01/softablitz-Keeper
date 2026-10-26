<?php

namespace App\Services;

use App\Jobs\ExecuteFileAction;
use App\Models\AutomationRule;
use App\Models\FileExecution;
use App\Models\WatchedFolder;
use Illuminate\Support\Facades\DB;

class FileDispatcher
{
    public function dispatch(
        WatchedFolder $folder,
        string $path,
        int $modifiedAt,
        string $checksum,
        AutomationRule $rule,
        string $sweepId,
    ): ?FileExecution {
        $normalizedPath = str_replace('\\', '/', $path);
        $uniqueKey = hash('sha256', (DIRECTORY_SEPARATOR === '\\' ? strtolower($normalizedPath) : $normalizedPath).'|'.$modifiedAt);

        $execution = DB::transaction(function () use ($folder, $path, $modifiedAt, $checksum, $rule, $sweepId, $uniqueKey): ?FileExecution {
            $inserted = FileExecution::query()->insertOrIgnore([
                'unique_key' => $uniqueKey,
                'watched_folder_id' => $folder->id,
                'automation_rule_id' => $rule->id,
                'file_path' => $path,
                'modified_at' => $modifiedAt,
                'checksum' => $checksum,
                'status' => 'queued',
                'sweep_id' => $sweepId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $execution = FileExecution::query()->where('unique_key', $uniqueKey)->lockForUpdate()->firstOrFail();

            if ($inserted === 0) {
                $retryDelay = min(300, 30 * (2 ** max(0, $execution->attempts - 1)));

                if ($execution->status !== 'failed' || $execution->updated_at->gt(now()->subSeconds($retryDelay))) {
                    return null;
                }

                $execution->update([
                    'status' => 'queued',
                    'last_error' => null,
                    'automation_rule_id' => $rule->id,
                    'sweep_id' => $sweepId,
                    'checksum' => $checksum,
                ]);

                return $execution->fresh();
            }

            return $execution;
        });

        if ($execution !== null) {
            ExecuteFileAction::dispatch($execution->unique_key)->onQueue('organizer');
        }

        return $execution;
    }
}
