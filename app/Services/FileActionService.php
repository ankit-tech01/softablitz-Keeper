<?php

namespace App\Services;

use App\Models\AutomationRule;
use App\Models\FileExecution;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class FileActionService
{
    public function execute(FileExecution $execution, AutomationRule $rule): LedgerEntry
    {
        $source = $execution->file_path;

        if (! is_file($source) || filemtime($source) !== $execution->modified_at) {
            throw new \RuntimeException('The file changed after the preview. Run a new scan before acting.');
        }

        $checksum = hash_file('sha256', $source);

        if ($checksum === false) {
            throw new \RuntimeException('The file could not be read to verify its checksum.');
        }

        if ($checksum !== $execution->checksum) {
            throw new \RuntimeException('The file content changed after the preview. Run a new scan before acting.');
        }

        $destination = $this->destination($rule, $source);
        $entry = DB::transaction(function () use ($execution, $rule, $source, $checksum, $destination): LedgerEntry {
            return LedgerEntry::query()->create([
                'entry_uuid' => (string) Str::uuid(),
                'file_execution_id' => $execution->id,
                'automation_rule_id' => $rule->id,
                'sweep_id' => $execution->sweep_id,
                'action' => $rule->action,
                'source_path' => $source,
                'destination_path' => $destination,
                'checksum' => $checksum,
                'metadata' => ['rule_name' => $rule->name],
                'status' => 'pending',
            ]);
        });

        try {
            $this->apply($rule, $source, $destination, $checksum);
            $resultPath = $destination ?? $source;
            $resultChecksum = is_file($resultPath) ? hash_file('sha256', $resultPath) : false;

            if ($resultChecksum === false) {
                throw new \RuntimeException('The action result could not be verified.');
            }

            $entry->update([
                'status' => 'completed',
                'completed_at' => now(),
                'result_checksum' => $resultChecksum,
            ]);

            return $entry;
        } catch (Throwable $exception) {
            $entry->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function previewDestination(AutomationRule $rule, string $source): ?string
    {
        return $this->destination($rule, $source);
    }

    public function undo(LedgerEntry $entry): void
    {
        if ($entry->status !== 'completed' || $entry->undone_at !== null || $entry->action === 'script') {
            throw new \RuntimeException('This ledger entry is not eligible for undo.');
        }

        $destination = $entry->destination_path;

        if ($destination === null || ! is_file($destination)) {
            throw new \RuntimeException('The recorded destination no longer exists.');
        }

        $currentChecksum = hash_file('sha256', $destination);

        if ($currentChecksum !== $entry->checksum) {
            throw new \RuntimeException('The file has changed since this action. Undo was stopped to protect your changes.');
        }

        if ($entry->action === 'copy') {
            if (! unlink($destination)) {
                throw new \RuntimeException('The copied file could not be removed.');
            }
        } else {
            if (file_exists($entry->source_path)) {
                throw new \RuntimeException('The original location is occupied. Move the file before undoing.');
            }

            $this->moveFile($destination, $entry->source_path, $entry->checksum);
        }

        $entry->update(['undone_at' => now()]);
    }

    public function recoverPendingEntries(): int
    {
        $recoveredEntries = 0;

        foreach (LedgerEntry::query()->where('status', 'pending')->orderBy('id')->get() as $entry) {
            $sourceExists = is_file($entry->source_path);
            $destinationExists = $entry->destination_path !== null && is_file($entry->destination_path);
            $sourceMatches = $sourceExists && hash_file('sha256', $entry->source_path) === $entry->checksum;
            $destinationMatches = $destinationExists && hash_file('sha256', $entry->destination_path) === $entry->checksum;

            if ($destinationMatches) {
                if ($entry->action !== 'copy' && $sourceMatches) {
                    if (! unlink($entry->source_path)) {
                        $entry->update(['status' => 'failed', 'error_message' => 'Recovery could not finish moving the original file.']);

                        continue;
                    }
                }

                $entry->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'result_checksum' => $entry->action === 'script'
                        ? (hash_file('sha256', $entry->source_path) ?: null)
                        : $entry->checksum,
                ]);
                $recoveredEntries++;

                continue;
            }

            $entry->update([
                'status' => 'failed',
                'error_message' => 'Recovery found no destination with the expected checksum; the source was left untouched.',
            ]);
        }

        return $recoveredEntries;
    }

    private function destination(AutomationRule $rule, string $source): ?string
    {
        return match ($rule->action) {
            'rename' => dirname($source).DIRECTORY_SEPARATOR.$this->safeFileName((string) $rule->target),
            'move', 'copy' => rtrim((string) $rule->target, '\\/').DIRECTORY_SEPARATOR.basename($source),
            'trash' => storage_path('app/private/keeper-trash/'.hash('sha256', $source.'|'.(string) filemtime($source)).'_'.basename($source)),
            'script' => $source,
            default => null,
        };
    }

    private function safeFileName(string $filename): string
    {
        $filename = trim(str_replace(["\0", '/', '\\'], '', $filename));

        if ($filename === '' || $filename === '.' || $filename === '..') {
            throw new \RuntimeException('Choose a valid new file name.');
        }

        return $filename;
    }

    private function apply(AutomationRule $rule, string $source, ?string $destination, string $checksum): void
    {
        if ($rule->action === 'script') {
            $this->runScript((string) $rule->target, $source, $checksum);

            return;
        }

        if ($destination === null || $destination === '' || file_exists($destination)) {
            throw new \RuntimeException('The destination is unavailable or already contains a file.');
        }

        if (! is_dir(dirname($destination)) && ! File::makeDirectory(dirname($destination), 0755, true)) {
            throw new \RuntimeException('The destination folder could not be created.');
        }

        if ($rule->action === 'copy') {
            if (! copy($source, $destination) || hash_file('sha256', $destination) !== $checksum) {
                @unlink($destination);
                throw new \RuntimeException('The file copy could not be verified.');
            }

            return;
        }

        $this->moveFile($source, $destination, $checksum);
    }

    private function moveFile(string $source, string $destination, string $checksum): void
    {
        if (@rename($source, $destination)) {
            return;
        }

        if (! copy($source, $destination) || hash_file('sha256', $destination) !== $checksum) {
            @unlink($destination);
            throw new \RuntimeException('The file could not be safely moved to its destination.');
        }

        if (! unlink($source)) {
            @unlink($destination);
            throw new \RuntimeException('The source could not be removed after copying.');
        }
    }

    private function runScript(string $script, string $source, string $checksum): void
    {
        if (trim($script) === '') {
            throw new \RuntimeException('Add a command before enabling this script rule.');
        }

        $temporaryDirectory = storage_path('app/private/keeper-script-staging');
        File::ensureDirectoryExists($temporaryDirectory);
        $stagedPath = $temporaryDirectory.DIRECTORY_SEPARATOR.Str::uuid().'_'.basename($source);

        if (! copy($source, $stagedPath)) {
            throw new \RuntimeException('The script input could not be staged safely.');
        }

        try {
            $process = Process::fromShellCommandline(
                $script,
                dirname($source),
                ['KEEPER_FILE' => $stagedPath],
                null,
                10,
            );
            $process->run();

            if (! $process->isSuccessful()) {
                throw new \RuntimeException('The custom script failed or exceeded its 10-second timeout.');
            }

            if (! is_file($stagedPath) || hash_file('sha256', $source) !== $checksum) {
                throw new \RuntimeException('The script removed its staged input or changed the original file.');
            }

            if (! copy($stagedPath, $source)) {
                throw new \RuntimeException('The script output could not be committed to the original file.');
            }
        } finally {
            File::delete($stagedPath);
        }
    }
}
