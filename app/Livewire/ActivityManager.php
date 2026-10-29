<?php

namespace App\Livewire;

use App\Jobs\ExecuteFileAction;
use App\Models\FileExecution;
use App\Models\LedgerEntry;
use App\Services\FileActionService;
use App\Services\KeeperTrashService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Native\Desktop\Facades\Shell;
use RuntimeException;

class ActivityManager extends Component
{
    use WithPagination;

    public ?string $message = null;

    public bool $messageIsError = false;

    public bool $showAllEntries = false;

    #[On('keeper-activity-updated')]
    public function refreshActivity(): void {}

    #[On('keeper-workspace-reset')]
    public function clearAfterWorkspaceReset(): void
    {
        $this->reset('message', 'messageIsError', 'showAllEntries');
        $this->resetPage();
    }

    public function toggleAllEntries(): void
    {
        $this->showAllEntries = ! $this->showAllEntries;
        $this->resetPage();
    }

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

    public function undoTrashFile(int $entryId, FileActionService $actions, KeeperTrashService $trash): void
    {
        $entry = LedgerEntry::query()
            ->whereKey($entryId)
            ->where('action', 'trash')
            ->where('status', 'completed')
            ->whereNull('undone_at')
            ->firstOrFail();
        $fileIsAvailable = collect($trash->files())
            ->contains(fn (array $file): bool => $file['ledger_entry_id'] === $entry->id);

        if (! $fileIsAvailable) {
            $this->setError('This file is no longer available in Keeper trash.');

            return;
        }

        try {
            $actions->undo($entry);
        } catch (RuntimeException $exception) {
            $this->setError($exception->getMessage());

            return;
        }

        $this->message = 'File restored from Keeper trash.';
        $this->messageIsError = false;
    }

    public function deleteTrashFile(string $path, KeeperTrashService $trash): void
    {
        try {
            $trash->deleteFile($path);
        } catch (RuntimeException $exception) {
            $this->setError($exception->getMessage());

            return;
        }

        $this->message = 'File permanently deleted from Keeper trash.';
        $this->messageIsError = false;
    }

    public function openTrashFile(string $path, KeeperTrashService $trash): void
    {
        $file = collect($trash->files())->first(
            fn (array $file): bool => $file['path'] === $path,
        );

        if ($file === null) {
            $this->setError('This file is no longer available in Keeper trash.');

            return;
        }

        if (! config('nativephp-internal.running')) {
            $this->setError('Open this file in the Sanctum Keeper desktop app to launch it.');

            return;
        }

        $error = Shell::openFile($file['path']);

        if ($error !== '') {
            $this->setError("The file could not be opened: {$error}");

            return;
        }

        $this->message = null;
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

    public function removeFailedExecution(int $executionId): void
    {
        $removed = DB::transaction(function () use ($executionId): bool {
            $execution = FileExecution::query()
                ->whereKey($executionId)
                ->where('status', 'failed')
                ->lockForUpdate()
                ->first();

            return $execution?->delete() ?? false;
        });

        if (! $removed) {
            $this->setError('This action is no longer failed or has already been removed.');

            return;
        }

        $this->message = 'Failed action removed from the queue. Any action history was kept.';
        $this->messageIsError = false;
    }

    public function removeAllFailedExecutions(): void
    {
        $removedCount = DB::transaction(function (): int {
            $failedIds = FileExecution::query()
                ->where('status', 'failed')
                ->lockForUpdate()
                ->pluck('id');

            if ($failedIds->isEmpty()) {
                return 0;
            }

            return FileExecution::query()
                ->whereIn('id', $failedIds)
                ->where('status', 'failed')
                ->delete();
        });

        $this->message = $removedCount === 0
            ? 'There are no failed actions to remove.'
            : "{$removedCount} failed action(s) removed from the queue. Any action history was kept.";
        $this->messageIsError = false;
    }

    private function setError(string $message): void
    {
        $this->message = $message;
        $this->messageIsError = true;
    }

    public function render(KeeperTrashService $trash): View
    {
        $executions = FileExecution::query()
            ->whereIn('status', ['queued', 'processing', 'failed'])
            ->latest()
            ->limit(8)
            ->get();
        $failedExecutionCount = FileExecution::query()->where('status', 'failed')->count();
        $trashFiles = $trash->files();
        $entriesQuery = LedgerEntry::query()->with('rule')->latest()->orderByDesc('id');
        $entries = $this->showAllEntries
            ? $entriesQuery->paginate(10)
            : $entriesQuery->limit(10)->get();
        $entryRows = $entries instanceof LengthAwarePaginator
            ? $entries->getCollection()
            : $entries;

        return view('livewire.activity-manager', [
            'entries' => $entries,
            'entryRows' => $entryRows,
            'entryCount' => LedgerEntry::query()->count(),
            'trashFiles' => $trashFiles,
            'trashEntryIds' => array_values(array_filter(array_column($trashFiles, 'ledger_entry_id'))),
            'executions' => $executions,
            'failedExecutionCount' => $failedExecutionCount,
            'hasQueuedExecutions' => $executions->contains(fn (FileExecution $execution): bool => in_array($execution->status, ['queued', 'processing'], true)),
        ]);
    }
}
