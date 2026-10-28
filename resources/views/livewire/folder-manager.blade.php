<section class="panel" id="folders">
    <div class="panel-heading">
        <div>
            <div class="eyebrow">01 / WATCH & SETTLE</div>
            <h2>Your watched folders</h2>
            <p>Keeper checks for finished files before anything can happen.</p>
        </div>
        <span class="count-pill">{{ $folders->count() }} of 3</span>
    </div>

    @if ($message)
        <div class="inventory-status" role="status">{{ $message }}</div>
    @endif
    @error('folderPath')<div class="inventory-status inventory-status-error" role="alert">{{ $message }}</div>@enderror

    @forelse ($folders as $folder)
        <article class="folder-row" wire:key="watched-folder-{{ $folder->id }}">
            <div class="folder-icon">↳</div>
            <div class="folder-details">
                <div class="folder-title">{{ $folder->name }} <span class="folder-state {{ $folder->is_active ? 'state-active' : 'state-paused' }}"><i></i>{{ $folder->is_active ? 'WATCHING' : 'DRIVE OFFLINE' }}</span></div>
                <div class="folder-path mono">{{ $folder->path }}</div>
                <div class="folder-meta">{{ $folder->observations_count }} observed file(s) <span>·</span> {{ $folder->last_scanned_at?->diffForHumans() ?? 'Not scanned yet' }}</div>
            </div>
            <div class="folder-actions">
                <button class="button button-soft" type="button" wire:click="previewFolder({{ $folder->id }})" wire:loading.attr="disabled" wire:target="previewFolder({{ $folder->id }})">
                    <span wire:loading.remove wire:target="previewFolder({{ $folder->id }})">Preview scan</span>
                    <span wire:loading wire:target="previewFolder({{ $folder->id }})">Scanning…</span>
                </button>
                <button class="icon-button" type="button" wire:click="removeFolder({{ $folder->id }})" wire:confirm="Remove this watched folder? Existing ledger history will be kept." wire:loading.attr="disabled" wire:target="removeFolder({{ $folder->id }})" aria-label="Remove folder">×</button>
            </div>
        </article>
    @empty
        <div class="empty-state"><div class="empty-mark">↳</div><strong>No folders watched yet</strong><p>Add a local folder to start building your private workflow.</p></div>
    @endforelse

    @if ($folders->count() < 3)
        <form class="add-folder-form" wire:submit="addFolder">
            <label for="folder_path">ADD A LOCAL FOLDER</label>
            <div class="form-row">
                <input id="folder_path" wire:model="folderPath" type="text" placeholder="Choose a folder from your computer" readonly required @error('folderPath') aria-invalid="true" aria-describedby="folder-path-error" @enderror>
                <button class="button button-soft" type="button" wire:click="browseFolder" wire:loading.attr="disabled" wire:target="browseFolder" aria-label="Browse computer for a folder">
                    <span wire:loading.remove wire:target="browseFolder">Browse…</span>
                    <span wire:loading wire:target="browseFolder">Opening…</span>
                </button>
                <button class="button button-dark" type="submit" wire:loading.attr="disabled" wire:target="addFolder">
                    <span wire:loading.remove wire:target="addFolder">Add folder</span>
                    <span wire:loading wire:target="addFolder">Adding…</span><span>→</span>
                </button>
            </div>
            <small>Browse to choose a folder on this device. System and root folders are blocked.</small>
            @error('folderPath')<small id="folder-path-error" class="inventory-form-error" role="alert">{{ $message }}</small>@enderror
        </form>
    @else
        <div class="limit-note">You’re using all three folder spots. Remove one to watch a different folder.</div>
    @endif
</section>
