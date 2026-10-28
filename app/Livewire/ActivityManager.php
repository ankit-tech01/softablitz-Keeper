<?php

namespace App\Livewire;

use App\Jobs\ExecuteFileAction;
use App\Models\FileExecution;
use App\Models\LedgerEntry;
use App\Services\FileActionService;
use App\Services\KeeperTrashService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

class ActivityManager extends Component
{
    public ?string $message = null;

    public bool $messageIsError = false;

    #[On('keeper-activity-updated')]
    public function refreshActivity(): void {}

    public function undoEntry(int $entryId, FileActionService $actions): void
    {
        try {
            $actions->undo(LedgerEntry::query()->findOrFail($entryId));
        } catch (RuntimeException $exception) {
            $this->setError($exception->getMessage());

            return;
        }

        $this->message = 'Action undone. The original checksum was verified.';
        $this->messageIsError = false;
    }

    public function undoSweep(string $sweep, FileActionService $actions): void
    {
        $entries = LedgerEntry::query()
            ->where('sweep_id', $sweep)
            ->where('status', 'completed')
            ->whereNull('undone_at')
            ->orderByDesc('id')
            ->get();
        $undone = 0;
        $errors = [];

        foreach ($entries as $entry) {
            try {
                $actions->undo($entry);
                $undone++;
            } catch (RuntimeException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        $this->message = $errors === []
            ? "{$undone} action(s) undone in reverse order."
            : "{$undone} action(s) undone; ".implode(' ', array_unique($errors));
        $this->messageIsError = $errors !== [];
    }

    public function emptyTrash(KeeperTrashService $trash): void
    {
        try {
            $removedFiles = $trash->empty();
        } catch (RuntimeException $exception) {
            $this->setError($exception->getMessage());

            return;
        }

        $this->message = $removedFiles === 0
            ? 'Keeper trash is already empty.'
            : "{$removedFiles} file(s) permanently removed from Keeper trash.";
        $this->messageIsError = false;
    }

    public function retryExecution(int $executionId): void
    {
        $execution = FileExecution::query()->findOrFail($executionId);

        if ($execution->status !== 'failed') {
            $this->setError('Only failed file actions can be retried.');

            return;
        }

        $execution->update(['status' => 'queued', 'last_error' => null]);
        ExecuteFileAction::dispatch($execution->unique_key);
        $this->message = 'File action queued for another attempt.';
        $this->messageIsError = false;
    }

    private function setError(string $message): void
    {
        $this->message = $message;
        $this->messageIsError = true;
    }

    public function render(): View
    {
        $executions = FileExecution::query()
            ->whereIn('status', ['queued', 'processing', 'failed'])
            ->latest()
            ->limit(8)
            ->get();

        return view('livewire.activity-manager', [
            'entries' => LedgerEntry::query()->latest()->limit(12)->get(),
            'executions' => $executions,
            'hasQueuedExecutions' => $executions->contains(fn (FileExecution $execution): bool => in_array($execution->status, ['queued', 'processing'], true)),
        ]);
    }
}
