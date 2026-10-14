<?php

/**
 * Tourfecto - Ad Copy Generation Service
 * توليد نصوص إعلانية + كلمات مفتاحية بالذكاء الاصطناعي لحملة موجودة،
 * يعيد استخدام GeminiClient الموحّد.
 *
 * الحدود الحرفية هنا (HEADLINE_*, DESCRIPTION_*, PRIMARY_TEXT_*) مأخوذة
 * من حدود Meta Ads الفعلية للنصوص الإعلانية (Feed): العنوان بيتقطع
 * بصريًا بعد ~27 حرف وله حد أقصى آمن 40، الوصف بيتقطع بعد ~27 وله حد
 * أقصى 30، والنص الأساسي بيظهر منه 125 حرف بس قبل "See More". بنطلب
 * من الذكاء الاصطناعي الالتزام بيها، وبرضو بننفذها بالكود بعد الرد
 * (قص فعلي لو تعدّى الحد الأقصى) - عشان "من غير غلطة" تبقى مضمونة
 * فعليًا مش مجرد تعليمات للنموذج ممكن يتجاهلها.
 * @version 1.1.0
 */
class AdCopyGenerationService
{
    /** @var GeminiClient */
    private $ai;
    private string $contentLanguage = 'en';

    /** الحد "الموصى بيه" (بيبان كامل من غير قص) والحد "الأقصى الآمن" (بنقص عنده إجباريًا) لكل حقل */
    private const HEADLINE_RECOMMENDED = 27;
    private const HEADLINE_MAX = 40;
    private const DESCRIPTION_RECOMMENDED = 27;
    private const DESCRIPTION_MAX = 30;
    private const PRIMARY_TEXT_RECOMMENDED = 125;
    private const PRIMARY_TEXT_MAX = 220;

    /** عبارات شائعة بتسبب رفض أو تقييد الإعلان من مراجعة Meta/منصات تانية - تحذير بس، مش منع */
    private const RISKY_PHRASES = [
        'مضمون 100%', 'مضمون100%', 'نتيجة مضمونة', 'نتائج مضمونة',
        'اربح فلوس بسرعة', 'اربح فلوس بسهولة', 'الأرخص في مصر', 'الأرخص على الإطلاق',
        'الأفضل في العالم', 'مفيش زيه', 'اضغط هنا فورًا', 'افرصة العمر',
        'كل يوم فلوس', 'مليونير', 'اكسب فلوس وانت نايم',
    ];

    /** خيارات الهدف من الحملة المتاحة في الويزارد - المفتاح يتخزّن في `objective`، والتسمية بتتبعت للذكاء الاصطناعي كسياق */
    public const OBJECTIVES = [
        'leads' => 'حجوزات واستفسارات (Leads)',
        'traffic' => 'زيارات للموقع',
        'engagement' => 'تفاعل ومتابعين على السوشيال ميديا',
        'awareness' => 'انتشار وشهرة العلامة التجارية',
        'calls' => 'مكالمات هاتفية مباشرة',
    ];

    /** دعوات لاتخاذ إجراء (CTA) نظيفة ومتوافقة مع أزرار المنصات الجاهزة - بنطلب من الذكاء الاصطناعي يختار منها بس عشان منحصلش على CTA غريب أو غير مدعوم */
    private const AR_CTAS = ['احجز الآن', 'تواصل معنا', 'اعرف أكتر', 'اتصل الآن', 'تسوق الآن', 'سجّل الآن', 'راسلنا واتساب'];

    private const ALLOWED_CTAS = ['Book Now','Contact Us','Learn More','Call Now','Shop Now','Sign Up','Message Us'];

    public function __construct(?GeminiClient $ai = null)
    {
        $this->ai = $ai ?? new GeminiClient();
    }

    /** قايمة الـ CTAs المسموحة - بتتعرض في الواجهة عشان تتطابق مع نفس القايمة اللي بيختار منها الذكاء الاصطناعي */
    public static function allowedCtas(string $language = 'en'): array
    {
        return $language==='ar'?self::AR_CTAS:self::ALLOWED_CTAS;
    }

    /** @return AdCopy[] */
    public function generateCopies(AdCampaign $campaign, int $count = 3): array
    {
        $this->contentLanguage=ContentLanguageService::forUser((int)$campaign->getAttribute('user_id'),(int)$campaign->getAttribute('website_id')?:null);
        $product = $campaign->getAttribute('objective') . ' - ' . $campaign->getAttribute('name');

        $prompt = $this->buildCopiesOnlyPrompt($product, $count);

        $response = $this->ai->generateContent($prompt, ['task'=>'ad_copy', 'user_id'=>(int)$campaign->getAttribute('user_id'), 'website_id'=>(int)$campaign->getAttribute('website_id'), 'output_language'=>$this->contentLanguage, 'maxOutputTokens' => 4096, 'responseMimeType' => 'application/json']);
        if (!($response['success'] ?? false)) {
            throw new Exception($response['error'] ?? 'فشل الاتصال بمحرك الذكاء الاصطناعي');
        }

        $parsed = $this->parseJsonResponse((string) ($response['data'] ?? ''));
        if (!is_array($parsed) || empty($parsed['copies'])) {
            throw new Exception('تعذّر تحليل رد الذكاء الاصطناعي');
        }

        $saved = [];
        $labels = ['A', 'B', 'C', 'D', 'E'];
        foreach (array_slice($parsed['copies'], 0, $count) as $i => $copy) {
            $safeCopy = $this->enforceLimits($copy);
            $model = new AdCopy([
                'campaign_id' => (int) $campaign->getAttribute('id'),
                'headline' => $safeCopy['headline'],
                'description' => $safeCopy['description'],
                'primary_text' => $safeCopy['primary_text'],
                'call_to_action' => $safeCopy['call_to_action'],
                'variant_label' => $labels[$i] ?? (string) ($i + 1),
                'ai_generated' => 1,
                'status' => 'pending_review',
            ]);
            $model->save();
            $saved[] = $model;
        }

        ActivityLog::record('ads', 'ad_copies.generated', [
            'subject_type' => 'ad_campaigns', 'subject_id' => (int) $campaign->getAttribute('id'),
            'meta' => ['count' => count($saved)],
        ]);

        return $saved;
    }

    /**
     * الويزارد الاحترافي: من وصف بسيط لعرض العميل، بيولّد بضغطة واحدة
     * حزمة حملة كاملة - اسم مقترح، جمهور مستهدف (فئة عمرية/جنس/مواقع/
     * اهتمامات)، توصية ميزانية مع سبب، و3 نسخ إعلانية مطابقة تمامًا
     * لحدود المنصة (بتنفيذ فعلي في الكود، مش مجرد طلب من النموذج).
     * ملحوظة: النتيجة دي "معاينة" بس - ملهاش أي تأثير على قاعدة البيانات
     * لحد ما العميل يراجعها ويأكّد الإنشاء فعليًا.
     */
    public function generateCampaignBrief(string $goalDescription, string $objectiveKey, ?float $dailyBudget = null, ?int $userId = null, ?int $websiteId = null): array
    {
        $this->contentLanguage=ContentLanguageService::forUser($userId??(int)($_SESSION['user_id']??0),$websiteId);
        $objectiveLabel = self::OBJECTIVES[$objectiveKey] ?? $objectiveKey;
        $prompt = $this->buildFullBriefPrompt($goalDescription, $objectiveLabel, $dailyBudget);

        $response = $this->ai->generateContent($prompt, ['task'=>'ad_copy', 'user_id'=>$userId??(int)($_SESSION['user_id']??0), 'website_id'=>$websiteId, 'output_language'=>$this->contentLanguage, 'maxOutputTokens' => 8192, 'responseMimeType' => 'application/json']);
        if (!($response['success'] ?? false)) {
            throw new Exception($response['error'] ?? 'فشل الاتصال بمحرك الذكاء الاصطناعي');
        }

        $parsed = $this->parseJsonResponse((string) ($response['data'] ?? ''));
        if (!is_array($parsed) || empty($parsed['copies']) || empty($parsed['campaign_name'])) {
            throw new Exception('تعذّر تحليل رد الذكاء الاصطناعي - جرّب تاني بوصف أوضح شوية');
        }

        $audience = is_array($parsed['target_audience'] ?? null) ? $parsed['target_audience'] : [];
        $budgetRec = is_array($parsed['budget_recommendation'] ?? null) ? $parsed['budget_recommendation'] : [];

        $labels = ['A', 'B', 'C', 'D', 'E'];
        $copies = [];
        foreach (array_slice($parsed['copies'], 0, 3) as $i => $copy) {
            $safeCopy = $this->enforceLimits($copy);
            $copies[] = array_merge($safeCopy, [
                'variant_label' => $labels[$i] ?? (string) ($i + 1),
                'char_status' => [
                    'headline' => $this->charStatus(mb_strlen($safeCopy['headline']), self::HEADLINE_RECOMMENDED, self::HEADLINE_MAX),
                    'description' => $this->charStatus(mb_strlen($safeCopy['description']), self::DESCRIPTION_RECOMMENDED, self::DESCRIPTION_MAX),
                    'primary_text' => $this->charStatus(mb_strlen($safeCopy['primary_text']), self::PRIMARY_TEXT_RECOMMENDED, self::PRIMARY_TEXT_MAX),
                ],
                'char_counts' => [
                    'headline' => mb_strlen($safeCopy['headline']),
                    'description' => mb_strlen($safeCopy['description']),
                    'primary_text' => mb_strlen($safeCopy['primary_text']),
                ],
                'policy_warnings' => array_merge(
                    $this->lintPolicy($safeCopy['headline']),
                    $this->lintPolicy($safeCopy['description']),
                    $this->lintPolicy($safeCopy['primary_text'])
                ),
            ]);
        }

        return [
            'campaign_name' => mb_substr(trim((string) $parsed['campaign_name']), 0, 255),
            'objective' => $objectiveKey,
            'product_or_service' => mb_substr($goalDescription, 0, 2000),
            'target_audience_brief' => trim((string) ($audience['summary'] ?? '')),
            'audience' => [
                'age_min' => $this->clampAge($audience['age_min'] ?? 18),
                'age_max' => $this->clampAge($audience['age_max'] ?? 65),
                'genders' => in_array($audience['genders'] ?? 'all', ['all', 'male', 'female'], true) ? $audience['genders'] : 'all',
                'locations' => $this->cleanStringList($audience['locations'] ?? []),
                'interests' => $this->cleanStringList($audience['interests'] ?? []),
            ],
            'budget_recommendation' => [
                'recommended_daily_budget' => is_numeric($budgetRec['recommended_daily_budget'] ?? null)
                    ? round((float) $budgetRec['recommended_daily_budget'], 2)
                    : ($dailyBudget ?? 10.0),
                'bid_strategy' => trim((string) ($budgetRec['bid_strategy'] ?? '')),
                'reasoning' => trim((string) ($budgetRec['reasoning'] ?? '')),
            ],
            'copies' => $copies,
        ];
    }

    private function buildCopiesOnlyPrompt(string $product, int $count): string
    {
        return <<<PROMPT
اكتب {$count} نسخ إعلانية مختلفة (A/B testing) لحملة إعلانية عن: "{$product}".
كل نسخة لازم تلتزم حرفيًا بحدود منصات الإعلانات دي (لو الرد أطول هيتقطع تلقائيًا):
- headline: أقصى حاجة {$this->headlineMax()} حرف (الأفضل تحت {$this->headlineRecommended()} حرف)
- description: أقصى حاجة {$this->descriptionMax()} حرف (الأفضل تحت {$this->descriptionRecommended()} حرف)
- primary_text: أقصى حاجة {$this->primaryTextMax()} حرف (الأفضل تحت {$this->primaryTextRecommended()} حرف)
- call_to_action: اختار واحدة بالظبط من القايمة دي: {$this->allowedCtasList()}

اتجنب أي ادعاءات مبالغ فيها أو وعود مضمونة أو حروف كابيتال كاملة أو علامات تعجب متكررة.
رجّع JSON فقط: {"copies": [{"headline":"...","description":"...","primary_text":"...","call_to_action":"..."}]}
PROMPT;
    }

    private function buildFullBriefPrompt(string $goalDescription, string $objectiveLabel, ?float $dailyBudget): string
    {
        $budgetLine = $dailyBudget
            ? "الميزانية اليومية اللي العميل حاطط في دماغه: {$dailyBudget} دولار (اقترح تعديل عليها لو شايف إنها مش كافية أو زيادة عن اللازم، واشرح ليه)."
            : "العميل لسه محددش ميزانية - اقترح رقم واقعي بناءً على وصف العرض.";

        return <<<PROMPT
انت خبير إعلانات رقمية محترف بيجهّز حملة إعلانية كاملة وجاهزة للإطلاق لصاحب عمل سياحي، بحيث تكون احترافية 100% ومتوافقة مع سياسات منصات الإعلانات من غير أي غلطة تسبب رفض الإعلان.

وصف العميل لعرضه: "{$goalDescription}"
الهدف من الحملة: {$objectiveLabel}
{$budgetLine}

جهّز حزمة حملة كاملة، بلغة جمهور الحملة المحددة في سياسة المخرجات، والتزم حرفيًا بحدود المنصات دي (هيتقطع أي حاجة أطول تلقائيًا فحاول تلتزم من الأول):
- headline: أقصى {$this->headlineMax()} حرف (الأفضل تحت {$this->headlineRecommended()})
- description: أقصى {$this->descriptionMax()} حرف (الأفضل تحت {$this->descriptionRecommended()})
- primary_text: أقصى {$this->primaryTextMax()} حرف (الأفضل تحت {$this->primaryTextRecommended()})
- call_to_action: اختار واحدة بالظبط من: {$this->allowedCtasList()}

اتجنب تمامًا: ادعاءات مضمونة 100%، مقارنات مبالغ فيها ("الأرخص في مصر"، "الأفضل في العالم")، حروف كابيتال كاملة، علامات تعجب متكررة، وأي كلام ممكن يتفسر كوعد مالي أو صحي غير واقعي.

رجّع JSON فقط بالشكل ده بالظبط (من غير أي نص أو شرح خارج الـ JSON):
{
  "campaign_name": "اسم حملة قصير احترافي",
  "target_audience": {
    "age_min": 18, "age_max": 55, "genders": "all",
    "locations": ["مصر"], "interests": ["السفر", "..."],
    "summary": "وصف الجمهور المستهدف في جملة أو اتنين"
  },
  "budget_recommendation": {
    "recommended_daily_budget": 15,
    "bid_strategy": "استراتيجية مزايدة مقترحة بجملة قصيرة",
    "reasoning": "سبب مختصر للرقم ده"
  },
  "copies": [
    {"headline":"...","description":"...","primary_text":"...","call_to_action":"..."},
    {"headline":"...","description":"...","primary_text":"...","call_to_action":"..."},
    {"headline":"...","description":"...","primary_text":"...","call_to_action":"..."}
  ]
}
PROMPT;
    }

    private function parseJsonResponse(string $raw): ?array
    {
        $clean = preg_replace('/^```(json)?|```$/m', '', trim($raw));
        $parsed = json_decode(trim((string) $clean), true);
        return is_array($parsed) ? $parsed : null;
    }

    /** يفرض الحدود القصوى فعليًا بالقص (مش مجرد تحذير) - دي الضمانة الحقيقية لـ "من غير غلطة" */
    private function enforceLimits(array $copy): array
    {
        $headline = trim((string) ($copy['headline'] ?? ''));
        $description = trim((string) ($copy['description'] ?? ''));
        $primaryText = trim((string) ($copy['primary_text'] ?? ''));
        $cta = trim((string) ($copy['call_to_action'] ?? ''));
        if ($this->contentLanguage==='en' && preg_match('/\p{Arabic}/u',$headline.' '.$description.' '.$primaryText)) throw new RuntimeException('Generated ad copy did not follow the English audience policy');

        if (!in_array($cta, self::allowedCtas($this->contentLanguage), true)) {
            $cta = self::allowedCtas($this->contentLanguage)[0];
        }

        return [
            'headline' => $this->truncate($headline, self::HEADLINE_MAX),
            'description' => $this->truncate($description, self::DESCRIPTION_MAX),
            'primary_text' => $this->truncate($primaryText, self::PRIMARY_TEXT_MAX),
            'call_to_action' => $cta,
        ];
    }

    private function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $truncated = mb_substr($text, 0, $max - 1);
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false && $lastSpace > (int) ($max * 0.5)) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }
        return rtrim($truncated) . '…';
    }

    private function charStatus(int $length, int $recommended, int $max): string
    {
        if ($length <= $recommended) {
            return 'ok';
        }
        if ($length <= $max) {
            return 'warn';
        }
        return 'over';
    }

    /** كشف عبارات وأنماط بتزوّد احتمال رفض الإعلان من مراجعة المنصة - تحذير إرشادي بس، مش قص إجباري (النص المهني ده محتاج مراجعة بشرية) */
    private function lintPolicy(string $text): array
    {
        $warnings = [];
        $lower = mb_strtolower($text);

        foreach (self::RISKY_PHRASES as $phrase) {
            if (mb_strpos($lower, mb_strtolower($phrase)) !== false) {
                $warnings[] = "العبارة \"{$phrase}\" ممكن تسبب رفض الإعلان من مراجعة المنصة - جرّب صياغة أهدأ";
            }
        }

        if (preg_match('/[!?]{3,}/', $text)) {
            $warnings[] = 'علامات تعجب/استفهام متكررة (!!! أو ؟؟؟) بتقلل مصداقية الإعلان في مراجعة بعض المنصات';
        }

        if (preg_match('/\p{Lu}{5,}/u', $text)) {
            $warnings[] = 'وجود كلمات بحروف كابيتال كاملة - بعض المنصات بتعتبرها صياغة عدوانية';
        }

        return $warnings;
    }

    private function clampAge($value): int
    {
        $age = (int) $value;
        if ($age < 13) {
            return 13;
        }
        if ($age > 65) {
            return 65;
        }
        return $age;
    }

    private function cleanStringList($list): array
    {
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            $clean = trim((string) $item);
            if ($clean !== '') {
                $out[] = mb_substr($clean, 0, 100);
            }
        }
        return array_slice($out, 0, 10);
    }

    private function headlineMax(): int
    {
        return self::HEADLINE_MAX;
    }
    private function headlineRecommended(): int
    {
        return self::HEADLINE_RECOMMENDED;
    }
    private function descriptionMax(): int
    {
        return self::DESCRIPTION_MAX;
    }
    private function descriptionRecommended(): int
    {
        return self::DESCRIPTION_RECOMMENDED;
    }
    private function primaryTextMax(): int
    {
        return self::PRIMARY_TEXT_MAX;
    }
    private function primaryTextRecommended(): int
    {
        return self::PRIMARY_TEXT_RECOMMENDED;
    }
    private function allowedCtasList(): string
    {
        return implode(', ', self::allowedCtas($this->contentLanguage));
    }
}
