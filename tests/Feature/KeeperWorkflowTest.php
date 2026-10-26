<?php

use App\Models\AutomationRule;
use App\Models\LedgerEntry;
use App\Models\WatchedFolder;
use App\Services\RuleEvaluator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

test('a settled file is previewed, renamed once, and restored from the ledger', function () {
    $folderPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-'.Str::uuid();
    File::makeDirectory($folderPath);

    try {
        $sourcePath = $folderPath.DIRECTORY_SEPARATOR.'report.pdf';
        $destinationPath = $folderPath.DIRECTORY_SEPARATOR.'report-archived.pdf';
        File::put($sourcePath, 'a private test document');

        $this->post(route('folders.store'), ['folder_path' => $folderPath])->assertRedirect();
        $this->post(route('rules.store'), [
            'name' => 'Archive reports',
            'logic' => 'and',
            'conditions' => [
                ['field' => 'name', 'operator' => 'contains', 'value' => 'report'],
            ],
            'action' => 'rename',
            'target' => 'report-archived.pdf',
        ])->assertRedirect();

        $folder = WatchedFolder::query()->firstOrFail();
        $this->post(route('folders.preview', $folder))->assertRedirect();
        expect(session('keeper.preview.plans'))->toBe([]);
        expect(is_file($sourcePath))->toBeTrue();

        $this->post(route('folders.preview', $folder))->assertRedirect();
        expect(session('keeper.preview.plans'))->toHaveCount(1);
        expect(session('keeper.preview.plans.0.destination'))->toBe($destinationPath);
        expect(is_file($sourcePath))->toBeTrue();
        expect(is_file($destinationPath))->toBeFalse();

        $this->post(route('preview.run'), ['confirmation' => '1'])->assertRedirect();

        expect(is_file($sourcePath))->toBeFalse();
        expect(is_file($destinationPath))->toBeTrue();

        $entry = LedgerEntry::query()->firstOrFail();
        expect($entry->status)->toBe('completed');
        expect($entry->checksum)->toBe(hash_file('sha256', $destinationPath));

        $this->post(route('activity.undo', $entry))->assertRedirect();

        expect(is_file($sourcePath))->toBeTrue();
        expect(is_file($destinationPath))->toBeFalse();
        expect($entry->fresh()->undone_at)->not->toBeNull();
    } finally {
        File::deleteDirectory($folderPath);
    }
});

test('the watched-folder validator rejects the filesystem root', function () {
    $rootPath = realpath(DIRECTORY_SEPARATOR);

    $this->post(route('folders.store'), ['folder_path' => $rootPath])
        ->assertSessionHasErrors('folder_path');

    expect(WatchedFolder::query()->count())->toBe(0);
});

test('the first matching enabled rule wins', function () {
    $firstRule = AutomationRule::query()->create([
        'name' => 'First rule',
        'conditions' => [['field' => 'extension', 'operator' => 'equals', 'value' => 'pdf']],
        'logic' => 'and',
        'action' => 'rename',
        'target' => 'first.pdf',
        'priority' => 0,
        'is_enabled' => true,
    ]);
    AutomationRule::query()->create([
        'name' => 'Second rule',
        'conditions' => [['field' => 'name', 'operator' => 'contains', 'value' => 'report']],
        'logic' => 'and',
        'action' => 'trash',
        'priority' => 1,
        'is_enabled' => true,
    ]);

    $matchedRule = app(RuleEvaluator::class)->firstMatch('C:\\Users\\test\\report.pdf', 100, time());

    expect($matchedRule?->is($firstRule))->toBeTrue();
});

test('nested AND OR and NOT groups are evaluated recursively', function () {
    $rule = AutomationRule::query()->create([
        'name' => 'Image invoices except drafts',
        'conditions' => [
            [
                'type' => 'group',
                'operator' => 'or',
                'conditions' => [
                    ['field' => 'extension', 'operator' => 'equals', 'value' => 'pdf'],
                    ['field' => 'name', 'operator' => 'contains', 'value' => 'invoice'],
                ],
            ],
            [
                'type' => 'group',
                'operator' => 'not',
                'conditions' => [
                    ['field' => 'name', 'operator' => 'contains', 'value' => 'draft'],
                ],
            ],
        ],
        'logic' => 'and',
        'action' => 'trash',
        'priority' => 0,
        'is_enabled' => true,
    ]);

    $evaluator = app(RuleEvaluator::class);

    expect($evaluator->matches($rule, 'invoice.png', 100, time()))->toBeTrue();
    expect($evaluator->matches($rule, 'invoice-draft.pdf', 100, time()))->toBeFalse();
});

test('a changed file is not moved by a stale preview even when its timestamp is unchanged', function () {
    $folderPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-'.Str::uuid();
    File::makeDirectory($folderPath);

    try {
        $sourcePath = $folderPath.DIRECTORY_SEPARATOR.'report.pdf';
        $destinationPath = $folderPath.DIRECTORY_SEPARATOR.'report-archived.pdf';
        File::put($sourcePath, 'first');

        $this->post(route('folders.store'), ['folder_path' => $folderPath]);
        $this->post(route('rules.store'), [
            'name' => 'Archive reports',
            'logic' => 'and',
            'conditions' => [['field' => 'name', 'operator' => 'contains', 'value' => 'report']],
            'action' => 'rename',
            'target' => 'report-archived.pdf',
        ]);

        $folder = WatchedFolder::query()->firstOrFail();
        $this->post(route('folders.preview', $folder));
        $this->post(route('folders.preview', $folder));
        $previewedTimestamp = filemtime($sourcePath);

        File::put($sourcePath, 'other');
        touch($sourcePath, $previewedTimestamp);
        clearstatcache(true, $sourcePath);

        $this->post(route('preview.run'), ['confirmation' => '1'])->assertRedirect();

        expect(file_get_contents($sourcePath))->toBe('other');
        expect(is_file($destinationPath))->toBeFalse();
    } finally {
        File::deleteDirectory($folderPath);
    }
});
