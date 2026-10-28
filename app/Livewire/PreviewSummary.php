<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class PreviewSummary extends Component
{
    #[On('keeper-preview-updated')]
    public function refreshSummary(): void {}

    public function render(): View
    {
        return view('livewire.preview-summary', [
            'preview' => session('keeper.preview', []),
        ]);
    }
}
