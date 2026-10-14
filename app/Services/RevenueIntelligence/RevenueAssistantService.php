<?php

/**
 * Tourfecto - AI Revenue Assistant Service
 * @version 1.3.0
 *
 * Section 10: AI REVENUE ASSISTANT
 *
 * تصميم متعمّد: مطابقة نوايا (Intent Matching) بكلمات مفتاحية عربي/
 * إنجليزي، والإجابة دائمًا محسوبة مباشرة من الخدمات الحقيقية (Overview/
 * Forecast/Insight/Customer/Pipeline/Anomaly/Action) - وليست نصًا مولّدًا
 * بحرية من نموذج لغوي قد "يخترع" رقمًا. هذا يضمن الالتزام الصارم بقاعدة
 * الموديول: "الـAI يعتمد على بيانات المشروع الحقيقية فقط. لا يخترع
 * إجابات. إذا البيانات غير كافية: Not enough data."
 *
 * v1.1.0 (تحسين): 4 نوايا جديدة (Pipeline/Segments/Anomalies/Next Best
 * Action) بتستخدم خدمات كانت موجودة فعلاً بالموديول لكن الـAssistant
 * مكنش بيوصلها، توسيع كبير في صيغ الأسئلة المتعرَّف عليها، وRedirect
 * ذكي بدل الرفض الجاف (لو السؤال قريب من نية معروفة بس مش متطابق
 * تمامًا، نقترحها بدل "مفيش بيانات" الجافة - من غير ما نجاوب بيانات
 * لسؤال تاني، بس نساعده يلاقي الطريق الصح).
 *
 * v1.2.0 (تحسين تنافسي - مقارنة بأقوى منصات Revenue Intelligence
 * العالمية زي Clari/Gong/Baremetrics/ChartMogul):
 *  - Normalization عربي (أ/ا/إ، ى/ي، ة/ه) قبل مطابقة النوايا: جملة
 *    "اكبر مصدر للايراد" و"أكبر مصدر للإيرادات" اتنين بيوصلوا لنفس
 *    الـIntent - ده بيحسّن الـNLP العربي بشكل كبير من غير ما نعتمد
 *    على لغة عربية مضبوطة 100%.
 *  - Period-aware questions: "الشهر ده" / "الاسبوع ده" / "الربع ده" /
 *    "السنة دي" / "this week" بيغيّروا فترة الحساب للرد على
 *    overview/trend/sources/forecast بدل monthly ثابتة.
 *  - What-if scenario forecasting (زي ChartMogul "Explore future
 *    scenarios"): "ماذا لو زادت الإيرادات 20%؟" بيحسب سيناريو مبني
 *    على نفس الاتجاه التاريخي الحقيقي - مش رقم مخترع.
 *  - Follow-up suggestions (زي Clari Copilot): كل إجابة بتقترح 3
 *    أسئلة متابعة منطقية تبقى كـ chips للمستخدم يضغط عليها.
 *
 * v1.3.0 (توسيع الـNLP): مرادفات عربية أوسع (زبون/مبيعات/دخل/منين/
 * الجاي...) تصل لنفس النوايا مع الإنجليزية المكافئة (client/sales/
 * forecast/outlier...).
 */
class RevenueAssistantService
{
    private RevenueOverviewService $overview;
    private RevenueForecastService $forecastService;
    private RevenueInsightService $insightService;
    private CustomerRevenueService $customerService;
    private PipelineRevenueService $pipelineService;
    private RevenueAnomalyService $anomalyService;
    private RevenueActionService $actionService;

    public function __construct(
        ?RevenueOverviewService $overview = null,
        ?RevenueForecastService $forecastService = null,
        ?RevenueInsightService $insightService = null,
        ?CustomerRevenueService $customerService = null,
        ?PipelineRevenueService $pipelineService = null,
        ?RevenueAnomalyService $anomalyService = null,
        ?RevenueActionService $actionService = null
    ) {
        $this->overview = $overview ?? new RevenueOverviewService();
        $this->forecastService = $forecastService ?? new RevenueForecastService();
        $this->insightService = $insightService ?? new RevenueInsightService();
        $this->customerService = $customerService ?? new CustomerRevenueService();
        $this->pipelineService = $pipelineService ?? new PipelineRevenueService();
        $this->anomalyService = $anomalyService ?? new RevenueAnomalyService();
        $this->actionService = $actionService ?? new RevenueActionService($this->insightService, $this->anomalyService);
    }

    public function ask(int $userId, string $question, bool $persist = true): array
    {
        $intent = self::matchIntent($question);
        $answer = $this->answerIntent($userId, $intent, $question);
        $answer['follow_up_questions'] = self::suggestFollowUps($intent);

        if ($persist) {
            try {
                (new RevaiAiQuery([
                    'user_id' => $userId,
                    'question' => mb_substr($question, 0, 500),
                    'matched_intent' => $intent,
                    'answer_summary' => mb_substr($answer['finding'] ?? '', 0, 1000),
                    'confidence' => $answer['confidence'] ?? null,
                    'had_enough_data' => !empty($answer['has_data']) ? 1 : 0,
                ]))->save();
            } catch (Exception $e) {
                if (class_exists('Logger')) {
                    Logger::error('RevenueAssistantService: failed to log query', ['message' => $e->getMessage()]);
                }
            }
        }

        return $answer + ['matched_intent' => $intent];
    }

    /**
     * نفس ask() لكن مع طبقة Copilot اختيارية (v1.4.0): الرد يُحسب أولًا
     * بالطريقة الصارمة الحالية (بيانات حقيقية فقط)، ثم الـLLM يعيد صياغة
     * نفس النتيجة نصًا طبيعيًا (copilot_narrative) بدون إضافة/تغيير أي
     * رقم. أي فشل في الـLLM => يرجّع الرد الصارم الأصلي كما هو.
     *
     * @param int    $userId
     * @param string $question
     * @param bool   $persist
     * @param string $lang 'ar' | 'en'
     */
    public function askWithCopilot(int $userId, string $question, bool $persist = true, string $lang = 'ar'): array
    {
        $intent = self::matchIntent($question);
        $answer = $this->ask($userId, $question, $persist);

        if (class_exists('RevenueCopilotService')) {
            $answer = RevenueCopilotService::enhance($answer, $intent, $question, $lang);
        }

        return $answer;
    }

    /**
     * Normalization عربي موحّد قبل أي مطابقة - يقلّل أخطاء الـNLP العربي
     * بشكل كبير: همزة/ألف، تاء مربوطة/هاء، ألف مقصورة/ياء. تطبيقه على
     * السؤال وعلى الأنماط معًا بحيث أي تهجئة شائعة بتوصل لنفس الـIntent.
     */
    public static function normalizeArabic(string $text): string
    {
        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        $text = str_replace('ة', 'ه', $text);
        $text = str_replace(['ى', 'ئ'], 'ي', $text);
        return $text;
    }

    /** كل النوايا المدعومة وأنماطها - مصدر واحد يستخدمه matchIntent() وأيضًا fallback الاقتراح (self::suggestClosestIntents). */
    public static function intentPatterns(): array
    {
        return [
            'why_revenue_declined' => ['ليه.*قل', 'ليه.*انخفض', 'ليه.*نزل', 'سبب.*انخفاض', 'لية.*انخفاض', 'انخفضت ليه', 'ليه.*مبيعات', 'لية.*مبيعات', 'ليه.*دخل', 'لية.*دخل', 'نزلت ليه', 'why.*(decrease|drop|declin|down|less|fell|fall)', 'reason.*(drop|decline)', 'sales.*(drop|down|decline)'],
            'top_revenue_sources' => ['أكبر مصادر', 'اكبر مصادر', 'أكبر مصدر', 'اكبر مصدر', 'مصادر الإيراد', 'مصادر الايراد', 'أهم مصدر', 'اهم مصدر', 'مصدر الدخل', 'ايراد.*منين', 'الايراد.*منين', 'الايراد.*من فين', 'بيتولد منين', 'top.*(source|channel)', 'biggest.*source', 'best.*(source|channel)', 'which source', 'where.*(revenue|income)'],
            'top_value_customers' => ['أعلى قيمة', 'اعلى قيمة', 'عملاء.*قيمة', 'أفضل عملاء', 'افضل عملاء', 'اكبر عميل', 'أكتر عميل', 'اكتر عميل', 'افضل زبون', 'أفضل زبون', 'اكبر زبون', 'الزباين.*(مهم|كبار|مميز)', 'top.*(customer|client)', 'most valuable customer', 'best customer', 'biggest customer', 'best client', 'top client'],
            'growth_opportunities' => ['فرص', 'تزود الإيرادات', 'تزود الايرادات', 'زيادة الإيرادات', 'زيادة الايرادات', 'أزود.*إيراد', 'ازود.*ايراد', 'ازود.*مبيعات', 'زود.*مبيعات', 'تزود.*مبيعات', 'زيادة المبيعات', 'تنمية المبيعات', 'انمى المبيعات', 'اوسع المبيعات', 'نمو الدخل', 'opportunit', 'grow.*revenue', 'increase revenue', 'how (can|do) i (grow|increase)', 'grow.*sales', 'increase.*sales'],
            'is_trending_up' => ['اتجاه صاعد', 'ماشية.*صاعد', 'الوضع عامل إيه', 'الوضع عامل ايه', 'الوضع ماشي إزاي', 'الوضع ماشي ازاي', 'الدخل ماشي', 'المبيعات ماشيه', 'المبيعات ماشية', 'trending up', 'is revenue up', 'going up', 'how are we doing', 'overall.*(status|health)', 'is business doing well'],
            'current_risks' => ['المخاطر', 'مخاطر موجودة', 'إيه المشاكل', 'ايه المشاكل', 'حاجة تقلقني', 'مخاوف', 'عايز اعرف الخطر', 'risk', 'any problems', 'what.*wrong', 'anything to worry'],
            'next_month_forecast' => ['المتوقع الشهر', 'الشهر القادم', 'الشهر الجاي', 'هيحصل إيه بعدين', 'هيحصل ايه بعدين', 'توقعات الشهر', 'متوقع المبيعات', 'المبيعات الجايه', 'المبيعات الجاية', 'next month', 'forecast', 'expected next', 'what.*expect', 'sales forecast', 'projected revenue'],
            'what_if_scenario' => ['ماذا لو', 'لو زاد', 'لو قل', 'لو حصل', 'لو زودنا', 'لو قللنا', 'ماذا يحدث لو', 'سيناريو', 'لو زودنا المبيعات', 'لو قللنا المصاريف', 'what if', 'scenario', 'if revenue', 'what would happen', 'projection if'],
            'pipeline_status' => ['خط الصفقات', 'خط المبيعات', 'الصفقات المفتوحة', 'حالة الصفقات', 'صفقات شغاله', 'صفقات شغالة', 'pipeline', 'open deals', 'deals status', 'sales pipeline'],
            'customer_segments' => ['الشرائح', 'تقسيم العملاء', 'فئات العملاء', 'تقسيم الزباين', 'فئات الزبون', 'customer segment', 'vip customer', 'customer tier', 'segmentation'],
            'anomaly_check' => ['حاجة غريبة', 'شذوذ', 'حاجة مش طبيعية', 'انحراف في', 'حصل حاجة غريبة', 'anomal', 'unusual', 'weird spike', 'strange drop', 'suspicious', 'outlier'],
            'next_best_action' => ['أعمل إيه دلوقتي', 'اعمل ايه دلوقتي', 'إيه المفروض أعمله', 'ايه المفروض اعمله', 'اقترح', 'إيه الأولوية', 'ايه الاولويه', 'خطوتي الجاية', 'ايه الخطوه الجايه', 'what should i do', 'next step', 'what.*priorit', 'recommend.*action'],
        ];
    }

    /** مطابقة النية - Pure function قابلة للاختبار بأمثلة نصية ثابتة. */
    public static function matchIntent(string $question): string
    {
        $q = self::normalizeArabic(mb_strtolower(trim($question)));

        foreach (self::intentPatterns() as $intent => $regexes) {
            foreach ($regexes as $pattern) {
                if (@preg_match('/' . self::normalizeArabic($pattern) . '/u', $q) === 1) {
                    return $intent;
                }
            }
        }
        return 'unknown';
    }

    /**
     * يكتشف الفترة اللي السؤال بيطلّبها (اليوم/الأسبوع/الشهر/الربع/السنة)
     * ليتحوّل بيها الرد إلى الفترة الصحيحة بدل monthly الثابتة. الفترة
     * الوحيدة اللي مش بيحصلها نص بتترجم إلى monthly (السلوك الافتراضي).
     */
    public static function detectPeriod(string $question): string
    {
        $q = self::normalizeArabic(mb_strtolower(trim($question)));

        $periodWords = [
            'daily' => ['اليوم', 'النهارده', 'النهاردة', 'today', 'daily', 'this day'],
            'weekly' => ['الاسبوع', 'الأسبوع', 'اسبوع', 'week', 'weekly', 'this week'],
            'monthly' => ['الشهر', 'شهر', 'month', 'monthly', 'this month'],
            'quarterly' => ['الربع', 'ربع', 'quarter', 'quarterly'],
            'yearly' => ['السنه', 'السنة', 'العام', 'سنه', 'سنة', 'عام', 'year', 'yearly', 'annual'],
        ];

        foreach ($periodWords as $period => $words) {
            foreach ($words as $w) {
                if (mb_strpos($q, self::normalizeArabic($w)) !== false) {
                    return $period;
                }
            }
        }

        return 'monthly';
    }

    /** يستخرج نسبة النمو المذكورة في سؤال "what-if" (مثل "زادت 20%" أو "grows 15%"). */
    public static function extractGrowthPercent(string $question): float
    {
        $q = trim($question);

        // إنجليزي: "grows 15%" / "increase by 20%" / "decline 10%"
        if (preg_match('/(?:increase|increases|grow|grows|decline|declines|decrease|decreases)\s*(?:by)?\s*(\d{1,3})(?:%|٪)?/iu', $q, $m)) {
            return (float) $m[1];
        }
        // إنجليزي: "15% increase" / "20% growth"
        if (preg_match('/(\d{1,3})(?:%|٪)?\s*(?:increase|growth|grow)/iu', $q, $m)) {
            return (float) $m[1];
        }
        // عربي: "زادت الإيرادات 20%" / "لو قللنا المصاريف 10%" (الفعل قبل الرقم، بكلمات بينهم)
        if (preg_match('/(زادت|زاد|زودنا|زودت|نمت|نمو|زيادة|قلت|انخفضت|قللت)\D{0,40}(\d{1,3})(?:%|٪)?/u', $q, $m)) {
            return (float) $m[2];
        }
        // عربي: "20% زيادة" / "10% نمو"
        if (preg_match('/(\d{1,3})(?:%|٪)?\s*(زيادة|نمو|انخفاض)/u', $q, $m)) {
            return (float) $m[1];
        }

        return 0.0;
    }

    /**
     * Follow-up suggestions (ميزة تنافسية زي Clari Copilot): كل إجابة
     * بترجع أسئلة متابعة منطقية عشان المستخدم يكمل الاستكشاف بنقرة
     * واحدة. مقترحات ثابتة لكل نية - مش بيانات مخترعة.
     */
    public static function suggestFollowUps(string $intent): array
    {
        $map = [
            'why_revenue_declined' => [
                'أكبر مصادر الإيرادات إيه؟',
                'إيه المخاطر الموجودة؟',
                'إيه المفروض أعمله دلوقتي؟',
            ],
            'top_revenue_sources' => [
                'إيه المتوقع الشهر القادم؟',
                'فيه مصدر نازل بشكل ملحوظ؟',
                'إيه أفضل فرص النمو دلوقتي؟',
            ],
            'top_value_customers' => [
                'تقسيم العملاء عندي إيه؟',
                'مين فيهم غير نشط؟',
                'إيه فرص النمو؟',
            ],
            'growth_opportunities' => [
                'إيه الأولويات اللي المفروض أشتغل عليها؟',
                'إيه حالة خط الصفقات؟',
                'إيه المتوقع الشهر القادم؟',
            ],
            'is_trending_up' => [
                'إيه المتوقع الشهر القادم؟',
                'فيه حاجة غريبة حصلت في الإيراد؟',
                'إيه المخاطر الحالية؟',
            ],
            'current_risks' => [
                'إيه المفروض أعمله بخصوص أكتر خطر؟',
                'ليه الإيرادات نزلت؟',
                'إيه حالة خط الصفقات؟',
            ],
            'next_month_forecast' => [
                'ماذا لو زادت الإيرادات 20%؟',
                'إيه اتجاه الإيراد حاليًا؟',
                'إيه أكبر مصادر الإيرادات؟',
            ],
            'what_if_scenario' => [
                'إيه المتوقع الشهر القادم؟',
                'إيه المخاطر اللي ممكن تمنع ده؟',
                'إيه أفضل فرص النمو؟',
            ],
            'pipeline_status' => [
                'إيه الصفقات اللي هتتقفل قريب؟',
                'إيه المخاطر في الصفقات؟',
                'إيه المتوقع من الإيراد؟',
            ],
            'customer_segments' => [
                'مين أعلى العملاء قيمة؟',
                'مين العملاء الغير نشطين؟',
                'إيه فرص النمو؟',
            ],
            'anomaly_check' => [
                'إيه المفروض أعمل عن الشذوذ ده؟',
                'إيه المخاطر الحالية؟',
                'إيه المتوقع الشهر القادم؟',
            ],
            'next_best_action' => [
                'إيه أولوية الإجراءات دي؟',
                'إيه المخاطر الموجودة؟',
                'إيه حالة خط الصفقات؟',
            ],
        ];

        return $map[$intent] ?? [
            'إيه أكبر مصادر الإيرادات؟',
            'إيه المتوقع الشهر القادم؟',
            'إيه المخاطر الموجودة؟',
        ];
    }

    /**
     * لو مفيش تطابق مباشر، نحاول نلاقي أقرب نية بمقارنة كلمات السؤال
     * بكلمات مفتاحية كل نية (تقاطع بسيط، مش أي نوع تخمين حر) - عشان
     * نقترح "تقصد كذا؟" بدل رفض جاف، من غير ما نجاوب على سؤال تاني
     * غير اللي اتسأل فعلاً.
     */
    public static function suggestClosestIntents(string $question, int $limit = 3): array
    {
        $qWords = array_filter(preg_split('/[\s؟?!.,]+/u', self::normalizeArabic(mb_strtolower(trim($question)))), static function ($w) {
            return mb_strlen($w) >= 3;
        });
        if (empty($qWords)) {
            return [];
        }

        $scores = [];
        foreach (self::intentPatterns() as $intent => $patterns) {
            $keywordText = self::normalizeArabic(mb_strtolower(implode(' ', $patterns)));
            $keywordWords = array_filter(preg_split('/[^\p{L}]+/u', $keywordText), static function ($w) {
                return mb_strlen($w) >= 3; // نفس حد الطول المستخدم على كلمات السؤال - يمنع تطابقات وهمية من كلمات قصيرة زي "i"/"do"/"up" (شوف اختبار "gibberish").
            });
            $score = 0;
            foreach ($qWords as $qw) {
                foreach ($keywordWords as $kw) {
                    if (mb_strpos($kw, $qw) !== false || mb_strpos($qw, $kw) !== false) {
                        $score++;
                        break;
                    }
                }
            }
            if ($score > 0) {
                $scores[$intent] = $score;
            }
        }

        arsort($scores);
        return array_slice(array_keys($scores), 0, $limit);
    }

    private function answerIntent(int $userId, string $intent, string $originalQuestion): array
    {
        $period = self::detectPeriod($originalQuestion);

        switch ($intent) {
            case 'why_revenue_declined':
                return $this->answerWhyRevenueDeclined($userId);
            case 'top_revenue_sources':
                return $this->answerTopRevenueSources($userId, $period);
            case 'top_value_customers':
                return $this->answerTopValueCustomers($userId);
            case 'growth_opportunities':
                return $this->answerGrowthOpportunities($userId);
            case 'is_trending_up':
                return $this->answerTrend($userId, $period);
            case 'current_risks':
                return $this->answerCurrentRisks($userId);
            case 'next_month_forecast':
                return $this->answerNextMonthForecast($userId, $period);
            case 'what_if_scenario':
                return $this->answerWhatIfScenario($userId, $period, $originalQuestion);
            case 'pipeline_status':
                return $this->answerPipelineStatus($userId);
            case 'customer_segments':
                return $this->answerCustomerSegments($userId);
            case 'anomaly_check':
                return $this->answerAnomalyCheck($userId);
            case 'next_best_action':
                return $this->answerNextBestAction($userId);
            default:
                return $this->answerUnknown($originalQuestion);
        }
    }

    private function answerUnknown(string $question): array
    {
        $suggestions = self::suggestClosestIntents($question);
        $labels = [
            'why_revenue_declined' => 'why revenue changed', 'top_revenue_sources' => 'top revenue sources',
            'top_value_customers' => 'top-value customers', 'growth_opportunities' => 'growth opportunities',
            'is_trending_up' => 'overall revenue trend', 'current_risks' => 'current risks',
            'next_month_forecast' => 'next period forecast', 'what_if_scenario' => 'what-if scenario forecast',
            'pipeline_status' => 'pipeline status', 'customer_segments' => 'customer segments',
            'anomaly_check' => 'unusual revenue activity', 'next_best_action' => 'recommended next actions',
        ];

        if (!empty($suggestions)) {
            $suggestedLabels = implode(', ', array_map(static function ($s) use ($labels) {
                return $labels[$s] ?? $s;
            }, $suggestions));
            return [
                'has_data' => false,
                'confidence' => null,
                'finding' => "Not enough data.",
                'evidence' => [],
                'reasoning_summary' => "I couldn't confidently match this to a supported topic, but it looks related to: {$suggestedLabels}. Try rephrasing around one of those, or use the matching tab directly.",
                'recommended_action' => null,
            ];
        }

        return [
            'has_data' => false,
            'confidence' => null,
            'finding' => 'Not enough data.',
            'evidence' => [],
            'reasoning_summary' => 'I can answer questions about: revenue trend, revenue sources, top customers, customer segments, growth opportunities, risks, unusual activity, pipeline status, next-period forecast, what-if scenarios, and recommended next actions. Try rephrasing around one of those, or use the matching tab directly.',
            'recommended_action' => null,
        ];
    }

    private function answerWhyRevenueDeclined(int $userId): array
    {
        $risks = $this->insightService->getRisks($userId);
        $declineRisk = null;
        foreach ($risks as $r) {
            if ($r['category'] === 'revenue_decline') {
                $declineRisk = $r;
                break;
            }
        }
        if ($declineRisk === null) {
            $overview = $this->overview->getOverview($userId, 'monthly');
            if (!$overview['has_data']) {
                return self::insufficientData();
            }
            return [
                'has_data' => true,
                'confidence' => 'high',
                'finding' => $overview['growth_percent'] === null
                    ? 'Not enough data to compare against a previous period yet.'
                    : "Revenue did not meaningfully decline (growth: {$overview['growth_percent']}% vs previous period).",
                'evidence' => ['growth_percent' => $overview['growth_percent']],
                'reasoning_summary' => 'No revenue-decline risk was detected in the current analysis.',
                'recommended_action' => null,
            ];
        }
        return [
            'has_data' => true,
            'confidence' => $declineRisk['confidence'],
            'finding' => $declineRisk['finding'],
            'evidence' => $declineRisk['evidence'],
            'reasoning_summary' => $declineRisk['reasoning_summary'],
            'recommended_action' => $declineRisk['recommended_action'],
        ];
    }

    private function answerTopRevenueSources(int $userId, string $period = 'monthly'): array
    {
        $sourceGrowth = $this->overview->getRevenueBySourceWithGrowth($userId, $period);
        if (!$sourceGrowth['has_data'] || empty($sourceGrowth['sources'])) {
            return self::insufficientData();
        }
        $top = array_slice($sourceGrowth['sources'], 0, 5);
        $summary = implode(', ', array_map(static function ($s) {
            return "{$s['source']} ({$s['revenue']})";
        }, $top));
        return [
            'has_data' => true,
            'confidence' => 'high',
            'finding' => "Top revenue sources this {$period}: {$summary}.",
            'evidence' => ['top_sources' => $top, 'period' => $period],
            'reasoning_summary' => 'Directly aggregated from recorded revenue transactions grouped by source, for the detected period.',
            'recommended_action' => null,
        ];
    }

    private function answerTopValueCustomers(int $userId): array
    {
        $intel = $this->customerService->getCustomerRevenueIntelligence($userId);
        if (!$intel['has_data']) {
            return self::insufficientData();
        }
        $top = array_slice($intel['customers'], 0, 5);
        $summary = implode(', ', array_map(static function ($c) {
            return "{$c['name']} ({$c['customer_revenue']})";
        }, $top));
        return [
            'has_data' => true,
            'confidence' => 'high',
            'finding' => "Highest-value customers: {$summary}.",
            'evidence' => ['top_customers' => $top],
            'reasoning_summary' => 'Ranked by total realized revenue from won deals per customer.',
            'recommended_action' => 'Consider prioritizing these customers for retention and upsell attention.',
        ];
    }

    private function answerGrowthOpportunities(int $userId): array
    {
        $opportunities = $this->insightService->getOpportunities($userId);
        if (empty($opportunities)) {
            return self::insufficientData();
        }
        $top = array_slice($opportunities, 0, 5);
        $summary = implode(' | ', array_map(static function ($o) {
            return $o['title'];
        }, $top));
        return [
            'has_data' => true,
            'confidence' => 'medium',
            'finding' => "Top revenue opportunities right now: {$summary}.",
            'evidence' => ['opportunities' => $top],
            'reasoning_summary' => 'Derived from customer value/trend patterns and revenue-source growth in your real data.',
            'recommended_action' => 'Open the Opportunities tab for full details and recommended actions on each.',
        ];
    }

    private function answerTrend(int $userId, string $period = 'monthly'): array
    {
        $overview = $this->overview->getOverview($userId, $period);
        if (!$overview['has_data'] || $overview['growth_percent'] === null) {
            return self::insufficientData();
        }
        $trendWord = ['up' => 'trending up', 'down' => 'trending down', 'flat' => 'roughly flat'][$overview['growth_trend']] ?? 'unclear';
        return [
            'has_data' => true,
            'confidence' => 'high',
            'finding' => "Revenue is {$trendWord} this {$period}: {$overview['growth_percent']}% vs the previous period ({$overview['previous_period_revenue']} -> {$overview['total_revenue']}).",
            'evidence' => ['growth_percent' => $overview['growth_percent'], 'growth_trend' => $overview['growth_trend'], 'period' => $period],
            'reasoning_summary' => 'Based on total recorded revenue this period compared to the immediately preceding period of equal length.',
            'recommended_action' => null,
        ];
    }

    private function answerCurrentRisks(int $userId): array
    {
        $risks = $this->insightService->getRisks($userId);
        if (empty($risks)) {
            return [
                'has_data' => true,
                'confidence' => 'medium',
                'finding' => 'No significant revenue risks detected in the current data.',
                'evidence' => [],
                'reasoning_summary' => 'Revenue decline, customer inactivity, channel decline, and pipeline weakness checks all came back clear.',
                'recommended_action' => null,
            ];
        }
        $top = array_slice($risks, 0, 5);
        $summary = implode(' | ', array_map(static function ($r) {
            return $r['title'];
        }, $top));
        return [
            'has_data' => true,
            'confidence' => 'medium',
            'finding' => "Current revenue risks: {$summary}.",
            'evidence' => ['risks' => $top],
            'reasoning_summary' => 'Derived from revenue trend, customer activity, source performance, and pipeline health in your real data.',
            'recommended_action' => 'Open the Risks tab for full detail and recommended action on each.',
        ];
    }

    private function answerNextMonthForecast(int $userId, string $period = 'monthly'): array
    {
        $forecast = $this->forecastService->forecast($userId, $period, false);
        if ($forecast['insufficient_data']) {
            return self::insufficientData('Not enough data for reliable forecast.');
        }
        return [
            'has_data' => true,
            'confidence' => $forecast['confidence'],
            'finding' => "Estimated revenue for the next {$period}: {$forecast['expected_revenue']} (range: {$forecast['forecast_range']['low']}-{$forecast['forecast_range']['high']}). This is an estimate, not a guarantee.",
            'evidence' => ['expected_revenue' => $forecast['expected_revenue'], 'range' => $forecast['forecast_range'], 'data_points_used' => $forecast['data_points_used']],
            'reasoning_summary' => 'Based on a linear trend fitted to the last 90 days of recorded daily revenue.',
            'recommended_action' => null,
        ];
    }

    /**
     * What-if scenario (ميزة تنافسية): "ماذا لو زادت الإيرادات 20%؟" -
     * بياخد نفس الـ Forecast التاريخي الحقيقي وبيطبّق عليه نسبة النمو
     * المذكورة في السؤال. الرقم الأساسي مش مخترع - مبني على بيانات فعلية.
     */
    private function answerWhatIfScenario(int $userId, string $period, string $question): array
    {
        $base = $this->forecastService->forecast($userId, $period, false);
        if ($base['insufficient_data']) {
            return self::insufficientData('Not enough data for reliable scenario forecast.');
        }

        $growth = self::extractGrowthPercent($question);
        $series = $base['historical_series'] ?? [];
        $scenario = RevenueForecastService::scenarioForecast($series, $period, date('Y-m-d'), $growth);

        return [
            'has_data' => true,
            'confidence' => $scenario['confidence'],
            'finding' => "If the current trend continues with a {$growth}% change, projected revenue for the next {$period} is {$scenario['expected_revenue']} (range: {$scenario['forecast_range']['low']}-{$scenario['forecast_range']['high']}). This is a scenario estimate, not a guarantee.",
            'evidence' => ['scenario_growth_percent' => $growth, 'base_expected_revenue' => $scenario['base_expected_revenue'], 'expected_revenue' => $scenario['expected_revenue'], 'range' => $scenario['forecast_range']],
            'reasoning_summary' => 'Base forecast is derived from the real daily revenue trend; the scenario scales it by the assumed growth percentage. No invented base numbers.',
            'recommended_action' => null,
        ];
    }

    private function answerPipelineStatus(int $userId): array
    {
        $result = $this->pipelineService->getPipelineIntelligence($userId);
        if (!$result['has_data']) {
            return self::insufficientData();
        }
        $p = $result['pipeline'];
        return [
            'has_data' => true,
            'confidence' => 'high',
            'finding' => "You have {$p['open_deals_count']} open deal(s) worth {$p['pipeline_value']} total (weighted by probability: {$p['weighted_pipeline']}). "
                . (!empty($p['at_risk_deals']) ? count($p['at_risk_deals']) . ' of them are past their expected close date.' : 'None are overdue.'),
            'evidence' => ['pipeline_value' => $p['pipeline_value'], 'weighted_pipeline' => $p['weighted_pipeline'], 'open_deals_count' => $p['open_deals_count'], 'at_risk_count' => count($p['at_risk_deals'])],
            'reasoning_summary' => $p['expected_revenue_note'],
            'recommended_action' => !empty($p['at_risk_deals']) ? 'Review the overdue deals in the Pipeline tab - they may need a follow-up or should be marked lost.' : null,
        ];
    }

    private function answerCustomerSegments(int $userId): array
    {
        $result = $this->customerService->getSegments($userId);
        if (!($result['has_data'] ?? false) || empty($result['summary'])) {
            return self::insufficientData();
        }
        $summary = implode(', ', array_map(static function ($s) {
            return "{$s['segment']} ({$s['customer_count']})";
        }, $result['summary']));
        return [
            'has_data' => true,
            'confidence' => 'high',
            'finding' => "Customer segments: {$summary}.",
            'evidence' => ['segments' => $result['summary']],
            'reasoning_summary' => 'Segmented by realized revenue percentile and recency of last won deal.',
            'recommended_action' => null,
        ];
    }

    private function answerAnomalyCheck(int $userId): array
    {
        $result = $this->anomalyService->detect($userId);
        if (!$result['has_data']) {
            return self::insufficientData();
        }
        if (empty($result['anomalies'])) {
            return [
                'has_data' => true,
                'confidence' => 'medium',
                'finding' => 'No unusual revenue activity detected in the recent daily pattern.',
                'evidence' => [],
                'reasoning_summary' => 'Daily revenue over the recent period stayed within the statistically expected range.',
                'recommended_action' => null,
            ];
        }
        $top = array_slice($result['anomalies'], 0, 3);
        $summary = implode(' | ', array_map(static function ($a) {
            return ($a['type'] === 'sudden_drop' ? 'Drop' : 'Spike') . " on {$a['period']}";
        }, $top));
        return [
            'has_data' => true,
            'confidence' => 'medium',
            'finding' => "Unusual revenue activity found: {$summary}.",
            'evidence' => ['anomalies' => $top],
            'reasoning_summary' => 'Detected via statistical deviation (z-score) from the recent daily revenue average.',
            'recommended_action' => 'Open the Anomalies tab to investigate each one.',
        ];
    }

    private function answerNextBestAction(int $userId): array
    {
        $actions = $this->actionService->getNextBestActions($userId, 3);
        if (empty($actions)) {
            return self::insufficientData();
        }
        $summary = implode(' | ', array_map(static function ($a) {
            return "{$a['action']}: {$a['reason']}";
        }, $actions));
        return [
            'has_data' => true,
            'confidence' => $actions[0]['confidence'] ?? 'medium',
            'finding' => "Top recommended actions right now: {$summary}.",
            'evidence' => ['actions' => $actions],
            'reasoning_summary' => 'Ranked from current Opportunities, Risks, and Anomalies by severity/confidence/estimated impact.',
            'recommended_action' => $actions[0]['recommended_action'] ?? null,
        ];
    }

    private static function insufficientData(string $message = 'Not enough data.'): array
    {
        return [
            'has_data' => false,
            'confidence' => null,
            'finding' => $message,
            'evidence' => [],
            'reasoning_summary' => null,
            'recommended_action' => null,
        ];
    }
}
