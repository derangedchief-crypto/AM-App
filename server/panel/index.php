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
require_once __DIR__ . '/Square.php';
require_once __DIR__ . '/Pricing.php';
$errorMessage = null;
$square = null;
$squareConnections = [];
try {
    $config = require __DIR__ . '/settings-config.php';
    $store = new \AmAccount\SettingsStore($config['database']);
    $snapshot = $store->read($resellerId);
    if (isset($config['secret_key'], $config['public_url'])) $square = new \AmAccount\SquareStore($config, new \AmAccount\Square());
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        csrf_check();
        $action = $_POST['action'] ?? 'settings';
        if ($action === 'package_price') {
            $catalogue = \AmAccount\PricingStore::catalogue(call_auth_worker('/packages', ['username' => $reseller['username']]));
            (new \AmAccount\PricingStore($config['database']))->save($resellerId, $_POST, $catalogue);
            $_SESSION['am_price_saved'] = $resellerId;
            header('Location: ' . url('am-app/?prices_currency=' . $_POST['currency'] . '#pricing'), true, 303); exit;
        }
        if ($action !== 'settings') {
            if (!$square) throw new InvalidArgumentException('Install the Square gateway update first.');
            $field = static function (string $name): string {
                $value = $_POST[$name] ?? '';
                if (!is_string($value)) throw new InvalidArgumentException('Invalid gateway field.');
                return trim($value);
            };
            try {
                switch ($action) {
                    case 'square_connect': $square->connect($resellerId, $field('mode'), $field('token'), $field('location')); break;
                    case 'square_webhook': $square->saveWebhook($resellerId, $field('connection'), $field('webhook_key')); break;
                    case 'square_checkout': $square->checkout($resellerId, $field('connection'), $field('confirm_live') === '1'); break;
                    case 'square_refresh': $square->refresh($resellerId, $field('connection')); break;
                    default: throw new InvalidArgumentException('Unknown gateway action.');
                }
            } catch (PDOException $error) {
                throw new InvalidArgumentException('Gateway storage could not confirm the operation. Reload and retry the original test.');
            } catch (RuntimeException $error) {
                throw new InvalidArgumentException($error->getMessage());
            }
            $_SESSION['am_square_saved'] = $resellerId;
            header('Location: ' . url('am-app/#square'), true, 303); exit;
        }
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
try { if ($square) $squareConnections = $square->connections($resellerId); }
catch (Throwable $error) { $square = null; $errorMessage = 'Square settings are unavailable. Complete the gateway update and reload.'; }
$saved = ($_SESSION['am_settings_saved'] ?? null) === $resellerId;
unset($_SESSION['am_settings_saved']);
$squareSaved = ($_SESSION['am_square_saved'] ?? null) === $resellerId;
unset($_SESSION['am_square_saved']);
$priceSaved = ($_SESSION['am_price_saved'] ?? null) === $resellerId;
unset($_SESSION['am_price_saved']);
$pricingError = null; $catalogue = []; $prices = [];
$priceCurrency = $_GET['prices_currency'] ?? $settings['currency'];
if (!is_string($priceCurrency) || !in_array($priceCurrency, ['CAD', 'USD'], true)) $priceCurrency = $settings['currency'];
try {
    if (!$ready) throw new RuntimeException('Storage unavailable.');
    $catalogue = \AmAccount\PricingStore::catalogue(call_auth_worker('/packages', ['username' => $reseller['username']]));
    $prices = (new \AmAccount\PricingStore($config['database']))->rows($resellerId);
} catch (Throwable $error) { $pricingError = 'Package pricing could not be loaded. Check the AM update and worker connection, then reload. Square setup remains separate below.'; }
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
<script>
(function () {
    try {
        var theme = localStorage.getItem('erp-theme');
        if (theme !== 'dark' && theme !== 'light') {
            theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        document.documentElement.setAttribute('data-theme', theme);
    } catch (error) {}
})();
</script>
<style>
.am-page{max-width:960px;margin:0 auto;padding:32px 20px 64px;color:var(--text)}
.am-top{display:flex;justify-content:space-between;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:26px;padding:24px;background:var(--card);border:1px solid var(--border);border-radius:12px}
.am-page h1{font-size:28px;margin:10px 0}.am-page h2{font-size:18px;margin:0 0 16px}
.am-page .am-muted{color:var(--text-muted,var(--muted));font-size:13px;line-height:1.6}
.am-page .am-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.am-page .am-wide{grid-column:1/-1}
.am-page .card{padding:24px}.am-page label.am-field{display:block;margin:16px 0}
.am-page label.am-field>span{display:block;margin-bottom:7px;font-weight:600;font-size:13px}
.am-page input[type=text],.am-page input[type=email],.am-page input[type=url],.am-page select{width:100%;min-height:44px}
.am-page input[type=url]{padding:10px 12px;border:1px solid var(--border);border-radius:8px;color:var(--text);font:inherit}
.am-page input[type=text],.am-page input[type=email],.am-page input[type=url],.am-page select{background:var(--muted-bg,var(--card));color:var(--text)}
.am-page label.am-switch{text-transform:none;letter-spacing:normal;color:var(--text)}
.am-page .am-switch small{font-weight:400}
.am-page .am-switch{display:flex;align-items:flex-start;gap:12px;margin:16px 0;font-size:14px}
.am-page .am-switch input{width:19px;height:19px;flex:none;margin-top:2px;accent-color:var(--accent,#8b9fff)}
.am-page .am-switch small{display:block;margin-top:5px}.am-notice{padding:16px;border:1px solid var(--border);border-radius:12px;margin-bottom:20px;line-height:1.6;font-size:14px;background:var(--card);color:var(--text)}
.am-status{font-size:11px;letter-spacing:1px;text-transform:uppercase;color:var(--text-muted,var(--muted))}
.am-actions{display:flex;justify-content:space-between;align-items:center;gap:20px;flex-wrap:wrap;margin-top:24px;padding:20px;background:var(--card);border:1px solid var(--border);border-radius:12px}
.am-page fieldset{border:0;padding:0;margin:0;min-width:0}.am-page a{overflow-wrap:anywhere}
.am-page input[type=password]{min-height:44px;background:var(--muted-bg,var(--card));color:var(--text)}
.am-square{margin-top:24px}.am-square form{margin-top:18px}.am-square code{overflow-wrap:anywhere;white-space:normal}.am-square .btn{white-space:normal}
.am-price{margin-top:18px;padding:18px;border:1px solid var(--border);border-radius:10px}.am-price h3{margin:0 0 8px}.am-page input[type=number]{background:var(--muted-bg,var(--card));color:var(--text);min-height:44px}
@media(max-width:640px){.am-page .am-grid{grid-template-columns:1fr}.am-page{padding:20px 14px}.am-page .card{padding:18px}}
</style>
</head><body>
<main class="am-page">
<div class="am-top"><div><div class="am-status">Private preview · hidden from navigation</div><h1>AM App management</h1>
<p class="am-muted">Settings for <?= e((string)($reseller['display_name'] ?? $reseller['username'] ?? 'your reseller account')) ?>.</p></div>
<a class="btn" href="<?= e(url('dashboard.php')) ?>">Back to reseller panel</a></div>
<div class="am-notice"><strong>Customer integration preview.</strong> Access and service switches save preferences only. Square can now be connected and tested below; gateway tests do not renew or modify a customer's service.</div>
<?php if ($saved): ?><div class="flash success" role="status">Your AM settings were saved for this reseller account.</div><?php endif; ?>
<?php if ($errorMessage): ?><div class="flash error" role="alert"><?= e($errorMessage) ?></div><?php endif; ?>
<?php if ($squareSaved): ?><div class="flash success" role="status">Square operation completed. Check the connection and verified test status below.</div><?php endif; ?>
<?php if ($priceSaved): ?><div class="flash success" role="status">Your customer renewal price was saved. The package's wholesale credit cost is unchanged.</div><?php endif; ?>
<?php if (isset($_GET['square_return'])): ?><div class="am-notice">Returned from Square. Use <strong>Check payment with Square</strong> below. Returning here does not confirm payment.</div><?php endif; ?>
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
<p class="am-muted">Connect Square below. Stripe and Rampex setup remain pending. Customer pricing and automatic service fulfilment are not connected yet.</p></div>
</div><div class="am-notice">Unused value from downgrades or fewer connections will become <strong>customer account credit</strong>. Proration rules and the customer ledger are still being connected. Reseller wholesale credits are separate.</div>
</section></div>
<div class="am-actions"><span class="am-muted">Only this reseller's AM settings are saved. No existing service is changed.</span><button class="btn btn-primary" type="submit">Save AM settings</button></div>
</fieldset></form>
<section id="pricing" class="card am-square">
<h2>Package renewal prices</h2>
<p>Set your own customer price for each package offered to your reseller account. The service renewal still deducts the package's fixed credit cost from your reseller balance. <strong>Bouquets have no extra charge.</strong></p>
<p class="am-muted">Prices are saved here now. Customer checkout and automatic renewal are still being connected. 0.00 means a free customer renewal; you still pay the wholesale credits.</p>
<form method="get" action="<?= e(url('am-app/#pricing')) ?>"><label class="am-field"><span>Price currency</span><select name="prices_currency"><option value="CAD"<?= am_selected($priceCurrency, 'CAD') ?>>CAD</option><option value="USD"<?= am_selected($priceCurrency, 'USD') ?>>USD</option></select></label><button class="btn" type="submit">Show prices</button></form>
<?php if ($pricingError): ?><p role="alert"><?= e($pricingError) ?></p>
<?php elseif (!$catalogue): ?><p>No packages are currently offered to this reseller account.</p>
<?php else: foreach ($catalogue as $package): $price = $prices[$package['id'] . ':' . $priceCurrency] ?? null; ?>
<form class="am-price" method="post" action="<?= e(url('am-app/')) ?>">
<?= csrf_field() ?><input type="hidden" name="action" value="package_price"><input type="hidden" name="package_id" value="<?= $package['id'] ?>"><input type="hidden" name="currency" value="<?= e($priceCurrency) ?>"><input type="hidden" name="revision" value="<?= (int)($price['revision'] ?? 0) ?>"><input type="hidden" name="fingerprint" value="<?= e($package['fingerprint']) ?>">
<h3><?= e($package['name']) ?></h3>
<p class="am-muted">Package #<?= $package['id'] ?> · <?= e((string)($package['duration'] ?? '')) ?> <?= e((string)($package['duration_period'] ?? '')) ?> · <?= $package['max_connections'] ?> connection(s)</p>
<p><strong>Your wholesale cost: <?= e($package['price_credits']) ?> credits per renewal.</strong></p>
<?php if ($price && $price['package_fingerprint'] !== $package['fingerprint']): ?><p>Package details or wholesale cost changed. Review and save your price again before it can be offered.</p><?php endif; ?>
<label class="am-field"><span>Customer renewal price · <?= e($priceCurrency) ?></span><input type="number" name="amount" min="0" max="999999.99" step="0.01" required value="<?= $price ? e(number_format((int)$price['amount_minor'] / 100, 2, '.', '')) : '' ?>"></label>
<label class="am-switch"><input type="checkbox" name="enabled" value="1"<?= am_checked($price && (int)$price['enabled'] === 1) ?>><span>Offer this package for customer renewal</span></label>
<button class="btn btn-primary" type="submit">Save package price</button>
</form>
<?php endforeach; endif; ?>
</section>
<section id="square" class="card am-square">
<h2>Square · merchant connection &amp; checkout test</h2>
<p class="am-muted">Uses the Hub-style Square-hosted checkout. Card details stay with Square; AM checks the merchant, location, amount, currency and captured payment on the server. Credentials are encrypted in private storage and are never shown again.</p>
<?php if (!$square): ?><p>Install the gateway update to enable connection setup.</p><?php else: ?>
<form method="post" action="<?= e(url('am-app/#square')) ?>" autocomplete="off">
<?= csrf_field() ?><input type="hidden" name="action" value="square_connect">
<div class="am-grid"><label class="am-field"><span>Square environment</span><select name="mode"><option value="sandbox">Sandbox · test payments</option><option value="live">Production · real payments</option></select></label>
<label class="am-field"><span>Location ID</span><input type="text" name="location" required maxlength="180" autocomplete="off"></label>
<label class="am-field am-wide"><span>Access token</span><input type="password" name="token" required maxlength="512" autocomplete="new-password"></label></div>
<p class="am-muted">From your Square Developer application, use an access token and location from the same environment. Hosted checkout does not need an Application ID. For scoped tokens, grant MERCHANT_PROFILE_READ, ORDERS_READ, ORDERS_WRITE, PAYMENTS_READ and PAYMENTS_WRITE. Reconnecting the same merchant/location updates its token and keeps its original test invoice.</p>
<button class="btn btn-primary" type="submit">Verify &amp; save Square connection</button>
</form>
<?php foreach ($squareConnections as $connection): $test = $square->test($resellerId, $connection['id']); ?>
<section class="am-notice am-square">
<h2><?= e($connection['business_name']) ?> · <?= $connection['mode'] === 'live' ? 'Production / real money' : 'Sandbox / test money' ?></h2>
<p class="am-muted">Merchant <?= e($connection['merchant_id']) ?> · Location <?= e($connection['location_id']) ?> · <?= e($connection['currency']) ?></p>
<p><strong>Webhook URL:</strong><br><code><?= e($square->webhookUrl($connection['id'])) ?></code></p>
<p class="am-muted">In Square Developer → your application → the matching environment → Webhooks, add this exact URL, API version 2026-09-16, and events <code>payment.created</code> and <code>payment.updated</code>. Paste its signature key below. Do not reuse Hub's webhook URL.</p>
<form method="post" action="<?= e(url('am-app/#square')) ?>" autocomplete="off">
<?= csrf_field() ?><input type="hidden" name="action" value="square_webhook"><input type="hidden" name="connection" value="<?= e($connection['id']) ?>">
<label class="am-field"><span>Webhook signature key <?= $connection['webhook_saved'] ? '(saved; enter only to replace)' : '' ?></span><input type="password" name="webhook_key" required maxlength="512" autocomplete="new-password"></label>
<button class="btn" type="submit">Save webhook key</button></form>
<p class="am-muted"><?= $connection['webhook_seen'] ? 'Signed notification received at ' . e(gmdate('Y-m-d H:i:s', (int)$connection['webhook_seen'])) . ' UTC.' : 'No authenticated notification received yet. A generic Square test event may not identify your merchant; the checkout payment will send real event data.' ?></p>
<p><strong>Setup checkout: 1.00 <?= e($connection['currency']) ?>.</strong> No subscription is purchased or renewed. <?= $connection['mode'] === 'live' ? 'This is a real charge; any refund is managed in your Square dashboard.' : "Use Square's Sandbox Testing Panel to simulate payment. It can report an EXTERNAL test payment rather than captured card details. No real money is collected." ?></p>
<?php if (!$test || !$test['checkout_url']): ?>
<form method="post" action="<?= e(url('am-app/#square')) ?>">
<?= csrf_field() ?><input type="hidden" name="action" value="square_checkout"><input type="hidden" name="connection" value="<?= e($connection['id']) ?>">
<?php if ($connection['mode'] === 'live'): ?><label class="am-switch"><input type="checkbox" name="confirm_live" value="1" required><span>I want a real 1.00 <?= e($connection['currency']) ?> setup checkout.</span></label><?php endif; ?>
<button class="btn btn-primary" type="submit"<?= $connection['webhook_saved'] ? '' : ' disabled' ?>><?= $test ? 'Retry original checkout creation' : 'Create setup checkout' ?></button></form>
<?php endif; ?>
<?php if ($test): ?>
<p>Test invoice <code><?= e($test['id']) ?></code> · <strong><?= e($test['status'] === 'sandbox_verified' ? 'Sandbox test verified · no real payment or service action' : ($test['status'] === 'paid' ? ($connection['mode'] === 'sandbox' ? 'Sandbox card test verified · no real payment or service action' : 'Payment verified · no service action') : ($test['status'] === 'review' ? 'Verification requires review · do not pay again' : 'Payment not yet verified'))) ?></strong></p>
<?php if ($test['checkout_url'] && $test['status'] === 'pending'): ?><a class="btn btn-primary" href="<?= e($test['checkout_url']) ?>" target="_blank" rel="noopener noreferrer">Open Square checkout · 1.00 <?= e($test['currency']) ?></a><?php endif; ?>
<?php if ($test['square_order_id']): ?><form method="post" action="<?= e(url('am-app/#square')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="square_refresh"><input type="hidden" name="connection" value="<?= e($connection['id']) ?>"><button class="btn" type="submit">Check payment with Square</button></form><?php endif; ?>
<?php endif; ?>
</section>
<?php endforeach; endif; ?>
</section>
</main></body></html>
