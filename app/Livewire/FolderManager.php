<?php

namespace App\Livewire;

use App\Models\AutomationRule;
use App\Models\FileExecution;
use App\Models\LedgerEntry;
use App\Models\WatchedFolder;
use App\Services\FileActionService;
use App\Services\FolderPathService;
use App\Services\FolderScanner;
use App\Services\RuleEvaluator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;

class FolderManager extends Component
{
    public string $folderPath = '';

    public ?string $message = null;

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

        $stableFiles = $scanner->scan($folder);
        $enabledRules = AutomationRule::query()
            ->where('is_enabled', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
        $plans = [];

        foreach ($stableFiles as $file) {
            $rule = $evaluator->firstMatchFromRules(
                $enabledRules,
                $file['path'],
                $file['size'],
                $file['modified_at'],
            );

            if ($rule === null) {
                $plans[] = [
                    ...$file,
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
                $this->addError('folderPath', 'A settled file could not be read to verify its checksum.');

                return;
            }

            $alreadyOrganized = LedgerEntry::query()
                ->where('status', 'completed')
                ->where('destination_path', $file['path'])
                ->where('result_checksum', $checksum)
                ->exists();
            $plans[] = [
                ...$file,
                'checksum' => $checksum,
                'rule_id' => $alreadyOrganized ? null : $rule->id,
                'rule_name' => $alreadyOrganized ? 'Already handled' : $rule->name,
                'action' => $alreadyOrganized ? 'ignored' : $rule->action,
                'destination' => $alreadyOrganized ? null : $actions->previewDestination($rule, $file['path']),
                'loop_guard' => $alreadyOrganized,
            ];
        }

        session()->put('keeper.preview', [
            'folder_id' => $folder->id,
            'sweep_id' => (string) Str::uuid(),
            'plans' => $plans,
        ]);

        $this->message = $plans === []
            ? 'Nothing is settled yet. Run another scan after the files stop changing.'
            : 'Preview ready. No files have been changed.';
        $this->dispatch('keeper-preview-updated');
    }

    public function render(): View
    {
        return view('livewire.folder-manager', [
            'folders' => WatchedFolder::query()->withCount('observations')->orderBy('created_at')->get(),
        ]);
    }
}
