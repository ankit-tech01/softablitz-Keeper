<section class="panel" id="rules">
    <div class="panel-heading">
        <div>
            <div class="eyebrow">02 / RULE STUDIO</div>
            <h2>Your rules, in order</h2>
            <p>Rules run from top to bottom. The first match gets one action.</p>
        </div>
        <span class="count-pill">{{ $rules->count() }} total</span>
    </div>

    @if ($message)
        <div class="inventory-status" role="status">{{ $message }}</div>
    @endif

    @forelse ($rules as $index => $rule)
        <article class="rule-row {{ $rule->is_enabled ? '' : 'rule-disabled' }}" wire:key="automation-rule-{{ $rule->id }}">
            <span class="rule-rank">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
            <div class="rule-main">
                <div class="rule-title">{{ $rule->name }} <span class="rule-badge">{{ $rule->logic === 'not' ? 'NOT' : strtoupper($rule->logic) }}</span></div>
                <div class="rule-summary">
                    @foreach ($rule->conditions as $condition)
                        @if (($condition['type'] ?? null) === 'group')
                            <span>{{ strtoupper($condition['operator']) }} group ({{ count($condition['conditions']) }})</span>
                        @else
                            <span>{{ ucfirst($condition['field'] ?? 'file') }} {{ str_replace('_', ' ', $condition['operator'] ?? '') }} {{ $condition['value'] ?? '' }}{{ ($condition['field'] ?? '') === 'size' ? ' bytes' : '' }}</span>
                        @endif
                        @if (!$loop->last)<b>{{ strtoupper($rule->logic) }}</b>@endif
                    @endforeach
                </div>
                <div class="rule-action"><span>THEN</span>
                    {{ ucfirst($rule->action) }}{{ $rule->action === 'rename' ? ' to '.$rule->target : (in_array($rule->action, ['move', 'copy'], true) ? ' → '.$rule->target : '') }}{{ $rule->schedule ? ' · '.$rule->schedule : '' }}
                    <span class="approval-badge {{ $rule->is_approved ? 'approved' : 'needs-approval' }}">{{ $rule->is_approved ? 'AUTO-RUN READY' : 'PREVIEW REQUIRED' }}</span>
                </div>
            </div>
            <div class="rule-controls">
                <button class="icon-button" type="button" wire:click="moveRule({{ $rule->id }}, 'up')" wire:loading.attr="disabled" wire:target="moveRule({{ $rule->id }}, 'up')" aria-label="Move rule up" @disabled($loop->first)>↑</button>
                <button class="icon-button" type="button" wire:click="moveRule({{ $rule->id }}, 'down')" wire:loading.attr="disabled" wire:target="moveRule({{ $rule->id }}, 'down')" aria-label="Move rule down" @disabled($loop->last)>↓</button>
                <button class="toggle {{ $rule->is_enabled ? 'toggle-on' : '' }}" type="button" wire:click="toggleRule({{ $rule->id }})" wire:loading.attr="disabled" wire:target="toggleRule({{ $rule->id }})" aria-label="{{ $rule->is_enabled ? 'Disable' : 'Enable' }} rule"><i></i></button>
                <button class="icon-button rule-delete" type="button" wire:click="deleteRule({{ $rule->id }})" wire:confirm="Delete this rule? Existing history will remain, and queued actions for this rule will be cancelled." wire:loading.attr="disabled" wire:target="deleteRule({{ $rule->id }})" aria-label="Delete rule: {{ $rule->name }}" title="Delete rule">×</button>
            </div>
        </article>
    @empty
        <div class="empty-state"><div class="empty-mark">⌘</div><strong>Your first rule is waiting</strong><p>Build a rule and preview its plan before it touches a file.</p></div>
    @endforelse

    <details class="rule-builder">
        <summary><span>＋</span> Build a new rule</summary>
        <form wire:submit="saveRule" class="builder-form" id="rule-builder-form">
            <label class="field-label" for="rule_name">RULE NAME</label>
            <input id="rule_name" wire:model.live.debounce.500ms="name" type="text" placeholder="e.g. Tidy invoices" maxlength="120" required @error('name') aria-invalid="true" aria-describedby="rule-name-error" @enderror>
            @error('name')<small id="rule-name-error" class="inventory-form-error" role="alert">{{ $message }}</small>@enderror
            <div class="builder-label-row"><label class="field-label">IF THESE CONDITIONS</label><span><button class="text-button" type="button" data-add-condition>＋ Condition</button><button class="text-button" type="button" data-add-group>＋ Group</button></span></div>
            <div class="condition-builder" id="conditions" data-condition-container>
                <div class="condition-row" data-condition-node>
                    <select name="conditions[0][field]" wire:model.live="conditions.0.field" data-condition-field aria-label="Condition field">
                        <option value="name">File name</option><option value="extension">Extension</option><option value="size">File size (bytes)</option><option value="age">Age (days)</option>
                    </select>
                    <select name="conditions[0][operator]" wire:model.live="conditions.0.operator" data-condition-operator aria-label="Condition operator">
                        <option value="contains">contains</option><option value="equals">equals</option><option value="starts_with">starts with</option><option value="ends_with">ends with</option><option value="greater_than">is greater than</option><option value="less_than">is less than</option>
                    </select>
                    <input name="conditions[0][value]" wire:model.live.debounce.500ms="conditions.0.value" data-condition-value placeholder="invoice" required>
                    <button class="icon-button condition-remove" data-remove-condition type="button" aria-label="Remove condition" disabled>×</button>
                </div>
            </div>
            @error('conditions')<small class="inventory-form-error">{{ $message }}</small>@enderror
            <template id="condition-row-template"><div class="condition-row" data-condition-node><select data-condition-field aria-label="Condition field"><option value="name">File name</option><option value="extension">Extension</option><option value="size">File size (bytes)</option><option value="age">Age (days)</option></select><select data-condition-operator aria-label="Condition operator"><option value="contains">contains</option><option value="equals">equals</option><option value="starts_with">starts with</option><option value="ends_with">ends with</option><option value="greater_than">is greater than</option><option value="less_than">is less than</option></select><input data-condition-value placeholder="invoice" required><button class="icon-button condition-remove" data-remove-condition type="button" aria-label="Remove condition">×</button></div></template>
            <template id="condition-group-template"><div class="condition-group" data-condition-node><input type="hidden" data-group-type value="group"><div class="group-toolbar"><span>Nested group</span><select data-group-operator aria-label="Group operator"><option value="and">AND</option><option value="or">OR</option><option value="not">NOT</option></select><button class="icon-button" data-remove-condition type="button" aria-label="Remove group">×</button></div><div class="condition-children" data-condition-container></div><div class="group-actions"><button class="text-button" data-add-condition type="button">＋ Condition</button><button class="text-button" data-add-group type="button">＋ Nested group</button></div></div></template>
            <label class="field-label" for="rule_logic">COMBINE CONDITIONS</label>
            <select id="rule_logic" wire:model.live="logic" @error('logic') aria-invalid="true" @enderror><option value="and">Match all (AND)</option><option value="or">Match any (OR)</option><option value="not">Match none (NOT)</option></select>
            @error('logic')<small class="inventory-form-error" role="alert">{{ $message }}</small>@enderror
            <label class="field-label" for="rule_action">THEN DO THIS</label>
            <select id="rule_action" wire:model.live="action" data-rule-action @error('action') aria-invalid="true" @enderror><option value="rename">Rename</option><option value="move">Move to folder</option><option value="copy">Copy to folder</option><option value="trash">Move to Keeper trash</option><option value="script">Run custom command</option></select>
            @error('action')<small class="inventory-form-error" role="alert">{{ $message }}</small>@enderror
            <label class="field-label" for="rule_target" data-target-label>NEW NAME OR DESTINATION FOLDER</label>
            <input id="rule_target" wire:model.live.debounce.500ms="target" type="text" placeholder="invoice-2026.pdf" data-rule-target @error('target') aria-invalid="true" aria-describedby="rule-target-error" @enderror>
            @error('target')<small id="rule-target-error" class="inventory-form-error" role="alert">{{ $message }}</small>@enderror
            <small class="field-help" data-target-help>For move and copy, use an existing destination folder path. Rename keeps the file in its current folder.</small>
            <label class="field-label" for="rule_schedule">RUN WHEN</label>
            <select id="rule_schedule" wire:model.live="schedule" @error('schedule') aria-invalid="true" @enderror><option value="">When a settled file arrives</option><option value="hourly">Hourly sweep</option><option value="daily">Daily sweep</option></select>
            @error('schedule')<small class="inventory-form-error" role="alert">{{ $message }}</small>@enderror
            <div class="script-warning" data-script-warning hidden>Custom commands run locally without an operating-system sandbox, have a 10-second timeout and cannot be undone. Only use commands you trust. The source path is available as <code>KEEPER_FILE</code>.</div>
            <button class="button button-dark builder-submit" type="submit" wire:loading.attr="disabled" wire:target="saveRule">
                <span wire:loading.remove wire:target="saveRule">Save rule</span><span wire:loading wire:target="saveRule">Saving…</span><span>→</span>
            </button>
        </form>
    </details>
</section>
