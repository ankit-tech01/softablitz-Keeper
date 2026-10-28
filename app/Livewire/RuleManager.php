<?php

namespace App\Livewire;

use App\Models\AutomationRule;
use App\Models\FileExecution;
use App\Services\FolderPathService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class RuleManager extends Component
{
    public string $name = '';

    public string $logic = 'and';

    /** @var array<int, array<string, mixed>> */
    public array $conditions = [['field' => 'name', 'operator' => 'contains', 'value' => '']];

    public string $action = 'rename';

    public string $target = '';

    public string $schedule = '';

    public ?string $message = null;

    public function updatedName(string $name): void
    {
        $this->validateOnly('name', [
            'name' => ['required', 'string', 'max:120'],
        ]);
    }

    public function updatedLogic(string $logic): void
    {
        $this->validateOnly('logic', [
            'logic' => ['required', Rule::in(['and', 'or', 'not'])],
        ]);
    }

    public function updatedAction(string $action): void
    {
        $this->validateOnly('action', [
            'action' => ['required', Rule::in(['rename', 'move', 'copy', 'trash', 'script'])],
        ]);
        $this->resetErrorBag('target');
        $this->validateTarget(app(FolderPathService::class));
    }

    public function updatedTarget(string $target): void
    {
        $this->validateTarget(app(FolderPathService::class));
    }

    public function updatedSchedule(string $schedule): void
    {
        $this->validateOnly('schedule', [
            'schedule' => ['nullable', Rule::in(['', 'hourly', 'daily'])],
        ]);
    }

    public function updatedConditions(mixed $value = null, ?string $key = null): void
    {
        $this->resetErrorBag('conditions');

        try {
            $this->validateConditions($this->conditions);
        } catch (ValidationException $exception) {
            $this->addError('conditions', $exception->validator->errors()->first());
        }
    }

    public function saveRule(FolderPathService $paths): void
    {
        $this->resetErrorBag();
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'logic' => ['required', Rule::in(['and', 'or', 'not'])],
            'conditions' => ['required', 'array', 'min:1', 'max:12'],
            'action' => ['required', Rule::in(['rename', 'move', 'copy', 'trash', 'script'])],
            'target' => ['nullable', 'string', 'max:2048'],
            'schedule' => ['nullable', Rule::in(['', 'hourly', 'daily'])],
        ]);
        $validated['conditions'] = $this->validateConditions($validated['conditions']);

        if (in_array($validated['action'], ['rename', 'move', 'copy', 'script'], true)
            && trim((string) $validated['target']) === '') {
            $this->addError('target', 'This action needs a destination or command.');

            return;
        }

        if ($validated['action'] === 'rename'
            && (basename($validated['target']) !== $validated['target']
                || in_array(trim($validated['target']), ['', '.', '..'], true))) {
            $this->addError('target', 'Enter a new file name without a folder path.');

            return;
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
            'schedule' => $validated['schedule'] ?: null,
            'priority' => (AutomationRule::query()->max('priority') ?? -1) + 1,
            'is_enabled' => true,
            'is_approved' => false,
        ]);

        $this->reset('name', 'conditions', 'logic', 'action', 'target', 'schedule');
        $this->conditions = [['field' => 'name', 'operator' => 'contains', 'value' => '']];
        $this->message = 'Rule saved. Rules run from top to bottom; only the first matching rule acts.';
        $this->dispatch('keeper-rules-updated');
    }

    private function validateTarget(?FolderPathService $paths = null): void
    {
        $this->validateOnly('target', [
            'target' => ['nullable', 'string', 'max:2048'],
        ]);

        $target = trim($this->target);

        if (in_array($this->action, ['rename', 'move', 'copy', 'script'], true) && $target === '') {
            $this->addError('target', 'This action needs a destination or command.');

            return;
        }

        if ($target === '') {
            return;
        }

        if ($this->action === 'rename'
            && (basename($target) !== $target || in_array($target, ['.', '..'], true))) {
            $this->addError('target', 'Enter a new file name without a folder path.');

            return;
        }

        if (in_array($this->action, ['move', 'copy'], true)) {
            ($paths ?? app(FolderPathService::class))->resolveWatchedFolder($target, 'target');
        }
    }

    public function toggleRule(int $ruleId): void
    {
        $this->resetErrorBag();
        DB::transaction(function () use ($ruleId): void {
            $rule = AutomationRule::query()->findOrFail($ruleId);
            $rule->update(['is_enabled' => ! $rule->is_enabled]);
            AutomationRule::query()->update(['is_approved' => false]);
        });

        $this->message = 'Rule status updated.';
    }

    public function moveRule(int $ruleId, string $direction): void
    {
        $this->resetErrorBag();

        if (! in_array($direction, ['up', 'down'], true)) {
            throw ValidationException::withMessages(['direction' => 'Choose up or down.']);
        }

        $orderedRules = AutomationRule::query()->orderBy('priority')->orderBy('id')->get();
        $currentIndex = $orderedRules->search(fn (AutomationRule $candidate): bool => $candidate->id === $ruleId);

        if ($currentIndex === false) {
            return;
        }

        $swapIndex = $currentIndex + ($direction === 'up' ? -1 : 1);

        if ($swapIndex < 0 || $swapIndex >= $orderedRules->count()) {
            return;
        }

        DB::transaction(function () use ($orderedRules, $currentIndex, $swapIndex): void {
            $first = $orderedRules[$currentIndex];
            $second = $orderedRules[$swapIndex];
            $firstPriority = $first->priority;
            $first->update(['priority' => $second->priority]);
            $second->update(['priority' => $firstPriority]);
            AutomationRule::query()->update(['is_approved' => false]);
        });

        $this->message = 'Rule order updated.';
    }

    public function deleteRule(int $ruleId): void
    {
        $this->resetErrorBag();
        DB::transaction(function () use ($ruleId): void {
            $rule = AutomationRule::query()->findOrFail($ruleId);
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
        $this->message = 'Rule deleted. Existing activity history was kept.';
        $this->dispatch('keeper-preview-updated');
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
                throw ValidationException::withMessages(['conditions' => 'Every condition must be a condition or a group.']);
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
            $allowedOperators = in_array($field, ['size', 'age'], true)
                ? ['equals', 'greater_than', 'less_than']
                : ['contains', 'equals', 'starts_with', 'ends_with'];

            if (! in_array($field, ['name', 'extension', 'size', 'age'], true)
                || ! in_array($operator, $allowedOperators, true)
                || ! is_string($value)
                || trim($value) === ''
                || strlen($value) > 255
                || (in_array($field, ['size', 'age'], true) && (! is_numeric($value) || (float) $value < 0))) {
                throw ValidationException::withMessages([
                    'conditions' => 'Use a valid field, operator and value in every condition.',
                ]);
            }

            $validatedConditions[] = ['field' => $field, 'operator' => $operator, 'value' => trim($value)];
        }

        return $validatedConditions;
    }

    public function render(): View
    {
        return view('livewire.rule-manager', [
            'rules' => AutomationRule::query()->orderBy('priority')->orderBy('id')->get(),
        ]);
    }
}
