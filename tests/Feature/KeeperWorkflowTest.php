<?php

use App\Jobs\ExecuteFileAction;
use App\Jobs\ScanDeviceInventory;
use App\Livewire\ActivityManager;
use App\Livewire\FolderManager;
use App\Livewire\InventoryDashboard;
use App\Livewire\PreviewPlan;
use App\Livewire\ResetWorkspace;
use App\Livewire\RuleManager;
use App\Livewire\WorkspaceStats;
use App\Models\AutomationRule;
use App\Models\FileExecution;
use App\Models\FileObservation;
use App\Models\InventoryFile;
use App\Models\InventoryScan;
use App\Models\LedgerEntry;
use App\Models\WatchedFolder;
use App\Services\DeviceInventoryScanner;
use App\Services\FileDispatcher;
use App\Services\FolderScanner;
use App\Services\KeeperTrashService;
use App\Services\RuleEvaluator;
use App\Support\ByteFormatter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Native\Desktop\Facades\Shell;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Tests\TestCase;

test('execution timeouts show a friendly error instead of a debug screen', function () {
    Route::get('/test/timeout', static function () {
        $message = 'Maximum execution time of 30 seconds exceeded';

        throw new FatalError($message, 0, [
            'type' => E_ERROR,
            'message' => $message,
            'file' => __FILE__,
            'line' => __LINE__,
        ]);
    });

    $this->get('/test/timeout')
        ->assertStatus(500)
        ->assertSee('This task took longer than expected')
        ->assertSee('Try the task again with a smaller folder or fewer files.')
        ->assertDontSee('Maximum execution time')
        ->assertDontSee('Stack trace');

    $this->getJson('/test/timeout')
        ->assertStatus(500)
        ->assertJsonPath('message', 'This task exceeded the time limit and was interrupted.')
        ->assertJsonPath('next_steps.0', 'Try the task again with a smaller folder or fewer files.');
});

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

test('the Livewire folder form adds a watched folder without a redirect', function () {
    $folderPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-livewire-folder-'.Str::uuid();
    File::makeDirectory($folderPath);

    try {
        Livewire::test(FolderManager::class)
            ->assertSee('Paste a folder path or browse your computer')
            ->assertDontSee('readonly')
            ->set('folderPath', $folderPath)
            ->call('addFolder')
            ->assertHasNoErrors()
            ->assertSee('Folder added');

        expect(WatchedFolder::query()->where('path', realpath($folderPath))->exists())->toBeTrue();
    } finally {
        File::deleteDirectory($folderPath);
    }
});

test('the watched-folder form can browse for a folder', function () {
    $folderPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-livewire-browse-folder-'.Str::uuid();
    File::makeDirectory($folderPath);
    Http::fake(['*dialog/open' => Http::response(['result' => [$folderPath]])]);
    config(['nativephp-internal.running' => true]);

    try {
        Livewire::test(FolderManager::class)
            ->call('browseFolder')
            ->assertHasNoErrors()
            ->assertSet('folderPath', realpath($folderPath));

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/dialog/open')
            && in_array('openDirectory', $request['properties'], true));
    } finally {
        File::deleteDirectory($folderPath);
    }
});

test('the Livewire folder form validates its path as the user types', function () {
    $folderPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-livewire-validation-'.Str::uuid();
    File::makeDirectory($folderPath);

    try {
        Livewire::test(FolderManager::class)
            ->set('folderPath', $folderPath.'-missing')
            ->assertHasErrors(['folderPath'])
            ->set('folderPath', $folderPath)
            ->assertHasNoErrors(['folderPath']);
    } finally {
        File::deleteDirectory($folderPath);
    }
});

test('the Livewire rule form validates and creates a rule', function () {
    Livewire::test(RuleManager::class)
        ->set('name', 'Archive invoices')
        ->set('conditions', [['field' => 'name', 'operator' => 'contains', 'value' => 'invoice']])
        ->set('action', 'trash')
        ->call('saveRule')
        ->assertHasNoErrors()
        ->assertSee('Rule saved');

    expect(AutomationRule::query()->firstOrFail())
        ->name->toBe('Archive invoices')
        ->action->toBe('trash');
});

test('file size rule conditions accept readable units and reject text operators', function () {
    Livewire::test(RuleManager::class)
        ->call('toggleBuilder')
        ->set('name', 'Large files')
        ->set('conditions', [['field' => 'size', 'operator' => 'greater_than', 'value' => '100MB']])
        ->assertSee('100MB')
        ->set('action', 'trash')
        ->call('saveRule')
        ->assertHasNoErrors();

    expect(AutomationRule::query()->firstOrFail()->conditions)
        ->toBe([['field' => 'size', 'operator' => 'greater_than', 'value' => '104857600']]);

    Livewire::test(RuleManager::class)
        ->set('name', 'Invalid size rule')
        ->set('conditions', [['field' => 'size', 'operator' => 'contains', 'value' => '100MB']])
        ->set('action', 'trash')
        ->call('saveRule')
        ->assertHasErrors(['conditions']);
});

test('toggling a rule disables its approval until it is previewed again', function () {
    $rule = AutomationRule::query()->create([
        'name' => 'Archive invoices',
        'conditions' => [['field' => 'name', 'operator' => 'contains', 'value' => 'invoice']],
        'logic' => 'and',
        'action' => 'trash',
        'priority' => 0,
        'is_enabled' => true,
        'is_approved' => true,
    ]);
    $otherRule = AutomationRule::query()->create([
        'name' => 'Archive receipts',
        'conditions' => [['field' => 'name', 'operator' => 'contains', 'value' => 'receipt']],
        'logic' => 'and',
        'action' => 'trash',
        'priority' => 1,
        'is_enabled' => true,
        'is_approved' => true,
    ]);

    Livewire::test(RuleManager::class)
        ->call('toggleRule', $rule->id)
        ->assertSee('Rule status updated.');

    expect($rule->fresh())
        ->is_enabled->toBeFalse()
        ->is_approved->toBeFalse();
    expect($otherRule->fresh()->is_approved)->toBeFalse();
});

test('the rule form stays open while editing and closes after saving', function () {
    Livewire::test(RuleManager::class)
        ->call('toggleBuilder')
        ->assertSet('builderOpen', true)
        ->set('name', 'Archive invoices')
        ->assertSee('Save rule')
        ->set('conditions', [['field' => 'name', 'operator' => 'contains', 'value' => 'invoice']])
        ->set('action', 'trash')
        ->call('saveRule')
        ->assertHasNoErrors()
        ->assertSet('builderOpen', false)
        ->assertSee('Build a new rule');
});

test('rule target controls and guidance match the selected action', function () {
    Livewire::test(RuleManager::class)
        ->call('toggleBuilder')
        ->assertSee('NEW FILE NAME')
        ->assertSee('e.g. invoice-2026.pdf')
        ->set('target', 'invoice-2026.pdf')
        ->set('action', 'move')
        ->assertSet('target', '')
        ->assertSee('DESTINATION FOLDER FOR MOVED FILES')
        ->assertSee('Paste a destination folder path or browse')
        ->assertSee('Browse…')
        ->set('action', 'copy')
        ->assertSee('DESTINATION FOLDER FOR COPIES')
        ->assertSee('a copy is created there')
        ->set('action', 'trash')
        ->assertSee('recoverable trash')
        ->assertDontSee('Browse…')
        ->set('action', 'script')
        ->assertSee('COMMAND TO RUN')
        ->assertSee('powershell -File');
});

test('move and copy rules accept a pasted destination folder path', function () {
    $folderPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-pasted-rule-destination-'.Str::uuid();
    File::makeDirectory($folderPath);

    try {
        foreach (['move', 'copy'] as $action) {
            Livewire::test(RuleManager::class)
                ->set('name', ucfirst($action).' pasted destination')
                ->set('conditions', [['field' => 'name', 'operator' => 'contains', 'value' => 'invoice']])
                ->set('action', $action)
                ->set('target', $folderPath)
                ->call('saveRule')
                ->assertHasNoErrors()
                ->assertSee('Rule saved');
        }

        expect(AutomationRule::query()->whereIn('action', ['move', 'copy'])->pluck('target')->all())
            ->toBe([realpath($folderPath), realpath($folderPath)]);
    } finally {
        File::deleteDirectory($folderPath);
    }
});

test('watched folders are automatically previewed every 30 seconds with counts but no filenames', function () {
    $folderPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-auto-preview-'.Str::uuid();
    File::makeDirectory($folderPath);
    File::put($folderPath.DIRECTORY_SEPARATOR.'private-arrival.pdf', 'private contents');
    $folder = WatchedFolder::query()->create([
        'name' => 'Automatic preview test',
        'path' => realpath($folderPath),
        'path_hash' => hash('sha256', realpath($folderPath)),
        'is_active' => true,
    ]);

    try {
        Livewire::test(FolderManager::class)
            ->assertSee('wire:poll.30s="autoPreview"', false)
            ->assertSee('data-auto-preview-countdown', false)
            ->assertSee('data-folder-new-count="'.$folder->id.'"', false)
            ->call('autoPreview')
            ->call('autoPreview')
            ->assertSet('newFilesByFolder', [$folder->id => 0])
            ->assertDontSee('private-arrival.pdf')
            ->call('autoPreview')
            ->assertSet('newFilesByFolder', [$folder->id => 0]);

        File::put($folderPath.DIRECTORY_SEPARATOR.'new-private-arrival.pdf', 'new private contents');

        Livewire::test(FolderManager::class)
            ->call('autoPreview')
            ->assertSet('newFilesByFolder', [$folder->id => 1])
            ->assertSee('+1 since previous scan')
            ->assertDontSee('new-private-arrival.pdf');

        expect(session('keeper.preview.automatic'))->toBeTrue();
        expect(session('keeper.preview.plans'))->toHaveCount(1);
        expect(session('keeper.preview.plans.0.folder_id'))->toBe($folder->id);
    } finally {
        File::deleteDirectory($folderPath);
    }
});

test('automatic preview shows details for matching files but hides unmatched filenames', function () {
    $folderPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-auto-matched-preview-'.Str::uuid();
    File::makeDirectory($folderPath);
    File::put($folderPath.DIRECTORY_SEPARATOR.'invoice.pdf', 'matching contents');
    File::put($folderPath.DIRECTORY_SEPARATOR.'personal.pdf', 'unmatched contents');
    WatchedFolder::query()->create([
        'name' => 'Automatic matching preview test',
        'path' => realpath($folderPath),
        'path_hash' => hash('sha256', realpath($folderPath)),
        'is_active' => true,
    ]);

    try {
        $this->post(route('rules.store'), [
            'name' => 'Archive invoices',
            'logic' => 'and',
            'conditions' => [
                ['field' => 'name', 'operator' => 'contains', 'value' => 'invoice'],
            ],
            'action' => 'rename',
            'target' => 'invoice-archived.pdf',
        ])->assertRedirect();

        Livewire::test(FolderManager::class)
            ->call('autoPreview')
            ->call('autoPreview');

        Livewire::test(PreviewPlan::class)
            ->assertSee('invoice.pdf')
            ->assertSee('Archive invoices')
            ->assertSee('Rename')
            ->assertSee('invoice-archived.pdf')
            ->assertDontSee('personal.pdf')
            ->assertSee('unmatched file names remain hidden');
    } finally {
        File::deleteDirectory($folderPath);
    }
});

test('automatic preview shows new-file counts independently for each watched folder', function () {
    $folderPaths = [
        sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-auto-count-a-'.Str::uuid(),
        sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-auto-count-b-'.Str::uuid(),
    ];
    $folders = [];

    foreach ($folderPaths as $index => $folderPath) {
        File::makeDirectory($folderPath);
        File::put($folderPath.DIRECTORY_SEPARATOR.'existing-'.$index.'.txt', 'existing');
        $folders[] = WatchedFolder::query()->create([
            'name' => 'Automatic count '.($index + 1),
            'path' => realpath($folderPath),
            'path_hash' => hash('sha256', realpath($folderPath)),
            'is_active' => true,
        ]);
    }

    try {
        $component = Livewire::test(FolderManager::class)
            ->call('autoPreview')
            ->call('autoPreview');

        foreach (range(1, 2) as $number) {
            File::put($folderPaths[0].DIRECTORY_SEPARATOR.'new-a-'.$number.'.txt', 'new');
        }

        foreach (range(1, 3) as $number) {
            File::put($folderPaths[1].DIRECTORY_SEPARATOR.'new-b-'.$number.'.txt', 'new');
        }

        $component->call('autoPreview')
            ->assertSet('newFilesByFolder', [
                $folders[0]->id => 2,
                $folders[1]->id => 3,
            ])
            ->assertSee('+2 since previous scan')
            ->assertSee('+3 since previous scan')
            ->assertDontSee('new-a-1.txt')
            ->assertDontSee('new-b-1.txt');
    } finally {
        foreach ($folderPaths as $folderPath) {
            File::deleteDirectory($folderPath);
        }
    }
});

test('move and copy rule destinations can be selected with the native folder picker', function () {
    $folderPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-rule-destination-'.Str::uuid();
    File::makeDirectory($folderPath);
    Http::fake(['*dialog/open' => Http::response(['result' => [$folderPath]])]);
    config(['nativephp-internal.running' => true]);

    try {
        foreach (['move', 'copy'] as $action) {
            Livewire::test(RuleManager::class)
                ->set('action', $action)
                ->call('browseTarget')
                ->assertHasNoErrors()
                ->assertSet('target', realpath($folderPath));
        }

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/dialog/open')
            && in_array('openDirectory', $request['properties'], true));
    } finally {
        File::deleteDirectory($folderPath);
    }
});

test('the Livewire rule form validates fields as the user types', function () {
    Livewire::test(RuleManager::class)
        ->set('name', 'A')
        ->set('name', '')
        ->assertHasErrors(['name'])
        ->set('name', 'Archive invoices')
        ->assertHasNoErrors(['name'])
        ->set('conditions', [['field' => 'name', 'operator' => 'contains', 'value' => 'invoice']])
        ->set('conditions', [['field' => 'name', 'operator' => 'contains', 'value' => '']])
        ->assertHasErrors(['conditions'])
        ->set('conditions', [['field' => 'name', 'operator' => 'contains', 'value' => 'invoice']])
        ->assertHasNoErrors(['conditions']);
});

test('resetting the workspace clears Keeper data, queued jobs, and trash', function () {
    $folder = WatchedFolder::query()->create([
        'name' => 'Reset folder',
        'path' => sys_get_temp_dir(),
        'path_hash' => hash('sha256', 'reset-folder'),
        'is_active' => true,
    ]);
    $rule = AutomationRule::query()->create([
        'name' => 'Reset rule',
        'conditions' => [['field' => 'name', 'operator' => 'contains', 'value' => 'report']],
        'logic' => 'and',
        'action' => 'trash',
        'priority' => 0,
        'is_enabled' => true,
    ]);
    FileObservation::query()->create([
        'watched_folder_id' => $folder->id,
        'path_hash' => hash('sha256', 'observed-file'),
        'file_path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'report.pdf',
        'file_size' => 100,
        'modified_at' => time(),
        'stable_scans' => 2,
        'last_seen_at' => now(),
    ]);
    $execution = FileExecution::query()->create([
        'unique_key' => hash('sha256', 'queued-reset-execution'),
        'watched_folder_id' => $folder->id,
        'automation_rule_id' => $rule->id,
        'file_path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'report.pdf',
        'modified_at' => time(),
        'checksum' => str_repeat('a', 64),
        'status' => 'queued',
    ]);
    LedgerEntry::query()->create([
        'entry_uuid' => Str::uuid()->toString(),
        'file_execution_id' => $execution->id,
        'automation_rule_id' => $rule->id,
        'action' => 'trash',
        'source_path' => $execution->file_path,
        'checksum' => str_repeat('a', 64),
        'status' => 'completed',
    ]);
    $scan = InventoryScan::query()->create([
        'roots' => [sys_get_temp_dir()],
        'status' => 'completed',
        'files_count' => 1,
    ]);
    InventoryFile::query()->create([
        'inventory_scan_id' => $scan->id,
        'path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'report.pdf',
        'name' => 'report.pdf',
        'extension' => 'pdf',
        'size' => 100,
        'created_at_timestamp' => time(),
        'modified_at_timestamp' => time(),
    ]);

    $now = time();
    DB::table('jobs')->insert([
        [
            'queue' => 'default',
            'payload' => '{"displayName":"ExecuteFileAction"}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now,
            'created_at' => $now,
        ],
        [
            'queue' => 'default',
            'payload' => '{"displayName":"UnrelatedJob"}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now,
            'created_at' => $now,
        ],
    ]);

    $trash = new class extends KeeperTrashService
    {
        public function empty(): int
        {
            return 2;
        }
    };
    app()->instance(KeeperTrashService::class, $trash);

    Livewire::test(ResetWorkspace::class)
        ->call('resetWorkspace')
        ->assertSee('2 trash file(s) were permanently deleted')
        ->assertDontSee('role="alert"');

    expect(WatchedFolder::query()->count())->toBe(0);
    expect(AutomationRule::query()->count())->toBe(0);
    expect(FileObservation::query()->count())->toBe(0);
    expect(FileExecution::query()->count())->toBe(0);
    expect(LedgerEntry::query()->count())->toBe(0);
    expect(InventoryScan::query()->count())->toBe(0);
    expect(InventoryFile::query()->count())->toBe(0);
    expect(DB::table('jobs')->count())->toBe(1);
    expect(DB::table('jobs')->value('payload'))->toContain('UnrelatedJob');
    Livewire::test(WorkspaceStats::class)
        ->assertSee('0<small> / 3</small>', false)
        ->assertSee('0<small> rules</small>', false)
        ->assertSee('0<small> files</small>', false)
        ->assertSee('0<small> waiting</small>', false);
});

test('reset refuses to run while a file action is processing', function () {
    FileExecution::query()->create([
        'unique_key' => hash('sha256', 'processing-reset-execution'),
        'file_path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'report.pdf',
        'modified_at' => time(),
        'checksum' => str_repeat('a', 64),
        'status' => 'processing',
    ]);

    $trash = new class extends KeeperTrashService
    {
        public function empty(): int
        {
            throw new RuntimeException('Trash should not be emptied while an action is processing.');
        }
    };
    app()->instance(KeeperTrashService::class, $trash);

    Livewire::test(ResetWorkspace::class)
        ->call('resetWorkspace')
        ->assertSee('A file action is still running')
        ->assertSee('role="alert"', false);

    expect(FileExecution::query()->count())->toBe(1);
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

test('a directory inventory scan records recursive file details and file type totals', function () {
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
            ->assertSee($folderPath)
            ->assertSee('report.pdf')
            ->assertSee('notes.txt')
            ->assertSee(ByteFormatter::format($scan->fresh()->total_bytes));
    } finally {
        File::deleteDirectory($folderPath);
    }
});

test('saved directory inventory can be deleted without deleting files on disk', function () {
    $folderPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-inventory-delete-'.Str::uuid();
    File::makeDirectory($folderPath);
    $filePath = $folderPath.DIRECTORY_SEPARATOR.'report.pdf';
    File::put($filePath, 'report contents');
    $scan = InventoryScan::query()->create([
        'roots' => [$folderPath],
        'status' => 'completed',
        'files_count' => 1,
    ]);
    $inventoryFile = InventoryFile::query()->create([
        'inventory_scan_id' => $scan->id,
        'path' => $filePath,
        'name' => 'report.pdf',
        'extension' => 'pdf',
        'size' => strlen('report contents'),
        'created_at_timestamp' => filectime($filePath),
        'modified_at_timestamp' => filemtime($filePath),
    ]);

    try {
        Livewire::test(InventoryDashboard::class)
            ->set('selectedDirectory', $folderPath)
            ->assertSee('Delete saved inventory for selected directory')
            ->call('deleteSelectedDirectory')
            ->assertHasNoErrors()
            ->assertSee('Saved inventory deleted.')
            ->assertDontSee($folderPath);

        expect(InventoryScan::query()->find($scan->id))->toBeNull();
        expect(InventoryFile::query()->find($inventoryFile->id))->toBeNull();
        expect(is_file($filePath))->toBeTrue();
    } finally {
        File::deleteDirectory($folderPath);
    }
});

test('the active inventory scan can be cancelled', function () {
    $folderPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-inventory-cancel-'.Str::uuid();
    File::makeDirectory($folderPath);
    $scan = InventoryScan::query()->create([
        'roots' => [$folderPath],
        'status' => 'scanning',
        'files_count' => 0,
        'total_bytes' => 0,
    ]);

    try {
        Livewire::test(InventoryDashboard::class)
            ->set('selectedDirectory', $folderPath)
            ->call('cancelScan')
            ->assertHasNoErrors()
            ->assertSee('Directory scan cancelled.');

        expect($scan->fresh()->status)->toBe('cancelled');
        expect($scan->fresh()->error_message)->toBe('The directory scan was cancelled by the user.');
    } finally {
        File::deleteDirectory($folderPath);
    }
});

test('saved directory inventory cannot be deleted while its scan is running', function () {
    $folderPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-inventory-active-'.Str::uuid();
    File::makeDirectory($folderPath);
    $scan = InventoryScan::query()->create([
        'roots' => [$folderPath],
        'status' => 'scanning',
    ]);

    try {
        Livewire::test(InventoryDashboard::class)
            ->set('selectedDirectory', $folderPath)
            ->call('deleteSelectedDirectory')
            ->assertSee('Wait for the directory scan to finish')
            ->assertSee($folderPath);

        expect(InventoryScan::query()->find($scan->id))->not->toBeNull();
    } finally {
        File::deleteDirectory($folderPath);
    }
});

test('inventory file links open files with their default desktop application', function () {
    $folderPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-inventory-open-'.Str::uuid();
    File::makeDirectory($folderPath);
    $filePath = $folderPath.DIRECTORY_SEPARATOR.'report.pdf';
    File::put($filePath, 'report contents');
    $scan = InventoryScan::query()->create([
        'roots' => [$folderPath],
        'status' => 'completed',
        'files_count' => 1,
    ]);
    $file = InventoryFile::query()->create([
        'inventory_scan_id' => $scan->id,
        'path' => realpath($filePath),
        'name' => 'report.pdf',
        'extension' => 'pdf',
        'size' => strlen('report contents'),
        'created_at_timestamp' => filectime($filePath),
        'modified_at_timestamp' => filemtime($filePath),
    ]);
    $shell = Shell::fake();
    config(['nativephp-internal.running' => true]);

    try {
        Livewire::test(InventoryDashboard::class)
            ->assertSee('Open report.pdf with its default app')
            ->call('openInventoryFile', $file->id)
            ->assertHasNoErrors();

        $shell->assertOpenedFile(realpath($filePath));
    } finally {
        File::deleteDirectory($folderPath);
    }
});

test('completed file actions leave the processing queue but remain in the action ledger', function () {
    $path = 'C:\\Users\\test\\Documents\\completed-report.pdf';
    $checksum = hash('sha256', 'completed report');
    $execution = FileExecution::query()->create([
        'unique_key' => hash('sha256', $path),
        'file_path' => $path,
        'modified_at' => 1,
        'checksum' => $checksum,
        'status' => 'completed',
        'attempts' => 1,
        'processed_at' => now(),
    ]);
    $entry = LedgerEntry::query()->create([
        'entry_uuid' => Str::uuid()->toString(),
        'file_execution_id' => $execution->id,
        'action' => 'rename',
        'source_path' => $path,
        'destination_path' => 'C:\\Users\\test\\Documents\\archived-report.pdf',
        'checksum' => $checksum,
        'result_checksum' => $checksum,
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    Livewire::test(ActivityManager::class)
        ->assertSee('Queue is clear.')
        ->assertSee('completed-report.pdf')
        ->assertSee('Completed')
        ->assertDontSee('file-execution-'.$execution->id)
        ->assertSee('ledger-entry-'.$entry->id);
});

test('a failed action can be removed from the queue without deleting its ledger history', function () {
    $execution = FileExecution::query()->create([
        'unique_key' => hash('sha256', 'failed-action-remove'),
        'file_path' => 'C:\\Users\\test\\Documents\\failed-report.pdf',
        'modified_at' => 1,
        'checksum' => hash('sha256', 'failed report'),
        'status' => 'failed',
        'attempts' => 5,
        'last_error' => 'Destination unavailable.',
    ]);
    $entry = LedgerEntry::query()->create([
        'entry_uuid' => Str::uuid()->toString(),
        'file_execution_id' => $execution->id,
        'action' => 'move',
        'source_path' => $execution->file_path,
        'checksum' => $execution->checksum,
        'status' => 'failed',
    ]);

    Livewire::test(ActivityManager::class)
        ->assertSee('Remove all failed (1)')
        ->call('removeFailedExecution', $execution->id)
        ->assertSee('Failed action removed from the queue.')
        ->assertDontSee('file-execution-'.$execution->id);

    expect(FileExecution::query()->whereKey($execution->id)->exists())->toBeFalse();
    expect($entry->fresh())->not->toBeNull();
    expect($entry->fresh()->file_execution_id)->toBeNull();
});

test('all failed actions can be removed without clearing active or completed queue history', function () {
    $failed = FileExecution::query()->create([
        'unique_key' => hash('sha256', 'bulk-failed-action'),
        'file_path' => 'C:\\Users\\test\\Documents\\failed-report.pdf',
        'modified_at' => 1,
        'checksum' => hash('sha256', 'failed report'),
        'status' => 'failed',
    ]);
    $queued = FileExecution::query()->create([
        'unique_key' => hash('sha256', 'bulk-queued-action'),
        'file_path' => 'C:\\Users\\test\\Documents\\queued-report.pdf',
        'modified_at' => 1,
        'checksum' => hash('sha256', 'queued report'),
        'status' => 'queued',
    ]);
    $processing = FileExecution::query()->create([
        'unique_key' => hash('sha256', 'bulk-processing-action'),
        'file_path' => 'C:\\Users\\test\\Documents\\processing-report.pdf',
        'modified_at' => 1,
        'checksum' => hash('sha256', 'processing report'),
        'status' => 'processing',
    ]);

    Livewire::test(ActivityManager::class)
        ->call('removeAllFailedExecutions')
        ->assertSee('1 failed action(s) removed from the queue.');

    expect(FileExecution::query()->whereKey($failed->id)->exists())->toBeFalse();
    expect(FileExecution::query()->whereKey($queued->id)->exists())->toBeTrue();
    expect(FileExecution::query()->whereKey($processing->id)->exists())->toBeTrue();
});

test('emptying Keeper trash removes nested files and keeps the trash folder', function () {
    $trashPath = storage_path('app/private/keeper-trash');
    File::makeDirectory($trashPath.DIRECTORY_SEPARATOR.'nested', 0755, true);
    File::put($trashPath.DIRECTORY_SEPARATOR.'top-level.txt', 'top level');
    File::put($trashPath.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'nested.txt', 'nested');

    try {
        Livewire::test(ActivityManager::class)
            ->call('emptyTrash')
            ->assertHasNoErrors()
            ->assertSee('2 file(s) permanently removed from Keeper trash.');

        expect(File::allFiles($trashPath, true))->toBe([]);
        expect(is_dir($trashPath))->toBeTrue();
    } finally {
        File::deleteDirectory($trashPath);
    }
});

test('empty Keeper trash removes files recorded by completed trash actions and keeps their history', function () {
    $trashPath = storage_path('app/private/keeper-trash');
    File::makeDirectory($trashPath, 0755, true);
    $trashedFile = $trashPath.DIRECTORY_SEPARATOR.'completed-report.pdf';
    File::put($trashedFile, 'trashed report');
    $entry = LedgerEntry::query()->create([
        'entry_uuid' => Str::uuid()->toString(),
        'action' => 'trash',
        'source_path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'report.pdf',
        'destination_path' => $trashedFile,
        'checksum' => hash('sha256', 'trashed report'),
        'result_checksum' => hash('sha256', 'trashed report'),
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    try {
        Livewire::test(ActivityManager::class)
            ->call('emptyTrash')
            ->assertHasNoErrors()
            ->assertSee('1 file(s) permanently removed from Keeper trash.');

        expect(is_file($trashedFile))->toBeFalse();
        expect($entry->fresh())->not->toBeNull();
        expect($entry->fresh()->status)->toBe('completed');
    } finally {
        File::deleteDirectory($trashPath);
    }
});

test('Keeper trash lists completed trash actions and can permanently delete one file', function () {
    $trashPath = storage_path('app/private/keeper-trash');
    File::makeDirectory($trashPath, 0755, true);
    $trashedFile = $trashPath.DIRECTORY_SEPARATOR.'delete-one.pdf';
    File::put($trashedFile, 'trash contents');
    $entry = LedgerEntry::query()->create([
        'entry_uuid' => Str::uuid()->toString(),
        'action' => 'trash',
        'source_path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'delete-one.pdf',
        'destination_path' => $trashedFile,
        'checksum' => hash('sha256', 'trash contents'),
        'result_checksum' => hash('sha256', 'trash contents'),
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    try {
        Livewire::test(ActivityManager::class)
            ->assertSee('08 / KEEPER TRASH')
            ->assertSee('delete-one.pdf')
            ->assertSee('Undo')
            ->call('deleteTrashFile', realpath($trashedFile))
            ->assertHasNoErrors()
            ->assertSee('File permanently deleted from Keeper trash.')
            ->assertDontSee('Delete permanently');

        expect(is_file($trashedFile))->toBeFalse();
        expect($entry->fresh())->not->toBeNull();
        expect($entry->fresh()->undone_at)->toBeNull();
    } finally {
        File::deleteDirectory($trashPath);
    }
});

test('action ledger shows ten recent entries and paginates all history ten entries at a time', function () {
    $entries = collect(range(1, 12))->map(function (int $number): LedgerEntry {
        return LedgerEntry::query()->create([
            'entry_uuid' => Str::uuid()->toString(),
            'action' => 'rename',
            'source_path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.sprintf('ledger-file-%02d.txt', $number),
            'destination_path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.sprintf('ledger-file-%02d-renamed.txt', $number),
            'checksum' => hash('sha256', "ledger contents {$number}"),
            'result_checksum' => hash('sha256', "ledger contents {$number}"),
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    });

    $activity = Livewire::test(ActivityManager::class)
        ->assertSee('10 recent entries')
        ->assertSee('ledger-file-12.txt')
        ->assertDontSee('ledger-file-02.txt')
        ->call('toggleAllEntries')
        ->assertSet('showAllEntries', true)
        ->assertSee('12 total entries')
        ->assertSee('Show recent actions')
        ->assertDontSee('ledger-file-02.txt')
        ->assertSee('Next');

    $activity->call('gotoPage', 2)
        ->assertSee('ledger-file-02.txt')
        ->assertSee('ledger-file-01.txt')
        ->assertDontSee('ledger-file-12.txt');

    $activity->call('toggleAllEntries')
        ->assertSet('showAllEntries', false)
        ->assertSee('ledger-file-12.txt');
});

test('workspace sidebar links to queue diagnostics and Keeper trash in page order', function () {
    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSee('07</span> Queue diagnostics', false)
        ->assertSee('08</span> Keeper trash', false)
        ->assertSee(route('home', ['tab' => 'queue']).'#queue-diagnostics', false)
        ->assertSee(route('home', ['tab' => 'trash']).'#keeper-trash', false);

    $html = $response->getContent();

    expect(strpos($html, 'id="queue-diagnostics"'))
        ->toBeLessThan(strpos($html, 'id="keeper-trash"'));
});

test('a trash file can be opened in its default app from the Keeper trash section', function () {
    $trashPath = storage_path('app/private/keeper-trash');
    File::makeDirectory($trashPath, 0755, true);
    $trashedFile = $trashPath.DIRECTORY_SEPARATOR.'open-from-trash.pdf';
    File::put($trashedFile, 'trash contents');
    LedgerEntry::query()->create([
        'entry_uuid' => Str::uuid()->toString(),
        'action' => 'trash',
        'source_path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'open-from-trash.pdf',
        'destination_path' => $trashedFile,
        'checksum' => hash('sha256', 'trash contents'),
        'result_checksum' => hash('sha256', 'trash contents'),
        'status' => 'completed',
        'completed_at' => now(),
    ]);
    $shell = Shell::fake();
    config(['nativephp-internal.running' => true]);

    try {
        Livewire::test(ActivityManager::class)
            ->assertSee('Open open-from-trash.pdf with its default app')
            ->call('openTrashFile', realpath($trashedFile))
            ->assertHasNoErrors();

        $shell->assertOpenedFile(realpath($trashedFile));
    } finally {
        File::deleteDirectory($trashPath);
    }
});

test('individual Keeper trash deletion cannot remove a file outside the trash folder', function () {
    $outsidePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-trash-protected-'.Str::uuid().'.txt';
    File::put($outsidePath, 'keep this file');

    try {
        Livewire::test(ActivityManager::class)
            ->call('deleteTrashFile', $outsidePath)
            ->assertSee('This file is not available in Keeper trash.')
            ->assertSee('07 / QUEUE DIAGNOSTICS');

        expect(is_file($outsidePath))->toBeTrue();
    } finally {
        File::delete($outsidePath);
    }
});

test('Keeper trash undo restores a trashed file to its original location', function () {
    $folderPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-trash-undo-'.Str::uuid();
    $trashPath = storage_path('app/private/keeper-trash');
    File::makeDirectory($folderPath, 0755, true);
    File::makeDirectory($trashPath, 0755, true);
    $sourcePath = $folderPath.DIRECTORY_SEPARATOR.'restore-me.pdf';
    $trashedFile = $trashPath.DIRECTORY_SEPARATOR.'restore-me.pdf';
    File::put($trashedFile, 'restore contents');
    $entry = LedgerEntry::query()->create([
        'entry_uuid' => Str::uuid()->toString(),
        'action' => 'trash',
        'source_path' => $sourcePath,
        'destination_path' => $trashedFile,
        'checksum' => hash('sha256', 'restore contents'),
        'result_checksum' => hash('sha256', 'restore contents'),
        'status' => 'completed',
        'completed_at' => now(),
    ]);

    try {
        Livewire::test(ActivityManager::class)
            ->call('undoTrashFile', $entry->id)
            ->assertHasNoErrors()
            ->assertSee('File restored from Keeper trash.')
            ->assertSee('0 files');

        expect(is_file($sourcePath))->toBeTrue();
        expect(is_file($trashedFile))->toBeFalse();
        expect($entry->fresh()->undone_at)->not->toBeNull();
    } finally {
        File::deleteDirectory($folderPath);
        File::deleteDirectory($trashPath);
    }
});

test('empty Keeper trash reports already empty when no files remain', function () {
    $trashPath = storage_path('app/private/keeper-trash');
    File::makeDirectory($trashPath, 0755, true);

    try {
        Livewire::test(ActivityManager::class)
            ->call('emptyTrash')
            ->assertHasNoErrors()
            ->assertSee('Keeper trash is already empty.');
    } finally {
        File::deleteDirectory($trashPath);
    }
});

test('file actions are dispatched to the default queue used by the local worker', function () {
    Queue::fake();
    $folderPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-dispatch-'.Str::uuid();
    File::makeDirectory($folderPath);

    try {
        $path = $folderPath.DIRECTORY_SEPARATOR.'report.pdf';
        File::put($path, 'queued report');
        $folder = WatchedFolder::query()->create([
            'name' => 'Dispatch test',
            'path' => $folderPath,
            'path_hash' => hash('sha256', $folderPath),
            'is_active' => true,
        ]);
        $rule = AutomationRule::query()->create([
            'name' => 'Archive report',
            'conditions' => [['field' => 'extension', 'operator' => 'equals', 'value' => 'pdf']],
            'logic' => 'and',
            'action' => 'rename',
            'target' => 'archived.pdf',
            'priority' => 0,
            'is_enabled' => true,
            'is_approved' => true,
        ]);
        $modifiedAt = filemtime($path);
        $checksum = hash_file('sha256', $path);

        if ($modifiedAt === false || $checksum === false) {
            throw new RuntimeException('The test file could not be inspected.');
        }

        app(FileDispatcher::class)->dispatch(
            $folder,
            $path,
            $modifiedAt,
            $checksum,
            $rule,
            Str::uuid()->toString(),
        );

        Queue::assertPushed(ExecuteFileAction::class, fn (ExecuteFileAction $job): bool => $job->queue === null);
    } finally {
        File::deleteDirectory($folderPath);
    }
});

test('the inventory section can queue an on-demand directory scan', function () {
    Queue::fake();
    $folderPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-inventory-queue-'.Str::uuid();
    File::makeDirectory($folderPath);

    try {
        Livewire::test(InventoryDashboard::class)
            ->assertSee('Paste a folder path or browse for a directory')
            ->assertDontSee('readonly')
            ->set('directoryPath', $folderPath)
            ->call('startScan')
            ->assertHasNoErrors()
            ->assertSee('Directory scan started.');

        $scan = InventoryScan::query()->firstOrFail();
        expect($scan->status)->toBe('queued');
        expect($scan->roots)->toBe([realpath($folderPath)]);
        Queue::assertPushed(ScanDeviceInventory::class);
    } finally {
        File::deleteDirectory($folderPath);
    }
});

test('the inventory form can browse for a directory', function () {
    $folderPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'keeper-inventory-browse-'.Str::uuid();
    File::makeDirectory($folderPath);
    Http::fake(['*dialog/open' => Http::response(['result' => [$folderPath]])]);
    config(['nativephp-internal.running' => true]);

    try {
        Livewire::test(InventoryDashboard::class)
            ->call('browseDirectory')
            ->assertHasNoErrors()
            ->assertSet('directoryPath', realpath($folderPath))
            ->assertSet('selectedDirectory', realpath($folderPath));

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/dialog/open')
            && in_array('openDirectory', $request['properties'], true));
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
    /** @var TestCase $this */
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
    /** @var TestCase $this */
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
