<?php
declare(strict_types=1);
require __DIR__ . '/../panel/Settings.php';
require __DIR__ . '/../panel/Square.php';
use AmAccount\SettingsStore;
use AmAccount\Square;
use AmAccount\SquareStore;

function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejects(Closure $action): void {
    try { $action(); } catch (Throwable $error) { return; }
    throw new RuntimeException('Expected operation to be rejected.');
}
$root = (getenv('TMPDIR') ?: '/tmp/opencode') . '/am-square-test-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$config = ['database' => "$root/settings.sqlite", 'secret_key' => "$root/gateway.key", 'public_url' => 'https://panel.example.com/am-app'];
$token = 'SQUARE_FAKE_TOKEN_NOT_A_REAL_SECRET'; $secret = 'SQUARE_FAKE_WEBHOOK_NOT_A_REAL_SECRET';
$merchant = 'merchant1'; $paid = false; $external = false; $mismatch = ''; $failCreate = false; $calls = []; $orders = [];
$transport = function ($path, $body, $c) use (&$merchant, &$paid, &$external, &$mismatch, &$failCreate, &$calls, &$orders): array {
    $calls[] = [$path, $body];
    if ($path === '/v2/merchants/me') return ['merchant' => ['id' => $merchant, 'status' => 'ACTIVE', 'country' => 'CA', 'business_name' => 'Fixture shop']];
    if ($path === '/v2/locations/loc1') return ['location' => ['id' => 'loc1', 'merchant_id' => $mismatch === 'merchant' ? 'other' : $merchant, 'country' => 'CA', 'currency' => 'CAD', 'status' => 'ACTIVE', 'capabilities' => ['CREDIT_CARD_PROCESSING']]];
    if ($path === '/v2/online-checkout/payment-links') {
        check($body['checkout_options']['allow_tipping'] === false, 'No tips');
        check($body['checkout_options']['accepted_payment_methods']['cash_app_pay'] === false, 'No alternate settlement');
        $id = $body['idempotency_key'];
        check($id === $body['order']['reference_id'], 'Stable reference');
        $orders['sq-' . $id] = ['id' => 'sq-' . $id, 'reference_id' => $id, 'location_id' => 'loc1', 'state' => 'OPEN', 'total_money' => $body['order']['line_items'][0]['base_price_money']];
        if ($failCreate) { $failCreate = false; throw new RuntimeException('Simulated lost reply'); }
        return ['payment_link' => ['order_id' => 'sq-' . $id, 'id' => 'link-' . $id, 'url' => 'https://sandbox.square.link/test']];
    }
    if (str_starts_with($path, '/v2/orders/')) {
        $id = substr($path, strlen('/v2/orders/')); $o = $orders[$id];
        if ($paid) { $o['tenders'] = [['payment_id' => 'pay-' . $id]]; $o['net_amount_due_money'] = ['amount' => 0]; }
        if ($mismatch === 'order') $o['reference_id'] = 'another_invoice';
        if ($mismatch === 'location') $o['location_id'] = 'another_location';
        if ($mismatch === 'split') $o['tenders'][] = ['payment_id' => 'second'];
        return ['order' => $o];
    }
    if (str_starts_with($path, '/v2/payments/')) {
        $id = substr($path, strlen('/v2/payments/')); $order = substr($id, 4);
        return ['payment' => ['id' => $id, 'order_id' => $order, 'location_id' => 'loc1', 'status' => 'COMPLETED',
            'amount_money' => ['amount' => $mismatch === 'amount' ? 99 : 100, 'currency' => $mismatch === 'currency' ? 'USD' : 'CAD'],
            'refunded_money' => ['amount' => $mismatch === 'refund' ? 100 : 0], 'tip_money' => ['amount' => $mismatch === 'tip' ? 1 : 0],
            'source_type' => $external ? 'EXTERNAL' : 'CARD', 'card_details' => $external ? [] : ['status' => $mismatch === 'capture' ? 'AUTHORIZED' : 'CAPTURED']]];
    }
    throw new RuntimeException('Unexpected fixture request ' . $path);
};
try {
    SettingsStore::initialize($config['database']); SquareStore::migrate($config['database']);
    file_put_contents($config['secret_key'], random_bytes(32));
    $store = new SquareStore($config, new Square($transport));
    $mismatch = 'merchant'; rejects(fn() => $store->connect('1', 'sandbox', $token, 'loc1')); $mismatch = '';
    $store->connect('1', 'sandbox', $token, 'loc1');
    $c = $store->connections('1')[0]; $id = $c['id'];
    check($store->connections('2') === [], 'Connections scoped');
    rejects(fn() => $store->connect('2', 'sandbox', $token, 'loc1'));
    rejects(fn() => $store->saveWebhook('2', $id, $secret));
    rejects(fn() => $store->checkout('2', $id, false));
    rejects(fn() => $store->checkout('1', $id, false));
    $store->saveWebhook('1', $id, $secret);
    $store->connect('1', 'sandbox', $token, 'loc1');
    check(count($store->connections('1')) === 1, 'Repeated connect preserves identity');
    check(!str_contains(file_get_contents($config['database']), $token), 'Token encrypted');
    check(!str_contains(file_get_contents($config['database']), $secret), 'Webhook key encrypted');
    $failCreate = true; rejects(fn() => $store->checkout('1', $id, false));
    $first = $store->test('1', $id);
    $store->checkout('1', $id, false);
    $o = $store->test('1', $id); check($first['id'] === $o['id'], 'Lost replies reuse invoice');
    $count = count($calls); $store->checkout('1', $id, false); check(count($calls) === $count, 'No duplicate checkout creation');
    $store->refresh('1', $id); check($store->test('1', $id)['status'] === 'pending', 'Unpaid remains pending');
    $raceStore = new SquareStore($config, new Square(function ($path, $body, $c) use ($transport, $config, $o): array {
        $reply = $transport($path, $body, $c);
        if (str_starts_with($path, '/v2/orders/')) {
            $db = new PDO('sqlite:' . $config['database']);
            $db->prepare("UPDATE am_square_tests SET status='paid',payment_id=? WHERE id=?")->execute(['pay-' . $o['square_order_id'], $o['id']]);
        }
        return $reply;
    }));
    $raceStore->refresh('1', $id);
    check($store->test('1', $id)['status'] !== 'pending', 'Slow pending lookup cannot reopen a concurrently paid checkout');
    $paid = true;
    foreach (['order', 'location', 'split', 'amount', 'currency', 'refund', 'tip', 'capture'] as $bad) {
        $mismatch = $bad; rejects(fn() => $store->refresh('1', $id));
        check($store->test('1', $id)['status'] === 'review', 'Mismatched settlement requires review');
    }
    $mismatch = '';
    $raw = json_encode(['merchant_id' => 'merchant1', 'type' => 'payment.updated', 'data' => ['object' => ['payment' => ['order_id' => $o['square_order_id']]]]]);
    $signature = base64_encode(hash_hmac('sha256', $store->webhookUrl($id) . $raw, $secret, true));
    rejects(fn() => $store->webhook($id, $raw, 'bad'));
    rejects(fn() => $store->webhook($id, $raw . ' ', $signature));
    rejects(fn() => $store->webhook($id, $raw, base64_encode(hash_hmac('sha256', 'https://wrong.example/' . $raw, $secret, true))));
    $wrong = str_replace('merchant1', 'merchant2', $raw);
    rejects(fn() => $store->webhook($id, $wrong, base64_encode(hash_hmac('sha256', $store->webhookUrl($id) . $wrong, $secret, true))));
    $store->webhook($id, $raw, $signature); $store->webhook($id, $raw, $signature);
    check($store->test('1', $id)['status'] === 'paid', 'Webhook verifies via API, including OPEN digital order');
    check($store->connections('1')[0]['webhook_seen'] !== null, 'Authenticated notification recorded');
    $again = new SquareStore($config, new Square($transport));
    check($again->test('1', $id)['status'] === 'paid', 'Paid state persists');
    $external = true;
    // Simulate the previously rejected Sandbox Testing Panel payment on the same invoice.
    $db = new PDO('sqlite:' . $config['database']);
    $db->prepare("UPDATE am_square_tests SET status='review' WHERE id=?")->execute([$o['id']]);
    foreach (['amount', 'currency', 'refund', 'tip', 'location', 'order'] as $bad) {
        $mismatch = $bad; rejects(fn() => $store->refresh('1', $id));
        check($store->test('1', $id)['status'] === 'review', 'Sandbox simulation still rejects mismatches');
    }
    $mismatch = '';
    $store->refresh('1', $id);
    check($store->test('1', $id)['status'] === 'sandbox_verified', 'Sandbox EXTERNAL is distinct from captured settlement');
    $store->webhook($id, $raw, $signature);
    check($store->test('1', $id)['status'] === 'sandbox_verified', 'Sandbox webhook replay remains test-only');
    $count = count($calls); $store->checkout('1', $id, false);
    check(count($calls) === $count, 'Sandbox success cannot open another checkout');
    $liveReader = ['mode' => 'live', 'token' => $token, 'location_id' => 'loc1'];
    try {
        (new Square($transport))->status($liveReader, $o);
        throw new LogicException('Production must reject EXTERNAL');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'EXTERNAL') && str_contains($error->getMessage(), 'CARD / CAPTURED'), 'Safe source-specific diagnostic');
    }
    $external = false;
    $merchant = 'merchant-live'; $store->connect('1', 'live', $token, 'loc1'); $live = $store->connections('1')[0]['id'];
    $store->saveWebhook('1', $live, $secret);
    rejects(fn() => $store->checkout('1', $live, false));
    check($store->test('1', $live) === null, 'No live checkout without explicit real-charge confirmation');
    $db = new PDO('sqlite:' . $config['database']);
    $db->exec("UPDATE am_square_connections SET token_cipher=(SELECT token_cipher FROM am_square_connections WHERE id='$id') WHERE id='$live'");
    rejects(fn() => $store->checkout('1', $live, true));
    echo "PASS: Square merchant/location validation, encrypted credentials, tenant isolation, idempotency, exact captured settlement, Sandbox EXTERNAL separation and production rejection, signed webhooks/replay, real-charge confirmation, persistence. No external calls.\n";
} finally {
    foreach (glob($root . '/*') as $file) unlink($file);
    rmdir($root);
}
