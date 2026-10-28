<div @if ($hasQueuedExecutions) wire:poll.3s @endif>
    @if ($message)
        <div class="flash {{ $messageIsError ? 'flash-error' : 'flash-success' }}" role="{{ $messageIsError ? 'alert' : 'status' }}">{{ $message }}</div>
    @endif
    <section class="panel activity-panel" id="activity">
        <div class="panel-heading">
            <div><div class="eyebrow">06 / CHECKSUM-VERIFIED HISTORY</div><h2>Action ledger</h2><p>Append-only file history with safe, reverse-order undo.</p></div>
            <div class="panel-heading-actions">
                <span class="count-pill">{{ $entries->count() }} recent entries</span>
                <button class="text-button text-danger" type="button" wire:click="emptyTrash" wire:confirm="Permanently delete every file in Keeper trash? These files cannot be restored." wire:loading.attr="disabled" wire:target="emptyTrash">
                    <span wire:loading.remove wire:target="emptyTrash">Empty Keeper trash</span><span wire:loading wire:target="emptyTrash">Emptying…</span>
                </button>
            </div>
        </div>
        <div class="table-scroll">
            <table><thead><tr><th>FILE</th><th>ACTION</th><th>STATUS</th><th>WHEN</th><th></th></tr></thead><tbody>
                @forelse ($entries as $entry)
                    <tr wire:key="ledger-entry-{{ $entry->id }}">
                        <td><strong>{{ basename($entry->source_path) }}</strong><small class="table-sub mono">{{ $entry->source_path }}</small><small class="table-sub mono">SHA-256 {{ substr($entry->checksum, 0, 16) }}…</small></td>
                        <td>{{ ucfirst($entry->action) }} @if ($entry->rule)<small class="table-sub">by {{ $entry->rule->name }}</small>@endif</td>
                        <td><span class="status-chip status-{{ $entry->status }}">{{ ucfirst($entry->status) }}</span>@if ($entry->error_message)<small class="table-sub status-error-text">{{ $entry->error_message }}</small>@endif</td>
                        <td>{{ $entry->created_at->format('M j, H:i') }}</td>
                        <td>
                            @if ($entry->status === 'completed' && !$entry->undone_at && $entry->action !== 'script')
                                <button class="text-button" type="button" wire:click="undoEntry({{ $entry->id }})" wire:confirm="Undo this file action?" wire:loading.attr="disabled" wire:target="undoEntry({{ $entry->id }})">
                                    <span wire:loading.remove wire:target="undoEntry({{ $entry->id }})">Undo</span><span wire:loading wire:target="undoEntry({{ $entry->id }})">Undoing…</span>
                                </button>
                            @elseif ($entry->undone_at)<span class="undone-label">Undone</span>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><div class="mini-empty">No file actions recorded yet. Your action history will live here.</div></td></tr>
                @endforelse
            </tbody></table>
        </div>
        @foreach ($entries->whereNotNull('sweep_id')->groupBy('sweep_id')->take(3) as $sweep => $sweepEntries)
            @if ($sweepEntries->where('status', 'completed')->whereNull('undone_at')->isNotEmpty())
                <button class="text-button sweep-undo" type="button" wire:click="undoSweep('{{ $sweep }}')" wire:confirm="Undo the completed actions in this sweep, newest first?" wire:loading.attr="disabled" wire:target="undoSweep('{{ $sweep }}')">
                    <span wire:loading.remove wire:target="undoSweep('{{ $sweep }}')">Undo sweep · {{ $sweepEntries->where('status', 'completed')->whereNull('undone_at')->count() }} action(s)</span><span wire:loading wire:target="undoSweep('{{ $sweep }}')">Undoing sweep…</span>
                </button>
            @endif
        @endforeach
    </section>

    <section class="panel queue-panel">
        <div class="panel-heading"><div><div class="eyebrow">07 / QUEUE DIAGNOSTICS</div><h2>Processing queue</h2><p>Completed actions stay in the action ledger above. Duplicate scans are deduplicated by file path and modification time.</p></div><span class="count-pill">{{ $executions->count() }} open jobs</span></div>
        <div class="table-scroll"><table><thead><tr><th>FILE</th><th>STATUS</th><th>ATTEMPTS</th><th>DETAILS</th><th></th></tr></thead><tbody>
            @forelse ($executions as $execution)
                <tr wire:key="file-execution-{{ $execution->id }}">
                    <td><strong>{{ basename($execution->file_path) }}</strong><small class="table-sub mono">{{ $execution->file_path }}</small></td>
                    <td><span class="status-chip status-{{ $execution->status }}">{{ ucfirst($execution->status) }}</span></td>
                    <td>{{ $execution->attempts }}</td><td class="queue-error">{{ $execution->last_error ?? '—' }}</td>
                    <td>
                        @if ($execution->status === 'failed')
                            <button class="text-button" type="button" wire:click="retryExecution({{ $execution->id }})" wire:loading.attr="disabled" wire:target="retryExecution({{ $execution->id }})">
                                <span wire:loading.remove wire:target="retryExecution({{ $execution->id }})">Retry</span><span wire:loading wire:target="retryExecution({{ $execution->id }})">Retrying…</span>
                            </button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5"><div class="mini-empty">Queue is clear. Confirm a preview to queue new file actions.</div></td></tr>
            @endforelse
        </tbody></table></div>
        <div class="worker-note"><span class="status-dot"></span><div><strong>Local worker</strong><p>The desktop app processes confirmed actions automatically. For manual Laravel runs, use <code>php artisan queue:work --queue=default</code>. For continuous folder scans, run <code>php artisan keeper:watch</code>.</p></div></div>
    </section>
</div>
