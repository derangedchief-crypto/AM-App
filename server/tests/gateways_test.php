<?php
declare(strict_types=1);
foreach (['Settings','Pricing','Square','Customer'] as $name) require_once __DIR__.'/../panel/'.$name.'.php';
use AmAccount\{Settings,SettingsStore,PricingStore,Square,SquareStore,CustomerService,Gateways,GatewayStore,Licenses};
function expect(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
function rejects(Closure $fn): void { try { $fn(); } catch (Throwable $e) { return; } throw new RuntimeException('Expected rejection'); }
$root='/tmp/opencode/am-gateways-'.bin2hex(random_bytes(6)); mkdir($root,0700);
$config=['database'=>$root.'/settings.sqlite','secret_key'=>$root.'/key','public_url'=>'https://example.com/am-app'];
$paid=false; $bad=false; $lost=false; $calls=0; $captures=0; $remote=[]; $renewals=[]; $renewCount=0;
$api=new Gateways(function($c,$method,$path,$body,$key) use (&$remote,&$paid,&$bad,&$lost,&$calls,&$captures) {
    if ($path==='/v1/account') return ['id'=>'acct_fixture','charges_enabled'=>true];
    if ($path==='/api-validate-key') return ['valid'=>true,'merchant_id'=>'merchant_fixture'];
    if ($path==='/v1/oauth2/token') return ['access_token'=>'fixture-access-token'];
    if ($path==='/v1/notifications/verify-webhook-signature') return ['verification_status'=>($body['transmission_sig'] ?? '')==='valid'?'SUCCESS':'FAILURE'];
    if ($path==='/v2/checkout/orders' && str_starts_with($key,'amv-')) return ['id'=>'verify'];
    if ($path==='/v2/checkout/orders/verify') return ['purchase_units'=>[['payee'=>['merchant_id'=>'merchant_fixture']]]];
    if ($path==='/v1/checkout/sessions' || $path==='/v2/checkout/orders' || $path==='/api-create-payment-link') {
        $calls++;
        if ($c['gateway']==='stripe') {
            $id='cs_'.$key; $price=$body['line_items'][0]['price_data']; expect($price['product_data']['name']==='Payment','Stripe receipt');
            $r=['id'=>$id,'client_reference_id'=>$key,'mode'=>'payment','livemode'=>$c['mode']==='live','amount_total'=>$price['unit_amount'],'currency'=>$price['currency'],'url'=>'https://checkout.stripe.com/c/pay/'.$id];
        } elseif ($c['gateway']==='paypal') {
            $id='pp_'.$key; $u=$body['purchase_units'][0]; $u['payee']=['merchant_id'=>'merchant_fixture'];
            $r=['id'=>$id,'intent'=>'CAPTURE','purchase_units'=>[$u],'links'=>[['rel'=>'payer-action','href'=>'https://'.($c['mode']==='sandbox'?'www.sandbox.paypal.com':'www.paypal.com').'/checkoutnow?token='.$id]]];
        } else {
            $id='rp_'.$body['woo_order_id'];
            $r=['link_id'=>$id,'description'=>$body['description'],'amount'=>$body['amount'],'currency'=>$body['currency'],'received_amount'=>$body['amount'],'payment_url'=>'https://checkout.rampex.io/pay.php?ref='.$id,'status'=>'active'];
        }
        $remote[$id]=$r;
        if ($lost) { $lost=false; throw new RuntimeException('Lost response after provider creation'); }
        return $c['gateway']==='rampex'?['success'=>true,'data'=>$r]:$r;
    }
    if (str_starts_with($path,'/v1/checkout/sessions/')) {
        $id=explode('?',basename($path))[0]; $r=$remote[$id]; $ref=$r['client_reference_id'];
        $r['status']=$paid?'complete':'open'; $r['payment_status']=$paid?'paid':'unpaid';
        $r['payment_intent']=['id'=>'pi_'.$ref,'status'=>'succeeded','amount_received'=>$r['amount_total']+($bad?1:0),'currency'=>$r['currency'],'livemode'=>$r['livemode'],'metadata'=>['am_order'=>$ref],
            'latest_charge'=>['paid'=>true,'captured'=>true,'amount_refunded'=>0,'disputed'=>false,'payment_intent'=>'pi_'.$ref,'amount'=>$r['amount_total'],'currency'=>$r['currency']]];
        return $r;
    }
    if (str_starts_with($path,'/v2/checkout/orders/')) {
        if (str_ends_with($path,'/capture')) { $captures++; $id=basename(dirname($path)); $remote[$id]['captured']=true; return $remote[$id]; }
        $id=basename($path); $r=$remote[$id]; $r['status']=!empty($r['captured'])?'COMPLETED':($paid?'APPROVED':'CREATED');
        if (!empty($r['captured'])) $r['purchase_units'][0]['payments']['captures']=[['id'=>'capture_'.$id]];
        return $r;
    }
    if (str_starts_with($path,'/v2/payments/captures/')) {
        $id=basename($path); $order=substr($id,8); $a=$remote[$order]['purchase_units'][0]['amount'];
        return ['id'=>$id,'status'=>$bad?'PENDING':'COMPLETED','final_capture'=>true,'amount'=>$a,'supplementary_data'=>['related_ids'=>['order_id'=>$order]]];
    }
    if (str_starts_with($path,'/api-list-payment-links')) {
        $links=[]; foreach ($remote as $id=>$r) if (str_starts_with($id,'rp_')) {
            $r['status']=$paid?'completed':'active'; $r['paid_at']=$paid?'2026-11-05T00:00:00Z':null;
            if ($bad) $r['received_amount']=0.01;
            $links[]=$r;
        }
        return ['success'=>true,'data'=>['links'=>$links]];
    }
    throw new RuntimeException('Unexpected provider request '.$path);
});
$package=['id'=>1,'name'=>'Monthly','price_credits'=>'3','duration'=>30,'duration_period'=>'days','duration_hours'=>720,'max_connections'=>1,'package_type'=>'0','worker_fingerprint'=>str_repeat('b',64)];
$worker=function($path,$body) use ($package,&$renewals,&$renewCount) {
    if ($path==='/am/customer' || $path==='/am/customer-auth') return ['ok'=>true,'reseller_id'=>'1','auth_revision'=>'fixture','line'=>['line_id'=>'10','username'=>'fixture','package_id'=>1,'max_connections'=>1,'expire_at'=>time()+86400,'activated'=>true]];
    if ($path==='/am/packages') return ['ok'=>true,'packages'=>[$package]];
    if ($path==='/am/renew-quote') return ['ok'=>true,'line_fingerprint'=>str_repeat('a',64),'package'=>$package];
    if ($path==='/am/renew-result') return $renewals[$body['order_id']] ?? ['ok'=>true,'state'=>'not_applied'];
    if ($path==='/am/renew') { $renewCount++; return $renewals[$body['order_id']]=['ok'=>true,'state'=>'applied','order_id'=>$body['order_id']]; }
    throw new RuntimeException('Unexpected worker request');
};
try {
    SettingsStore::initialize($config['database']); PricingStore::migrate($config['database']); SquareStore::migrate($config['database']); CustomerService::migrate($config['database']);
    file_put_contents($config['secret_key'],random_bytes(32));
    $licenses=new Licenses($config['database']); $l=$licenses->issue('1',null); $licenses->redeem('1',$l['key']);
    $settings=new SettingsStore($config['database']); $s=array_replace(Settings::defaults(),['app_enabled'=>true,'customer_policy'=>'all','allow_renewals'=>true,'billing_mode'=>'integrated','gateways'=>['stripe','paypal','rampex']]);
    $settings->save('1',0,$s);
    $catalogue=PricingStore::catalogue(['ok'=>true,'packages'=>[$package]]);
    (new PricingStore($config['database']))->save('1',['package_id'=>'1','currency'=>'USD','amount'=>'7.25','revision'=>'0','fingerprint'=>$catalogue[1]['fingerprint'],'enabled'=>'1'],$catalogue);
    $store=new GatewayStore($config,$api); $db=new PDO('sqlite:'.$config['database']);
    $service=new CustomerService($config,$worker,new Square(fn()=>throw new RuntimeException('Must not call Square')),$api);
    $login=$service->login(['username'=>'fixture','password'=>'fixture'],'fixture'); $ctx=$service->session($login['token']);
    foreach (['stripe','paypal','rampex'] as $gateway) foreach ($gateway==='rampex'?['live']:['sandbox','live'] as $mode) {
        $paid=false; $bad=false;
        $secret=$gateway==='stripe'?('sk_'.($mode==='live'?'live':'test').'_fixture_only_123456'):'fixture_secret_key_123456';
        $input=['gateway'=>$gateway,'mode'=>$mode,'currency'=>'USD','secret'=>$secret,'client_id'=>'fixture_client','merchant_id'=>'merchant_fixture'];
        $store->connect('1',$input); $c=$store->connections('1')[0];
        $store->connect('1',$input);
        expect($store->connections('1')[0]['id']===$c['id'],'Same verified merchant reconnect preserves order routing');
        rejects(fn()=>$store->connect('2',$input));
        rejects(fn()=>$store->configure('2',$c['id'],'fixture_webhook_secret',true));
        rejects(fn()=>$store->connection('2',$c['id']));
        $store->configure('1',$c['id'],'fixture_webhook_secret',true);
        expect(!str_contains(file_get_contents($config['database']),$secret),'Credentials must be encrypted at rest');
        $options=$service->account($ctx)['offers'][0]['payment_options'];
        expect(in_array(['gateway'=>$gateway,'mode'=>$mode],$options,true),'Available gateway option');
        $quote=$service->quote($ctx,1,'USD',null,$gateway,'buyer@example.com');
        expect($quote['gateway']===$gateway && $quote['mode']===$mode,'Quote pins provider/mode');
        $before=$renewCount; $lost=true;
        $order=$service->checkout($ctx,$quote['quote_id']); expect($order['state']==='review','Uncertain creation stays review');
        $created=$calls;
        $order=$service->checkout($ctx,$quote['quote_id']); expect($order['state']==='pending','Original payment recovered');
        if ($gateway==='rampex') expect($calls===$created,'Rampex recovery must not create another link');
        expect($renewCount===$before,'Pending payment must not renew');
        $paid=true; $bad=true;
        expect($service->status($ctx,$order['id'])['state']==='review' && $renewCount===$before,'Unsettled or mismatched payment must not renew');
        $bad=false;
        // A disabled connection still reconciles its original order.
        $store->configure('1',$c['id'],'',false);
        $disabled=array_replace($s,['gateways'=>[],'allow_renewals'=>false,'billing_mode'=>'none']);
        $settings->save('1',$settings->read('1')['revision'],$disabled);
        $result=$service->status($ctx,$order['id']);
        expect($result['state']===($mode==='live'?'applied':'sandbox_verified'),'Payment completes in pinned environment');
        expect($renewCount===$before+($mode==='live'?1:0),'Sandbox never mutates service');
        $service->status($ctx,$order['id']); $service->checkout($ctx,$quote['quote_id']);
        expect($renewCount===$before+($mode==='live'?1:0),'Retries do not double renew');
        rejects(fn()=>$service->status(array_replace($ctx,['reseller'=>'2']),$order['id']));
        $connection=$store->connection('1',$c['id']);
        $remoteId=$db->query("SELECT remote_id FROM am_gateway_transactions WHERE order_id='".$order['id']."'")->fetchColumn();
        if ($gateway==='stripe') {
            $event=['type'=>'checkout.session.completed','livemode'=>$mode==='live','data'=>['object'=>['id'=>$remoteId,'client_reference_id'=>$order['id']]]];
            $raw=json_encode($event); $headers=['stripe-signature'=>'t='.time().',v1='.hash_hmac('sha256',time().'.'.$raw,'fixture_webhook_secret')];
            rejects(fn()=>$api->webhook($connection,$raw,['stripe-signature'=>'t=1,v1=bad']));
        } elseif ($gateway==='paypal') {
            $event=['event_type'=>'CHECKOUT.ORDER.APPROVED','resource'=>['id'=>$remoteId,'purchase_units'=>[['custom_id'=>$order['id']]]]]; $raw=json_encode($event);
            $headers=['paypal-auth-algo'=>'SHA256withRSA','paypal-cert-url'=>'https://api.paypal.com/cert','paypal-transmission-id'=>'fixture','paypal-transmission-sig'=>'valid','paypal-transmission-time'=>gmdate('c')];
            rejects(fn()=>$api->webhook($connection,$raw,array_replace($headers,['paypal-transmission-sig'=>'invalid'])));
        } else {
            $event=['event'=>'payment.completed','link_id'=>$remoteId,'description'=>'Payment '.$order['id']]; $raw=json_encode($event);
            $headers=['x-rampex-signature'=>hash_hmac('sha256',$raw,'fixture_webhook_secret')];
            rejects(fn()=>$api->webhook($connection,$raw.' ',$headers));
        }
        $events=0;
        $db->prepare('UPDATE am_gateway_transactions SET remote_id=NULL WHERE order_id=?')->execute([$order['id']]);
        $store->webhook($c['id'],$raw,$headers,function($reseller,$id) use ($service,&$events) { $events++; $service->retryOrder($reseller,$id); });
        $store->webhook($c['id'],$raw,$headers,function($reseller,$id) use ($service,&$events) { $events++; $service->retryOrder($reseller,$id); });
        expect($events===2 && $renewCount===$before+($mode==='live'?1:0),'Signed webhook replay cannot double renew');
        $settings->save('1',$settings->read('1')['revision'],$s);
    }
    rejects(fn()=>$store->connect('1',['gateway'=>'rampex','mode'=>'sandbox','currency'=>'USD','secret'=>'fixture_secret_key_123456']));
    foreach (['stripe','paypal','rampex'] as $gateway) rejects(fn()=>Gateways::checkoutUrl($gateway,'live','https://evil.example/checkout'));
    rejects(fn()=>Gateways::minor('7.251'));
    expect(Gateways::minor('7.25')===725 && Gateways::decimal(725)==='7.25','Exact money conversion');
    echo "PASS: Stripe/PayPal/Rampex settlement, tenant isolation, encrypted credentials, sandbox, uncertain creation recovery and signed webhook replay.\n";
} finally { foreach (glob($root.'/*') as $file) unlink($file); rmdir($root); }
