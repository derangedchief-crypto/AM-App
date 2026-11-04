<?php
declare(strict_types=1);
header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow, noarchive'); header('Referrer-Policy: same-origin');
require_once dirname(__DIR__).'/includes/bootstrap.php';
if (!function_exists('require_local_admin') || !function_exists('is_local_admin') || !empty($_SESSION['portal_staff']) || !empty($_SESSION['impersonation'])) { http_response_code(403); exit('Administrator access required.'); }
require_local_admin();
if (!is_local_admin()) { http_response_code(403); exit('Administrator access required.'); }
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '',['GET','POST','HEAD'],true)) { http_response_code(405); exit; }
require_once __DIR__.'/Licenses.php';
$error=null; $notice=null; $rows=[]; $audit=[]; $count=0;
$search=is_string($_GET['reseller'] ?? null)?trim($_GET['reseller']):'';
if ($search!=='' && !preg_match('/^[1-9][0-9]{0,17}$/D',$search)) $search='';
$page=max(1,min(100000,(int)(is_string($_GET['page'] ?? null)?$_GET['page']:1)));
$field=static function(string $name): string {
    if (!is_string($_POST[$name] ?? null)) throw new InvalidArgumentException('Invalid license field.');
    return trim($_POST[$name]);
};
try {
    $config=require __DIR__.'/settings-config.php'; $licenses=new \AmAccount\Licenses($config['database']);
    if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST') {
        csrf_check(); $action=$field('action');
        if ($action==='issue') {
            $resellerId=$field('reseller_id');
            if (!preg_match('/^[1-9][0-9]{0,17}$/D',$resellerId)) throw new InvalidArgumentException('Enter a reseller ID.');
            $identity=call_auth_worker('/panel-user-balance',['panel_user_id'=>$resellerId]);
            if (($identity['ok'] ?? false)!==true || ($identity['is_enabled'] ?? false)!==true) throw new InvalidArgumentException('An active reseller account could not be verified.');
            $issued=$licenses->issue($resellerId,\AmAccount\Licenses::expiry($field('expires')),($_POST['replace'] ?? '')==='1');
            $_SESSION['am_issued_license']=['reseller'=>$resellerId,'key'=>$issued['key']];
        } elseif ($action==='retry_order') {
            foreach (['Settings','Pricing','Square','Customer'] as $file) require_once __DIR__.'/'.$file.'.php';
            $resellerId=$field('reseller_id'); $order=$field('order_id');
            if (!preg_match('/^[1-9][0-9]{0,17}$/D',$resellerId) || !preg_match('/^[a-f0-9]{32}$/D',$order)) throw new InvalidArgumentException('Invalid original order reference.');
            $result=(new \AmAccount\CustomerService($config,static fn($path,$body)=>call_auth_worker($path,$body),new \AmAccount\Square()))->retryOrder($resellerId,$order);
            $_SESSION['am_license_notice']='Original order '.$result['id'].': '.$result['message'];
        } else {
            $id=$field('license_id'); $revision=$field('revision');
            if (!preg_match('/^[a-f0-9]{32}$/D',$id) || !preg_match('/^[0-9]{1,9}$/D',$revision)) throw new InvalidArgumentException('Invalid license revision.');
            $licenses->change($id,(int)$revision,$action,$action==='expiry'?\AmAccount\Licenses::expiry($field('expires')):null);
            $_SESSION['am_license_notice']='License updated. Access is checked on every AM request.';
        }
        header('Location: '.url('am-app/licenses-admin.php'.($search!==''?'?reseller='.$search:'')),true,303); exit;
    }
    $count=$licenses->count($search); $page=min($page,max(1,(int)ceil($count/25)));
    $rows=$licenses->rows($search,$page); $audit=$licenses->auditRows();
} catch (InvalidArgumentException $e) { http_response_code(422); $error=$e->getMessage(); }
catch (Throwable $e) { http_response_code(503); $error='Licensing storage or service verification is unavailable. No key is displayed; reload to verify the operation before retrying.'; }
$issued=$_SESSION['am_issued_license'] ?? null; unset($_SESSION['am_issued_license']);
$notice=$_SESSION['am_license_notice'] ?? null; unset($_SESSION['am_license_notice']);
?>
<!doctype html><html lang="en"<?= theme_html_attrs() ?>><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AM licenses · Admin</title><link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>"><style>
main{max-width:1100px;margin:auto;padding:28px 18px;color:var(--text)}.am-box{padding:22px;margin:18px 0;background:var(--card);border:1px solid var(--border);border-radius:14px}.am-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}label{display:block;margin:12px 0}input:not([type=checkbox]){width:100%;box-sizing:border-box;min-height:44px;padding:10px;background:var(--muted-bg,var(--card));color:var(--text);border:1px solid var(--border);border-radius:8px}p,small{line-height:1.6}summary{cursor:pointer;padding:14px}details{border:1px solid var(--border);border-radius:10px;margin:12px 0}.actions{display:flex;gap:10px;flex-wrap:wrap}.key{font-family:monospace;overflow-wrap:anywhere;padding:16px;border:2px solid var(--accent,#8b9fff);border-radius:8px}table{width:100%;border-collapse:collapse}td,th{padding:10px;text-align:left;border-bottom:1px solid var(--border)}@media(max-width:650px){.am-grid{grid-template-columns:1fr}}button{min-height:44px}
</style></head><body><main><a href="<?= e(url('admin.php')) ?>">← Admin panel</a><h1>AM App licenses</h1><p>License keys unlock AM management and customer app access. IPTV service is unaffected. Suspended, revoked and expired licenses block new AM requests; existing payments continue reconciliation.</p>
<?php if ($error): ?><div class="am-box" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="am-box" role="status"><?= e($notice) ?></div><?php endif; ?>
<?php if ($issued): ?><section class="am-box"><h2>New key · reseller #<?= e($issued['reseller']) ?></h2><p>Copy this key now and give it privately to this reseller. The full key is shown once.</p><div class="key"><?= e($issued['key']) ?></div></section><?php endif; ?>
<section class="am-box"><h2>Generate a license</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="issue"><div class="am-grid"><label>Reseller ID<input type="text" name="reseller_id" inputmode="numeric" pattern="[1-9][0-9]{0,17}" required></label><label>Expiry · UTC (blank = one-time / no expiry)<input type="datetime-local" name="expires"></label></div><label><input type="checkbox" name="replace" value="1"> Replace any existing license for this reseller. This immediately revokes the old license and requires the new key to be activated.</label><button class="btn btn-primary" type="submit">Generate one-use key</button></form></section>
<section class="am-box"><h2>Licenses</h2><form method="get"><label>Find reseller ID<input type="text" name="reseller" inputmode="numeric" value="<?= e($search) ?>"></label><button class="btn" type="submit">Find licenses</button> <a href="<?= e(url('am-app/licenses-admin.php')) ?>">Show all</a></form>
<?php foreach ($rows as $row): ?><details><summary><strong>Reseller #<?= e($row['reseller_id']) ?></strong> · <?= e(\AmAccount\Licenses::state($row)) ?><?= (int)$row['current']===0?' · replaced':'' ?> · <?= $row['expires']===null?'No expiry':e(gmdate('Y-m-d H:i',(int)$row['expires'])).' UTC' ?></summary><div style="padding:16px"><p>License <?= e($row['id']) ?> · key ending <?= e($row['key_hint']) ?> · <?= $row['activated']!==null?'Activated '.e(gmdate('Y-m-d H:i',(int)$row['activated'])).' UTC':'Not activated' ?></p>
<?php if ((int)$row['current']===1 && $row['status']!=='revoked'): ?><div class="actions"><?php foreach ([$row['status']==='suspended'?'restore':'suspend','revoke'] as $action): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $action ?>"><input type="hidden" name="license_id" value="<?= e($row['id']) ?>"><input type="hidden" name="revision" value="<?= $row['revision'] ?>"><?php if ($action==='revoke'): ?><label><input type="checkbox" required> Permanently revoke this key</label><?php endif; ?><button class="btn" type="submit"><?= ucfirst($action) ?></button></form><?php endforeach; ?></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="expiry"><input type="hidden" name="license_id" value="<?= e($row['id']) ?>"><input type="hidden" name="revision" value="<?= $row['revision'] ?>"><label>Set or extend expiry · UTC<input type="datetime-local" name="expires" value="<?= $row['expires']!==null?e(gmdate('Y-m-d\TH:i',(int)$row['expires'])):'' ?>"></label><p>Set a future date to convert this license to time-limited access, including an existing one-time license. Blank removes expiry. This does not charge anyone or set up recurring billing; monthly payments and extensions are manual.</p><button class="btn" type="submit">Save expiry</button></form>
<?php endif; ?></div></details><?php endforeach; ?>
<?php if (!$rows): ?><p>No licenses found.</p><?php endif; ?><p>Page <?= $page ?> of <?= max(1,(int)ceil($count/25)) ?> · <?= $count ?> licenses</p><div class="actions"><?php if ($page>1): ?><a href="?<?= e(http_build_query(['reseller'=>$search,'page'=>$page-1])) ?>">Previous</a><?php endif; ?><?php if ($page*25<$count): ?><a href="?<?= e(http_build_query(['reseller'=>$search,'page'=>$page+1])) ?>">Next</a><?php endif; ?></div></section>
<details class="am-box"><summary>Reconcile an existing customer order</summary><p>For payments begun before suspension or expiry. This retries the original order, never a new purchase. Signed payment webhooks continue doing this automatically.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="retry_order"><label>Reseller ID<input type="text" name="reseller_id" inputmode="numeric" required></label><label>Original AM order ID<input type="text" name="order_id" pattern="[a-f0-9]{32}" required></label><button class="btn" type="submit">Reconcile original order</button></form></details>
<details class="am-box"><summary>Recent license activity</summary><div style="overflow-x:auto"><table><thead><tr><th>UTC</th><th>Reseller</th><th>Actor</th><th>Action</th><th>Detail</th></tr></thead><tbody><?php foreach ($audit as $event): ?><tr><td><?= e(gmdate('Y-m-d H:i',(int)$event['created'])) ?></td><td><?= e($event['reseller_id']) ?></td><td><?= e($event['actor']) ?></td><td><?= e($event['action']) ?></td><td><?= e($event['detail']) ?></td></tr><?php endforeach; ?></tbody></table></div></details>
</main></body></html>
