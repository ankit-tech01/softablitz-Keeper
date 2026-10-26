<?php

use App\Models\AutomationRule;
use App\Models\WatchedFolder;
use App\Services\DesktopNotifier;
use App\Services\FileActionService;
use App\Services\FileDispatcher;
use App\Services\FolderScanner;
use App\Services\RuleEvaluator;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('keeper:scan', function (
    FolderScanner $scanner,
    RuleEvaluator $evaluator,
    FileDispatcher $dispatcher,
    DesktopNotifier $notifier,
): void {
    $now = now();
    $enabledRules = AutomationRule::query()->where('is_enabled', true)->orderBy('priority')->orderBy('id')->get();
    $allowedRules = $enabledRules->filter(function (AutomationRule $rule) use ($now): bool {
        if ($rule->schedule === null) {
            return true;
        }

        $periodStart = match ($rule->schedule) {
            'hourly' => $now->startOfHour(),
            'daily' => $now->startOfDay(),
            default => null,
        };

        return $periodStart !== null
            && ($rule->last_scheduled_at === null
                || Carbon::parse($rule->last_scheduled_at)->lt($periodStart));
    });
    $allowedRuleIds = $allowedRules->pluck('id')->all();
    $sweepId = (string) Str::uuid();
    $queued = 0;

    foreach (WatchedFolder::query()->get() as $folder) {
        foreach ($scanner->scan($folder) as $file) {
            $rule = $evaluator->firstMatch($file['path'], $file['size'], $file['modified_at'], $allowedRuleIds);

            $checksum = hash_file('sha256', $file['path']);

            if ($rule !== null && $rule->is_approved && $checksum !== false
                && $dispatcher->dispatch($folder, $file['path'], $file['modified_at'], $checksum, $rule, $sweepId) !== null) {
                $queued++;
            }
        }
    }

    foreach ($allowedRules->whereNotNull('schedule') as $rule) {
        $rule->update(['last_scheduled_at' => $now]);
    }

    $notifier->queuedActions($queued);
    $this->info("Queued {$queued} settled file(s).");
})->purpose('Scan watched folders and queue settled file actions');

Artisan::command('keeper:watch', function (): never {
    $this->info('Watching folders every two seconds. Press Ctrl+C to stop.');

    while (true) {
        $this->call('keeper:scan');
        sleep(2);
    }
})->purpose('Continuously scan watched folders');

Artisan::command('keeper:recover', function (FileActionService $actions): void {
    $recovered = $actions->recoverPendingEntries();
    $this->info("Recovered {$recovered} pending ledger action(s).");
})->purpose('Reconcile pending file actions after an interrupted run');

Schedule::command('keeper:scan')->everyMinute()->withoutOverlapping();
Schedule::command('keeper:recover')->everyMinute()->withoutOverlapping();
