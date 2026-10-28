<?php

use App\Models\AutomationRule;
use App\Models\FileExecution;
use App\Models\FileObservation;
use App\Models\InventoryFile;
use App\Models\InventoryScan;
use App\Models\LedgerEntry;
use App\Models\WatchedFolder;
use App\Services\DeviceInventoryScanner;
use App\Services\FolderScanner;
use App\Services\RuleEvaluator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
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
        expect(FileExecution::query()->firstOrFail()->status)->toBe('completed');

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

test('a concurrent observation insert is reconciled without failing the folder scan', function () {
    $folderPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-scan-race-'.Str::uuid();
    File::makeDirectory($folderPath);
    $filePath = $folderPath.DIRECTORY_SEPARATOR.'report.pdf';
    File::put($filePath, 'stable report');
    $realPath = realpath($filePath);

    if ($realPath === false) {
        throw new RuntimeException('The test file path could not be resolved.');
    }

    $normalizedPath = str_replace('\\', '/', $realPath);

    if (DIRECTORY_SEPARATOR === '\\') {
        $normalizedPath = strtolower($normalizedPath);
    }

    $pathHash = hash('sha256', $normalizedPath);
    $folder = WatchedFolder::query()->create([
        'name' => 'Race test',
        'path' => $folderPath,
        'path_hash' => hash('sha256', $folderPath),
        'is_active' => true,
    ]);
    $insertCompetingObservation = true;

    FileObservation::saving(function (FileObservation $observation) use (&$insertCompetingObservation, $filePath, $realPath, $pathHash, $folder): void {
        if (! $insertCompetingObservation) {
            return;
        }

        $insertCompetingObservation = false;
        DB::table('file_observations')->insert([
            'watched_folder_id' => $folder->id,
            'path_hash' => $pathHash,
            'file_path' => $realPath,
            'file_size' => filesize($filePath),
            'modified_at' => filemtime($filePath),
            'stable_scans' => 1,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        $stableFiles = app(FolderScanner::class)->scan($folder);

        expect($stableFiles)->toHaveCount(1);
        expect(FileObservation::query()->where('watched_folder_id', $folder->id)->count())->toBe(1);
        expect(FileObservation::query()->where('watched_folder_id', $folder->id)->firstOrFail()->stable_scans)->toBe(2);
    } finally {
        FileObservation::flushEventListeners();
        File::deleteDirectory($folderPath);
    }
});

test('a rule can be deleted without removing its history and queued actions are cancelled', function () {
    $rule = AutomationRule::query()->create([
        'name' => 'Delete me',
        'conditions' => [['field' => 'extension', 'operator' => 'equals', 'value' => 'pdf']],
        'logic' => 'and',
        'action' => 'trash',
        'priority' => 0,
        'is_enabled' => true,
        'is_approved' => true,
    ]);
    $anotherRule = AutomationRule::query()->create([
        'name' => 'Keep me',
        'conditions' => [['field' => 'name', 'operator' => 'contains', 'value' => 'report']],
        'logic' => 'and',
        'action' => 'trash',
        'priority' => 1,
        'is_enabled' => true,
        'is_approved' => true,
    ]);
    $execution = FileExecution::query()->create([
        'unique_key' => Str::uuid()->toString(),
        'automation_rule_id' => $rule->id,
        'file_path' => 'C:\\Users\\test\\report.pdf',
        'modified_at' => time(),
        'checksum' => str_repeat('a', 64),
        'status' => 'queued',
    ]);
    $entry = LedgerEntry::query()->create([
        'entry_uuid' => Str::uuid()->toString(),
        'automation_rule_id' => $rule->id,
        'action' => 'trash',
        'source_path' => 'C:\\Users\\test\\old-report.pdf',
        'checksum' => str_repeat('b', 64),
        'status' => 'completed',
    ]);

    $this->withSession(['keeper.preview' => ['folder_id' => 1, 'plans' => []]])
        ->delete(route('rules.destroy', $rule))
        ->assertRedirect(route('home', ['tab' => 'rules']))
        ->assertSessionMissing('keeper.preview');

    expect(AutomationRule::query()->find($rule->id))->toBeNull();
    expect($execution->fresh()->status)->toBe('ignored');
    expect($execution->fresh()->automation_rule_id)->toBeNull();
    expect($entry->fresh())->not->toBeNull();
    expect($entry->fresh()->automation_rule_id)->toBeNull();
    expect($anotherRule->fresh()->is_approved)->toBeFalse();
});

test('a device inventory scan records recursive file details and file type totals', function () {
    $folderPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-inventory-'.Str::uuid();
    File::makeDirectory($folderPath.DIRECTORY_SEPARATOR.'nested', 0755, true);

    try {
        File::put($folderPath.DIRECTORY_SEPARATOR.'report.pdf', 'pdf content');
        File::put($folderPath.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'notes.txt', 'text content');
        $scan = InventoryScan::query()->create(['roots' => [$folderPath], 'status' => 'queued']);

        app(DeviceInventoryScanner::class)->scan($scan, [$folderPath]);

        expect($scan->fresh()->status)->toBe('completed');
        expect($scan->fresh()->files_count)->toBe(2);
        expect($scan->fresh()->total_bytes)->toBe(strlen('pdf content') + strlen('text content'));
        expect($scan->files()->where('extension', 'pdf')->firstOrFail()->name)->toBe('report.pdf');
        expect($scan->files()->where('name', 'notes.txt')->firstOrFail()->path)
            ->toBe(realpath($folderPath.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'notes.txt'));
        expect(InventoryFile::query()->count())->toBe(2);
        $this->get(route('home', ['tab' => 'inventory']))
            ->assertOk()
            ->assertSee('Files in a selected directory')
            ->assertSee('report.pdf')
            ->assertSee('notes.txt');
    } finally {
        File::deleteDirectory($folderPath);
    }
});

test('the inventory section can queue an on-demand directory scan', function () {
    Queue::fake();
    $folderPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-inventory-queue-'.Str::uuid();
    File::makeDirectory($folderPath);

    try {
        \Livewire\Livewire::test(\App\Livewire\InventoryDashboard::class)
            ->set('directoryPath', $folderPath)
            ->call('startScan')
            ->assertHasNoErrors();

        $scan = InventoryScan::query()->firstOrFail();
        expect($scan->status)->toBe('queued');
        expect($scan->roots)->toBe([realpath($folderPath)]);
        Queue::assertPushed(\App\Jobs\ScanDeviceInventory::class);
    } finally {
        File::deleteDirectory($folderPath);
    }
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

test('preview skips checksum hashing for files that match no enabled rule', function () {
    $folderPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-unmatched-'.Str::uuid();
    File::makeDirectory($folderPath);

    try {
        File::put($folderPath.DIRECTORY_SEPARATOR.'installer.exe', 'unmatched file');
        $this->post(route('folders.store'), ['folder_path' => $folderPath])->assertRedirect();
        $this->post(route('rules.store'), [
            'name' => 'Separate PDF files',
            'logic' => 'and',
            'conditions' => [['field' => 'extension', 'operator' => 'equals', 'value' => 'pdf']],
            'action' => 'trash',
        ])->assertRedirect();

        $folder = WatchedFolder::query()->firstOrFail();
        $this->post(route('folders.preview', $folder))->assertRedirect();
        $this->post(route('folders.preview', $folder))->assertRedirect();

        $plan = session('keeper.preview.plans.0');
        expect($plan['rule_id'])->toBeNull();
        expect($plan['checksum'])->toBeNull();
        expect($plan['rule_name'])->toBeNull();
    } finally {
        File::deleteDirectory($folderPath);
    }
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
