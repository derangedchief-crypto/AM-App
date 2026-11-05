<?php
declare(strict_types=1);
namespace AmAccount;

final class Announcements
{
    private \PDO $db;
    public function __construct(string $path)
    {
        if (!is_file($path)) throw new \RuntimeException('Announcement storage unavailable.');
        $this->db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout=5000');
    }
    public static function migrate(string $path): void
    {
        $s=new self($path);
        $s->db->exec('CREATE TABLE IF NOT EXISTS am_announcements (id TEXT PRIMARY KEY,revision INTEGER NOT NULL,title TEXT NOT NULL,body TEXT NOT NULL,severity TEXT NOT NULL,expires INTEGER NOT NULL,updated INTEGER NOT NULL,withdrawn INTEGER NOT NULL DEFAULT 0)');
    }
    private function query(string $sql,array $values=[]): \PDOStatement
    { $q=$this->db->prepare($sql); $q->execute($values); return $q; }
    public function active(): array
    { return $this->query('SELECT id,revision,title,body,severity,expires,updated FROM am_announcements WHERE withdrawn=0 AND expires>? ORDER BY updated DESC,id LIMIT 20',[time()])->fetchAll(\PDO::FETCH_ASSOC); }
    public function recent(): array
    { return $this->query('SELECT * FROM am_announcements ORDER BY updated DESC,id LIMIT 100')->fetchAll(\PDO::FETCH_ASSOC); }
    public function delete(string $id,int $revision): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id) || $revision<1) throw new \InvalidArgumentException('Invalid announcement.');
        if ($this->query('DELETE FROM am_announcements WHERE id=? AND revision=?',[$id,$revision])->rowCount()!==1) throw new \InvalidArgumentException('Notice changed or was deleted. Reload before trying again.');
    }
    public function save(string $id,int $revision,string $title,string $body,string $severity,int $expires,bool $withdraw=false): void
    {
        if ($id!=='' && !preg_match('/^[a-f0-9]{32}$/D',$id)) throw new \InvalidArgumentException('Invalid announcement.');
        $title=trim($title); $body=trim($body);
        if (!$withdraw && ($title==='' || strlen($title)>160 || $body==='' || strlen($body)>2000 || !preg_match('//u',$title.$body) || !in_array($severity,['info','warning','critical'],true) || $expires<=time() || $expires>time()+90*86400)) throw new \InvalidArgumentException('Enter a title (160 bytes maximum), message (2000 bytes maximum), severity and expiry within 90 days.');
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            if ($id==='') {
                if ($withdraw || count($this->active())>=20) throw new \InvalidArgumentException('At most 20 announcements may be active. Withdraw an old notice first.');
                $this->query('INSERT INTO am_announcements VALUES (?,1,?,?,?,?,?,0)',[bin2hex(random_bytes(16)),$title,$body,$severity,$expires,time()]);
            } else {
                $q=$withdraw?$this->query('UPDATE am_announcements SET withdrawn=1,revision=revision+1,updated=? WHERE id=? AND revision=? AND withdrawn=0',[time(),$id,$revision]):$this->query('UPDATE am_announcements SET title=?,body=?,severity=?,expires=?,revision=revision+1,updated=? WHERE id=? AND revision=? AND withdrawn=0 AND expires>?',[$title,$body,$severity,$expires,time(),$id,$revision,time()]);
                if ($q->rowCount()!==1) throw new \InvalidArgumentException('Notice changed, expired or was withdrawn. Reload before trying again.');
            }
            $this->db->exec('COMMIT');
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
}
