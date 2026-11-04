<?php
declare(strict_types=1);
header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow, noarchive'); header('Referrer-Policy: same-origin');
require_once dirname(__DIR__).'/includes/bootstrap.php';
if (!function_exists('require_local_admin') || !function_exists('is_local_admin') || !empty($_SESSION['portal_staff']) || !empty($_SESSION['impersonation'])) { http_response_code(403); exit('Administrator access required.'); }
require_local_admin();
if (!is_local_admin()) { http_response_code(403); exit('Administrator access required.'); }
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '',['GET','POST','HEAD'],true)) { http_response_code(405); exit; }
require_once __DIR__.'/Licenses.php';
$directory=static function(array $body): array {
    $result=call_auth_worker('/am/admin-resellers',$body);
    if (($result['ok'] ?? false)!==true || !is_array($result['resellers'] ?? null)) throw new RuntimeException('Reseller directory unavailable.');
    return $result;
};
if (isset($_GET['reseller_search'])) {
    header('Content-Type: application/json');
    $query=$_GET['reseller_search'];
    if (!is_string($query) || strlen($query)>100) { http_response_code(422); echo '{"error":"Search is too long."}'; exit; }
    try { echo json_encode($directory(['query'=>$query]),JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { http_response_code(503); echo '{"error":"Reseller list unavailable. Update the Auth-worker and try again."}'; }
    exit;
}
$resellers=[]; $names=[]; $directoryError=null; $more=false;
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
            if (!preg_match('/^[1-9][0-9]{0,17}$/D',$resellerId)) throw new InvalidArgumentException('Choose a reseller.');
            $identity=$directory(['reseller_ids'=>[$resellerId]])['resellers'][0] ?? null;
            if (!$identity || $identity['id']!==$resellerId || $identity['enabled']!==true) throw new InvalidArgumentException('An active reseller account could not be verified.');
            $issued=$licenses->issue($resellerId,\AmAccount\Licenses::expiry($field('expires')),($_POST['replace'] ?? '')==='1');
            $_SESSION['am_issued_license']=['reseller'=>$identity['username'],'key'=>$issued['key']];
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
try {
    $result=$directory(['query'=>'']); $resellers=$result['resellers']; $more=!empty($result['more']);
    $ids=array_values(array_unique(array_merge(array_column($rows,'reseller_id'),array_column($audit,'reseller_id'),$search!==''?[$search]:[])));
    foreach (array_merge($resellers,$ids?$directory(['reseller_ids'=>$ids])['resellers']:[]) as $r) $names[$r['id']]=$r['username'];
} catch (Throwable $e) { $directoryError='Reseller list unavailable. Install the Auth-worker update, then reload this page.'; }
$picker=static function(string $name,string $selected='',bool $required=true) use ($resellers,$names,$more,$directoryError): void { ?>
<div class="reseller-picker"><label>Search resellers<input type="search" class="reseller-search" placeholder="Type a reseller name…" autocomplete="off"></label><label>Reseller<select name="<?= e($name) ?>" <?= $required?'required':'' ?>><option value=""><?= $required?'Choose a reseller':'All resellers' ?></option><?php if ($selected!=='' && !in_array($selected,array_column($resellers,'id'),true)): ?><option value="<?= e($selected) ?>" selected><?= e($names[$selected] ?? 'Unavailable reseller') ?></option><?php endif; ?><?php foreach ($resellers as $r): ?><option value="<?= e($r['id']) ?>" <?= $selected===$r['id']?'selected':'' ?>><?= e($r['username'].(!$r['enabled']?' (disabled)':'')) ?></option><?php endforeach; ?></select></label><small role="status"><?= e($directoryError ?? ($more?'Showing the first 200 resellers. Search to find others.':'Choose a reseller by name.')) ?></small></div>
<?php };
?>
<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark"><title>My TV · License administration</title><style>
main{max-width:1100px;margin:auto;padding:28px 18px;color:var(--text)}.am-box{padding:22px;margin:18px 0;background:var(--card);border:1px solid var(--border);border-radius:14px}.am-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}label{display:block;margin:12px 0}input:not([type=checkbox]){width:100%;box-sizing:border-box;min-height:44px;padding:10px;background:var(--muted-bg,var(--card));color:var(--text);border:1px solid var(--border);border-radius:8px}p,small{line-height:1.6}summary{cursor:pointer;padding:14px}details{border:1px solid var(--border);border-radius:10px;margin:12px 0}.actions{display:flex;gap:10px;flex-wrap:wrap}.key{font-family:monospace;overflow-wrap:anywhere;padding:16px;border:2px solid var(--accent,#8b9fff);border-radius:8px}table{width:100%;border-collapse:collapse}td,th{padding:10px;text-align:left;border-bottom:1px solid var(--border)}@media(max-width:650px){.am-grid{grid-template-columns:1fr}}button{min-height:44px}
</style><?php require __DIR__.'/management-style.php'; ?></head><body class="am-admin-page"><main><header class="am-top"><a class="am-brand" href="<?= e(url('am-app/')) ?>"><span class="am-logo">AM</span><span><strong>My TV</strong><small>License administration</small></span></a><a class="btn" href="<?= e(url('admin.php')) ?>">Admin panel ↗</a></header><span class="am-eyebrow">Administrator</span><h1>License management</h1><p>Issue keys and manage reseller access.</p>
<?php if ($error): ?><div class="am-box" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="am-box" role="status"><?= e($notice) ?></div><?php endif; ?>
<?php if ($issued): ?><section class="am-box"><h2>New key · <?= e($issued['reseller']) ?></h2><p>Copy and share privately. This key is shown once.</p><div class="key"><?= e($issued['key']) ?></div></section><?php endif; ?>
<section class="am-box"><h2>Generate a license</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="issue"><div class="am-grid"><?php $picker('reseller_id'); ?><label>Expiry · UTC (blank = one-time / no expiry)<input type="datetime-local" name="expires"></label></div><label><input type="checkbox" name="replace" value="1"> Replace any existing license for this reseller. This immediately revokes the old license and requires the new key to be activated.</label><button class="btn btn-primary" type="submit">Generate one-use key</button></form></section>
<section class="am-box"><h2>Licenses</h2><form method="get"><?php $picker('reseller',$search,false); ?><button class="btn" type="submit">Find licenses</button> <a href="<?= e(url('am-app/licenses-admin.php')) ?>">Show all</a></form>
<?php foreach ($rows as $row): ?><details><summary><strong><?= e($names[$row['reseller_id']] ?? 'Unavailable reseller #'.$row['reseller_id']) ?></strong> · <?= e(\AmAccount\Licenses::state($row)) ?><?= (int)$row['current']===0?' · replaced':'' ?> · <?= $row['expires']===null?'No expiry':e(gmdate('Y-m-d H:i',(int)$row['expires'])).' UTC' ?></summary><div style="padding:16px"><p>License <?= e($row['id']) ?> · key ending <?= e($row['key_hint']) ?> · <?= $row['activated']!==null?'Activated '.e(gmdate('Y-m-d H:i',(int)$row['activated'])).' UTC':'Not activated' ?></p>
<?php if ((int)$row['current']===1 && $row['status']!=='revoked'): ?><div class="actions"><?php foreach ([$row['status']==='suspended'?'restore':'suspend','revoke'] as $action): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $action ?>"><input type="hidden" name="license_id" value="<?= e($row['id']) ?>"><input type="hidden" name="revision" value="<?= $row['revision'] ?>"><?php if ($action==='revoke'): ?><label><input type="checkbox" required> Permanently revoke this key</label><?php endif; ?><button class="btn" type="submit"><?= ucfirst($action) ?></button></form><?php endforeach; ?></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="expiry"><input type="hidden" name="license_id" value="<?= e($row['id']) ?>"><input type="hidden" name="revision" value="<?= $row['revision'] ?>"><label>Set or extend expiry · UTC<input type="datetime-local" name="expires" value="<?= $row['expires']!==null?e(gmdate('Y-m-d\TH:i',(int)$row['expires'])):'' ?>"></label><p>Set a future date to convert this license to time-limited access, including an existing one-time license. Blank removes expiry. This does not charge anyone or set up recurring billing; monthly payments and extensions are manual.</p><button class="btn" type="submit">Save expiry</button></form>
<?php endif; ?></div></details><?php endforeach; ?>
<?php if (!$rows): ?><p>No licenses found.</p><?php endif; ?><p>Page <?= $page ?> of <?= max(1,(int)ceil($count/25)) ?> · <?= $count ?> licenses</p><div class="actions"><?php if ($page>1): ?><a href="?<?= e(http_build_query(['reseller'=>$search,'page'=>$page-1])) ?>">Previous</a><?php endif; ?><?php if ($page*25<$count): ?><a href="?<?= e(http_build_query(['reseller'=>$search,'page'=>$page+1])) ?>">Next</a><?php endif; ?></div></section>
<details class="am-box"><summary>Reconcile an existing customer order</summary><p>For payments begun before suspension or expiry. This retries the original order, never a new purchase. Signed payment webhooks continue doing this automatically.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="retry_order"><?php $picker('reseller_id'); ?><label>Original AM order ID<input type="text" name="order_id" pattern="[a-f0-9]{32}" required></label><button class="btn" type="submit">Reconcile original order</button></form></details>
<details class="am-box"><summary>Recent license activity</summary><div style="overflow-x:auto"><table><thead><tr><th>UTC</th><th>Reseller</th><th>Actor</th><th>Action</th><th>Detail</th></tr></thead><tbody><?php foreach ($audit as $event): ?><tr><td><?= e(gmdate('Y-m-d H:i',(int)$event['created'])) ?></td><td><?= e($event['reseller_id']) ?></td><td><?= e($event['actor']) ?></td><td><?= e($event['action']) ?></td><td><?= e($event['detail']) ?></td></tr><?php endforeach; ?></tbody></table></div></details>
</main><style>.reseller-picker select{width:100%;max-width:100%;min-height:44px;padding:10px;background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:8px}.reseller-picker{min-width:0}</style><script>
document.querySelectorAll('.reseller-picker').forEach(picker=>{
    const input=picker.querySelector('input'), select=picker.querySelector('select'), status=picker.querySelector('[role=status]');
    let timer,version=0;
    input.addEventListener('input',()=>{
        clearTimeout(timer); const current=++version;
        select.value=''; select.disabled=true; status.textContent='Searching…';
        timer=setTimeout(async()=>{
            try {
                const url=new URL(location.href); url.search=''; url.searchParams.set('reseller_search',input.value);
                const response=await fetch(url,{headers:{Accept:'application/json'}});
                if(!response.ok) throw new Error();
                const data=await response.json(); if(current!==version)return;
                select.replaceChildren(new Option(select.required?'Choose a reseller':'All resellers',''));
                data.resellers.forEach(r=>select.add(new Option(r.username+(r.enabled?'':' (disabled)'),r.id)));
                select.disabled=false;
                status.textContent=data.more?'Showing the first 200 matches. Keep typing to narrow the list.':data.resellers.length+' resellers found.';
            } catch(error) { if(current===version)status.textContent='Search unavailable. Try typing again or reload the page.'; }
        },250);
    });
    picker.closest('form').addEventListener('submit',event=>{if(select.disabled){event.preventDefault();status.textContent='Wait for the reseller search to finish, then select a reseller.';}});
});
</script></body></html>
