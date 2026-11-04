<?php
declare(strict_types=1);
namespace AmAccount;

/** AM-owned Square adapter, following Hub CardGateway at 1effc230. */
final class Square
{
    public function __construct(private ?\Closure $transport = null) {}

    private function request(array $connection, string $path, ?array $body = null): array
    {
        if (!in_array($connection['mode'], ['sandbox', 'live'], true)) throw new \RuntimeException('Invalid Square environment.');
        $host = $connection['mode'] === 'sandbox' ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';
        $headers = ['Authorization: Bearer ' . $connection['token'], 'Square-Version: 2026-09-16', 'Content-Type: application/json', 'Accept: application/json'];
        if ($this->transport) return ($this->transport)($path, $body, $connection);
        $raw = '';
        $curl = curl_init($host . $path);
        curl_setopt_array($curl, [CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
            CURLOPT_WRITEFUNCTION => static function ($handle, $chunk) use (&$raw) {
                if (strlen($raw) + strlen($chunk) > 262144) return 0;
                $raw .= $chunk; return strlen($chunk);
            }]);
        if ($body !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR)]);
        $ok = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        if ($ok === false || !in_array($status, [200, 201], true)) {
            throw new \RuntimeException('Square request could not be confirmed. Check the environment and permissions; retry the same test rather than creating another connection.');
        }
        try { $reply = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new \RuntimeException('Square returned an invalid response.'); }
        if (!is_array($reply) || !empty($reply['errors'])) throw new \RuntimeException('Square did not confirm the request.');
        return $reply;
    }

    public static function id(mixed $id): string
    {
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,180}$/D', $id)) throw new \InvalidArgumentException('Invalid Square resource ID.');
        return $id;
    }

    public function validate(string $mode, string $token, string $location): array
    {
        if (!in_array($mode, ['sandbox', 'live'], true) || strlen($token) < 16 || strlen($token) > 512 || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new \InvalidArgumentException('Enter a valid Square access token and environment.');
        }
        self::id($location);
        $c = ['mode' => $mode, 'token' => $token];
        $m = $this->request($c, '/v2/merchants/me')['merchant'] ?? [];
        $l = $this->request($c, '/v2/locations/' . rawurlencode($location))['location'] ?? [];
        $merchant = self::id($m['id'] ?? null);
        if (($m['status'] ?? '') !== 'ACTIVE' || ($l['id'] ?? '') !== $location || ($l['status'] ?? '') !== 'ACTIVE'
            || ($l['merchant_id'] ?? '') !== $merchant || ($l['country'] ?? '') !== ($m['country'] ?? null)
            || !in_array($l['currency'] ?? '', ['CAD', 'USD'], true)
            || !in_array('CREDIT_CARD_PROCESSING', $l['capabilities'] ?? [], true)) {
            throw new \InvalidArgumentException('Square needs an active CAD/USD card-enabled location owned by this merchant.');
        }
        return ['merchant_id' => $merchant, 'location_id' => $location, 'currency' => $l['currency'],
            'business_name' => substr((string)($m['business_name'] ?? 'Square merchant'), 0, 120), 'mode' => $mode];
    }

    private function checkOrder(array $c, array $o, array $remote): void
    {
        if ((!empty($o['square_order_id']) && ($remote['id'] ?? null) !== $o['square_order_id'])
            || ($remote['reference_id'] ?? null) !== $o['id'] || ($remote['location_id'] ?? null) !== $c['location_id']
            || ($remote['total_money']['amount'] ?? null) !== (int)$o['amount_minor'] || ($remote['total_money']['currency'] ?? null) !== $o['currency']) {
            throw new \RuntimeException('Square order does not match the saved test invoice.');
        }
    }

    public function create(array $c, array $o, string $returnUrl): array
    {
        $reply = $this->request($c, '/v2/online-checkout/payment-links', [
            'idempotency_key' => $o['id'], 'order' => ['location_id' => $c['location_id'], 'reference_id' => $o['id'],
                'line_items' => [['name' => 'AM gateway setup test (no service renewal)', 'quantity' => '1',
                    'base_price_money' => ['amount' => (int)$o['amount_minor'], 'currency' => $o['currency']]]]],
            'checkout_options' => ['allow_tipping' => false, 'ask_for_shipping_address' => false, 'redirect_url' => $returnUrl,
                'accepted_payment_methods' => ['apple_pay' => true, 'google_pay' => true, 'cash_app_pay' => false, 'afterpay_clearpay' => false]]]);
        $link = $reply['payment_link'] ?? [];
        $id = self::id($link['order_id'] ?? null);
        $remote = $this->request($c, '/v2/orders/' . rawurlencode($id))['order'] ?? [];
        $this->checkOrder($c, array_replace($o, ['square_order_id' => $id]), $remote);
        $url = $link['url'] ?? null; $parts = is_string($url) ? parse_url($url) : false;
        $hosts = ['square.link', 'checkout.square.site'];
        if ($c['mode'] === 'sandbox') $hosts = array_merge($hosts, ['sandbox.square.link', 'sandbox.checkout.square.site']);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !in_array($parts['host'] ?? '', $hosts, true)
            || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new \RuntimeException('Square returned an untrusted checkout URL.');
        }
        return ['square_order_id' => $id, 'link_id' => self::id($link['id'] ?? null), 'checkout_url' => $url];
    }

    public function status(array $c, array $o): array
    {
        $id = self::id($o['square_order_id']);
        $remote = $this->request($c, '/v2/orders/' . rawurlencode($id))['order'] ?? [];
        $this->checkOrder($c, $o, $remote);
        $pending = ['status' => 'pending', 'payment_id' => null];
        if (!in_array($remote['state'] ?? '', ['OPEN', 'COMPLETED'], true) || ($remote['net_amount_due_money']['amount'] ?? 0) !== 0) return $pending;
        $tenders = $remote['tenders'] ?? [];
        if (!$tenders) return $pending;
        if (count($tenders) !== 1) throw new \RuntimeException('Split payments require manual review.');
        $pid = self::id($tenders[0]['payment_id'] ?? null);
        $p = $this->request($c, '/v2/payments/' . rawurlencode($pid))['payment'] ?? [];
        if (($p['status'] ?? '') !== 'COMPLETED') return $pending;
        $mismatches = [];
        if (($p['id'] ?? '') !== $pid) $mismatches[] = 'payment ID';
        if (($p['order_id'] ?? '') !== $id) $mismatches[] = 'order ID';
        if (($p['location_id'] ?? '') !== $c['location_id']) $mismatches[] = 'location';
        if (($p['amount_money']['amount'] ?? null) !== (int)$o['amount_minor']) $mismatches[] = 'amount';
        if (($p['amount_money']['currency'] ?? '') !== $o['currency']) $mismatches[] = 'currency';
        if (($p['refunded_money']['amount'] ?? 0) !== 0) $mismatches[] = 'refund';
        if (($p['tip_money']['amount'] ?? 0) !== 0) $mismatches[] = 'tip';
        if ($mismatches) throw new \RuntimeException('Square payment mismatch: ' . implode(', ', $mismatches) . '. Keep this invoice; do not pay again.');
        // Square hosted-checkout Sandbox Testing Panel can produce EXTERNAL payments,
        // not captured cards. Record a separate test-only result, never live settlement.
        if ($c['mode'] === 'sandbox' && ($p['source_type'] ?? '') === 'EXTERNAL') {
            return ['status' => 'sandbox_verified', 'payment_id' => $pid];
        }
        if (($p['source_type'] ?? '') !== 'CARD' || ($p['card_details']['status'] ?? '') !== 'CAPTURED') {
            $enum = static fn($value): string => is_string($value) && preg_match('/^[A-Z_]{1,40}$/D', $value) ? $value : 'missing/invalid';
            throw new \RuntimeException('Square payment source is ' . $enum($p['source_type'] ?? null)
                . '; card status is ' . $enum($p['card_details']['status'] ?? null) . '. Expected CARD / CAPTURED. Keep this invoice; do not pay again.');
        }
        return ['status' => 'paid', 'payment_id' => $pid];
    }
}

final class SquareStore
{
    private \PDO $db;
    private string $key;

    public function __construct(private array $config, private Square $api)
    {
        if (!is_file($config['database']) || !is_file($config['secret_key'])) throw new \RuntimeException('Install the gateway update first.');
        $this->key = file_get_contents($config['secret_key']);
        if (strlen($this->key) !== 32) throw new \RuntimeException('Gateway encryption key is unavailable.');
        $this->db = new \PDO('sqlite:' . $config['database'], null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout=5000');
    }

    public static function migrate(string $path): void
    {
        if (!is_file($path)) throw new \RuntimeException('Install settings storage first.');
        $db = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $db->beginTransaction();
        $db->exec('CREATE TABLE IF NOT EXISTS am_square_connections (id TEXT PRIMARY KEY, reseller_id TEXT NOT NULL, merchant_id TEXT NOT NULL, location_id TEXT NOT NULL, business_name TEXT NOT NULL, mode TEXT NOT NULL, currency TEXT NOT NULL, token_cipher TEXT NOT NULL, webhook_cipher TEXT, webhook_seen INTEGER, created_at INTEGER NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS am_square_merchants (mode TEXT NOT NULL, merchant_id TEXT NOT NULL, reseller_id TEXT NOT NULL, PRIMARY KEY(mode,merchant_id))');
        $db->exec('CREATE TABLE IF NOT EXISTS am_square_tests (id TEXT PRIMARY KEY, reseller_id TEXT NOT NULL, connection_id TEXT NOT NULL UNIQUE, amount_minor INTEGER NOT NULL, currency TEXT NOT NULL, square_order_id TEXT, link_id TEXT, checkout_url TEXT, status TEXT NOT NULL, payment_id TEXT UNIQUE, created_at INTEGER NOT NULL)');
        $db->commit();
    }

    private function one(string $sql, array $params): ?array
    {
        $q = $this->db->prepare($sql); $q->execute($params); return $q->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function seal(string $plain, string $context): string
    {
        $nonce = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, $context);
        if ($cipher === false) throw new \RuntimeException('Could not protect gateway credentials.');
        return base64_encode($nonce . $tag . $cipher);
    }

    private function unseal(string $cipher, string $context): string
    {
        $raw = base64_decode($cipher, true);
        if ($raw === false || strlen($raw) < 29) throw new \RuntimeException('Gateway credentials are unavailable.');
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $context);
        if ($plain === false) throw new \RuntimeException('Gateway credentials could not be authenticated.');
        return $plain;
    }

    public function connections(string $reseller): array
    {
        $q = $this->db->prepare('SELECT id,merchant_id,location_id,business_name,mode,currency,webhook_seen,webhook_cipher IS NOT NULL AS webhook_saved FROM am_square_connections WHERE reseller_id=? ORDER BY created_at DESC,rowid DESC LIMIT 20');
        $q->execute([$reseller]); return $q->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function connection(string $reseller, string $id): array
    {
        $c = $this->one('SELECT * FROM am_square_connections WHERE reseller_id=? AND id=?', [$reseller, $id]);
        if (!$c) throw new \InvalidArgumentException('Square connection not found for this reseller.');
        $c['token'] = $this->unseal($c['token_cipher'], $reseller . ':' . $id . ':token');
        return $c;
    }

    public function connect(string $reseller, string $mode, string $token, string $location): void
    {
        $merchant = $this->api->validate($mode, $token, $location);
        $id = bin2hex(random_bytes(16));
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT OR IGNORE INTO am_square_merchants VALUES (?,?,?)')->execute([$mode, $merchant['merchant_id'], $reseller]);
            $owner = $this->one('SELECT reseller_id FROM am_square_merchants WHERE mode=? AND merchant_id=?', [$mode, $merchant['merchant_id']]);
            if ($owner['reseller_id'] !== $reseller) throw new \InvalidArgumentException('This Square merchant is already assigned to another reseller.');
            // Repeated form submissions do not create another connection/test invoice.
            $old = $this->one('SELECT * FROM am_square_connections WHERE reseller_id=? AND mode=? AND merchant_id=? AND location_id=? ORDER BY rowid DESC LIMIT 1', [$reseller, $mode, $merchant['merchant_id'], $location]);
            if ($old) {
                $this->db->prepare('UPDATE am_square_connections SET token_cipher=? WHERE id=?')->execute([$this->seal($token, $reseller . ':' . $old['id'] . ':token'), $old['id']]);
            } else {
                $this->db->prepare('INSERT INTO am_square_connections (id,reseller_id,merchant_id,location_id,business_name,mode,currency,token_cipher,created_at) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$id,$reseller,$merchant['merchant_id'],$location,$merchant['business_name'],$mode,$merchant['currency'],$this->seal($token, $reseller . ':' . $id . ':token'),time()]);
            }
            $this->db->commit();
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }

    public function webhookUrl(string $id): string { return $this->config['public_url'] . '/square-webhook.php?connection=' . rawurlencode($id); }

    public function saveWebhook(string $reseller, string $id, string $secret): void
    {
        $this->connection($reseller, $id);
        if (strlen($secret) < 16 || strlen($secret) > 512 || preg_match('/[\x00-\x20\x7f]/', $secret)) throw new \InvalidArgumentException('Enter a valid webhook signature key.');
        $this->db->prepare('UPDATE am_square_connections SET webhook_cipher=?,webhook_seen=NULL WHERE reseller_id=? AND id=?')
            ->execute([$this->seal($secret, $reseller . ':' . $id . ':webhook'), $reseller, $id]);
    }

    public function test(string $reseller, string $connection): ?array
    {
        return $this->one('SELECT * FROM am_square_tests WHERE reseller_id=? AND connection_id=?', [$reseller, $connection]);
    }

    public function checkout(string $reseller, string $connection, bool $confirmLive): void
    {
        $c = $this->connection($reseller, $connection);
        if ($c['mode'] === 'live' && !$confirmLive) throw new \InvalidArgumentException('Confirm the real 1.00 charge before opening a production test checkout.');
        if (!$c['webhook_cipher']) throw new \InvalidArgumentException('Save the webhook signature key before testing checkout.');
        $this->db->prepare('INSERT OR IGNORE INTO am_square_tests (id,reseller_id,connection_id,amount_minor,currency,status,created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([bin2hex(random_bytes(16)), $reseller, $connection, 100, $c['currency'], 'pending', time()]);
        $o = $this->test($reseller, $connection);
        if ($o['checkout_url'] || in_array($o['status'], ['paid', 'sandbox_verified'], true)) return;
        $link = $this->api->create($c, $o, $this->config['public_url'] . '/?square_return=1');
        $this->db->prepare('UPDATE am_square_tests SET square_order_id=?,link_id=?,checkout_url=? WHERE id=? AND reseller_id=?')
            ->execute([$link['square_order_id'],$link['link_id'],$link['checkout_url'],$o['id'],$reseller]);
    }

    public function refresh(string $reseller, string $connection): void
    {
        $c = $this->connection($reseller, $connection); $o = $this->test($reseller, $connection);
        if (!$o || !$o['square_order_id']) throw new \InvalidArgumentException('Create or retry the original checkout first.');
        try {
            $status = $this->api->status($c, $o);
            if (in_array($o['status'], ['paid', 'sandbox_verified'], true) && $status['status'] !== $o['status']) throw new \RuntimeException('Previously verified payment now requires review.');
            // A slower pending lookup must never reopen a checkout verified by a concurrent webhook.
            $this->db->prepare("UPDATE am_square_tests SET status=CASE WHEN payment_id IS NOT NULL AND ?='pending' THEN 'review' ELSE ? END,payment_id=COALESCE(?,payment_id) WHERE id=? AND reseller_id=?")
                ->execute([$status['status'],$status['status'],$status['payment_id'],$o['id'],$reseller]);
        } catch (\Throwable $error) {
            $this->db->prepare("UPDATE am_square_tests SET status='review' WHERE id=? AND reseller_id=?")->execute([$o['id'],$reseller]);
            throw $error;
        }
    }

    public function webhook(string $id, string $raw, string $signature): void
    {
        $c = $this->one('SELECT * FROM am_square_connections WHERE id=?', [$id]);
        if (!$c || !$c['webhook_cipher'] || strlen($raw) > 65536 || strlen($signature) > 1024) throw new \InvalidArgumentException('Invalid notification.');
        $secret = $this->unseal($c['webhook_cipher'], $c['reseller_id'] . ':' . $id . ':webhook');
        if (!hash_equals(base64_encode(hash_hmac('sha256', $this->webhookUrl($id) . $raw, $secret, true)), $signature)) throw new \InvalidArgumentException('Invalid signature.');
        $event = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (($event['merchant_id'] ?? '') !== $c['merchant_id']) throw new \InvalidArgumentException('Merchant mismatch.');
        $this->db->prepare('UPDATE am_square_connections SET webhook_seen=? WHERE id=?')->execute([time(), $id]);
        if (!in_array($event['type'] ?? '', ['payment.created', 'payment.updated'], true)) return;
        $payment = $event['data']['object']['payment'] ?? [];
        $o = $this->test($c['reseller_id'], $id);
        if (!$o || !$o['square_order_id'] || ($payment['order_id'] ?? '') !== $o['square_order_id']) return;
        // Notifications trigger a fresh API lookup; their content never proves settlement.
        $this->refresh($c['reseller_id'], $id);
    }
}
