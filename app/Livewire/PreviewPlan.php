<?php

namespace App\Livewire;

use App\Models\WatchedFolder;
use App\Services\DesktopNotifier;
use App\Services\FileDispatcher;
use App\Services\RuleEvaluator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class PreviewPlan extends Component
{
    public string $confirmation = '1';

    public ?string $message = null;

    #[On('keeper-preview-updated')]
    public function refreshPlan(): void {}

    #[On('keeper-workspace-reset')]
    public function clearAfterWorkspaceReset(): void
    {
        $this->message = null;
    }

    public function confirmPlan(
        RuleEvaluator $evaluator,
        FileDispatcher $dispatcher,
        DesktopNotifier $notifier,
    ): void {
        $this->validate(['confirmation' => ['accepted']]);
        $preview = session('keeper.preview', []);
        $folderId = $preview['folder_id'] ?? null;
        $sweepId = $preview['sweep_id'] ?? null;
        $plans = $preview['plans'] ?? [];

        if (! is_string($sweepId)
            || ! is_array($plans)) {
            $this->addError('confirmation', 'The preview session is invalid. Run a fresh scan before confirming.');

            return;
        }

        $fallbackFolderId = is_int($folderId) || (is_string($folderId) && ctype_digit($folderId))
            ? (int) $folderId
            : null;
        $folderIds = collect($plans)
            ->map(fn (mixed $plan): mixed => is_array($plan) ? ($plan['folder_id'] ?? $fallbackFolderId) : null)
            ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->all();
        $folders = WatchedFolder::query()
            ->whereIn('id', $folderIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        if ($folders->isEmpty()) {
            $this->addError('confirmation', 'The watched folder is no longer available.');

            return;
        }

        $queued = 0;

        foreach ($plans as $plan) {
            if (! is_array($plan)
                || ! is_string($plan['path'] ?? null)
                || ! is_int($plan['modified_at'] ?? null)
                || ! is_string($plan['checksum'] ?? null)
                || ! is_int($plan['rule_id'] ?? null)) {
                continue;
            }

            $planFolderId = $plan['folder_id'] ?? $fallbackFolderId;
            $folder = (is_int($planFolderId) || (is_string($planFolderId) && ctype_digit($planFolderId)))
                ? $folders->get((int) $planFolderId)
                : null;

            if ($folder === null) {
                continue;
            }

            $path = $plan['path'];
            $currentModifiedAt = is_file($path) ? filemtime($path) : false;
            $currentSize = is_file($path) ? filesize($path) : false;
            $currentChecksum = is_file($path) ? hash_file('sha256', $path) : false;

            if ($currentModifiedAt !== $plan['modified_at']
                || $currentSize === false
                || $currentChecksum === false
                || $currentChecksum !== $plan['checksum']) {
                continue;
            }

            $firstMatch = $evaluator->firstMatch($path, $currentSize, $currentModifiedAt);

            if ($firstMatch === null || $firstMatch->id !== $plan['rule_id']) {
                continue;
            }

            if (! $firstMatch->is_approved) {
                $firstMatch->update(['is_approved' => true]);
            }

            if ($dispatcher->dispatch($folder, $path, $plan['modified_at'], $plan['checksum'], $firstMatch, $sweepId)) {
                $queued++;
            }
        }

        session()->forget('keeper.preview');
        $notifier->queuedActions($queued);
        $this->message = $queued > 0
            ? "{$queued} file action(s) queued. The local worker will process them automatically."
            : 'No files were queued because their preview changed or they were already processed.';
        $this->dispatch('keeper-preview-updated');
        $this->dispatch('keeper-activity-updated');
    }

    public function render(): View
    {
        $preview = session('keeper.preview', []);
        $preview = is_array($preview) ? $preview : [];
        $rawPlans = $preview['plans'] ?? [];
        $plans = collect(is_array($rawPlans) ? $rawPlans : []);

        return view('livewire.preview-plan', [
            'preview' => $preview,
            'displayPlans' => ! empty($preview['automatic'])
                ? $plans->filter(fn (mixed $plan): bool => is_array($plan) && ($plan['rule_id'] ?? null) !== null)
                : $plans,
        ]);
    }
}
