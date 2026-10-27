<?php

namespace App\Jobs;

use App\Models\InventoryScan;
use App\Services\DeviceInventoryScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScanDeviceInventory implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public int $scanId) {}

    public function handle(DeviceInventoryScanner $scanner): void
    {
        $scan = InventoryScan::query()->findOrFail($this->scanId);

        $claimed = InventoryScan::query()
            ->whereKey($scan->id)
            ->where('status', 'queued')
            ->update([
                'status' => 'scanning',
                'started_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        try {
            $roots = $scanner->roots();

            if ($roots === []) {
                throw new \RuntimeException('No mounted drives were found to scan.');
            }

            $scanner->scan($scan, $roots);
        } catch (Throwable $exception) {
            $scan->update([
                'status' => 'failed',
                'error_message' => 'The device scan could not be completed. Check the application log for details.',
                'completed_at' => now(),
            ]);

            Log::error('The device inventory scan failed.', [
                'scan_id' => $scan->id,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        InventoryScan::query()->whereKey($this->scanId)->update([
            'status' => 'failed',
            'error_message' => 'The device scan could not be completed. Check the application log for details.',
            'completed_at' => now(),
        ]);
    }
}
