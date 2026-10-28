<?php

namespace App\Livewire;

use App\Services\WorkspaceResetService;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use RuntimeException;

class ResetWorkspace extends Component
{
    public ?string $message = null;

    public bool $messageIsError = false;

    public function resetWorkspace(WorkspaceResetService $reset): void
    {
        try {
            $deletedTrashFiles = $reset->reset();
        } catch (RuntimeException $exception) {
            $this->message = $exception->getMessage();
            $this->messageIsError = true;

            return;
        }

        $this->message = $deletedTrashFiles === 0
            ? 'Keeper was reset. All workspace records and settings were cleared.'
            : "Keeper was reset. All workspace records and settings were cleared, and {$deletedTrashFiles} trash file(s) were permanently deleted.";
        $this->messageIsError = false;
        $this->dispatch('keeper-workspace-reset');
        $this->dispatch('keeper-preview-updated');
    }

    public function render(): View
    {
        return view('livewire.reset-workspace');
    }
}
