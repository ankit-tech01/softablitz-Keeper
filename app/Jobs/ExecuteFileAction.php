<?php

namespace App\Jobs;

use App\Models\FileExecution;
use App\Models\LedgerEntry;
use App\Services\FileActionService;
use App\Services\RuleEvaluator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteFileAction implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 180];

    public function __construct(public string $uniqueKey) {}

    public function uniqueId(): string
    {
        return $this->uniqueKey;
    }

    public function handle(RuleEvaluator $evaluator, FileActionService $actions): void
    {
        $claimed = FileExecution::query()
            ->where('unique_key', $this->uniqueKey)
            ->where('status', 'queued')
            ->update([
                'status' => 'processing',
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => null,
            ]);

        if ($claimed === 0) {
            return;
        }

        $execution = FileExecution::query()->where('unique_key', $this->uniqueKey)->first();

        if ($execution === null) {
            return;
        }

        if (! is_file($execution->file_path) || filemtime($execution->file_path) !== $execution->modified_at) {
            $execution->update([
                'status' => 'failed',
                'last_error' => 'The file is missing or changed since it was previewed.',
                'processed_at' => now(),
            ]);

            return;
        }

        $checksum = hash_file('sha256', $execution->file_path);

        if ($checksum !== $execution->checksum) {
            $execution->update([
                'status' => 'failed',
                'last_error' => 'The file content changed after the preview.',
                'processed_at' => now(),
            ]);

            return;
        }

        $alreadyOrganized = LedgerEntry::query()
            ->where('status', 'completed')
            ->where('destination_path', $execution->file_path)
            ->where('result_checksum', $checksum)
            ->exists();

        if ($alreadyOrganized) {
            $execution->update([
                'status' => 'ignored',
                'last_error' => 'Loop guard: this file already reached a destination in an earlier action.',
                'processed_at' => now(),
            ]);

            return;
        }

        $rule = $evaluator->firstMatch(
            $execution->file_path,
            (int) @filesize($execution->file_path),
            $execution->modified_at,
        );

        if ($rule === null || $rule->id !== $execution->automation_rule_id || ! $rule->is_approved) {
            $execution->update([
                'status' => 'ignored',
                'last_error' => 'The rule changed or needs a confirmed preview.',
                'processed_at' => now(),
            ]);

            return;
        }

        try {
            $actions->execute($execution, $rule);
            $execution->update(['status' => 'completed', 'processed_at' => now()]);
        } catch (Throwable $exception) {
            $execution->update([
                'status' => 'failed',
                'last_error' => $exception->getMessage(),
                'processed_at' => now(),
            ]);
            Log::warning('A file action failed.', [
                'execution_id' => $execution->id,
                'path' => $execution->file_path,
                'rule_id' => $rule->id,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
