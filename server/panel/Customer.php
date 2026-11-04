<?php
declare(strict_types=1);
namespace AmAccount;

final class CustomerError extends \RuntimeException
{
    public function __construct(public int $status, string $message) { parent::__construct($message); }
}

/** Customer-only API. Worker responses and merchant credentials never pass through. */
final class CustomerService
{
    private \PDO $db;
    private string $key;
    private SquareStore $merchants;
    private SettingsStore $settings;
    private PricingStore $prices;

    public function __construct(private array $config, private \Closure $transport, private Square $square)
    {
        if (!is_file($config['database']) || !is_file($config['secret_key'])) throw new \RuntimeException('Customer API not installed.');
        $this->db = new \PDO('sqlite:' . $config['database'], null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->key = file_get_contents($config['secret_key']);
        if (strlen($this->key) !== 32) throw new \RuntimeException('Invalid API key storage.');
        $this->merchants = new SquareStore($config, $square);
        $this->settings = new SettingsStore($config['database']);
        $this->prices = new PricingStore($config['database']);
    }

    public static function migrate(string $path): void
    {
        if (!is_file($path)) throw new \RuntimeException('Settings not installed.');
        $db = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $db->beginTransaction();
        $db->exec('CREATE TABLE IF NOT EXISTS am_customer_sessions (token_hash TEXT PRIMARY KEY,reseller_id TEXT NOT NULL,line_id TEXT NOT NULL,auth_revision TEXT NOT NULL,expires INTEGER NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS am_customer_access (reseller_id TEXT NOT NULL,line_id TEXT NOT NULL,enabled INTEGER NOT NULL,username TEXT NOT NULL,PRIMARY KEY(reseller_id,line_id))');
        $db->exec('CREATE TABLE IF NOT EXISTS am_customer_limits (bucket TEXT PRIMARY KEY,window INTEGER NOT NULL,hits INTEGER NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS am_customer_quotes (id TEXT PRIMARY KEY,reseller_id TEXT NOT NULL,line_id TEXT NOT NULL,auth_revision TEXT NOT NULL,package_id INTEGER NOT NULL,currency TEXT NOT NULL,amount_minor INTEGER NOT NULL,price_revision INTEGER NOT NULL,connection_id TEXT,mode TEXT NOT NULL,worker_request TEXT NOT NULL,package_name TEXT NOT NULL,expires INTEGER NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS am_customer_orders (id TEXT PRIMARY KEY,quote_id TEXT UNIQUE NOT NULL,reseller_id TEXT NOT NULL,line_id TEXT NOT NULL,auth_revision TEXT NOT NULL,package_id INTEGER NOT NULL,currency TEXT NOT NULL,amount_minor INTEGER NOT NULL,connection_id TEXT,mode TEXT NOT NULL,worker_request TEXT NOT NULL,package_name TEXT NOT NULL,state TEXT NOT NULL,payment_verified INTEGER NOT NULL DEFAULT 0,payment_id TEXT UNIQUE,square_order_id TEXT,link_id TEXT,checkout_url TEXT,active_line TEXT UNIQUE,last_error TEXT,created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL)');
        $db->commit();
    }

    private function query(string $sql, array $params = []): \PDOStatement { $q = $this->db->prepare($sql); $q->execute($params); return $q; }
    private function one(string $sql, array $params): ?array { return $this->query($sql,$params)->fetch(\PDO::FETCH_ASSOC) ?: null; }
    private function worker(string $route, array $body): array
    {
        $r = ($this->transport)($route,$body);
        if (($r['ok'] ?? false) !== true) throw new CustomerError(409, 'Provider could not authorize this operation. Refresh your account or contact your provider.');
        return $r;
    }

    private function limit(string $bucket, int $max): void
    {
        $bucket = hash_hmac('sha256', $bucket, $this->key); $window = intdiv(time(),300);
        $this->query('INSERT INTO am_customer_limits VALUES (?,?,1) ON CONFLICT(bucket) DO UPDATE SET hits=CASE WHEN window=excluded.window THEN hits+1 ELSE 1 END,window=excluded.window',[$bucket,$window]);
        if ((int)$this->one('SELECT hits FROM am_customer_limits WHERE bucket=?',[$bucket])['hits'] > $max) throw new CustomerError(429,'Too many requests. Try again in a few minutes.');
    }

    private function context(string $reseller, string $line, ?string $revision = null): array
    {
        $s = $this->settings->read($reseller)['settings'];
        $access = $this->one('SELECT enabled FROM am_customer_access WHERE reseller_id=? AND line_id=?',[$reseller,$line]);
        if (!$s['app_enabled'] || ($access && (int)$access['enabled'] === 0) || ($s['customer_policy'] !== 'all' && (int)($access['enabled'] ?? 0) !== 1)) throw new CustomerError(403,'Your provider has not enabled AM access for this service.');
        $r = $this->worker('/am/customer',['reseller_id'=>$reseller,'line_id'=>$line]);
        if (($r['reseller_id'] ?? '') !== $reseller || ($r['line']['line_id'] ?? '') !== $line || !is_string($r['auth_revision'] ?? null)) throw new CustomerError(403,'Service ownership could not be verified.');
        if ($revision !== null && !hash_equals($revision,$r['auth_revision'])) throw new CustomerError(401,'Your service login changed. Sign in again.');
        return ['reseller'=>$reseller,'line'=>$r['line'],'auth_revision'=>$r['auth_revision'],'settings'=>$s];
    }

    private function authorizeRenewal(array $ctx, int $package, array $catalogue): array
    {
        $p = $catalogue[$package] ?? null;
        // Customer checkout is renewal-only, regardless of legacy change permissions.
        if (empty($ctx['line']['package_id'])) throw new CustomerError(403,'No package is assigned to this service. Contact your reseller to assign the correct package.');
        if ($package !== $ctx['line']['package_id']) throw new CustomerError(403,'Only your currently assigned package can be renewed. A price for a different package does not enable renewal of this service.');
        if (!empty($ctx['line']['activated']) && (int)($ctx['line']['expire_at'] ?? -1) === 0) throw new CustomerError(403,'This unlimited service has no expiry date and does not need renewal.');
        if (!$ctx['settings']['allow_renewals']) throw new CustomerError(403,'The general customer renewal permission is off. Your reseller must enable Renew or extend current subscription and save AM settings.');
        if (!in_array($ctx['settings']['billing_mode'],['integrated','both'],true)) throw new CustomerError(403,'Integrated billing is not selected. Your reseller must choose Integrated gateway checkout or Both billing methods and save AM settings.');
        if (!in_array('square',$ctx['settings']['gateways'],true)) throw new CustomerError(403,'Square is not selected in the reseller gateway preferences.');
        if (!$p) throw new CustomerError(403,'Your assigned package is not currently offered in the reseller catalogue.');
        if ((string)$p['package_type'] === '1') throw new CustomerError(403,'This service is assigned a trial package. Contact your reseller to move it to a paid package.');
        if ($p['max_connections'] !== $ctx['line']['max_connections']) throw new CustomerError(403,'Your service connection limit differs from its assigned package. Your reseller must resolve that mismatch before renewal.');
        return $p;
    }

    private function catalogue(string $reseller): array { return PricingStore::catalogue($this->worker('/am/packages',['reseller_id'=>$reseller])); }

    public function login(array $input, string $ip): array
    {
        $this->limit('ip:' . $ip,30);
        $identity = $input['activation_code'] ?? $input['username'] ?? '';
        if (!is_string($identity) || strlen($identity)>255) throw new CustomerError(422,'Invalid login.');
        $this->limit('login:' . $identity,10);
        $this->query('DELETE FROM am_customer_limits WHERE window<?',[intdiv(time(),300)-12]);
        $this->query('DELETE FROM am_customer_sessions WHERE expires<?',[time()]);
        $r = ($this->transport)('/am/customer-auth',$input);
        if (($r['ok'] ?? false) !== true) throw new CustomerError(401,'Your login could not be verified.');
        $ctx = $this->context($r['reseller_id'],$r['line']['line_id'],$r['auth_revision']);
        $token = bin2hex(random_bytes(32));
        $this->query('INSERT INTO am_customer_sessions VALUES (?,?,?,?,?)',[hash_hmac('sha256',$token,$this->key),$ctx['reseller'],$ctx['line']['line_id'],$ctx['auth_revision'],time()+86400*7]);
        return ['token'=>$token,'account'=>$this->account($ctx)];
    }

    public function session(string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) throw new CustomerError(401,'Sign in again.');
        $row = $this->one('SELECT * FROM am_customer_sessions WHERE token_hash=? AND expires>?',[hash_hmac('sha256',$token,$this->key),time()]);
        if (!$row) throw new CustomerError(401,'Sign in again.');
        $this->limit('session:' . $token,120);
        return $this->context($row['reseller_id'],$row['line_id'],$row['auth_revision']);
    }

    public function logout(string $token): void { $this->query('DELETE FROM am_customer_sessions WHERE token_hash=?',[hash_hmac('sha256',$token,$this->key)]); }

    public function account(array $ctx): array
    {
        $line = $ctx['line']; unset($line['line_fingerprint']);
        $offers = []; $reasons = []; $catalogue = $this->catalogue($ctx['reseller']);
        $rows = array_filter($this->prices->rows($ctx['reseller']),fn($row)=>(int)$row['package_id'] === ($line['package_id'] ?? null));
        try {
            $this->authorizeRenewal($ctx,(int)($line['package_id'] ?? 0),$catalogue);
            if (!$rows) $reasons[] = 'No renewal price is saved for the package assigned to this service. A price for another package or connection variant does not apply.';
        } catch (CustomerError $error) { $reasons[] = $error->getMessage(); $rows = []; }
        foreach ($rows as $row) {
            try {
                $p = $this->authorizeRenewal($ctx,(int)$row['package_id'],$catalogue);
                $q = $this->prices->quote($ctx['reseller'],(int)$row['package_id'],$row['currency'],$catalogue);
                if ($q['amount_minor'] > 0) $this->merchants->forCheckout($ctx['reseller'],$ctx['settings']['square_mode'],$row['currency']);
                $offers[] = ['package_id'=>$p['id'],'name'=>$p['name'],'duration'=>$p['duration'],'duration_period'=>$p['duration_period'],'max_connections'=>$p['max_connections'],'currency'=>$q['currency'],'amount_minor'=>$q['amount_minor']];
            } catch (CustomerError | \InvalidArgumentException $error) { $reasons[] = $error->getMessage(); }
        }
        return ['service'=>$line,'provider'=>$ctx['settings']['display_name'],'support_email'=>$ctx['settings']['support_email'],
                'renewal_mode'=>$ctx['settings']['square_mode'],'allow_bouquets'=>(bool)$ctx['settings']['allow_bouquets'],'offers'=>$offers,
                'renewal_unavailable_message'=>$offers ? null : implode(' ',array_unique($reasons)),'server_time'=>time()];
    }

    public function renewalDiagnostic(string $reseller, string $line): array
    {
        if (!preg_match('/^[1-9][0-9]{0,17}$/D',$line)) throw new CustomerError(422,'Enter a valid customer service ID.');
        $ctx = $this->context($reseller,$line);
        $account = $this->account($ctx);
        return ['line_id'=>$line,'package_id'=>$ctx['line']['package_id'],'package_name'=>$ctx['line']['package_name'] ?? '',
            'connections'=>$ctx['line']['max_connections'],'mode'=>$ctx['settings']['square_mode'],
            'message'=>$account['renewal_unavailable_message'] ?? 'Renewal offers are available. Refresh the app to load the latest saved settings.',
            'offers'=>array_map(fn($o)=>$o['currency'],$account['offers'])];
    }

    public function bouquets(array $ctx, array $input): array
    {
        if (!$ctx['settings']['allow_bouquets']) throw new CustomerError(403,'Your reseller has not enabled bouquet editing.');
        $ids = $input['bouquet_ids'] ?? null; $revision = $input['revision'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || count($ids)>2000 || !is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D',$revision)) throw new CustomerError(422,'Invalid bouquet selection.');
        foreach ($ids as $id) if (!is_int($id) || $id<0) throw new CustomerError(422,'Invalid bouquet ID.');
        if (count(array_unique($ids)) !== count($ids)) throw new CustomerError(422,'Duplicate bouquet IDs.');
        $r = $this->worker('/am/customer-bouquets',['reseller_id'=>$ctx['reseller'],'line_id'=>$ctx['line']['line_id'],
            'auth_revision'=>$ctx['auth_revision'],'bouquet_ids'=>$ids,'revision'=>$revision]);
        $fresh = $this->context($ctx['reseller'],$ctx['line']['line_id'],$ctx['auth_revision']);
        return ['account'=>$this->account($fresh),'refresh_pending'=>(bool)($r['refresh_pending'] ?? true)];
    }

    public function quote(array $ctx, int $package, string $currency): array
    {
        if (!in_array($currency,['CAD','USD'],true)) throw new CustomerError(422,'Invalid currency.');
        $catalogue = $this->catalogue($ctx['reseller']); $p = $this->authorizeRenewal($ctx,$package,$catalogue);
        $price = $this->prices->quote($ctx['reseller'],$package,$currency,$catalogue);
        $q = $this->worker('/am/renew-quote',['reseller_id'=>$ctx['reseller'],'line_id'=>$ctx['line']['line_id'],'package_id'=>$package]);
        $quoted = PricingStore::catalogue(['ok'=>true,'packages'=>[$q['package']]])[$package] ?? null;
        if (!$quoted || $quoted['fingerprint'] !== $p['fingerprint']) throw new CustomerError(409,'Package changed. Refresh renewal options.');
        $mode = $ctx['settings']['square_mode'];
        $c = $price['amount_minor'] > 0 ? $this->merchants->forCheckout($ctx['reseller'],$mode,$currency) : null;
        $id = bin2hex(random_bytes(16));
        $request = ['reseller_id'=>$ctx['reseller'],'line_id'=>$ctx['line']['line_id'],'package_id'=>$package,
                    'line_fingerprint'=>$q['line_fingerprint'],'package_fingerprint'=>$q['package']['worker_fingerprint']];
        $this->query('DELETE FROM am_customer_quotes WHERE expires<?',[time()-86400]);
        $this->query('INSERT INTO am_customer_quotes VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$ctx['reseller'],$ctx['line']['line_id'],$ctx['auth_revision'],$package,$currency,$price['amount_minor'],$price['price_revision'],$c['id'] ?? null,$mode,json_encode($request,JSON_THROW_ON_ERROR),$p['name'],time()+900]);
        return ['quote_id'=>$id,'package_name'=>$p['name'],'amount_minor'=>$price['amount_minor'],'currency'=>$currency,'mode'=>$mode,'duration'=>$p['duration'],'duration_period'=>$p['duration_period'],'max_connections'=>$p['max_connections'],'receipt_label'=>'Payment'];
    }

    private function order(string $reseller, string $line, string $id): array
    {
        $o = $this->one('SELECT * FROM am_customer_orders WHERE reseller_id=? AND line_id=? AND id=?',[$reseller,$line,$id]);
        if (!$o) throw new CustomerError(404,'Order not found.');
        return $o;
    }

    public function checkout(array $ctx, string $quote): array
    {
        $existing = $this->one('SELECT * FROM am_customer_orders WHERE reseller_id=? AND line_id=? AND (quote_id=? OR active_line=?) ORDER BY created_at DESC LIMIT 1',[$ctx['reseller'],$ctx['line']['line_id'],$quote,$ctx['reseller'].':'.$ctx['line']['line_id']]);
        if ($existing) return $this->refresh($existing);
        $q = $this->one('SELECT * FROM am_customer_quotes WHERE id=? AND reseller_id=? AND line_id=? AND expires>?',[$quote,$ctx['reseller'],$ctx['line']['line_id'],time()]);
        if (!$q || !hash_equals($q['auth_revision'],$ctx['auth_revision'])) throw new CustomerError(409,'Quote expired. Refresh renewal options.');
        $catalogue = $this->catalogue($ctx['reseller']); $this->authorizeRenewal($ctx,(int)$q['package_id'],$catalogue);
        $price = $this->prices->quote($ctx['reseller'],(int)$q['package_id'],$q['currency'],$catalogue);
        if ($price['price_revision'] !== (int)$q['price_revision'] || $ctx['settings']['square_mode'] !== $q['mode']) throw new CustomerError(409,'Renewal settings changed. Request a new quote.');
        if ($q['connection_id'] !== null && $this->merchants->forCheckout($ctx['reseller'],$q['mode'],$q['currency'])['id'] !== $q['connection_id']) throw new CustomerError(409,'Merchant settings changed. Request a new quote.');
        $check = $this->worker('/am/renew-quote',['reseller_id'=>$ctx['reseller'],'line_id'=>$ctx['line']['line_id'],'package_id'=>(int)$q['package_id']]);
        $request = json_decode($q['worker_request'],true,32,JSON_THROW_ON_ERROR);
        if ($check['line_fingerprint'] !== $request['line_fingerprint'] || $check['package']['worker_fingerprint'] !== $request['package_fingerprint']) throw new CustomerError(409,'Service changed. Request a new quote.');
        $id = bin2hex(random_bytes(16)); $request['order_id'] = $id;
        $this->query('INSERT OR IGNORE INTO am_customer_orders (id,quote_id,reseller_id,line_id,auth_revision,package_id,currency,amount_minor,connection_id,mode,worker_request,package_name,state,active_line,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$id,$q['id'],$q['reseller_id'],$q['line_id'],$q['auth_revision'],$q['package_id'],$q['currency'],$q['amount_minor'],$q['connection_id'],$q['mode'],json_encode($request,JSON_THROW_ON_ERROR),$q['package_name'],'creating',$q['reseller_id'].':'.$q['line_id'],time(),time()]);
        $o = $this->one('SELECT * FROM am_customer_orders WHERE quote_id=? OR active_line=?',[$q['id'],$q['reseller_id'].':'.$q['line_id']]);
        return $this->refresh($o);
    }

    private function dto(array $o): array
    {
        return ['id'=>$o['id'],'package_name'=>$o['package_name'],'amount_minor'=>(int)$o['amount_minor'],'currency'=>$o['currency'],'mode'=>$o['mode'],'state'=>$o['state'],
                'payment_verified'=>(bool)$o['payment_verified'],'created_at'=>(int)$o['created_at'],
                'checkout_url'=>in_array($o['state'],['creating','pending'],true) && !$o['payment_verified'] ? $o['checkout_url'] : null,
                'message'=>match($o['state']) {'applied'=>(int)$o['amount_minor'] === 0 ? 'Service renewed. No customer payment was required.' : 'Payment verified and service renewed.', 'sandbox_verified'=>'Sandbox test verified. No real payment or renewal.',
                    'refresh_pending'=>'Renewal saved; service activation is processing. Do not pay again.',
                    'review','applying'=>$o['payment_verified'] ? 'Payment verified. Renewal needs provider review; do not pay again.' : 'Checkout needs verification. Retry this order; do not create another payment.',
                    default=>'Payment not yet verified.'}];
    }

    private function refresh(array $o): array
    {
        if (in_array($o['state'],['applied','sandbox_verified'],true)) return $this->dto($o);
        try {
            if (!$o['payment_verified']) {
                if ((int)$o['amount_minor'] === 0) {
                    $status = ['status'=>$o['mode']==='sandbox'?'sandbox_verified':'paid','payment_id'=>null];
                } else {
                    $c = $this->merchants->forOrder($o['reseller_id'],$o['connection_id']);
                    if (!$o['square_order_id']) {
                        $link = $this->square->create($c,$o,$this->config['public_url'].'/payment-return.php');
                        $this->query("UPDATE am_customer_orders SET square_order_id=?,link_id=?,checkout_url=?,state=CASE WHEN state='creating' THEN 'pending' ELSE state END WHERE id=?",[$link['square_order_id'],$link['link_id'],$link['checkout_url'],$o['id']]);
                        $o = $this->order($o['reseller_id'],$o['line_id'],$o['id']);
                    }
                    $status = $this->square->status($c,$o);
                }
                if ($status['status'] === 'pending') {
                    $this->query("UPDATE am_customer_orders SET state='pending' WHERE id=? AND payment_verified=0 AND payment_id IS NULL AND state IN ('creating','pending','review')",[$o['id']]);
                    return $this->dto($this->order($o['reseller_id'],$o['line_id'],$o['id']));
                }
                if ($o['mode'] === 'sandbox') {
                    $this->query("UPDATE am_customer_orders SET state='sandbox_verified',payment_verified=1,payment_id=?,active_line=NULL,updated_at=? WHERE id=?",[$status['payment_id'],time(),$o['id']]);
                    return $this->dto($this->order($o['reseller_id'],$o['line_id'],$o['id']));
                }
                if ($status['status'] !== 'paid') throw new CustomerError(409,'Live captured settlement required.');
                $this->query("UPDATE am_customer_orders SET payment_verified=1,payment_id=COALESCE(payment_id,?),state=CASE WHEN payment_verified=0 THEN 'applying' ELSE state END,updated_at=? WHERE id=?",[$status['payment_id'],time(),$o['id']]);
            }
            // A sandbox order never reaches a real service mutation, even if the
            // reseller changes its current checkout mode after creating this order.
            if ($o['mode'] !== 'live') throw new CustomerError(409,'Test orders cannot renew services.');
            $request = json_decode($o['worker_request'],true,32,JSON_THROW_ON_ERROR);
            $result = $this->worker('/am/renew-result',['reseller_id'=>$o['reseller_id'],'order_id'=>$o['id']]);
            if ($result['state'] === 'not_applied') {
                $ctx = $this->context($o['reseller_id'],$o['line_id'],$o['auth_revision']);
                $this->authorizeRenewal($ctx,(int)$o['package_id'],$this->catalogue($o['reseller_id']));
                $result = $this->worker('/am/renew',$request);
            }
            if (!in_array($result['state'] ?? '',['applied','applied_refresh_pending'],true) || ($result['order_id'] ?? '') !== $o['id']) throw new CustomerError(503,'Renewal result could not be verified.');
            $state = $result['state']==='applied'?'applied':'refresh_pending';
            $this->query("UPDATE am_customer_orders SET state=?,active_line=?,last_error=NULL,updated_at=? WHERE id=? AND state NOT IN ('applied','sandbox_verified')",[$state,$state==='applied'?null:$o['reseller_id'].':'.$o['line_id'],time(),$o['id']]);
        } catch (\Throwable $error) {
            $this->query("UPDATE am_customer_orders SET state=CASE WHEN state IN ('applied','sandbox_verified') THEN state ELSE 'review' END,last_error='Verification or renewal requires retry/review',updated_at=? WHERE id=?",[time(),$o['id']]);
        }
        return $this->dto($this->order($o['reseller_id'],$o['line_id'],$o['id']));
    }

    public function status(array $ctx, string $id): array { return $this->refresh($this->order($ctx['reseller'],$ctx['line']['line_id'],$id)); }
    public function orders(array $ctx): array
    {
        return array_map(fn($row)=>$this->dto($row),$this->query('SELECT * FROM am_customer_orders WHERE reseller_id=? AND line_id=? ORDER BY created_at DESC,rowid DESC LIMIT 50',[$ctx['reseller'],$ctx['line']['line_id']])->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function notification(string $reseller, string $connection, string $squareOrder): void
    {
        $o = $this->one('SELECT * FROM am_customer_orders WHERE reseller_id=? AND connection_id=? AND square_order_id=?',[$reseller,$connection,$squareOrder]);
        if (!$o) {
            $ref = $this->square->reference($this->merchants->forOrder($reseller,$connection),$squareOrder);
            if ($ref) $o = $this->one('SELECT * FROM am_customer_orders WHERE reseller_id=? AND connection_id=? AND id=?',[$reseller,$connection,$ref]);
        }
        if ($o) {
            $r = $this->refresh($o);
            if (in_array($r['state'],['review','applying','refresh_pending'],true)) throw new \RuntimeException('Retry notification reconciliation.');
        }
    }

    public function approve(string $reseller, string $line, bool $enabled): void
    {
        if (!preg_match('/^[1-9][0-9]{0,17}$/D',$line)) throw new CustomerError(422,'Enter a valid customer service ID.');
        $r = $this->worker('/am/customer',['reseller_id'=>$reseller,'line_id'=>$line]);
        if ($r['reseller_id'] !== $reseller || $r['line']['line_id'] !== $line) throw new CustomerError(403,'Customer ownership mismatch.');
        $this->query('INSERT INTO am_customer_access VALUES (?,?,?,?) ON CONFLICT(reseller_id,line_id) DO UPDATE SET enabled=excluded.enabled,username=excluded.username',[$reseller,$line,$enabled?1:0,$r['line']['username']]);
        if (!$enabled) $this->query('DELETE FROM am_customer_sessions WHERE reseller_id=? AND line_id=?',[$reseller,$line]);
    }

    public function resellerOrders(string $reseller): array
    {
        return array_map(fn($o)=>$this->dto($o)+['line_id'=>$o['line_id']],$this->query('SELECT * FROM am_customer_orders WHERE reseller_id=? ORDER BY created_at DESC,rowid DESC LIMIT 50',[$reseller])->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function retryOrder(string $reseller, string $id): array
    {
        $o = $this->one('SELECT * FROM am_customer_orders WHERE reseller_id=? AND id=?',[$reseller,$id]);
        if (!$o) throw new CustomerError(404,'Order not found.');
        return $this->refresh($o);
    }
}
