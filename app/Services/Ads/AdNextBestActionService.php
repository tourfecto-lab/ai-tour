<?php

/**
 * Tourfecto - Ad Next-Best-Action Recommendation Service (بند 5)
 * توصيات "الخطوة التالية" لكل حملة نشطة، محسوبة من ترند إحصائي حقيقي
 * على بيانات ad_performance_reports المزامنة (انحدار خطي بأقل المربعات
 * لميل الإنفاق وCTR) + كفاية الميزانية + ROAS + حالة الأصول/التجارب.
 *
 * مبادئ (مرآة لـ CrmForecastService):
 *   - "إحصائي وليس ML": كل رقم مشتق بطريقة إحصائية شفافة وموثقة.
 *   - اقتراح فقط: لا تنفيذ تلقائي لأي إجراء (نفس CrmNextBestActionService).
 *   - لا اختراع بيانات: لو أيام البيانات < حد أدنى، التوصية = wait بصراحة.
 *   - كل توصية تُعرض مع basis='statistical' + confidence من كفاية البيانات.
 *
 * @version 1.0.0
 */
class AdNextBestActionService
{
    private Database $db;

    /** الحد الأدنى لأيام البيانات المزامنة لاعتبار التوصية موثوقة */
    private const MIN_DATA_DAYS = 5;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ================================================================
    // توليد التوصيات
    // ================================================================

    /**
     * يقيّم كل الحملات النشطة (أو حملة واحدة) ويولّد توصية لكل منها مع
     * حفظ سجل يومي (dedupe لكل حملة/يوم). @return array
     */
    public function recommendations(int $ownerUserId, ?int $onlyCampaignId = null): array
    {
        $campaigns = (new AdCampaign())->where(['user_id' => $ownerUserId, 'status' => 'active']);
        if ($onlyCampaignId !== null) {
            $campaigns = array_values(array_filter($campaigns, fn ($c) => (int) $c->getAttribute('id') === $onlyCampaignId));
        }

        $out = [];
        foreach ($campaigns as $campaign) {
            $campaignId = (int) $campaign->getAttribute('id');
            $rec = $this->buildRecommendation($ownerUserId, $campaign);
            if ($rec === null) {
                continue;
            }
            $this->persistDaily($ownerUserId, $campaignId, $rec);
            $out[] = $rec + ['campaign_id' => $campaignId];
        }
        return $out;
    }

    /** توصية لحملة واحدة (بدون حفظ) - @return array|null */
    public function forCampaign(int $ownerUserId, int $campaignId): ?array
    {
        $campaign = (new AdCampaign())->find($campaignId);
        if (!$campaign || (int) $campaign->getAttribute('user_id') !== $ownerUserId) {
            return null;
        }
        return $this->buildRecommendation($ownerUserId, $campaign);
    }

    // ================================================================
    // سجل التوصيات
    // ================================================================

    public function list(int $ownerUserId, int $limit = 50, ?string $status = null): array
    {
        $conditions = ['user_id' => $ownerUserId];
        if ($status !== null) {
            $conditions['status'] = $status;
        }
        return array_map(fn ($r) => $r->toArray(), (new AdRecommendation())->where($conditions, ['recommendation_date' => 'DESC', 'id' => 'DESC'], $limit));
    }

    public function markApplied(int $ownerUserId, int $recommendationId): bool
    {
        return $this->setStatus($ownerUserId, $recommendationId, 'applied');
    }

    public function dismiss(int $ownerUserId, int $recommendationId): bool
    {
        return $this->setStatus($ownerUserId, $recommendationId, 'dismissed');
    }

    private function setStatus(int $ownerUserId, int $recommendationId, string $status): bool
    {
        $rec = (new AdRecommendation())->find($recommendationId);
        if (!$rec || (int) $rec->getAttribute('user_id') !== $ownerUserId) {
            return false;
        }
        $rec->setAttribute('status', $status);
        $rec->save();
        return true;
    }

    // ================================================================
    // الحساب
    // ================================================================

    /**
     * يبني توصية لحملة من إشارات حقيقية. @return array|null (لا أرقام مختلقة)
     */
    private function buildRecommendation(int $ownerUserId, AdCampaign $campaign): ?array
    {
        $campaignId = (int) $campaign->getAttribute('id');
        $name = (string) $campaign->getAttribute('name');

        $signals = $this->computeSignals($campaignId);
        $days = (int) $signals['days_of_data'];

        // بيانات غير كافية: توصية صريحة بانتظار المزيد من البيانات
        if ($days < self::MIN_DATA_DAYS) {
            return [
                'action' => 'wait',
                'basis' => 'statistical',
                'confidence' => 'low',
                'reason' => sprintf(
                    'حملة "%s": %d أيام بيانات مزامنة فقط (يلزم %d+) - لا توجد إشارة كافية لتوصية موثوقة.',
                    $name, $days, self::MIN_DATA_DAYS
                ),
                'signals' => $signals,
            ];
        }

        $confidence = $days >= 10 ? 'high' : 'moderate';
        $budget = (float) ($signals['daily_budget'] ?? 0);
        $budgetUtil = (float) $signals['budget_utilization_pct'];
        $roas = $signals['roas'];
        $ctrTrend = (float) $signals['ctr_trend_slope'];
        $recentCtr = $signals['recent_ctr'];
        $spendTrend = (float) $signals['spend_trend_slope'];

        // 1) حملة بتصرف كامل الميزانية بعائد إيجابي وميل إنفاق تصاعدي → ارفع
        if ($budget > 0 && $budgetUtil >= 95.0 && $roas !== null && $roas >= 1.0 && $spendTrend >= 0) {
            return [
                'action' => 'increase_budget',
                'basis' => 'statistical',
                'confidence' => $confidence,
                'reason' => sprintf(
                    'حملة "%s": صرفت %.1f%% من ميزانيتها اليومية (%.2f) بميل إنفاق تصاعدي وROAS %.2f - مرشحة لزيادة الميزانية.',
                    $name, $budgetUtil, $budget, $roas
                ),
                'signals' => $signals,
            ];
        }

        // 2) إنفاق عائد دون التكلفة (ROAS منخفض) → خفّض الميزانية
        if ($roas !== null && $roas < 0.5) {
            return [
                'action' => 'decrease_budget',
                'basis' => 'statistical',
                'confidence' => $confidence,
                'reason' => sprintf(
                    'حملة "%s": ROAS %.2f أقل من 0.5 (إنفاق %.2f / إيراد %.2f آخر 7 أيام) - خفّض الميزانية أو أوقف مؤقتًا.',
                    $name, $roas, (float) $signals['recent_spend'], (float) $signals['recent_revenue']
                ),
                'signals' => $signals,
            ];
        }

        // 3) انهيار CTR مع ميل سالب واضح → دوّر الأصول الإعلانية
        if ($ctrTrend < -0.1 && $recentCtr !== null && $recentCtr < 1.0) {
            return [
                'action' => 'rotate_creative',
                'basis' => 'statistical',
                'confidence' => $confidence,
                'reason' => sprintf(
                    'حملة "%s": CTR آخر 7 أيام %.2f%% وميله سالب (%.3f نقطة/يوم) - دوّر الأصول الإعلانية أو جرّب تنويعًا جديدًا.',
                    $name, $recentCtr, $ctrTrend
                ),
                'signals' => $signals,
            ];
        }

        // 4) عدم صرف الميزانية مع بيانات كافية → راجع الاستهداف
        if ($budget > 0 && $budgetUtil <= 30.0) {
            return [
                'action' => 'review_targeting',
                'basis' => 'statistical',
                'confidence' => $confidence,
                'reason' => sprintf(
                    'حملة "%s": صرفت %.1f%% فقط من ميزانيتها اليومية رغم %d أيام بيانات - راجع الاستهداف أو العروض.',
                    $name, $budgetUtil, $days
                ),
                'signals' => $signals,
            ];
        }

        // 5) أصل بأكثر من تنويع بلا تجربة جارية → ابدأ تجربة A/B
        if ($this->creativeReadyForAbTest($ownerUserId, $campaignId)) {
            return [
                'action' => 'start_ab_test',
                'basis' => 'rule',
                'confidence' => $confidence,
                'reason' => sprintf(
                    'حملة "%s": يوجد أصل إعلاني بأكثر من تنويع أداء فعلي ولا توجد تجربة A/B جارية - ابدأ تجربة لتحديد الأفضل إحصائيًا.',
                    $name
                ),
                'signals' => $signals,
            ];
        }

        return [
            'action' => 'wait',
            'basis' => 'statistical',
            'confidence' => $confidence,
            'reason' => sprintf('حملة "%s": لا توجد إشارة قوية تستدعي إجراء - راقب وعدّ لاحقًا.', $name),
            'signals' => $signals,
        ];
    }

    /**
     * إشارات إحصائية حقيقية على آخر 14 يوم مزامنة.
     * @return array{days_of_data:int, daily_budget:float, budget_utilization_pct:float, recent_spend:float, recent_revenue:float, recent_ctr:?float, recent_cpc:?float, roas:?float, spend_trend_slope:float, ctr_trend_slope:float}
     */
    private function computeSignals(int $campaignId): array
    {
        $campaign = (new AdCampaign())->find($campaignId);
        $budget = (float) ($campaign ? ($campaign->getAttribute('daily_budget') ?? 0) : 0);

        $rows = $this->db->query(
            "SELECT date_start, SUM(spend) AS spend, SUM(clicks) AS clicks,
                    SUM(impressions) AS impressions, SUM(revenue) AS revenue, SUM(conversions) AS conversions
             FROM ad_performance_reports
             WHERE campaign_id = ? AND date_start BETWEEN DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND DATE_SUB(CURDATE(), INTERVAL 1 DAY)
             GROUP BY date_start ORDER BY date_start ASC",
            [$campaignId]
        );

        $daysOfData = count($rows);
        $today = $this->db->query(
            "SELECT SUM(spend) AS spend FROM ad_performance_reports WHERE campaign_id = ? AND date_start = CURDATE()",
            [$campaignId]
        );
        $todaySpend = (float) (($today[0]['spend'] ?? 0));

        // نافذتان: آخر 7 أيام (recent) و7-14 (baseline)
        $recentSpend = 0.0;
        $recentRevenue = 0.0;
        $recentClicks = 0;
        $recentImpressions = 0;
        $spendSeries = [];
        $ctrSeries = [];
        foreach ($rows as $i => $r) {
            $spend = (float) $r['spend'];
            $clicks = (int) $r['clicks'];
            $impressions = (int) $r['impressions'];
            $revenue = (float) ($r['revenue'] ?? 0);
            $isRecent = $i >= count($rows) - 7;
            if ($isRecent) {
                $recentSpend += $spend;
                $recentRevenue += $revenue;
                $recentClicks += $clicks;
                $recentImpressions += $impressions;
            }
            $spendSeries[] = $spend;
            $ctrSeries[] = $impressions > 0 ? ($clicks / $impressions) * 100 : 0.0;
        }

        $recentCtr = $recentImpressions > 0 ? round(($recentClicks / $recentImpressions) * 100, 3) : null;
        $recentCpc = $recentClicks > 0 ? round($recentSpend / $recentClicks, 2) : null;
        $roas = $recentSpend > 0 ? round($recentRevenue / $recentSpend, 2) : null;
        $budgetUtil = $budget > 0 ? round(($todaySpend / $budget) * 100, 1) : 0.0;

        return [
            'days_of_data' => $daysOfData,
            'daily_budget' => $budget,
            'budget_utilization_pct' => $budgetUtil,
            'recent_spend' => round($recentSpend, 2),
            'recent_revenue' => round($recentRevenue, 2),
            'recent_ctr' => $recentCtr,
            'recent_cpc' => $recentCpc,
            'roas' => $roas,
            'spend_trend_slope' => round(self::linearSlope($spendSeries), 4),
            'ctr_trend_slope' => round(self::linearSlope($ctrSeries), 4),
        ];
    }

    /** هل هناك أصل بأكثر من تنويع (أداء فعلي) بلا تجربة A/B جارية؟ */
    private function creativeReadyForAbTest(int $ownerUserId, int $campaignId): bool
    {
        $creatives = array_values(array_filter(
            (new AdCreative())->where(['user_id' => $ownerUserId, 'campaign_id' => $campaignId]),
            fn ($cr) => $cr->getAttribute('status') !== 'archived'
        ));
        foreach ($creatives as $cr) {
            $variants = (new AdCreativeVariant())->where(['user_id' => $ownerUserId, 'creative_id' => (int) $cr->getAttribute('id')]);
            if (count($variants) < 2) {
                continue;
            }
            $running = array_filter(
                (new AdAbTest())->where(['user_id' => $ownerUserId, 'creative_id' => (int) $cr->getAttribute('id')]),
                fn ($t) => $t->getAttribute('status') === 'running'
            );
            if (empty($running)) {
                return true;
            }
        }
        return false;
    }

    // ================================================================
    // إحصاء
    // ================================================================

    /**
     * ميل الانحدار الخطي (أقل المربعات) لسلسلة زمنية - y=mx+b. m =
     * Σ((x-x̄)(y-ȳ)) / Σ((x-x̄)²). لو السلسلة أقل من نقطتين أو ثابتة،
     * الميل 0.0. طريقة إحصائية شفافة (ليست ML).
     */
    public static function linearSlope(array $y): float
    {
        $n = count($y);
        if ($n < 2) {
            return 0.0;
        }
        $xMean = ($n - 1) / 2.0;
        $yMean = array_sum($y) / $n;
        $num = 0.0;
        $den = 0.0;
        foreach ($y as $i => $yi) {
            $dx = $i - $xMean;
            $num += $dx * ($yi - $yMean);
            $den += $dx * $dx;
        }
        if ($den == 0.0) {
            return 0.0;
        }
        return $num / $den;
    }

    private function persistDaily(int $ownerUserId, int $campaignId, array $rec): void
    {
        $existing = (new AdRecommendation())->where(
            ['user_id' => $ownerUserId, 'campaign_id' => $campaignId, 'recommendation_date' => date('Y-m-d')],
            [],
            1
        );
        $row = !empty($existing) ? $existing[0] : new AdRecommendation([
            'user_id' => $ownerUserId, 'campaign_id' => $campaignId,
            'recommendation_date' => date('Y-m-d'),
        ]);
        $row->fill([
            'action' => $rec['action'],
            'basis' => $rec['basis'],
            'confidence' => $rec['confidence'],
            'reason' => $rec['reason'],
            'signals' => json_encode($rec['signals']),
        ]);
        $row->save();
    }
}
