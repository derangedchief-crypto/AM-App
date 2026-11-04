<?php
declare(strict_types=1);
require __DIR__.'/../panel/Licenses.php';
use AmAccount\Licenses;
function expect(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejected(Closure $fn): void { try { $fn(); } catch (InvalidArgumentException | AmAccount\LicenseDenied $e) { return; } throw new RuntimeException('Expected license denial'); }
$path='/tmp/opencode/am-license-'.bin2hex(random_bytes(6)).'.sqlite'; touch($path);
try {
    Licenses::migrate($path); Licenses::migrate($path); $store=new Licenses($path); $db=new PDO('sqlite:'.$path);
    rejected(fn()=>$store->requireActive('1'));
    $key=$store->issue('1',null); expect(Licenses::state($store->current('1'))==='pending','Issued keys require activation');
    rejected(fn()=>$store->redeem('2',$key['key']));
    rejected(fn()=>$store->requireActive('1'));
    $store->redeem('1',$key['key']); $store->requireActive('1');
    rejected(fn()=>$store->redeem('1',$key['key']));
    expect(!str_contains(file_get_contents($path),$key['key']),'Only key hash is stored');
    $row=$store->current('1'); $store->change($row['id'],(int)$row['revision'],'suspend');
    rejected(fn()=>$store->requireActive('1'));
    rejected(fn()=>$store->redeem('1',$key['key']));
    rejected(fn()=>$store->change($row['id'],(int)$row['revision'],'restore'));
    $row=$store->current('1'); $store->change($row['id'],(int)$row['revision'],'restore'); $store->requireActive('1');
    $row=$store->current('1'); $store->change($row['id'],(int)$row['revision'],'expiry',time()+3600);
    $store->requireActive('1'); expect($store->current('1')['expires']!==null,'Lifetime license can become expiring');
    $db->exec('UPDATE am_licenses SET expires=1'); rejected(fn()=>$store->requireActive('1'));
    $row=$store->current('1'); $store->change($row['id'],(int)$row['revision'],'expiry',time()+86400); $store->requireActive('1');
    $row=$store->current('1'); $store->change($row['id'],(int)$row['revision'],'revoke');
    rejected(fn()=>$store->requireActive('1'));
    $row=$store->current('1'); rejected(fn()=>$store->change($row['id'],(int)$row['revision'],'restore'));
    rejected(fn()=>$store->issue('1',null));
    $replacement=$store->issue('1',null,true); rejected(fn()=>$store->redeem('1',$key['key']));
    $store->redeem('1',$replacement['key']); $store->requireActive('1');
    expect($store->count('1')===2 && (int)$db->query('SELECT COUNT(*) FROM am_licenses WHERE current=1')->fetchColumn()===1,'Replacement keeps history and one entitlement');
    $scheduled=$store->issue('3',time()+3600); $row=$store->current('3'); $store->change($row['id'],1,'suspend');
    rejected(fn()=>$store->redeem('3',$scheduled['key']));
    $store->change($row['id'],2,'restore'); expect(Licenses::state($store->current('3'))==='pending','Restoring unredeemed key does not grant access');
    for ($i=0;$i<10;$i++) rejected(fn()=>$store->redeem('4','bad'));
    $limited=$store->issue('4',null); rejected(fn()=>$store->redeem('4',$limited['key']));
    expect(count($store->auditRows())>=10,'License mutations are audited');
    echo "PASS: one-use reseller binding, hashed keys, suspension/restoration, expiry conversion, permanent revocation, replacement, revisions, rate limiting and audit.\n";
} finally { @unlink($path); }
