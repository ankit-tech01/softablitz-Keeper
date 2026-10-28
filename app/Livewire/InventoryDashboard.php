<?php

namespace App\Livewire;

use App\Jobs\ScanDeviceInventory;
use App\Models\InventoryFile;
use App\Models\InventoryScan;
use App\Models\WatchedFolder;
use App\Services\FolderPathService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Native\Desktop\Dialog;
use Native\Desktop\Facades\Shell;

class InventoryDashboard extends Component
{
    use WithPagination;

    public string $directoryPath = '';

    public string $selectedDirectory = '';

    public ?string $statusMessage = null;

    public bool $statusIsError = false;

    #[On('keeper-workspace-reset')]
    public function clearAfterWorkspaceReset(): void
    {
        $this->reset('directoryPath', 'selectedDirectory', 'statusMessage');
        $this->statusIsError = false;
        $this->resetPage();
        $this->resetErrorBag();
    }

    public function browseDirectory(FolderPathService $paths): void
    {
        if (! config('nativephp-internal.running')) {
            $this->statusMessage = 'Folder browsing is available in the Sanctum Keeper desktop app.';
            $this->statusIsError = true;

            return;
        }

        $selectedPath = Dialog::new()
            ->title('Choose a directory to scan')
            ->defaultPath(is_dir($this->directoryPath) ? $this->directoryPath : base_path())
            ->button('Choose directory')
            ->folders()
            ->open();

        if (! is_string($selectedPath) || $selectedPath === '') {
            return;
        }

        $this->resetErrorBag('directoryPath');
        $this->directoryPath = $paths->resolveWatchedFolder($selectedPath, 'directoryPath');
        $this->selectedDirectory = $this->directoryPath;
    }

    public function updatedSelectedDirectory(string $path): void
    {
        if ($path !== '') {
            $this->directoryPath = $path;
            $this->validateDirectoryPath();
        }
    }

    public function updatedDirectoryPath(string $path): void
    {
        if ($path !== $this->selectedDirectory) {
            $this->selectedDirectory = '';
        }

        $this->validateDirectoryPath();
    }

    private function validateDirectoryPath(): void
    {
        $validated = $this->validateOnly('directoryPath', [
            'directoryPath' => ['required', 'string', 'max:4096'],
        ]);
        app(FolderPathService::class)->resolveWatchedFolder($validated['directoryPath'], 'directoryPath');
    }

    public function startScan(FolderPathService $paths): void
    {
        $this->resetErrorBag('directoryPath');
        $validated = $this->validate([
            'directoryPath' => ['required', 'string', 'max:4096'],
        ]);

        if (InventoryScan::query()->whereIn('status', ['queued', 'scanning'])->exists()) {
            $this->addError('directoryPath', 'A directory scan is already running. Wait for it to finish before starting another.');

            return;
        }

        $directory = $paths->resolveWatchedFolder($validated['directoryPath'], 'directoryPath');
        $scan = InventoryScan::query()->create([
            'roots' => [$directory],
            'status' => 'queued',
        ]);

        ScanDeviceInventory::dispatch($scan->id);
        $this->directoryPath = $directory;
        $this->selectedDirectory = $directory;
        $this->resetPage();
        $this->statusMessage = 'Directory scan started. Progress and matching files will appear here as they are found.';
        $this->statusIsError = false;
    }

    public function openInventoryFile(int $fileId): void
    {
        $activeScanId = InventoryScan::query()
            ->whereIn('status', ['queued', 'scanning'])
            ->latest('id')
            ->value('id');
        $displayedScanId = $activeScanId ?? InventoryScan::query()
            ->where('status', 'completed')
            ->when(
                $this->selectedDirectory !== '',
                fn ($query) => $query->whereJsonContains('roots', $this->selectedDirectory),
            )
            ->latest('id')
            ->value('id');
        $file = InventoryFile::query()
            ->whereKey($fileId)
            ->where('inventory_scan_id', $displayedScanId)
            ->firstOrFail();
        $path = realpath($file->path);

        if ($path === false || $path !== $file->path || ! is_file($path)) {
            $this->statusMessage = 'This file is no longer available at its scanned location.';
            $this->statusIsError = true;

            return;
        }

        if (! config('nativephp-internal.running')) {
            $this->statusMessage = 'Open this inventory in the Sanctum Keeper desktop app to launch files.';
            $this->statusIsError = true;

            return;
        }

        $error = Shell::openFile($path);

        if ($error !== '') {
            $this->statusMessage = "The file could not be opened: {$error}";
            $this->statusIsError = true;

            return;
        }

        $this->statusMessage = null;
        $this->statusIsError = false;
    }

    public function cancelScan(): void
    {
        $scan = InventoryScan::query()
            ->whereIn('status', ['queued', 'scanning'])
            ->latest('id')
            ->first();

        if ($scan === null) {
            $this->statusMessage = 'There is no active directory scan to cancel.';
            $this->statusIsError = true;

            return;
        }

        $scan->update([
            'status' => 'cancelled',
            'completed_at' => now(),
            'error_message' => 'The directory scan was cancelled by the user.',
        ]);

        $this->statusMessage = 'Directory scan cancelled. The last completed inventory remains available below.';
        $this->statusIsError = false;
        $this->resetPage();
    }

    public function deleteSelectedDirectory(): void
    {
        $directory = $this->selectedDirectory;

        if ($directory === '') {
            $this->statusMessage = 'Select a saved scan directory before deleting its inventory.';

            return;
        }

        $scans = InventoryScan::query()->get()->filter(
            fn (InventoryScan $scan): bool => in_array($directory, $scan->roots ?? [], true),
        );

        if ($scans->isEmpty()) {
            $this->statusMessage = 'There is no saved inventory for the selected directory.';

            return;
        }

        if ($scans->contains(fn (InventoryScan $scan): bool => in_array($scan->status, ['queued', 'scanning'], true))) {
            $this->statusMessage = 'Wait for the directory scan to finish before deleting its saved inventory.';

            return;
        }

        DB::transaction(function () use ($scans): void {
            InventoryScan::query()->whereKey($scans->modelKeys())->delete();
        });

        $this->directoryPath = '';
        $this->selectedDirectory = '';
        $this->resetPage();
        $this->statusMessage = 'Saved inventory deleted. Files on disk were not changed.';
    }

    public function render(): View
    {
        $scans = InventoryScan::query()->latest('id')->get();
        $latestScan = $scans->first();
        $activeScan = $scans->first(
            fn (InventoryScan $scan): bool => in_array($scan->status, ['queued', 'scanning'], true),
        );
        $isScanning = $activeScan !== null;
        $selectedScan = $this->selectedDirectory === ''
            ? null
            : $scans->first(fn (InventoryScan $scan): bool => in_array($this->selectedDirectory, $scan->roots ?? [], true));
        $latestScan = $activeScan ?? $selectedScan ?? $latestScan;
        $completedScan = $this->selectedDirectory === ''
            ? $scans->first(fn (InventoryScan $scan): bool => $scan->status === 'completed')
            : $scans->first(fn (InventoryScan $scan): bool => $scan->status === 'completed'
                && in_array($this->selectedDirectory, $scan->roots ?? [], true));
        $displayScan = $isScanning ? $activeScan : $completedScan;
        $inventoryFiles = $displayScan?->files()->orderBy('path')->paginate(25);
        $inventoryTypes = $displayScan?->files()
            ->selectRaw('extension, COUNT(*) as files_count')
            ->groupBy('extension')
            ->orderByDesc('files_count')
            ->get() ?? collect();
        $scannedDirectories = $scans
            ->flatMap(fn (InventoryScan $scan): array => $scan->roots ?? [])
            ->filter(fn (mixed $path): bool => is_string($path))
            ->unique()
            ->values();
        $watchedDirectories = WatchedFolder::query()->orderBy('name')->pluck('path');
        $directories = $scannedDirectories->concat($watchedDirectories)->unique()->sort()->values();

        return view('livewire.inventory-dashboard', [
            'latestScan' => $latestScan,
            'isScanning' => $isScanning,
            'displayScan' => $displayScan,
            'inventoryFiles' => $inventoryFiles,
            'inventoryTypes' => $inventoryTypes,
            'directories' => $directories,
            'hasSelectedScan' => $scannedDirectories->contains($this->selectedDirectory),
        ]);
    }
}
