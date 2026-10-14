<?php

/**
 * Tourfecto - Article & Page Content Generator
 * توليد محتوى تسويقي جاهز للنشر لشركات السياحة (مقالات + صفحات رحلات).
 * @version 1.1.0 - Phase 8 (Content Agent)
 *
 * ملاحظة مهمة (من النسخة الأصلية، لسه سارية): بما إن أغلب مواقع عملاء
 * تورفكتو مواقع مخصصة/مبرمجة (مش WordPress بمعيار موحّد)، مفيش طريقة عامة
 * "ننشر" المقال على موقع العميل تلقائيًا. الكلاس ده بيولّد المحتوى **جاهز
 * للنسخ/التنزيل**. الاستثناء الوحيد: مواقع Website Builder بتاعتنا (عندنا
 * صلاحية كتابة حقيقية عليها) - انظر AIController::applyGeneratedTourPage()
 * الجديدة في هذه الـPhase، اللي بتستخدم نفس فكرة Auto-Apply من Phase 5.
 *
 * Phase 8 التغييرات:
 * - Constructor بقى بيستخدم AIOrchestrator (Phase 3) افتراضيًا بدل
 *   GeminiClient مباشرة - task='content_generation' (Gemini-first، بالظبط
 *   زي ما السبيك حددت للمحتوى).
 * - generate() بقى بيرجّع كمان: faqs (أسئلة شائعة)، schema_suggestion
 *   (FAQPage JSON-LD جاهز للصق)، internal_link_suggestions - كل ده Additive،
 *   الحقول القديمة (title/meta_description/slug/content/suggested_keywords)
 *   متلمستش.
 * - method جديدة generateTourPage() لتوليد صفحة رحلة كاملة بنفس البنية
 *   اللي WebsiteBuilderController بيتوقعها فعليًا (name/short_description/
 *   price/duration/highlights/itinerary/includes/excludes).
 */
class ArticleGenerator
{
    /** @var mixed أي كائن عنده generateContent($prompt,$options):array بنفس شكل GeminiClient - عادة AIOrchestrator */
    private $geminiClient;

    public function __construct($geminiClient = null)
    {
        $this->geminiClient = $geminiClient ?? (class_exists('AIOrchestrator') ? new AIOrchestrator() : new GeminiClient());
    }

    /**
     * توليد مقال كامل جاهز للنشر (+ FAQs + اقتراح Schema + اقتراحات روابط داخلية).
     *
     * @param string $topic الموضوع/الكلمة المفتاحية
     * @param string $language 'ar' أو 'en'
     * @param string $tone professional/friendly/luxury/adventurous
     * @param string|null $companyName
     * @param string|null $websiteUrl
     * @param array $existingPages قائمة صفحات موجودة (['title'=>..,'path'=>..]) لاقتراح روابط داخلية حقيقية بدل عناوين وهمية - اختياري
     * @return array
     */
    public function generate(
        string $topic,
        string $language = 'en',
        string $tone = 'professional',
        ?string $companyName = null,
        ?string $websiteUrl = null,
        array $existingPages = []
    ): array {
        $prompt = $this->buildPrompt($topic, $language, $tone, $companyName, $websiteUrl, $existingPages);

        $apiResponse = $this->geminiClient->generateContent($prompt, [
            'task' => 'article_generation', 'output_language' => $language,
            'maxOutputTokens' => 16384,
            'responseMimeType' => 'application/json',
        ]);

        if (!$apiResponse['success']) {
            return [
                'success' => false,
                'error' => $apiResponse['error'] ?? 'فشل الاتصال بمحرك الذكاء الاصطناعي',
            ];
        }

        $parsed = $this->extractJson($apiResponse['data']);

        if (!$parsed || empty($parsed['content'])) {
            return [
                'success' => false,
                'error' => 'تعذّر تحليل رد الذكاء الاصطناعي إلى مقال منظّم',
            ];
        }

        $content = (string) $parsed['content'];
        $faqs = $this->sanitizeFaqs($parsed['faqs'] ?? []);

        return [
            'success' => true,
            'data' => [
                'title' => $parsed['title'] ?? $topic,
                'meta_description' => $parsed['meta_description'] ?? '',
                'slug' => $this->slugify($parsed['title'] ?? $topic),
                'content' => $content,
                'suggested_keywords' => $parsed['suggested_keywords'] ?? [],
                'word_count' => str_word_count(strip_tags($content)),
                // Phase 8 - حقول جديدة إضافية:
                'faqs' => $faqs,
                'schema_suggestion' => $this->buildFaqSchema($faqs),
                'internal_link_suggestions' => $this->sanitizeInternalLinks($parsed['internal_link_suggestions'] ?? []),
            ],
            'tokens_used' => $apiResponse['tokens_used'] ?? 0,
            'cost' => $apiResponse['cost'] ?? 0,
        ];
    }

    /**
     * توليد صفحة رحلة كاملة (Tour Page) بنفس البنية اللي WebsiteBuilderController
     * بيتوقعها فعليًا في content_json['tours'] (name/short_description/price/
     * duration/highlights/itinerary/includes/excludes/group_size).
     *
     * @param string $topic اسم/موضوع الرحلة (مثال: "رحلة سفاري صحراوية 3 أيام من القاهرة")
     * @param string $language
     * @param string|null $companyName
     * @param array $existingSlugs Slugs موجودة بالفعل (لتجنب تكرار الـslug)
     * @return array
     */
    public function generateTourPage(string $topic, string $language = 'en', ?string $companyName = null, array $existingSlugs = []): array
    {
        $prompt = $this->buildTourPagePrompt($topic, $language, $companyName);

        $apiResponse = $this->geminiClient->generateContent($prompt, [
            'task' => 'article_generation', 'output_language' => $language,
            'maxOutputTokens' => 4096,
            'responseMimeType' => 'application/json',
        ]);

        if (!$apiResponse['success']) {
            return ['success' => false, 'error' => $apiResponse['error'] ?? 'فشل الاتصال بمحرك الذكاء الاصطناعي'];
        }

        $parsed = $this->extractJson($apiResponse['data']);
        if (!$parsed || empty($parsed['name'])) {
            return ['success' => false, 'error' => 'تعذّر تحليل رد الذكاء الاصطناعي إلى صفحة رحلة منظّمة'];
        }

        $slug = $this->slugify($parsed['name']);
        $baseSlug = $slug;
        $i = 2;
        while (in_array($slug, $existingSlugs, true)) {
            $slug = $baseSlug . '-' . $i;
            $i++;
        }

        $tour = [
            'name' => (string) $parsed['name'],
            'slug' => $slug,
            'short_description' => (string) ($parsed['short_description'] ?? ''),
            'price' => (string) ($parsed['price'] ?? ''),
            'duration' => (string) ($parsed['duration'] ?? ''),
            'group_size' => (string) ($parsed['group_size'] ?? ''),
            'image_url' => '',
            'highlights' => $this->sanitizeStringList($parsed['highlights'] ?? [], 8),
            'includes' => $this->sanitizeStringList($parsed['includes'] ?? [], 10),
            'excludes' => $this->sanitizeStringList($parsed['excludes'] ?? [], 10),
            'itinerary' => $this->sanitizeItinerary($parsed['itinerary'] ?? []),
        ];

        return [
            'success' => true,
            'data' => $tour,
            'tokens_used' => $apiResponse['tokens_used'] ?? 0,
            'cost' => $apiResponse['cost'] ?? 0,
        ];
    }

    private function sanitizeFaqs($faqs): array
    {
        if (!is_array($faqs)) {
            return [];
        }
        $out = [];
        foreach (array_slice($faqs, 0, 8) as $f) {
            if (!is_array($f) || empty($f['question']) || empty($f['answer'])) {
                continue;
            }
            $out[] = ['question' => (string) $f['question'], 'answer' => (string) $f['answer']];
        }
        return $out;
    }

    private function sanitizeInternalLinks($links): array
    {
        if (!is_array($links)) {
            return [];
        }
        $out = [];
        foreach (array_slice($links, 0, 5) as $l) {
            if (!is_array($l) || empty($l['anchor_text'])) {
                continue;
            }
            $out[] = [
                'anchor_text' => (string) $l['anchor_text'],
                'suggested_target' => (string) ($l['suggested_target'] ?? ''),
                'reason' => (string) ($l['reason'] ?? ''),
            ];
        }
        return $out;
    }

    private function sanitizeStringList($list, int $max): array
    {
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach (array_slice($list, 0, $max) as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }
        return $out;
    }

    private function sanitizeItinerary($itinerary): array
    {
        if (!is_array($itinerary)) {
            return [];
        }
        $out = [];
        foreach (array_slice($itinerary, 0, 14) as $day) {
            if (!is_array($day)) {
                continue;
            }
            $out[] = [
                'day' => (string) ($day['day'] ?? ''),
                'title' => (string) ($day['title'] ?? ''),
                'description' => (string) ($day['description'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * بناء FAQPage JSON-LD جاهز للصق مباشرة في <head> - يرجع null لو مفيش
     * أسئلة شائعة أصلًا (عشان محدش يلصق Schema فاضي غلط).
     */
    private function buildFaqSchema(array $faqs): ?string
    {
        if (empty($faqs)) {
            return null;
        }
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn ($f) => [
                '@type' => 'Question',
                'name' => $f['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['answer']],
            ], $faqs),
        ];
        return json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function buildPrompt(string $topic, string $language, string $tone, ?string $companyName, ?string $websiteUrl, array $existingPages = []): string
    {
        $languageMap = [
            'ar' => 'العربية الفصحى السهلة',
            'en' => 'English',
            'fr' => 'Français',
            'de' => 'Deutsch',
            'es' => 'Español',
            'it' => 'Italiano',
            'tr' => 'Türkçe',
            'ru' => 'Русский',
            'zh' => '中文',
            'ja' => '日本語',
            'pt' => 'Português',
            'nl' => 'Nederlands',
        ];
        $languageName = InternationalSettingsService::languages()[$language] ?? $languageMap['en'];
        $toneMap = [
            'professional' => $language === 'ar' ? 'احترافي وموثوق' : 'professional and trustworthy',
            'friendly' => $language === 'ar' ? 'ودود وقريب من القارئ' : 'friendly and approachable',
            'luxury' => $language === 'ar' ? 'فاخر وراقي' : 'luxurious and upscale',
            'adventurous' => $language === 'ar' ? 'مغامر وحماسي' : 'adventurous and exciting',
        ];
        $toneDesc = $toneMap[$tone] ?? $toneMap['professional'];
        $companyLine = $companyName ? "اسم الشركة: {$companyName}." : '';
        $ctaLine = $websiteUrl ? "في نهاية المقال، ادعُ القارئ لزيارة الموقع: {$websiteUrl}." : '';

        $pagesLine = '';
        if (!empty($existingPages)) {
            $list = implode("\n", array_map(fn ($p) => "- {$p['title']} ({$p['path']})", array_slice($existingPages, 0, 15)));
            $pagesLine = "\n\nصفحات موجودة فعليًا على الموقع (لو مناسب، اقترح روابط داخلية لها فقط، ماتخترعش صفحات مش موجودة):\n{$list}";
        }

        return <<<PROMPT
أنت كاتب محتوى تسويقي متخصص في قطاع السياحة، وخبير SEO. اكتب مقالة تسويقية
كاملة جاهزة للنشر مباشرة على موقع شركة سياحة، بالمواصفات التالية:

- الموضوع: {$topic}
- اللغة: {$languageName}
- الأسلوب: {$toneDesc}
- الطول: 700-1000 كلمة
- {$companyLine}
- {$ctaLine}{$pagesLine}

المقال لازم يكون:
1. محسّن لمحركات البحث (SEO) بشكل طبيعي، من غير حشو كلمات مفتاحية مبالغ فيه.
2. مقسّم بعناوين فرعية واضحة (H2/H3) بصيغة Markdown (## و ###).
3. مفيد وحقيقي المعلومات، مش عام وسطحي.
4. خالي من أي معلومات ملفّقة عن أماكن أو أسعار محددة غير مؤكدة.

رجّع الرد **بصيغة JSON فقط** (بدون أي نص قبله أو بعده)، بالشكل ده بالظبط:

{
  "title": "عنوان جذاب للمقال (أقل من 70 حرف)",
  "meta_description": "وصف meta تسويقي (أقل من 160 حرف)",
  "content": "محتوى المقال الكامل بصيغة Markdown مع عناوين ##",
  "suggested_keywords": ["كلمة مفتاحية 1", "كلمة مفتاحية 2", "كلمة مفتاحية 3"],
  "faqs": [{"question":"سؤال شائع متعلق بالموضوع","answer":"إجابة مختصرة وحقيقية"}],
  "internal_link_suggestions": [{"anchor_text":"نص الرابط المقترح","suggested_target":"مسار الصفحة المناسبة من القائمة أعلاه لو فيه","reason":"سبب مختصر"}]
}
PROMPT;
    }

    private function buildTourPagePrompt(string $topic, string $language, ?string $companyName): string
    {
        $languageName = InternationalSettingsService::languages()[$language] ?? 'English';
        $companyLine = $companyName ? "اسم الشركة: {$companyName}." : '';

        return <<<PROMPT
أنت مسؤول محتوى في شركة سياحة، خبير SEO. اكتب صفحة رحلة/باقة سياحية كاملة
جاهزة للنشر بناءً على الموضوع ده: {$topic}
اللغة: {$languageName}. {$companyLine}

خالي من أي معلومات ملفّقة عن أماكن أو أسعار محددة غير مؤكدة - استخدم صياغة
عامة معقولة للسعر بدل رقم مختلق (مثال: "ابتداءً من X$ للفرد" أو "السعر عند الطلب").

رجّع الرد **بصيغة JSON فقط** بالشكل ده بالظبط:
{
  "name": "اسم الرحلة (قصير وجذاب)",
  "short_description": "وصف مختصر (سطر أو اتنين)",
  "price": "نص سعر معقول وعام",
  "duration": "المدة (مثال: يوم واحد / 3 أيام - ليلتين)",
  "group_size": "حجم المجموعة المقترح",
  "highlights": ["أبرز نقطة 1", "أبرز نقطة 2", "أبرز نقطة 3"],
  "includes": ["يشمل 1", "يشمل 2"],
  "excludes": ["لا يشمل 1"],
  "itinerary": [{"day":"1","title":"عنوان اليوم","description":"وصف مختصر لليوم"}]
}
PROMPT;
    }

    /**
     * استخراج JSON من رد الذكاء الاصطناعي النصي (اللي أحيانًا بيغلّفه بكتلة كود markdown).
     */
    private function extractJson(string $text): ?array
    {
        $jsonPattern = '/\{[\s\S]*\}/';
        if (preg_match($jsonPattern, $text, $matches)) {
            $jsonString = $matches[0];
        } else {
            $codePattern = '/```(?:json)?\s*([\s\S]*?)\s*```/';
            if (preg_match($codePattern, $text, $codeMatches)) {
                $jsonString = trim($codeMatches[1]);
            } else {
                return null;
            }
        }

        $data = json_decode($jsonString, true);
        return json_last_error() === JSON_ERROR_NONE ? $data : null;
    }

    private function slugify(string $title): string
    {
        $slug = trim($title);
        $slug = preg_replace('/[\s]+/u', '-', $slug);
        $slug = preg_replace('/[^\p{L}\p{N}\-]/u', '', $slug);
        $slug = mb_strtolower($slug);
        return mb_substr($slug, 0, 80);
    }
}
