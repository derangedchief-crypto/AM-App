<?php
declare(strict_types=1);
namespace AmAccount;

/** Customer money prices are independent of the worker's wholesale credits. */
final class PricingStore
{
    private \PDO $db;

    public function __construct(string $path)
    {
        if (!is_file($path)) throw new \RuntimeException('Pricing database is not installed.');
        $this->db = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout=5000');
    }

    public static function migrate(string $path): void
    {
        if (!is_file($path)) throw new \RuntimeException('Settings database is not installed.');
        $db = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE IF NOT EXISTS am_package_prices (reseller_id TEXT NOT NULL, package_id INTEGER NOT NULL, currency TEXT NOT NULL, amount_minor INTEGER NOT NULL, enabled INTEGER NOT NULL, revision INTEGER NOT NULL, package_fingerprint TEXT NOT NULL, package_snapshot TEXT NOT NULL, updated_at INTEGER NOT NULL, PRIMARY KEY(reseller_id,package_id,currency))');
    }

    public static function catalogue(array $reply): array
    {
        if (($reply['ok'] ?? false) !== true || !is_array($reply['packages'] ?? null)) throw new \RuntimeException('Package catalogue is unavailable.');
        $out = [];
        foreach ($reply['packages'] as $raw) {
            if (!is_array($raw) || !is_int($raw['id'] ?? null) || $raw['id'] < 1 || !is_string($raw['name'] ?? null)
                || !is_string($raw['price_credits'] ?? null) || !preg_match('/^\d{1,12}(?:\.\d{1,8})?$/D', $raw['price_credits'])
                || !is_int($raw['max_connections'] ?? null) || $raw['max_connections'] < 1 || isset($out[$raw['id']])) {
                throw new \RuntimeException('Package catalogue format could not be verified.');
            }
            $p = ['id' => $raw['id'], 'name' => $raw['name'], 'price_credits' => $raw['price_credits'],
                'duration' => $raw['duration'] ?? null, 'duration_period' => $raw['duration_period'] ?? null,
                'duration_hours' => $raw['duration_hours'] ?? null, 'max_connections' => $raw['max_connections'],
                'package_type' => $raw['package_type'] ?? null];
            // Bouquets intentionally do not affect price or price reapproval.
            $p['fingerprint'] = hash('sha256', json_encode($p, JSON_THROW_ON_ERROR));
            $out[$p['id']] = $p;
        }
        return $out;
    }

    public static function minor(string $price): int
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,5})(?:\.([0-9]{1,2}))?$/D', $price, $m)) throw new \InvalidArgumentException('Enter a price from 0.00 to 999999.99 with at most two decimal places.');
        return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
    }

    public function rows(string $reseller): array
    {
        $q = $this->db->prepare('SELECT * FROM am_package_prices WHERE reseller_id=? ORDER BY package_id,currency');
        $q->execute([$reseller]);
        $out = [];
        foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $row) $out[$row['package_id'] . ':' . $row['currency']] = $row;
        return $out;
    }

    public function save(string $reseller, array $input, array $catalogue): void
    {
        foreach (['package_id', 'currency', 'amount', 'revision', 'fingerprint'] as $field) {
            if (!is_string($input[$field] ?? null)) throw new \InvalidArgumentException('Invalid pricing field.');
        }
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $input['package_id']) || !preg_match('/^[0-9]{1,9}$/D', $input['revision'])
            || !in_array($input['currency'], ['CAD','USD'], true) || (isset($input['enabled']) && $input['enabled'] !== '1')) throw new \InvalidArgumentException('Invalid package pricing option.');
        $package = $catalogue[(int)$input['package_id']] ?? null;
        if (!$package) throw new \InvalidArgumentException('This package is no longer offered to your reseller account.');
        if (!hash_equals($package['fingerprint'], $input['fingerprint'])) throw new Conflict('The package or credit cost changed. Reload and review it before saving.');
        $amount = self::minor(trim($input['amount']));
        $revision = (int)$input['revision'];
        $this->db->beginTransaction();
        try {
            $q = $this->db->prepare('SELECT revision FROM am_package_prices WHERE reseller_id=? AND package_id=? AND currency=?');
            $scope = [$reseller, $package['id'], $input['currency']];
            $q->execute($scope); $current = $q->fetchColumn();
            if (($current === false ? 0 : (int)$current) !== $revision) throw new Conflict('This package price changed in another tab. Reload before saving.');
            $this->db->prepare('INSERT INTO am_package_prices VALUES (?,?,?,?,?,?,?,?,?) ON CONFLICT(reseller_id,package_id,currency) DO UPDATE SET amount_minor=excluded.amount_minor,enabled=excluded.enabled,revision=excluded.revision,package_fingerprint=excluded.package_fingerprint,package_snapshot=excluded.package_snapshot,updated_at=excluded.updated_at')
                ->execute([$reseller,$package['id'],$input['currency'],$amount,isset($input['enabled']) ? 1 : 0,$revision + 1,$package['fingerprint'],json_encode($package, JSON_THROW_ON_ERROR),time()]);
            $this->db->commit();
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }

    /** Server-owned quote; never accepts a customer-submitted amount or wholesale cost. */
    public function quote(string $reseller, int $packageId, string $currency, array $catalogue): array
    {
        $p = $catalogue[$packageId] ?? null;
        $row = $this->rows($reseller)[$packageId . ':' . $currency] ?? null;
        if (!$p) throw new \InvalidArgumentException('The current package is not available in the reseller catalogue.');
        if (!$row) throw new \InvalidArgumentException('No renewal price is saved for the current package in '.$currency.'.');
        if ((int)$row['enabled'] !== 1) throw new \InvalidArgumentException('The current package renewal price is disabled for '.$currency.'.');
        if (!hash_equals($p['fingerprint'], $row['package_fingerprint'])) throw new \InvalidArgumentException('The package details changed. Your reseller must review and save the '.$currency.' renewal price again.');
        return ['package_id' => $packageId, 'currency' => $currency, 'amount_minor' => (int)$row['amount_minor'],
            'price_revision' => (int)$row['revision'], 'wholesale_credits' => $p['price_credits'], 'package' => $p];
    }
}
