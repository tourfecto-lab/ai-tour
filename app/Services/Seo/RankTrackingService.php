<?php

/**
 * Tourfecto - SEO: Rank Tracking Service (G7)
 * @version 1.0.0
 *
 * يسد فجوة "تتبع ترتيب يومي للكلمات المفتاحية" (Ahrefs/SEMrush): يفحص
 * ترتيب `tracked_keywords` الخاص بالعميل عبر `KeywordRankingSourceInterface`
 * (نفس عقد M5 - قابل لإعادة الاستخدام)، يحفظ كل قياس في
 * `seo_rank_tracking_history` (بُعد زمني)، يحدّث `current_position` +
 * `last_checked_at` على الكلمة نفسها، ويسجّل `last_rank_tracked_at` على
 * الموقع. المصدر الافتراضي Null يفشل بأمان (لا اختلاق ترتيبات).
 *
 * المطلوب: ميجريشن 2026_08_29_000004 (seo_rank_tracking_history + عمود
 * websites.last_rank_tracked_at).
 */
class RankTrackingService
{
    private const INTERVAL_DAYS = 1;

    /** @var KeywordRankingSourceInterface|null */
    private $source;

    public function __construct(?KeywordRankingSourceInterface $source = null)
    {
        $this->source = $source;
    }

    private function resolveSource(): KeywordRankingSourceInterface
    {
        return $this->source ?? new DataForSeoRankingSource();
    }

    /**
     * المواقع المستحقة لفحص الترتيب (عندها كلمات متابعة + مرّ يوم منذ آخر
     * فحص ناجح). الترتيب حسب الأقدمية.
     */
    public function dueWebsites(Database $db, int $limit = 50): array
    {
        return $db->query(
            "SELECT w.id, w.user_id, w.main_url
             FROM websites w
             INNER JOIN tracked_keywords tk ON tk.website_id = w.id
             WHERE w.is_connected = 1
               AND (w.last_rank_tracked_at IS NULL
                    OR w.last_rank_tracked_at <= DATE_SUB(NOW(), INTERVAL ? DAY))
             GROUP BY w.id, w.user_id, w.main_url
             ORDER BY w.last_rank_tracked_at ASC
             LIMIT ?",
            [self::INTERVAL_DAYS, $limit]
        );
    }

    /**
     * فحص ترتيب كلمات موقع معيّن وتسجيل التاريخ.
     *
     * @param Database $db
     * @param int      $websiteId
     * @param int      $userId
     * @return array{available:bool, reason:?string, checked:int, recorded:int, source:string, results:array, error:?string}
     */
    public function checkWebsite(Database $db, int $websiteId, int $userId): array
    {
        $source = $this->resolveSource();
        if (!$source->isConfigured()) {
            return [
                'available' => false,
                'reason' => 'no_keyword_ranking_source_configured',
                'checked' => 0,
                'recorded' => 0,
                'source' => $source->sourceName(),
                'results' => [],
                'error' => 'مفيش مصدر ترتيبات SERP مهيأ (KeywordRankingSource)',
            ];
        }

        $sites = $db->query(
            "SELECT id, user_id, main_url FROM websites WHERE id = ? AND user_id = ? LIMIT 1",
            [$websiteId, $userId]
        );
        if (empty($sites)) {
            return ['available' => false, 'reason' => 'website_not_found', 'checked' => 0, 'recorded' => 0, 'source' => $source->sourceName(), 'results' => [], 'error' => 'الموقع غير موجود'];
        }
        $site = $sites[0];

        $keywords = $db->query(
            "SELECT id, keyword FROM tracked_keywords WHERE website_id = ? AND user_id = ? AND keyword <> '' ORDER BY last_checked_at ASC,id ASC LIMIT 20",
            [$websiteId, $userId]
        );
        if (empty($keywords)) {
            return ['available' => true, 'reason' => null, 'checked' => 0, 'recorded' => 0, 'source' => $source->sourceName(), 'results' => [], 'error' => null];
        }

        $host = strtolower((string) parse_url((string) $site['main_url'], PHP_URL_HOST));
        if ($host === '') {
            return ['available' => false, 'reason' => 'invalid_main_url', 'checked' => 0, 'recorded' => 0, 'source' => $source->sourceName(), 'results' => [], 'error' => 'main_url غير صالح'];
        }

        $keywordList = array_column($keywords, 'keyword');
        $result = $source->check($host, $keywordList);
        if (empty($result['available'])) {
            return [
                'available' => false,
                'reason' => $result['reason'] ?? 'source_unavailable',
                'checked' => 0,
                'recorded' => 0,
                'source' => $source->sourceName(),
                'results' => [],
                'error' => $result['reason'] ?? 'المصدر مش متاح',
            ];
        }

        $checkedAt = date('Y-m-d H:i:s');
        $sourceTag = 'integration:' . $source->sourceName();
        $byKeyword = [];
        foreach ($result['rankings'] as $r) {
            $byKeyword[strtolower(trim((string) ($r['keyword'] ?? '')))] = $r;
        }

        $insertSql = "INSERT INTO seo_rank_tracking_history
                (website_id, user_id, keyword, position, url, source, checked_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)";
        $updateKeywordSql = "UPDATE tracked_keywords SET current_position = ?, last_checked_at = ? WHERE id = ? AND website_id = ? AND user_id = ?";

        $recorded = 0;
        $results = [];
        foreach ($keywords as $kw) {
            $meta = $byKeyword[strtolower(trim((string) $kw['keyword']))] ?? null;
            // A missing provider row is not evidence that the site failed to rank.
            if ($meta === null) { continue; }
            $position = isset($meta['position']) ? (int) $meta['position'] : null;
            $url = isset($meta['url']) ? (string) $meta['url'] : null;
            if ($position !== null && ($position < 1 || $position > 100)) {
                $position = null;
            }

            $db->query($insertSql, [$websiteId, $userId, $kw['keyword'], $position, $url, $sourceTag, $checkedAt]);
            $db->query($updateKeywordSql, [$position, $checkedAt, $kw['id'], $websiteId, $userId]);
            $recorded++;
            $results[] = [
                'keyword' => $kw['keyword'],
                'position' => $position,
                'url' => $url,
            ];
        }

        $db->exec("UPDATE websites SET last_rank_tracked_at = NOW() WHERE id = ? AND user_id = ?", [$websiteId, $userId]);

        return [
            'available' => true,
            'reason' => null,
            'checked' => count($keywords),
            'recorded' => $recorded,
            'source' => $sourceTag,
            'results' => $results,
            'error' => null,
        ];
    }

    /**
     * نظرة عامة: أحدث ترتيب + أفضل ترتيب + اتجاه لكل كلمة متابعة.
     *
     * @param Database $db
     * @param int      $websiteId
     * @param int      $userId
     * @return array{status:array, keywords:array}
     */
    public function trackingOverview(Database $db, int $websiteId, int $userId): array
    {
        $source = $this->resolveSource();
        $status = [
            'available' => $source->isConfigured(),
            'reason' => $source->isConfigured() ? null : 'no_keyword_ranking_source_configured',
            'source' => $source->sourceName(),
        ];

        $keywords = $db->query(
            "SELECT id, keyword, current_position, search_volume, difficulty, last_checked_at
             FROM tracked_keywords WHERE website_id = ? AND user_id = ? ORDER BY id ASC",
            [$websiteId, $userId]
        );

        $out = [];
        foreach ($keywords as $kw) {
            $history = $db->query(
                "SELECT position, checked_at FROM seo_rank_tracking_history
                 WHERE website_id = ? AND user_id = ? AND keyword = ?
                 ORDER BY checked_at ASC",
                [$websiteId, $userId, $kw['keyword']]
            );
            $positions = array_map(static fn ($h) => (int) $h['position'], array_filter($history, static fn ($h) => $h['position'] !== null));
            $best = empty($positions) ? null : min($positions);
            $latest = $kw['current_position'] !== null ? (int) $kw['current_position'] : null;
            $trend = null;
            if ($latest !== null && $best !== null && count($positions) >= 2) {
                $trend = $positions[count($positions)-2] - $positions[count($positions)-1]; // موجب = تحسّن عن الأفضل
            }

            $out[] = [
                'keyword' => $kw['keyword'],
                'current_position' => $latest,
                'best_position' => $best,
                'distance_from_best' => $latest!==null&&$best!==null?$latest-$best:null,
                'trend' => $trend,
                'readings' => count($history),
                'search_volume' => $kw['search_volume'] !== null ? (int) $kw['search_volume'] : null,
                'difficulty' => $kw['difficulty'] !== null ? (int) $kw['difficulty'] : null,
                'last_checked_at' => $kw['last_checked_at'],
            ];
        }

        return ['status' => $status, 'keywords' => $out];
    }

    /**
     * السلسلة الزمنية لكلمة واحدة.
     * @return array<string, mixed>
     */
    public function history(Database $db, int $websiteId, int $userId, string $keyword, int $limit = 60): array
    {
        $rows = $db->query(
            "SELECT position, url, source, checked_at FROM seo_rank_tracking_history
             WHERE website_id = ? AND user_id = ? AND keyword = ?
             ORDER BY checked_at DESC, id DESC LIMIT ?",
            [$websiteId, $userId, $keyword, $limit]
        );
        return [
            'keyword' => $keyword,
            'points' => array_map(static fn ($r) => [
                'position' => $r['position'] !== null ? (int) $r['position'] : null,
                'url' => $r['url'],
                'source' => $r['source'],
                'checked_at' => $r['checked_at'],
            ], array_reverse($rows)),
        ];
    }
}
