<?php
declare(strict_types=1);
foreach (['Settings','Pricing','Square','Customer'] as $name) require __DIR__.'/../panel/'.$name.'.php';
use AmAccount\{Settings,SettingsStore,PricingStore,Square,SquareStore,CustomerService,CustomerError};
function check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function denied(Closure $action,int $status): void {
    try { $action(); } catch (CustomerError $e) { check($e->status===$status,'Unexpected rejection: '.$e->getMessage()); return; }
    throw new RuntimeException('Expected rejection');
}
$root='/tmp/opencode/am-customer-'.bin2hex(random_bytes(6)); mkdir($root,0700);
$config=['database'=>"$root/settings.sqlite",'secret_key'=>"$root/gateway.key",'public_url'=>'https://example.com/am-app'];
$revision='auth-one'; $renewals=[]; $attempts=0; $lost=false;
$package=['id'=>1,'name'=>'Monthly','price_credits'=>'3.5','duration'=>30,'duration_period'=>'days','duration_hours'=>720,'max_connections'=>2,'package_type'=>'0','worker_fingerprint'=>str_repeat('b',64)];
$worker=function($route,$body) use (&$revision,&$renewals,&$attempts,&$lost,$package) {
    $line=['line_id'=>'10','package_id'=>1,'max_connections'=>2,'username'=>'customer','line_fingerprint'=>str_repeat('a',64)];
    if ($route==='/am/customer-auth' && ($body['password']??'')!=='secret') return ['ok'=>false];
    if (in_array($route,['/am/customer','/am/customer-auth'],true)) {
        if (isset($body['line_id']) && ($body['line_id']!=='10'||$body['reseller_id']!=='1')) return ['ok'=>false];
        return ['ok'=>true,'reseller_id'=>'1','line'=>$line,'auth_revision'=>$revision];
    }
    if ($route==='/am/packages') return ['ok'=>true,'packages'=>[$package]];
    if ($route==='/am/renew-quote') return ['ok'=>true,'line_fingerprint'=>$line['line_fingerprint'],'package'=>$package];
    if ($route==='/am/renew-result') return $renewals[$body['order_id']]??['ok'=>true,'state'=>'not_applied'];
    if ($route==='/am/renew') {
        $attempts++; $renewals[$body['order_id']]=['ok'=>true,'state'=>'applied','order_id'=>$body['order_id']];
        if ($lost) { $lost=false; throw new RuntimeException('Lost acknowledgement'); }
        return $renewals[$body['order_id']];
    }
    throw new RuntimeException('Unexpected route');
};
$paid=false; $external=false; $badAmount=false; $lostLink=false; $remote=[]; $creates=0;
$square=new Square(function($path,$body,$c) use (&$paid,&$external,&$badAmount,&$lostLink,&$remote,&$creates) {
    if ($path==='/v2/merchants/me') return ['merchant'=>['id'=>'merchant','status'=>'ACTIVE','country'=>'CA']];
    if ($path==='/v2/locations/location') return ['location'=>['id'=>'location','merchant_id'=>'merchant','country'=>'CA','currency'=>'CAD','status'=>'ACTIVE','capabilities'=>['CREDIT_CARD_PROCESSING']]];
    if ($path==='/v2/online-checkout/payment-links') {
        $creates++; $id='sq-'.$body['idempotency_key'];
        check($body['order']['line_items'][0]['name']==='Payment','Receipt label');
        $remote[$id]=['id'=>$id,'reference_id'=>$body['idempotency_key'],'location_id'=>'location','state'=>'OPEN','total_money'=>$body['order']['line_items'][0]['base_price_money']];
        if ($lostLink) { $lostLink=false; throw new RuntimeException('Lost checkout reply'); }
        return ['payment_link'=>['order_id'=>$id,'id'=>'link-'.$id,'url'=>'https://square.link/test']];
    }
    if (str_starts_with($path,'/v2/orders/')) {
        $id=substr($path,11); $o=$remote[$id];
        if ($paid) $o['tenders']=[['payment_id'=>'pay-'.$id]];
        return ['order'=>$o];
    }
    if (str_starts_with($path,'/v2/payments/')) {
        $id=substr($path,13); $order=substr($id,4); $money=$remote[$order]['total_money'];
        if ($badAmount) $money['amount']++;
        return ['payment'=>['id'=>$id,'order_id'=>$order,'location_id'=>'location','status'=>'COMPLETED','amount_money'=>$money,
            'source_type'=>$external?'EXTERNAL':'CARD','card_details'=>['status'=>'CAPTURED']]];
    }
    throw new RuntimeException('Unexpected Square route '.$path);
});
try {
    SettingsStore::initialize($config['database']); PricingStore::migrate($config['database']); SquareStore::migrate($config['database']); CustomerService::migrate($config['database']);
    file_put_contents($config['secret_key'],random_bytes(32));
    $service=new CustomerService($config,$worker,new Square(fn()=>throw new RuntimeException('Free checkout must not call Square')));
    $settings=new SettingsStore($config['database']); $s=Settings::defaults();
    $save=function() use ($settings,&$s) { $settings->save('1',$settings->read('1')['revision'],$s); };
    denied(fn()=>$service->login(['username'=>'customer','password'=>'bad'],'fixture'),401);
    denied(fn()=>$service->login(['username'=>'customer','password'=>'secret'],'fixture'),403);
    $s=array_replace($s,['app_enabled'=>true,'allow_renewals'=>true,'billing_mode'=>'integrated','gateways'=>['square']]); $save();
    denied(fn()=>$service->approve('2','10',true),409);
    $service->approve('1','10',true);
    $catalogue=PricingStore::catalogue(['ok'=>true,'packages'=>[$package]]);
    (new PricingStore($config['database']))->save('1',['package_id'=>'1','currency'=>'CAD','amount'=>'0','revision'=>'0','fingerprint'=>$catalogue[1]['fingerprint'],'enabled'=>'1'],$catalogue);
    $login=$service->login(['username'=>'customer','password'=>'secret'],'fixture');
    $ctx=$service->session($login['token']); check(count($login['account']['offers'])===1,'Offer available');
    check($service->account($ctx)['renewal_unavailable_message']===null,'Available offer has no false blocker');
    foreach ([['allow_renewals',false,'general customer renewal permission'],['billing_mode','none','Integrated billing'],['gateways',[],'Square is not selected']] as [$field,$value,$expected]) {
        $blocked=$ctx; $blocked['settings'][$field]=$value;
        $view=$service->account($blocked);
        check($view['offers']===[] && str_contains($view['renewal_unavailable_message'],$expected),'Specific configuration blocker: '.$field);
    }
    $blocked=$ctx; $blocked['line']['max_connections']=1;
    check(str_contains($service->account($blocked)['renewal_unavailable_message'],'connection limit differs'),'Connection mismatch distinguished from disabled price');
    $blocked=$ctx; $blocked['line']['package_id']=null;
    check(str_contains($service->account($blocked)['renewal_unavailable_message'],'No package is assigned'),'Missing assignment explained');
    $db=new PDO('sqlite:'.$config['database']);
    $db->exec('UPDATE am_package_prices SET enabled=0');
    check(str_contains($service->account($ctx)['renewal_unavailable_message'],'disabled for CAD'),'Disabled price explained');
    $db->exec('UPDATE am_package_prices SET enabled=1,amount_minor=725');
    $view=$service->account($ctx);
    check($view['offers']===[] && str_contains($view['renewal_unavailable_message'],'sandbox CAD connection'),'Enabled paid price reports missing matching gateway');
    $db->exec('UPDATE am_package_prices SET amount_minor=0');
    $db->exec("UPDATE am_package_prices SET package_fingerprint='stale'");
    check(str_contains($service->account($ctx)['renewal_unavailable_message'],'review and save the CAD'),'Stale package price explained');
    $db->prepare('UPDATE am_package_prices SET package_fingerprint=?')->execute([$catalogue[1]['fingerprint']]);
    check($service->renewalDiagnostic('1','10')['offers']===['CAD'],'Reseller diagnostic uses the same available offers');
    denied(fn()=>$service->renewalDiagnostic('2','10'),403);
    denied(fn()=>$service->bouquets($ctx,['bouquet_ids'=>[1],'revision'=>str_repeat('a',64)]),403);
    $editable=$ctx; $editable['settings']['allow_bouquets']=true;
    denied(fn()=>$service->bouquets($editable,['bouquet_ids'=>['1'],'revision'=>str_repeat('a',64)]),422);
    denied(fn()=>$service->bouquets($editable,['bouquet_ids'=>[1,1],'revision'=>str_repeat('a',64)]),422);
    denied(fn()=>$service->quote($ctx,2,'CAD'),403);
    $legacy=$ctx; $legacy['settings']['allow_packages']=true; $legacy['settings']['allow_connections']=true;
    denied(fn()=>$service->quote($legacy,2,'CAD'),403);
    $legacy['line']['max_connections']=1;
    denied(fn()=>$service->quote($legacy,1,'CAD'),403);
    $quote=$service->quote($ctx,1,'CAD'); $sandbox=$service->checkout($ctx,$quote['quote_id']);
    check($sandbox['state']==='sandbox_verified' && $attempts===0,'Sandbox isolation');
    $s['square_mode']='live'; $save(); $ctx=$service->session($login['token']);
    check($service->status($ctx,$sandbox['id'])['state']==='sandbox_verified' && $attempts===0,'Mode switch preserves sandbox isolation');
    $s['billing_mode']='none'; $save(); $ctx=$service->session($login['token']);
    denied(fn()=>$service->quote($ctx,1,'CAD'),403);
    $s['billing_mode']='integrated'; $s['gateways']=['stripe']; $save(); $ctx=$service->session($login['token']);
    denied(fn()=>$service->quote($ctx,1,'CAD'),403);
    $s['gateways']=['square']; $save(); $ctx=$service->session($login['token']);
    $quote=$service->quote($ctx,1,'CAD'); $lost=true;
    $order=$service->checkout($ctx,$quote['quote_id']);
    check($order['state']==='review' && $order['payment_verified'],'Lost acknowledgement retains verified order');
    $other=$service->quote($ctx,1,'CAD'); $recovered=$service->checkout($ctx,$other['quote_id']);
    check($recovered['id']===$order['id'] && $recovered['state']==='applied' && $attempts===1,'Outstanding order recovered without second renewal');
    check($service->checkout($ctx,$quote['quote_id'])['id']===$order['id'] && $attempts===1,'Quote replay immutable');
    denied(fn()=>$service->status(array_replace($ctx,['reseller'=>'2']),$order['id']),404);
    $revision='auth-two'; denied(fn()=>$service->session($login['token']),401);
    $s['customer_policy']='all'; $save(); $service->approve('1','10',false);
    denied(fn()=>$service->login(['username'=>'customer','password'=>'secret'],'fixture'),403);
    denied(fn()=>$service->session($login['token']),401);
    check(count($service->orders($ctx))===2,'Isolated history retains both orders');
    $service->approve('1','10',true);
    $service=new CustomerService($config,$worker,$square);
    $merchant=new SquareStore($config,$square);
    foreach (['sandbox','live'] as $mode) {
        $merchant->connect('1',$mode,'FAKE_SQUARE_TOKEN_ONLY','location');
        foreach ($merchant->connections('1') as $c) if ($c['mode']===$mode) $merchant->saveWebhook('1',$c['id'],'FAKE_WEBHOOK_KEY_ONLY');
    }
    (new PricingStore($config['database']))->save('1',['package_id'=>'1','currency'=>'CAD','amount'=>'7.25','revision'=>'1','fingerprint'=>$catalogue[1]['fingerprint'],'enabled'=>'1'],$catalogue);
    $login=$service->login(['username'=>'customer','password'=>'secret'],'paid-fixture'); $ctx=$service->session($login['token']);
    $q=$service->quote($ctx,1,'CAD'); $lostLink=true; $order=$service->checkout($ctx,$q['quote_id']);
    check($order['state']==='review' && !$order['payment_verified'],'Lost link keeps unpaid order');
    $order=$service->checkout($ctx,$q['quote_id']);
    check($order['state']==='pending' && count($remote)===1 && $attempts===1,'Link recovery uses same Square reference');
    $paid=true; $badAmount=true;
    check($service->status($ctx,$order['id'])['state']==='review' && $attempts===1,'Wrong amount never renews');
    $badAmount=false; $external=true;
    check($service->status($ctx,$order['id'])['state']==='review' && $attempts===1,'Production EXTERNAL never renews');
    $external=false; $lost=true;
    check($service->status($ctx,$order['id'])['payment_verified'] && $attempts===2,'Captured payment persists before uncertain renewal');
    $connection=$merchant->forCheckout('1','live','CAD');
    $service->notification('1',$connection['id'],'sq-'.$order['id']);
    $service->notification('1',$connection['id'],'sq-'.$order['id']);
    denied(fn()=>$service->retryOrder('2',$order['id']),404);
    check($service->resellerOrders('2')===[],'Reseller cannot see other merchant orders');
    check($service->retryOrder('1',$order['id'])['state']==='applied','Reseller retry preserves completed result');
    check($service->status($ctx,$order['id'])['state']==='applied' && $attempts===2 && $creates===2,'Notification replay recovers without charge or renewal duplication');
    $s['square_mode']='sandbox'; $save(); $ctx=$service->session($login['token']); $external=true;
    $q=$service->quote($ctx,1,'CAD'); $test=$service->checkout($ctx,$q['quote_id']);
    check($test['state']==='sandbox_verified' && $attempts===2,'Paid sandbox EXTERNAL remains isolated');
    $promos=new \AmAccount\Promotions($config['database']);
    $image=\AmAccount\Promotions::image(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j8N8AAAAASUVORK5CYII='));
    $input=['promo_id'=>'','revision'=>'0','package_id'=>'1','currency'=>'CAD','amount'=>'5.00','title'=>'Weekend deal','description'=>'Current package only',
        'starts'=>gmdate('Y-m-d\TH:i',time()-600),'ends'=>gmdate('Y-m-d\TH:i',time()+86400),'enabled'=>'1','fingerprint'=>$catalogue[1]['fingerprint']];
    $reject=function(Closure $fn) { try { $fn(); } catch (\InvalidArgumentException | \AmAccount\Conflict $e) { return; } throw new RuntimeException('Expected promo rejection'); };
    $reject(fn()=>\AmAccount\Promotions::image('<svg onload="alert(1)"></svg>'));
    $reject(fn()=>$promos->save('1',array_replace($input,['amount'=>'7.25']),$catalogue,$image));
    $reject(fn()=>$promos->save('1',array_replace($input,['ends'=>$input['starts']]),$catalogue,$image));
    $id=$promos->save('1',$input,$catalogue,$image);
    check($promos->get('2',$id)===null,'Promotion tenant isolation');
    $reject(fn()=>$promos->disable('2',$id,1));
    $reject(fn()=>$promos->save('2',array_replace($input,['promo_id'=>$id,'revision'=>'1']),$catalogue,$image));
    $base=(new PricingStore($config['database']))->quote('1',1,'CAD',$catalogue);
    $row=$promos->get('1',$id);
    $reject(fn()=>$promos->price('1',$id,$base,(int)$row['starts']-1));
    check($promos->price('1',$id,$base,(int)$row['starts'])['id']===$id,'Inclusive start boundary');
    $reject(fn()=>$promos->price('1',$id,$base,(int)$row['ends']));
    $reject(fn()=>$promos->price('1',$id,array_replace($base,['package_id'=>2])));
    $reject(fn()=>$promos->price('1',$id,array_replace($base,['currency'=>'USD'])));
    check(count($service->account($ctx)['deals'])===1,'Eligible active deal appears');
    $blocked=$ctx; $blocked['line']['max_connections']=1;
    check($service->account($blocked)['deals']===[],'Different connection count sees no deals');
    $blocked=$ctx; $blocked['settings']['allow_renewals']=false;
    check($service->account($blocked)['deals']===[],'Renewal permission also gates deals');
    $q=$service->quote($ctx,1,'CAD',$id);
    check($q['amount_minor']===500 && $q['promo_title']==='Weekend deal','Server-owned discounted quote');
    $promos->disable('1',$id,1);
    check($service->account($ctx)['deals']===[],'Disabled deal hidden');
    $reject(fn()=>$service->checkout($ctx,$q['quote_id']));
    $input['promo_id']=$id; $input['revision']='2';
    $promos->save('1',$input,$catalogue,null);
    $q=$service->quote($ctx,1,'CAD',$id);
    $db->exec('UPDATE am_promotions SET ends=1');
    check($service->account($ctx)['deals']===[],'Expired deal hidden');
    $reject(fn()=>$service->checkout($ctx,$q['quote_id']));
    $input['revision']='3'; $promos->save('1',$input,$catalogue,null);
    $q=$service->quote($ctx,1,'CAD',$id);
    $test=$service->checkout($ctx,$q['quote_id']);
    check($test['amount_minor']===500 && $test['state']==='sandbox_verified' && $attempts===2,'Discounted sandbox never renews');
    $s['square_mode']='live'; $save(); $ctx=$service->session($login['token']); $external=false; $paid=false;
    $q=$service->quote($ctx,1,'CAD',$id); $order=$service->checkout($ctx,$q['quote_id']);
    $promos->disable('1',$id,4); $paid=true;
    $complete=$service->status($ctx,$order['id']);
    check($complete['amount_minor']===500 && $complete['state']==='applied' && $attempts===3,'Existing paid promo order keeps price after withdrawal');
    check($service->checkout($ctx,$q['quote_id'])['id']===$order['id'] && $attempts===3,'Promo checkout replay is idempotent');
    check($service->quote($ctx,1,'CAD')['amount_minor']===725,'Future normal renewal uses normal price');
    echo "Customer access, billing gates, sandbox isolation, order recovery and promotion eligibility/expiry/payment tests passed.\n";
} finally {
    foreach (glob($root.'/*') as $file) unlink($file); rmdir($root);
}
