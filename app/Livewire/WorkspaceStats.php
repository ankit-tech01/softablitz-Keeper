<?php

namespace App\Livewire;

use App\Models\AutomationRule;
use App\Models\FileExecution;
use App\Models\LedgerEntry;
use App\Models\WatchedFolder;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class WorkspaceStats extends Component
{
    #[On('keeper-workspace-reset')]
    public function refreshStats(): void {}

    #[On('keeper-activity-updated')]
    public function refreshActivityStats(): void {}

    #[On('keeper-rules-updated')]
    public function refreshRuleStats(): void {}

    #[On('keeper-folder-updated')]
    public function refreshFolderStats(): void {}

    public function render(): View
    {
        return view('livewire.workspace-stats', [
            'stats' => [
                'folders' => WatchedFolder::query()->where('is_active', true)->count(),
                'rules' => AutomationRule::query()->where('is_enabled', true)->count(),
                'organized' => LedgerEntry::query()->where('status', 'completed')->whereNull('undone_at')->count(),
                'queued' => FileExecution::query()->whereIn('status', ['queued', 'processing'])->count(),
            ],
        ]);
    }
}
