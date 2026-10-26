<?php

namespace App\Services;

use App\Models\AutomationRule;

class RuleEvaluator
{
    /**
     * @param  array<int, int>|null  $allowedRuleIds
     */
    public function firstMatch(string $path, int $size, int $modifiedAt, ?array $allowedRuleIds = null): ?AutomationRule
    {
        foreach (AutomationRule::query()->where('is_enabled', true)->orderBy('priority')->orderBy('id')->get() as $rule) {
            if ($allowedRuleIds !== null && ! in_array($rule->id, $allowedRuleIds, true)) {
                continue;
            }

            if ($this->matches($rule, $path, $size, $modifiedAt)) {
                return $rule;
            }
        }

        return null;
    }

    public function matches(AutomationRule $rule, string $path, int $size, int $modifiedAt): bool
    {
        $conditions = $rule->conditions;

        if ($conditions === []) {
            return false;
        }

        $results = [];

        foreach ($conditions as $condition) {
            $results[] = $this->evaluateCondition($condition, $path, $size, $modifiedAt);
        }

        return match ($rule->logic) {
            'or' => in_array(true, $results, true),
            'not' => ! in_array(true, $results, true),
            default => ! in_array(false, $results, true),
        };
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function evaluateCondition(array $condition, string $path, int $size, int $modifiedAt): bool
    {
        if (($condition['type'] ?? null) === 'group') {
            $children = $condition['conditions'] ?? [];

            if (! is_array($children) || $children === []) {
                return false;
            }

            $results = [];

            foreach ($children as $child) {
                if (! is_array($child)) {
                    return false;
                }

                $results[] = $this->evaluateCondition($child, $path, $size, $modifiedAt);
            }

            return match ($condition['operator'] ?? 'and') {
                'or' => in_array(true, $results, true),
                'not' => ! in_array(true, $results, true),
                default => ! in_array(false, $results, true),
            };
        }

        $field = $condition['field'] ?? '';
        $operator = $condition['operator'] ?? '';
        $expected = $condition['value'] ?? '';
        $fileName = basename($path);

        if ($field === 'size') {
            return $this->compareNumber($size, (int) $expected, $operator);
        }

        if ($field === 'age') {
            $ageInDays = max(0, (time() - $modifiedAt) / 86400);

            return $this->compareNumber($ageInDays, (float) $expected, $operator);
        }

        $actual = match ($field) {
            'extension' => strtolower(pathinfo($fileName, PATHINFO_EXTENSION)),
            'name' => strtolower($fileName),
            default => null,
        };

        if ($actual === null) {
            return false;
        }

        $expected = strtolower((string) $expected);

        return match ($operator) {
            'contains' => str_contains($actual, $expected),
            'equals' => $actual === $expected,
            'starts_with' => str_starts_with($actual, $expected),
            'ends_with' => str_ends_with($actual, $expected),
            default => false,
        };
    }

    private function compareNumber(float|int $actual, float|int $expected, string $operator): bool
    {
        return match ($operator) {
            'greater_than' => $actual > $expected,
            'less_than' => $actual < $expected,
            'equals' => $actual === $expected,
            default => false,
        };
    }
}
