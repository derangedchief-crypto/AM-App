<?php
// Read-only views of this authenticated reseller's existing settings and license.
if (!isset($resellerId,$licenseRow,$settings)) { http_response_code(404); exit; }
$accountName=(string)($reseller['display_name'] ?? $reseller['username'] ?? 'Your account');
$licenseDate=static fn($value): string => $value===null ? 'Not activated' : gmdate('d M Y · H:i',(int)$value).' UTC';
$currentConnections=array_filter($squareConnections,static fn($connection)=>$connection['mode']===$settings['square_mode']);
$setupItems=[
    ['Your customer app',$ready && $settings['app_enabled'],'Choose your brand and enable customer access.','settings'],
    ['Renewal pricing',!$pricingError && $stateCounts['enabled']>0,'Set the retail prices your customers will pay.','pricing'],
    ['Square connection',$square && count($currentConnections)>0,'Connect Square, or configure PayPal, Stripe or Rampex under Payment gateways.','square'],
];
?>
<section id="overview" data-am-section>
<div class="am-section-title"><span class="am-eyebrow">Your workspace</span><h1>Welcome to My TV</h1><p class="am-muted">Manage your customer experience, from first sign-in to the next renewal.</p></div>
<div class="am-metrics">
<a class="am-metric" href="#settings"><span>Customer app</span><strong><?= !$ready?'Unavailable':($settings['app_enabled']?'Enabled':'Off') ?></strong><small>Access preferences →</small></a>
<a class="am-metric" href="#pricing"><span>Prices enabled</span><strong><?= $pricingError?'—':$stateCounts['enabled'] ?></strong><small><?= e($priceCurrency) ?> · package renewal prices →</small></a>
<a class="am-metric" href="#square"><span>Square checkout mode</span><strong><?= $settings['square_mode']==='live'?'Production':'Sandbox' ?></strong><small>Other gateways use their connection's environment →</small></a>
</div>
<div class="am-grid am-overview-grid">
<section class="card"><div class="am-card-heading"><div><span class="am-eyebrow">Get ready</span><h2>Your app setup</h2></div><span class="am-pill"><?= count(array_filter($setupItems,static fn($item)=>$item[1])) ?>/3</span></div>
<div class="am-setup-list"><?php foreach ($setupItems as [$label,$complete,$description,$target]): ?><a class="am-setup-item" href="#<?= e($target) ?>"><span class="am-step <?= $complete?'is-done':'' ?>" aria-label="<?= $complete?'Configured':'Needs setup' ?>"><?= $complete?'✓':'→' ?></span><span><strong><?= e($label) ?></strong><small><?= e($description) ?></small></span><span aria-hidden="true">↗</span></a><?php endforeach; ?></div>
<p class="am-muted am-footnote">Check individual renewal availability in <a href="#renewal-check">Renewal check</a>.</p></section>
<section class="card am-license-teaser"><span class="am-eyebrow">Your account</span><span class="am-pill am-pill-success"><span class="am-dot"></span> License active</span><h2><?= $licenseRow['expires']===null?'No expiry date':'Time-limited access' ?></h2><p class="am-muted"><?= $licenseRow['expires']===null?'Your license has no scheduled expiry.':'Access until '.e($licenseDate($licenseRow['expires'])).'.' ?></p><a class="btn" href="#license">View license details →</a></section>
</div>
<section class="card am-promo-callout"><div><span class="am-eyebrow">Keep customers coming back</span><h2>A great offer starts here.</h2><p class="am-muted">Create a limited-time renewal deal for your customers.</p></div><a class="btn btn-primary" href="#promotions">Manage promotions →</a></section>
</section>
<section id="license" data-am-section>
<div class="am-section-title"><span class="am-eyebrow">Account</span><h1>Your license</h1><p class="am-muted">Access details for <?= e($accountName) ?>.</p></div>
<section class="card am-license-detail"><div class="am-card-heading"><div><span class="am-eyebrow">My TV</span><h2>Reseller license</h2></div><span class="am-pill am-pill-success"><span class="am-dot"></span> Active</span></div>
<dl class="am-details">
<div><dt>License term</dt><dd><?= $licenseRow['expires']===null?'No expiry':'Time-limited' ?></dd></div>
<div><dt>Expires</dt><dd><?= $licenseRow['expires']===null?'No scheduled expiry':e($licenseDate($licenseRow['expires'])) ?></dd></div>
<div><dt>Activated</dt><dd><?= e($licenseDate($licenseRow['activated'])) ?></dd></div>
<div><dt>Issued</dt><dd><?= e($licenseDate($licenseRow['created'])) ?></dd></div>
<div><dt>Reseller account</dt><dd><?= e($accountName) ?> <span class="am-muted">#<?= e($resellerId) ?></span></dd></div>
<div><dt>License key</dt><dd><code>•••• •••• <?= e($licenseRow['key_hint']) ?></code></dd></div>
<div><dt>License reference</dt><dd><code><?= e($licenseRow['id']) ?></code></dd></div>
</dl><p class="am-muted am-footnote">Need an extension or a replacement? Contact your service administrator and quote your license reference.</p>
<?php if (function_exists('is_local_admin') && is_local_admin()): ?><a class="btn" href="<?= e(url('am-app/licenses-admin.php')) ?>">Manage licenses ↗</a><?php endif; ?>
</section>
</section>
