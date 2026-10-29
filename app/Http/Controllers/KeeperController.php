<?php

namespace App\Http\Controllers;

use App\Jobs\ExecuteFileAction;
use App\Models\AutomationRule;
use App\Models\FileExecution;
use App\Models\LedgerEntry;
use App\Models\WatchedFolder;
use App\Services\DesktopNotifier;
use App\Services\FileActionService;
use App\Services\FileDispatcher;
use App\Services\FolderPathService;
use App\Services\FolderScanner;
use App\Services\KeeperTrashService;
use App\Services\RuleEvaluator;
use App\Support\ByteSizeParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class KeeperController extends Controller
{
    public function index(): View
    {
        return view('welcome');
    }

    public function storeFolder(Request $request, FolderPathService $paths): RedirectResponse
    {
        $validated = $request->validate(['folder_path' => ['required', 'string', 'max:4096']]);
        $resolvedPath = $paths->resolveWatchedFolder($validated['folder_path']);

        $normalizedPath = str_replace('\\', '/', $resolvedPath);
        $pathHash = hash('sha256', DIRECTORY_SEPARATOR === '\\' ? strtolower($normalizedPath) : $normalizedPath);

        if (WatchedFolder::query()->where('path_hash', $pathHash)->doesntExist()
            && WatchedFolder::query()->count() >= 3) {
            return back()->withErrors(['folder_path' => 'The local watcher supports up to three folders.']);
        }

        WatchedFolder::query()->firstOrCreate(
            ['path_hash' => $pathHash],
            [
                'name' => basename($resolvedPath),
                'path' => $resolvedPath,
                'is_active' => true,
            ],
        );

        return to_route('home', ['tab' => 'folders'])->with('success', 'Folder added. Scan it twice to confirm files have finished downloading.');
    }

    public function destroyFolder(WatchedFolder $folder): RedirectResponse
    {
        DB::transaction(function () use ($folder): void {
            $folder->executions()->whereIn('status', ['queued', 'processing'])->update([
                'status' => 'ignored',
                'last_error' => 'The watched folder was removed.',
                'processed_at' => now(),
            ]);
            $folder->delete();
        });

        return to_route('home', ['tab' => 'folders'])->with('success', 'Folder removed. Existing ledger history was kept.');
    }

    public function storeRule(Request $request, FolderPathService $paths): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'logic' => ['required', Rule::in(['and', 'or', 'not'])],
            'conditions' => ['required', 'array', 'min:1', 'max:12'],
            'action' => ['required', Rule::in(['rename', 'move', 'copy', 'trash', 'script'])],
            'target' => ['nullable', 'string', 'max:2048'],
            'schedule' => ['nullable', Rule::in(['hourly', 'daily'])],
        ]);
        $validated['conditions'] = $this->validateConditions($validated['conditions']);

        if (in_array($validated['action'], ['rename', 'move', 'copy', 'script'], true) && trim((string) ($validated['target'] ?? '')) === '') {
            return back()->withErrors(['target' => 'This action needs a destination or command.']);
        }

        if ($validated['action'] === 'rename'
            && (basename($validated['target']) !== $validated['target'] || in_array(trim($validated['target']), ['', '.', '..'], true))) {
            return back()->withErrors(['target' => 'Enter a new file name without a folder path.']);
        }

        if (in_array($validated['action'], ['move', 'copy'], true)) {
            $validated['target'] = $paths->resolveWatchedFolder($validated['target'], 'target');
        }

        AutomationRule::query()->create([
            'name' => $validated['name'],
            'conditions' => array_values($validated['conditions']),
            'logic' => $validated['logic'],
            'action' => $validated['action'],
            'target' => trim((string) ($validated['target'] ?? '')) ?: null,
            'schedule' => $validated['schedule'] ?? null,
            'priority' => (AutomationRule::query()->max('priority') ?? -1) + 1,
            'is_enabled' => true,
            'is_approved' => false,
        ]);

        return to_route('home', ['tab' => 'rules'])->with('success', 'Rule saved. Rules run from top to bottom; only the first matching rule acts.');
    }

    public function destroyRule(AutomationRule $rule): RedirectResponse
    {
        DB::transaction(function () use ($rule): void {
            FileExecution::query()
                ->where('automation_rule_id', $rule->id)
                ->where('status', 'queued')
                ->update([
                    'status' => 'ignored',
                    'last_error' => 'The automation rule was removed.',
                    'processed_at' => now(),
                ]);

            $rule->delete();
            AutomationRule::query()->update(['is_approved' => false]);
        });

        session()->forget('keeper.preview');

        return to_route('home', ['tab' => 'rules'])->with('success', 'Rule deleted. Existing activity history was kept.');
    }

    /**
     * @param  array<int, mixed>  $conditions
     * @return array<int, array<string, mixed>>
     */
    private function validateConditions(array $conditions, int $depth = 0, int &$nodeCount = 0): array
    {
        if ($depth > 4 || count($conditions) > 12 || ($nodeCount += count($conditions)) > 60) {
            throw ValidationException::withMessages([
                'conditions' => 'Condition groups can be at most four levels deep and contain up to 60 conditions.',
            ]);
        }

        $validatedConditions = [];

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                throw ValidationException::withMessages([
                    'conditions' => 'Every condition must be a condition or a group.',
                ]);
            }

            if (($condition['type'] ?? null) === 'group') {
                if (! in_array($condition['operator'] ?? null, ['and', 'or', 'not'], true)
                    || ! is_array($condition['conditions'] ?? null)
                    || $condition['conditions'] === []) {
                    throw ValidationException::withMessages([
                        'conditions' => 'Each nested group needs an AND, OR or NOT operator and at least one condition.',
                    ]);
                }

                $validatedConditions[] = [
                    'type' => 'group',
                    'operator' => $condition['operator'],
                    'conditions' => $this->validateConditions($condition['conditions'], $depth + 1, $nodeCount),
                ];

                continue;
            }

            $field = $condition['field'] ?? null;
            $operator = $condition['operator'] ?? null;
            $value = $condition['value'] ?? null;
            $sizeInBytes = $field === 'size' && is_string($value)
                ? ByteSizeParser::parse($value)
                : null;
            $stringOperators = ['contains', 'equals', 'starts_with', 'ends_with'];
            $numberOperators = ['equals', 'greater_than', 'less_than'];
            $allowedOperators = in_array($field, ['size', 'age'], true) ? $numberOperators : $stringOperators;

            if (! in_array($field, ['name', 'extension', 'size', 'age'], true)
                || ! in_array($operator, $allowedOperators, true)
                || ! is_string($value)
                || trim($value) === ''
                || strlen($value) > 255
                || ($field === 'size' && $sizeInBytes === null)
                || ($field === 'age' && (! is_numeric($value) || (float) $value < 0))) {
                throw ValidationException::withMessages([
                    'conditions' => 'Use a valid field, operator and value in every condition.',
                ]);
            }

            $validatedConditions[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $field === 'size' ? (string) $sizeInBytes : trim($value),
            ];
        }

        return $validatedConditions;
    }

    public function toggleRule(AutomationRule $rule): RedirectResponse
    {
        DB::transaction(function () use ($rule): void {
            $rule->update(['is_enabled' => ! $rule->is_enabled]);
            AutomationRule::query()->update(['is_approved' => false]);
        });

        return to_route('home', ['tab' => 'rules'])->with('success', 'Rule status updated.');
    }

    public function moveRule(Request $request, AutomationRule $rule): RedirectResponse
    {
        $validated = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);
        $orderedRules = AutomationRule::query()->orderBy('priority')->orderBy('id')->get();
        $currentIndex = $orderedRules->search(fn (AutomationRule $candidate): bool => $candidate->is($rule));
        $swapIndex = $currentIndex + ($validated['direction'] === 'up' ? -1 : 1);

        if ($currentIndex !== false && $swapIndex >= 0 && $swapIndex < $orderedRules->count()) {
            DB::transaction(function () use ($orderedRules, $currentIndex, $swapIndex): void {
                $first = $orderedRules[$currentIndex];
                $second = $orderedRules[$swapIndex];
                $firstPriority = $first->priority;
                $secondPriority = $second->priority;

                $first->update(['priority' => $secondPriority]);
                $second->update(['priority' => $firstPriority]);
                AutomationRule::query()->update(['is_approved' => false]);
            });
        }

        return to_route('home', ['tab' => 'rules'])->with('success', 'Rule order updated.');
    }

    public function previewFolder(
        WatchedFolder $folder,
        FolderScanner $scanner,
        RuleEvaluator $evaluator,
        FileActionService $actions,
    ): RedirectResponse {
        if (! is_dir($folder->path)) {
            $folder->update(['is_active' => false]);

            return to_route('home', ['tab' => 'folders'])->withErrors(['folder_path' => 'This folder is unavailable. Reconnect its drive and scan again.']);
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

            $checksum = $actions->checksum($file['path']);

            if ($checksum === null) {
                return to_route('home', ['tab' => 'preview'])->withErrors(['preview' => 'A settled file could not be read to verify its checksum.']);
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

        $sweepId = (string) Str::uuid();
        session()->put('keeper.preview', [
            'folder_id' => $folder->id,
            'sweep_id' => $sweepId,
            'plans' => $plans,
        ]);

        if ($plans === []) {
            return to_route('home', ['tab' => 'preview'])->with('info', 'Nothing is settled yet. Run another scan after the files stop changing.');
        }

        return to_route('home', ['tab' => 'preview'])->with('success', 'Preview ready. No files have been changed.');
    }

    public function runPreview(
        Request $request,
        RuleEvaluator $evaluator,
        FileDispatcher $dispatcher,
        DesktopNotifier $notifier,
    ): RedirectResponse {
        $validated = $request->validate(['confirmation' => ['accepted']]);
        $preview = session('keeper.preview', []);
        $folderId = $preview['folder_id'] ?? null;
        $sweepId = $preview['sweep_id'] ?? null;
        $plans = $preview['plans'] ?? [];

        if ((! is_int($folderId) && ! (is_string($folderId) && ctype_digit($folderId)))
            || ! is_string($sweepId)
            || ! is_array($plans)) {
            return back()->withErrors(['preview' => 'The preview session is invalid. Run a fresh scan before confirming.']);
        }

        $folder = WatchedFolder::query()->whereKey($folderId)->first();

        if ($folder === null || ! $folder->is_active) {
            return back()->withErrors(['preview' => 'The watched folder is no longer available.']);
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

        return to_route('home', ['tab' => 'activity'])->with(
            'success',
            $queued > 0
                ? "{$queued} file action(s) queued. Start the organizer queue worker to apply them."
                : 'No files were queued because their preview changed or they were already processed.',
        );
    }

    public function undoEntry(LedgerEntry $entry, FileActionService $actions): RedirectResponse
    {
        try {
            $actions->undo($entry);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['undo' => $exception->getMessage()]);
        }

        return to_route('home', ['tab' => 'activity'])->with('success', 'Action undone. The original checksum was verified.');
    }

    public function undoSweep(string $sweep, FileActionService $actions): RedirectResponse
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

        if ($errors !== []) {
            return back()->withErrors(['undo' => "{$undone} action(s) undone; ".implode(' ', array_unique($errors))]);
        }

        return to_route('home', ['tab' => 'activity'])->with('success', "{$undone} action(s) undone in reverse order.");
    }

    public function emptyTrash(KeeperTrashService $trash): RedirectResponse
    {
        try {
            $removedFiles = $trash->empty();
        } catch (RuntimeException $exception) {
            return back()->withErrors(['trash' => $exception->getMessage()]);
        }

        if ($removedFiles === 0) {
            return to_route('home', ['tab' => 'activity'])->with('info', 'Keeper trash is already empty.');
        }

        return to_route('home', ['tab' => 'activity'])->with('success', "{$removedFiles} file(s) permanently removed from Keeper trash. These files can no longer be restored.");
    }

    public function retryExecution(FileExecution $execution): RedirectResponse
    {
        if ($execution->status !== 'failed') {
            return back()->withErrors(['retry' => 'Only failed file actions can be retried.']);
        }

        $execution->update(['status' => 'queued', 'last_error' => null]);
        ExecuteFileAction::dispatch($execution->unique_key);

        return to_route('home', ['tab' => 'activity'])->with('success', 'File action queued for another attempt.');
    }
}
