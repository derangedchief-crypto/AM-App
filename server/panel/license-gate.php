<?php
// Included after reseller identity verification, before any AM management action.
if (!isset($resellerId)) { http_response_code(404); exit; }
require_once __DIR__.'/Licenses.php';
$licenseError=null; $licenseRow=null;
try {
    $licenseConfig=require __DIR__.'/settings-config.php';
    $licensing=new \AmAccount\Licenses($licenseConfig['database']);
    if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && ($_POST['action'] ?? '')==='license_activate') {
        csrf_check();
        if (!is_string($_POST['license_key'] ?? null)) throw new InvalidArgumentException('Enter your license key.');
        $licensing->redeem($resellerId,$_POST['license_key']);
        header('Location: '.url('am-app/'),true,303); exit;
    }
    $licenseRow=$licensing->current($resellerId);
    if (\AmAccount\Licenses::state($licenseRow)==='active') return;
} catch (InvalidArgumentException $e) { $licenseError=$e->getMessage(); }
catch (Throwable $e) { http_response_code(503); $licenseError='License verification is unavailable. Please try again later.'; }
if (http_response_code()!==503) http_response_code(403);
?>
<!doctype html><html lang="en"<?= theme_html_attrs() ?>><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Activate AM App</title><link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>"><?= theme_head() ?><style>main{max-width:580px;margin:50px auto;padding:24px;color:var(--text)}.am-license{padding:24px;border:1px solid var(--border);border-radius:16px;background:var(--card)}input{box-sizing:border-box;width:100%;padding:12px;margin:12px 0;background:var(--muted-bg,var(--card));color:var(--text);border:1px solid var(--border);border-radius:8px}p{line-height:1.6}.am-error{color:var(--text);padding:12px;border:1px solid var(--border)}</style></head><body><main><div class="am-license"><h1>AM App access</h1><p>Reseller #<?= e($resellerId) ?> · License: <strong><?= e(\AmAccount\Licenses::state($licenseRow)) ?></strong></p>
<p>AM requires a license issued by the service administrator. A key activates this reseller account once. One-time licenses have no expiry unless the administrator explicitly changes the license terms.</p>
<?php if ($licenseError): ?><p class="am-error" role="alert"><?= e($licenseError) ?></p><?php endif; ?>
<?php if (!$licenseRow || \AmAccount\Licenses::state($licenseRow)==='pending'): ?><form method="post" action="<?= e(url('am-app/')) ?>" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="license_activate"><label for="license-key">License key</label><input id="license-key" type="password" name="license_key" required maxlength="43" autocomplete="new-password"><button class="btn btn-primary" type="submit">Activate license</button></form>
<?php else: ?><p>Contact the administrator to restore, renew or replace your license. Entering another reseller's key cannot bypass this restriction.</p><?php endif; ?>
<p><a href="<?= e(url('dashboard.php')) ?>">Back to reseller panel</a></p>
<?php if (function_exists('is_local_admin') && is_local_admin()): ?><p><a href="<?= e(url('am-app/licenses-admin.php')) ?>">Manage AM licenses</a></p><?php endif; ?></div></main></body></html>
<?php exit;
