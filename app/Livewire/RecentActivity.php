<?php

namespace App\Livewire;

use App\Models\LedgerEntry;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class RecentActivity extends Component
{
    #[On('keeper-workspace-reset')]
    public function refreshActivity(): void {}

    #[On('keeper-activity-updated')]
    public function refreshAfterAction(): void {}

    public function render(): View
    {
        return view('livewire.recent-activity', [
            'entries' => LedgerEntry::query()->latest()->limit(5)->get(),
        ]);
    }
}
