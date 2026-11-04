<?php
declare(strict_types=1);
require __DIR__ . '/../panel/Settings.php';
require __DIR__ . '/../panel/Pricing.php';
use AmAccount\SettingsStore;
use AmAccount\PricingStore;

function expect(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function rejects(Closure $fn): void { try { $fn(); } catch (Throwable $error) { return; } throw new RuntimeException('Expected rejection.'); }
$root = (getenv('TMPDIR') ?: '/tmp/opencode') . '/am-pricing-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true); $path = $root . '/settings.sqlite';
$raw = ['id' => 12, 'name' => 'One-month package', 'price_credits' => '3.50', 'duration' => 1, 'duration_period' => 'months', 'duration_hours' => 720, 'max_connections' => 2, 'package_type' => 'regular', 'bouquets' => [1,2]];
$catalogue = PricingStore::catalogue(['ok' => true, 'packages' => [$raw]]);
$input = ['package_id' => '12', 'currency' => 'CAD', 'amount' => '19.95', 'revision' => '0', 'fingerprint' => $catalogue[12]['fingerprint'], 'enabled' => '1', 'reseller_id' => '999', 'price_credits' => '0'];
try {
    SettingsStore::initialize($path); PricingStore::migrate($path);
    $store = new PricingStore($path);
    $store->save('1', $input, $catalogue);
    $quote = $store->quote('1', 12, 'CAD', $catalogue);
    expect($quote['amount_minor'] === 1995 && $quote['wholesale_credits'] === '3.50', 'Retail price and wholesale credits independent; submitted wholesale cost ignored');
    expect($store->rows('999') === [] && $store->rows('2') === [], 'Posted reseller cannot change scope');
    rejects(fn() => $store->quote('2', 12, 'CAD', $catalogue));
    $store->save('2', array_replace($input, ['amount' => '45.01']), $catalogue);
    expect($store->quote('2', 12, 'CAD', $catalogue)['amount_minor'] === 4501, 'Each reseller sets its own price');
    expect($store->quote('1', 12, 'CAD', $catalogue)['amount_minor'] === 1995, 'Other reseller unchanged');
    rejects(fn() => $store->save('1', $input, $catalogue));
    rejects(fn() => $store->save('1', array_replace($input, ['package_id' => '13']), $catalogue));
    rejects(fn() => $store->save('1', array_replace($input, ['fingerprint' => 'stale']), $catalogue));
    $store->save('1', array_replace($input, ['currency' => 'USD', 'amount' => '17.50']), $catalogue);
    expect($store->quote('1', 12, 'USD', $catalogue)['amount_minor'] === 1750, 'Currencies independently priced');
    $bouquets = PricingStore::catalogue(['ok' => true, 'packages' => [array_replace($raw, ['bouquets' => [3,4,5]])]]);
    expect($store->quote('1', 12, 'CAD', $bouquets)['amount_minor'] === 1995, 'Bouquet changes do not add price or invalidate pricing');
    foreach (['price_credits' => '4.00', 'duration' => 3, 'max_connections' => 4] as $key => $value) {
        $changed = PricingStore::catalogue(['ok' => true, 'packages' => [array_replace($raw, [$key => $value])]]);
        rejects(fn() => $store->quote('1', 12, 'CAD', $changed));
        rejects(fn() => $store->save('1', array_replace($input, ['revision' => '1']), $changed));
    }
    rejects(fn() => $store->quote('1', 12, 'CAD', []));
    $store->save('1', array_replace($input, ['revision' => '1', 'amount' => '0.00']), $catalogue);
    $free = $store->quote('1', 12, 'CAD', $catalogue);
    expect($free['amount_minor'] === 0 && $free['wholesale_credits'] === '3.50', 'Free customer renewal still costs reseller credits');
    $disabled = array_replace($input, ['revision' => '2']); unset($disabled['enabled']);
    $store->save('1', $disabled, $catalogue);
    rejects(fn() => $store->quote('1', 12, 'CAD', $catalogue));
    foreach (['-1', '1.001', '1e3', '1,000', 'NaN', '1000000', '01.00'] as $bad) rejects(fn() => PricingStore::minor($bad));
    expect(PricingStore::minor('0.01') === 1 && PricingStore::minor('12.3') === 1230, 'Exact decimal-to-minor conversion');
    expect((new PricingStore($path))->quote('2', 12, 'CAD', $catalogue)['amount_minor'] === 4501, 'Prices persist');
    echo "PASS: independent reseller/currency prices, wholesale credits preserved, free bouquets, free retail renewal cost, scoped catalogue, stale revisions/package changes, exact money, disabled prices, persistence.\n";
} finally { foreach (glob($root . '/*') as $file) unlink($file); rmdir($root); }
