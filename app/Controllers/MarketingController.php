<?php

/**
 * Tourfecto - صفحات الموقع العام: الرئيسية، المميزات، الأسعار، من نحن، تواصل، اطلب عرض، الاسترجاع، المدونة
 *
 * كل المحتوى جاي من MarketingContent (عربي + إنجليزي) وكل الصفحات بتتعرض جوه PublicLayout.
 */
class MarketingController extends Controller
{
    private function t($pair): string
    {
        return MarketingContent::t($pair);
    }

    private function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    private function te($pair): string
    {
        return $this->e($this->t($pair));
    }

    private function out(string $html, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    private static function check(): string
    {
        return '<span class="ck"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>';
    }

    private function icon(string $name, int $size = 20): string
    {
        return function_exists('icon_svg') ? icon_svg($name, $size) : '';
    }

    // ================================================================== الرئيسية

    /** GET / */
    /** الرئيسية: "الباقات تبدأ من ..." بعملة الزائر + زر العملة */
    private function fromPrice(): string
    {
        try {
            require_once __DIR__ . '/../Services/Payment/CurrencyService.php';
            require_once __DIR__ . '/../Services/Subscription/BillingPlanCatalog.php';
            $min = 0.0;
            foreach (BillingPlanCatalog::read() as $p) {
                $m = (float) ($p['price_monthly'] ?? 0);
                if ($m > 0 && ($min === 0.0 || $m < $min)) $min = $m;
            }
            if ($min <= 0) return '';
            header('Vary: Cookie, Accept-Language');
            $isAr = $this->t(['ar', 'en']) === 'ar';
            $price = CurrencyService::format($min, CurrencyService::forViewer($this->user ?? null), $isAr);
            require_once __DIR__ . '/../Services/Subscription/PlanExtras.php';
            $cfg = (new PlanExtras())->config();
            $trial = !empty($cfg['trial_enabled']) ? sprintf($this->t([((int) $cfg['trial_days'] <= 10 ? ' · تجربة مجانية %d أيام' : ' · تجربة مجانية %d يوم'), ' · %d-day free trial']), (int) $cfg['trial_days']) : '';
            return '<div class="hero-from" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:center;margin-top:14px;font-size:14px;opacity:.9">'
                . '<a href="/pricing" style="color:inherit">' . $this->e(sprintf($this->t(['الباقات تبدأ من %s / شهر', 'Plans from %s / month']), $price) . $trial) . '</a>'
                . '<span data-tf-currency="compact"></span></div>';
        } catch (Throwable $e) {
            return '';
        }
    }

    public function home(array $params = []): array
    {
        $h = MarketingContent::home();
        [$l1, $grad, $l2] = MarketingContent::isAr() ? $h['title'][0] : $h['title'][1];

        $ticker = '';
        foreach (array_merge($h['ticker'], $h['ticker']) as $tk) {
            $ticker .= '<span><b>' . $this->te($tk) . '</b></span>';
        }
        $feats = '';
        foreach (MarketingContent::features() as $slug => $f) {
            $feats .= '<a href="/features/' . $slug . '" class="hf reveal"><span class="fi tone-' . $f['tone'] . '">' . $this->icon($f['icon'], 19) . '</span><b>' . $this->te($f['title']) . '</b><span>' . $this->te($f['short']) . '</span></a>';
        }
        $journey = '';
        foreach ($h['journey'] as $i => [$jt, $jd]) {
            $journey .= '<div class="step-card"><div class="step-num">' . ($i + 1) . '</div><h3>' . $this->te($jt) . '</h3><p>' . $this->te($jd) . '</p></div>';
        }
        $facts = '';
        foreach ($h['facts'] as [$n, $l]) {
            $facts .= '<div><b dir="ltr">' . $this->te($n) . '</b><span>' . $this->te($l) . '</span></div>';
        }
        $how = '';
        foreach ($h['how'] as $i => [$st, $sd]) {
            $how .= '<div class="step-card"><div class="step-num">0' . ($i + 1) . '</div><h3>' . $this->te($st) . '</h3><p>' . $this->te($sd) . '</p></div>';
        }
        $ex = '';
        foreach ($h['examples'] as [$ic, $et, $es, $eo]) {
            $ex .= '<div class="example-card"><div class="example-badge">' . $this->te($h['ex_badge']) . '</div><div class="example-icon">' . $this->icon($ic, 22) . '</div>'
                . '<h3>' . $this->te($et) . '</h3><p>' . $this->te($es) . '</p><div class="example-outcome"><span class="oi">' . $this->icon('trending-up', 16) . '</span><p>' . $this->te($eo) . '</p></div></div>';
        }
        $faq = '';
        $faqLd = [];
        foreach ($h['faq'] as [$q, $a]) {
            $faq .= '<details class="faq-item"><summary>' . $this->te($q) . '</summary><p>' . $this->te($a) . '</p></details>';
            $faqLd[] = ['@type' => 'Question', 'name' => $this->t($q), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $this->t($a)]];
        }
        $cards = $h['cards'];

        $body = '<section class="hero">'
            . '<div><div class="eyebrow"><span class="p"></span>' . $this->te($h['eyebrow']) . '</div>'
            . '<h1>' . $this->e($l1) . ' <span class="grad">' . $this->e($grad) . '</span> ' . $this->e($l2) . '</h1>'
            . '<p class="sub">' . $this->te($h['sub']) . '</p>'
            . '<div class="hero-ctas"><a href="/register" class="btn-primary">' . $this->te($h['cta1']) . '</a><a href="/demo" class="cta-ghost">' . $this->te($h['cta2']) . '</a></div>' . $this->fromPrice() . '</div>'
            . '<div class="globe-stage" id="globeStage"><div class="hero-map" id="heroMap" role="img" aria-label="' . $this->te($h['eyebrow']) . '"></div>'
            . '<div class="orbit-card oc1"><div class="n" data-count="48200" data-prefix="$">$0</div><div class="l">' . $this->te($cards[0]) . '</div></div>'
            . '<div class="orbit-card oc2"><div class="n" data-count="4.9" data-decimal="1">0</div><div class="l">' . $this->te($cards[1]) . '</div></div>'
            . '<div class="orbit-card oc3"><div class="n" data-count="126" data-prefix="">0</div><div class="l">' . $this->te($cards[2]) . '</div></div></div>'
            . '</section>'
            . '<div class="ticker-wrap" aria-hidden="true"><div class="ticker">' . $ticker . '</div></div>'
            . '<section class="blk"><div class="sec-head"><h2 class="sec-title">' . $this->te($h['feat_title']) . '</h2><p class="sec-sub">' . $this->te($h['feat_sub']) . '</p></div>'
            . '<div class="home-feats">' . $feats . '</div>'
            . '<div style="text-align:center;margin-top:26px"><a href="/features" class="cta-ghost">' . $this->te($h['feat_all']) . '</a></div></section>'
            . '<section class="blk alt"><div class="sec-head"><h2 class="sec-title">' . $this->te($h['journey_title']) . '</h2></div><div class="steps-grid journey reveal">' . $journey . '</div>'
            . '<div class="facts reveal" style="margin-top:28px">' . $facts . '</div></section>'
            . '<section class="blk"><div class="sec-head"><h2 class="sec-title">' . $this->te($h['how_title']) . '</h2><p class="sec-sub">' . $this->te($h['how_sub']) . '</p></div><div class="steps-grid reveal">' . $how . '</div></section>'
            . '<section class="blk alt"><div class="sec-head"><h2 class="sec-title">' . $this->te($h['ex_title']) . '</h2><p class="sec-sub">' . $this->te($h['ex_note']) . '</p></div><div class="examples-grid reveal">' . $ex . '</div></section>'
            . '<section class="blk"><div class="sec-head"><h2 class="sec-title">' . $this->te(['أسئلة شائعة', 'Frequently asked questions']) . '</h2></div><div class="faq-list">' . $faq . '</div></section>'
            . PublicLayout::ctaBand();

        $script = '<script src="/assets/js/tf-geo.js" defer></script><script src="/assets/js/home-map.js" defer></script>'
            . '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("[data-count]").forEach(function(el){var t=parseFloat(el.dataset.count),d=parseInt(el.dataset.decimal||"0"),p=el.dataset.prefix||"",c=0,s=t/60;(function k(){c=Math.min(c+s,t);el.textContent=p+c.toLocaleString("en-US",{maximumFractionDigits:d,minimumFractionDigits:d});if(c<t)requestAnimationFrame(k);})();});});</script>';

        $this->out(PublicLayout::render([
            'title' => $this->t($h['meta_title']),
            'description' => $this->t($h['meta_desc']),
            'path' => '/',
            'body' => $body . $script,
            'jsonld' => [
                ['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => 'Tourfecto', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web', 'description' => $this->t($h['meta_desc']), 'url' => PublicLayout::baseUrl()],
                ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $faqLd],
            ],
        ]));
        return [];
    }

    // ================================================================== المميزات

    /** GET /features */
    public function features(array $params = []): array
    {
        $cards = '';
        foreach (MarketingContent::features() as $slug => $f) {
            $bul = '';
            foreach (array_slice($f['capabilities'], 0, 3) as [$ct]) {
                $bul .= '<li>' . $this->te($ct) . '</li>';
            }
            $cards .= '<a href="/features/' . $slug . '" class="feat-card reveal"><span class="fi lg tone-' . $f['tone'] . '">' . $this->icon($f['icon'], 25) . '</span>'
                . '<h3>' . $this->te($f['title']) . '</h3><p>' . $this->te($f['short']) . '</p><ul>' . $bul . '</ul>'
                . '<span class="more">' . $this->te(['اعرف أكتر ←', 'Learn more →']) . '</span></a>';
        }
        $body = PublicLayout::hero($this->t(['المميزات', 'Features']), $this->t(['كل أدوات شركة الرحلات في منصة واحدة', 'Every tool a tour company needs, in one platform']),
            $this->t(['من الحجز والدفع للشات والتقييمات والتسويق - اختار الجزء اللي يهمك واعرف بيشتغل إزاي.', 'From bookings and payments to chat, reviews and marketing - pick what matters to you and see how it works.']),
            '<a href="/register" class="btn-primary">' . $this->te(['ابدأ مجانًا', 'Start free']) . '</a><a href="/pricing" class="cta-ghost">' . $this->te(['شوف الأسعار', 'See pricing']) . '</a>')
            . '<section class="blk tight"><div class="feat-grid">' . $cards . '</div></section>' . PublicLayout::ctaBand();
        $this->out(PublicLayout::render([
            'title' => $this->t(['المميزات', 'Features']),
            'description' => $this->t(['كل مميزات Tourfecto لشركات الرحلات: الحجوزات والدفع، موقع حجز، شات ذكي، تذكير الرحلات، التقييمات، SEO، التسويق، المنافسين، و CRM.', 'All Tourfecto features for tour companies: bookings & payments, booking website, AI chat, trip reminders, reviews, SEO, marketing, competitors and CRM.']),
            'path' => '/features', 'active' => 'features', 'body' => $body,
            'jsonld' => [PublicLayout::breadcrumb([[$this->t(['الرئيسية', 'Home']), '/'], [$this->t(['المميزات', 'Features']), '/features']])],
        ]));
        return [];
    }

    /** GET /features/{slug} */
    public function feature(array $params = []): array
    {
        $slug = (string) ($params['slug'] ?? '');
        $all = MarketingContent::features();
        if (!isset($all[$slug])) {
            return $this->notFound();
        }
        $f = $all[$slug];
        $ck = self::check();
        $registerUrl = self::serviceRegisterUrl($slug);
        $demoUrl = '/demo?interest=' . rawurlencode($slug) . '&lang=' . MarketingContent::lang();

        $preview = '';
        foreach (array_slice($f['capabilities'], 0, 4) as [$ct, $cd]) {
            $preview .= '<li>' . $ck . '<div><b>' . $this->te($ct) . '</b><span>' . $this->te($cd) . '</span></div></li>';
        }
        $caps = '';
        foreach ($f['capabilities'] as [$ct, $cd]) {
            $caps .= '<div class="cap reveal"><h3>' . $this->te($ct) . '</h3><p>' . $this->te($cd) . '</p></div>';
        }
        $steps = '';
        foreach ($f['steps'] as $i => $st) {
            $steps .= '<div class="step-card"><div class="step-num">' . ($i + 1) . '</div><p>' . $this->te($st) . '</p></div>';
        }
        $faq = '';
        $faqLd = [];
        foreach ($f['faq'] as [$q, $a]) {
            $faq .= '<details class="faq-item"><summary>' . $this->te($q) . '</summary><p>' . $this->te($a) . '</p></details>';
            $faqLd[] = ['@type' => 'Question', 'name' => $this->t($q), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $this->t($a)]];
        }
        // مميزات قريبة: اللي بعدها في الترتيب (بيلف)
        $keys = $f['details']['related'];
        $rel = '';
        for ($i = 1; $i <= 3; $i++) {
            $k = $keys[$i - 1];
            $rel .= '<a href="/features/' . $k . '" class="rel-card"><span class="fi tone-' . $all[$k]['tone'] . '">' . $this->icon($all[$k]['icon'], 18) . '</span>' . $this->te($all[$k]['title']) . '</a>';
        }
        $wa = function_exists('support_whatsapp_number') ? support_whatsapp_number() : '';
        $ask = $wa ? '<a href="https://wa.me/' . $this->e($wa) . '?text=' . rawurlencode($this->t(['أهلاً، عندي سؤال عن ', 'Hi, I have a question about ']) . $this->t($f['title'])) . '" target="_blank" rel="noopener" class="cta-ghost">' . $this->te(['اسألنا على واتساب', 'Ask us on WhatsApp']) . '</a>'
            : '<a href="' . $this->e($demoUrl) . '" class="cta-ghost">' . $this->te(['اطلب عرض توضيحي', 'Book a demo']) . '</a>';

        $body = '<section class="f-hero"><div>'
            . '<nav class="crumbs" aria-label="breadcrumb"><a href="/features">' . $this->te(['المميزات', 'Features']) . '</a><span aria-hidden="true">/</span><span>' . $this->te($f['title']) . '</span></nav>'
            . '<div class="f-kicker"><span class="fi tone-' . $f['tone'] . '">' . $this->icon($f['icon'], 18) . '</span>' . $this->te($f['title']) . '</div>'
            . '<h1>' . $this->te($f['tagline']) . '</h1><p class="sub">' . $this->te($f['short']) . '</p>'
            . '<div class="hero-ctas"><a href="' . $this->e($registerUrl) . '" class="btn-primary">' . $this->te(['جرّبها مجانًا', 'Try it free']) . '</a>' . $ask . '</div></div>'
            . '<div class="f-preview" aria-hidden="true"><div class="f-preview-head"><span class="dots"><i></i><i></i><i></i></span>' . $this->te($f['title']) . '</div><ul>' . $preview . '</ul></div>'
            . '</section>'
            . '<section class="blk alt"><div class="ps-grid"><div class="ps-card"><h3>' . $this->te(['المشكلة', 'The problem']) . '</h3><p>' . $this->te($f['problem']) . '</p></div>'
            . '<div class="ps-card sol"><h3>' . $this->te(['الحل مع Tourfecto', 'How Tourfecto solves it']) . '</h3><p>' . $this->te($f['solution']) . '</p></div></div></section>'
            . '<section class="blk"><div class="sec-head"><h2 class="sec-title">' . $this->te(['هتقدر تعمل إيه', 'What you can do']) . '</h2></div><div class="cap-grid">' . $caps . '</div></section>'
            . '<section class="blk alt"><div class="sec-head"><h2 class="sec-title">' . $this->te(['بتشتغل إزاي', 'How it works']) . '</h2></div><div class="steps3">' . $steps . '</div></section>'
            . $this->serviceSections($slug, $f)
            . ($faq ? '<section class="blk"><div class="sec-head"><h2 class="sec-title">' . $this->te(['أسئلة عن الميزة دي', 'Questions about this feature']) . '</h2></div><div class="faq-list">' . $faq . '</div></section>' : '')
            . '<section class="blk tight"><div class="sec-head" style="margin-bottom:18px"><h2 class="sec-title" style="font-size:22px">' . $this->te(['مميزات تانية', 'More features']) . '</h2></div><div class="related">' . $rel . '</div></section>'
            . '<section class="blk service-final"><h2>' . $this->te(['ابدأ بالخدمة اللي اخترتها','Start with your chosen service']) . '</h2><div class="hero-ctas"><a class="btn-primary" href="' . $this->e($registerUrl) . '">' . $this->te(['ابدأ إعداد حسابك','Set up your account']) . '</a><a class="cta-ghost" href="' . $this->e($demoUrl) . '">' . $this->te(['اطلب عرضًا لهذه الخدمة','Request a demo of this service']) . '</a></div></section>';

        $ld = [PublicLayout::breadcrumb([[$this->t(['الرئيسية', 'Home']), '/'], [$this->t(['المميزات', 'Features']), '/features'], [$this->t($f['title']), '/features/' . $slug]])];
        if ($faqLd) {
            $ld[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $faqLd];
        }
        $this->out(PublicLayout::render([
            'title' => $this->t($f['title']),
            'description' => $this->t($f['short']) . ' ' . $this->t($f['tagline']),
            'serviceRegisterUrl' => $registerUrl,
            'head' => '<link rel="stylesheet" href="/assets/css/service-details.css?v=20261010"><script src="/assets/js/service-details.js?v=20261010" defer></script>',
            'path' => '/features/' . $slug, 'active' => 'features', 'body' => $body, 'jsonld' => $ld,
        ]));
        return [];
    }

    public static function serviceRegisterUrl(string $slug): string
    {
        if (!isset(MarketingContent::features()[$slug])) return '/register';
        return '/register?feature=' . rawurlencode($slug) . '&redirect=' . rawurlencode('/onboarding?feature=' . $slug) . '&lang=' . MarketingContent::lang();
    }

    private function serviceSections(string $slug, array $f): string
    {
        $d = $f['details'];
        $sample = '';
        foreach ($d['example'] as $i => $line) {
            $sample .= '<details class="service-example-step"' . ($i === 0 ? ' open' : '') . '><summary>' . $this->te(['الخطوة ', 'Step ']) . ($i + 1) . '</summary><p>' . $this->te($line) . '</p></details>';
        }
        $tools = '';
        $toolsData = ServicePageDetails::tools();
        foreach ($f['legacy'] as $old) {
            if (!isset($toolsData[$old])) continue;
            [$title, $description] = $toolsData[$old];
            $tools .= '<details id="service-' . $this->e($old) . '" class="service-tool"><summary>' . $this->te($title) . '</summary><p>' . $this->te($description) . '</p></details>';
        }
        $plan = '<p>' . $this->te(['راجع الباقات وحدود الاستخدام قبل الاشتراك. إتاحة الخدمة تعتمد على الباقة والصلاحيات والربط المطلوب؛ رسوم المزوّدين الخارجية ليست مشمولة تلقائيًا.', 'Review plans and usage limits before subscribing. Availability depends on plan, permissions and required connections; external provider fees are not automatically included.']) . '</p>';
        $plans = [];
        if (class_exists('SubscriptionPlan')) {
            try { $plans = SubscriptionPlan::allAsLegacyArray(); } catch (Throwable $e) { $plans = []; }
        }
        if ($plans) {
            $quotaKeys = [
                'ai-chat'=>['chat_credits'=>['رسائل AI / شهر','AI messages / month']],
                'reviews-reputation'=>['review_credits'=>['ردود التقييم / شهر','Review replies / month']],
                'google-visibility'=>['ai_analysis'=>['تحليلات AI / شهر','AI analyses / month']],
                'competitors'=>['competitor_analysis'=>['تحليلات المنافسين / شهر','Competitor analyses / month']],
                'agency-teams'=>['multiple_websites'=>['عدد المواقع','Website allowance']],
            ];
            $plan .= '<div class="service-plan-list">';
            foreach ($plans as $key => $p) {
                $plan .= '<article class="service-plan"><h3>' . $this->e($p['name'] ?? $key) . '</h3>';
                if (isset($p['price_monthly'], $p['currency']) && is_numeric($p['price_monthly'])) {
                    $plan .= '<p><bdi>' . $this->e(number_format((float) $p['price_monthly'], 2) . ' ' . $p['currency']) . '</bdi> ' . $this->te(['/ شهر', '/ month']) . '</p>';
                }
                foreach ($quotaKeys[$slug] ?? [] as $q => $label) {
                    $value = $p['features'][$q] ?? null;
                    $plan .= '<p>' . $this->te($label) . ': <bdi>' . (is_numeric($value) && (float) $value >= 0 ? $this->e($value) : $this->te(['أكد الحد مع الفريق', 'Confirm allowance with our team'])) . '</bdi></p>';
                }
                $plan .= '</article>';
            }
            $plan .= '</div><p>' . $this->te(['هذه أسعار وحدود الباقات المعروضة؛ راجع تفاصيل الباقة وإتاحة الأداة المطلوبة قبل اختيارها.', 'These are the displayed plan prices and allowances; confirm plan details and access to your required tool before choosing.']) . '</p>';
        }
        $plan .= '<a class="cta-ghost" href="/pricing?lang=' . MarketingContent::lang() . '">' . $this->te(['قارن الباقات وحدودها', 'Compare plans and allowances']) . '</a> <a class="cta-ghost" href="/demo?interest=' . $this->e($slug) . '">' . $this->te(['أكد الباقة المناسبة مع الفريق', 'Confirm the right plan with our team']) . '</a>';
        return '<section class="blk alt service-details" id="service-demo"><div class="sec-head"><h2 class="sec-title">' . $this->te(['شوف مثال الاستخدام', 'Explore a use-case example']) . '</h2><p class="sec-sub">' . $this->te(['معاينة توضيحية ببيانات افتراضية، وليست صورة من حساب عميل أو نتيجة فعلية. افتح الخطوات لاستكشافها.', 'An illustrative preview with sample data, not a customer screenshot or an actual result. Open the steps to explore.']) . '</p></div><div class="service-example">' . $sample . '</div></section>'
            . '<section class="blk service-details"><div class="ps-grid"><div class="ps-card"><h2>' . $this->te(['قبل ما تبدأ', 'Before you start']) . '</h2><p>' . $this->te($d['requirements']) . '</p></div><div class="ps-card"><h2>' . $this->te(['الحدود والتكاليف', 'Limits and costs']) . '</h2><p>' . $this->te($d['limits']) . '</p></div></div></section>'
            . ($tools ? '<section class="blk alt service-details"><h2>' . $this->te(['الأدوات داخل الخدمة', 'Tools within this service']) . '</h2><div class="service-tools">' . $tools . '</div></section>' : '')
            . '<section class="blk service-details"><h2>' . $this->te(['اختار الباقة المناسبة', 'Choose the right plan']) . '</h2>' . $plan . '</section>';
    }

    // ================================================================== الأسعار

    /** GET /pricing */
    public function pricing(array $params = []): array
    {
        $plans = class_exists('SubscriptionPlan') ? SubscriptionPlan::allAsLegacyArray() : [];
        if (!$plans) {
            $this->out(PublicLayout::render(['title'=>$this->t(['الباقات','Plans']),'path'=>'/pricing','active'=>'pricing',
                'body'=>PublicLayout::hero($this->t(['الباقات','Plans']),$this->t(['الباقات غير متاحة حاليًا','Plans are currently unavailable']),$this->t(['تواصل معنا لتأكيد السعر والحدود قبل الاشتراك.','Contact us to confirm prices and allowances before subscribing.']),'<a class="btn-primary" href="/contact">'.$this->te(['تواصل معنا','Contact us']).'</a>')]));
        }

        $ck = self::check();
        $x = '<span class="ck"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 12h10"/></svg></span>';
        $loggedIn = $this->isAuthenticated();
        $wa = function_exists('support_whatsapp_number') ? support_whatsapp_number() : '';
        $keys = array_keys($plans);
        $featuredKey = isset($plans['professional']) ? 'professional' : ($keys[(int) floor(count($keys) / 2)] ?? '');
        $maxSave = 0;

        require_once __DIR__ . '/../Services/Payment/CurrencyService.php';
        $viewCur = CurrencyService::forViewer($this->user ?? null);
        header('Vary: Cookie, Accept-Language'); // السعر بيختلف حسب عملة الزائر
        $isAr = $this->t(['ar', 'en']) === 'ar';
        $cards = '';
        foreach ($plans as $key => $p) {
            $f = $p['features'];
            $sym = $this->e($p['currency_symbol'] ?? '$');
            $m = (float) $p['price_monthly'];
            $y = (float) $p['price_yearly'];
            // عرض بعملة الزائر (الجنيه المصري مثلًا) محسوبة من سعر الدولار
            if ($viewCur !== 'USD' && ($p['currency'] ?? 'USD') === 'USD') {
                $meta = CurrencyService::CURRENCIES[$viewCur];
                $sym = $this->e(($isAr ? $meta['ar_symbol'] : $meta['symbol']) . ' ');
                $m = CurrencyService::fromUsd($m, $viewCur);
                $y = CurrencyService::fromUsd($y, $viewCur);
            }
            $save = $m > 0 ? max(0, (int) round((1 - $y / ($m * 12)) * 100)) : 0;
            $maxSave = max($maxSave, $save);
            $name = MarketingContent::planName($key, (string) $p['name']);
            $n = fn ($v) => '<bdi>' . number_format((int) $v) . '</bdi>';
            $sites = (int) $f['multiple_websites'];
            $items = [
                [true, $this->t(['حتى ', 'Up to ']) . $n($sites) . ' ' . ($sites > 1 ? $this->t(['مواقع', 'websites']) : $this->t(['موقع', 'website']))],
                [true, $n($f['chat_credits']) . ' ' . $this->t(['رسالة شات ذكي شهريًا', 'AI chat messages / month'])],
                [true, $n($f['review_credits']) . ' ' . $this->t(['رد ذكي على التقييمات', 'AI review replies / month'])],
                [true, $n($f['ai_analysis']) . ' ' . $this->t(['تحليل بالذكاء الاصطناعي', 'AI analyses / month'])],
                [true, $n($f['competitor_analysis']) . ' ' . $this->t(['تحليل منافسين', 'competitor analyses / month'])],
                [!empty($f['whatsapp_bot']), $this->t(['شات واتساب', 'WhatsApp chat'])],
                [!empty($f['auto_pilot']), !empty($f['auto_pilot']) ? $this->t(['الطيار الآلي: ردود وإجراءات من غير موافقة', 'Autopilot: replies and actions without approval']) : $this->t(['الردود بتستنى موافقتك', 'Replies wait for your approval'])],
                [!empty($f['advanced_analytics']), $this->t(['تحليلات متقدمة', 'Advanced analytics'])],
            ];
            $li = '';
            foreach ($items as [$on, $txt]) {
                // النص فيه <bdi> بس - كل الباقي متعمله escape هنا
                $safe = preg_replace_callback('/<bdi>(.*?)<\/bdi>|([^<]+)/u', fn ($mm) => isset($mm[2]) && $mm[2] !== '' ? $this->e($mm[2]) : '<bdi>' . $this->e($mm[1]) . '</bdi>', $txt);
                $li .= '<li class="' . ($on ? '' : 'off') . '">' . ($on ? $ck : $x) . '<span>' . $safe . '</span></li>';
            }
            if ($loggedIn) {
                $actions = '<a href="/plans?plan=' . rawurlencode($key) . '" class="btn-primary">' . $this->te(['اشترك من حسابك', 'Subscribe from your account']) . '</a>';
            } else {
                $actions = '<a href="/register?plan=' . rawurlencode($key) . '" class="btn-primary">' . $this->te(['ابدأ مجانًا', 'Start free']) . '</a>';
            }
            if ($wa) {
                $msgM = $this->t(['أهلاً، عايز أشترك في ', 'Hi, I’d like to subscribe to ']) . $name;
                $actions .= '<a href="https://wa.me/' . $this->e($wa) . '?text=' . rawurlencode($msgM) . '" target="_blank" rel="noopener" class="cta-ghost">' . $this->te(['اشترك عبر واتساب', 'Subscribe via WhatsApp']) . '</a>';
            }
            $feat = $key === $featuredKey;
            // السعر بالجنيه جنب الدولار (شرط بنك الدفع)
            $egM = $viewCur === 'USD' ? CurrencyService::egpHint((float) $p['price_monthly'], $isAr) : '';
            $egY = $viewCur === 'USD' ? CurrencyService::egpHint((float) $p['price_yearly'], $isAr) : '';
            $cards .= '<div class="plan' . ($feat ? ' featured' : '') . '" data-em="' . $this->e($egM) . '" data-ey="' . $this->e($egY) . '" data-m="' . $this->e(number_format($m, 0)) . '" data-y="' . $this->e(number_format($y, 0)) . '" data-ym="' . $this->e(number_format($y / 12, 0)) . '" data-save="' . $save . '">'
                . ($feat ? '<span class="badge">' . $this->te(['الأكثر اختيارًا', 'Most popular']) . '</span>' : '')
                . '<h3>' . $this->e($name) . '</h3><p class="blurb">' . $this->e(MarketingContent::planBlurb($key)) . '</p>'
                . '<div class="price"><b><span class="cur">' . $sym . '</span><span class="amt">' . $this->e(number_format($m, 0)) . '</span></b><small class="unit">' . $this->te(['/ شهر', '/ month']) . '</small></div>'
                . ($egM !== '' ? '<div class="egp" style="font-size:12.5px;opacity:.7;margin-top:2px">≈ <bdi>' . $this->e($egM) . '</bdi></div>' : '')
                . '<div class="per">&nbsp;</div><ul>' . $li . '</ul><div class="actions">' . $actions . '</div></div>';
        }
        if ($cards === '') {
            $cards = '<div class="empty-state" style="grid-column:1/-1"><b>' . $this->te(['الأسعار هتتعلن قريب', 'Pricing coming soon']) . '</b>' . $this->te(['كلمنا ونبعتلك العرض المناسب.', 'Contact us for a quote.']) . '</div>';
        }

        $incl = '';
        foreach (MarketingContent::includedEverywhere() as $it) {
            $incl .= '<div>' . $ck . $this->te($it) . '</div>';
        }

        // جدول المقارنة
        $cmp = '';
        if ($plans) {
            $head = '<th>' . $this->te(['الميزة', 'Feature']) . '</th>';
            foreach ($plans as $key => $p) {
                $head .= '<th>' . $this->e(MarketingContent::planName($key, (string) $p['name'])) . '</th>';
            }
            $rows = [
                [['المواقع', 'Websites'], 'multiple_websites'],
                [['رسايل الشات الذكي / شهر', 'AI chat messages / month'], 'chat_credits'],
                [['ردود التقييمات / شهر', 'Review replies / month'], 'review_credits'],
                [['تحليلات الذكاء الاصطناعي / شهر', 'AI analyses / month'], 'ai_analysis'],
                [['تحليلات المنافسين / شهر', 'Competitor analyses / month'], 'competitor_analysis'],
                [['شات واتساب', 'WhatsApp chat'], 'whatsapp_bot'],
                [['الطيار الآلي (من غير موافقة)', 'Autopilot (no approval needed)'], 'auto_pilot'],
                [['تحليلات متقدمة', 'Advanced analytics'], 'advanced_analytics'],
            ];
            $body = '';
            foreach ($rows as [$label, $k]) {
                $body .= '<tr><td>' . $this->te($label) . '</td>';
                foreach ($plans as $p) {
                    $v = $p['features'][$k] ?? null;
                    $body .= '<td class="v">' . (is_bool($v) ? ($v ? '<span class="yes">✓</span>' : '<span class="no">—</span>') : '<bdi>' . $this->e(number_format((int) $v)) . '</bdi>') . '</td>';
                }
                $body .= '</tr>';
            }
            foreach (array_slice(MarketingContent::includedEverywhere(), 0, 6) as $it) {
                $body .= '<tr><td>' . $this->te($it) . '</td>' . str_repeat('<td class="v"><span class="yes">✓</span></td>', count($plans)) . '</tr>';
            }
            $cmp = '<section class="blk alt"><div class="sec-head"><h2 class="sec-title">' . $this->te(['قارن الباقات', 'Compare plans']) . '</h2></div><div class="cmp-wrap"><table class="cmp"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div></section>';
        }

        $faq = '';
        $faqLd = [];
        foreach (MarketingContent::pricingFaq() as [$q, $a]) {
            $faq .= '<details class="faq-item"><summary>' . $this->te($q) . '</summary><p>' . $this->te($a) . '</p></details>';
            $faqLd[] = ['@type' => 'Question', 'name' => $this->t($q), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $this->t($a)]];
        }
        $saveLbl = $maxSave > 0 ? '<span class="save">' . $this->e(sprintf($this->t(['وفّر حتى %d%%', 'save up to %d%%']), $maxSave)) . '</span>' : '';
        $i18n = json_encode([
            'month' => $this->t(['/ شهر', '/ month']), 'year' => $this->t(['/ سنة', '/ year']),
            'eq' => $this->t(['يعني %s شهريًا · وفّر %d%%', 'That’s %s / month · save %d%%']),
            'eq0' => $this->t(['يعني %s شهريًا', 'That’s %s / month']),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

        $body = PublicLayout::hero($this->t(['الأسعار', 'Pricing']), $this->t(['أسعار واضحة على قد شغلك', 'Simple pricing that grows with you']),
            $this->t(['ابدأ مجانًا، وارقّي لما شغلك يكبر. كل الباقات فيها الحجوزات والدفع والأتمتة الأساسية.', 'Start free and upgrade as you grow. Every plan includes bookings, payments and core automation.']))
            . '<section class="blk tight" style="padding-top:0"><div style="text-align:center"><div class="cycle" role="group" aria-label="' . $this->te(['دورة الدفع', 'Billing cycle']) . '">'
            . '<button type="button" data-cycle="m" aria-pressed="true">' . $this->te(['شهري', 'Monthly']) . '</button>'
            . '<button type="button" data-cycle="y" aria-pressed="false">' . $this->te(['سنوي', 'Yearly']) . $saveLbl . '</button></div></div>'
            . '<div style="text-align:center;margin:-6px 0 14px;font-size:13px"><label>' . $this->te(['العملة', 'Currency']) . ' <select id="tfCur" style="padding:5px 8px;border-radius:8px">' . implode('', array_map(fn ($c) => '<option value="' . $this->e($c['code']) . '"' . ($c['code'] === $viewCur ? ' selected' : '') . '>' . $this->e($c['name'] . ' (' . $c['symbol'] . ')') . '</option>', CurrencyService::publicList($isAr))) . '</select></label>'
            . ($viewCur !== 'USD' ? '<div style="opacity:.7;margin-top:4px">' . $this->e(sprintf($this->t(['محسوبة بسعر الصرف النهارده (1$ = %s)', 'Converted at today’s rate ($1 = %s)']), CurrencyService::format(1, $viewCur, $isAr))) . '</div>' : '')
            . '</div><script>document.getElementById("tfCur").addEventListener("change",function(e){fetch("/api/currency",{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json"},body:JSON.stringify({code:e.target.value})}).then(function(){location.reload();});});</script>'
            . '<div class="plans">' . $cards . '</div></section>'
            . '<section class="blk"><div class="sec-head"><h2 class="sec-title">' . $this->te(['موجود في كل الباقات', 'Included in every plan']) . '</h2></div><div class="incl">' . $incl . '</div></section>'
            . $cmp
            . '<section class="blk"><div class="sec-head"><h2 class="sec-title">' . $this->te(['أسئلة عن الأسعار', 'Pricing questions']) . '</h2></div><div class="faq-list">' . $faq . '</div></section>'
            . PublicLayout::ctaBand()
            . '<script>(function(){var T=' . $i18n . ';function f(s){var a=[].slice.call(arguments,1),i=0;return s.replace(/%[sd]%?/g,function(m){var v=a[i++];return m.length>2?v+"%":v;});}'
            . 'var bs=document.querySelectorAll(".cycle button");function set(c){bs.forEach(function(b){b.setAttribute("aria-pressed",b.dataset.cycle===c?"true":"false");});'
            . 'document.querySelectorAll(".plan[data-m]").forEach(function(p){var y=c==="y";p.querySelector(".amt").textContent=y?p.dataset.y:p.dataset.m;p.querySelector(".unit").textContent=y?T.year:T.month;'
            . 'var eg=p.querySelector(".egp bdi");if(eg)eg.textContent=y?p.dataset.ey:p.dataset.em;var cur=p.querySelector(".cur").textContent,s=+p.dataset.save;p.querySelector(".per").textContent=y?(s>0?f(T.eq,cur+p.dataset.ym,s):f(T.eq0,cur+p.dataset.ym)):" ";});}'
            . 'bs.forEach(function(b){b.addEventListener("click",function(){set(b.dataset.cycle);});});})();</script>';

        $this->out(PublicLayout::render([
            'title' => $this->t(['الأسعار والباقات', 'Pricing & plans']),
            'description' => $this->t(['باقات Tourfecto لشركات الرحلات - شهري أو سنوي، وابدأ مجانًا. كل الباقات فيها الحجوزات والدفع والتذكير والتقييمات.', 'Tourfecto plans for tour companies - monthly or yearly, start free. Every plan includes bookings, payments, reminders and reviews.']),
            'path' => '/pricing', 'active' => 'pricing', 'body' => $body,
            'jsonld' => [['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $faqLd]],
        ]));
        return [];
    }

    // ================================================================== من نحن

    /** GET /about */
    public function about(array $params = []): array
    {
        $a = MarketingContent::about();
        $story = '';
        foreach ($a['story'] as $p) {
            $story .= '<p>' . $this->te($p) . '</p>';
        }
        $values = '';
        foreach ($a['values'] as [$vt, $vd]) {
            $values .= '<div class="value reveal"><b>' . $this->te($vt) . '</b><span>' . $this->te($vd) . '</span></div>';
        }
        $feats = '';
        foreach (array_slice(MarketingContent::features(), 0, 6, true) as $slug => $f) {
            $feats .= '<a href="/features/' . $slug . '" class="rel-card"><span class="fi tone-' . $f['tone'] . '">' . $this->icon($f['icon'], 18) . '</span>' . $this->te($f['title']) . '</a>';
        }
        $body = PublicLayout::hero($this->t(['من نحن', 'About us']), $this->t($a['title']), '')
            . '<section class="blk tight" style="padding-top:0"><div class="container-narrow"><p class="about-lead">' . $this->te($a['lead']) . '</p><div class="prose">' . $story . '</div></div></section>'
            . '<section class="blk alt"><div class="sec-head"><h2 class="sec-title">' . $this->te(['بنشتغل إزاي', 'What we believe']) . '</h2></div><div class="values">' . $values . '</div></section>'
            . '<section class="blk"><div class="sec-head"><h2 class="sec-title">' . $this->te(['بنساعد في إيه', 'What we help with']) . '</h2></div><div class="related">' . $feats . '</div></section>'
            . PublicLayout::ctaBand();
        $this->out(PublicLayout::render([
            'title' => $this->t($a['title']),
            'description' => $this->t($a['lead']),
            'path' => '/about', 'active' => 'about', 'body' => $body,
        ]));
        return [];
    }

    // ================================================================== تواصل / اطلب عرض

    private function formScript(string $type): string
    {
        $msgs = json_encode([
            'sending' => $this->t(['جارِ الإرسال…', 'Sending…']),
            'ok' => $type === 'demo' ? $this->t(['وصلنا طلبك ✓ هنتواصل معاك خلال يوم عمل نحدد ميعاد العرض.', 'Request received ✓ We’ll contact you within one business day to schedule your demo.']) : $this->t(['وصلتنا رسالتك ✓ هنرد عليك في أقرب وقت.', 'Message received ✓ We’ll get back to you shortly.']),
            'err' => $this->t(['حصلت مشكلة، جرّب تاني أو كلمنا على واتساب.', 'Something went wrong. Please try again or reach us on WhatsApp.']),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        return '<script>(function(){var M=' . $msgs . ',f=document.getElementById("leadForm"),a=document.getElementById("leadAlert");if(!f)return;'
            . 'f.addEventListener("submit",async function(e){e.preventDefault();var b=f.querySelector("button[type=submit]"),o=b.textContent;b.disabled=true;b.textContent=M.sending;a.className="form-alert";'
            . 'var d={};new FormData(f).forEach(function(v,k){d[k]=v;});'
            . 'try{var r=await fetch("/contact/send",{method:"POST",headers:{"Content-Type":"application/json","Accept":"application/json"},body:JSON.stringify(d)});var j=await r.json().catch(function(){return{};});'
            . 'if(j.success){a.textContent=M.ok;a.className="form-alert ok";f.reset();}else{a.textContent=j.error||j.message||M.err;a.className="form-alert err";}}'
            . 'catch(x){a.textContent=M.err;a.className="form-alert err";}b.disabled=false;b.textContent=o;});})();</script>';
    }

    /** GET /contact */
    public function contact(array $params = []): array
    {
        $wa = function_exists('support_whatsapp_number') ? support_whatsapp_number() : '';
        $email = '';
        if (class_exists('SystemSettingsService')) {
            try {
                $email = (string) (new SystemSettingsService())->get('contact_email', '');
            } catch (Throwable $e) {
            }
        }
        $side = '';
        if ($wa) {
            $side .= '<a class="side-card" href="https://wa.me/' . $this->e($wa) . '" target="_blank" rel="noopener"><span class="fi tone-teal">' . $this->icon('message', 18) . '</span><div><b>' . $this->te(['واتساب', 'WhatsApp']) . '</b><span>' . $this->te(['أسرع طريقة توصلنا - بنرد خلال ساعات العمل.', 'The fastest way to reach us - we reply during business hours.']) . '</span></div></a>';
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $side .= '<a class="side-card" href="mailto:' . $this->e($email) . '"><span class="fi tone-accent">' . $this->icon('mail', 18) . '</span><div><b>' . $this->te(['الإيميل', 'Email']) . '</b><span dir="ltr">' . $this->e($email) . '</span></div></a>';
        }
        $side .= '<a class="side-card" href="/demo"><span class="fi tone-violet">' . $this->icon('monitor', 18) . '</span><div><b>' . $this->te(['اطلب عرض توضيحي', 'Book a demo']) . '</b><span>' . $this->te(['نوريك المنصة على شغلك في مكالمة قصيرة.', 'We’ll show you the platform on your own business in a short call.']) . '</span></div></a>'
            . '<a class="side-card" href="/help"><span class="fi tone-amber">' . $this->icon('book', 18) . '</span><div><b>' . $this->te(['مركز المساعدة', 'Help center']) . '</b><span>' . $this->te(['شروحات وأسئلة شائعة.', 'Guides and FAQs.']) . '</span></div></a>';

        $form = '<form class="form-card" id="leadForm" novalidate><h2>' . $this->te(['ابعتلنا رسالة', 'Send us a message']) . '</h2><p>' . $this->te(['هنرد عليك على الإيميل أو الواتساب.', 'We’ll reply by email or WhatsApp.']) . '</p>'
            . '<input type="hidden" name="type" value="contact"><div class="hp" aria-hidden="true"><label>Website<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>'
            . '<div class="fgrid"><div class="field"><label for="cName">' . $this->te(['الاسم', 'Name']) . '</label><input id="cName" name="name" required maxlength="120" autocomplete="name"></div>'
            . '<div class="field"><label for="cEmail">' . $this->te(['الإيميل', 'Email']) . '</label><input id="cEmail" name="email" type="email" required maxlength="150" autocomplete="email"></div>'
            . '<div class="field full"><label for="cPhone">' . $this->te(['رقم الواتساب', 'WhatsApp number']) . ' <span class="opt">' . $this->te(['(اختياري)', '(optional)']) . '</span></label><input id="cPhone" name="phone" type="tel" maxlength="30" autocomplete="tel"></div>'
            . '<div class="field full"><label for="cMsg">' . $this->te(['رسالتك', 'Your message']) . '</label><textarea id="cMsg" name="message" required maxlength="4000"></textarea></div></div>'
            . '<div class="form-foot"><button type="submit" class="btn-primary">' . $this->te(['إرسال', 'Send']) . '</button><small>' . $this->te(['بياناتك بتستخدم للرد عليك بس.', 'We only use your details to reply to you.']) . '</small></div>'
            . '<div class="form-alert" id="leadAlert" role="status" aria-live="polite"></div></form>';

        $body = PublicLayout::hero($this->t(['تواصل معنا', 'Contact us']), $this->t(['إزاي نقدر نساعدك؟', 'How can we help?']), $this->t(['سؤال عن المنصة، مساعدة في حسابك، أو عايز تعرف الباقة المناسبة - إحنا هنا.', 'A question about the platform, help with your account, or choosing the right plan - we’re here.']))
            . '<section class="blk tight" style="padding-top:0"><div class="two-col">' . $form . '<div class="side-cards">' . $side . '</div></div></section>'
            . $this->formScript('contact');
        $this->out(PublicLayout::render([
            'title' => $this->t(['تواصل معنا', 'Contact us']),
            'description' => $this->t(['تواصل مع فريق Tourfecto على الواتساب أو الإيميل أو من خلال الفورم.', 'Reach the Tourfecto team by WhatsApp, email or the contact form.']),
            'path' => '/contact', 'active' => 'contact', 'body' => $body,
        ]));
        return [];
    }

    /** GET /demo */
    public function demo(array $params = []): array
    {
        $opts = '<option value="">' . $this->te(['اختار', 'Choose']) . '</option>';
        foreach ([['1-2', ['1-2', '1-2']], ['3-10', ['3-10', '3-10']], ['11-30', ['11-30', '11-30']], ['30+', ['أكتر من 30', '30+']]] as [$v, $l]) {
            $opts .= '<option value="' . $v . '">' . $this->te($l) . '</option>';
        }
        $selectedInterest = is_string($_GET['interest'] ?? null) ? $_GET['interest'] : '';
        $interest = '<option value="">' . $this->te(['كله', 'Everything']) . '</option>';
        foreach (MarketingContent::features() as $slug => $f) {
            $interest .= '<option value="' . $slug . '"' . ($selectedInterest === $slug ? ' selected' : '') . '>' . $this->te($f['title']) . '</option>';
        }
        $ck = self::check();
        $list = '';
        foreach ([
            ['المنصة على شركتك انت: رحلاتك، أسعارك، وطريقة شغلك.', 'The platform on your own company: your tours, prices and workflow.'],
            ['إزاي تستقبل حجوزات ودفع أونلاين من أول يوم.', 'How to take bookings and online payments from day one.'],
            ['الشات الذكي وهو بيرد ويحجز.', 'The AI chat answering and booking.'],
            ['الباقة المناسبة لحجمك - من غير ضغط.', 'The right plan for your size - no pressure.'],
        ] as $it) {
            $list .= '<li>' . $ck . '<span>' . $this->te($it) . '</span></li>';
        }
        $form = '<form class="form-card" id="leadForm" novalidate><h2>' . $this->te(['احجز عرضك', 'Book your demo']) . '</h2><p>' . $this->te(['30 دقيقة أونلاين، ببلاش.', '30 minutes online, free.']) . '</p>'
            . '<input type="hidden" name="type" value="demo"><div class="hp" aria-hidden="true"><label>Website<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>'
            . '<div class="fgrid"><div class="field"><label for="dName">' . $this->te(['الاسم', 'Name']) . '</label><input id="dName" name="name" required maxlength="120" autocomplete="name"></div>'
            . '<div class="field"><label for="dCompany">' . $this->te(['اسم الشركة', 'Company name']) . '</label><input id="dCompany" name="company" required maxlength="150" autocomplete="organization"></div>'
            . '<div class="field"><label for="dEmail">' . $this->te(['الإيميل', 'Email']) . '</label><input id="dEmail" name="email" type="email" required maxlength="150" autocomplete="email"></div>'
            . '<div class="field"><label for="dPhone">' . $this->te(['رقم الواتساب', 'WhatsApp number']) . '</label><input id="dPhone" name="phone" type="tel" required maxlength="30" autocomplete="tel"></div>'
            . '<div class="field"><label for="dSite">' . $this->te(['موقعك أو صفحتك', 'Your website or page']) . ' <span class="opt">' . $this->te(['(اختياري)', '(optional)']) . '</span></label><input id="dSite" name="site" maxlength="200" dir="ltr" placeholder="https://"></div>'
            . '<div class="field"><label for="dSize">' . $this->te(['عدد الرحلات/البرامج عندك', 'How many tours you run']) . '</label><select id="dSize" name="size">' . $opts . '</select></div>'
            . '<div class="field full"><label for="dInt">' . $this->te(['مهتم بإيه أكتر؟', 'What interests you most?']) . '</label><select id="dInt" name="interest">' . $interest . '</select></div>'
            . '<div class="field full"><label for="dMsg">' . $this->te(['حاجة عايزنا نعرفها؟', 'Anything we should know?']) . ' <span class="opt">' . $this->te(['(اختياري)', '(optional)']) . '</span></label><textarea id="dMsg" name="message" maxlength="4000" style="min-height:90px"></textarea></div></div>'
            . '<div class="form-foot"><button type="submit" class="btn-primary">' . $this->te(['احجز العرض', 'Book the demo']) . '</button></div>'
            . '<div class="form-alert" id="leadAlert" role="status" aria-live="polite"></div></form>';
        $side = '<div class="form-card"><h2 style="font-size:17px">' . $this->te(['هتشوف إيه في العرض', 'What you’ll see']) . '</h2><ul class="checklist" style="margin-top:14px">' . $list . '</ul></div>';

        $body = PublicLayout::hero($this->t(['عرض توضيحي', 'Live demo']), $this->t(['شوف Tourfecto على شغلك انت', 'See Tourfecto on your own business']), $this->t(['سيب بياناتك ونكلمك نحدد ميعاد مناسب.', 'Leave your details and we’ll call to set a time that suits you.']))
            . '<section class="blk tight" style="padding-top:0"><div class="two-col">' . $form . '<div class="side-cards">' . $side . '</div></div></section>'
            . $this->formScript('demo');
        $this->out(PublicLayout::render([
            'title' => $this->t(['اطلب عرض توضيحي', 'Book a demo']),
            'description' => $this->t(['احجز عرض توضيحي مجاني لمنصة Tourfecto على شركتك.', 'Book a free Tourfecto demo for your company.']),
            'path' => '/demo', 'active' => '', 'body' => $body,
        ]));
        return [];
    }

    /** POST /contact/send - رسائل التواصل وطلبات العرض (بتظهر في الأدمن ← رسائل التواصل) */
    public function send(array $params = []): array
    {
        // فخ البوتات: الحقل ده مخفي عن البشر
        if (trim((string) $this->get('website_url', '')) !== '') {
            return $this->success([], 'ok');
        }
        $type = $this->get('type') === 'demo' ? 'demo' : 'contact';
        $name = trim(mb_substr((string) $this->get('name', ''), 0, 120));
        $email = trim(mb_substr((string) $this->get('email', ''), 0, 150));
        $phone = trim(preg_replace('/[^0-9+\s()-]/', '', mb_substr((string) $this->get('phone', ''), 0, 30)));
        $company = trim(mb_substr((string) $this->get('company', ''), 0, 150));
        $site = trim(mb_substr((string) $this->get('site', ''), 0, 200));
        $size = trim(mb_substr((string) $this->get('size', ''), 0, 20));
        $interest = (string) $this->get('interest', '');
        $message = trim(mb_substr((string) $this->get('message', ''), 0, 4000));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error($this->t(['اكتب اسمك وإيميل صحيح.', 'Please enter your name and a valid email.']), 422);
        }
        if ($type === 'contact' && mb_strlen($message) < 5) {
            return $this->error($this->t(['اكتب رسالتك.', 'Please write your message.']), 422);
        }
        if ($type === 'demo' && ($company === '' || $phone === '')) {
            return $this->error($this->t(['اكتب اسم الشركة ورقم الواتساب.', 'Please enter your company name and WhatsApp number.']), 422);
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        try {
            $recent = (int) ($this->db->query('SELECT COUNT(*) c FROM contact_submissions WHERE ip_address = ? AND created_at >= (NOW() - INTERVAL 1 HOUR)', [$ip])[0]['c'] ?? 0);
            if ($ip !== '' && $recent >= 5) {
                return $this->error($this->t(['بعت رسايل كتير - جرّب تاني بعد شوية أو كلمنا على واتساب.', 'Too many messages - please try again later or reach us on WhatsApp.']), 429);
            }
        } catch (Throwable $e) {
        }

        $features = MarketingContent::features();
        $lines = [];
        if ($type === 'demo') {
            $lines[] = '[طلب عرض توضيحي]';
            $lines[] = 'الشركة: ' . $company;
        }
        if ($phone !== '') {
            $lines[] = 'واتساب: ' . $phone;
        }
        if ($site !== '') {
            $lines[] = 'الموقع: ' . $site;
        }
        if ($size !== '') {
            $lines[] = 'عدد الرحلات: ' . $size;
        }
        if ($interest !== '' && isset($features[$interest])) {
            $lines[] = 'مهتم بـ: ' . $features[$interest]['title'][0];
        }
        $lines[] = 'اللغة: ' . MarketingContent::lang();
        $full = implode("\n", $lines) . ($message !== '' ? "\n\n" . $message : '');

        try {
            $this->db->query('INSERT INTO contact_submissions (name, email, message, user_id, status, ip_address) VALUES (?, ?, ?, ?, ?, ?)',
                [$name, $email, $full, !empty($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null, 'new', $ip ?: null]);
        } catch (Throwable $e) {
            Logger::error('Lead save failed', ['message' => $e->getMessage(), 'type' => $type]);
            return $this->error($this->t(['حصلت مشكلة، جرّب تاني أو كلمنا على واتساب.', 'Something went wrong. Please try again or reach us on WhatsApp.']), 500);
        }
        $this->notifyAdmins($type, $name, $email, $full);
        return $this->success([], 'ok');
    }

    private function notifyAdmins(string $type, string $name, string $email, string $body): void
    {
        $title = $type === 'demo' ? 'طلب عرض توضيحي جديد: ' . $name : 'رسالة تواصل جديدة: ' . $name;
        try {
            $admins = $this->db->query("SELECT id, email FROM users WHERE role IN ('admin','super_admin') AND deleted_at IS NULL");
        } catch (Throwable $e) {
            $admins = [];
        }
        foreach ($admins as $a) {
            try {
                $this->db->query('INSERT INTO notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)',
                    [(int) $a['id'], 'contact_' . $type, $title, mb_substr($body, 0, 240), '/admin/contact-messages']);
            } catch (Throwable $e) {
            }
        }
        $to = [];
        if (class_exists('SystemSettingsService')) {
            try {
                $cfg = (string) (new SystemSettingsService())->get('admin_alert_emails', '');
                $to = array_filter(array_map('trim', explode(',', $cfg)));
            } catch (Throwable $e) {
            }
        }
        if (!$to) {
            $to = array_column($admins, 'email');
        }
        if (!$to || !class_exists('Mailer')) {
            return;
        }
        $html = '<div dir="rtl" style="font-family:Tahoma,Arial;max-width:600px"><h2 style="font-size:18px">' . $this->e($title) . '</h2>'
            . '<p><b>الإيميل:</b> ' . $this->e($email) . '</p><pre style="white-space:pre-wrap;font-family:inherit;background:#f6f7fb;padding:12px;border-radius:8px">' . $this->e($body) . '</pre>'
            . '<p><a href="' . $this->e(PublicLayout::baseUrl() . '/admin/contact-messages') . '">افتح رسائل التواصل في الأدمن</a></p></div>';
        foreach (array_slice(array_unique($to), 0, 5) as $addr) {
            try {
                (new Mailer())->context('admin_alert')->send($addr, 'Tourfecto Admin', $title, $html);
            } catch (Throwable $e) {
                Logger::warning('Lead notify mail failed', ['message' => $e->getMessage()]);
            }
        }
    }

    // ================================================================== سياسة الاسترجاع

    /** GET /refund-policy */
    public function refund(array $params = []): array
    {
        $custom = '';
        if (class_exists('SystemSettingsService')) {
            try {
                $custom = trim((string) (new SystemSettingsService())->get('refund_policy_content', ''));
            } catch (Throwable $e) {
            }
        }
        $html = $custom !== '' ? self::sanitizeHtml($custom) : self::defaultRefundHtml(MarketingContent::lang());
        $body = PublicLayout::hero($this->t(['قانوني', 'Legal']), $this->t(['سياسة الاسترجاع والإلغاء', 'Refund & cancellation policy']), '')
            . '<section class="blk tight" style="padding-top:0"><div class="container-narrow prose">' . $html . '</div></section>';
        $this->out(PublicLayout::render([
            'title' => $this->t(['سياسة الاسترجاع', 'Refund policy']),
            'description' => $this->t(['سياسة الاسترجاع والإلغاء لاشتراكات منصة Tourfecto.', 'Refund and cancellation policy for Tourfecto subscriptions.']),
            'path' => '/refund-policy', 'body' => $body,
        ]));
        return [];
    }

    /** HTML الأدمن: بنشيل السكربتات والأحداث (الأدمن موثوق بس احتياط) */
    private static function sanitizeHtml(string $h): string
    {
        $h = preg_replace('#<(script|style|iframe|object|embed)[^>]*>.*?</\1>#is', '', $h);
        $h = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', (string) $h);
        return (string) preg_replace('#(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2#i', '$1="#"', (string) $h);
    }

    public static function defaultRefundHtml(string $lang): string
    {
        if ($lang === 'ar') {
            return <<<'HTML'
<p><small>آخر تحديث: أكتوبر 2026</small></p>
<p>السياسة دي بتوضح إزاي الإلغاء والاسترجاع بيتم لاشتراكات منصة Tourfecto. وهي جزء من <a href="/terms">شروط الاستخدام</a>.</p>
<h2>1. الحساب المجاني والتجربة</h2>
<p>تقدر تعمل حساب مجاني وتجرّب المنصة قبل ما تدفع أي حاجة، عشان تتأكد إنها مناسبة لشغلك.</p>
<h2>2. إلغاء الاشتراك</h2>
<ul>
<li>تقدر تلغي اشتراكك في أي وقت من صفحة <b>الاشتراك</b> في حسابك.</li>
<li>الإلغاء بيمنع التجديد الجاي، والاشتراك بيفضل شغّال لحد آخر المدة اللي دفعتها.</li>
<li>تقدر ترجّع الاشتراك قبل نهاية المدة لو غيّرت رأيك.</li>
</ul>
<h2>3. الاسترجاع</h2>
<p>رسوم الاشتراك بتتدفع مقدمًا عن المدة (شهر أو سنة)، ومش بتسترد بعد بداية المدة، إلا في الحالات دي:</p>
<ul>
<li><b>خصم مكرر أو خطأ في الفاتورة:</b> بنرجّع المبلغ الزيادة بالكامل.</li>
<li><b>عطل من ناحيتنا:</b> لو الخدمة وقفت فترة طويلة بسبب مشكلة عندنا، بنعوّضك بمدة إضافية أو استرجاع جزئي حسب الحالة.</li>
<li><b>أي حالة القانون بيلزمنا فيها بالاسترجاع.</b></li>
</ul>
<h2>4. الترقية وتغيير الباقة</h2>
<p>لما ترقّي لباقة أعلى، بتدفع فرق السعر بس. ولو نزلت لباقة أقل، التغيير بيبدأ من التجديد الجاي.</p>
<h2>5. رصيد المحفظة</h2>
<p>الرصيد اللي في محفظتك جوه المنصة بيستخدم في الاشتراكات والخدمات. لو عندك طلب بخصوص رصيد ماستخدمتهوش، كلمنا ونراجعه معاك.</p>
<h2>6. مدفوعات عملاءك (حجوزات الرحلات)</h2>
<p>الفلوس اللي عملاءك بيدفعوها لحجز رحلاتك (من لينكات الدفع أو موقع الحجز) بتروح لشركتك. استرجاع أو إلغاء حجز رحلة مسؤولية شركة الرحلات حسب سياستها هي، ومش بيخضع للسياسة دي.</p>
<h2>7. إزاي تطلب استرجاع</h2>
<p>ابعتلنا من صفحة <a href="/contact">تواصل معنا</a> بالإيميل المسجّل بيه وسبب الطلب، وهنرد عليك خلال 5 أيام عمل. لو الطلب اتقبل، المبلغ بيرجع على نفس طريقة الدفع خلال 5-10 أيام عمل حسب البنك.</p>
HTML;
        }
        return <<<'HTML'
<p><small>Last updated: October 2026</small></p>
<p>This policy explains how cancellations and refunds work for Tourfecto subscriptions. It forms part of our <a href="/terms">Terms of Service</a>.</p>
<h2>1. Free account and trial</h2>
<p>You can create a free account and try the platform before paying anything, so you can make sure it fits your business.</p>
<h2>2. Cancelling your subscription</h2>
<ul>
<li>You can cancel any time from the <b>Subscription</b> page in your account.</li>
<li>Cancelling stops the next renewal; your plan stays active until the end of the period you’ve paid for.</li>
<li>You can resume the subscription before the period ends if you change your mind.</li>
</ul>
<h2>3. Refunds</h2>
<p>Subscription fees are paid in advance for each period (monthly or yearly) and are non-refundable once the period has started, except in these cases:</p>
<ul>
<li><b>Duplicate charge or billing error:</b> we refund the excess amount in full.</li>
<li><b>Extended outage on our side:</b> if the service is unavailable for a significant period due to a problem on our end, we’ll compensate you with extra time or a partial refund, depending on the case.</li>
<li><b>Any case where a refund is required by law.</b></li>
</ul>
<h2>4. Upgrades and plan changes</h2>
<p>When you upgrade, you only pay the price difference. If you downgrade, the change takes effect at your next renewal.</p>
<h2>5. Wallet balance</h2>
<p>Your in-platform wallet balance is used for subscriptions and services. If you have a request about unused balance, contact us and we’ll review it with you.</p>
<h2>6. Your guests’ payments (tour bookings)</h2>
<p>Money your guests pay to book your tours (via payment links or your booking site) goes to your company. Refunds or cancellations of tour bookings are the tour company’s responsibility under its own policy and are not covered by this policy.</p>
<h2>7. How to request a refund</h2>
<p>Message us from the <a href="/contact">Contact</a> page using your account email and the reason for the request; we’ll reply within 5 business days. If approved, the amount is returned to the original payment method within 5-10 business days, depending on your bank.</p>
HTML;
    }

    // ================================================================== المدونة

    private function blogReady(): bool
    {
        try {
            $this->db->query('SELECT 1 FROM blog_posts LIMIT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function readMinutes(string $md): int
    {
        $words = count(preg_split('/\s+/u', MarkdownLite::plain($md), -1, PREG_SPLIT_NO_EMPTY));
        return max(1, (int) ceil($words / 200));
    }

    private function postCard(array $p): string
    {
        $cover = !empty($p['cover_url']) && MarkdownLite::safeUrl((string) $p['cover_url'])
            ? '<div class="post-cover"><img src="' . $this->e($p['cover_url']) . '" alt="" loading="lazy"></div>'
            : '<div class="post-cover ph"><span>' . $this->e(mb_substr((string) $p['title'], 0, 1)) . '</span></div>';
        $date = $p['published_at'] ? date('Y-m-d', strtotime((string) $p['published_at'])) : '';
        $excerpt = (string) ($p['excerpt'] ?: mb_substr(MarkdownLite::plain((string) $p['body']), 0, 160));
        $dir = $p['lang'] === 'ar' ? 'rtl' : 'ltr';
        return '<a class="post-card reveal" href="/blog/' . $this->e($p['slug']) . '" dir="' . $dir . '" lang="' . $this->e($p['lang']) . '">' . $cover . '<div class="post-body">'
            . '<div class="post-meta"><span>' . $this->e($date) . '</span><span>' . self::readMinutes((string) $p['body']) . ' ' . $this->te(['دقايق قراءة', 'min read']) . '</span></div>'
            . '<h3>' . $this->e($p['title']) . '</h3><p>' . $this->e($excerpt) . '</p></div></a>';
    }

    /** GET /blog */
    public function blog(array $params = []): array
    {
        $lang = MarketingContent::lang();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per = 12;
        $posts = [];
        $total = 0;
        if ($this->blogReady()) {
            $w = "status = 'published' AND published_at <= NOW()";
            $cntLang = (int) ($this->db->query("SELECT COUNT(*) c FROM blog_posts WHERE {$w} AND lang = ?", [$lang])[0]['c'] ?? 0);
            // لو مفيش مقالات بلغة الزائر، نعرض كل اللغات بدل صفحة فاضية
            $args = $cntLang > 0 ? [$lang] : [];
            $wl = $cntLang > 0 ? ' AND lang = ?' : '';
            $total = $cntLang > 0 ? $cntLang : (int) ($this->db->query("SELECT COUNT(*) c FROM blog_posts WHERE {$w}")[0]['c'] ?? 0);
            $off = ($page - 1) * $per;
            $posts = $this->db->query("SELECT id, slug, lang, title, excerpt, body, cover_url, published_at FROM blog_posts WHERE {$w}{$wl} ORDER BY published_at DESC LIMIT {$per} OFFSET {$off}", $args);
        }
        $grid = '';
        foreach ($posts as $p) {
            $grid .= $this->postCard($p);
        }
        if ($grid === '') {
            $grid = '<div class="empty-state" style="grid-column:1/-1"><b>' . $this->te(['المقالات جاية قريب', 'Articles coming soon']) . '</b>' . $this->te(['بنجهز نصايح عملية لشركات الرحلات - ارجع تاني قريب.', 'We’re preparing practical guides for tour companies - check back soon.']) . '</div>';
        }
        $pager = '';
        $pages = (int) ceil($total / $per);
        if ($pages > 1) {
            $pager = '<div class="hero-ctas" style="justify-content:center;margin-top:28px">'
                . ($page > 1 ? '<a class="cta-ghost" href="/blog?page=' . ($page - 1) . '">' . $this->te(['→ الأحدث', '← Newer']) . '</a>' : '')
                . ($page < $pages ? '<a class="cta-ghost" href="/blog?page=' . ($page + 1) . '">' . $this->te(['الأقدم ←', 'Older →']) . '</a>' : '') . '</div>';
        }
        $body = PublicLayout::hero($this->t(['المدونة', 'Blog']), $this->t(['نصايح عملية لشركات الرحلات', 'Practical guides for tour companies']), $this->t(['إزاي تجيب حجوزات أكتر، تحسّن تقييماتك، وتظهر على جوجل.', 'How to get more bookings, improve your reviews and show up on Google.']))
            . '<section class="blk tight" style="padding-top:0"><div class="post-grid">' . $grid . '</div>' . $pager . '</section>';
        $this->out(PublicLayout::render([
            'title' => $this->t(['المدونة', 'Blog']),
            'description' => $this->t(['مقالات ونصايح لشركات الرحلات والسياحة: الحجوزات، التسويق، التقييمات و SEO.', 'Articles and tips for tour and travel companies: bookings, marketing, reviews and SEO.']),
            'path' => '/blog', 'active' => 'blog', 'body' => $body,
        ]));
        return [];
    }

    /** GET /blog/{slug} */
    public function blogPost(array $params = []): array
    {
        $slug = mb_substr(rawurldecode((string) ($params['slug'] ?? '')), 0, 160); // الراوتر بيبعت الرابط encoded (عربي)
        if (!$this->blogReady() || $slug === '') {
            return $this->notFound();
        }
        $lang = MarketingContent::lang();
        $rows = $this->db->query("SELECT * FROM blog_posts WHERE slug = ? AND status = 'published' AND published_at <= NOW() ORDER BY (lang = ?) DESC LIMIT 1", [$slug, $lang]);
        if (!$rows) {
            return $this->notFound();
        }
        $p = $rows[0];
        try {
            $this->db->exec('UPDATE blog_posts SET views = views + 1 WHERE id = ?', [(int) $p['id']]);
        } catch (Throwable $e) {
        }
        $dir = $p['lang'] === 'ar' ? 'rtl' : 'ltr';
        $date = date('Y-m-d', strtotime((string) $p['published_at']));
        $cover = !empty($p['cover_url']) && MarkdownLite::safeUrl((string) $p['cover_url']) ? '<div class="post-hero-img"><img src="' . $this->e($p['cover_url']) . '" alt=""></div>' : '';
        $tags = '';
        foreach (array_filter(array_map('trim', explode(',', (string) $p['tags']))) as $tg) {
            $tags .= '<span>' . $this->e($tg) . '</span>';
        }
        $more = $this->db->query("SELECT id, slug, lang, title, excerpt, body, cover_url, published_at FROM blog_posts WHERE status = 'published' AND published_at <= NOW() AND id <> ? AND lang = ? ORDER BY published_at DESC LIMIT 3", [(int) $p['id'], $p['lang']]);
        $moreHtml = '';
        foreach ($more as $m) {
            $moreHtml .= $this->postCard($m);
        }
        $desc = (string) ($p['meta_description'] ?: $p['excerpt'] ?: mb_substr(MarkdownLite::plain((string) $p['body']), 0, 160));
        $body = '<article dir="' . $dir . '" lang="' . $this->e($p['lang']) . '"><header class="post-hero"><nav class="crumbs"><a href="/blog">' . $this->te(['المدونة', 'Blog']) . '</a></nav>'
            . '<h1>' . $this->e($p['title']) . '</h1><div class="post-meta"><time datetime="' . $this->e($date) . '">' . $this->e($date) . '</time><span>' . self::readMinutes((string) $p['body']) . ' ' . $this->te(['دقايق قراءة', 'min read']) . '</span></div></header>'
            . $cover . '<div class="article prose">' . MarkdownLite::toHtml((string) $p['body']) . ($tags ? '<div class="tags">' . $tags . '</div>' : '') . '</div></article>'
            . ($moreHtml ? '<section class="blk"><div class="sec-head"><h2 class="sec-title" style="font-size:22px">' . $this->te(['مقالات تانية', 'More articles']) . '</h2></div><div class="post-grid">' . $moreHtml . '</div></section>' : '')
            . PublicLayout::ctaBand();
        $base = PublicLayout::baseUrl();
        $this->out(PublicLayout::render([
            'title' => (string) $p['title'],
            'description' => $desc,
            'path' => '/blog/' . $p['slug'], 'active' => 'blog', 'body' => $body, 'ogType' => 'article',
            'ogImage' => !empty($p['cover_url']) && preg_match('#^https?://#', (string) $p['cover_url']) ? (string) $p['cover_url'] : null,
            'jsonld' => [
                ['@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => (string) $p['title'], 'description' => $desc, 'datePublished' => date('c', strtotime((string) $p['published_at'])),
                    'dateModified' => date('c', strtotime((string) $p['updated_at'])), 'inLanguage' => $p['lang'], 'mainEntityOfPage' => $base . '/blog/' . $p['slug'],
                    'publisher' => ['@type' => 'Organization', 'name' => 'Tourfecto']] + (!empty($p['cover_url']) ? ['image' => (string) $p['cover_url']] : []),
                PublicLayout::breadcrumb([[$this->t(['المدونة', 'Blog']), '/blog'], [(string) $p['title'], '/blog/' . $p['slug']]]),
            ],
        ]));
        return [];
    }

    // ================================================================== 404

    private function notFound(): array
    {
        $body = PublicLayout::hero('404', $this->t(['الصفحة دي مش موجودة', 'This page doesn’t exist']), $this->t(['يمكن اللينك اتغير. جرّب من هنا:', 'The link may have changed. Try one of these:']),
            '<a href="/" class="btn-primary">' . $this->te(['الرئيسية', 'Home']) . '</a><a href="/features" class="cta-ghost">' . $this->te(['المميزات', 'Features']) . '</a><a href="/blog" class="cta-ghost">' . $this->te(['المدونة', 'Blog']) . '</a>');
        $this->out(PublicLayout::render(['title' => '404', 'description' => '', 'body' => $body, 'noindex' => true]), 404);
        return [];
    }
}
