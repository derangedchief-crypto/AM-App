<?php
// Included only by the authenticated management page.
if (!isset($resellerId,$config,$catalogue)) { http_response_code(404); exit; }
$promoError=null; $promoRows=[]; $editPromo=null;
try {
    $promoStore=new \AmAccount\Promotions($config['database']);
    $promoRows=$promoStore->rows($resellerId);
    if (is_string($_GET['promo_edit'] ?? null)) $editPromo=$promoStore->get($resellerId,$_GET['promo_edit']);
} catch (Throwable $e) { $promoError='Promotions are unavailable. Install the website update.'; }
$promoPackage=$catalogue[(int)($editPromo['package_id'] ?? (is_string($_GET['promo_package'] ?? null) ? $_GET['promo_package'] : 0))] ?? null;
$promoPage=max(1,min(max(1,(int)ceil(count($promoRows)/10)),(int)(is_string($_GET['promo_page'] ?? null)?$_GET['promo_page']:1)));
?>
<section id="promotions" data-am-section class="card am-square">
<h2>Promotions</h2>
<p class="am-muted">Limited-time renewal deals for a customer's current package. The banner appears in the app's Deals screen between the start and end dates. Future renewals return to normal pricing.</p>
<p><a class="btn" href="#pricing">Choose a package to promote</a></p>
<p class="am-muted">In Packages &amp; prices, expand a package and choose Create deal. Save an enabled normal price first. Promotion amounts must be lower than that price. Your wholesale credit cost stays the same.</p>
<?php if ($promoError): ?><p role="alert"><?= e($promoError) ?></p><?php endif; ?>
<?php if ($promoPackage): ?>
<form method="post" enctype="multipart/form-data" action="<?= e(url('am-app/?'.http_build_query($editPromo ? ['promo_edit'=>$editPromo['id']] : ['promo_package'=>$promoPackage['id'],'prices_currency'=>$priceCurrency]).'#promotions')) ?>">
<?= csrf_field() ?><input type="hidden" name="action" value="promotion_save"><input type="hidden" name="promo_id" value="<?= e($editPromo['id'] ?? '') ?>"><input type="hidden" name="revision" value="<?= (int)($editPromo['revision'] ?? 0) ?>"><input type="hidden" name="package_id" value="<?= $promoPackage['id'] ?>"><input type="hidden" name="fingerprint" value="<?= e($promoPackage['fingerprint']) ?>">
<h3><?= $editPromo ? 'Edit promotion' : 'New promotion' ?> · <?= e($promoPackage['name']) ?></h3>
<p class="am-muted">Package #<?= $promoPackage['id'] ?> · <?= $promoPackage['max_connections'] ?> connections · <?= e((string)$promoPackage['duration'].' '.(string)$promoPackage['duration_period']) ?></p>
<div class="am-grid">
<label class="am-field"><span>Deal title</span><input type="text" name="title" maxlength="120" required value="<?= e($editPromo['title'] ?? '') ?>" placeholder="Weekend renewal deal"></label>
<label class="am-field"><span>Currency</span><select name="currency"><?php foreach (['CAD','USD'] as $currency): ?><option<?= am_selected($editPromo['currency'] ?? $priceCurrency,$currency) ?>><?= $currency ?></option><?php endforeach; ?></select></label>
<label class="am-field"><span>Promotional renewal price</span><input type="number" name="amount" min="0" max="999999.99" step="0.01" required value="<?= $editPromo ? e(number_format((int)$editPromo['amount_minor']/100,2,'.','')) : '' ?>"></label>
<label class="am-field"><span>Banner image · PNG, JPEG or WebP · max 1 MB</span><input type="file" name="banner" accept="image/png,image/jpeg,image/webp"<?= $editPromo ? '' : ' required' ?>><small class="am-muted">Recommended: a wide 1200 × 600 banner. On edit, leave empty to keep the current image.</small></label>
<label class="am-field am-wide"><span>Description</span><input type="text" name="description" maxlength="1000" value="<?= e($editPromo['description'] ?? '') ?>"></label>
<label class="am-field"><span>Starts · UTC</span><input type="datetime-local" name="starts" required value="<?= e(gmdate('Y-m-d\TH:i',(int)($editPromo['starts'] ?? time()))) ?>"></label>
<label class="am-field"><span>Ends · UTC (deal disappears automatically)</span><input type="datetime-local" name="ends" required value="<?= e(gmdate('Y-m-d\TH:i',(int)($editPromo['ends'] ?? time()+7*86400))) ?>"></label>
</div>
<label class="am-switch"><input type="checkbox" name="enabled" value="1"<?= am_checked(!$editPromo || (bool)$editPromo['enabled']) ?>><span>Publish during these dates</span></label>
<p class="am-muted">Dates use UTC. Customers see the deadline in their device's timezone. New checkout must begin before the deal ends; existing orders keep their agreed price. No ongoing discounted rate is promised.</p>
<button class="btn btn-primary" type="submit">Save promotion</button> <a class="btn" href="<?= e(url('am-app/#promotions')) ?>">Close editor</a>
</form>
<?php endif; ?>
<?php if (!$promoRows && !$promoError): ?><p class="am-empty">No promotions yet. Choose a package to create your first deal.</p><?php endif; ?>
<?php foreach (array_slice($promoRows,($promoPage-1)*10,10) as $promo):
    $promoStatus=!(int)$promo['enabled']?'Disabled':((int)$promo['ends']<=time()?'Ended':((int)$promo['starts']>time()?'Scheduled':'Active window'));
?>
<details class="am-package"><summary><span><strong><?= e($promo['title']) ?></strong><small>Package #<?= $promo['package_id'] ?> · <?= e($promoStatus) ?> · ends <?= e(gmdate('Y-m-d H:i',(int)$promo['ends'])) ?> UTC</small></span><span><?= e(number_format((int)$promo['amount_minor']/100,2,'.','').' '.$promo['currency']) ?></span></summary>
<div class="am-price"><img loading="lazy" src="<?= e(url('am-app/promo-banner.php?id='.$promo['id'])) ?>" alt="<?= e($promo['title']) ?>" style="display:block;width:100%;max-width:600px;max-height:300px;object-fit:contain;border-radius:12px">
<p><?= e($promo['description']) ?></p><p class="am-muted">Visibility also requires the linked package's normal price, renewal permissions and matching Square setup. Only customers on this exact package can redeem the deal.</p>
<a class="btn" href="<?= e(url('am-app/?promo_edit='.$promo['id'].'#promotions')) ?>">Edit promotion</a>
<?php if ((int)$promo['enabled']): ?><form method="post" action="<?= e(url('am-app/#promotions')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="promotion_disable"><input type="hidden" name="promo_id" value="<?= e($promo['id']) ?>"><input type="hidden" name="revision" value="<?= $promo['revision'] ?>"><button class="btn" type="submit">Disable promotion</button></form><?php endif; ?>
</div></details>
<?php endforeach; ?>
<?php if (count($promoRows)>10): ?><nav class="am-pager" aria-label="Promotion pages"><?php if ($promoPage>1): ?><a href="<?= e(url('am-app/?promo_page='.($promoPage-1).'#promotions')) ?>">Previous</a><?php endif; ?><span>Page <?= $promoPage ?> of <?= (int)ceil(count($promoRows)/10) ?></span><?php if ($promoPage*10<count($promoRows)): ?><a href="<?= e(url('am-app/?promo_page='.($promoPage+1).'#promotions')) ?>">Next</a><?php endif; ?></nav><?php endif; ?>
</section>
