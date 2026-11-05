<?php
// Included after reseller identity verification, before any AM management action.
if (!isset($resellerId)) { http_response_code(404); exit; }
require_once __DIR__.'/Licenses.php';
$licenseError=null; $licenseRow=null;
try {
    $licenseConfig=require __DIR__.'/settings-config.php';
    $licensing=new \AmAccount\Licenses($licenseConfig['database']);
    $licenseRow=$licensing->current($resellerId);
    if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && ($_POST['action'] ?? '')==='license_activate') {
        csrf_check();
        if (!is_string($_POST['license_key'] ?? null)) throw new InvalidArgumentException('Enter your license key.');
        $licensing->redeem($resellerId,$_POST['license_key']);
        header('Location: '.url('am-app/'),true,303); exit;
    }
    if (\AmAccount\Licenses::state($licenseRow)==='active') return;
} catch (InvalidArgumentException $e) { $licenseError=$e->getMessage(); }
catch (Throwable $e) { http_response_code(503); $licenseError='License verification is unavailable. Please try again later.'; }
if (http_response_code()!==503) http_response_code(403);
$licenseState=http_response_code()===503?'unavailable':\AmAccount\Licenses::state($licenseRow);
?>
<!doctype html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark"><title>My TV · Activation</title><link rel="icon" type="image/svg+xml" href="my-tv-symbol.svg">
<style>
:root{color-scheme:dark;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#0b1017;color:#f3f6fa}
*{box-sizing:border-box}body{margin:0;min-height:100svh;background:radial-gradient(ellipse at 50% 0%,#1b2b30 0%,transparent 65%)}
main{min-height:100svh;display:grid;grid-template-columns:minmax(0,440px);place-content:center;padding:40px 20px}.am-shell{width:100%;margin:auto}
.am-back{display:inline-flex;align-items:center;gap:8px;margin:0 0 24px;color:#9aaabd;font-size:.85rem;text-decoration:none}.am-back:hover{color:#f3f6fa}
.am-license{padding:36px;background:#151d28;border:1px solid #273341;border-radius:24px;box-shadow:0 24px 70px #0004}
.am-brand{display:flex;align-items:center;gap:14px;margin-bottom:30px}.am-logo{display:grid;place-items:center;width:52px;height:52px;flex-shrink:0;border-radius:15px;background:#d5f58a;color:#0b1017;font-size:1.35rem;font-weight:800;letter-spacing:-1px}
h1{font-size:1.6rem;letter-spacing:-.04em;line-height:1.2;margin:0}p{line-height:1.5}.am-caption{font-size:.8rem;color:#9aaabd;margin:5px 0 0}
.am-status{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 0;border-top:1px solid #273341;border-bottom:1px solid #273341;margin-bottom:28px;font-size:.85rem;color:#9aaabd}
.am-badge{display:inline-flex;align-items:center;gap:7px;padding:6px 10px;border-radius:99px;background:#ffd69a12;color:#ffd69a;font-size:.75rem;font-weight:600;text-transform:capitalize}.am-badge::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
label{display:block;font-size:.85rem;font-weight:600;margin-bottom:10px}input{display:block;width:100%;min-width:0;min-height:52px;padding:14px 15px;border:1px solid #384657;border-radius:12px;background:#0b1017;color:#f3f6fa;font:inherit;font-size:1rem}input::placeholder{color:#738397}
button{width:100%;min-height:50px;border:0;border-radius:12px;margin-top:16px;padding:14px;background:#d5f58a;color:#0b1017;font:inherit;font-size:.9rem;font-weight:700;cursor:pointer}button:hover{background:#e0ffa1}button:active{transform:translateY(1px)}
a:focus-visible,button:focus-visible,input:focus-visible{outline:2px solid #d5f58a;outline-offset:4px}.am-error{font-size:.85rem;color:#ffceca;background:#ff8b8010;border:1px solid #ff8b8030;border-radius:12px;padding:12px;margin:0 0 20px;overflow-wrap:anywhere}
.am-help{font-size:.85rem;color:#9aaabd;margin:0}.am-admin{display:block;width:fit-content;margin:22px auto 0;font-size:.8rem;text-decoration:none;color:#9aaabd}.am-admin:hover{color:#d5f58a}
@media(max-width:480px){main{padding:24px 16px}.am-license{padding:26px 22px;border-radius:20px}.am-shell{width:100%}}
</style></head><body><main><div class="am-shell">
<a class="am-back" href="<?= e(url('dashboard.php')) ?>"><span aria-hidden="true">←</span> Back to reseller panel</a>
<section class="am-license" aria-labelledby="activation-title">
<div class="am-brand"><img class="am-logo" src="my-tv-symbol.svg" width="52" height="52" alt="" style="background:transparent;border-radius:0"><div><h1 id="activation-title">My <span style="color:#d5f58a">TV</span></h1><p class="am-caption">Reseller access</p></div></div>
<div class="am-status"><span>License status</span><span class="am-badge"><?= e($licenseState==='pending'?'Not activated':$licenseState) ?></span></div>
<?php if ($licenseError): ?><p class="am-error" role="alert"><?= e($licenseError) ?></p><?php endif; ?>
<?php if (in_array($licenseState,['unlicensed','pending'],true)): ?>
<form method="post" action="<?= e(url('am-app/')) ?>" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="license_activate"><label for="license-key">License key</label><input id="license-key" type="password" name="license_key" placeholder="Enter your license key" required maxlength="43" autocomplete="new-password" spellcheck="false" autocapitalize="none"><button type="submit">Activate license</button></form>
<?php elseif ($licenseState!=='unavailable'): ?><p class="am-help">Contact your administrator to restore access.</p><?php endif; ?>
</section>
<?php if (function_exists('is_local_admin') && is_local_admin()): ?><a class="am-admin" href="<?= e(url('am-app/licenses-admin.php')) ?>">Manage licenses</a><?php endif; ?>
</div></main></body></html>
<?php exit;
