<?php
declare(strict_types=1);
namespace AmAccount;

final class LicenseDenied extends \RuntimeException {}

/** Server-owned entitlements; keys are one-time activation secrets, never customer tokens. */
final class Licenses
{
    private \PDO $db;
    public function __construct(string $path)
    {
        if (!is_file($path)) throw new \RuntimeException('License storage unavailable.');
        $this->db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout=5000');
    }
    public static function migrate(string $path): void
    {
        $s=new self($path);
        $s->db->exec("CREATE TABLE IF NOT EXISTS am_licenses (id TEXT PRIMARY KEY,reseller_id TEXT NOT NULL,key_hash TEXT UNIQUE NOT NULL,key_hint TEXT NOT NULL,status TEXT NOT NULL,expires INTEGER,activated INTEGER,created INTEGER NOT NULL,updated INTEGER NOT NULL,revision INTEGER NOT NULL,current INTEGER NOT NULL)");
        $s->db->exec('CREATE UNIQUE INDEX IF NOT EXISTS am_license_current ON am_licenses(reseller_id) WHERE current=1');
        $s->db->exec('CREATE TABLE IF NOT EXISTS am_license_audit (id INTEGER PRIMARY KEY AUTOINCREMENT,license_id TEXT NOT NULL,reseller_id TEXT NOT NULL,actor TEXT NOT NULL,action TEXT NOT NULL,detail TEXT NOT NULL,created INTEGER NOT NULL)');
        $s->db->exec('CREATE TABLE IF NOT EXISTS am_license_attempts (reseller_id TEXT PRIMARY KEY,window INTEGER NOT NULL,hits INTEGER NOT NULL)');
    }
    private function query(string $sql,array $values=[]): \PDOStatement { $q=$this->db->prepare($sql); $q->execute($values); return $q; }
    public function current(string $reseller): ?array
    {
        return $this->query('SELECT id,reseller_id,key_hint,status,expires,activated,created,updated,revision,current FROM am_licenses WHERE reseller_id=? AND current=1',[$reseller])->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    public static function state(?array $row): string
    {
        if (!$row) return 'unlicensed';
        if (in_array($row['status'],['suspended','revoked'],true)) return $row['status'];
        if ($row['expires']!==null && (int)$row['expires']<=time()) return 'expired';
        return $row['status'];
    }
    public function requireActive(string $reseller): void
    {
        if (self::state($this->current($reseller))!=='active') throw new LicenseDenied('AM access is unavailable because your reseller license is not active. Contact the service administrator.');
    }
    public static function expiry(string $value): ?int
    {
        if ($value==='') return null;
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new \DateTimeZone('UTC'));
        if (!$d || $d->format('Y-m-d\TH:i')!==$value || $d->getTimestamp()<=time()) throw new \InvalidArgumentException('Enter a future expiry date in UTC, or leave blank for no expiry.');
        return $d->getTimestamp();
    }
    private function audit(array $row,string $actor,string $action,string $detail=''): void
    {
        $this->query('INSERT INTO am_license_audit (license_id,reseller_id,actor,action,detail,created) VALUES (?,?,?,?,?,?)',[$row['id'],$row['reseller_id'],$actor,$action,$detail,time()]);
    }
    public function issue(string $reseller,?int $expires,bool $replace=false): array
    {
        if (!preg_match('/^[1-9][0-9]{0,17}$/D',$reseller) || ($expires!==null && $expires<=time())) throw new \InvalidArgumentException('Invalid reseller or expiry.');
        $key='AM-'.strtoupper(bin2hex(random_bytes(20))); $id=bin2hex(random_bytes(16));
        $this->db->beginTransaction();
        try {
            $old=$this->current($reseller);
            if ($old && !$replace) throw new \InvalidArgumentException('This reseller already has a license. Confirm replacement to invalidate it and issue a new key.');
            if ($old) {
                $this->query("UPDATE am_licenses SET current=0,status='revoked',updated=?,revision=revision+1 WHERE id=?",[time(),$old['id']]);
                $this->audit($old,'admin','replaced');
            }
            $this->query("INSERT INTO am_licenses VALUES (?,?,?,?,'pending',?,NULL,?,?,1,1)",[$id,$reseller,hash('sha256',$key),substr($key,-6),$expires,time(),time()]);
            $this->audit(['id'=>$id,'reseller_id'=>$reseller],'admin','issued',$expires===null?'No expiry':'Expires '.gmdate('c',$expires));
            $this->db->commit(); return ['id'=>$id,'key'=>$key];
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    public function redeem(string $reseller,string $key): void
    {
        $window=intdiv(time(),900);
        $this->query('INSERT INTO am_license_attempts VALUES (?,?,1) ON CONFLICT(reseller_id) DO UPDATE SET hits=CASE WHEN window=excluded.window THEN hits+1 ELSE 1 END,window=excluded.window',[$reseller,$window]);
        if ((int)$this->query('SELECT hits FROM am_license_attempts WHERE reseller_id=?',[$reseller])->fetchColumn()>10) throw new \InvalidArgumentException('Too many activation attempts. Try again in 15 minutes.');
        $key=strtoupper(trim($key));
        if (!preg_match('/^AM-[A-F0-9]{40}$/D',$key)) throw new \InvalidArgumentException('License key is invalid or unavailable for this reseller.');
        $this->db->beginTransaction();
        try {
            $row=$this->query('SELECT * FROM am_licenses WHERE reseller_id=? AND current=1 AND key_hash=?',[$reseller,hash('sha256',$key)])->fetch(\PDO::FETCH_ASSOC);
            if (!$row || self::state($row)!=='pending' || $row['activated']!==null) throw new \InvalidArgumentException('License key is invalid, already used, or unavailable for this reseller.');
            $this->query("UPDATE am_licenses SET status='active',activated=?,updated=?,revision=revision+1 WHERE id=? AND status='pending'",[time(),time(),$row['id']]);
            $this->audit($row,'reseller:'.$reseller,'activated');
            $this->db->commit();
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    public function change(string $id,int $revision,string $action,?int $expires=null): void
    {
        $this->db->beginTransaction();
        try {
            $row=$this->query('SELECT * FROM am_licenses WHERE id=? AND current=1',[$id])->fetch(\PDO::FETCH_ASSOC);
            if (!$row || (int)$row['revision']!==$revision) throw new \InvalidArgumentException('License changed or was replaced. Reload before editing.');
            if ($row['status']==='revoked') throw new \InvalidArgumentException('Revoked keys cannot be restored. Issue a replacement license instead.');
            $status=$row['status']; $end=$row['expires'];
            switch ($action) {
                case 'suspend': $status='suspended'; break;
                case 'restore':
                    if ($status!=='suspended') throw new \InvalidArgumentException('Only suspended licenses can be restored.');
                    $status=$row['activated']===null?'pending':'active'; break;
                case 'revoke': $status='revoked'; break;
                case 'expiry':
                    if ($expires!==null && $expires<=time()) throw new \InvalidArgumentException('Expiry must be in the future.');
                    $end=$expires; break;
                default: throw new \InvalidArgumentException('Unknown license operation.');
            }
            $this->query('UPDATE am_licenses SET status=?,expires=?,revision=revision+1,updated=? WHERE id=?',[$status,$end,time(),$id]);
            $this->audit($row,'admin',$action,$action==='expiry'?($end===null?'No expiry':'Expires '.gmdate('c',(int)$end)):'');
            $this->db->commit();
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    public function rows(string $search,int $page): array
    {
        $filter=$search===''?'%':$search;
        return $this->query('SELECT id,reseller_id,key_hint,status,expires,activated,created,updated,revision,current FROM am_licenses WHERE reseller_id LIKE ? ORDER BY created DESC,rowid DESC LIMIT 25 OFFSET ?',[$filter,max(0,$page-1)*25])->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function count(string $search): int { return (int)$this->query('SELECT COUNT(*) FROM am_licenses WHERE reseller_id LIKE ?',[$search===''?'%':$search])->fetchColumn(); }
    public function auditRows(): array { return $this->query('SELECT reseller_id,actor,action,detail,created FROM am_license_audit ORDER BY id DESC LIMIT 30')->fetchAll(\PDO::FETCH_ASSOC); }
}
