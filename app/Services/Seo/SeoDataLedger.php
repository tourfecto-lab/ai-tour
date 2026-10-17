<?php
/** Global provider budget reservation, serialized across workers; user billing is atomic. */
class SeoDataLedger
{
    private Database $db;
    private ?float $cap=null;
    public function __construct(?Database $db = null) { $this->db = $db ?? Database::getInstance(); }

    public function reserve(string $endpoint, int $websiteId, int $units, float $ceiling, float $cap): int
    {
        $this->cap=$cap;
        SeoDataSchema::ensureSchema($this->db);
        $pdo = $this->db->getConnection();
        $lock = $pdo->query("SELECT GET_LOCK('tourfecto:dataforseo:budget',10)")->fetchColumn();
        if ((int)$lock !== 1) throw new RuntimeException('seo_budget_busy');
        try {
            $spent = (float)$pdo->query("SELECT COALESCE(SUM(GREATEST(cost,reserved_cost)),0) FROM dataforseo_calls WHERE created_at >= DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-01')")->fetchColumn();
            if (!is_finite($cap) || $cap <= 0 || round($spent + $ceiling,6) > round($cap,6)) {
                $this->alert();
                throw new RuntimeException('seo_monthly_budget_reached');
            }
            $site=$pdo->prepare('SELECT user_id FROM websites WHERE id=?'); $site->execute([$websiteId]);
            $owner=$site->fetchColumn();
            if (!$owner) throw new RuntimeException('website_not_found');
            // Initialize PlanUsage's schema check before opening the billing transaction.
            PlanUsage::quota((int)$owner,'seo_data');
            return $this->db->transaction(function () use ($endpoint,$websiteId,$units,$ceiling,$owner,$pdo) {
                for ($i=0; $i<$units; $i++) {
                    if (PlanUsage::charge((int)$owner,'seo_data','seo_data','DataForSEO '.$endpoint) === 'failed')
                        throw new RuntimeException('seo_quota_and_wallet_exhausted');
                }
                $s=$pdo->prepare('INSERT INTO dataforseo_calls (endpoint,cost,reserved_cost,website_id,status,created_at) VALUES (?,0,?,?,\'pending\',UTC_TIMESTAMP())');
                $s->execute([$endpoint,$ceiling,$websiteId]);
                return (int)$pdo->lastInsertId();
            });
        } finally { $pdo->query("SELECT RELEASE_LOCK('tourfecto:dataforseo:budget')"); }
    }

    public function settle(int $id, ?float $cost, string $status): void
    {
        // Unknown network outcome keeps the reservation: a timeout does not prove no charge.
        $s=$this->db->getConnection()->prepare('UPDATE dataforseo_calls SET cost=?, reserved_cost=IF(? IS NULL,reserved_cost,0), status=? WHERE id=?');
        $s->execute([$cost ?? 0,$cost,$status,$id]);
        if ($this->cap!==null && $this->cap>0) {
            $spent=(float)$this->db->getConnection()->query("SELECT COALESCE(SUM(GREATEST(cost,reserved_cost)),0) FROM dataforseo_calls WHERE created_at>=DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-01')")->fetchColumn();
            if (round($spent,6)>=round($this->cap,6)) $this->alert();
        }
    }

    private function alert(): void
    {
        $pdo=$this->db->getConnection();
        $s=$pdo->prepare('INSERT IGNORE INTO seo_provider_alerts (alert_key,created_at) VALUES (?,UTC_TIMESTAMP())');
        $s->execute(['budget:'.gmdate('Y-m')]);
        if (!$s->rowCount()) return;
        foreach ($this->db->query("SELECT id FROM users WHERE role IN ('admin','super_admin')") as $u)
            Notification::notify((int)$u['id'],'seo_budget','ميزانية DataForSEO خلصت','وقفنا الطلبات المدفوعة. راجع سقف الميزانية وسجل التكاليف.','/admin/settings');
    }
}
