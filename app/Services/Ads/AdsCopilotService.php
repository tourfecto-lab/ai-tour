<?php

/**
 * Tourfecto - Ads Copilot Service
 * مساعد ذكاء اصطناعي لموديول الإعلانات: بيرد على أسئلة العميل عن أداء
 * حسابه (باستخدام بياناته الحقيقية من ad_campaigns/ad_performance_reports)
 * وبينفّذ أوامر مالية صريحة (زيادة/تخفيض ميزانية حملة، إيقاف/تشغيل حملة)
 * عبر نفس مسار AdAutopilotEngine::applyExplicitRecommendation() بالظبط -
 * مفيش أي طريق مختصر يتجاوز الـGuardrails حتى لو الأمر جاي من الشات.
 * @version 1.0.0
 */
class AdsCopilotService
{
    /** @var GeminiClient */
    private $ai;

    private Database $db;

    public function __construct(?GeminiClient $ai = null)
    {
        $this->ai = $ai ?? new GeminiClient();
        $this->db = Database::getInstance();
    }

    /**
     * @return array{reply: string}
     */
    public function ask(int $userId, string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            return ['reply' => 'اكتب سؤال أو أمر واضح، مثل: "إيه أداء حملاتي آخر أسبوع؟" أو "زود ميزانية حملة رحلات البحر لـ 50"'];
        }

        // 1) محاولة فهم أمر تنفيذي صريح (تعديل ميزانية/حالة) على حملة محددة
        $command = $this->parseCommand($userId, $message);
        if ($command !== null) {
            $reply = $this->executeCommand($userId, $command);
            return ['reply' => $reply];
        }

        // 2) رد استشاري على سؤال عام باستخدام بيانات حقيقية + سياق الحملات
        return ['reply' => $this->answerQuestion($userId, $message)];
    }

    // ================================================================
    // تنفيذ الأوامر الصريحة عبر مسار الـAutopilot نفسه
    // ================================================================

    private function executeCommand(int $userId, array $command): string
    {
        $campaign = $command['campaign'];

        $engine = new AdAutopilotEngine();
        $rec = $command['recommendation'];

        // معاملة غير مدعومة للحالة دي: إيقاف/تشغيل مباشر عبر حالة الحملة
        if ($command['type'] === 'toggle_status') {
            $newStatus = $command['new_status'];
            $statusResult = $this->changeStatusManually($userId, $campaign, $newStatus);
            return $this->formatStatusReply($campaign, $newStatus, $statusResult);
        }

        $result = $engine->applyExplicitRecommendation($userId, $campaign, $rec);

        return $this->formatBudgetReply($campaign, $rec, $result);
    }

    private function formatBudgetReply(AdCampaign $campaign, array $rec, array $result): string
    {
        $name = (string) $campaign->getAttribute('name');
        $reasoning = (string) ($rec['reasoning'] ?? '');

        $actionLabel = $rec['action_type'] === 'increase_budget' ? 'زيادة الميزانية' : 'تخفيض الميزانية';

        switch ($result['status'] ?? '') {
            case 'executed':
                $after = $result['after'] ?? $rec['after_daily_budget'];
                return "تم {$actionLabel} لحملة \"{$name}\" فعليًا إلى {$after} يوميًا عبر المنصة.\n\nملاحظة من التحليل: {$reasoning}";
            case 'pending_approval':
                $blocked = !empty($result['blocked_reason']) ? "\n\nالسبب: {$result['blocked_reason']}" : '';
                return "اقترح {$actionLabel} لحملة \"{$name}\" إلى {$rec['after_daily_budget']} يوميًا، وبما إن وضع الـAutopilot عندك \"موافقة\" أو القرار تجاوز حد آمن، اتحوّل لانتظار موافقتك من صفحة الـAutopilot.{$blocked}";
            case 'recommendation_only':
                return "حسب إعداداتك (الوضع اليدوي)، مقدرش أنفّذ تغيير ميزانية تلقائيًا. التوصية لحملة \"{$name}\": {$rec['after_daily_budget']} يوميًا بدل {$rec['current_daily_budget']}.\n\nالسبب: {$reasoning}\n\nلو عايزني أنفّذ تلقائيًا، فعّل وضع الـAutopilot من إعداداته.";
            default:
                return "التوصية لحملة \"{$name}\": {$rec['after_daily_budget']} يوميًا ({$reasoning}). لو حصل خطأ في التنفيذ الفعلي، راجع سجل الـAutopilot للتفاصيل.";
        }
    }

    private function formatStatusReply(AdCampaign $campaign, string $newStatus, array $result): string
    {
        $name = (string) $campaign->getAttribute('name');
        if ($result['success']) {
            return "تم {$this->statusLabel($newStatus)} حملة \"{$name}\" بنجاح على المنصة.";
        }
        return "تعذّر {$this->statusLabel($newStatus)} حملة \"{$name}\": " . ($result['error'] ?? 'خطأ غير معروف');
    }

    private function statusLabel(string $status): string
    {
        return $status === 'paused' ? 'إيقاف' : 'تشغيل';
    }

    private function changeStatusManually(int $userId, AdCampaign $campaign, string $newStatus): array
    {
        $connId = $campaign->getAttribute('platform_connection_id');
        $externalId = (string) $campaign->getAttribute('external_campaign_id');
        if (!$connId || $externalId === '') {
            return ['success' => false, 'error' => 'الحملة دي لسه مش متزامنة مع منصة حقيقية'];
        }

        $conn = (new PlatformConnection())->find((int) $connId);
        if (!$conn || $conn->getAttribute('status') !== 'connected') {
            return ['success' => false, 'error' => 'الربط بالمنصة غير متاح'];
        }

        $encryption = new Encryption();
        $accessToken = $encryption->decrypt((string) $conn->getAttribute('access_token'));
        $platform = (string) $conn->getAttribute('platform');

        try {
            if ($platform === 'meta_ads') {
                $api = new MetaAdsAPI($accessToken);
                $result = $newStatus === 'paused' ? $api->pauseCampaign($externalId) : $api->resumeCampaign($externalId);
            } elseif ($platform === 'google_ads') {
                $api = new GoogleAdsAPI($accessToken);
                $customerId = (string) $conn->getAttribute('external_account_id');
                $resource = "customers/{$customerId}/campaigns/{$externalId}";
                $result = $newStatus === 'paused' ? $api->pauseCampaign($customerId, $resource) : $api->resumeCampaign($customerId, $resource);
            } else {
                return ['success' => false, 'error' => "منصة غير مدعومة: {$platform}"];
            }

            if (!($result['success'] ?? false)) {
                return ['success' => false, 'error' => $result['error'] ?? 'فشل التنفيذ على المنصة'];
            }

            $previousStatus = (string) $campaign->getAttribute('status');
            $campaign->setAttribute('status', $newStatus);
            $campaign->save();

            $log = new AdOptimizationLog([
                'campaign_id' => (int) $campaign->getAttribute('id'),
                'user_id' => $userId,
                'action_type' => $newStatus === 'paused' ? 'pause_campaign' : 'resume_campaign',
                'mode' => 'manual',
                'description' => 'أمر صريح من العميل عبر AI Copilot',
                'before_value' => $previousStatus,
                'after_value' => $newStatus,
                'applied_automatically' => 1,
                'external_result' => 'success',
                'can_rollback' => 1,
            ]);
            $log->save();

            return ['success' => true];
        } catch (Exception $e) {
            Logger::error('AdsCopilotService changeStatusManually Error', ['message' => $e->getMessage()]);
            return ['success' => false, 'error' => 'تعذر التنفيذ'];
        }
    }

    // ================================================================
    // فهم الأوامر
    // ================================================================

    /**
     * يحاول فهم أمر صريح من الرسالة. بيدوّر على حملة (بالاسم أو الرقم)
     * جوه حملات المستخدم الحقيقية، وبيميّز بين:
     *   - زيادة/تخفيض الميزانية بمبلغ محدد
     *   - إيقاف/تشغيل حملة
     * @return array|null
     */
    private function parseCommand(int $userId, string $message): ?array
    {
        $campaigns = (new AdCampaign())->where(['user_id' => $userId]);

        $target = $this->findTargetCampaign($campaigns, $message);
        if ($target === null) {
            return null;
        }
        $campaign = $target;

        // أرقام مذكورة في الرسالة (ميزانية مقترحة)
        preg_match_all('/\b(\d+(?:[.,]\d+)?)\b/', $message, $matches);
        $numbers = array_map('floatval', str_replace(',', '.', $matches[1]));

        $currentBudget = (float) ($campaign->getAttribute('daily_budget') ?: 0);

        // إيقاف/تشغيل
        if (preg_match('/\b(اوقف|وقّف|أوقف|ايقاف|إيقاف|pause|stop)\b/i', $message) && !preg_match('/\b(ميزانية|ميزانيه|budget|زود|خفّض|خفض|زود|اكتر|اقل|أقل)\b/i', $message)) {
            return ['type' => 'toggle_status', 'campaign' => $campaign, 'new_status' => 'paused'];
        }
        if (preg_match('/\b(شغّل|شغل|فعّل|فعل|استأنف|start|resume|enable)\b/i', $message) && !preg_match('/\b(ميزانية|ميزانيه|budget)\b/i', $message)) {
            return ['type' => 'toggle_status', 'campaign' => $campaign, 'new_status' => 'active'];
        }

        // تعديل ميزانية
        $increase = preg_match('/\b(زود|زودي|زيّد|ارفع|كبّر|زيادة|increase|raise|اكثر|أكثر)\b/i', $message);
        $decrease = preg_match('/\b(خفّض|خفض|قلّل|اقلل|نقص|نقّص|انقص|تخفيض|انخفاض|decrease|reduce|lower|اقل|أقل)\b/i', $message);

        $targetBudget = null;
        foreach ($numbers as $n) {
            // رقم معقول للميزانية اليومية (أكبر من 0 وأقل من مليون)
            if ($n > 0 && $n < 1000000) {
                $targetBudget = $n;
                break;
            }
        }

        if ($increase || $decrease) {
            if ($targetBudget === null) {
                // من غير رقم محدد - زيادة/تخفيض نسبية آمنة
                $pct = $increase ? 0.15 : 0.25;
                $targetBudget = $increase
                    ? $currentBudget + ($currentBudget * $pct)
                    : max(0.0, $currentBudget - ($currentBudget * $pct));
            }

            $actionType = ($targetBudget > $currentBudget) ? 'increase_budget' : 'decrease_budget';
            $after = round($targetBudget, 2);

            return [
                'type' => 'budget',
                'campaign' => $campaign,
                'recommendation' => [
                    'action_type' => $actionType,
                    'current_daily_budget' => $currentBudget,
                    'after_daily_budget' => $after,
                    'reasoning' => 'طلب صريح من العميل عبر AI Copilot: ' . $message,
                    'confidence_level' => 'confirmed_signal',
                ],
            ];
        }

        return null;
    }

    /** يدوّر على حملة للمستخدم (مذكورة بالاسم أو برقم قريب من الاسم) */
    private function findTargetCampaign(array $campaigns, string $message): ?AdCampaign
    {
        // الرقم اللي بيبدأ بـ# أو قبل كلمة "حملة" - بيشير لمعرّف/رقم الحملة
        preg_match('/#(\d+)/', $message, $m);
        if (!empty($m)) {
            foreach ($campaigns as $c) {
                if ((string) $c->getAttribute('id') === $m[1]) {
                    return $c;
                }
            }
        }

        // بحث بالاسم: أول حملة اسمه مذكور حرفيًا جوه الرسالة
        $best = null;
        $bestLen = 0;
        foreach ($campaigns as $c) {
            $name = (string) $c->getAttribute('name');
            if ($name === '') {
                continue;
            }
            if (mb_stripos($message, $name) !== false && mb_strlen($name) > $bestLen) {
                $best = $c;
                $bestLen = mb_strlen($name);
            }
        }

        return $best;
    }

    // ================================================================
    // الأسئلة الاستشارية العامة
    // ================================================================

    private function answerQuestion(int $userId, string $message): string
    {
        $summary = $this->buildAccountSummary($userId);

        $prompt = <<<PROMPT
انت مساعد إعلانات خبير لصاحب عمل سياحي على منصة Tourfecto. أجب عن سؤال العميل بوضوح وبالعربي المصري الاحترافي، معتمدًا على بياناته الحقيقية دي:

{$summary}

سؤال العميل: "{$message}"

القواعد:
- لو السؤال عن أداء رقمي، استخدم الأرقام الفعلية فوق ولسه مفيش بيانات كافية قولها صراحة بدل ما تخترع أرقام.
- لو العميل بيطلب تنفيذ تغيير (تعديل ميزانية/إيقاف/تشغيل) على حملة، قوله اذكر اسم الحملة بوضوح أو استخدم أمر صريح زي "زود ميزانية حملة X لـ 50".
- الرد 3 إلى 8 جمل، عملي ومباشر، من غير أرقام مختلقة.
PROMPT;

        $response = $this->ai->generateContent($prompt, ['task'=>'ads_copilot', 'maxOutputTokens' => 2048]);
        if (!($response['success'] ?? false)) {
            return 'تعذر الاتصال بمحرك الذكاء الاصطناعي حاليًا - جرّب تاني بعد قليل.';
        }

        $text = trim((string) ($response['data'] ?? ''));
        return $text !== '' ? mb_substr($text, 0, 3000) : 'تعذر توليد الرد حاليًا.';
    }

    /** ملخص حساب حقيقي مش مختلق - بيتبعت للذكاء الاصطناعي كسياق للرد */
    private function buildAccountSummary(int $userId): string
    {
        $rows = $this->db->query(
            "SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                    COALESCE(SUM(spend), 0) AS total_spend, COALESCE(SUM(impressions), 0) AS total_impressions,
                    COALESCE(SUM(clicks), 0) AS total_clicks
             FROM ad_campaigns WHERE user_id = ? AND deleted_at IS NULL",
            [$userId]
        );
        $row = $rows[0] ?? [];

        $campaignRows = $this->db->query(
            "SELECT name, status, daily_budget, spend, impressions, clicks
             FROM ad_campaigns WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 10",
            [$userId]
        );

        $campaignLines = [];
        foreach ($campaignRows as $c) {
            $campaignLines[] = "- {$c['name']} (الحالة: {$c['status']}, ميزانية يومية: {$c['daily_budget']}, إنفاق: {$c['spend']}, ظهور: {$c['impressions']}, نقرات: {$c['clicks']})";
        }
        $campaignsText = !empty($campaignLines) ? implode("\n", $campaignLines) : '- لا توجد حملات بعد';

        $reportRows = $this->db->query(
            "SELECT COALESCE(SUM(r.spend),0) AS spend, COALESCE(SUM(r.conversions),0) AS conversions,
                    COALESCE(SUM(r.revenue),0) AS revenue
             FROM ad_performance_reports r JOIN ad_campaigns c ON c.id = r.campaign_id
             WHERE c.user_id = ? AND c.deleted_at IS NULL",
            [$userId]
        );
        $rep = $reportRows[0] ?? [];

        return "ملخص حساب المستخدم:
- عدد الحملات: " . (int) ($row['total'] ?? 0) . " (منها نشطة: " . (int) ($row['active'] ?? 0) . ")
- إجمالي الإنفاق المسجل: " . (float) ($row['total_spend'] ?? 0) . "
- إجمالي الظهور: " . (float) ($row['total_impressions'] ?? 0) . " | إجمالي النقرات: " . (float) ($row['total_clicks'] ?? 0) . "
- أداء مُزامن (آخر تقارير): إنفاق " . (float) ($rep['spend'] ?? 0) . " | تحويلات " . (float) ($rep['conversions'] ?? 0) . " | إيراد " . (float) ($rep['revenue'] ?? 0) . "

الحملات:
{$campaignsText}

ملاحظة: الأرقام دي هي اللي مسحوبة فعليًا من المنصات عبر المزامنة (sync) - لو كلها صفر فمفيش بيانات أداء حقيقية بعد.";
    }
}
