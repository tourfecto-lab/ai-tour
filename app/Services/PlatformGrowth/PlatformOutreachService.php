<?php

/**
 * Tourfecto - تسويق المنصة نفسها لشركات السياحة (من لوحة الأدمن)
 *
 * - استيراد شركات من Apify (Google Maps وغيره) أو JSON/CSV، من غير تكرار
 * - لو الشركة مالهاش إيميل: بندوّر عليه في موقعها (صفحة التواصل)
 * - سلسلة من 3 رسايل (تعارف ← تحليل مجاني ← رسالة أخيرة) مخصصة لكل شركة
 *   باسمها ومدينتها وتقييمها على جوجل، وبالعربي أو الإنجليزي حسب البلد
 * - حماية سمعة الإيميل: حد يومي بيزيد تدريجيًا، مواعيد عمل، لينك إلغاء اشتراك
 *   في كل رسالة، وقف تلقائي لو الإيميلات الراجعة كترت
 * - الشركة اللي تسجّل في المنصة بتقف رسايلها لوحدها
 */
class PlatformOutreachService
{
    public const STATUSES = ['new', 'no_email', 'queued', 'contacted', 'finished', 'replied', 'interested', 'signed_up', 'unsubscribed', 'bounced', 'invalid', 'paused'];
    private const STOP = ['replied', 'interested', 'signed_up', 'unsubscribed', 'bounced', 'invalid', 'paused', 'finished'];
    private const ARAB = ['EG', 'SA', 'AE', 'KW', 'QA', 'BH', 'OM', 'JO', 'LB', 'IQ', 'SY', 'PS', 'YE', 'LY', 'TN', 'DZ', 'MA', 'SD', 'MR'];
    private const BAD_LOCAL = '/^(no-?reply|donotreply|do-not-reply|mailer-daemon|postmaster|abuse|webmaster|privacy|gdpr|example|test|user|email|your|name)$/i';
    private const BAD_DOMAIN = '/(example\.(com|org)|sentry\.io|wixpress\.com|sentry-next|domain\.com|email\.com|yourdomain|godaddy\.com|\.png|\.jpe?g|\.gif|\.webp|\.svg)$/i';

    private PDO $pdo;
    private SystemSettingsService $settings;
    /** @var callable(string,int):?array{body:string,url:string} */
    private $fetch;

    /** بنتأكد إن دومين الإيميل بيستقبل بريد قبل الإرسال (بيتقفل في الاختبارات) */
    public bool $checkMx = true;

    public function __construct(?PDO $pdo = null, ?SystemSettingsService $settings = null, ?callable $fetch = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->settings = $settings ?? new SystemSettingsService();
        $this->fetch = $fetch ?? [self::class, 'httpFetch'];
        $this->ensureSchema();
    }

    private function q(string $sql, array $args = []): PDOStatement
    {
        $s = $this->pdo->prepare($sql);
        $s->execute($args);
        return $s;
    }

    /** الجداول بتتعمل لوحدها أول مرة (من غير ما الأدمن يشغّل SQL) */
    private function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS platform_leads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source VARCHAR(40) NOT NULL DEFAULT 'import',
            company VARCHAR(255) NOT NULL DEFAULT '',
            email VARCHAR(255) NULL,
            phone VARCHAR(80) NULL,
            website VARCHAR(500) NULL,
            domain VARCHAR(255) NULL,
            city VARCHAR(120) NULL,
            country VARCHAR(8) NULL,
            category VARCHAR(255) NULL,
            rating DECIMAL(3,2) NULL,
            reviews INT NULL,
            maps_url VARCHAR(700) NULL,
            language VARCHAR(5) NOT NULL DEFAULT 'en',
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            step TINYINT NOT NULL DEFAULT 0,
            next_send_at DATETIME NULL,
            last_sent_at DATETIME NULL,
            opened_at DATETIME NULL,
            clicked_at DATETIME NULL,
            enrich_tries TINYINT NOT NULL DEFAULT 0,
            token CHAR(32) NOT NULL,
            notes TEXT NULL,
            data_json TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_pl_email (email),
            UNIQUE KEY uq_pl_token (token),
            KEY idx_pl_status (status, next_send_at),
            KEY idx_pl_domain (domain)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS platform_lead_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            step TINYINT NOT NULL,
            subject VARCHAR(255) NOT NULL,
            status VARCHAR(12) NOT NULL,
            error VARCHAR(500) NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            opened_at DATETIME NULL,
            clicked_at DATETIME NULL,
            KEY idx_plm_lead (lead_id),
            KEY idx_plm_sent (sent_at)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done = true;
    }

    // ------------------------------------------------------------------ الإعدادات

    public static function defaults(): array
    {
        return [
            'enabled' => '0',
            'daily_limit' => '40',
            'per_run' => '3',
            'hour_from' => '9',
            'hour_to' => '17',
            'skip_days' => '5', // الجمعة (0=الأحد)
            'timezone' => 'Africa/Cairo',
            'step_gaps' => '3,4',
            'sender_name' => 'Tourfecto',
            'sender_title' => '',
            'from_email' => '',
            'reply_to' => '',
            'smtp_host' => '',
            'smtp_port' => '587',
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_encryption' => 'tls',
            'signup_url' => (defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://tourfecto.pro') . '/register',
            'company_address' => 'Tourfecto · Cairo, Egypt',
            'ai_opener' => '1',
            'track_opens' => '0',
            'source_url' => '',
            'first_send_date' => '',
            'templates' => '',
        ];
    }

    public function getSettings(bool $forAdmin = false): array
    {
        $out = [];
        foreach (self::defaults() as $k => $v) {
            $out[$k] = $this->settings->get('outreach_' . $k, $v);
        }
        if ($out['smtp_password'] !== '') {
            try {
                $out['smtp_password'] = (new Encryption())->decrypt($out['smtp_password'], 'platform-outreach');
            } catch (Throwable $e) {
                $out['smtp_password'] = '';
            }
        }
        $tpl = json_decode($out['templates'], true);
        $out['templates'] = is_array($tpl) && $tpl ? array_replace_recursive(self::defaultTemplates(), $tpl) : self::defaultTemplates();
        if ($forAdmin) {
            $out['smtp_password_set'] = $out['smtp_password'] !== '';
            $out['smtp_password'] = '';
        }
        return $out;
    }

    public function saveSettings(array $in): array
    {
        $d = self::defaults();
        foreach ($in as $k => $v) {
            if (!array_key_exists($k, $d) || in_array($k, ['first_send_date'], true)) {
                continue;
            }
            if ($k === 'templates') {
                $v = json_encode(self::cleanTemplates($v), JSON_UNESCAPED_UNICODE);
            } elseif ($k === 'smtp_password') {
                if (!is_string($v) || $v === '') {
                    continue; // فاضي = سيب القديم
                }
                $v = (new Encryption())->encrypt($v, 'platform-outreach');
            } else {
                $v = mb_substr(trim((string) (is_bool($v) ? (int) $v : $v)), 0, 500);
            }
            if (in_array($k, ['from_email', 'reply_to'], true) && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('إيميل المرسل مش مظبوط');
            }
            if ($k === 'daily_limit') {
                $v = (string) max(1, min(300, (int) $v));
            }
            if (in_array($k, ['hour_from', 'hour_to'], true)) {
                $v = (string) max(0, min(23, (int) $v));
            }
            $this->settings->set('outreach_' . $k, (string) $v);
        }
        return $this->getSettings(true);
    }

    private static function cleanTemplates($v): array
    {
        $out = self::defaultTemplates();
        if (!is_array($v)) {
            return $out;
        }
        foreach (['ar', 'en'] as $lang) {
            foreach ([1, 2, 3] as $step) {
                $t = $v[$lang][$step] ?? $v[$lang][(string) $step] ?? null;
                if (is_array($t) && is_string($t['subject'] ?? null) && is_string($t['body'] ?? null) && trim($t['subject']) !== '' && trim($t['body']) !== '') {
                    $out[$lang][$step] = ['subject' => mb_substr(trim($t['subject']), 0, 200), 'body' => mb_substr(trim($t['body']), 0, 5000)];
                }
            }
        }
        return $out;
    }

    /** الرسايل الافتراضية: قصيرة وشخصية ودعوة واحدة واضحة */
    public static function defaultTemplates(): array
    {
        return [
            'ar' => [
                1 => ['subject' => '{{company}}: فكرة سريعة لحجوزات أكتر', 'body' => "أهلًا فريق {{company}}،\n\n{{opener}}\n\nأنا {{sender_name}} من Tourfecto، منصة ذكاء اصطناعي معمولة مخصوص لشركات السياحة. بتاخد منكم الشغل اليومي اللي بياكل الوقت:\n\n• ترد على رسائل العملاء في واتساب وفيسبوك وإنستجرام 24 ساعة\n• تكتب مقالات وتظبط موقعكم عشان يظهر أعلى في جوجل\n• تعمل خطة سوشيال شهرية بصوركم ولوجو شركتكم وتنشرها لوحدها\n• ترد على تقييمات جوجل باحترافية\n\nتحبوا تجربوها على {{company}}؟ التسجيل بياخد دقيقتين:\n{{signup_link}}\n\nتحياتي،\n{{sender_name}}{{sender_title_line}}"],
                2 => ['subject' => 'تحليل مجاني لموقع {{company}}', 'body' => "أهلًا تاني،\n\nحبيت أكمّل على رسالتي اللي فاتت بحاجة مفيدة ليكم حتى لو مااشتركتوش:\n\nTourfecto بتعمل تحليل مجاني لموقع {{website_or_company}} في دقيقة: إيه اللي موقفكم في جوجل، والمنافسين بيعملوا إيه، وإزاي تجيبوا حجوزات أكتر من الموقع.\n\nسجّلوا وشغّلوا التحليل بنفسكم:\n{{signup_link}}\n\nأو ردّوا على الإيميل ده بكلمة \"موافق\" وأنا أبعتلكم النتيجة.\n\n{{sender_name}}"],
                3 => ['subject' => 'آخر رسالة مني ({{company}})', 'body' => "أهلًا،\n\nمش عاوز أزحم الإيميل عندكم، فدي آخر رسالة.\n\nلو في وقت تاني حابين تخلّوا الرد على العملاء والتسويق يشتغل لوحده، Tourfecto موجودة وتقدروا تبدأوا من هنا:\n{{signup_link}}\n\nبالتوفيق لـ {{company}}،\n{{sender_name}}"],
            ],
            'en' => [
                1 => ['subject' => '{{company}}: a quick idea for more bookings', 'body' => "Hi {{company}} team,\n\n{{opener}}\n\nI'm {{sender_name}} from Tourfecto, an AI platform built for tour and travel companies. It takes the daily busywork off your plate:\n\n• Replies to customer messages on WhatsApp, Facebook and Instagram 24/7\n• Writes articles and fixes your website SEO so you rank higher on Google\n• Builds a monthly social plan with your own photos and logo, and posts it for you\n• Answers your Google reviews professionally\n\nWould you like to try it for {{company}}? Signing up takes two minutes:\n{{signup_link}}\n\nBest regards,\n{{sender_name}}{{sender_title_line}}"],
                2 => ['subject' => 'Free website check for {{company}}', 'body' => "Hi again,\n\nFollowing up with something useful even if you don't sign up:\n\nTourfecto runs a free one-minute analysis of {{website_or_company}}: what is holding you back on Google, what competitors do better, and how to get more bookings from your site.\n\nRun it yourself here:\n{{signup_link}}\n\nOr just reply \"yes\" and I'll send you the results.\n\n{{sender_name}}"],
                3 => ['subject' => 'Closing the loop ({{company}})', 'body' => "Hi,\n\nI don't want to crowd your inbox, so this is my last note.\n\nIf you ever want customer replies and marketing to run on autopilot, Tourfecto is here:\n{{signup_link}}\n\nWishing {{company}} a great season,\n{{sender_name}}"],
            ],
        ];
    }

    // ------------------------------------------------------------------ الاستيراد

    /** @return array{added:int, updated:int, skipped:int, no_email:int, total:int} */
    public function importFromUrl(string $url): array
    {
        if (!preg_match('#^https://#i', $url)) {
            throw new InvalidArgumentException('الرابط لازم يبدأ بـ https://');
        }
        $u = $url;
        if (preg_match('#api\.apify\.com/v2/datasets/#i', $u)) {
            if (!preg_match('/[?&]format=/', $u)) {
                $u .= (strpos($u, '?') === false ? '?' : '&') . 'format=json';
            }
            if (!preg_match('/[?&]clean=/', $u)) {
                $u .= '&clean=true';
            }
        }
        $r = ($this->fetch)($u, 60000000);
        if (!$r) {
            throw new RuntimeException('مقدرناش نفتح الرابط. اتأكد إن الرابط شغال (ولو من Apify اتأكد إن الـ signature لسه صالحة).');
        }
        $rows = json_decode($r['body'], true);
        if (!is_array($rows)) {
            throw new InvalidArgumentException('الرابط مارجّعش بيانات JSON');
        }
        $this->settings->set('outreach_source_url', $url);
        return $this->importRows(array_values($rows), preg_match('/apify/i', $url) ? 'apify' : 'import');
    }

    /** JSON أو CSV متلزق */
    public function importText(string $text): array
    {
        $text = trim($text);
        $rows = json_decode($text, true);
        if (!is_array($rows)) {
            $lines = preg_split('/\r?\n/', $text);
            $head = str_getcsv(array_shift($lines));
            $rows = [];
            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $vals = str_getcsv($line);
                $rows[] = array_combine(array_map('strtolower', array_map('trim', $head)), array_pad(array_slice($vals, 0, count($head)), count($head), ''));
            }
        }
        return $this->importRows(array_values($rows), 'import');
    }

    public function importRows(array $rows, string $source): array
    {
        $stats = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'no_email' => 0, 'total' => count($rows)];
        $customers = $this->customerKeys();
        foreach (array_slice($rows, 0, 20000) as $row) {
            if (!is_array($row)) {
                $stats['skipped']++;
                continue;
            }
            $lead = self::mapRow($row);
            if ($lead['company'] === '' && $lead['email'] === null && $lead['website'] === null) {
                $stats['skipped']++;
                continue;
            }
            // عملاء المنصة الحاليين مابنبعتلهمش تسويق
            if (($lead['email'] && isset($customers['e:' . strtolower($lead['email'])])) || ($lead['domain'] && isset($customers['d:' . $lead['domain']]))) {
                $stats['skipped']++;
                continue;
            }
            $existing = null;
            if ($lead['email']) {
                $existing = $this->q('SELECT id,status FROM platform_leads WHERE email=?', [$lead['email']])->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            if (!$existing && $lead['domain']) {
                $existing = $this->q('SELECT id,status,email FROM platform_leads WHERE domain=? LIMIT 1', [$lead['domain']])->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($existing && $lead['email'] && !empty($existing['email']) && strcasecmp($existing['email'], $lead['email']) !== 0) {
                    $existing = null; // نفس الشركة بإيميل تاني: نضيفه (بنقفل الرسايل لو حد منهم ردّ)
                }
            }
            if ($existing) {
                // بنكمّل البيانات الناقصة بس، من غير ما نلمس الحالة
                $this->q('UPDATE platform_leads SET email=COALESCE(email,?),phone=COALESCE(phone,?),website=COALESCE(website,?),domain=COALESCE(domain,?),city=COALESCE(city,?),country=COALESCE(country,?),category=COALESCE(category,?),rating=COALESCE(rating,?),reviews=COALESCE(reviews,?),maps_url=COALESCE(maps_url,?),status=IF(status=\'no_email\' AND ? IS NOT NULL,\'new\',status) WHERE id=?', [
                    $lead['email'], $lead['phone'], $lead['website'], $lead['domain'], $lead['city'], $lead['country'], $lead['category'], $lead['rating'], $lead['reviews'], $lead['maps_url'], $lead['email'], $existing['id'],
                ]);
                $stats['updated']++;
                continue;
            }
            $status = $lead['email'] ? 'new' : 'no_email';
            if (!$lead['email']) {
                $stats['no_email']++;
            }
            $this->q('INSERT INTO platform_leads(source,company,email,phone,website,domain,city,country,category,rating,reviews,maps_url,language,status,token,data_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                $source, $lead['company'], $lead['email'], $lead['phone'], $lead['website'], $lead['domain'], $lead['city'], $lead['country'], $lead['category'], $lead['rating'], $lead['reviews'], $lead['maps_url'], $lead['language'], $status, bin2hex(random_bytes(16)), json_encode($lead['extra'], JSON_UNESCAPED_UNICODE),
            ]);
            $stats['added']++;
        }
        return $stats;
    }

    /** أعمدة Apify (Google Maps / Contact scraper) أو أي جدول عادي */
    public static function mapRow(array $r): array
    {
        $pick = static function (array $keys) use ($r) {
            foreach ($keys as $k) {
                if (isset($r[$k]) && is_scalar($r[$k]) && trim((string) $r[$k]) !== '') {
                    return trim((string) $r[$k]);
                }
            }
            return null;
        };
        $emails = [];
        foreach ($r as $k => $v) {
            if (stripos((string) $k, 'email') === false) {
                continue;
            }
            foreach ((array) $v as $e) {
                if (is_string($e)) {
                    $emails[] = $e;
                } elseif (is_array($e) && is_string($e['value'] ?? $e['email'] ?? null)) {
                    $emails[] = $e['value'] ?? $e['email'];
                }
            }
        }
        $website = $pick(['website', 'site', 'url_website', 'domain', 'web', 'Website', 'الموقع']);
        if ($website && !preg_match('#^https?://#i', $website)) {
            $website = 'https://' . $website;
        }
        if ($website && preg_match('#(google\.[a-z.]+/maps|facebook\.com|instagram\.com|wa\.me|tripadvisor\.)#i', $website)) {
            $website = null; // مش موقع الشركة
        }
        $domain = $website ? strtolower(preg_replace('/^www\./i', '', (string) parse_url($website, PHP_URL_HOST))) : null;
        $email = self::bestEmail($emails, $domain);
        $country = strtoupper((string) ($pick(['countryCode', 'country_code', 'country']) ?? ''));
        $country = preg_match('/^[A-Z]{2}$/', $country) ? $country : (preg_match('/egypt|مصر/i', $country) ? 'EG' : null);
        $phone = $pick(['phone', 'phoneUnformatted', 'phone_number', 'phones', 'الهاتف']);
        if (!$phone && is_array($r['phones'] ?? null) && is_string($r['phones'][0] ?? null)) {
            $phone = $r['phones'][0];
        }
        $category = $pick(['categoryName', 'category', 'type']);
        if (!$category && is_array($r['categories'] ?? null)) {
            $category = implode(', ', array_slice(array_filter($r['categories'], 'is_string'), 0, 3));
        }
        $rating = $pick(['totalScore', 'rating', 'stars']);
        $reviews = $pick(['reviewsCount', 'reviews', 'userRatingsTotal']);
        $company = (string) ($pick(['title', 'name', 'company', 'companyName', 'business_name', 'الاسم', 'الشركة']) ?? '');
        $arabicName = preg_match('/\p{Arabic}/u', $company) === 1;
        return [
            'company' => mb_substr($company, 0, 255),
            'email' => $email,
            'phone' => $phone ? mb_substr($phone, 0, 80) : null,
            'website' => $website ? mb_substr($website, 0, 500) : null,
            'domain' => $domain ?: null,
            'city' => ($c = $pick(['city', 'neighborhood', 'state', 'المدينة'])) ? mb_substr($c, 0, 120) : null,
            'country' => $country,
            'category' => $category ? mb_substr($category, 0, 255) : null,
            'rating' => is_numeric($rating) && $rating > 0 ? round((float) $rating, 2) : null,
            'reviews' => is_numeric($reviews) ? (int) $reviews : null,
            'maps_url' => ($m = $pick(['url', 'placeUrl', 'googleMapsUrl'])) && preg_match('#^https?://#', $m) ? mb_substr($m, 0, 700) : null,
            'language' => ($country && in_array($country, self::ARAB, true)) || (!$country && $arabicName) ? 'ar' : 'en',
            'extra' => array_filter(['address' => $pick(['address', 'street']), 'place_id' => $pick(['placeId'])]),
        ];
    }

    /** أحسن إيميل: اللي على دومين الشركة، وبعدين info/sales/booking */
    public static function bestEmail(array $emails, ?string $domain): ?string
    {
        $good = [];
        foreach ($emails as $e) {
            $e = strtolower(trim(preg_replace('/^mailto:/i', '', (string) $e)));
            $e = preg_replace('/\?.*$/', '', $e);
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            [$local, $host] = explode('@', $e, 2);
            if (preg_match(self::BAD_LOCAL, $local) || preg_match(self::BAD_DOMAIN, $host) || strlen($e) > 120) {
                continue;
            }
            $score = 0;
            if ($domain && ($host === $domain || str_ends_with($host, '.' . $domain))) {
                $score += 10;
            }
            if (preg_match('/^(info|sales|booking|bookings|reservations?|contact|hello|tours?|travel|marketing|ceo|manager)/', $local)) {
                $score += 3;
            }
            $good[$e] = max($good[$e] ?? 0, $score);
        }
        if (!$good) {
            return null;
        }
        arsort($good);
        return (string) array_key_first($good);
    }

    private function customerKeys(): array
    {
        $out = [];
        try {
            foreach ($this->q('SELECT email FROM users')->fetchAll(PDO::FETCH_COLUMN) as $e) {
                $out['e:' . strtolower((string) $e)] = true;
            }
            foreach ($this->q('SELECT main_url FROM websites')->fetchAll(PDO::FETCH_COLUMN) as $u) {
                $h = strtolower(preg_replace('/^www\./i', '', (string) parse_url((string) $u, PHP_URL_HOST)));
                if ($h !== '') {
                    $out['d:' . $h] = true;
                }
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    // ------------------------------------------------------------------ إيميل من الموقع

    /** الشركات اللي مالهاش إيميل: بندوّر في صفحة التواصل بتاعتها */
    public function enrich(int $limit = 8, int $budget = 40): int
    {
        $start = time();
        $found = 0;
        $rows = $this->q("SELECT id,website,domain FROM platform_leads WHERE status='no_email' AND website IS NOT NULL AND enrich_tries<2 ORDER BY enrich_tries,id LIMIT " . (int) $limit)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $l) {
            if (time() - $start > $budget) {
                break;
            }
            $this->q('UPDATE platform_leads SET enrich_tries=enrich_tries+1 WHERE id=?', [$l['id']]);
            $emails = [];
            $base = rtrim((string) $l['website'], '/');
            foreach ([$base . '/', $base . '/contact', $base . '/contact-us', $base . '/ar/contact'] as $i => $url) {
                $p = ($this->fetch)($url, 1500000);
                if (!$p) {
                    continue;
                }
                $emails = array_merge($emails, self::emailsIn($p['body']));
                if ($i === 0 && preg_match_all('#href=["\']([^"\']*(contact|about|تواصل|اتصل)[^"\']*)["\']#iu', $p['body'], $m)) {
                    // لو فيه لينك "تواصل معنا" مختلف
                    foreach (array_slice($m[1], 0, 1) as $href) {
                        $abs = preg_match('#^https?://#i', $href) ? $href : $base . '/' . ltrim($href, '/');
                        if (($q = ($this->fetch)($abs, 1500000))) {
                            $emails = array_merge($emails, self::emailsIn($q['body']));
                        }
                    }
                }
                if (self::bestEmail($emails, $l['domain'])) {
                    break;
                }
            }
            $email = self::bestEmail($emails, $l['domain']);
            if ($email && !$this->q('SELECT 1 FROM platform_leads WHERE email=?', [$email])->fetchColumn()) {
                $this->q("UPDATE platform_leads SET email=?,status='new' WHERE id=?", [$email, $l['id']]);
                $found++;
            }
        }
        return $found;
    }

    public static function emailsIn(string $html): array
    {
        $html = str_ireplace(['[at]', '(at)', ' at ', '&#64;', '&commat;'], ['@', '@', '@', '@', '@'], $html);
        preg_match_all('/(?:mailto:)?([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})/', $html, $m);
        return array_values(array_unique($m[1]));
    }

    // ------------------------------------------------------------------ كتابة الرسالة

    public function compose(array $lead, int $step, array $set, ?callable $ai = null): array
    {
        $lang = $lead['language'] === 'ar' ? 'ar' : 'en';
        $tpl = $set['templates'][$lang][$step] ?? self::defaultTemplates()[$lang][$step];
        $opener = $step === 1 ? $this->opener($lead, $lang, $set, $ai) : '';
        $link = self::withParams((string) $set['signup_url'], ['utm_source' => 'outreach', 'utm_medium' => 'email', 'utm_campaign' => 'step' . $step, 'ref' => $lead['token']]);
        $vars = [
            '{{company}}' => $lead['company'] !== '' ? $lead['company'] : ($lang === 'ar' ? 'شركتكم' : 'your company'),
            '{{city}}' => (string) ($lead['city'] ?? ''),
            '{{rating}}' => $lead['rating'] !== null ? rtrim(rtrim(number_format((float) $lead['rating'], 1), '0'), '.') : '',
            '{{reviews}}' => (string) ($lead['reviews'] ?? ''),
            '{{website_or_company}}' => $lead['domain'] ?: ($lead['company'] ?: ''),
            '{{opener}}' => $opener,
            '{{signup_link}}' => '__LINK__',
            '{{sender_name}}' => (string) $set['sender_name'],
            '{{sender_title_line}}' => trim((string) $set['sender_title']) !== '' ? "\n" . $set['sender_title'] : '',
        ];
        $subject = trim(preg_replace('/\s+/u', ' ', strtr($tpl['subject'], $vars)));
        $text = preg_replace("/\n{3,}/", "\n\n", strtr($tpl['body'], $vars));
        $unsub = (defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://tourfecto.pro') . '/p/u/' . $lead['token'];
        $click = (defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://tourfecto.pro') . '/p/c/' . $lead['token'] . '/' . $step;
        $html = self::toHtml($text, $click, $link, $lang);
        $footer = $lang === 'ar'
            ? 'وصلتك الرسالة دي لأن شركتكم ظاهرة كشركة سياحة على الإنترنت. مش عاوز رسايل تاني؟ <a href="' . htmlspecialchars($unsub) . '" style="color:#888">إلغاء الاشتراك</a>'
            : 'You received this because your company is listed publicly as a travel business. Not interested? <a href="' . htmlspecialchars($unsub) . '" style="color:#888">Unsubscribe</a>';
        $dir = $lang === 'ar' ? 'rtl' : 'ltr';
        $pixel = !empty($set['track_opens']) && $set['track_opens'] !== '0'
            ? '<img src="' . htmlspecialchars((defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/p/o/' . $lead['token'] . '/' . $step) . '" width="1" height="1" alt="">' : '';
        $body = '<div dir="' . $dir . '" style="font-family:Arial,Tahoma,sans-serif;font-size:15px;line-height:1.7;color:#1f2933;max-width:600px">' . $html
            . '<p style="font-size:12px;color:#888;margin-top:28px;border-top:1px solid #eee;padding-top:10px">' . $footer . '<br>' . htmlspecialchars((string) $set['company_address']) . '</p>' . $pixel . '</div>';
        return ['subject' => $subject, 'html' => $body, 'text' => str_replace('__LINK__', $link, $text), 'unsubscribe' => $unsub];
    }

    private static function toHtml(string $text, string $click, string $link, string $lang): string
    {
        $out = '';
        foreach (preg_split("/\n{2,}/", trim($text)) as $para) {
            $lines = array_map(static fn ($l) => htmlspecialchars($l), explode("\n", $para));
            $p = implode('<br>', $lines);
            $btn = '<a href="' . htmlspecialchars($click) . '" style="color:#0E4D64;font-weight:bold">' . ($lang === 'ar' ? 'جرّب Tourfecto مجانًا' : 'Try Tourfecto free') . '</a>';
            $p = str_replace('__LINK__', $btn, $p);
            $out .= '<p style="margin:0 0 14px">' . $p . '</p>';
        }
        return $out;
    }

    private static function withParams(string $url, array $params): string
    {
        return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
    }

    /** أول سطر شخصي: من تقييم جوجل، أو جملة يكتبها الذكاء الصناعي */
    private function opener(array $lead, string $lang, array $set, ?callable $ai): string
    {
        if (($set['ai_opener'] ?? '1') !== '0') {
            $ai ??= static function (string $prompt): ?string {
                if (!class_exists('AiText')) {
                    return null;
                }
                $r = AiText::generate($prompt, ['task' => 'platform_outreach', 'maxOutputTokens' => 200, 'temperature' => 0.7]);
                return !empty($r['success']) ? (string) $r['data'] : null;
            };
            $facts = array_filter(['company' => $lead['company'], 'category' => $lead['category'], 'city' => $lead['city'], 'country' => $lead['country'], 'google_rating' => $lead['rating'], 'google_reviews' => $lead['reviews'], 'website' => $lead['domain']]);
            $prompt = 'Write ONE short, warm, specific opening sentence (max 25 words) for a cold email to this travel company, '
                . ($lang === 'ar' ? 'in simple Egyptian-friendly Arabic' : 'in English')
                . '. Compliment something real from the facts (rating, reviews, city, type of tours). Do not invent facts, do not mention AI, no greeting, no emojis, no quotes. Facts: ' . json_encode($facts, JSON_UNESCAPED_UNICODE);
            try {
                $line = $ai($prompt);
                $line = trim((string) preg_replace('/^["\'«]|["\'»]$/u', '', trim((string) $line)));
                if ($line !== '' && mb_strlen($line) <= 220 && strpos($line, "\n") === false && !preg_match('/\{\{|\[|AI|ذكاء/u', $line)) {
                    return $line;
                }
            } catch (Throwable $e) {
            }
        }
        if ($lead['rating'] !== null && (int) $lead['reviews'] >= 5) {
            $r = rtrim(rtrim(number_format((float) $lead['rating'], 1), '0'), '.');
            return $lang === 'ar'
                ? 'شفت إن تقييمكم ' . $r . ' من ' . (int) $lead['reviews'] . ' تقييم على جوجل' . ($lead['city'] ? ' في ' . $lead['city'] : '') . '، وده مجهود يستاهل يوصل لعملاء أكتر.'
                : 'I noticed your ' . $r . '-star rating from ' . (int) $lead['reviews'] . ' Google reviews' . ($lead['city'] ? ' in ' . $lead['city'] : '') . ', which is the kind of reputation that deserves more bookings.';
        }
        return $lang === 'ar' ? 'شفت شركتكم وأنا بدوّر على شركات سياحة' . ($lead['city'] ? ' في ' . $lead['city'] : '') . ' شغلها مميز.' : 'I came across your company while looking for standout travel businesses' . ($lead['city'] ? ' in ' . $lead['city'] : '') . '.';
    }

    // ------------------------------------------------------------------ الإرسال

    /** الحد المسموح النهارده (بيزيد تدريجيًا عشان الإيميل مايتعلّمش سبام) */
    public function todayLimit(array $set): int
    {
        $max = max(1, (int) $set['daily_limit']);
        $first = (string) $set['first_send_date'];
        $days = $first !== '' ? max(0, (int) floor((time() - strtotime($first)) / 86400)) : 0;
        return min($max, 15 + 10 * $days);
    }

    public function sentToday(array $set): int
    {
        $tz = new DateTimeZone($set['timezone'] ?: 'Africa/Cairo');
        $start = (new DateTimeImmutable('today', $tz))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        return (int) $this->q("SELECT COUNT(*) FROM platform_lead_messages WHERE status='sent' AND sent_at>=?", [$start])->fetchColumn();
    }

    public function inWindow(array $set, ?DateTimeImmutable $now = null): bool
    {
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone($set['timezone'] ?: 'Africa/Cairo'));
        $h = (int) $now->format('G');
        $skip = array_filter(array_map('trim', explode(',', (string) $set['skip_days'])), 'strlen');
        return $h >= (int) $set['hour_from'] && $h < (int) $set['hour_to'] && !in_array($now->format('w'), $skip, true);
    }

    /**
     * دورة إرسال (من الكرون كل 5 دقايق)
     * @param callable|null $send للاختبار: fn(email,name,subject,html,headers):array
     */
    public function run(?callable $send = null, ?callable $ai = null, bool $force = false): array
    {
        $set = $this->getSettings();
        $this->markSignedUp();
        $res = ['sent' => 0, 'failed' => 0, 'reason' => null, 'enriched' => 0];
        if ($set['enabled'] !== '1' && !$force) {
            $res['reason'] = 'disabled';
            return $res;
        }
        if (!$force && !$this->inWindow($set)) {
            $res['reason'] = 'outside_hours';
            return $res;
        }
        if ($this->tooManyBounces()) {
            $this->settings->set('outreach_enabled', '0');
            $this->settings->set('outreach_paused_reason', 'bounces');
            $res['reason'] = 'paused_bounces';
            return $res;
        }
        $left = $this->todayLimit($set) - $this->sentToday($set);
        if ($left <= 0) {
            $res['reason'] = 'daily_limit';
            return $res;
        }
        $mailer = null;
        if (!$send) {
            $mailer = new Mailer();
            if ($set['smtp_host'] !== '') {
                $mailer->configure(['host' => $set['smtp_host'], 'port' => (int) $set['smtp_port'], 'username' => $set['smtp_username'], 'password' => $set['smtp_password'], 'encryption' => $set['smtp_encryption']]);
            }
            $mailer->configure(['from_email' => $set['from_email'], 'from_name' => $set['sender_name']]);
            if (method_exists($mailer, 'context')) {
                $mailer->context('platform_outreach');
            }
            $send = static fn ($to, $name, $subject, $html, $headers) => $mailer->send($to, $name, $subject, $html, $headers);
        }
        $gaps = array_map('intval', explode(',', (string) $set['step_gaps'])) + [0 => 3, 1 => 4];
        $batch = min($left, max(1, (int) $set['per_run']));
        $rows = $this->q("SELECT * FROM platform_leads WHERE email IS NOT NULL AND ((status='new' AND step=0) OR (status='contacted' AND step BETWEEN 1 AND 2 AND next_send_at<=NOW())) ORDER BY step DESC, (rating IS NULL), reviews DESC, id LIMIT " . (int) $batch)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $lead) {
            $step = (int) $lead['step'] + 1;
            // نفس الشركة (نفس الدومين) ردّت أو سجّلت أو ألغت: مانكمّلش على باقي إيميلاتها
            if ($lead['domain'] && $this->q("SELECT 1 FROM platform_leads WHERE domain=? AND id<>? AND status IN ('replied','interested','signed_up','unsubscribed') LIMIT 1", [$lead['domain'], $lead['id']])->fetchColumn()) {
                $this->q("UPDATE platform_leads SET status='paused',notes=CONCAT(COALESCE(notes,''),' [same company already handled]') WHERE id=?", [$lead['id']]);
                continue;
            }
            if ($this->checkMx && !self::domainAcceptsMail((string) $lead['email'])) {
                $this->q("UPDATE platform_leads SET status='invalid' WHERE id=?", [$lead['id']]);
                continue;
            }
            $msg = $this->compose($lead, $step, $set, $ai);
            $headers = [
                'List-Unsubscribe' => '<' . $msg['unsubscribe'] . '>' . ($set['reply_to'] || $set['from_email'] ? ', <mailto:' . ($set['reply_to'] ?: $set['from_email']) . '?subject=unsubscribe>' : ''),
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ];
            if ($set['reply_to'] !== '') {
                $headers['Reply-To'] = $set['reply_to'];
            }
            $r = $send((string) $lead['email'], (string) $lead['company'], $msg['subject'], $msg['html'], $headers);
            $ok = !empty($r['success']);
            $this->q('INSERT INTO platform_lead_messages(lead_id,step,subject,status,error) VALUES(?,?,?,?,?)', [$lead['id'], $step, mb_substr($msg['subject'], 0, 255), $ok ? 'sent' : 'failed', $ok ? null : mb_substr((string) ($r['error'] ?? ''), 0, 500)]);
            if ($ok) {
                $res['sent']++;
                $next = $step < 3 ? date('Y-m-d H:i:s', time() + 86400 * max(1, $gaps[$step - 1] ?? 3)) : null;
                $this->q('UPDATE platform_leads SET step=?,status=?,last_sent_at=NOW(),next_send_at=? WHERE id=?', [$step, $step < 3 ? 'contacted' : 'finished', $next, $lead['id']]);
                if ($set['first_send_date'] === '') {
                    $this->settings->set('outreach_first_send_date', date('Y-m-d'));
                    $set['first_send_date'] = date('Y-m-d');
                }
            } else {
                $res['failed']++;
                // السيرفر رفض الإيميل نفسه (مش موجود): مانبعتلوش تاني
                if (preg_match('/\b(550|551|553|554)\b|user unknown|does not exist|no such user|mailbox unavailable|invalid recipient/i', (string) ($r['error'] ?? ''))) {
                    $this->q("UPDATE platform_leads SET status='bounced' WHERE id=?", [$lead['id']]);
                }
            }
        }
        return $res;
    }

    private static function domainAcceptsMail(string $email): bool
    {
        $host = substr(strrchr($email, '@') ?: '', 1);
        if ($host === '' || !function_exists('checkdnsrr')) {
            return $host !== '';
        }
        static $cache = [];
        return $cache[$host] ??= (checkdnsrr($host, 'MX') || checkdnsrr($host, 'A'));
    }

    /** لو أكتر من 8% من رسايل آخر أسبوع رجعت: نوقف قبل ما الدومين يتحرق */
    private function tooManyBounces(): bool
    {
        $sent = (int) $this->q("SELECT COUNT(*) FROM platform_lead_messages WHERE status='sent' AND sent_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
        $bounced = (int) $this->q("SELECT COUNT(*) FROM platform_leads WHERE status='bounced' AND updated_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
        return $sent >= 30 && $bounced / max(1, $sent) > 0.08;
    }

    /** اللي سجّل في المنصة (بنفس الإيميل أو نفس دومين الموقع) بتقف رسايله */
    public function markSignedUp(): int
    {
        try {
            $n = $this->q("UPDATE platform_leads l JOIN users u ON LOWER(u.email)=LOWER(l.email) SET l.status='signed_up',l.next_send_at=NULL WHERE l.status NOT IN ('signed_up','unsubscribed')")->rowCount();
            $n += $this->q("UPDATE platform_leads l JOIN websites w ON l.domain IS NOT NULL AND LOWER(REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(w.main_url,'://',-1),'/',1),'www.',''))=l.domain SET l.status='signed_up',l.next_send_at=NULL WHERE l.status NOT IN ('signed_up','unsubscribed')")->rowCount();
            return $n;
        } catch (Throwable $e) {
            return 0;
        }
    }

    // ------------------------------------------------------------------ التتبع العام

    public function leadByToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        return $this->q('SELECT * FROM platform_leads WHERE token=?', [$token])->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function unsubscribe(string $token): bool
    {
        $l = $this->leadByToken($token);
        if (!$l) {
            return false;
        }
        $this->q("UPDATE platform_leads SET status='unsubscribed',next_send_at=NULL WHERE id=?", [$l['id']]);
        if ($l['domain']) {
            $this->q("UPDATE platform_leads SET status='unsubscribed',next_send_at=NULL WHERE domain=? AND status IN ('new','no_email','contacted','queued')", [$l['domain']]);
        }
        return true;
    }

    public function track(string $token, int $step, string $kind): ?array
    {
        $l = $this->leadByToken($token);
        if (!$l) {
            return null;
        }
        $col = $kind === 'click' ? 'clicked_at' : 'opened_at';
        $this->q("UPDATE platform_leads SET $col=COALESCE($col,NOW())" . ($kind === 'click' && in_array($l['status'], ['contacted', 'finished'], true) ? ",status='interested'" : '') . ' WHERE id=?', [$l['id']]);
        $this->q("UPDATE platform_lead_messages SET $col=COALESCE($col,NOW()) WHERE lead_id=? AND step=?", [$l['id'], $step]);
        return $l;
    }

    public function signupLinkFor(array $lead, int $step): string
    {
        $set = $this->getSettings();
        return self::withParams((string) $set['signup_url'], ['utm_source' => 'outreach', 'utm_medium' => 'email', 'utm_campaign' => 'step' . $step, 'ref' => $lead['token']]);
    }

    // ------------------------------------------------------------------ لوحة الأدمن

    public function stats(): array
    {
        $set = $this->getSettings();
        $by = $this->q('SELECT status,COUNT(*) n FROM platform_leads GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
        $msgs = $this->q("SELECT COUNT(*) sent,SUM(opened_at IS NOT NULL) opened,SUM(clicked_at IS NOT NULL) clicked FROM platform_lead_messages WHERE status='sent'")->fetch(PDO::FETCH_ASSOC);
        $countries = $this->q('SELECT COALESCE(country,\'?\') c,COUNT(*) n FROM platform_leads GROUP BY c ORDER BY n DESC LIMIT 8')->fetchAll(PDO::FETCH_KEY_PAIR);
        $pending = (int) $this->q("SELECT COUNT(*) FROM platform_leads WHERE email IS NOT NULL AND ((status='new' AND step=0) OR (status='contacted' AND step BETWEEN 1 AND 2))")->fetchColumn();
        return [
            'total' => (int) array_sum($by),
            'by_status' => array_map('intval', $by),
            'messages' => ['sent' => (int) $msgs['sent'], 'opened' => (int) $msgs['opened'], 'clicked' => (int) $msgs['clicked']],
            'today' => ['sent' => $this->sentToday($set), 'limit' => $this->todayLimit($set)],
            'pending' => $pending,
            'countries' => $countries,
            'in_window' => $this->inWindow($set),
            'paused_reason' => $this->settings->get('outreach_paused_reason', ''),
            'last_run' => $this->settings->get('outreach_last_run', ''),
        ];
    }

    public function leads(array $f): array
    {
        $where = ['1=1'];
        $args = [];
        if (!empty($f['status']) && in_array($f['status'], self::STATUSES, true)) {
            $where[] = 'status=?';
            $args[] = $f['status'];
        }
        if (!empty($f['country']) && preg_match('/^[A-Z]{2}$/', (string) $f['country'])) {
            $where[] = 'country=?';
            $args[] = $f['country'];
        }
        if (!empty($f['q'])) {
            $where[] = '(company LIKE ? OR email LIKE ? OR city LIKE ? OR domain LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr((string) $f['q'], 0, 80)) . '%';
            array_push($args, $like, $like, $like, $like);
        }
        $page = max(1, (int) ($f['page'] ?? 1));
        $sql = 'SELECT id,company,email,phone,website,city,country,category,rating,reviews,language,status,step,next_send_at,last_sent_at,opened_at,clicked_at,maps_url,notes FROM platform_leads WHERE ' . implode(' AND ', $where);
        $total = (int) $this->q('SELECT COUNT(*) FROM platform_leads WHERE ' . implode(' AND ', $where), $args)->fetchColumn();
        $rows = $this->q($sql . ' ORDER BY updated_at DESC,id DESC LIMIT 50 OFFSET ' . (($page - 1) * 50), $args)->fetchAll(PDO::FETCH_ASSOC);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => (int) ceil($total / 50)];
    }

    public function setLeadStatus(int $id, string $status, ?string $note = null): void
    {
        if (!in_array($status, ['new', 'replied', 'interested', 'signed_up', 'unsubscribed', 'bounced', 'paused', 'contacted'], true)) {
            throw new InvalidArgumentException('حالة غير معروفة');
        }
        $l = $this->q('SELECT * FROM platform_leads WHERE id=?', [$id])->fetch(PDO::FETCH_ASSOC);
        if (!$l) {
            throw new InvalidArgumentException('الشركة مش موجودة');
        }
        if ($status === 'contacted' && (int) $l['step'] === 0) {
            $status = 'new'; // استئناف قبل أول رسالة
        }
        $this->q('UPDATE platform_leads SET status=?,next_send_at=IF(?=\'contacted\',COALESCE(next_send_at,NOW()),next_send_at),notes=COALESCE(?,notes) WHERE id=?', [$status, $status, $note, $id]);
    }

    public function deleteLead(int $id): void
    {
        $this->q('DELETE FROM platform_lead_messages WHERE lead_id=?', [$id]);
        $this->q('DELETE FROM platform_leads WHERE id=?', [$id]);
    }

    public function sampleLead(): array
    {
        $l = $this->q("SELECT * FROM platform_leads WHERE email IS NOT NULL ORDER BY (rating IS NULL),reviews DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $l ?: ['id' => 0, 'company' => 'Nile Dream Tours', 'email' => null, 'city' => 'Luxor', 'country' => 'EG', 'category' => 'Tour agency', 'rating' => 4.8, 'reviews' => 214, 'domain' => 'niledreamtours.com', 'language' => 'ar', 'token' => str_repeat('0', 32)];
    }

    /** @return array{body:string,url:string}|null */
    public static function httpFetch(string $url, int $maxBytes): ?array
    {
        $r = SafeHttp::exec($url, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TourfectoBot/1.0; +https://tourfecto.pro)',
        ], 5);
        if (!is_string($r['response']) || $r['code'] < 200 || $r['code'] >= 300 || $r['response'] === '' || strlen($r['response']) > $maxBytes) {
            return null;
        }
        return ['body' => $r['response'], 'url' => (string) $r['url']];
    }
}
