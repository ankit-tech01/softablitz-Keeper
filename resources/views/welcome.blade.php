<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f6f7f4">
    <title>Sanctum Keeper — Local file organizer</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    @php($tab = request()->query('tab', 'overview'))
    <div class="app-shell">
        <aside class="sidebar">
            <a class="brand" href="{{ route('home') }}">
                <span class="brand-mark">K</span>
                <span><strong>keeper</strong><small>THE SANCTUM DESKTOP</small></span>
            </a>

            <div class="nav-label">WORKSPACE</div>
            <nav class="nav-links" aria-label="Main navigation">
                <a class="{{ $tab === 'overview' ? 'active' : '' }}" href="{{ route('home', ['tab' => 'overview']) }}"><span>01</span> Overview</a>
                <a class="{{ $tab === 'folders' ? 'active' : '' }}" href="{{ route('home', ['tab' => 'folders']) }}"><span>02</span> Watched folders</a>
                <a class="{{ $tab === 'rules' ? 'active' : '' }}" href="{{ route('home', ['tab' => 'rules']) }}"><span>03</span> Rule studio</a>
                <a class="{{ $tab === 'preview' ? 'active' : '' }}" href="{{ route('home', ['tab' => 'preview']) }}"><span>04</span> Dry-run preview</a>
                <a class="{{ $tab === 'activity' ? 'active' : '' }}" href="{{ route('home', ['tab' => 'activity']) }}"><span>05</span> Activity & undo</a>
            </nav>

            <div class="sidebar-bottom">
                <div class="private-card">
                    <div class="private-icon">↗</div>
                    <div><strong>Private by design</strong><p>Files stay on this device.<br>No cloud. No account.</p></div>
                </div>
                <div class="sidebar-version"><span class="status-dot"></span> LOCAL INSTANCE <span>v1.0</span></div>
            </div>
        </aside>

        <main class="main-content">
            <header class="topbar">
                <div class="breadcrumbs">SANCTUM KEEPER <span>/</span> {{ strtoupper(str_replace('-', ' ', $tab)) }}</div>
                <div class="topbar-right"><span class="local-pill"><i></i> RUNNING LOCALLY</span><span class="topbar-divider"></span><span class="topbar-date">{{ now()->format('D, M j') }}</span></div>
            </header>

            <div class="page-wrap">
                @if ($errors->any())
                    <div class="flash flash-error" role="alert"><strong>We couldn't complete that.</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                @endif
                @if (session('success'))
                    <div class="flash flash-success" role="status"><span>✓</span> {{ session('success') }}</div>
                @endif
                @if (session('info'))
                    <div class="flash flash-info" role="status"><span>i</span> {{ session('info') }}</div>
                @endif

                <section id="overview" class="page-heading">
                    <div><div class="eyebrow"><span class="eyebrow-line"></span> YOUR FILES, IN GOOD HANDS</div><h1>A calmer kind of <em>organized.</em></h1><p>Local-first file care, guided by rules you can preview before they run.</p></div>
                    <a class="button button-dark" href="#folders"><span>＋</span> Add a folder</a>
                </section>

                <section class="stats-grid" aria-label="Workspace summary">
                    <article class="stat-card"><div class="stat-top"><span>WATCHING</span><span class="stat-icon green">◎</span></div><div class="stat-value">{{ $stats['folders'] }}<small> / 3</small></div><div class="stat-foot">Local folders protected</div></article>
                    <article class="stat-card"><div class="stat-top"><span>ACTIVE RULES</span><span class="stat-icon lavender">⌘</span></div><div class="stat-value">{{ $stats['rules'] }}<small> rules</small></div><div class="stat-foot">First match wins, every time</div></article>
                    <article class="stat-card"><div class="stat-top"><span>FILES ORGANIZED</span><span class="stat-icon peach">↗</span></div><div class="stat-value">{{ number_format($stats['organized']) }}<small> files</small></div><div class="stat-foot">Recorded in your action ledger</div></article>
                    <article class="stat-card"><div class="stat-top"><span>IN THE QUEUE</span><span class="stat-icon blue">◷</span></div><div class="stat-value">{{ $stats['queued'] }}<small> waiting</small></div><div class="stat-foot">Settled files only</div></article>
                </section>

                <section class="content-grid">
                    <div class="main-column">
                        <section class="panel" id="folders">
                            <div class="panel-heading">
                                <div><div class="eyebrow">01 / WATCH & SETTLE</div><h2>Your watched folders</h2><p>Keeper checks for finished files before anything can happen.</p></div>
                                <span class="count-pill">{{ $folders->count() }} of 3</span>
                            </div>

                            @forelse ($folders as $folder)
                                <article class="folder-row">
                                    <div class="folder-icon">↳</div>
                                    <div class="folder-details"><div class="folder-title">{{ $folder->name }} <span class="folder-state {{ $folder->is_active ? 'state-active' : 'state-paused' }}"><i></i>{{ $folder->is_active ? 'WATCHING' : 'DRIVE OFFLINE' }}</span></div><div class="folder-path mono">{{ $folder->path }}</div><div class="folder-meta">{{ $folder->observations_count }} observed file(s) <span>·</span> {{ $folder->last_scanned_at?->diffForHumans() ?? 'Not scanned yet' }}</div></div>
                                    <div class="folder-actions">
                                        <form method="POST" action="{{ route('folders.preview', $folder) }}">@csrf<button class="button button-soft" type="submit">Preview scan</button></form>
                                        <form method="POST" action="{{ route('folders.destroy', $folder) }}" onsubmit="return confirm('Remove this watched folder? Existing ledger history will be kept.')">@csrf @method('DELETE')<button class="icon-button" type="submit" aria-label="Remove folder">×</button></form>
                                    </div>
                                </article>
                            @empty
                                <div class="empty-state"><div class="empty-mark">↳</div><strong>No folders watched yet</strong><p>Add a local folder to start building your private workflow.</p></div>
                            @endforelse

                            @if ($folders->count() < 3)
                                <form class="add-folder-form" method="POST" action="{{ route('folders.store') }}">
                                    @csrf<label for="folder_path">ADD A LOCAL FOLDER</label>
                                    <div class="form-row"><input id="folder_path" name="folder_path" type="text" placeholder="C:\Users\you\Downloads" required><button class="button button-dark" type="submit">Add folder <span>→</span></button></div>
                                    <small>Folder paths stay on this device. System and root folders are blocked.</small>
                                </form>
                            @else
                                <div class="limit-note">You’re using all three folder spots. Remove one to watch a different folder.</div>
                            @endif
                        </section>

                        <section class="panel" id="rules">
                            <div class="panel-heading">
                                <div><div class="eyebrow">02 / RULE STUDIO</div><h2>Your rules, in order</h2><p>Rules run from top to bottom. The first match gets one action.</p></div>
                                <span class="count-pill">{{ $rules->count() }} total</span>
                            </div>

                            @forelse ($rules as $index => $rule)
                                <article class="rule-row {{ $rule->is_enabled ? '' : 'rule-disabled' }}">
                                    <span class="rule-rank">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                    <div class="rule-main"><div class="rule-title">{{ $rule->name }} <span class="rule-badge">{{ $rule->logic === 'not' ? 'NOT' : strtoupper($rule->logic) }}</span></div><div class="rule-summary">
                                        @foreach ($rule->conditions as $condition)
                                            @if (($condition['type'] ?? null) === 'group')
                                                <span>{{ strtoupper($condition['operator']) }} group ({{ count($condition['conditions']) }})</span>
                                            @else
                                                <span>{{ ucfirst($condition['field'] ?? 'file') }} {{ str_replace('_', ' ', $condition['operator'] ?? '') }} {{ $condition['value'] ?? '' }}{{ ($condition['field'] ?? '') === 'size' ? ' bytes' : '' }}</span>
                                            @endif
                                            @if (!$loop->last)<b>{{ strtoupper($rule->logic) }}</b>@endif
                                        @endforeach
                                    </div><div class="rule-action"><span>THEN</span> {{ ucfirst($rule->action) }}{{ $rule->action === 'rename' ? ' to '.$rule->target : (in_array($rule->action, ['move', 'copy'], true) ? ' → '.$rule->target : '') }}{{ $rule->schedule ? ' · '.$rule->schedule : '' }} <span class="approval-badge {{ $rule->is_approved ? 'approved' : 'needs-approval' }}">{{ $rule->is_approved ? 'AUTO-RUN READY' : 'PREVIEW REQUIRED' }}</span></div></div>
                                    <div class="rule-controls">
                                        <form method="POST" action="{{ route('rules.move', $rule) }}">@csrf @method('PATCH')<input type="hidden" name="direction" value="up"><button class="icon-button" type="submit" aria-label="Move rule up" {{ $loop->first ? 'disabled' : '' }}>↑</button></form>
                                        <form method="POST" action="{{ route('rules.move', $rule) }}">@csrf @method('PATCH')<input type="hidden" name="direction" value="down"><button class="icon-button" type="submit" aria-label="Move rule down" {{ $loop->last ? 'disabled' : '' }}>↓</button></form>
                                        <form method="POST" action="{{ route('rules.toggle', $rule) }}">@csrf @method('PATCH')<button class="toggle {{ $rule->is_enabled ? 'toggle-on' : '' }}" type="submit" aria-label="{{ $rule->is_enabled ? 'Disable' : 'Enable' }} rule"><i></i></button></form>
                                    </div>
                                </article>
                            @empty
                                <div class="empty-state"><div class="empty-mark">⌘</div><strong>Your first rule is waiting</strong><p>Build a rule and preview its plan before it touches a file.</p></div>
                            @endforelse

                            <details class="rule-builder">
                                <summary><span>＋</span> Build a new rule</summary>
                                <form method="POST" action="{{ route('rules.store') }}" class="builder-form">
                                    @csrf
                                    <label class="field-label" for="rule_name">RULE NAME</label><input id="rule_name" name="name" type="text" placeholder="e.g. Tidy invoices" maxlength="120" required>
                                    <div class="builder-label-row"><label class="field-label">IF THESE CONDITIONS</label><span><button class="text-button" type="button" data-add-condition>＋ Condition</button><button class="text-button" type="button" data-add-group>＋ Group</button></span></div>
                                    <div class="condition-builder" id="conditions" data-condition-container>
                                        <div class="condition-row" data-condition-node>
                                            <select name="conditions[0][field]" data-condition-field aria-label="Condition field"><option value="name">File name</option><option value="extension">Extension</option><option value="size">File size (bytes)</option><option value="age">Age (days)</option></select>
                                            <select name="conditions[0][operator]" data-condition-operator aria-label="Condition operator"><option value="contains">contains</option><option value="equals">equals</option><option value="starts_with">starts with</option><option value="ends_with">ends with</option><option value="greater_than">is greater than</option><option value="less_than">is less than</option></select>
                                            <input name="conditions[0][value]" data-condition-value placeholder="invoice" required>
                                            <button class="icon-button condition-remove" data-remove-condition type="button" aria-label="Remove condition" disabled>×</button>
                                        </div>
                                    </div>
                                    <template id="condition-row-template"><div class="condition-row" data-condition-node><select data-condition-field aria-label="Condition field"><option value="name">File name</option><option value="extension">Extension</option><option value="size">File size (bytes)</option><option value="age">Age (days)</option></select><select data-condition-operator aria-label="Condition operator"><option value="contains">contains</option><option value="equals">equals</option><option value="starts_with">starts with</option><option value="ends_with">ends with</option><option value="greater_than">is greater than</option><option value="less_than">is less than</option></select><input data-condition-value placeholder="invoice" required><button class="icon-button condition-remove" data-remove-condition type="button" aria-label="Remove condition">×</button></div></template>
                                    <template id="condition-group-template"><div class="condition-group" data-condition-node><input type="hidden" data-group-type value="group"><div class="group-toolbar"><span>Nested group</span><select data-group-operator aria-label="Group operator"><option value="and">AND</option><option value="or">OR</option><option value="not">NOT</option></select><button class="icon-button" data-remove-condition type="button" aria-label="Remove group">×</button></div><div class="condition-children" data-condition-container></div><div class="group-actions"><button class="text-button" data-add-condition type="button">＋ Condition</button><button class="text-button" data-add-group type="button">＋ Nested group</button></div></div></template>
                                    <label class="field-label" for="rule_logic">COMBINE CONDITIONS</label><select id="rule_logic" name="logic"><option value="and">Match all (AND)</option><option value="or">Match any (OR)</option><option value="not">Match none (NOT)</option></select>
                                    <label class="field-label" for="rule_action">THEN DO THIS</label><select id="rule_action" name="action" data-rule-action><option value="rename">Rename</option><option value="move">Move to folder</option><option value="copy">Copy to folder</option><option value="trash">Move to Keeper trash</option><option value="script">Run custom command</option></select>
                                    <label class="field-label" for="rule_target" data-target-label>NEW NAME OR DESTINATION FOLDER</label><input id="rule_target" name="target" type="text" placeholder="invoice-2026.pdf" data-rule-target>
                                    <small class="field-help" data-target-help>For move and copy, use an existing destination folder path. Rename keeps the file in its current folder.</small>
                                    <label class="field-label" for="rule_schedule">RUN WHEN</label><select id="rule_schedule" name="schedule"><option value="">When a settled file arrives</option><option value="hourly">Hourly sweep</option><option value="daily">Daily sweep</option></select>
                                    <div class="script-warning" data-script-warning hidden>Custom commands run locally without an operating-system sandbox, have a 10-second timeout and cannot be undone. Only use commands you trust. The source path is available as <code>KEEPER_FILE</code>.</div>
                                    <button class="button button-dark builder-submit" type="submit">Save rule <span>→</span></button>
                                </form>
                            </details>
                        </section>
                    </div>

                    <aside class="side-column">
                        <section class="preview-card" id="preview">
                            <div class="preview-top"><span class="eyebrow">03 / SAFE PREVIEW</span><span class="preview-spark">✳</span></div>
                            <h2>Nothing moves<br>without your <em>okay.</em></h2>
                            <p>Preview shows the matching rule and destination for every settled file. Your folder stays untouched until you confirm.</p>
                            @if (!empty($preview['plans']))
                                <div class="preview-summary">{{ collect($preview['plans'])->whereNotNull('rule_id')->count() }} matching · {{ count($preview['plans']) }} settled</div>
                                <a class="button button-light button-full" href="#preview-plan">Review this plan <span>↓</span></a>
                            @else
                                <div class="preview-empty">Choose <strong>Preview scan</strong> beside a watched folder to create a plan.</div>
                            @endif
                            <div class="preview-foot"><span>✓</span> Dry-run uses the same rule engine</div>
                        </section>

                        <section class="mini-panel">
                            <div class="mini-heading"><div><div class="eyebrow">04 / RECENT ACTIVITY</div><h3>Latest actions</h3></div><a href="{{ route('home', ['tab' => 'activity']) }}">View all →</a></div>
                            @forelse ($entries->take(5) as $entry)
                                <div class="activity-line"><span class="activity-mark {{ $entry->status === 'completed' ? 'mark-done' : 'mark-error' }}">{{ $entry->status === 'completed' ? '✓' : '!' }}</span><div class="activity-copy"><strong>{{ ucfirst($entry->action) }} · {{ basename($entry->source_path) }}</strong><small>{{ $entry->created_at->diffForHumans() }}</small></div></div>
                            @empty
                                <div class="mini-empty">Your first file action will show up here.</div>
                            @endforelse
                        </section>

                        <section class="safety-card"><span class="safety-icon">◌</span><div><strong>Undo is built in</strong><p>Every file action is recorded with a checksum. Restore one action or an entire sweep.</p></div></section>
                    </aside>
                </section>

                <section class="panel preview-plan-panel" id="preview-plan">
                    <div class="panel-heading"><div><div class="eyebrow">05 / THE PLAN</div><h2>Dry-run preview</h2><p>Review each proposed action. This plan does not change files.</p></div>@if (!empty($preview['plans']))<span class="count-pill">{{ count($preview['plans']) }} settled</span>@endif</div>
                    @if (!empty($preview['plans']))
                        <div class="table-scroll"><table><thead><tr><th>FILE</th><th>FIRST MATCH</th><th>ACTION</th><th>DESTINATION</th></tr></thead><tbody>
                            @foreach ($preview['plans'] as $plan)
                                <tr><td><strong>{{ basename($plan['path']) }}</strong><small class="table-sub mono">{{ $plan['path'] }}</small></td><td>{{ $plan['rule_name'] ?? 'No matching rule' }}</td><td><span class="action-chip">{{ $plan['action'] ? ucfirst($plan['action']) : 'Leave as is' }}</span></td><td class="mono table-destination">{{ $plan['destination'] ?? '—' }}</td></tr>
                            @endforeach
                        </tbody></table></div>
                        @if (collect($preview['plans'])->contains(fn ($plan) => $plan['rule_id'] !== null))
                            <form method="POST" action="{{ route('preview.run') }}" class="confirm-row" onsubmit="return confirm('Apply these file actions? Keeper will recheck the files before making any change.')">@csrf<input type="hidden" name="confirmation" value="1"><span>Only matching files are queued. Files changed since preview are skipped.</span><button class="button button-dark" type="submit">Confirm & queue actions <span>→</span></button></form>
                        @else
                            <div class="limit-note">No files matched an enabled rule. Add or enable a rule to create a useful plan.</div>
                        @endif
                    @else
                        <div class="empty-state"><div class="empty-mark">◷</div><strong>No preview plan yet</strong><p>Scan a watched folder twice so Keeper can confirm its files have settled.</p></div>
                    @endif
                </section>

                <section class="panel activity-panel" id="activity">
                    <div class="panel-heading"><div><div class="eyebrow">06 / CHECKSUM-VERIFIED HISTORY</div><h2>Action ledger</h2><p>Append-only file history with safe, reverse-order undo.</p></div><div class="panel-heading-actions"><span class="count-pill">{{ $entries->count() }} recent entries</span><form method="POST" action="{{ route('trash.empty') }}" onsubmit="return confirm('Permanently delete every file in Keeper trash? These files cannot be restored.')">@csrf<button class="text-button text-danger" type="submit">Empty Keeper trash</button></form></div></div>
                    <div class="table-scroll"><table><thead><tr><th>FILE</th><th>ACTION</th><th>STATUS</th><th>WHEN</th><th></th></tr></thead><tbody>
                        @forelse ($entries as $entry)
                            <tr><td><strong>{{ basename($entry->source_path) }}</strong><small class="table-sub mono">{{ $entry->source_path }}</small><small class="table-sub mono">SHA-256 {{ substr($entry->checksum, 0, 16) }}…</small></td><td>{{ ucfirst($entry->action) }} @if($entry->rule) <small class="table-sub">by {{ $entry->rule->name }}</small> @endif</td><td><span class="status-chip status-{{ $entry->status }}">{{ ucfirst($entry->status) }}</span>@if($entry->error_message)<small class="table-sub status-error-text">{{ $entry->error_message }}</small>@endif</td><td>{{ $entry->created_at->format('M j, H:i') }}</td><td>@if ($entry->status === 'completed' && !$entry->undone_at && $entry->action !== 'script')<form method="POST" action="{{ route('activity.undo', $entry) }}">@csrf<button class="text-button" type="submit">Undo</button></form>@elseif($entry->undone_at)<span class="undone-label">Undone</span>@endif</td></tr>
                        @empty
                            <tr><td colspan="5"><div class="mini-empty">No file actions recorded yet. Your action history will live here.</div></td></tr>
                        @endforelse
                    </tbody></table></div>
                    @foreach ($entries->whereNotNull('sweep_id')->groupBy('sweep_id')->take(3) as $sweep => $sweepEntries)
                        @if($sweepEntries->where('status', 'completed')->whereNull('undone_at')->isNotEmpty())
                            <form method="POST" action="{{ route('activity.sweep.undo', $sweep) }}" class="sweep-undo" onsubmit="return confirm('Undo the completed actions in this sweep, newest first?')">@csrf<button class="text-button" type="submit">Undo sweep · {{ $sweepEntries->where('status', 'completed')->whereNull('undone_at')->count() }} action(s)</button></form>
                        @endif
                    @endforeach
                </section>

                <section class="panel queue-panel">
                    <div class="panel-heading"><div><div class="eyebrow">07 / QUEUE DIAGNOSTICS</div><h2>Processing queue</h2><p>Duplicate scans are deduplicated by file path and modification time.</p></div><span class="count-pill">{{ $executions->count() }} recent jobs</span></div>
                    <div class="table-scroll"><table><thead><tr><th>FILE</th><th>STATUS</th><th>ATTEMPTS</th><th>DETAILS</th><th></th></tr></thead><tbody>
                        @forelse ($executions as $execution)
                            <tr><td><strong>{{ basename($execution->file_path) }}</strong><small class="table-sub mono">{{ $execution->file_path }}</small></td><td><span class="status-chip status-{{ $execution->status }}">{{ ucfirst($execution->status) }}</span></td><td>{{ $execution->attempts }}</td><td class="queue-error">{{ $execution->last_error ?? '—' }}</td><td>@if($execution->status === 'failed')<form method="POST" action="{{ route('activity.retry', $execution) }}">@csrf<button class="text-button" type="submit">Retry</button></form>@endif</td></tr>
                        @empty
                            <tr><td colspan="5"><div class="mini-empty">No queued actions. Confirm a preview to start.</div></td></tr>
                        @endforelse
                    </tbody></table></div>
                    <div class="worker-note"><span class="status-dot"></span><div><strong>Local worker required</strong><p>Run <code>php artisan queue:work --queue=organizer,default</code> to process confirmed actions. For continuous folder scans, run <code>php artisan keeper:watch</code>.</p></div></div>
                </section>

                <footer class="page-footer"><span>✳</span> Made to keep things in their place. <span class="footer-right">LOCAL FILES · MYSQL · CHECKSUM VERIFIED</span></footer>
            </div>
        </main>
    </div>
</body>
</html>
