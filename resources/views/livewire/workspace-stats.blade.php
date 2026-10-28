<section class="stats-grid" aria-label="Workspace summary">
    <article class="stat-card">
        <div class="stat-top"><span>WATCHING</span><span class="stat-icon green">◎</span></div>
        <div class="stat-value">{{ $stats['folders'] }}<small> / 3</small></div>
        <div class="stat-foot">Local folders protected</div>
    </article>
    <article class="stat-card">
        <div class="stat-top"><span>ACTIVE RULES</span><span class="stat-icon lavender">⌘</span></div>
        <div class="stat-value">{{ $stats['rules'] }}<small> rules</small></div>
        <div class="stat-foot">First match wins, every time</div>
    </article>
    <article class="stat-card">
        <div class="stat-top"><span>FILES ORGANIZED</span><span class="stat-icon peach">↗</span></div>
        <div class="stat-value">{{ number_format($stats['organized']) }}<small> files</small></div>
        <div class="stat-foot">Recorded in your action ledger</div>
    </article>
    <article class="stat-card">
        <div class="stat-top"><span>IN THE QUEUE</span><span class="stat-icon blue">◷</span></div>
        <div class="stat-value">{{ $stats['queued'] }}<small> waiting</small></div>
        <div class="stat-foot">Settled files only</div>
    </article>
</section>
