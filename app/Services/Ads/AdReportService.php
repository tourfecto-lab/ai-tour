<?php

/**
 * Tourfecto - Ad Automated Reports
 * تقارير يومي/أسبوعي/شهري (بند 21 من طلب Ads Autopilot) - تجميع حقيقي
 * من ad_performance_reports (بيانات مُزامنة فعليًا من المنصات) +
 * ad_optimization_logs (كل الإجراءات اللي اتخذها Autopilot). مفيش أي
 * رقم مُختلق - لو مفيش بيانات لفترة معيّنة، الحقل بيرجع null/0 بوضوح.
 * @version 1.0.0
 */
class AdReportService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * @param string $period 'daily'|'weekly'|'monthly'
     */
    public function generate(int $userId, string $period = 'weekly'): array
    {
        $days = ['daily' => 1, 'weekly' => 7, 'monthly' => 30][$period] ?? 7;
        $since = date('Y-m-d', strtotime("-{$days} days"));

        $campaigns = array_map(fn ($c) => $c->toArray(), (new AdCampaign())->where(['user_id' => $userId]));
        $campaignIds = array_column($campaigns, 'id');

        if (empty($campaignIds)) {
            return $this->emptyReport($period, $since);
        }

        $placeholders = implode(',', array_fill(0, count($campaignIds), '?'));

        $reports = $this->db->query(
            "SELECT campaign_id, SUM(spend) as spend, SUM(clicks) as clicks, SUM(impressions) as impressions,
                    SUM(conversions) as conversions, SUM(revenue) as revenue
             FROM ad_performance_reports
             WHERE campaign_id IN ({$placeholders}) AND date_start >= ?
             GROUP BY campaign_id",
            array_merge($campaignIds, [$since])
        );

        $totals = ['spend' => 0.0, 'clicks' => 0, 'impressions' => 0, 'conversions' => 0.0, 'revenue' => 0.0];
        $byCampaign = [];
        $campaignNames = array_column($campaigns, 'name', 'id');

        foreach ($reports as $r) {
            $spend = (float) $r['spend'];
            $conversions = (float) $r['conversions'];
            $revenue = (float) $r['revenue'];

            $totals['spend'] += $spend;
            $totals['clicks'] += (int) $r['clicks'];
            $totals['impressions'] += (int) $r['impressions'];
            $totals['conversions'] += $conversions;
            $totals['revenue'] += $revenue;

            $byCampaign[] = [
                'campaign_id' => (int) $r['campaign_id'],
                'name' => $campaignNames[$r['campaign_id']] ?? ('#' . $r['campaign_id']),
                'spend' => round($spend, 2),
                'clicks' => (int) $r['clicks'],
                'conversions' => $conversions,
                'revenue' => round($revenue, 2),
                'cpa' => $conversions > 0 ? round($spend / $conversions, 2) : null,
                'roas' => $spend > 0 ? round($revenue / $spend, 2) : null,
            ];
        }

        usort($byCampaign, fn ($a, $b) => ($b['roas'] ?? -1) <=> ($a['roas'] ?? -1));
        $best = !empty($byCampaign) ? $byCampaign[0] : null;
        $worst = !empty($byCampaign) ? end($byCampaign) : null;

        $bestCopy = $this->bestCreative($campaignIds);
        $actions = $this->recentActions($userId, $since);

        return [
            'period' => $period,
            'since' => $since,
            'totals' => [
                'spend' => round($totals['spend'], 2),
                'clicks' => $totals['clicks'],
                'impressions' => $totals['impressions'],
                'conversions' => $totals['conversions'],
                'revenue' => round($totals['revenue'], 2),
                'cpa' => $totals['conversions'] > 0 ? round($totals['spend'] / $totals['conversions'], 2) : null,
                'roas' => $totals['spend'] > 0 ? round($totals['revenue'] / $totals['spend'], 2) : null,
            ],
            'best_campaign' => $best,
            'worst_campaign' => (count($byCampaign) > 1) ? $worst : null,
            'best_creative' => $bestCopy,
            'actions_taken' => $actions,
            'has_data' => !empty($byCampaign),
        ];
    }

    /**
     * ملخص KPIs لصفحة الـDashboard الرئيسية - نفس مصدر البيانات الحقيقي
     * بتاع generate() بالظبط، لكن بيضيف CTR/CPC/CPM وعدد الحملات النشطة/
     * المتوقفة واستخدام الميزانية، مع فلترة اختيارية بالمنصة والحالة.
     */
    public function dashboardSummary(int $userId, string $period = 'weekly', ?string $platform = null, ?string $status = null): array
    {
        $days = ['daily' => 1, 'weekly' => 7, 'monthly' => 30][$period] ?? 7;
        $since = date('Y-m-d', strtotime("-{$days} days"));

        $conditions = ['user_id' => $userId];
        if ($status) {
            $conditions['status'] = $status;
        }
        $allCampaigns = array_map(fn ($c) => $c->toArray(), (new AdCampaign())->where($conditions));

        if ($platform) {
            $connIds = array_column($this->db->query("SELECT id FROM platform_connections WHERE user_id = ? AND platform = ?", [$userId, $platform]), 'id');
            $allCampaigns = array_values(array_filter($allCampaigns, fn ($c) => in_array($c['platform_connection_id'], $connIds, true)));
        }

        $activeCount = count(array_filter($allCampaigns, fn ($c) => $c['status'] === 'active'));
        $pausedCount = count(array_filter($allCampaigns, fn ($c) => $c['status'] === 'paused'));
        $campaignIds = array_column($allCampaigns, 'id');

        $empty = [
            'spend' => null, 'conversions' => null, 'ctr' => null, 'cpc' => null, 'cpm' => null, 'roas' => null,
            'active_campaigns' => $activeCount, 'paused_campaigns' => $pausedCount, 'budget_utilization_pct' => null,
        ];

        if (empty($campaignIds)) {
            return $empty;
        }

        $placeholders = implode(',', array_fill(0, count($campaignIds), '?'));
        $rows = $this->db->query(
            "SELECT SUM(spend) as spend, SUM(clicks) as clicks, SUM(impressions) as impressions,
                    SUM(conversions) as conversions, SUM(revenue) as revenue
             FROM ad_performance_reports WHERE campaign_id IN ({$placeholders}) AND date_start >= ?",
            array_merge($campaignIds, [$since])
        );

        $row = $rows[0] ?? [];
        $spend = (float) ($row['spend'] ?? 0);
        $clicks = (int) ($row['clicks'] ?? 0);
        $impressions = (int) ($row['impressions'] ?? 0);
        $conversions = (float) ($row['conversions'] ?? 0);
        $revenue = (float) ($row['revenue'] ?? 0);

        if ($spend <= 0 && $clicks <= 0 && $impressions <= 0) {
            return $empty; // مفيش بيانات أداء مُزامنة فعليًا للفترة دي
        }

        $totalDailyBudget = array_sum(array_column(array_filter($allCampaigns, fn ($c) => $c['status'] === 'active'), 'daily_budget'));
        $expectedSpendForPeriod = $totalDailyBudget * $days;

        return [
            'spend' => round($spend, 2),
            'conversions' => $conversions,
            'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
            'cpc' => $clicks > 0 ? round($spend / $clicks, 2) : null,
            'cpm' => $impressions > 0 ? round(($spend / $impressions) * 1000, 2) : null,
            'roas' => ($spend > 0 && $revenue > 0) ? round($revenue / $spend, 2) : null,
            'active_campaigns' => $activeCount,
            'paused_campaigns' => $pausedCount,
            'budget_utilization_pct' => $expectedSpendForPeriod > 0 ? round(min(999, ($spend / $expectedSpendForPeriod) * 100), 1) : null,
        ];
    }

    /**
     * سلسلة زمنية يومية حقيقية (مش إجمالي مجمّع) لرسم Charts فعلية - بند
     * "Charts حقيقية" في طلب الـFrontend. كل صف من ad_performance_reports
     * له date_start فعلي من المزامنة، فمفيش أي تقريب أو اختلاق نقاط.
     */
    public function dailyTrend(int $userId, int $days = 30, ?int $onlyCampaignId = null): array
    {
        $since = date('Y-m-d', strtotime("-{$days} days"));

        if ($onlyCampaignId !== null) {
            $owned = (new AdCampaign())->find($onlyCampaignId);
            if (!$owned || (int) $owned->getAttribute('user_id') !== $userId) {
                return [];
            }
            $campaignIds = [$onlyCampaignId];
        } else {
            $campaignIds = array_column((new AdCampaign())->where(['user_id' => $userId]), 'id');
        }
        if (empty($campaignIds)) {
            return [];
        }

        $rows = $this->db->query(
            "SELECT date_start, SUM(spend) as spend, SUM(clicks) as clicks, SUM(conversions) as conversions, SUM(impressions) as impressions
             FROM ad_performance_reports
             WHERE campaign_id IN (" . implode(',', array_fill(0, count($campaignIds), '?')) . ") AND date_start >= ?
             GROUP BY date_start ORDER BY date_start ASC",
            array_merge($campaignIds, [$since])
        );

        return array_map(fn ($r) => [
            'date' => $r['date_start'],
            'spend' => round((float) $r['spend'], 2),
            'clicks' => (int) $r['clicks'],
            'conversions' => (float) $r['conversions'],
            'impressions' => (int) $r['impressions'],
        ], $rows);
    }

    /** مقارنة إنفاق/تحويلات حقيقية بين الحملات (للـBudget & Spend page) */
    public function campaignComparison(int $userId, string $period = 'weekly'): array
    {
        $days = ['daily' => 1, 'weekly' => 7, 'monthly' => 30][$period] ?? 7;
        $since = date('Y-m-d', strtotime("-{$days} days"));

        $campaigns = array_map(fn ($c) => $c->toArray(), (new AdCampaign())->where(['user_id' => $userId]));
        if (empty($campaigns)) {
            return [];
        }
        $campaignIds = array_column($campaigns, 'id');
        $names = array_column($campaigns, 'name', 'id');
        $budgets = array_column($campaigns, 'daily_budget', 'id');

        $rows = $this->db->query(
            "SELECT campaign_id, SUM(spend) as spend, SUM(conversions) as conversions
             FROM ad_performance_reports
             WHERE campaign_id IN (" . implode(',', array_fill(0, count($campaignIds), '?')) . ") AND date_start >= ?
             GROUP BY campaign_id",
            array_merge($campaignIds, [$since])
        );

        return array_map(fn ($r) => [
            'campaign_id' => (int) $r['campaign_id'],
            'name' => $names[$r['campaign_id']] ?? ('#' . $r['campaign_id']),
            'spend' => round((float) $r['spend'], 2),
            'conversions' => (float) $r['conversions'],
            'daily_budget' => $budgets[$r['campaign_id']] ?? null,
        ], $rows);
    }

    /**
     * ROAS حقيقي من الحجوزات المرتبطة فعليًا (attribution-based): مجموع
     * total_amount لحجوزات confirmed/completed اتئسبت لحملة (عبر روابط
     * UTM اللي الزائر كلَك عليها قبل الحجز - attributed_utm_link_id)
     * مقسوم على إجمالي إنفاق الحملة. بيختلف عن ROAS بتاع
     * ad_performance_reports اللي بييجي من revenue المنصة نفسها - ده
     * بيقيس العائد الفعلي بالفلوس اللي الحجوزات جابت.
     *
     * @return array<int, array{campaign_id:int, name:string, attributed_bookings:int,
     *                          attributed_revenue:float, spend:float, roas:?float}>
     */
    public function calculateRoas(int $userId): array
    {
        $campaigns = array_map(fn ($c) => $c->toArray(), (new AdCampaign())->where(['user_id' => $userId]));
        if (empty($campaigns)) {
            return [];
        }
        $campaignIds = array_column($campaigns, 'id');
        $placeholders = implode(',', array_fill(0, count($campaignIds), '?'));

        $rows = $this->db->query(
            "SELECT u.campaign_id,
                    COUNT(b.id) AS attributed_bookings,
                    COALESCE(SUM(b.total_amount), 0) AS attributed_revenue
             FROM bookings b
             JOIN ad_utm_links u ON u.id = b.attributed_utm_link_id
             WHERE b.status IN ('confirmed', 'completed')
               AND u.campaign_id IN ({$placeholders})
             GROUP BY u.campaign_id",
            $campaignIds
        );

        $names = array_column($campaigns, 'name', 'id');
        $spends = array_column($campaigns, 'spend', 'id');

        $result = [];
        foreach ($rows as $r) {
            $campaignId = (int) $r['campaign_id'];
            $spend = (float) ($spends[$campaignId] ?? 0);
            $revenue = round((float) $r['attributed_revenue'], 2);
            $result[] = [
                'campaign_id' => $campaignId,
                'name' => $names[$campaignId] ?? ('#' . $campaignId),
                'attributed_bookings' => (int) $r['attributed_bookings'],
                'attributed_revenue' => $revenue,
                'spend' => round($spend, 2),
                'roas' => $spend > 0 ? round($revenue / $spend, 2) : null,
            ];
        }

        usort($result, fn ($a, $b) => ($b['roas'] ?? -1) <=> ($a['roas'] ?? -1));
        return $result;
    }

    private function bestCreative(array $campaignIds): ?array
    {
        if (empty($campaignIds)) {
            return null;
        }
        $placeholders = implode(',', array_fill(0, count($campaignIds), '?'));
        $rows = $this->db->query(
            "SELECT id, campaign_id, headline, variant_label, performance_score FROM ad_copies
             WHERE campaign_id IN ({$placeholders}) AND performance_score IS NOT NULL
             ORDER BY performance_score DESC LIMIT 1",
            $campaignIds
        );
        return $rows[0] ?? null;
    }

    private function recentActions(int $userId, string $since): array
    {
        return $this->db->query(
            "SELECT action_type, mode, description, applied_automatically, created_at FROM ad_optimization_logs
             WHERE user_id = ? AND created_at >= ? ORDER BY created_at DESC LIMIT 20",
            [$userId, $since]
        );
    }

    private function emptyReport(string $period, string $since): array
    {
        return [
            'period' => $period, 'since' => $since,
            'totals' => ['spend' => 0, 'clicks' => 0, 'impressions' => 0, 'conversions' => 0, 'revenue' => 0, 'cpa' => null, 'roas' => null],
            'best_campaign' => null, 'worst_campaign' => null, 'best_creative' => null, 'actions_taken' => [],
            'has_data' => false,
            'note' => 'مفيش حملات على الحساب لسه',
        ];
    }
}
