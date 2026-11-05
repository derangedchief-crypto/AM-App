<?php
declare(strict_types=1);
namespace AmAccount;

final class PaymentRejected extends \RuntimeException {}

/** Hosted checkout adapters. Only authenticated provider reads prove settlement. */
final class Gateways
{
    public function __construct(private ?\Closure $transport=null) {}
    public static function id(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,200}$/D',$id)) throw new \InvalidArgumentException('Invalid payment reference.');
        return $id;
    }
    public static function decimal(int $minor): string { return intdiv($minor,100).'.'.str_pad((string)($minor%100),2,'0',STR_PAD_LEFT); }
    public static function minor(mixed $value): int
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) throw new \RuntimeException('Invalid payment amount.');
        $value=(string)$value;
        if (!preg_match('/^(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,2}))?$/D',$value,$m)) throw new \RuntimeException('Invalid payment precision.');
        return (int)$m[1]*100+(int)str_pad($m[2] ?? '',2,'0');
    }
    private function request(array $c,string $method,string $path,?array $body=null,string $key=''): array
    {
        if ($this->transport) return ($this->transport)($c,$method,$path,$body,$key);
        $provider=$c['gateway'];
        $host=match($provider) {'stripe'=>'https://api.stripe.com','paypal'=>$c['mode']==='sandbox'?'https://api-m.sandbox.paypal.com':'https://api-m.paypal.com','rampex'=>'https://api.rampex.io',default=>throw new \RuntimeException('Unknown gateway.')};
        $form=$provider==='stripe' || $path==='/v1/oauth2/token';
        $headers=['Accept: application/json','Content-Type: '.($form?'application/x-www-form-urlencoded':'application/json')];
        if ($provider==='stripe') { $headers[]='Authorization: Bearer '.$c['secret']; $headers[]='Stripe-Version: 2026-10-28.endive'; if ($key!=='') $headers[]='Idempotency-Key: '.$key; }
        if ($provider==='rampex') $headers[]='X-API-Key: '.$c['secret'];
        if ($provider==='paypal') {
            if ($path==='/v1/oauth2/token') $headers[]='Authorization: Basic '.base64_encode($c['client_id'].':'.$c['secret']);
            else { $token=$this->request($c,'POST','/v1/oauth2/token',['grant_type'=>'client_credentials'])['access_token'] ?? ''; if (!is_string($token) || $token==='') throw new \RuntimeException('PayPal authentication unavailable.'); $headers[]='Authorization: Bearer '.$token; }
            if ($key!=='') $headers[]='PayPal-Request-Id: '.$key;
            $headers[]='Prefer: return=representation';
        }
        $raw=''; $curl=curl_init($host.$path);
        curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,
            CURLOPT_WRITEFUNCTION=>static function($handle,$chunk) use (&$raw) { if (strlen($raw)+strlen($chunk)>1048576) return 0; $raw.=$chunk; return strlen($chunk); }]);
        if ($body!==null) curl_setopt($curl,CURLOPT_POSTFIELDS,$form?http_build_query($body,'','&'):json_encode($body ?: new \stdClass(),JSON_THROW_ON_ERROR));
        $ok=curl_exec($curl); $code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
        if ($ok!==false && in_array($code,[400,401,403,422,429],true)) throw new PaymentRejected('Payment provider rejected the request. Check the connection and retry the original order.');
        if ($ok===false || !in_array($code,[200,201,202],true)) throw new \RuntimeException('Payment provider could not confirm the request. Retry the original order.');
        $data=json_decode($raw,true,48,JSON_THROW_ON_ERROR);
        if (!is_array($data) || isset($data['error'])) throw new \RuntimeException('Invalid payment provider response.');
        return $data;
    }
    public function validate(array $c): array
    {
        if (!in_array($c['gateway'],['stripe','paypal','rampex'],true) || !in_array($c['mode'],['sandbox','live'],true)) throw new \InvalidArgumentException('Invalid gateway or environment.');
        foreach (['secret','client_id','merchant_id'] as $field) if (strlen($c[$field] ?? '')>512 || preg_match('/[\x00-\x20\x7f]/',$c[$field] ?? '')) throw new \InvalidArgumentException('Invalid gateway credentials.');
        if (strlen($c['secret'])<16) throw new \InvalidArgumentException('Enter the full provider secret key.');
        if ($c['gateway']==='stripe') {
            if (!str_starts_with($c['secret'],$c['mode']==='live'?'sk_live_':'sk_test_')) throw new \InvalidArgumentException('Stripe key does not match the environment.');
            $a=$this->request($c,'GET','/v1/account');
            if (($a['charges_enabled'] ?? false)!==true) throw new \InvalidArgumentException('Stripe charges are not enabled for this account.');
            return ['merchant_id'=>self::id($a['id'] ?? null),'business_name'=>substr((string)($a['business_profile']['name'] ?? 'Stripe merchant'),0,120)];
        }
        if ($c['gateway']==='paypal') {
            self::id($c['client_id']); self::id($c['merchant_id']);
            $a=$this->request($c,'POST','/v1/oauth2/token',['grant_type'=>'client_credentials']);
            if (empty($a['access_token'])) throw new \InvalidArgumentException('PayPal credentials could not be verified.');
            // An unapproved verification order identifies the actual payee without charging anyone.
            $probe=$this->request($c,'POST','/v2/checkout/orders',['intent'=>'CAPTURE','purchase_units'=>[['description'=>'Connection verification','amount'=>['currency_code'=>'USD','value'=>'1.00']]]], 'amv-'.substr(hash('sha256',$c['client_id']),0,32));
            $probe=$this->request($c,'GET','/v2/checkout/orders/'.self::id($probe['id'] ?? null));
            $merchant=self::id($probe['purchase_units'][0]['payee']['merchant_id'] ?? null);
            if ($merchant!==$c['merchant_id']) throw new \InvalidArgumentException('PayPal merchant ID does not match this REST app.');
            return ['merchant_id'=>$merchant,'business_name'=>'PayPal merchant'];
        }
        if ($c['mode']!=='live') throw new \InvalidArgumentException('Rampex is live-only. It has no sandbox.');
        $a=$this->request($c,'POST','/api-validate-key',[]);
        if (($a['valid'] ?? false)!==true) throw new \InvalidArgumentException('Rampex API key is not active.');
        return ['merchant_id'=>self::id($a['merchant_id'] ?? null),'business_name'=>substr((string)($a['business_name'] ?? 'Rampex merchant'),0,120)];
    }
    public static function checkoutUrl(string $gateway,string $mode,mixed $url): string
    {
        $p=is_string($url)?parse_url($url):false;
        $hosts=match($gateway) {'stripe'=>['checkout.stripe.com'],'paypal'=>[$mode==='sandbox'?'www.sandbox.paypal.com':'www.paypal.com'],'rampex'=>['checkout.rampex.io','rampex.io'],default=>[]};
        if (!$p || ($p['scheme'] ?? '')!=='https' || !in_array($p['host'] ?? '',$hosts,true) || isset($p['user']) || isset($p['pass']) || (isset($p['port']) && $p['port']!==443)) throw new \RuntimeException('Untrusted payment URL.');
        return $url;
    }
    private function paypalOrder(array $c,array $o,array $remote): array
    {
        $units=$remote['purchase_units'] ?? [];
        if (count($units)!==1 || ($remote['id'] ?? '')!==$o['remote_id'] || ($remote['intent'] ?? '')!=='CAPTURE') throw new \RuntimeException('PayPal order mismatch.');
        $u=$units[0];
        if (($u['custom_id'] ?? '')!==$o['id'] || ($u['payee']['merchant_id'] ?? '')!==$c['merchant_id'] || ($u['amount']['currency_code'] ?? '')!==$o['currency'] || self::minor($u['amount']['value'] ?? null)!==(int)$o['amount_minor']) throw new \RuntimeException('PayPal amount or merchant mismatch.');
        return $u;
    }
    public function create(array $c,array $o,string $return): array
    {
        if ($c['gateway']==='stripe') {
            $r=$this->request($c,'POST','/v1/checkout/sessions',['mode'=>'payment','success_url'=>$return,'cancel_url'=>$return,'client_reference_id'=>$o['id'],'metadata'=>['am_order'=>$o['id']],
                'payment_intent_data'=>['metadata'=>['am_order'=>$o['id']]],'payment_method_types'=>['card'],
                'line_items'=>[['quantity'=>1,'price_data'=>['currency'=>strtolower($o['currency']),'unit_amount'=>(int)$o['amount_minor'],'product_data'=>['name'=>'Payment']]]]],$o['id']);
            $id=self::id($r['id'] ?? null);
            $this->stripeSession($c,array_replace($o,['remote_id'=>$id]),$r);
            $url=$r['url'] ?? null;
        } elseif ($c['gateway']==='paypal') {
            $r=$this->request($c,'POST','/v2/checkout/orders',['intent'=>'CAPTURE','purchase_units'=>[['reference_id'=>$o['id'],'custom_id'=>$o['id'],'description'=>'Payment','amount'=>['currency_code'=>$o['currency'],'value'=>self::decimal((int)$o['amount_minor'])]]],
                'payment_source'=>['paypal'=>['experience_context'=>['return_url'=>$return,'cancel_url'=>$return,'user_action'=>'PAY_NOW','shipping_preference'=>'NO_SHIPPING']]]],$o['id']);
            $id=self::id($r['id'] ?? null);
            $remote=$this->request($c,'GET','/v2/checkout/orders/'.$id);
            $this->paypalOrder($c,array_replace($o,['remote_id'=>$id]),$remote);
            $url=null; foreach ($r['links'] ?? [] as $link) if (in_array($link['rel'] ?? '',['payer-action','approve'],true)) $url=$link['href'];
        } else {
            $r=$this->request($c,'POST','/api-create-payment-link',['amount'=>(int)$o['amount_minor']/100,'currency'=>$o['currency'],'customer_email'=>$o['email'],
                'description'=>'Payment '.$o['id'],'provider'=>'hosted','woo_order_id'=>$o['id'],'woo_store_url'=>dirname($return),'payment_url'=>$return]);
            if (($r['success'] ?? false)!==true) throw new \RuntimeException('Rampex checkout not confirmed.');
            $id=self::id($r['data']['link_id'] ?? null); $url=$r['data']['payment_url'] ?? null;
        }
        return ['remote_id'=>$id,'checkout_url'=>self::checkoutUrl($c['gateway'],$c['mode'],$url)];
    }
    private function stripeSession(array $c,array $o,array $s): void
    {
        if (($s['id'] ?? '')!==$o['remote_id'] || ($s['livemode'] ?? null)!==($c['mode']==='live') || ($s['mode'] ?? '')!=='payment' || ($s['client_reference_id'] ?? '')!==$o['id'] || ($s['amount_total'] ?? null)!==(int)$o['amount_minor'] || strtoupper($s['currency'] ?? '')!==$o['currency']) throw new \RuntimeException('Stripe checkout mismatch.');
    }
    /** Rampex has no documented idempotency key. Recover, never blindly create a second link. */
    public function rampexFind(array $c,array $o): ?array
    {
        for ($offset=0;$offset<10000;$offset+=100) {
            $r=$this->request($c,'GET','/api-list-payment-links?limit=100&offset='.$offset);
            if (($r['success'] ?? false)!==true || !is_array($r['data']['links'] ?? null)) throw new \RuntimeException('Rampex lookup unavailable.');
            foreach ($r['data']['links'] as $link) {
                if (($link['description'] ?? '')==='Payment '.$o['id'] && (($o['remote_id'] ?? '')==='' || ($link['link_id'] ?? '')===$o['remote_id'])) return $link;
            }
            if (count($r['data']['links'])<100) return null;
        }
        throw new \RuntimeException('Rampex reconciliation requires provider review.');
    }
    public function status(array $c,array $o): array
    {
        $pending=['status'=>'pending','payment_id'=>null]; $id=self::id($o['remote_id']);
        if ($c['gateway']==='stripe') {
            $s=$this->request($c,'GET','/v1/checkout/sessions/'.$id.'?expand[]=payment_intent.latest_charge');
            $this->stripeSession($c,$o,$s);
            if (($s['payment_status'] ?? '')!=='paid' || ($s['status'] ?? '')!=='complete') return $pending;
            $p=$s['payment_intent'] ?? []; $charge=$p['latest_charge'] ?? [];
            if (($p['status'] ?? '')!=='succeeded' || ($p['amount_received'] ?? null)!==(int)$o['amount_minor'] || strtoupper($p['currency'] ?? '')!==$o['currency'] || ($p['livemode'] ?? null)!==($c['mode']==='live') || ($p['metadata']['am_order'] ?? '')!==$o['id'] || ($charge['paid'] ?? false)!==true || ($charge['captured'] ?? false)!==true || ($charge['amount_refunded'] ?? -1)!==0 || ($charge['disputed'] ?? true)!==false || ($charge['payment_intent'] ?? '')!==($p['id'] ?? null) || ($charge['amount'] ?? null)!==(int)$o['amount_minor'] || strtoupper($charge['currency'] ?? '')!==$o['currency']) throw new \RuntimeException('Stripe capture requires review.');
            $payment=self::id($p['id'] ?? null);
        } elseif ($c['gateway']==='paypal') {
            $r=$this->request($c,'GET','/v2/checkout/orders/'.$id); $u=$this->paypalOrder($c,$o,$r);
            if (($r['status'] ?? '')==='APPROVED') {
                $this->request($c,'POST','/v2/checkout/orders/'.$id.'/capture',[],$o['id'].'-cap');
                $r=$this->request($c,'GET','/v2/checkout/orders/'.$id); $u=$this->paypalOrder($c,$o,$r);
            }
            if (($r['status'] ?? '')!=='COMPLETED') return $pending;
            $captures=$u['payments']['captures'] ?? [];
            if (count($captures)!==1) throw new \RuntimeException('PayPal split capture requires review.');
            $payment=self::id($captures[0]['id'] ?? null);
            $p=$this->request($c,'GET','/v2/payments/captures/'.$payment);
            if (($p['id'] ?? '')!==$payment || ($p['status'] ?? '')!=='COMPLETED' || ($p['final_capture'] ?? false)!==true || ($p['amount']['currency_code'] ?? '')!==$o['currency'] || self::minor($p['amount']['value'] ?? null)!==(int)$o['amount_minor'] || ($p['supplementary_data']['related_ids']['order_id'] ?? '')!==$id) throw new \RuntimeException('PayPal capture requires review.');
        } else {
            $p=$this->rampexFind($c,$o);
            if (!$p || ($p['status'] ?? '')!=='completed') return $pending;
            if (($p['link_id'] ?? '')!==$id || ($p['currency'] ?? '')!==$o['currency'] || self::minor($p['amount'] ?? null)!==(int)$o['amount_minor'] || self::minor($p['received_amount'] ?? null)<(int)$o['amount_minor'] || empty($p['paid_at'])) throw new \RuntimeException('Rampex settlement requires review.');
            $payment=$id;
        }
        return ['status'=>$c['mode']==='sandbox'?'sandbox_verified':'paid','payment_id'=>$c['gateway'].':'.$c['mode'].':'.$c['merchant_id'].':'.$payment];
    }
    public function webhook(array $c,string $raw,array $headers): array
    {
        if (strlen($raw)>65536) throw new \InvalidArgumentException('Notification too large.');
        $secret=$c['webhook'];
        if ($c['gateway']==='stripe') {
            $parts=[]; foreach (explode(',',$headers['stripe-signature'] ?? '') as $part) { $pair=explode('=',$part,2); if (count($pair)===2) $parts[$pair[0]][]=$pair[1]; }
            $t=$parts['t'][0] ?? '';
            if (!preg_match('/^[0-9]{1,12}$/D',$t) || abs(time()-(int)$t)>300) throw new \InvalidArgumentException('Invalid signature timestamp.');
            $expected=hash_hmac('sha256',$t.'.'.$raw,$secret); $valid=false;
            foreach ($parts['v1'] ?? [] as $signature) $valid=$valid || hash_equals($expected,$signature);
            if (!$valid) throw new \InvalidArgumentException('Invalid signature.');
        } elseif ($c['gateway']==='rampex') {
            if (!hash_equals(hash_hmac('sha256',$raw,$secret),$headers['x-rampex-signature'] ?? $headers['x-webhook-signature'] ?? '')) throw new \InvalidArgumentException('Invalid signature.');
        }
        $event=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($event)) throw new \InvalidArgumentException('Invalid notification.');
        if ($c['gateway']==='paypal') {
            $body=['webhook_id'=>$secret,'webhook_event'=>$event];
            foreach (['auth_algo','cert_url','transmission_id','transmission_sig','transmission_time'] as $field) {
                $v=$headers['paypal-'.str_replace('_','-',$field)] ?? '';
                if (!is_string($v) || $v==='' || strlen($v)>2048) throw new \InvalidArgumentException('Missing PayPal signature.');
                $body[$field]=$v;
            }
            if (($this->request($c,'POST','/v1/notifications/verify-webhook-signature',$body)['verification_status'] ?? '')!=='SUCCESS') throw new \InvalidArgumentException('Invalid PayPal signature.');
        }
        return $event;
    }
}
