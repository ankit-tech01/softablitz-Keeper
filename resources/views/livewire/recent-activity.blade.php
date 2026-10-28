<section class="mini-panel">
    <div class="mini-heading">
        <div>
            <div class="eyebrow">04 / RECENT ACTIVITY</div>
            <h3>Latest actions</h3>
        </div>
        <a href="{{ route('home', ['tab' => 'activity']) }}" wire:navigate data-navigate-noscroll>View all →</a>
    </div>
    @forelse ($entries as $entry)
        <div class="activity-line">
            <span class="activity-mark {{ $entry->status === 'completed' ? 'mark-done' : 'mark-error' }}">{{ $entry->status === 'completed' ? '✓' : '!' }}</span>
            <div class="activity-copy">
                <strong>{{ ucfirst($entry->action) }} · {{ basename($entry->source_path) }}</strong>
                <small>{{ $entry->created_at->diffForHumans() }}</small>
            </div>
        </div>
    @empty
        <div class="mini-empty">Your first file action will show up here.</div>
    @endforelse
</section>
