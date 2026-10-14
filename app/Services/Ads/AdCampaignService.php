<?php

/**
 * Tourfecto - Ad Campaign Service (إدارة الإعلانات)
 * لا يوجد أي موديول مرفوع يغطي الإعلانات - هذا بناء جديد بالكامل بنفس
 * نمط باقي الخدمات الموحّدة. إدارة الحملات نفسها (CRUD + إحصائيات) تعمل
 * بشكل كامل الآن؛ مزامنة الأداء الفعلي (impressions/clicks/spend) من
 * Google Ads/Meta Ads تحتاج ربط OAuth حقيقي بنفس نمط GoogleBusinessAPI.php
 * الموجود، عبر platform_connections (platform = google_ads/meta_ads).
 * @version 1.1.0
 */
class AdCampaignService
{
    /**
     * إنشاء حملة. لو الطلب فيه `audience` أو `copies` أو `budget_recommendation`
     * (من ويزارد الذكاء الاصطناعي)، بيتحفظوا كلهم مع بعض في معاملة واحدة
     * (transaction) عشان الحملة متتحفظش أبدًا من غير جمهورها ونصوصها لو
     * حصل خطأ نص الطريق.
     */
    public function create(int $userId, array $data): AdCampaign
    {
        $db = Database::getInstance();
        $db->beginTransaction();

        try {
            $platform = $data['platform'] ?? 'manual';
            if (!in_array($platform, ['manual', 'meta_ads', 'google_ads'], true)) {
                $platform = 'manual';
            }
            $status = $data['status'] ?? 'draft';
            if (!in_array($status, ['draft', 'active', 'paused', 'completed', 'removed'], true)) {
                $status = 'draft';
            }
            $campaign = new AdCampaign([
                'user_id' => $userId,
                'website_id' => $data['website_id'] ?? null,
                'platform_connection_id' => $data['platform_connection_id'] ?? null,
                'platform' => $platform,
                'name' => $data['name'],
                'objective' => $data['objective'] ?? null,
                'product_or_service' => $data['product_or_service'] ?? null,
                'target_audience_brief' => $data['target_audience_brief'] ?? null,
                'target_countries_json' => !empty($data['target_countries_json'])
                    ? (is_array($data['target_countries_json']) ? json_encode($data['target_countries_json'], JSON_UNESCAPED_UNICODE) : (string) $data['target_countries_json'])
                    : null,
                'landing_page_url' => $data['landing_page_url'] ?? null,
                'daily_budget' => $data['daily_budget'] !== null && $data['daily_budget'] !== '' ? $data['daily_budget'] : null,
                'budget_total' => $data['budget_total'] ?? null,
                'currency' => $data['currency'] ?? 'USD',
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'ai_generated' => !empty($data['ai_generated']) ? 1 : 0,
                'status' => $status,
            ]);
            $campaign->save();
            $campaignId = (int) $campaign->getAttribute('id');

            if (!empty($data['audience']) && is_array($data['audience'])) {
                $a = $data['audience'];
                $audience = new AdAudience([
                    'campaign_id' => $campaignId,
                    'name' => 'الجمهور المقترح',
                    'age_min' => $a['age_min'] ?? null,
                    'age_max' => $a['age_max'] ?? null,
                    'genders' => $a['genders'] ?? 'all',
                    'locations_json' => json_encode($a['locations'] ?? [], JSON_UNESCAPED_UNICODE),
                    'interests_json' => json_encode($a['interests'] ?? [], JSON_UNESCAPED_UNICODE),
                    'ai_generated' => !empty($data['ai_generated']) ? 1 : 0,
                ]);
                $audience->save();
            }

            if (!empty($data['budget_recommendation']) && is_array($data['budget_recommendation'])) {
                $b = $data['budget_recommendation'];
                if (!empty($b['recommended_daily_budget'])) {
                    $rec = new AdBudgetRecommendation([
                        'campaign_id' => $campaignId,
                        'recommended_daily_budget' => $b['recommended_daily_budget'],
                        'bid_strategy' => $b['bid_strategy'] ?? null,
                        'reasoning' => $b['reasoning'] ?? null,
                    ]);
                    $rec->save();
                }
            }

            if (!empty($data['copies']) && is_array($data['copies'])) {
                $labels = ['A', 'B', 'C', 'D', 'E'];
                foreach (array_slice($data['copies'], 0, 5) as $i => $c) {
                    if (empty($c['headline'])) {
                        continue;
                    }
                    $copy = new AdCopy([
                        'campaign_id' => $campaignId,
                        'headline' => $c['headline'] ?? null,
                        'description' => $c['description'] ?? null,
                        'primary_text' => $c['primary_text'] ?? null,
                        'call_to_action' => $c['call_to_action'] ?? null,
                        'variant_label' => $c['variant_label'] ?? ($labels[$i] ?? (string) ($i + 1)),
                        'ai_generated' => !empty($data['ai_generated']) ? 1 : 0,
                        'status' => 'pending_review',
                    ]);
                    $copy->save();
                }
            }

            if (!empty($data['keywords']) && is_array($data['keywords'])) {
                foreach (array_slice($data['keywords'], 0, 30) as $k) {
                    $keywordText = is_array($k) ? ($k['keyword'] ?? '') : (string) $k;
                    $keywordText = trim($keywordText);
                    if ($keywordText === '') {
                        continue;
                    }

                    $matchType = is_array($k) && in_array($k['match_type'] ?? '', ['exact', 'phrase', 'broad', 'negative'], true)
                        ? $k['match_type'] : 'phrase';

                    $keyword = new AdKeyword([
                        'campaign_id' => $campaignId,
                        'keyword' => mb_substr($keywordText, 0, 255),
                        'match_type' => $matchType,
                        'ai_relevance_score' => is_array($k) ? ($k['ai_relevance_score'] ?? null) : null,
                        'estimated_search_volume' => is_array($k) ? ($k['estimated_search_volume'] ?? null) : null,
                        'estimated_cpc' => is_array($k) ? ($k['estimated_cpc'] ?? null) : null,
                        'ai_generated' => !empty($data['ai_generated']) ? 1 : 0,
                    ]);
                    $keyword->save();
                }
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }

        ActivityLog::record('ads', 'campaign.created', [
            'user_id' => $userId, 'subject_type' => 'ad_campaigns', 'subject_id' => (int) $campaign->getAttribute('id'),
            'meta' => ['ai_generated' => !empty($data['ai_generated'])],
        ]);

        return $campaign;
    }

    /**
     * لاحظ إن الـmethod دي اتحوّلت لـSQL مباشر بدل Model::where() - Model::where()
     * مبيقدرش يعبّر عن `deleted_at IS NULL` (بيولّد `deleted_at = ?` دايمًا
     * حتى لو القيمة NULL، وده مش بيطابق صفوف NULL في SQL). التغيير ده
     * بيحافظ على نفس السلوك القديم بالظبط (كل حملات المستخدم مرتّبة
     * بالأحدث) + استبعاد الحملات المحذوفة (Soft Delete) اللي محتاجة
     * الاستبعاد ده أصلًا عشان الميزة تشتغل صح.
     */
    public function listForUser(int $userId): array
    {
        $rows = Database::getInstance()->query(
            "SELECT * FROM ad_campaigns WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC",
            [$userId]
        );
        return array_map(fn ($r) => new AdCampaign($r), $rows);
    }

    /**
     * نسخة Server-side كاملة من listForUser(): بحث (LIKE على الاسم)،
     * فلترة (حالة/منصة)، ترتيب، وTرقيم صفحات حقيقي (LIMIT/OFFSET) - مطلوبة
     * لأداء صفحة "الحملات" لأي حساب فيه عدد كبير من الحملات (بند 31 من
     * طلب الـFrontend "Performance"). listForUser() الأصلية فضلت زي ما هي
     * من غير أي تعديل - أي استدعاء قديم ليها لسه شغال بالظبط زي الأول.
     *
     * @return array{campaigns: array, total: int, page: int, per_page: int}
     */
    public function listForUserPaginated(int $userId, array $filters = []): array
    {
        $db = Database::getInstance();

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $allowedSorts = ['created_at', 'name', 'spend', 'daily_budget', 'status'];
        $sortField = in_array($filters['sort'] ?? '', $allowedSorts, true) ? $filters['sort'] : 'created_at';
        $sortDir = (($filters['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';

        $where = ['user_id = ?', 'deleted_at IS NULL'];
        $params = [$userId];

        if (!empty($filters['search'])) {
            $where[] = 'name LIKE ?';
            $params[] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['platform_connection_id'])) {
            $where[] = 'platform_connection_id = ?';
            $params[] = (int) $filters['platform_connection_id'];
        }

        $whereSql = implode(' AND ', $where);

        $totalRow = $db->query("SELECT COUNT(*) as cnt FROM ad_campaigns WHERE {$whereSql}", $params);
        $total = (int) ($totalRow[0]['cnt'] ?? 0);

        $rows = $db->query(
            "SELECT * FROM ad_campaigns WHERE {$whereSql} ORDER BY {$sortField} {$sortDir} LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return ['campaigns' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }
}
