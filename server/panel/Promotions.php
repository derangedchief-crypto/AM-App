<?php
declare(strict_types=1);
namespace AmAccount;

final class Promotions
{
    private \PDO $db;
    public function __construct(private string $path)
    {
        if (!is_file($path)) throw new \RuntimeException('Promotions are not installed.');
        $this->db = new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout=5000');
    }
    public static function migrate(string $path): void
    {
        $store = new self($path);
        $store->db->exec('CREATE TABLE IF NOT EXISTS am_promotions (id TEXT PRIMARY KEY,reseller_id TEXT NOT NULL,package_id INTEGER NOT NULL,currency TEXT NOT NULL,amount_minor INTEGER NOT NULL,title TEXT NOT NULL,description TEXT NOT NULL,starts INTEGER NOT NULL,ends INTEGER NOT NULL,enabled INTEGER NOT NULL,revision INTEGER NOT NULL,package_fingerprint TEXT NOT NULL,image TEXT NOT NULL,image_type TEXT NOT NULL)');
        $store->db->exec('CREATE INDEX IF NOT EXISTS am_promotions_owner ON am_promotions(reseller_id,ends)');
        $store->db->exec('CREATE TABLE IF NOT EXISTS am_promo_quotes (quote_id TEXT PRIMARY KEY,promo_id TEXT NOT NULL,promo_revision INTEGER NOT NULL)');
    }
    public function rows(string $reseller): array
    {
        $q=$this->db->prepare('SELECT id,reseller_id,package_id,currency,amount_minor,title,description,starts,ends,enabled,revision,package_fingerprint FROM am_promotions WHERE reseller_id=? ORDER BY ends DESC,id');
        $q->execute([$reseller]); return $q->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function get(string $reseller, string $id): ?array
    {
        $q=$this->db->prepare('SELECT * FROM am_promotions WHERE reseller_id=? AND id=?'); $q->execute([$reseller,$id]);
        return $q->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    public function banner(string $id): ?array
    {
        $q=$this->db->prepare('SELECT image,image_type FROM am_promotions WHERE id=?'); $q->execute([$id]);
        return $q->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    public static function image(string $bytes): array
    {
        if (strlen($bytes)>1048576) throw new \InvalidArgumentException('Banner must be at most 1 MB.');
        $info=@getimagesizefromstring($bytes);
        if (!$info || !in_array($info['mime'],['image/png','image/jpeg','image/webp'],true) || $info[0]>4000 || $info[1]>4000) throw new \InvalidArgumentException('Use a PNG, JPEG or WebP banner up to 4000 × 4000 pixels.');
        return ['image'=>base64_encode($bytes),'image_type'=>$info['mime']];
    }
    private static function date(string $value): int
    {
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d\TH:i')!==$value) throw new \InvalidArgumentException('Enter valid start and end dates in UTC.');
        return $date->getTimestamp();
    }
    public function save(string $reseller, array $input, array $catalogue, ?array $image): string
    {
        foreach (['promo_id','revision','package_id','currency','amount','title','description','starts','ends','fingerprint'] as $key)
            if (!is_string($input[$key] ?? null)) throw new \InvalidArgumentException('Invalid promotion field.');
        $id=$input['promo_id'];
        if (($id!=='' && !preg_match('/^[a-f0-9]{32}$/D',$id)) || !preg_match('/^\d{1,9}$/D',$input['revision']) || !preg_match('/^[1-9]\d{0,9}$/D',$input['package_id']) || (isset($input['enabled']) && $input['enabled']!=='1')) throw new \InvalidArgumentException('Invalid promotion reference.');
        $old=$id!=='' ? $this->get($reseller,$id) : null;
        if (($id!=='' && !$old) || (int)($old['revision'] ?? 0)!==(int)$input['revision']) throw new Conflict('Promotion changed or is unavailable. Reload before saving.');
        $p=$catalogue[(int)$input['package_id']] ?? null;
        if (!$p || !hash_equals($p['fingerprint'],$input['fingerprint']) || (string)$p['package_type']==='1') throw new Conflict('Choose a current paid package and review its details.');
        $base=(new PricingStore($this->path))->quote($reseller,$p['id'],$input['currency'],$catalogue);
        $amount=PricingStore::minor(trim($input['amount']));
        if ($amount >= $base['amount_minor']) throw new \InvalidArgumentException('Deal price must be lower than the enabled normal renewal price in this currency.');
        $title=trim($input['title']); $description=trim($input['description']);
        if ($title==='' || strlen($title)>120 || strlen($description)>1000) throw new \InvalidArgumentException('Use a title up to 120 bytes and description up to 1000 bytes.');
        $starts=self::date($input['starts']); $ends=self::date($input['ends']);
        if ($starts >= $ends || $ends <= time()) throw new \InvalidArgumentException('The end must be after the start and in the future.');
        $image ??= $old;
        if (!$image) throw new \InvalidArgumentException('Upload a promotional banner.');
        $values=[$p['id'],$input['currency'],$amount,$title,$description,$starts,$ends,isset($input['enabled'])?1:0,(int)$input['revision']+1,$p['fingerprint'],$image['image'],$image['image_type']];
        if ($old) {
            $q=$this->db->prepare('UPDATE am_promotions SET package_id=?,currency=?,amount_minor=?,title=?,description=?,starts=?,ends=?,enabled=?,revision=?,package_fingerprint=?,image=?,image_type=? WHERE id=? AND reseller_id=? AND revision=?');
            $q->execute([...$values,$id,$reseller,(int)$input['revision']]);
            if ($q->rowCount()!==1) throw new Conflict('Promotion changed. Reload before saving.');
        } else {
            $id=bin2hex(random_bytes(16));
            $this->db->prepare('INSERT INTO am_promotions VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$reseller,...$values]);
        }
        return $id;
    }
    public function disable(string $reseller, string $id, int $revision): void
    {
        $q=$this->db->prepare('UPDATE am_promotions SET enabled=0,revision=revision+1 WHERE reseller_id=? AND id=? AND revision=?');
        $q->execute([$reseller,$id,$revision]);
        if ($q->rowCount()!==1) throw new Conflict('Promotion changed or is unavailable. Reload before saving.');
    }
    public function price(string $reseller, string $id, array $base, ?int $now=null): array
    {
        $row=$this->get($reseller,$id); $now ??= time();
        if (!$row || !(int)$row['enabled'] || (int)$row['starts']>$now || (int)$row['ends']<=$now
            || (int)$row['package_id']!==$base['package_id'] || $row['currency']!==$base['currency']
            || !hash_equals($row['package_fingerprint'],$base['package']['fingerprint']) || (int)$row['amount_minor'] >= $base['amount_minor'])
            throw new \InvalidArgumentException('This deal has ended, changed or is not available for your current package.');
        return $row;
    }
}
