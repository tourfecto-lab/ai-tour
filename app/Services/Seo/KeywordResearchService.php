<?php

/**
 * Tourfecto - SEO: Keyword Research Service (G4)
 * @version 1.0.0
 *
 * يوحّد الوصول لبيانات الكلمات المفتاحية الخارجية (حجم بحث/صعوبة) ويثري
 * `tracked_keywords` بالبيانات الحقيقية القادمة من مصدر مهيأ. لو مفيش
 * مصدر مهيأ → available=false بسبب واضح، ولا يتغير أي عمود (لا اختلاق).
 */
class KeywordResearchService
{
    /** @var KeywordResearchSourceInterface|null */
    private $source;

    public function __construct(?KeywordResearchSourceInterface $source = null)
    {
        $this->source = $source;
    }

    /**
     * حلّ المصدر: محقون صراحةً، أو HttpKeywordResearchSource لو متظبط،
     * أو NullKeywordResearchSource كـ fail-safe.
     */
    public function resolveSource(): KeywordResearchSourceInterface
    {
        if ($this->source !== null) {
            return $this->source;
        }
        $http = new HttpKeywordResearchSource();
        return $http->isConfigured() ? $http : new NullKeywordResearchSource();
    }

    /** حالة التهيئة للمصدر الحالي. */
    public function status(): array
    {
        $source = $this->resolveSource();
        return [
            'available' => $source->isConfigured(),
            'reason' => $source->isConfigured() ? null : 'no_keyword_research_source_configured',
            'source' => $source->sourceName(),
        ];
    }

    /**
     * تخصيب tracked_keywords لموقع معيّن ببيانات بحث حقيقية من المصدر.
     *
     * @param Database $db
     * @param int      $websiteId
     * @param int      $userId
     * @return array{available:bool, reason:?string, enriched:int, total:int, source:string}
     */
    public function enrichTrackedKeywords(Database $db, int $websiteId, int $userId): array
    {
        $source = $this->resolveSource();
        if (!$source->isConfigured()) {
            return [
                'available' => false,
                'reason' => 'no_keyword_research_source_configured',
                'enriched' => 0,
                'total' => 0,
                'source' => $source->sourceName(),
            ];
        }

        $rows = $db->query(
            "SELECT id, keyword FROM tracked_keywords WHERE website_id = ? AND user_id = ?",
            [$websiteId, $userId]
        );
        if (empty($rows)) {
            return ['available' => true, 'reason' => null, 'enriched' => 0, 'total' => 0, 'source' => $source->sourceName()];
        }

        $keywords = array_column($rows, 'keyword');
        $result = $source->getKeywordData($keywords);
        if (empty($result['available']) || !empty($result['error'])) {
            return [
                'available' => false,
                'reason' => $result['reason'] ?? 'source_unavailable',
                'error' => $result['error'] ?? null,
                'enriched' => 0,
                'total' => count($rows),
                'source' => $source->sourceName(),
            ];
        }

        $byLower = [];
        foreach ($result['data'] as $kw => $meta) {
            $byLower[strtolower((string) $kw)] = $meta;
        }

        $updateSql = "UPDATE tracked_keywords SET search_volume = ?, difficulty = ?, enriched_at = NOW() WHERE id = ? AND website_id = ? AND user_id = ?";
        $enriched = 0;
        foreach ($rows as $r) {
            $meta = $byLower[strtolower((string) $r['keyword'])] ?? null;
            if ($meta === null) {
                continue;
            }
            $db->query($updateSql, [
                $meta['search_volume'],
                $meta['difficulty'],
                (int) $r['id'],
                $websiteId,
                $userId,
            ]);
            $enriched++;
        }

        return [
            'available' => true,
            'reason' => null,
            'enriched' => $enriched,
            'total' => count($rows),
            'source' => $source->sourceName(),
        ];
    }
}
