<?php

namespace App\Livewire;

use App\Jobs\ScanDeviceInventory;
use App\Models\InventoryScan;
use App\Models\WatchedFolder;
use App\Services\FolderPathService;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class InventoryDashboard extends Component
{
    use WithPagination;

    public string $directoryPath = '';

    public string $selectedDirectory = '';

    public ?string $message = null;

    public function updatedSelectedDirectory(string $path): void
    {
        if ($path !== '') {
            $this->directoryPath = $path;
        }
    }

    public function startScan(FolderPathService $paths): void
    {
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
        $this->message = 'Directory scan started. Progress and matching files will appear here as they are found.';
    }

    public function render(): View
    {
        $latestScan = InventoryScan::query()->latest('id')->first();
        $isScanning = in_array($latestScan?->status, ['queued', 'scanning'], true);
        $completedScan = InventoryScan::query()->where('status', 'completed')->latest('id')->first();
        $displayScan = $isScanning ? $latestScan : $completedScan;
        $inventoryFiles = $displayScan?->files()->orderBy('path')->paginate(25);
        $inventoryTypes = $displayScan?->files()
            ->selectRaw('extension, COUNT(*) as files_count')
            ->groupBy('extension')
            ->orderByDesc('files_count')
            ->get() ?? collect();

        return view('livewire.inventory-dashboard', [
            'latestScan' => $latestScan,
            'isScanning' => $isScanning,
            'displayScan' => $displayScan,
            'inventoryFiles' => $inventoryFiles,
            'inventoryTypes' => $inventoryTypes,
            'directories' => WatchedFolder::query()->orderBy('name')->get(),
        ]);
    }
}
