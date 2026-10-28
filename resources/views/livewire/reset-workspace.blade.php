<div class="workspace-reset">
    <button
        class="button button-danger"
        type="button"
        wire:click="resetWorkspace"
        wire:confirm="Permanently delete Keeper's trash files and clear all watched folders, rules, scans, queued work, and activity history? Files already moved or copied to your regular folders will not be changed."
        wire:loading.attr="disabled"
        wire:target="resetWorkspace"
    >
        <span wire:loading.remove wire:target="resetWorkspace">Reset application</span>
        <span wire:loading wire:target="resetWorkspace">Resetting…</span>
    </button>
    @if ($message)
        <small class="{{ $messageIsError ? 'workspace-reset-error' : 'workspace-reset-success' }}" role="{{ $messageIsError ? 'alert' : 'status' }}">{{ $message }}</small>
    @endif
</div>
