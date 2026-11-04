<?php
declare(strict_types=1);

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');
$portalRoot = dirname(__DIR__);
require_once $portalRoot . '/includes/bootstrap.php';
$reseller = require_login();
if (!empty($_SESSION['portal_staff']) || !empty($_SESSION['impersonation'])) {
    http_response_code(403);
    exit('Sign in as the reseller to manage AM billing and customer access.');
}
$resellerId = (string)($reseller['id'] ?? '');
if (!preg_match('/^[1-9][0-9]{0,17}$/D', $resellerId)) { http_response_code(403); exit('Reseller login required.'); }
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'POST', 'HEAD'], true)) {
    header('Allow: GET, HEAD, POST'); http_response_code(405); exit;
}
// Every visit/save rechecks the immutable authenticated ID, never a submitted tenant.
try {
    $identity = call_auth_worker('/panel-user-balance', ['panel_user_id' => $resellerId]);
    if (($identity['ok'] ?? false) !== true || ($identity['is_enabled'] ?? false) !== true) {
        http_response_code(403); exit('Your reseller account could not be confirmed as active. Please sign in again or contact support.');
    }
} catch (Throwable $error) {
    http_response_code(503); exit('AM settings could not verify your reseller account. Please retry shortly.');
}

require_once __DIR__ . '/Settings.php';
$errorMessage = null;
try {
    $config = require __DIR__ . '/settings-config.php';
    $store = new \AmAccount\SettingsStore($config['database']);
    $snapshot = $store->read($resellerId);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        csrf_check();
        if (!is_string($_POST['revision'] ?? null) || !preg_match('/^[0-9]{1,9}$/D', $_POST['revision'])) {
            throw new InvalidArgumentException('Invalid settings revision. Reload and try again.');
        }
        $settings = \AmAccount\Settings::fromPost($_POST);
        $store->save($resellerId, (int)$_POST['revision'], $settings);
        $_SESSION['am_settings_saved'] = $resellerId;
        header('Location: ' . url('am-app/'), true, 303); exit;
    }
} catch (\AmAccount\Conflict $error) {
    http_response_code(409); $errorMessage = $error->getMessage();
} catch (InvalidArgumentException $error) {
    http_response_code(422); $errorMessage = $error->getMessage();
} catch (Throwable $error) {
    http_response_code(503); $errorMessage = 'AM settings storage is unavailable. No reseller-panel settings were changed.';
}
$ready = isset($snapshot);
$settings = $snapshot['settings'] ?? \AmAccount\Settings::defaults();
$saved = ($_SESSION['am_settings_saved'] ?? null) === $resellerId;
unset($_SESSION['am_settings_saved']);
function am_checked(bool $value): string { return $value ? ' checked' : ''; }
function am_selected(string $value, string $expected): string { return $value === $expected ? ' selected' : ''; }
?>
<!doctype html>
<html lang="en"<?= theme_html_attrs() ?>>
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>AM App management</title>
<link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
<?= theme_head() ?>
<style>
.am-page{max-width:960px;margin:0 auto;padding:32px 20px 64px;color:var(--text)}
.am-top{display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:26px}
.am-page h1{font-size:28px;margin:10px 0}.am-page h2{font-size:18px;margin:0 0 16px}
.am-page .am-muted{color:var(--text-muted,var(--muted));font-size:13px;line-height:1.6}
.am-page .am-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.am-page .am-wide{grid-column:1/-1}
.am-page .card{padding:24px}.am-page label.am-field{display:block;margin:16px 0}
.am-page label.am-field>span{display:block;margin-bottom:7px;font-weight:600;font-size:13px}
.am-page input[type=text],.am-page input[type=email],.am-page input[type=url],.am-page select{width:100%;min-height:44px}
.am-page input[type=url]{padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);font:inherit}
.am-page label.am-switch{text-transform:none;letter-spacing:normal;color:var(--text)}
.am-page .am-switch small{font-weight:400}
.am-page .am-switch{display:flex;align-items:flex-start;gap:12px;margin:16px 0;font-size:14px}
.am-page .am-switch input{width:19px;height:19px;flex:none;margin-top:2px;accent-color:var(--accent,#8b9fff)}
.am-page .am-switch small{display:block;margin-top:5px}.am-notice{padding:16px;border:1px solid var(--border);border-radius:12px;margin-bottom:20px;line-height:1.6;font-size:14px}
.am-status{font-size:11px;letter-spacing:1px;text-transform:uppercase;color:var(--text-muted,var(--muted))}
.am-actions{display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap;margin-top:24px}
.am-page fieldset{border:0;padding:0;margin:0;min-width:0}.am-page a{overflow-wrap:anywhere}
@media(max-width:640px){.am-page .am-grid{grid-template-columns:1fr}.am-page{padding:20px 14px}.am-page .card{padding:18px}}
</style>
</head><body>
<main class="am-page">
<div class="am-top"><div><div class="am-status">Private preview · hidden from navigation</div><h1>AM App management</h1>
<p class="am-muted">Settings for <?= e((string)($reseller['display_name'] ?? $reseller['username'] ?? 'your reseller account')) ?>.</p></div>
<a class="btn" href="<?= e(url('dashboard.php')) ?>">Back to reseller panel</a></div>
<div class="am-notice"><strong>Configuration preview.</strong> You can save your AM preferences here. The customer API and payment integrations are not connected yet, so these settings do not currently change customer access, services, or payments.</div>
<?php if ($saved): ?><div class="flash success" role="status">Your AM settings were saved for this reseller account.</div><?php endif; ?>
<?php if ($errorMessage): ?><div class="flash error" role="alert"><?= e($errorMessage) ?></div><?php endif; ?>
<form method="post" action="<?= e(url('am-app/')) ?>">
<?= csrf_field() ?><input type="hidden" name="revision" value="<?= (int)($snapshot['revision'] ?? 0) ?>">
<fieldset<?= $ready ? '' : ' disabled' ?>><div class="am-grid">
<section class="card"><h2>Customer access</h2>
<label class="am-switch"><input type="checkbox" name="app_enabled" value="1"<?= am_checked($settings['app_enabled']) ?>><span>Enable AM for my customers<small class="am-muted">Saved participation preference. Activation requires the customer API rollout.</small></span></label>
<label class="am-field"><span>Who can use the app?</span><select name="customer_policy"><option value="selected"<?= am_selected($settings['customer_policy'], 'selected') ?>>Only customers I approve</option><option value="all"<?= am_selected($settings['customer_policy'], 'all') ?>>All customers belonging to me</option></select></label>
<p class="am-muted">Individual customer approval will be managed here after the customer lookup is connected. No customers are implicitly approved under the selected-customer policy.</p>
<label class="am-field"><span>Provider display name</span><input type="text" name="display_name" maxlength="120" value="<?= e($settings['display_name']) ?>"></label>
<label class="am-field"><span>Customer support email</span><input type="email" name="support_email" maxlength="254" value="<?= e($settings['support_email']) ?>"></label>
</section>
<section class="card"><h2>Service permissions</h2><p class="am-muted">Choose the actions you want to offer when their backend is connected.</p>
<?php foreach (['allow_bouquets' => 'Add or remove permitted bouquets', 'allow_packages' => 'Change package', 'allow_connections' => 'Change connection count', 'allow_renewals' => 'Renew or extend subscription'] as $field => $label): ?>
<label class="am-switch"><input type="checkbox" name="<?= e($field) ?>" value="1"<?= am_checked($settings[$field]) ?>><span><?= e($label) ?></span></label>
<?php endforeach; ?>
<p class="am-muted">Services show the package and bouquets, without categories. Customer choices must stay within the permitted package and bouquet catalogue.</p>
</section>
<section class="card am-wide"><h2>Billing &amp; renewals</h2><div class="am-grid">
<div><label class="am-field"><span>Billing flow</span><select name="billing_mode">
<?php foreach (['none' => 'Not configured', 'external' => 'My existing billing page', 'integrated' => 'Integrated gateway checkout (setup pending)', 'both' => 'Both billing methods (setup pending)'] as $value => $label): ?><option value="<?= e($value) ?>"<?= am_selected($settings['billing_mode'], $value) ?>><?= e($label) ?></option><?php endforeach; ?>
</select></label>
<label class="am-field"><span>Existing HTTPS billing page</span><input type="url" name="billing_url" maxlength="2048" placeholder="https://billing.example.com/" value="<?= e($settings['billing_url']) ?>"></label>
<p class="am-muted">Your public customer billing destination, not an API endpoint or secret. A link alone cannot verify payment or automatically renew a service.</p></div>
<div><label class="am-field"><span>Customer billing currency</span><select name="currency"><option value="CAD"<?= am_selected($settings['currency'], 'CAD') ?>>CAD</option><option value="USD"<?= am_selected($settings['currency'], 'USD') ?>>USD</option></select></label>
<div class="am-muted">Gateway preferences · merchant setup pending</div>
<?php foreach (['stripe' => 'Stripe', 'square' => 'Square', 'rampex' => 'Rampex'] as $value => $label): ?>
<label class="am-switch"><input type="checkbox" name="gateways[]" value="<?= e($value) ?>"<?= am_checked(in_array($value, $settings['gateways'], true)) ?>><span><?= e($label) ?></span></label>
<?php endforeach; ?>
<p class="am-muted">Gateway credentials, verification and pricing will be configured here in the next integration step. Selecting a gateway does not connect it or take payments.</p></div>
</div><div class="am-notice">Unused value from downgrades or fewer connections will become <strong>customer account credit</strong>. Proration rules and the customer ledger are still being connected. Reseller wholesale credits are separate.</div>
</section></div>
<div class="am-actions"><span class="am-muted">Only this reseller's AM settings are saved. No existing service is changed.</span><button class="btn btn-primary" type="submit">Save AM settings</button></div>
</fieldset></form>
</main></body></html>
