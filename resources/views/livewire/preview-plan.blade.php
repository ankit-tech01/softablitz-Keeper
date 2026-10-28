<section class="panel preview-plan-panel" id="preview-plan">
    <div class="panel-heading">
        <div><div class="eyebrow">05 / THE PLAN</div><h2>Dry-run preview</h2><p>Review each proposed action. This plan does not change files.</p></div>
        @if (!empty($preview['plans']))<span class="count-pill">{{ count($preview['plans']) }} settled</span>@endif
    </div>
    @if ($message)<div class="inventory-status" role="status">{{ $message }}</div>@endif
    @if (!empty($preview['automatic']))
        <div class="inventory-status" role="status">
            Automatic preview found {{ count($preview['plans'] ?? []) }} settled file(s) across {{ $preview['folder_count'] ?? 0 }} watched folder(s). Matching files and proposed actions are listed below; unmatched file names remain hidden. No files change until you confirm.
        </div>
    @endif
    @if (!empty($preview['plans']))
        @if ($displayPlans->isNotEmpty())
        <div class="table-scroll">
            <table><thead><tr><th>FILE</th><th>FIRST MATCH</th><th>ACTION</th><th>DESTINATION</th></tr></thead><tbody>
                @foreach ($displayPlans as $plan)
                    <tr wire:key="preview-plan-{{ sha1($plan['path']) }}">
                        <td><strong>{{ basename($plan['path']) }}</strong><small class="table-sub mono">{{ $plan['path'] }}</small></td>
                        <td>{{ $plan['rule_name'] ?? 'No matching rule' }}</td>
                        <td><span class="action-chip">{{ $plan['action'] ? ucfirst($plan['action']) : 'Leave as is' }}</span></td>
                        <td class="mono table-destination">{{ $plan['destination'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody></table>
        </div>
        @endif
        @if (collect($preview['plans'])->contains(fn ($plan) => $plan['rule_id'] !== null))
            <form wire:submit="confirmPlan" class="confirm-row">
                <input type="hidden" wire:model="confirmation" value="1">
                <span>Only matching files are queued. Files changed since preview are skipped.</span>
                <button class="button button-dark" type="submit" wire:confirm="Apply these file actions? Keeper will recheck the files before making any change." wire:loading.attr="disabled" wire:target="confirmPlan">
                    <span wire:loading.remove wire:target="confirmPlan">Confirm & queue actions</span>
                    <span wire:loading wire:target="confirmPlan">Queueing…</span><span>→</span>
                </button>
                @error('confirmation')<small class="inventory-form-error">{{ $message }}</small>@enderror
            </form>
        @else
            <div class="limit-note">No files matched an enabled rule. Add or enable a rule to create a useful plan.</div>
        @endif
    @elseif (!empty($preview['automatic']))
        <div class="empty-state"><div class="empty-mark">◷</div><strong>No settled files found</strong><p>The automatic preview will check the watched folders again in 30 seconds.</p></div>
    @else
        <div class="empty-state"><div class="empty-mark">◷</div><strong>No preview plan yet</strong><p>Scan a watched folder twice so Keeper can confirm its files have settled.</p></div>
    @endif
</section>
