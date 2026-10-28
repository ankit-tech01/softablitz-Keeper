<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f6f7f4">
    <title>Sanctum Keeper — Local file organizer</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>
    @php($tab = request()->query('tab', 'overview'))
    <div class="app-shell">

        <aside class="sidebar">
            <a class="brand" href="{{ route('home') }}" wire:navigate data-navigate-noscroll>
                <span class="brand-mark">K</span>
                <span><strong>keeper</strong><small>THE SANCTUM DESKTOP</small></span>
            </a>

            <div class="nav-label">WORKSPACE</div>
            <nav class="nav-links" aria-label="Main navigation">
                <a class="{{ $tab === 'overview' ? 'active' : '' }}" href="{{ route('home', ['tab' => 'overview']) }}"
                    wire:navigate data-navigate-noscroll><span>01</span> Overview</a>
                <a class="{{ $tab === 'folders' ? 'active' : '' }}" href="{{ route('home', ['tab' => 'folders']) }}"
                    wire:navigate data-navigate-noscroll><span>02</span> Watched folders</a>
                <a class="{{ $tab === 'rules' ? 'active' : '' }}" href="{{ route('home', ['tab' => 'rules']) }}"
                    wire:navigate data-navigate-noscroll><span>03</span> Rule studio</a>
                <a class="{{ $tab === 'preview' ? 'active' : '' }}" href="{{ route('home', ['tab' => 'preview']) }}"
                    wire:navigate data-navigate-noscroll><span>04</span> Dry-run preview</a>
                <a class="{{ $tab === 'activity' ? 'active' : '' }}" href="{{ route('home', ['tab' => 'activity']) }}"
                    wire:navigate data-navigate-noscroll><span>05</span> Activity & undo</a>
                <a class="{{ $tab === 'inventory' ? 'active' : '' }}"
                    href="{{ route('home', ['tab' => 'inventory']) }}" wire:navigate
                    data-navigate-noscroll><span>06</span> File inventory</a>
                <a class="{{ $tab === 'queue' ? 'active' : '' }}"
                    href="{{ route('home', ['tab' => 'queue']) }}#queue-diagnostics" wire:navigate><span>07</span> Queue diagnostics</a>
                <a class="{{ $tab === 'trash' ? 'active' : '' }}"
                    href="{{ route('home', ['tab' => 'trash']) }}#keeper-trash" wire:navigate><span>08</span> Keeper trash</a>
            </nav>

            <div class="sidebar-bottom">
                <div class="private-card">
                    <div class="private-icon">↗</div>
                    <div><strong>Private by design</strong>
                        <p>Files stay on this device.<br>No cloud. No account.</p>
                    </div>
                </div>
                <div class="sidebar-version"><span class="status-dot"></span> LOCAL INSTANCE <span>v1.0</span></div>
            </div>
        </aside>

        <main class="main-content">

            <header class="topbar">
                <div class="breadcrumbs">SANCTUM KEEPER <span>/</span> {{ strtoupper(str_replace('-', ' ', $tab)) }}
                </div>
                <div class="topbar-right"><span class="local-pill"><i></i> RUNNING LOCALLY</span><span
                        class="topbar-divider"></span><span class="topbar-date">{{ now()->format('D, M j') }}</span>
                </div>
            </header>

            <div class="page-wrap">
                @if ($errors->any())
                    <div class="flash flash-error" role="alert"><strong>We couldn't complete that.</strong>
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if (session('success'))
                    <div class="flash flash-success" role="status"><span>✓</span> {{ session('success') }}</div>
                @endif
                @if (session('info'))
                    <div class="flash flash-info" role="status"><span>i</span> {{ session('info') }}</div>
                @endif

                <section id="overview" class="page-heading">
                    <div>
                        <div class="eyebrow"><span class="eyebrow-line"></span> YOUR FILES, IN GOOD HANDS</div>
                        <h1>A calmer kind of <em>organized.</em></h1>
                        <p>Local-first file care, guided by rules you can preview before they run.</p>
                    </div>
                    <a class="button button-dark" href="#folders"><span>＋</span> Add a folder</a>
                </section>

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

                @livewire('inventory-dashboard')

                <section class="content-grid">
                    <div class="main-column">


                        @livewire('folder-manager')

                        @livewire('rule-manager')
                    </div>

                    <aside class="side-column">
                        @livewire('preview-summary')

                        <section class="mini-panel">
                            <div class="mini-heading">
                                <div>
                                    <div class="eyebrow">04 / RECENT ACTIVITY</div>
                                    <h3>Latest actions</h3>
                                </div><a href="{{ route('home', ['tab' => 'activity']) }}" wire:navigate
                                    data-navigate-noscroll>View all →</a>
                            </div>
                            @forelse ($entries->take(5) as $entry)
                                <div class="activity-line"><span
                                        class="activity-mark {{ $entry->status === 'completed' ? 'mark-done' : 'mark-error' }}">{{ $entry->status === 'completed' ? '✓' : '!' }}</span>
                                    <div class="activity-copy"><strong>{{ ucfirst($entry->action) }} ·
                                            {{ basename($entry->source_path) }}</strong><small>{{ $entry->created_at->diffForHumans() }}</small>
                                    </div>
                                </div>
                            @empty
                                <div class="mini-empty">Your first file action will show up here.</div>
                            @endforelse
                        </section>

                        <section class="safety-card"><span class="safety-icon">◌</span>
                            <div><strong>Undo is built in</strong>
                                <p>Every file action is recorded with a checksum. Restore one action or an entire sweep.
                                </p>
                            </div>
                        </section>
                    </aside>
                </section>

                @livewire('preview-plan')

                @livewire('activity-manager')

                <footer class="page-footer"><span>✳</span> Made to keep things in their place. <span
                        class="footer-right">LOCAL FILES · MYSQL · CHECKSUM VERIFIED</span></footer>
            </div>
        </main>
    </div>
</body>

</html>
