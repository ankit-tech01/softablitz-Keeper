<?php

namespace App\Livewire;

use App\Models\AutomationRule;
use App\Models\LedgerEntry;
use App\Models\WatchedFolder;
use App\Services\FileActionService;
use App\Services\FolderPathService;
use App\Services\FolderScanner;
use App\Services\RuleEvaluator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;
use Native\Desktop\Dialog;
use RuntimeException;

class FolderManager extends Component
{
    public string $folderPath = '';

    public ?string $message = null;

    #[On('keeper-workspace-reset')]
    public function clearAfterWorkspaceReset(): void
    {
        $this->reset('folderPath', 'message');
        $this->resetErrorBag();
    }

    /** @var array<int, int> */
    public array $newFilesByFolder = [];

    public int $nextAutoPreviewAt;

    public function mount(): void
    {
        $this->nextAutoPreviewAt = now()->addSeconds(30)->timestamp;
    }

    public function browseFolder(FolderPathService $paths): void
    {
        if (! config('nativephp-internal.running')) {
            $this->addError('folderPath', 'Folder browsing is available in the Sanctum Keeper desktop app.');

            return;
        }

        $selectedPath = Dialog::new()
            ->title('Choose a folder to watch')
            ->defaultPath(is_dir($this->folderPath) ? $this->folderPath : base_path())
            ->button('Choose folder')
            ->folders()
            ->open();

        if (! is_string($selectedPath) || $selectedPath === '') {
            return;
        }

        $this->resetErrorBag('folderPath');
        $this->folderPath = $paths->resolveWatchedFolder($selectedPath, 'folderPath');
        $this->updatedFolderPath($this->folderPath);
    }

    public function updatedFolderPath(string $path): void
    {
        $validated = $this->validateOnly('folderPath', [
            'folderPath' => ['required', 'string', 'max:4096'],
        ]);
        $resolvedPath = app(FolderPathService::class)->resolveWatchedFolder($validated['folderPath'], 'folderPath');
        $normalizedPath = str_replace('\\', '/', $resolvedPath);
        $pathHash = hash('sha256', DIRECTORY_SEPARATOR === '\\' ? strtolower($normalizedPath) : $normalizedPath);

        if (WatchedFolder::query()->where('path_hash', $pathHash)->doesntExist()
            && WatchedFolder::query()->count() >= 3) {
            $this->addError('folderPath', 'The local watcher supports up to three folders.');
        }
    }

    public function addFolder(FolderPathService $paths): void
    {
        $this->resetErrorBag('folderPath');
        $validated = $this->validate([
            'folderPath' => ['required', 'string', 'max:4096'],
        ]);
        $resolvedPath = $paths->resolveWatchedFolder($validated['folderPath'], 'folderPath');
        $normalizedPath = str_replace('\\', '/', $resolvedPath);
        $pathHash = hash('sha256', DIRECTORY_SEPARATOR === '\\' ? strtolower($normalizedPath) : $normalizedPath);

        if (WatchedFolder::query()->where('path_hash', $pathHash)->doesntExist()
            && WatchedFolder::query()->count() >= 3) {
            $this->addError('folderPath', 'The local watcher supports up to three folders.');

            return;
        }

        WatchedFolder::query()->firstOrCreate(
            ['path_hash' => $pathHash],
            ['name' => basename($resolvedPath), 'path' => $resolvedPath, 'is_active' => true],
        );
        $this->folderPath = '';
        $this->message = 'Folder added. Scan it twice to confirm files have finished downloading.';
        $this->dispatch('keeper-folder-updated');
    }

    public function removeFolder(int $folderId): void
    {
        $this->resetErrorBag();
        $folder = WatchedFolder::query()->findOrFail($folderId);

        DB::transaction(function () use ($folder): void {
            $folder->executions()->whereIn('status', ['queued', 'processing'])->update([
                'status' => 'ignored',
                'last_error' => 'The watched folder was removed.',
                'processed_at' => now(),
            ]);
            $folder->delete();
        });

        $this->message = 'Folder removed. Existing ledger history was kept.';
        $this->dispatch('keeper-folder-updated');
    }

    public function previewFolder(
        int $folderId,
        FolderScanner $scanner,
        RuleEvaluator $evaluator,
        FileActionService $actions,
    ): void {
        $this->resetErrorBag();
        $folder = WatchedFolder::query()->findOrFail($folderId);

        if (! is_dir($folder->path)) {
            $folder->update(['is_active' => false]);
            $this->addError('folderPath', 'This folder is unavailable. Reconnect its drive and scan again.');

            return;
        }

        try {
            $plans = $this->buildPlans($folder, $scanner, $evaluator, $actions);
        } catch (RuntimeException $exception) {
            $this->addError('folderPath', $exception->getMessage());

            return;
        }

        session()->put('keeper.preview', [
            'folder_id' => $folder->id,
            'sweep_id' => (string) Str::uuid(),
            'plans' => $plans,
            'automatic' => false,
        ]);

        $this->message = $plans === []
            ? 'Nothing is settled yet. Run another scan after the files stop changing.'
            : 'Preview ready. No files have been changed.';
        $this->dispatch('keeper-preview-updated');
    }

    public function autoPreview(
        FolderScanner $scanner,
        RuleEvaluator $evaluator,
        FileActionService $actions,
    ): void {
        $this->resetErrorBag();
        $enabledFolders = WatchedFolder::query()->orderBy('id')->get();
        $plans = [];
        $previousSnapshots = session()->get('keeper.auto_preview.observation_hashes', []);
        $nextSnapshots = [];
        $newFilesByFolder = [];

        foreach ($enabledFolders as $folder) {
            if (! is_dir($folder->path)) {
                $folder->update(['is_active' => false]);

                continue;
            }

            try {
                $plans = [
                    ...$plans,
                    ...$this->buildPlans($folder, $scanner, $evaluator, $actions),
                ];
                $currentHashes = $folder->observations()
                    ->pluck('path_hash')
                    ->all();
                $previousHashes = $previousSnapshots[$folder->id] ?? null;

                if (is_array($previousHashes)) {
                    $newFilesByFolder[$folder->id] = count(array_diff($currentHashes, $previousHashes));
                } else {
                    $newFilesByFolder[$folder->id] = 0;
                }

                $nextSnapshots[$folder->id] = $currentHashes;
            } catch (RuntimeException $exception) {
                $this->addError('folderPath', $exception->getMessage());

                return;
            }
        }

        session()->put('keeper.auto_preview.observation_hashes', $nextSnapshots);
        $this->newFilesByFolder = $newFilesByFolder;
        $this->nextAutoPreviewAt = now()->addSeconds(30)->timestamp;
        session()->put('keeper.preview', [
            'folder_id' => null,
            'sweep_id' => (string) Str::uuid(),
            'plans' => $plans,
            'automatic' => true,
            'folder_count' => $enabledFolders->filter(fn (WatchedFolder $folder): bool => is_dir($folder->path))->count(),
        ]);

        $this->message = null;
        $this->dispatch('keeper-preview-updated');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildPlans(
        WatchedFolder $folder,
        FolderScanner $scanner,
        RuleEvaluator $evaluator,
        FileActionService $actions,
    ): array {
        $enabledRules = AutomationRule::query()
            ->where('is_enabled', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
        $plans = [];

        foreach ($scanner->scan($folder) as $file) {
            $rule = $evaluator->firstMatchFromRules(
                $enabledRules,
                $file['path'],
                $file['size'],
                $file['modified_at'],
            );

            if ($rule === null) {
                $plans[] = [
                    ...$file,
                    'folder_id' => $folder->id,
                    'checksum' => null,
                    'rule_id' => null,
                    'rule_name' => null,
                    'action' => null,
                    'destination' => null,
                    'loop_guard' => false,
                ];

                continue;
            }

            $checksum = hash_file('sha256', $file['path']);

            if ($checksum === false) {
                throw new RuntimeException('A settled file could not be read to verify its checksum.');
            }

            $alreadyOrganized = LedgerEntry::query()
                ->where('status', 'completed')
                ->where('destination_path', $file['path'])
                ->where('result_checksum', $checksum)
                ->exists();
            $plans[] = [
                ...$file,
                'folder_id' => $folder->id,
                'checksum' => $checksum,
                'rule_id' => $alreadyOrganized ? null : $rule->id,
                'rule_name' => $alreadyOrganized ? 'Already handled' : $rule->name,
                'action' => $alreadyOrganized ? 'ignored' : $rule->action,
                'destination' => $alreadyOrganized ? null : $actions->previewDestination($rule, $file['path']),
                'loop_guard' => $alreadyOrganized,
            ];
        }

        return $plans;
    }

    public function render(): View
    {
        return view('livewire.folder-manager', [
            'folders' => WatchedFolder::query()->withCount('observations')->orderBy('created_at')->get(),
            'nextAutoPreviewAt' => $this->nextAutoPreviewAt,
            'newFilesByFolder' => $this->newFilesByFolder,
        ]);
    }
}
