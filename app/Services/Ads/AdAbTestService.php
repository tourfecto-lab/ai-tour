<?php

/**
 * Tourfecto - Ad A/B Testing Service (بند 2)
 * تجارب A/B على مستوى تنويعات الأصول الإعلانية (نص/صورة/فيديو) مع
 * توزيع نسب قابل للضبط (50/50...) ودلالة إحصائية حقيقية (chi-square
 * على CTR مع تصحيح Yates) تُحسب من بيانات أداء التنويعات الخام.
 *
 * مبدأ أساسي: لا اختراع بيانات. الدلالة الإحصائية تُحسب فقط من
 * impressions/clicks الفعلية المخزنة للتنويعات؛ لو البيانات غير كافية
 * (`reliable=false`) يُقال ذلك صراحة. التنبؤ بالفائز وثيقة إحصائية شفافة
 * (ليست ML black-box). عزل تينانت صارم عبر user_id.
 *
 * @version 1.0.0
 */
class AdAbTestService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ================================================================
    // إدارة التجارب
    // ================================================================

    /** إنشاء تجربة A/B على أصل إعلاني مملوك تابع لحملة مملوكة */
    public function createTest(int $ownerUserId, int $campaignId, int $creativeId, string $name): ?array
    {
        if (!$this->campaignOwnedBy($campaignId, $ownerUserId)) {
            return null;
        }
        $creative = (new AdCreative())->find($creativeId);
        if (!$creative
            || (int) $creative->getAttribute('user_id') !== $ownerUserId
            || (int) $creative->getAttribute('campaign_id') !== $campaignId) {
            return null;
        }
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('اسم التجربة مطلوب');
        }

        $test = new AdAbTest();
        $test->fill([
            'user_id' => $ownerUserId,
            'campaign_id' => $campaignId,
            'creative_id' => $creativeId,
            'name' => $name,
            'status' => 'draft',
        ]);
        $test->save();

        ActivityLog::record('ads', 'abtest.created', [
            'user_id' => $ownerUserId, 'subject_type' => 'ad_ab_tests',
            'subject_id' => (int) $test->getAttribute('id'),
        ]);

        return $this->get($ownerUserId, (int) $test->getAttribute('id'));
    }

    /** كل تجارب الحملة غير المؤرشفة */
    public function listForCampaign(int $ownerUserId, int $campaignId): array
    {
        if (!$this->campaignOwnedBy($campaignId, $ownerUserId)) {
            return [];
        }
        $tests = array_values(array_filter(
            (new AdAbTest())->where(['user_id' => $ownerUserId, 'campaign_id' => $campaignId], ['id' => 'DESC']),
            fn ($t) => $t->getAttribute('status') !== 'archived'
        ));
        return array_map(fn ($t) => $this->decorateTest($t->toArray()), $tests);
    }

    /** تجربة واحدة مع أذرعها وإحصائياتها */
    public function get(int $ownerUserId, int $testId): ?array
    {
        $test = $this->findOwned($ownerUserId, $testId);
        if (!$test || $test->getAttribute('status') === 'archived') {
            return null;
        }
        $data = $this->decorateTest($test->toArray());
        $data['variants'] = $this->armsWithMetrics($ownerUserId, $testId);
        $data['statistics'] = $this->statistics($ownerUserId, $testId);
        return $data;
    }

    // ================================================================
    // أذرع التجربة
    // ================================================================

    /** إضافة تنويع موجود لأصل التجربة كذراع بوزن نسبي */
    public function addVariant(int $ownerUserId, int $testId, int $creativeVariantId, int $weightPct = 50, bool $isControl = false): ?array
    {
        $test = $this->findOwnedActive($ownerUserId, $testId);
        if (!$test || $test->getAttribute('status') === 'completed') {
            return null;
        }
        $creativeVariant = (new AdCreativeVariant())->find($creativeVariantId);
        if (!$creativeVariant
            || (int) $creativeVariant->getAttribute('user_id') !== $ownerUserId
            || (int) $creativeVariant->getAttribute('creative_id') !== (int) $test->getAttribute('creative_id')) {
            return null;
        }
        $weightPct = $this->normalizeWeight($weightPct);

        $arm = new AdAbTestVariant();
        $arm->fill([
            'user_id' => $ownerUserId,
            'ab_test_id' => $testId,
            'creative_variant_id' => $creativeVariantId,
            'weight_pct' => $weightPct,
            'is_control' => (int) ($isControl ? 1 : 0),
        ]);
        $arm->save();

        ActivityLog::record('ads', 'abtest.variant_added', [
            'user_id' => $ownerUserId, 'subject_type' => 'ad_ab_test_variants',
            'subject_id' => (int) $arm->getAttribute('id'),
        ]);

        return $this->get($ownerUserId, $testId);
    }

    /** تحديث وزن ذراع */
    public function updateVariantWeight(int $ownerUserId, int $armId, int $weightPct): ?array
    {
        $arm = $this->findOwnedArm($ownerUserId, $armId);
        if (!$arm) {
            return null;
        }
        $test = $this->findOwned($ownerUserId, (int) $arm->getAttribute('ab_test_id'));
        if (!$test || $test->getAttribute('status') !== 'draft') {
            return null; // الأوزان قابلة للتعديل في حالة draft فقط
        }
        $arm->setAttribute('weight_pct', $this->normalizeWeight($weightPct));
        $arm->save();
        return $this->get($ownerUserId, (int) $arm->getAttribute('ab_test_id'));
    }

    /** إزالة ذراع من التجربة */
    public function removeVariant(int $ownerUserId, int $armId): ?array
    {
        $arm = $this->findOwnedArm($ownerUserId, $armId);
        if (!$arm) {
            return null;
        }
        $test = $this->findOwned($ownerUserId, (int) $arm->getAttribute('ab_test_id'));
        if (!$test || $test->getAttribute('status') !== 'draft') {
            return null;
        }
        $testId = (int) $arm->getAttribute('ab_test_id');
        $arm->delete();
        ActivityLog::record('ads', 'abtest.variant_removed', [
            'user_id' => $ownerUserId, 'subject_type' => 'ad_ab_test_variants', 'subject_id' => $armId,
        ]);
        return $this->get($ownerUserId, $testId);
    }

    // ================================================================
    // حالة التجربة
    // ================================================================

    public function startTest(int $ownerUserId, int $testId): ?array
    {
        $test = $this->findOwnedActive($ownerUserId, $testId);
        if (!$test) {
            return null;
        }
        $arms = (new AdAbTestVariant())->where(['user_id' => $ownerUserId, 'ab_test_id' => $testId]);
        if (count($arms) < 2) {
            throw new InvalidArgumentException('التجربة تحتاج ذراعين على الأقل');
        }
        $test->setAttribute('status', 'running');
        $test->setAttribute('started_at', date('Y-m-d H:i:s'));
        $test->save();
        return $this->get($ownerUserId, $testId);
    }

    /** إعلان الفائز (إنهاء التجربة) - winneVariantId = id لتنويع الأصول */
    public function completeTest(int $ownerUserId, int $testId, int $winnerCreativeVariantId): ?array
    {
        $test = $this->findOwnedActive($ownerUserId, $testId);
        if (!$test) {
            return null;
        }
        $arms = (new AdAbTestVariant())->where(['user_id' => $ownerUserId, 'ab_test_id' => $testId]);
        $winnerInTest = false;
        foreach ($arms as $arm) {
            if ((int) $arm->getAttribute('creative_variant_id') === $winnerCreativeVariantId) {
                $winnerInTest = true;
                break;
            }
        }
        if (!$winnerInTest) {
            throw new InvalidArgumentException('التنويع الفائز ليس ذراعًا في هذه التجربة');
        }
        $test->setAttribute('status', 'completed');
        $test->setAttribute('winning_variant_id', $winnerCreativeVariantId);
        $test->setAttribute('ended_at', date('Y-m-d H:i:s'));
        $test->save();
        return $this->get($ownerUserId, $testId);
    }

    public function archiveTest(int $ownerUserId, int $testId): bool
    {
        $test = $this->findOwned($ownerUserId, $testId);
        if (!$test || $test->getAttribute('status') === 'archived') {
            return false;
        }
        $test->setAttribute('status', 'archived');
        $test->save();
        return true;
    }

    // ================================================================
    // الدلالة الإحصائية
    // ================================================================

    /**
     * إحصائيات التجربة: لكل ذراع CTR فعلي + مقارنة chi-square 2x2 مع
     * التحكم (أو أول ذراع لو لا يوجد control معلّم). مفيش أي رقم مُختلق:
     * لو الانطباعات صفر أو الخلايا المتوقعة أقل من 5، `reliable=false`.
     * @return array{arms: array, comparisons: array, has_enough_data: bool}
     */
    public function statistics(int $ownerUserId, int $testId): array
    {
        $arms = $this->armsWithMetrics($ownerUserId, $testId);
        if (empty($arms)) {
            return ['arms' => [], 'comparisons' => [], 'has_enough_data' => false];
        }

        // تحديد الأساس (control أو أول ذراع له بيانات)
        $control = null;
        foreach ($arms as $arm) {
            if ($arm['is_control']) {
                $control = $arm;
                break;
            }
        }
        if ($control === null) {
            $control = $arms[0];
        }

        $comparisons = [];
        $hasEnoughData = true;
        foreach ($arms as $arm) {
            if ((int) $arm['creative_variant_id'] === (int) $control['creative_variant_id']) {
                $comparisons[] = [
                    'creative_variant_id' => (int) $arm['creative_variant_id'],
                    'is_control' => true,
                    'chi_square' => null,
                    'significant' => false,
                    'reliable' => false,
                    'ctr' => $arm['ctr'],
                ];
                continue;
            }
            $sig = self::chiSquare2x2(
                (int) $arm['clicks'], (int) $arm['impressions'],
                (int) $control['clicks'], (int) $control['impressions']
            );
            if (!$sig['reliable']) {
                $hasEnoughData = false;
            }
            $comparisons[] = [
                'creative_variant_id' => (int) $arm['creative_variant_id'],
                'is_control' => false,
                'chi_square' => $sig['chi_square'],
                'significant' => $sig['significant'],
                'reliable' => $sig['reliable'],
                'ctr' => $arm['ctr'],
                'control_ctr' => $control['ctr'],
            ];
        }

        return ['arms' => $arms, 'comparisons' => $comparisons, 'has_enough_data' => $hasEnoughData];
    }

    /**
     * تنبؤ الفائز: الذراع الأعلى CTR مع دلالة إحصائية مقابل التحكم؛ لو
     * مفيش ذراع مؤثر، نرجّع صاحب أعلى CTR مع `significant=false` وسبب واضح.
     * @return array{predicted_winner_id:?int, ctr:?float, significant:bool, reliable:bool, reason:string}
     */
    public function predictWinner(int $ownerUserId, int $testId): array
    {
        $stats = $this->statistics($ownerUserId, $testId);
        if (empty($stats['arms'])) {
            return [
                'predicted_winner_id' => null, 'ctr' => null,
                'significant' => false, 'reliable' => false, 'reason' => 'لا توجد أذرع في التجربة',
            ];
        }

        // أعلى CTR إجمالًا (بشرط بيانات فعلية)
        $leader = null;
        foreach ($stats['arms'] as $arm) {
            if ($arm['ctr'] === null) {
                continue;
            }
            if ($leader === null || $arm['ctr'] > $leader['ctr']) {
                $leader = $arm;
            }
        }
        if ($leader === null) {
            return [
                'predicted_winner_id' => null, 'ctr' => null,
                'significant' => false, 'reliable' => false, 'reason' => 'لا توجد بيانات أداء فعلية للتنويعات بعد',
            ];
        }

        // هل الفارق عن التحكم دال إحصائيًا؟
        $significant = false;
        $reliable = false;
        $bestSig = null;
        foreach ($stats['comparisons'] as $cmp) {
            if ($cmp['is_control'] || $cmp['creative_variant_id'] !== (int) $leader['creative_variant_id']) {
                continue;
            }
            $bestSig = $cmp;
            break;
        }
        if ($bestSig !== null) {
            $significant = (bool) $bestSig['significant'];
            $reliable = (bool) $bestSig['reliable'];
        }

        $reason = $significant
            ? 'فارق CTR دال إحصائيًا (chi-square ≥ 3.841 عند p<0.05) مقابل التنويع الأفضل'
            : ($reliable
                ? 'أعلى CTR لكن الفارق لم يبلغ الدلالة الإحصائية بعد — يُنصح بجمع المزيد من البيانات'
                : 'البيانات الحالية غير كافية إحصائيًا (خلايا متوقعة أقل من 5) — أعلى CTR مبدئي فقط');

        return [
            'predicted_winner_id' => (int) $leader['creative_variant_id'],
            'ctr' => $leader['ctr'],
            'significant' => $significant,
            'reliable' => $reliable,
            'reason' => $reason,
        ];
    }

    /**
     * توزيع الحركة: اختيار ذراع للتجربة الجارية (weighted random حسب
     * الأوزان النسبية). لو لا توجد تجربة جارية على الأصل، يرجع null.
     * @return array{creative_variant_id:?int, variant_label:?string, ab_test_id:?int, weight_pct:?int}
     */
    public function pickVariantForTraffic(int $ownerUserId, int $creativeId): array
    {
        $tests = array_values(array_filter(
            (new AdAbTest())->where(['user_id' => $ownerUserId, 'creative_id' => $creativeId]),
            fn ($t) => $t->getAttribute('status') === 'running'
        ));
        if (empty($tests)) {
            return ['creative_variant_id' => null, 'variant_label' => null, 'ab_test_id' => null, 'weight_pct' => null];
        }
        $test = $tests[0];

        $arms = (new AdAbTestVariant())->where(['user_id' => $ownerUserId, 'ab_test_id' => (int) $test->getAttribute('id')]);
        if (empty($arms)) {
            return ['creative_variant_id' => null, 'variant_label' => null, 'ab_test_id' => (int) $test->getAttribute('id'), 'weight_pct' => null];
        }

        // بناء نطاقات الأوزان ثم اختيار رقم عشوائي - توزيع حقيقي مبني على
        // الأوزان المحفوظة (50/50...) وليس على أي بيانات مُختلقة.
        $buckets = [];
        $cursor = 0;
        foreach ($arms as $arm) {
            $weight = max(1, (int) $arm->getAttribute('weight_pct'));
            $cursor += $weight;
            $buckets[] = ['arm' => $arm, 'to' => $cursor];
        }
        $roll = random_int(1, $cursor);

        $picked = null;
        foreach ($buckets as $bucket) {
            if ($roll <= $bucket['to']) {
                $picked = $bucket['arm'];
                break;
            }
        }
        if ($picked === null) {
            $picked = end($arms);
        }

        $creativeVariant = (new AdCreativeVariant())->find((int) $picked->getAttribute('creative_variant_id'));

        return [
            'creative_variant_id' => (int) $picked->getAttribute('creative_variant_id'),
            'variant_label' => $creativeVariant ? $creativeVariant->getAttribute('variant_label') : null,
            'ab_test_id' => (int) $test->getAttribute('id'),
            'weight_pct' => (int) $picked->getAttribute('weight_pct'),
        ];
    }

    // ================================================================
    // مساعدون
    // ================================================================

    /** أذرع التجربة مع بيانات أداء التنويع الخام + CTR محسوب */
    private function armsWithMetrics(int $ownerUserId, int $testId): array
    {
        $arms = (new AdAbTestVariant())->where(['user_id' => $ownerUserId, 'ab_test_id' => $testId], ['id' => 'ASC']);
        $out = [];
        foreach ($arms as $arm) {
            $v = (new AdCreativeVariant())->find((int) $arm->getAttribute('creative_variant_id'));
            if (!$v || (int) $v->getAttribute('user_id') !== $ownerUserId) {
                continue;
            }
            $va = $v->toArray();
            $impressions = (int) ($va['impressions'] ?? 0);
            $clicks = (int) ($va['clicks'] ?? 0);
            $spend = (float) ($va['spend'] ?? 0);
            $conversions = (float) ($va['conversions'] ?? 0);
            $out[] = [
                'id' => (int) $arm->getAttribute('id'),
                'ab_test_id' => $testId,
                'creative_variant_id' => (int) $arm->getAttribute('creative_variant_id'),
                'variant_label' => $v->getAttribute('variant_label'),
                'weight_pct' => (int) $arm->getAttribute('weight_pct'),
                'is_control' => (int) $arm->getAttribute('is_control'),
                'impressions' => $impressions,
                'clicks' => $clicks,
                'spend' => $spend,
                'conversions' => $conversions,
                'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 3) : null,
            ];
        }
        return $out;
    }

    private function decorateTest(array $test): array
    {
        $test['arms_count'] = (int) $this->scalar(
            "SELECT COUNT(*) FROM ad_ab_test_variants WHERE user_id = ? AND ab_test_id = ?",
            [(int) $test['user_id'], (int) $test['id']]
        );
        return $test;
    }

    /**
     * اختبار chi-squared (2x2) مع تصحيح Yates لمقارنة CTR بين ذراعين.
     * نفس المنهجية الموثقة في SeoAbTestService::chiSquare2x2 — الخلايا:
     * a=نقرات الذراع، b=غير نقراته، c=نقرات الأساس، d=غير نقراته.
     * @return array{chi_square:float, significant:bool, reliable:bool}
     */
    public static function chiSquare2x2(int $aClicks, int $aImp, int $bClicks, int $bImp): array
    {
        $a = $aClicks;
        $b = max(0, $aImp - $aClicks);
        $c = $bClicks;
        $d = max(0, $bImp - $bClicks);

        $n = $a + $b + $c + $d;
        if ($n === 0) {
            return ['chi_square' => 0.0, 'significant' => false, 'reliable' => false];
        }

        $row1 = $a + $b;
        $row2 = $c + $d;
        $col1 = $a + $c;
        $col2 = $b + $d;

        $expected = [$row1 * $col1 / $n, $row1 * $col2 / $n, $row2 * $col1 / $n, $row2 * $col2 / $n];
        $reliable = min($expected) >= 5;

        if ($row1 === 0 || $row2 === 0 || $col1 === 0 || $col2 === 0) {
            return ['chi_square' => 0.0, 'significant' => false, 'reliable' => false];
        }

        $num = abs($a * $d - $b * $c) - ($n / 2);
        $chi = ($n * $num * $num) / ($row1 * $row2 * $col1 * $col2);

        return [
            'chi_square' => round($chi, 3),
            'significant' => $chi > 3.841, // p < 0.05 عند درجة حرية واحدة
            'reliable' => $reliable,
        ];
    }

    private function normalizeWeight(int $weightPct): int
    {
        return max(1, min(100, $weightPct));
    }

    private function findOwned(int $ownerUserId, int $testId): ?AdAbTest
    {
        $test = (new AdAbTest())->find($testId);
        if (!$test || (int) $test->getAttribute('user_id') !== $ownerUserId) {
            return null;
        }
        return $test;
    }

    private function findOwnedActive(int $ownerUserId, int $testId): ?AdAbTest
    {
        $test = $this->findOwned($ownerUserId, $testId);
        if (!$test || $test->getAttribute('status') === 'archived') {
            return null;
        }
        return $test;
    }

    private function findOwnedArm(int $ownerUserId, int $armId): ?AdAbTestVariant
    {
        $arm = (new AdAbTestVariant())->find($armId);
        if (!$arm || (int) $arm->getAttribute('user_id') !== $ownerUserId) {
            return null;
        }
        return $arm;
    }

    private function campaignOwnedBy(int $campaignId, int $ownerUserId): bool
    {
        $campaign = (new AdCampaign())->find($campaignId);
        return $campaign !== null && (int) $campaign->getAttribute('user_id') === $ownerUserId;
    }

    private function scalar(string $sql, array $params)
    {
        $rows = $this->db->query($sql, $params);
        if (empty($rows)) {
            return 0;
        }
        return reset($rows[0]);
    }
}
