<?php

namespace App\Services;

use App\Jobs\ExecuteFileAction;
use App\Jobs\ScanDeviceInventory;
use App\Models\AutomationRule;
use App\Models\FileExecution;
use App\Models\InventoryScan;
use App\Models\LedgerEntry;
use App\Models\WatchedFolder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class WorkspaceResetService
{
    /**
     * Permanently remove Keeper's private trash files and reset its persisted workspace state.
     */
    public function reset(): int
    {
        $this->assertQueueCanBeSafelyCleared();

        return DB::transaction(function (): int {
            $executions = FileExecution::query()->orderBy('id')->lockForUpdate()->get(['id', 'status']);

            if ($executions->contains(fn (FileExecution $execution): bool => $execution->status === 'processing')) {
                throw new RuntimeException('A file action is still running. Wait for it to finish before resetting Keeper.');
            }

            $scans = InventoryScan::query()->orderBy('id')->lockForUpdate()->get(['id', 'status']);

            if ($scans->contains(fn (InventoryScan $scan): bool => $scan->status === 'scanning')) {
                throw new RuntimeException('A directory scan is still running. Wait for it to finish before resetting Keeper.');
            }

            $this->assertNoReservedKeeperJobs();
            $deletedTrashFiles = app(KeeperTrashService::class)->empty();
            $this->deletePendingKeeperJobs();
            $this->deleteFailedKeeperJobs();

            LedgerEntry::query()->delete();
            FileExecution::query()->delete();
            InventoryScan::query()->delete();
            AutomationRule::query()->delete();
            WatchedFolder::query()->delete();

            session()->forget('keeper.preview');

            return $deletedTrashFiles;
        });
    }

    private function assertQueueCanBeSafelyCleared(): void
    {
        $driver = config('queue.default');

        if ($driver === 'sync') {
            return;
        }

        if ($driver !== 'database') {
            throw new RuntimeException('Reset requires Keeper to use the database or sync queue so pending file actions can be safely cancelled.');
        }

        $queueConnection = config('queue.connections.database.connection') ?: DB::getDefaultConnection();

        if ($queueConnection !== DB::getDefaultConnection()) {
            throw new RuntimeException('Reset requires the database queue and Keeper records to use the same database connection.');
        }

        $failedConfig = config('queue.failed');

        if (($failedConfig['driver'] ?? null) === 'database-uuids'
            && ($failedConfig['database'] ?? null) !== null
            && $failedConfig['database'] !== DB::getDefaultConnection()) {
            throw new RuntimeException('Reset requires failed jobs and Keeper records to use the same database connection.');
        }
    }

    private function assertNoReservedKeeperJobs(): void
    {
        $jobs = $this->queueTable();

        if ($jobs === null) {
            return;
        }

        $reservedJobs = $this->keeperJobQuery($jobs)
            ->whereNotNull('reserved_at')
            ->exists();

        if ($reservedJobs) {
            throw new RuntimeException('A Keeper background task is already running. Wait for it to finish before resetting Keeper.');
        }
    }

    private function deletePendingKeeperJobs(): void
    {
        $jobs = $this->queueTable();

        if ($jobs !== null) {
            $this->keeperJobQuery($jobs)->delete();
        }
    }

    private function deleteFailedKeeperJobs(): void
    {
        $failedConfig = config('queue.failed');

        if (($failedConfig['driver'] ?? null) !== 'database-uuids') {
            return;
        }

        $connection = $failedConfig['database'] ?? DB::getDefaultConnection();

        if ($connection !== DB::getDefaultConnection()) {
            throw new RuntimeException('Reset requires failed jobs and Keeper records to use the same database connection.');
        }

        $table = $failedConfig['table'] ?? 'failed_jobs';

        if (Schema::connection($connection)->hasTable($table)) {
            $this->keeperJobQuery(DB::connection($connection)->table($table))->delete();
        }
    }

    private function queueTable(): ?Builder
    {
        $connection = DB::connection();
        $table = config('queue.connections.database.table', 'jobs');

        return Schema::connection($connection->getName())->hasTable($table)
            ? $connection->table($table)
            : null;
    }

    private function keeperJobQuery(Builder $query): Builder
    {
        return $query->where(function ($query): void {
            $query->where('payload', 'like', '%'.class_basename(ExecuteFileAction::class).'%')
                ->orWhere('payload', 'like', '%'.class_basename(ScanDeviceInventory::class).'%');
        });
    }
}
