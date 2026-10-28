<section class="preview-card" id="preview">
    <div class="preview-top"><span class="eyebrow">03 / SAFE PREVIEW</span><span class="preview-spark">✳</span></div>
    <h2>Nothing moves<br>without your <em>okay.</em></h2>
    <p>Preview shows the matching rule and destination for every settled file. Your folder stays untouched until you confirm.</p>
    @if (!empty($preview['plans']))
        <div class="preview-summary">{{ collect($preview['plans'])->whereNotNull('rule_id')->count() }} matching · {{ count($preview['plans']) }} settled</div>
        <a class="button button-light button-full" href="#preview-plan">Review this plan <span>↓</span></a>
    @elseif (!empty($preview['automatic']))
        <div class="preview-summary">0 settled files in the latest scan</div>
    @else
        <div class="preview-empty">Choose <strong>Preview scan</strong> beside a watched folder to create a plan.</div>
    @endif
    <div class="preview-foot"><span>✓</span> Dry-run uses the same rule engine</div>
</section>
