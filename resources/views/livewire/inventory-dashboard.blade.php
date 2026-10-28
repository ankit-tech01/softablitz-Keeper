<section class="panel inventory-panel" id="inventory" @if ($isScanning) wire:poll.3s @endif>
    <div class="panel-heading">
        <div>
            <div class="eyebrow">06 / DIRECTORY FILE INVENTORY</div>
            <h2>Files in a selected directory</h2>
            <p>Scan one directory and its subdirectories. Large folders and slower drives can take longer.</p>
        </div>
    </div>

    <form class="inventory-directory-form" wire:submit="startScan">
        <label class="field-label" for="inventory-directory">DIRECTORY TO SCAN</label>
        <div class="form-row">
            <div class="inventory-directory-picker">
                <select wire:model.live="selectedDirectory" aria-label="Choose a saved or watched directory">
                    <option value="">Choose a saved or watched directory (optional)</option>
                    @foreach ($directories as $directory)
                        <option value="{{ $directory }}">{{ $directory }}</option>
                    @endforeach
                </select>
                @if ($hasSelectedScan)
                    <button
                        class="inventory-directory-delete"
                        type="button"
                        wire:click="deleteSelectedDirectory"
                        wire:confirm="Delete the saved inventory for this directory? Files on disk will not be deleted."
                        wire:loading.attr="disabled"
                        wire:target="deleteSelectedDirectory"
                        aria-label="Delete saved inventory for selected directory"
                        title="Delete saved inventory"
                        @disabled($isScanning)
                    >
                        <span aria-hidden="true">×</span>
                    </button>
                @endif
            </div>
            <div class="inventory-path-picker">
                <input id="inventory-directory" wire:model.blur="directoryPath" type="text" placeholder="Paste a folder path or browse for a directory" required @error('directoryPath') aria-invalid="true" aria-describedby="inventory-directory-error" @enderror>
                <button class="button button-soft" type="button" wire:click="browseDirectory" wire:loading.attr="disabled" wire:target="browseDirectory" aria-label="Browse computer for a directory">
                    <span wire:loading.remove wire:target="browseDirectory">Browse…</span>
                    <span wire:loading wire:target="browseDirectory">Opening…</span>
                </button>
            </div>
            <div class="inventory-actions">
                <button class="button button-dark" type="submit" wire:loading.attr="disabled" @disabled($isScanning)>
                    <span wire:loading.remove wire:target="startScan">Scan directory</span>
                    <span wire:loading wire:target="startScan">Starting…</span>
                    <span>↻</span>
                </button>
                @if ($isScanning)
                    <button
                        class="button button-soft inventory-cancel-button"
                        type="button"
                        wire:click="cancelScan"
                        wire:loading.attr="disabled"
                        wire:target="cancelScan"
                        aria-label="Cancel active directory scan"
                    >
                        <span wire:loading.remove wire:target="cancelScan">Cancel scan</span>
                        <span wire:loading wire:target="cancelScan">Cancelling…</span>
                    </button>
                @endif
            </div>
        </div>
        @error('directoryPath')<small id="inventory-directory-error" class="inventory-form-error" role="alert">{{ $message }}</small>@enderror
        <small>Select a saved directory, paste a local folder path, or browse your computer. Symbolic links are skipped.</small>
    </form>

    @if ($statusMessage)
        <div class="inventory-status {{ $statusIsError ? 'inventory-status-error' : '' }}" role="{{ $statusIsError ? 'alert' : 'status' }}">{{ $statusMessage }}</div>
    @endif

    @if ($isScanning)
        <div class="inventory-status" role="status">
            <span class="status-dot"></span>
            Directory scan {{ $latestScan->status }} · {{ number_format($latestScan->files_count) }} files found so far
            · {{ number_format($latestScan->total_bytes) }} B processed.
            {{ $latestScan->roots[0] ?? '' }}.
            This may take a while for large directories.
        </div>
    @elseif ($latestScan?->status === 'failed')
        <div class="inventory-status inventory-status-error" role="alert">
            {{ $latestScan->error_message }} The last completed inventory remains available below.
        </div>
    @endif

    @if ($displayScan)
        <div class="inventory-stats">
            <article><span>FILES FOUND</span><strong>{{ number_format($displayScan->files_count) }}</strong></article>
            <article><span>PDF FILES</span><strong>{{ number_format($inventoryTypes->firstWhere('extension', 'pdf')?->files_count ?? 0) }}</strong></article>
            <article><span>TOTAL SIZE</span><strong>{{ number_format($displayScan->total_bytes) }} B</strong></article>
            <article><span>{{ $isScanning ? 'SCAN STARTED' : 'LAST SCANNED' }}</span><strong>{{ ($isScanning ? $displayScan->started_at : $displayScan->completed_at)?->diffForHumans() ?? '—' }}</strong></article>
        </div>

        <div class="inventory-types">
            <strong>File types</strong>
            @forelse ($inventoryTypes as $type)
                <span>{{ $type->extension !== '' ? '.'.$type->extension : 'No extension' }} <b>{{ number_format($type->files_count) }}</b></span>
            @empty
                <span>No files found yet</span>
            @endforelse
        </div>

        @if ($displayScan->inaccessible_directories > 0)
            <div class="inventory-note">{{ number_format($displayScan->inaccessible_directories) }} directories could not be read and were skipped.</div>
        @endif

        <div class="table-wrap inventory-table-wrap">
            <table>
                <thead><tr><th>FILE NAME</th><th>LOCATION</th><th>SIZE</th><th>CREATED</th><th>MODIFIED</th></tr></thead>
                <tbody>
                    @forelse ($inventoryFiles as $file)
                        <tr wire:key="inventory-file-{{ $file->id }}">
                            <td>
                                <button
                                    class="inventory-file-link"
                                    type="button"
                                    wire:click="openInventoryFile({{ $file->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="openInventoryFile({{ $file->id }})"
                                    aria-label="Open {{ $file->name }} with its default app"
                                    title="Open with default app"
                                >{{ $file->name }}{{ $file->extension !== '' ? ' · '.strtoupper($file->extension) : '' }}</button>
                            </td>
                            <td class="mono">{{ $file->path }}</td>
                            <td>{{ number_format($file->size) }} B</td>
                            <td>{{ date('M j, Y H:i', $file->created_at_timestamp) }}</td>
                            <td>{{ date('M j, Y H:i', $file->modified_at_timestamp) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">{{ $isScanning ? 'Waiting for files to be found…' : 'No files were found in this directory.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($inventoryFiles)
            <div class="inventory-pagination">{{ $inventoryFiles->links() }}</div>
        @endif
    @else
        <div class="empty-state"><div class="empty-mark">⌕</div><strong>No directory inventory yet</strong><p>Select a directory and start a scan to see file names, types, sizes, and locations.</p></div>
    @endif
</section>
