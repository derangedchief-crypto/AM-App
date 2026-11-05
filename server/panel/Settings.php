<?php
declare(strict_types=1);

namespace AmAccount;

final class Conflict extends \RuntimeException {}

final class Settings
{
    public static function defaults(): array
    {
        return [
            'app_enabled' => false, 'customer_policy' => 'selected',
            'allow_bouquets' => false, 'allow_packages' => false,
            'allow_connections' => false, 'allow_renewals' => false,
            'display_name' => '', 'support_email' => '', 'currency' => 'CAD',
            'billing_mode' => 'none', 'billing_url' => '',
            'gateways' => [], 'square_mode' => 'sandbox',
        ];
    }

    public static function fromPost(array $input): array
    {
        $out = self::defaults();
        foreach (['app_enabled', 'allow_bouquets', 'allow_packages', 'allow_connections', 'allow_renewals'] as $field) {
            if (isset($input[$field]) && $input[$field] !== '1') throw new \InvalidArgumentException('Invalid switch value.');
            $out[$field] = isset($input[$field]);
        }
        foreach (['display_name' => 120, 'support_email' => 254, 'billing_url' => 2048] as $field => $limit) {
            $value = $input[$field] ?? '';
            if (!is_string($value) || strlen($value) > $limit || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new \InvalidArgumentException('A text field is invalid or too long.');
            }
            $out[$field] = trim($value);
        }
        foreach (['customer_policy' => ['all', 'selected'], 'currency' => ['CAD', 'USD'],
                  'billing_mode' => ['none', 'external', 'integrated', 'both'], 'square_mode' => ['sandbox', 'live']] as $field => $allowed) {
            $value = $input[$field] ?? $out[$field];
            if (!is_string($value) || !in_array($value, $allowed, true)) throw new \InvalidArgumentException('Invalid settings option.');
            $out[$field] = $value;
        }
        if ($out['support_email'] !== '' && !filter_var($out['support_email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Enter a valid support email address.');
        }
        $gateways = $input['gateways'] ?? [];
        if (!is_array($gateways) || count($gateways) > 3) throw new \InvalidArgumentException('Invalid gateway selection.');
        foreach ($gateways as $gateway) {
            if (!is_string($gateway) || !in_array($gateway, ['stripe', 'square', 'rampex', 'paypal'], true)) {
                throw new \InvalidArgumentException('Invalid gateway selection.');
            }
        }
        $out['gateways'] = array_values(array_unique($gateways));
        if ($out['billing_url'] !== '') self::validateBillingUrl($out['billing_url']);
        if (in_array($out['billing_mode'], ['external', 'both'], true) && $out['billing_url'] === '') {
            throw new \InvalidArgumentException('Enter your HTTPS billing page for external billing.');
        }
        if (in_array($out['billing_mode'], ['integrated', 'both'], true) && !$out['gateways']) {
            throw new \InvalidArgumentException('Choose at least one gateway preference for integrated billing.');
        }
        return $out;
    }

    private static function validateBillingUrl(string $url): void
    {
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new \InvalidArgumentException('Use a public HTTPS billing page without embedded credentials or fragments.');
        }
        $host = strtolower($parts['host'] ?? '');
        if (!str_contains($host, '.') || str_ends_with($host, '.local') || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.internal') || str_contains($host, ':')
            || (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
            throw new \InvalidArgumentException('Use a public billing hostname.');
        }
        parse_str($parts['query'] ?? '', $query);
        foreach (array_keys($query) as $key) {
            if (preg_match('/password|secret|api.?key|activation|authorization|bearer|token/i', (string)$key)) {
                throw new \InvalidArgumentException('Do not put account credentials or API secrets in the billing link.');
            }
        }
    }
}

final class SettingsStore
{
    private \PDO $db;

    public function __construct(string $path)
    {
        if (!is_file($path)) throw new \RuntimeException('AM settings database is not installed.');
        $this->db = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout=5000');
    }

    /** Installer only. Never touches existing portal databases. */
    public static function initialize(string $path): void
    {
        if (file_exists($path)) throw new \RuntimeException('Refusing to replace an existing AM database.');
        $db = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE am_reseller_settings (reseller_id TEXT PRIMARY KEY, revision INTEGER NOT NULL, settings_json TEXT NOT NULL, updated_at INTEGER NOT NULL)');
        $db->exec('CREATE TABLE am_settings_audit (id INTEGER PRIMARY KEY AUTOINCREMENT, reseller_id TEXT NOT NULL, revision INTEGER NOT NULL, created_at INTEGER NOT NULL)');
        chmod($path, 0600);
    }

    public function read(string $resellerId): array
    {
        self::validateId($resellerId);
        $query = $this->db->prepare('SELECT revision, settings_json FROM am_reseller_settings WHERE reseller_id=?');
        $query->execute([$resellerId]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        return $row ? ['revision' => (int)$row['revision'], 'settings' => array_replace(Settings::defaults(), json_decode($row['settings_json'], true, 32, JSON_THROW_ON_ERROR))]
            : ['revision' => 0, 'settings' => Settings::defaults()];
    }

    public function save(string $resellerId, int $revision, array $settings): void
    {
        self::validateId($resellerId);
        if ($revision < 0) throw new \InvalidArgumentException('Invalid settings revision.');
        $this->db->beginTransaction();
        try {
            $fresh = $this->read($resellerId);
            if ($fresh['revision'] !== $revision) throw new Conflict('These settings changed in another tab. Reload before saving again.');
            $json = json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if ($revision === 0) {
                $query = $this->db->prepare('INSERT INTO am_reseller_settings VALUES (?,?,?,?)');
                $query->execute([$resellerId, 1, $json, time()]);
            } else {
                $query = $this->db->prepare('UPDATE am_reseller_settings SET revision=?,settings_json=?,updated_at=? WHERE reseller_id=? AND revision=?');
                $query->execute([$revision + 1, $json, time(), $resellerId, $revision]);
                if ($query->rowCount() !== 1) throw new Conflict('Settings changed. Reload before saving again.');
            }
            $this->db->prepare('INSERT INTO am_settings_audit (reseller_id,revision,created_at) VALUES (?,?,?)')->execute([$resellerId, $revision + 1, time()]);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    private static function validateId(string $id): void
    {
        if (!preg_match('/^[1-9][0-9]{0,17}$/D', $id)) throw new \InvalidArgumentException('Invalid reseller identity.');
    }
}
