<?php
declare(strict_types=1);
header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow'); header('Referrer-Policy: same-origin');
require_once dirname(__DIR__).'/includes/bootstrap.php';
if (!function_exists('require_local_admin') || !function_exists('is_local_admin') || !empty($_SESSION['portal_staff']) || !empty($_SESSION['impersonation'])) { http_response_code(403); exit('Administrator access required.'); }
require_local_admin();
if (!is_local_admin()) { http_response_code(403); exit('Administrator access required.'); }
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '',['GET','HEAD','POST'],true)) { http_response_code(405); exit; }
require_once __DIR__.'/Announcements.php';
$error=null; $rows=[];
$field=static function(string $key): string { if (!is_string($_POST[$key] ?? null)) throw new InvalidArgumentException('Invalid field.'); return trim($_POST[$key]); };
try {
    $config=require __DIR__.'/settings-config.php'; $store=new \AmAccount\Announcements($config['database']);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        csrf_check(); $action=$field('action');
        if (!in_array($action,['save','withdraw'],true)) throw new InvalidArgumentException('Invalid action.');
        $expires=0;
        if ($action==='save') {
            $value=$field('expires'); $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d\TH:i')!==$value) throw new InvalidArgumentException('Enter a valid UTC expiry.');
            $expires=$date->getTimestamp();
        }
        $revision=$field('revision');
        if (!preg_match('/^[0-9]{1,9}$/D',$revision)) throw new InvalidArgumentException('Invalid revision.');
        $store->save($field('id'),(int)$revision,$action==='save'?$field('title'):'',$action==='save'?$field('body'):'',$action==='save'?$field('severity'):'',$expires,$action==='withdraw');
        header('Location: '.url('am-app/announcements-admin.php'),true,303); exit;
    }
    $rows=$store->recent();
} catch (InvalidArgumentException $e) { http_response_code(422); $error=$e->getMessage(); }
catch (Throwable $e) { http_response_code(503); $error='Announcement storage unavailable. Reload to verify before retrying.'; }
$form=static function(array $row=[]): void { ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= e($row['id'] ?? '') ?>"><input type="hidden" name="revision" value="<?= (int)($row['revision'] ?? 0) ?>">
<label>Title<input name="title" maxlength="160" required value="<?= e($row['title'] ?? '') ?>"></label>
<label>Message<textarea name="body" maxlength="2000" rows="5" required><?= e($row['body'] ?? '') ?></textarea></label>
<label>Severity<select name="severity"><?php foreach (['info','warning','critical'] as $severity): ?><option <?= ($row['severity'] ?? 'info')===$severity?'selected':'' ?>><?= $severity ?></option><?php endforeach; ?></select></label>
<label>Expiry · UTC (within 90 days)<input type="datetime-local" name="expires" required value="<?= e(gmdate('Y-m-d\TH:i',(int)($row['expires'] ?? time()+86400))) ?>"></label>
<button class="btn btn-primary"><?= $row?'Publish updated revision':'Publish to all customers' ?></button></form>
<?php }; ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>My TV · Global announcements</title><link rel="icon" href="my-tv-symbol.svg"><?php require __DIR__.'/management-style.php'; ?><style>main{max-width:900px;margin:auto;padding:24px 16px}section,details{background:var(--card);padding:20px;border-radius:14px;margin:16px 0}label{display:block;margin:14px 0}input:not([type=hidden]),textarea,select{display:block;width:100%;box-sizing:border-box;padding:12px;background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:8px}summary{cursor:pointer}p{white-space:pre-wrap;overflow-wrap:anywhere}</style></head><body class="am-admin-page"><main><a href="<?= e(url('admin.php')) ?>">Admin panel</a> · <a href="licenses-admin.php">My TV licenses</a><h1>Global announcements</h1><p>Public notices for every My TV customer, including before sign-in. Checks run about every minute while open and every 15 minutes in the background; Android may delay delivery. Updates alert customers again. Withdrawals disappear on their next successful check. Do not include private account information.</p>
<?php if ($error): ?><section role="alert"><?= e($error) ?></section><?php endif; ?>
<section><h2>New announcement</h2><?php $form(); ?></section><h2>Recent announcements</h2>
<?php foreach ($rows as $row): $active=!(int)$row['withdrawn'] && (int)$row['expires']>time(); ?><details><summary><?= e($row['title']) ?> · <?= $active?'Active':((int)$row['withdrawn']?'Withdrawn':'Expired') ?> · revision <?= (int)$row['revision'] ?></summary><?php if ($active): $form($row); ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="withdraw"><input type="hidden" name="id" value="<?= e($row['id']) ?>"><input type="hidden" name="revision" value="<?= (int)$row['revision'] ?>"><button class="btn">Withdraw announcement</button></form><?php else: ?><p><?= e($row['body']) ?></p><?php endif; ?></details><?php endforeach; ?>
</main></body></html>
