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
require_once __DIR__ . '/Customer.php';
$errorMessage = null;
$square = null;
$squareConnections = [];
// Carry only catalogue view parameters across price saves; never carry posted credentials.
$viewQuery = [];
foreach (['q','connections','term','price_status','sort','page','prices_currency'] as $key) {
    if (isset($_GET[$key]) && is_string($_GET[$key])) $viewQuery[$key] = substr($_GET[$key],0,160);
}
try {
    $config = require __DIR__ . '/settings-config.php';
    $store = new \AmAccount\SettingsStore($config['database']);
    $snapshot = $store->read($resellerId);
    if (isset($config['secret_key'], $config['public_url'])) $square = new \AmAccount\SquareStore($config, new \AmAccount\Square());
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        csrf_check();
        $action = $_POST['action'] ?? 'settings';
        if ($action === 'renewal_diagnostic') {
            if (!is_string($_POST['line_id'] ?? null)) throw new InvalidArgumentException('Invalid customer service ID.');
            $customers = new \AmAccount\CustomerService($config,static fn($path,$body)=>call_auth_worker($path,$body),new \AmAccount\Square());
            $_SESSION['am_renewal_diagnostic'] = ['reseller'=>$resellerId,'result'=>$customers->renewalDiagnostic($resellerId,trim($_POST['line_id']))];
            header('Location: ' . url('am-app/#renewal-check'), true, 303); exit;
        }
        if ($action === 'customer_retry') {
            if (!is_string($_POST['order_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D',$_POST['order_id'])) throw new InvalidArgumentException('Invalid order reference.');
            $customers = new \AmAccount\CustomerService($config,static fn($path,$body)=>call_auth_worker($path,$body),new \AmAccount\Square());
            $customers->retryOrder($resellerId,$_POST['order_id']);
            header('Location: ' . url('am-app/#customer-orders'), true, 303); exit;
        }
        if ($action === 'customer_access') {
            if (!is_string($_POST['line_id'] ?? null) || !in_array($_POST['enabled'] ?? null,['0','1'],true)) throw new InvalidArgumentException('Invalid customer access request.');
            $customers = new \AmAccount\CustomerService($config,static fn($path,$body)=>call_auth_worker($path,$body),new \AmAccount\Square());
            $customers->approve($resellerId,trim($_POST['line_id']),$_POST['enabled'] === '1');
            $_SESSION['am_settings_saved'] = $resellerId;
            header('Location: ' . url('am-app/#customer-access'), true, 303); exit;
        }
        if ($action === 'package_price') {
            $catalogue = \AmAccount\PricingStore::catalogue(call_auth_worker('/packages', ['username' => $reseller['username']]));
            (new \AmAccount\PricingStore($config['database']))->save($resellerId, $_POST, $catalogue);
            $_SESSION['am_price_saved'] = $resellerId;
            $viewQuery['prices_currency'] = $_POST['currency'];
            header('Location: ' . url('am-app/?' . http_build_query($viewQuery) . '#pricing'), true, 303); exit;
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
} catch (\AmAccount\CustomerError $error) {
    http_response_code($error->status); $errorMessage = $error->getMessage();
} catch (InvalidArgumentException $error) {
    http_response_code(422); $errorMessage = $error->getMessage();
} catch (Throwable $error) {
    http_response_code(503); $errorMessage = 'AM settings storage is unavailable. No reseller-panel settings were changed.';
}
$ready = isset($snapshot);
$renewalDiagnostic = ($_SESSION['am_renewal_diagnostic']['reseller'] ?? null) === $resellerId ? ($_SESSION['am_renewal_diagnostic']['result'] ?? null) : null;
unset($_SESSION['am_renewal_diagnostic']);
$customerOrders = []; $customerOrdersError = null;
try {
    if (!$ready) throw new RuntimeException('Storage unavailable.');
    $customerOrders = (new \AmAccount\CustomerService($config,static fn($path,$body)=>call_auth_worker($path,$body),new \AmAccount\Square()))->resellerOrders($resellerId);
} catch (Throwable $error) { $customerOrdersError = 'Customer order storage is unavailable. Install the customer API update.'; }
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
$packageStates = []; $connectionOptions = []; $termOptions = []; $stateCounts = ['enabled'=>0,'disabled'=>0,'unset'=>0,'review'=>0];
foreach ($catalogue as $p) {
    $price = $prices[$p['id'] . ':' . $priceCurrency] ?? null;
    $status = !$price ? 'unset' : ($price['package_fingerprint'] !== $p['fingerprint'] ? 'review' : ((int)$price['enabled'] === 1 ? 'enabled' : 'disabled'));
    $packageStates[$p['id']] = $status; $stateCounts[$status]++;
    $connectionOptions[(string)$p['max_connections']] = (string)$p['max_connections'];
    $term = trim((string)($p['duration'] ?? '') . ' ' . (string)($p['duration_period'] ?? ''));
    $termOptions[$term] = $term;
}
natsort($connectionOptions); natsort($termOptions);
$query = trim($viewQuery['q'] ?? '');
$connectionFilter = $viewQuery['connections'] ?? '';
$termFilter = $viewQuery['term'] ?? '';
$statusFilter = $viewQuery['price_status'] ?? '';
$sort = $viewQuery['sort'] ?? 'name';
$filteredPackages = array_values(array_filter($catalogue, static function ($p) use ($query,$connectionFilter,$termFilter,$statusFilter,$packageStates) {
    return ($query === '' || stripos($p['name'] . ' #' . $p['id'],$query) !== false)
        && ($connectionFilter === '' || (string)$p['max_connections'] === $connectionFilter)
        && ($termFilter === '' || trim((string)($p['duration'] ?? '') . ' ' . (string)($p['duration_period'] ?? '')) === $termFilter)
        && ($statusFilter === '' || $packageStates[$p['id']] === $statusFilter);
}));
usort($filteredPackages, static function ($a,$b) use ($sort) {
    $result = $sort === 'connections' ? $a['max_connections'] <=> $b['max_connections'] : ($sort === 'credits' ? (float)$a['price_credits'] <=> (float)$b['price_credits'] : strnatcasecmp($a['name'],$b['name']));
    return $result ?: $a['id'] <=> $b['id'];
});
$packageCount = count($filteredPackages); $pageCount = max(1,(int)ceil($packageCount / 12));
$page = max(1,min($pageCount,(int)($viewQuery['page'] ?? 1)));
$visiblePackages = array_slice($filteredPackages,($page-1)*12,12);
$viewQuery['page'] = $page; $viewQuery['prices_currency'] = $priceCurrency;
$pricingUrl = url('am-app/?' . http_build_query($viewQuery) . '#pricing');
$statusLabels = ['enabled'=>'Enabled','disabled'=>'Disabled','unset'=>'Not priced','review'=>'Needs review'];
$initialSection = isset($_GET['square_return']) ? 'square' : ($saved ? 'settings' : 'pricing');
if (isset($action) && is_string($action)) $initialSection = match ($action) {
    'package_price'=>'pricing', 'customer_access'=>'customer-access', 'renewal_diagnostic'=>'renewal-check', 'customer_retry'=>'customer-orders',
    'square_connect','square_webhook','square_checkout','square_refresh'=>'square', default=>'settings'
};
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
.am-page{max-width:1200px;margin:0 auto;padding:32px 20px 64px;color:var(--text)}
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
.am-nav{display:flex;flex-wrap:wrap;gap:8px;margin:20px 0}.am-nav a{padding:12px 16px;border-radius:10px;text-decoration:none;color:var(--text);background:var(--card);border:1px solid var(--border);font-weight:600;font-size:14px}.am-nav a[aria-current=page]{background:var(--accent,#8b9fff);color:var(--bg,#111827)}
.am-page [hidden]{display:none!important}.am-page [data-am-section]{scroll-margin-top:20px}.am-page .am-square{margin-top:0}.am-page .am-summary{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0}.am-summary span{padding:8px 12px;background:var(--muted-bg,var(--card));border:1px solid var(--border);border-radius:8px;font-size:13px}
.am-page .am-filters{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:16px;background:var(--muted-bg,var(--card));border-radius:12px;margin:20px 0}.am-page .am-filters .am-field{margin:0}.am-filter-actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;grid-column:1/-1}
.am-package-list{display:grid;gap:10px}.am-package{border:1px solid var(--border);border-radius:12px;overflow:hidden}.am-package summary{cursor:pointer;padding:16px;display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:18px;align-items:center}.am-package summary strong{display:block;overflow-wrap:anywhere}.am-package summary small{display:block;margin-top:6px;color:var(--text-muted,var(--muted))}.am-package summary::after{content:'Edit price +';font-size:12px;color:var(--text-muted,var(--muted))}.am-package[open] summary::after{content:'Close −'}.am-package[open] summary{border-bottom:1px solid var(--border)}.am-package summary:focus-visible{outline:2px solid var(--accent,#8b9fff);outline-offset:-3px}.am-page .am-price{margin:0;border:0;padding:18px}.am-price-badge{font-size:12px;font-weight:600;white-space:nowrap}.am-price-badge[data-status=review]{color:var(--warning,#b7791f)}.am-pager{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:20px;flex-wrap:wrap}.am-help{margin:16px 0;color:var(--text-muted,var(--muted));font-size:13px;line-height:1.6}.am-help summary{cursor:pointer;font-weight:600}.am-section-title{margin:0 0 20px}.am-page .am-empty{padding:32px;text-align:center;border:1px dashed var(--border);border-radius:12px}
@media(max-width:760px){.am-page .am-filters{grid-template-columns:1fr 1fr}.am-package summary{grid-template-columns:minmax(0,1fr) auto;gap:10px}.am-package summary::after{grid-column:1/-1}.am-nav a{flex:1;text-align:center}.am-page .am-filters .am-search{grid-column:1/-1}}
@media(max-width:640px){.am-page .am-grid{grid-template-columns:1fr}.am-page{padding:20px 14px}.am-page .card{padding:18px}}
@media(max-width:760px){.am-nav{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.am-nav a{overflow-wrap:normal}}
.am-package summary::after{content:'View details +'}#pricing .am-package summary::after{content:'Edit price +'}.am-package[open] summary::after,#pricing .am-package[open] summary::after{content:'Close −'}.card>.am-package{margin-bottom:10px}
</style>
</head><body>
<main class="am-page">
<div class="am-top"><div><div class="am-status">Private preview · hidden from navigation</div><h1>AM App management</h1>
<p class="am-muted">Settings for <?= e((string)($reseller['display_name'] ?? $reseller['username'] ?? 'your reseller account')) ?>.</p></div>
<a class="btn" href="<?= e(url('dashboard.php')) ?>">Back to reseller panel</a></div>
<nav class="am-nav" aria-label="AM management sections">
<?php foreach (['pricing'=>'Packages & prices','settings'=>'App settings','customer-access'=>'Customer access','renewal-check'=>'Renewal check','customer-orders'=>'Orders','square'=>'Square setup'] as $id=>$label): ?><a href="#<?= e($id) ?>" data-am-link="<?= e($id) ?>"><?= e($label) ?></a><?php endforeach; ?>
</nav>
<?php if ($saved): ?><div class="flash success" role="status">Your AM settings were saved for this reseller account.</div><?php endif; ?>
<?php if ($errorMessage): ?><div class="flash error" role="alert"><?= e($errorMessage) ?></div><?php endif; ?>
<?php if ($squareSaved): ?><div class="flash success" role="status">Square operation completed. Check the connection and verified test status below.</div><?php endif; ?>
<?php if ($priceSaved): ?><div class="flash success" role="status">Your customer renewal price was saved. General renewal permission and billing settings are saved separately with Save AM settings.</div><?php endif; ?>
<?php if (isset($_GET['square_return'])): ?><div class="am-notice">Returned from Square. Use <strong>Check payment with Square</strong> below. Returning here does not confirm payment.</div><?php endif; ?>
<form id="settings" data-am-section method="post" action="<?= e(url('am-app/#settings')) ?>">
<?= csrf_field() ?><input type="hidden" name="revision" value="<?= (int)($snapshot['revision'] ?? 0) ?>">
<fieldset<?= $ready ? '' : ' disabled' ?>><div class="am-grid">
<section class="card"><h2>Customer access</h2>
<label class="am-switch"><input type="checkbox" name="app_enabled" value="1"<?= am_checked($settings['app_enabled']) ?>><span>Enable AM for my customers<small class="am-muted">The customer API checks this setting at every login and request.</small></span></label>
<label class="am-field"><span>Who can use the app?</span><select name="customer_policy"><option value="selected"<?= am_selected($settings['customer_policy'], 'selected') ?>>Only customers I approve</option><option value="all"<?= am_selected($settings['customer_policy'], 'all') ?>>All customers belonging to me</option></select></label>
<p class="am-muted">Approve individual services in <a href="#customer-access">Customer access</a>. Revoking access blocks that service even under the all-customers policy.</p>
<label class="am-field"><span>Service display name (shown in the app)</span><input type="text" name="display_name" maxlength="120" placeholder="e.g. Extreme TV" value="<?= e($settings['display_name']) ?>"></label>
<label class="am-field"><span>Customer support email</span><input type="email" name="support_email" maxlength="254" value="<?= e($settings['support_email']) ?>"></label>
</section>
<section class="card"><h2>Customer permissions</h2><p class="am-muted">Customers can edit permitted bouquets for free and renew their current package. Package and connection changes are handled by their reseller.</p>
<?php foreach (['allow_bouquets' => 'Add or remove permitted bouquets (free)', 'allow_renewals' => 'Renew or extend current subscription'] as $field => $label): ?>
<label class="am-switch"><input type="checkbox" name="<?= e($field) ?>" value="1"<?= am_checked($settings[$field]) ?>><span><?= e($label) ?></span></label>
<?php endforeach; ?>
<p class="am-muted">Services show the package and bouquets, without categories. Customer choices must stay within the permitted package and bouquet catalogue.</p>
</section>
<section class="card am-wide"><h2>Billing &amp; renewals</h2><div class="am-grid">
<div><label class="am-field"><span>Billing flow</span><select name="billing_mode">
<?php foreach (['none' => 'Not configured', 'external' => 'My existing billing page', 'integrated' => 'Integrated gateway checkout', 'both' => 'Both billing methods'] as $value => $label): ?><option value="<?= e($value) ?>"<?= am_selected($settings['billing_mode'], $value) ?>><?= e($label) ?></option><?php endforeach; ?>
</select></label>
<label class="am-field"><span>Existing HTTPS billing page</span><input type="url" name="billing_url" maxlength="2048" placeholder="https://billing.example.com/" value="<?= e($settings['billing_url']) ?>"></label>
<p class="am-muted">Your public customer billing destination, not an API endpoint or secret. A link alone cannot verify payment or automatically renew a service.</p></div>
<div><label class="am-field"><span>Customer billing currency</span><select name="currency"><option value="CAD"<?= am_selected($settings['currency'], 'CAD') ?>>CAD</option><option value="USD"<?= am_selected($settings['currency'], 'USD') ?>>USD</option></select></label>
<label class="am-field"><span>Customer Square checkout mode</span><select name="square_mode"><option value="sandbox"<?= am_selected($settings['square_mode'], 'sandbox') ?>>Sandbox · no real payment or renewal</option><option value="live"<?= am_selected($settings['square_mode'], 'live') ?>>Production · real payment and renewal</option></select></label>
<div class="am-muted">Gateway preferences · merchant setup pending</div>
<?php foreach (['stripe' => 'Stripe', 'square' => 'Square', 'rampex' => 'Rampex'] as $value => $label): ?>
<label class="am-switch"><input type="checkbox" name="gateways[]" value="<?= e($value) ?>"<?= am_checked(in_array($value, $settings['gateways'], true)) ?>><span><?= e($label) ?></span></label>
<?php endforeach; ?>
<p class="am-muted">Connect your merchant in <a href="#square">Square setup</a>. Customer renewal requires integrated billing and Square selected. Stripe and Rampex setup remain pending.</p></div>
</div><div class="am-notice">AM checkout is <strong>renewal-only</strong>. Customers must contact their reseller for package or connection changes.</div>
</section></div>
<div class="am-actions"><span class="am-muted">Only this reseller's AM settings are saved. No existing service is changed.</span><button class="btn btn-primary" type="submit">Save AM settings</button></div>
</fieldset></form>
<section id="customer-access" data-am-section class="card am-square"><h2>Individual customer access</h2>
<p class="am-muted">Enter the customer's numeric service ID from your reseller panel. The worker verifies that the service belongs to your account.</p>
<form method="post" action="<?= e(url('am-app/#customer-access')) ?>">
<?= csrf_field() ?><input type="hidden" name="action" value="customer_access">
<label class="am-field"><span>Customer service ID</span><input type="text" name="line_id" inputmode="numeric" pattern="[1-9][0-9]{0,17}" required></label>
<label class="am-field"><span>Access</span><select name="enabled"><option value="1">Approve</option><option value="0">Revoke</option></select></label>
<button class="btn btn-primary" type="submit">Save customer access</button></form></section>
<section id="renewal-check" data-am-section class="card am-square"><h2>Check a service's renewal availability</h2>
<p class="am-muted">Saving a package price is separate from the general renewal permission and Square setup. Enter the numeric customer service ID from your reseller panel to see the exact reason an offer is unavailable. This check does not charge or renew anything.</p>
<form method="post" action="<?= e(url('am-app/#renewal-check')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="renewal_diagnostic">
<label class="am-field"><span>Customer service ID</span><input type="text" name="line_id" inputmode="numeric" pattern="[1-9][0-9]{0,17}" required></label>
<button class="btn" type="submit">Check renewal availability</button></form>
<?php if ($renewalDiagnostic): ?><div class="am-notice" role="status"><strong><?= e($renewalDiagnostic['message']) ?></strong>
<p>Service #<?= e($renewalDiagnostic['line_id']) ?> · Assigned package #<?= e((string)($renewalDiagnostic['package_id'] ?? 'none')) ?> <?= e($renewalDiagnostic['package_name']) ?> · <?= (int)$renewalDiagnostic['connections'] ?> connection(s) · <?= e($renewalDiagnostic['mode']) ?></p>
<?php if ($renewalDiagnostic['offers']): ?><p>Available currencies: <?= e(implode(', ',$renewalDiagnostic['offers'])) ?></p><?php endif; ?></div><?php endif; ?>
</section>
<section id="pricing" data-am-section class="card am-square">
<h2>Package renewal prices</h2>
<p class="am-muted">Find a package, expand its row, and set your customer price. Each package saves separately.</p>
<div class="am-summary"><span><strong><?= count($catalogue) ?></strong> packages</span><?php foreach ($statusLabels as $status=>$label): ?><span><?= $stateCounts[$status] ?> <?= e(strtolower($label)) ?></span><?php endforeach; ?></div>
<?php if (!$settings['allow_renewals'] || !in_array($settings['billing_mode'],['integrated','both'],true) || !in_array('square',$settings['gateways'],true)): ?><div class="am-notice"><strong>Renewal setup incomplete.</strong> In <a href="#settings">App settings</a>, enable renewals, select integrated billing and Square, then Save AM settings. Package prices save separately.</div><?php endif; ?>
<details class="am-help"><summary>How renewal pricing works</summary><p>Set your own customer price for each package offered to your reseller account. The service renewal still deducts the package's fixed credit cost from your reseller balance. <strong>Bouquets have no extra charge.</strong></p>
<p class="am-muted">Customers only see prices for their current package. 0.00 means a free customer renewal; you still pay the wholesale credits. Production mode renews after payment verification; sandbox never modifies service.</p>
</details>
<form class="am-filters" method="get" action="<?= e(url('am-app/#pricing')) ?>">
<label class="am-field am-search"><span>Search packages</span><input type="text" name="q" value="<?= e($query) ?>" placeholder="Package name or #ID" maxlength="160"></label>
<label class="am-field"><span>Connections</span><select name="connections"><option value="">All connections</option><?php foreach ($connectionOptions as $value): ?><option value="<?= e($value) ?>"<?= am_selected($connectionFilter,$value) ?>><?= e($value) ?> connection(s)</option><?php endforeach; ?></select></label>
<label class="am-field"><span>Duration</span><select name="term"><option value="">All durations</option><?php foreach ($termOptions as $value): ?><option value="<?= e($value) ?>"<?= am_selected($termFilter,$value) ?>><?= e($value) ?></option><?php endforeach; ?></select></label>
<label class="am-field"><span>Price status</span><select name="price_status"><option value="">All statuses</option><?php foreach ($statusLabels as $value=>$label): ?><option value="<?= e($value) ?>"<?= am_selected($statusFilter,$value) ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
<label class="am-field"><span>Price currency</span><select name="prices_currency"><option value="CAD"<?= am_selected($priceCurrency,'CAD') ?>>CAD</option><option value="USD"<?= am_selected($priceCurrency,'USD') ?>>USD</option></select></label>
<label class="am-field"><span>Sort by</span><select name="sort"><?php foreach (['name'=>'Package name','connections'=>'Connections','credits'=>'Wholesale credits'] as $value=>$label): ?><option value="<?= e($value) ?>"<?= am_selected($sort,$value) ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
<div class="am-filter-actions"><button class="btn btn-primary" type="submit">Apply filters</button><a href="<?= e(url('am-app/?prices_currency='.$priceCurrency.'#pricing')) ?>">Clear filters</a><span class="am-muted"><?= $packageCount ?> matching packages</span></div></form>
<?php if ($pricingError): ?><p role="alert"><?= e($pricingError) ?></p>
<?php elseif (!$catalogue): ?><p>No packages are currently offered to this reseller account.</p>
<?php elseif (!$visiblePackages): ?><p class="am-empty">No packages match these filters. Try a different name, connection count or duration.</p>
<?php else: ?><div class="am-package-list"><?php foreach ($visiblePackages as $package): $price = $prices[$package['id'] . ':' . $priceCurrency] ?? null; $status = $packageStates[$package['id']]; ?>
<details class="am-package"<?= $errorMessage && ($action ?? '') === 'package_price' && (string)($_POST['package_id'] ?? '') === (string)$package['id'] ? ' open' : '' ?>>
<summary><span><strong><?= e($package['name']) ?></strong><small>#<?= $package['id'] ?> · <?= e((string)($package['duration'] ?? '')) ?> <?= e((string)($package['duration_period'] ?? '')) ?> · <?= $package['max_connections'] ?> connections · <?= e($package['price_credits']) ?> wholesale credits</small></span><span class="am-price-badge" data-status="<?= e($status) ?>"><?= $price ? e(number_format((int)$price['amount_minor']/100,2,'.','').' '.$priceCurrency) : '—' ?><small><?= e($statusLabels[$status]) ?></small></span></summary>
<form class="am-price" method="post" action="<?= e($pricingUrl) ?>">
<?= csrf_field() ?><input type="hidden" name="action" value="package_price"><input type="hidden" name="package_id" value="<?= $package['id'] ?>"><input type="hidden" name="currency" value="<?= e($priceCurrency) ?>"><input type="hidden" name="revision" value="<?= (int)($price['revision'] ?? 0) ?>"><input type="hidden" name="fingerprint" value="<?= e($package['fingerprint']) ?>">
<h3><?= e($package['name']) ?></h3>
<p class="am-muted">Package #<?= $package['id'] ?> · <?= e((string)($package['duration'] ?? '')) ?> <?= e((string)($package['duration_period'] ?? '')) ?> · <?= $package['max_connections'] ?> connection(s)</p>
<p><strong>Your wholesale cost: <?= e($package['price_credits']) ?> credits per renewal.</strong></p>
<?php if ($price && $price['package_fingerprint'] !== $package['fingerprint']): ?><p>Package details or wholesale cost changed. Review and save your price again before it can be offered.</p><?php endif; ?>
<label class="am-field"><span>Customer renewal price · <?= e($priceCurrency) ?></span><input type="number" name="amount" min="0" max="999999.99" step="0.01" required value="<?= $price ? e(number_format((int)$price['amount_minor'] / 100, 2, '.', '')) : '' ?>"></label>
<label class="am-switch"><input type="checkbox" name="enabled" value="1"<?= am_checked($price && (int)$price['enabled'] === 1) ?>><span>Offer this package for customer renewal</span></label>
<button class="btn btn-primary" type="submit">Save package price</button>
</form></details>
<?php endforeach; ?></div>
<nav class="am-pager" aria-label="Package pages">
<?php if ($page > 1): ?><a class="btn" href="<?= e(url('am-app/?'.http_build_query(array_merge($viewQuery,['page'=>$page-1])).'#pricing')) ?>">← Previous</a><?php endif; ?>
<span class="am-muted">Page <?= $page ?> of <?= $pageCount ?> · Showing <?= ($page-1)*12+1 ?>–<?= min($page*12,$packageCount) ?> of <?= $packageCount ?></span>
<?php if ($page < $pageCount): ?><a class="btn" href="<?= e(url('am-app/?'.http_build_query(array_merge($viewQuery,['page'=>$page+1])).'#pricing')) ?>">Next →</a><?php endif; ?></nav>
<?php endif; ?>
</section>
<section id="customer-orders" data-am-section class="card am-square"><h2>Customer renewal orders</h2>
<p class="am-muted">Most recent 50 orders for your customers. Retry checks the original payment and renewal ID; it does not create another invoice. If a paid order needs review, check your wholesale credit balance, customer access and package availability before retrying.</p>
<?php if ($customerOrdersError): ?><p role="alert"><?= e($customerOrdersError) ?></p>
<?php elseif (!$customerOrders): ?><p>No customer orders yet.</p><?php else: foreach ($customerOrders as $order): ?>
<details class="am-package"><summary><span><strong><?= e($order['package_name']) ?></strong><small>Service #<?= e($order['line_id']) ?> · <?= e($order['mode']) ?></small></span><span><?= e(number_format($order['amount_minor']/100,2,'.','')) ?> <?= e($order['currency']) ?><small><?= e($order['state']) ?></small></span></summary><div class="am-price">
<p>Service #<?= e($order['line_id']) ?> · <?= e(number_format($order['amount_minor']/100,2,'.','')) ?> <?= e($order['currency']) ?> · <?= e($order['mode']) ?></p>
<p><?= e($order['message']) ?></p><p class="am-muted">Order <?= e($order['id']) ?> · <?= e($order['state']) ?></p>
<?php if (!in_array($order['state'],['applied','sandbox_verified'],true)): ?>
<form method="post" action="<?= e(url('am-app/#customer-orders')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="customer_retry"><input type="hidden" name="order_id" value="<?= e($order['id']) ?>"><button class="btn" type="submit">Retry original order</button></form>
<?php endif; ?></div></details><?php endforeach; endif; ?></section>
<section id="square" data-am-section class="card am-square">
<h2>Square · merchant connection &amp; checkout test</h2>
<p class="am-muted">Uses the Hub-style Square-hosted checkout. Card details stay with Square; AM checks the merchant, location, amount, currency and captured payment on the server. Credentials are encrypted in private storage and are never shown again.</p>
<?php if (!$square): ?><p>Install the gateway update to enable connection setup.</p><?php else: ?>
<details class="am-help"<?= $squareConnections ? '' : ' open' ?>><summary>Connect or update a Square merchant</summary>
<form method="post" action="<?= e(url('am-app/#square')) ?>" autocomplete="off">
<?= csrf_field() ?><input type="hidden" name="action" value="square_connect">
<div class="am-grid"><label class="am-field"><span>Square environment</span><select name="mode"><option value="sandbox">Sandbox · test payments</option><option value="live">Production · real payments</option></select></label>
<label class="am-field"><span>Location ID</span><input type="text" name="location" required maxlength="180" autocomplete="off"></label>
<label class="am-field am-wide"><span>Access token</span><input type="password" name="token" required maxlength="512" autocomplete="new-password"></label></div>
<p class="am-muted">From your Square Developer application, use an access token and location from the same environment. Hosted checkout does not need an Application ID. For scoped tokens, grant MERCHANT_PROFILE_READ, ORDERS_READ, ORDERS_WRITE, PAYMENTS_READ and PAYMENTS_WRITE. Reconnecting the same merchant/location updates its token and keeps its original test invoice.</p>
<button class="btn btn-primary" type="submit">Verify &amp; save Square connection</button>
</form></details>
<?php foreach ($squareConnections as $connection): $test = $square->test($resellerId, $connection['id']); ?>
<details class="am-package"><summary><span><strong><?= e($connection['business_name']) ?></strong><small><?= $connection['mode'] === 'live' ? 'Production / real money' : 'Sandbox / test money' ?> · <?= e($connection['currency']) ?></small></span><span><?= $connection['webhook_saved'] ? 'Webhook saved' : 'Webhook needed' ?></span></summary><section class="am-price">
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
</section></details>
<?php endforeach; endif; ?>
</section>
</main>
<script>
(() => {
    const sections = [...document.querySelectorAll('[data-am-section]')];
    const links = [...document.querySelectorAll('[data-am-link]')];
    function showSection() {
        const requested = location.hash.slice(1);
        const id = sections.some(section => section.id === requested) ? requested : <?= json_encode($initialSection) ?>;
        sections.forEach(section => { section.hidden = section.id !== id; });
        links.forEach(link => { if (link.dataset.amLink === id) link.setAttribute('aria-current','page'); else link.removeAttribute('aria-current'); });
    }
    window.addEventListener('hashchange', showSection);
    showSection();
})();
</script></body></html>
