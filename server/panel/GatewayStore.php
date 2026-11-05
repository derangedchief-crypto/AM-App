<?php
declare(strict_types=1);
namespace AmAccount;
require_once __DIR__.'/Gateways.php';

final class GatewayStore
{
    private \PDO $db;
    private string $key;
    public function __construct(private array $config,private Gateways $api)
    {
        if (!is_file($config['database']) || !is_file($config['secret_key'])) throw new \RuntimeException('Gateway storage unavailable.');
        $this->key=file_get_contents($config['secret_key']);
        if (strlen($this->key)!==32) throw new \RuntimeException('Invalid encryption key.');
        $this->db=new \PDO('sqlite:'.$config['database'],null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout=5000');
    }
    public static function migrate(string $path): void
    {
        $db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE IF NOT EXISTS am_gateway_connections (id TEXT PRIMARY KEY,reseller_id TEXT NOT NULL,gateway TEXT NOT NULL,mode TEXT NOT NULL,currency TEXT NOT NULL,merchant_id TEXT NOT NULL,business_name TEXT NOT NULL,credentials TEXT NOT NULL,webhook TEXT,enabled INTEGER NOT NULL DEFAULT 0,created INTEGER NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS am_gateway_owners (gateway TEXT NOT NULL,mode TEXT NOT NULL,merchant_id TEXT NOT NULL,reseller_id TEXT NOT NULL,PRIMARY KEY(gateway,mode,merchant_id))');
        $db->exec('CREATE TABLE IF NOT EXISTS am_gateway_quotes (quote_id TEXT PRIMARY KEY,gateway TEXT NOT NULL,email TEXT NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS am_gateway_transactions (order_id TEXT PRIMARY KEY,reseller_id TEXT NOT NULL,connection_id TEXT NOT NULL,remote_id TEXT,checkout_url TEXT,attempted INTEGER,lease INTEGER NOT NULL DEFAULT 0,UNIQUE(connection_id,remote_id))');
    }
    private function query(string $sql,array $args=[]): \PDOStatement { $q=$this->db->prepare($sql); $q->execute($args); return $q; }
    private function seal(string $value,string $aad): string
    {
        $nonce=random_bytes(12); $tag=''; $encrypted=openssl_encrypt($value,'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$nonce,$tag,$aad);
        if ($encrypted===false) throw new \RuntimeException('Credential encryption failed.');
        return base64_encode($nonce.$tag.$encrypted);
    }
    private function open(string $value,string $aad): string
    {
        $raw=base64_decode($value,true);
        if ($raw===false || strlen($raw)<29) throw new \RuntimeException('Invalid encrypted credentials.');
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$aad);
        if ($plain===false) throw new \RuntimeException('Credentials unavailable.');
        return $plain;
    }
    public function connections(string $reseller): array
    { return $this->query('SELECT id,gateway,mode,currency,merchant_id,business_name,enabled,webhook IS NOT NULL AS webhook_saved FROM am_gateway_connections WHERE reseller_id=? ORDER BY created DESC,rowid DESC LIMIT 60',[$reseller])->fetchAll(\PDO::FETCH_ASSOC); }
    public function connection(string $reseller,string $id): array
    {
        $c=$this->query('SELECT * FROM am_gateway_connections WHERE reseller_id=? AND id=?',[$reseller,$id])->fetch(\PDO::FETCH_ASSOC);
        if (!$c) throw new \InvalidArgumentException('Gateway connection not found.');
        $aad=$reseller.':'.$id;
        $secrets=json_decode($this->open($c['credentials'],$aad),true,8,JSON_THROW_ON_ERROR);
        $c['secret']=$secrets['secret']; $c['client_id']=$secrets['client_id'];
        $c['webhook']=$c['webhook']===null?'':$this->open($c['webhook'],$aad.':webhook');
        return $c;
    }
    public function forCheckout(string $reseller,string $gateway,string $currency): array
    {
        $id=$this->query('SELECT id FROM am_gateway_connections WHERE reseller_id=? AND gateway=? AND currency=? AND enabled=1 AND webhook IS NOT NULL ORDER BY created DESC,rowid DESC LIMIT 1',[$reseller,$gateway,$currency])->fetchColumn();
        if (!$id) throw new \InvalidArgumentException(ucfirst($gateway).' needs an enabled '.$currency.' connection with webhook verification configured.');
        return $this->connection($reseller,$id);
    }
    public function connect(string $reseller,array $input): void
    {
        $c=[]; foreach (['gateway','mode','currency','secret','client_id','merchant_id'] as $key) { if (!is_string($input[$key] ?? '')) throw new \InvalidArgumentException('Invalid gateway field.'); $c[$key]=trim($input[$key] ?? ''); }
        if (!in_array($c['currency'],['USD','CAD'],true)) throw new \InvalidArgumentException('Choose CAD or USD.');
        $verified=$this->api->validate($c); $id=bin2hex(random_bytes(16));
        $this->db->beginTransaction();
        try {
            $this->query('INSERT OR IGNORE INTO am_gateway_owners VALUES (?,?,?,?)',[$c['gateway'],$c['mode'],$verified['merchant_id'],$reseller]);
            $owner=$this->query('SELECT reseller_id FROM am_gateway_owners WHERE gateway=? AND mode=? AND merchant_id=?',[$c['gateway'],$c['mode'],$verified['merchant_id']])->fetchColumn();
            if ($owner!==$reseller) throw new \InvalidArgumentException('This merchant is already assigned to another reseller.');
            $old=$this->query('SELECT id FROM am_gateway_connections WHERE reseller_id=? AND gateway=? AND mode=? AND currency=? AND merchant_id=? ORDER BY rowid DESC LIMIT 1',[$reseller,$c['gateway'],$c['mode'],$c['currency'],$verified['merchant_id']])->fetchColumn();
            if ($old && $c['gateway']==='paypal' && $this->connection($reseller,$old)['client_id']!==$c['client_id']) $old=false;
            if ($old) {
                $this->query('UPDATE am_gateway_connections SET credentials=? WHERE id=?',[$this->seal(json_encode(['secret'=>$c['secret'],'client_id'=>$c['client_id']],JSON_THROW_ON_ERROR),$reseller.':'.$old),$old]);
            } else {
                $this->query('INSERT INTO am_gateway_connections (id,reseller_id,gateway,mode,currency,merchant_id,business_name,credentials,created) VALUES (?,?,?,?,?,?,?,?,?)',[$id,$reseller,$c['gateway'],$c['mode'],$c['currency'],$verified['merchant_id'],$verified['business_name'],$this->seal(json_encode(['secret'=>$c['secret'],'client_id'=>$c['client_id']],JSON_THROW_ON_ERROR),$reseller.':'.$id),time()]);
            }
            $this->db->commit();
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    public function webhookUrl(string $id): string { return $this->config['public_url'].'/gateway-webhook.php?connection='.rawurlencode($id); }
    public function configure(string $reseller,string $id,string $secret,bool $enabled): void
    {
        $c=$this->connection($reseller,$id);
        if ($secret!=='' && (strlen($secret)<8 || strlen($secret)>512 || preg_match('/[\x00-\x20\x7f]/',$secret))) throw new \InvalidArgumentException('Invalid webhook secret or ID.');
        if ($enabled && $secret==='' && $c['webhook']==='') throw new \InvalidArgumentException('Save webhook verification before enabling checkout.');
        $this->db->beginTransaction();
        try {
            if ($enabled) $this->query('UPDATE am_gateway_connections SET enabled=0 WHERE reseller_id=? AND gateway=? AND currency=?',[$reseller,$c['gateway'],$c['currency']]);
            $this->query('UPDATE am_gateway_connections SET enabled=?,webhook=COALESCE(?,webhook) WHERE reseller_id=? AND id=?',[$enabled?1:0,$secret!==''?$this->seal($secret,$reseller.':'.$id.':webhook'):null,$reseller,$id]);
            $this->db->commit();
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    public function payment(array $o,array $q): array
    {
        $c=$this->connection($o['reseller_id'],$o['connection_id']);
        if ($c['gateway']!==$q['gateway'] || $c['mode']!==$o['mode'] || $c['currency']!==$o['currency']) throw new \RuntimeException('Saved payment context mismatch.');
        $this->query('INSERT OR IGNORE INTO am_gateway_transactions (order_id,reseller_id,connection_id) VALUES (?,?,?)',[$o['id'],$o['reseller_id'],$o['connection_id']]);
        $tx=$this->query('SELECT * FROM am_gateway_transactions WHERE order_id=?',[$o['id']])->fetch(\PDO::FETCH_ASSOC);
        if (!$tx['remote_id']) {
            if ($this->query('UPDATE am_gateway_transactions SET lease=?,attempted=COALESCE(attempted,?) WHERE order_id=? AND lease<? AND remote_id IS NULL',[time()+120,time(),$o['id'],time()])->rowCount()!==1) throw new \RuntimeException('Payment creation is already in progress.');
            try {
                $saved=array_replace($o,['remote_id'=>null,'email'=>$q['email']]);
                if ($c['gateway']==='rampex' && $tx['attempted']!==null) {
                    $found=$this->api->rampexFind($c,$saved);
                    if (!$found) throw new \RuntimeException('Rampex creation is unconfirmed. Retain this order for reconciliation.');
                    $link=['remote_id'=>Gateways::id($found['link_id'] ?? null),'checkout_url'=>Gateways::checkoutUrl('rampex','live',$found['payment_url'] ?? $found['redirect_url'] ?? null)];
                } else {
                    // Do not recreate beyond the provider's guaranteed idempotency window.
                    if ($tx['attempted']!==null && time()-(int)$tx['attempted']>($c['gateway']==='paypal'?5*3600:23*3600)) throw new \RuntimeException('Creation requires reconciliation, not another payment.');
                    $link=$this->api->create($c,$saved,$this->config['public_url'].'/payment-return.php');
                }
                $this->query('UPDATE am_gateway_transactions SET remote_id=?,checkout_url=? WHERE order_id=? AND (remote_id IS NULL OR remote_id=?)',[$link['remote_id'],$link['checkout_url'],$o['id'],$link['remote_id']]);
            } catch (PaymentRejected $e) {
                // Only a definitively rejected initial POST can be attempted again without recovery.
                if ($tx['attempted']===null && $c['gateway']!=='paypal') $this->query('UPDATE am_gateway_transactions SET attempted=NULL WHERE order_id=? AND remote_id IS NULL',[$o['id']]);
                throw $e;
            } finally { $this->query('UPDATE am_gateway_transactions SET lease=0 WHERE order_id=?',[$o['id']]); }
            $tx=$this->query('SELECT * FROM am_gateway_transactions WHERE order_id=?',[$o['id']])->fetch(\PDO::FETCH_ASSOC);
        }
        $this->query("UPDATE am_customer_orders SET checkout_url=?,state=CASE WHEN state='creating' THEN 'pending' ELSE state END WHERE id=?",[$tx['checkout_url'],$o['id']]);
        return $this->api->status($c,array_replace($o,['remote_id'=>$tx['remote_id'],'email'=>$q['email']]));
    }
    public function webhook(string $id,string $raw,array $headers,\Closure $reconcile): void
    {
        $reseller=$this->query('SELECT reseller_id FROM am_gateway_connections WHERE id=?',[$id])->fetchColumn();
        if (!$reseller) throw new \InvalidArgumentException('Unknown connection.');
        $c=$this->connection($reseller,$id);
        if ($c['webhook']==='') throw new \InvalidArgumentException('Webhook not configured.');
        $event=$this->api->webhook($c,$raw,$headers);
        $remote=null; $ref=null;
        if ($c['gateway']==='stripe') {
            if (($event['livemode'] ?? null)!==($c['mode']==='live')) throw new \InvalidArgumentException('Wrong environment.');
            if (!in_array($event['type'] ?? '',['checkout.session.completed','checkout.session.async_payment_succeeded','checkout.session.expired'],true)) return;
            $remote=$event['data']['object']['id'] ?? null; $ref=$event['data']['object']['client_reference_id'] ?? null;
        } elseif ($c['gateway']==='paypal') {
            if (!in_array($event['event_type'] ?? '',['CHECKOUT.ORDER.APPROVED','PAYMENT.CAPTURE.COMPLETED'],true)) return;
            $remote=$event['event_type']==='CHECKOUT.ORDER.APPROVED'?($event['resource']['id'] ?? null):($event['resource']['supplementary_data']['related_ids']['order_id'] ?? null);
            $ref=$event['resource']['purchase_units'][0]['custom_id'] ?? $event['resource']['custom_id'] ?? null;
        } else {
            if (($event['event'] ?? '')!=='payment.completed') return;
            $remote=$event['link_id'] ?? null;
            $description=$event['description'] ?? '';
            if (is_string($description) && preg_match('/^Payment ([a-f0-9]{32})$/D',$description,$m)) $ref=$m[1];
        }
        $remote=Gateways::id($remote);
        $order=$this->query('SELECT order_id FROM am_gateway_transactions WHERE reseller_id=? AND connection_id=? AND remote_id=?',[$reseller,$id,$remote])->fetchColumn();
        if (!$order && is_string($ref) && preg_match('/^[a-f0-9]{32}$/D',$ref)) {
            // Signed reference only locates a saved order. The adapter still verifies all payment details.
            $order=$this->query('SELECT id FROM am_customer_orders WHERE id=? AND reseller_id=? AND connection_id=?',[$ref,$reseller,$id])->fetchColumn();
            if ($order) $this->query('UPDATE am_gateway_transactions SET remote_id=? WHERE order_id=? AND remote_id IS NULL',[$remote,$order]);
        }
        if ($order) $reconcile($reseller,$order);
    }
}
