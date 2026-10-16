<?php

/**
 * Tourfecto - سحب صور العميل من موقعه
 *
 * بيفتح الصفحة الرئيسية + صفحات الرحلات/الفنادق/المعرض، ويجمع الصور الكبيرة
 * (من غير اللوجو والأيقونات والأعلام)، وينزّلها في مكتبة العميل كصور جاهزة
 * للنشر. الصورة اللي اتسحبت قبل كده مش بتتكرر.
 */
class SiteImageImporter
{
    private const UA = 'Mozilla/5.0 (compatible; TourfectoBot/1.0; +https://tourfecto.pro)';
    private const PAGE_HINTS = '/tour|trip|package|excursion|safari|cruise|hotel|gallery|galler|destination|activit|program|offer|رحل|برنامج|عرض|فندق|معرض|جولات|سفاري/iu';
    private const SKIP_IMG = '/logo|icon|favicon|sprite|avatar|flag|badge|payment|visa|master|paypal|whatsapp|facebook|instagram|twitter|tiktok|youtube|tripadvisor|google|placeholder|loading|spinner|blank|pixel|arrow|star|rating|emoji|banner-ad|\.svg|\.gif/i';
    private const MIN_W = 500;
    private const MIN_H = 350;

    private PDO $pdo;
    private string $publicRoot;
    /** @var callable(string,int):?array{body:string,url:string} */
    private $fetch;
    public int $budgetSeconds = 38;

    public function __construct(?PDO $pdo = null, ?string $publicRoot = null, ?callable $fetch = null)
    {
        $this->pdo = $pdo ?? Database::getInstance()->getConnection();
        $this->publicRoot = rtrim($publicRoot ?? ROOT_PATH . '/public_html', '/');
        $this->fetch = $fetch ?? [self::class, 'httpFetch'];
    }

    /** @return array{added:int[], found:int, message:string} */
    public function import(int $uid, int $wid, int $max = 20): array
    {
        $start = microtime(true);
        $s = $this->pdo->prepare('SELECT main_url,domain FROM websites WHERE id=? AND user_id=?');
        $s->execute([$wid, $uid]);
        $site = $s->fetch(PDO::FETCH_ASSOC);
        if (!$site) {
            throw new InvalidArgumentException('Website unavailable');
        }
        $home = trim((string) ($site['main_url'] ?: 'https://' . $site['domain']));
        if (!preg_match('#^https?://#i', $home)) {
            $home = 'https://' . $home;
        }
        if ((string) parse_url($home, PHP_URL_PATH) === '') {
            $home .= '/';
        }
        $page = ($this->fetch)($home, 2000000);
        if (!$page) {
            return ['added' => [], 'found' => 0, 'message' => 'مقدرناش نفتح موقعك دلوقتي. اتأكد إن الموقع شغال وجرّب تاني.'];
        }
        $host = strtolower(preg_replace('/^www\./i', '', (string) parse_url($page['url'], PHP_URL_HOST)));

        // الصفحات: الرئيسية + لحد 6 صفحات رحلات/فنادق/معرض من نفس الموقع
        $pages = [$page];
        foreach (array_slice($this->tourLinks($page['body'], $page['url'], $host), 0, 6) as $link) {
            if (microtime(true) - $start > $this->budgetSeconds * 0.4) {
                break;
            }
            $p = ($this->fetch)($link, 2000000);
            if ($p) {
                $pages[] = $p;
            }
        }
        $candidates = [];
        foreach ($pages as $i => $p) {
            foreach ($this->images($p['body'], $p['url']) as $url => $alt) {
                // صور صفحات الرحلات أهم من صور الرئيسية
                $candidates[$url] ??= ['alt' => $alt, 'page' => $p['url'], 'rank' => $i === 0 ? 1 : 0];
            }
        }
        uasort($candidates, static fn ($a, $b) => $a['rank'] <=> $b['rank']);

        $dir = $this->publicRoot . '/uploads/social/' . $uid;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new RuntimeException('Upload folder not writable');
        }
        $added = [];
        $seen = [];
        foreach ($candidates as $url => $c) {
            if (count($added) >= $max || microtime(true) - $start > $this->budgetSeconds) {
                break;
            }
            if ($this->alreadyImported($uid, $url)) {
                continue;
            }
            $img = ($this->fetch)($url, 8000000);
            if (!$img) {
                continue;
            }
            $hash = md5($img['body']);
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $jpeg = self::prepare($img['body']);
            if ($jpeg === null) {
                continue;
            }
            $path = '/uploads/social/' . $uid . '/site_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.jpg';
            file_put_contents($this->publicRoot . $path, $jpeg[0]);
            $label = $c['alt'] !== '' ? $c['alt'] : urldecode(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME));
            $meta = ['source' => 'site_import', 'src' => $url, 'page' => $c['page']];
            $this->pdo->prepare("INSERT INTO media_items(user_id,type,prompt,file_path,thumbnail_path,width,height,status,generation_meta) VALUES(?,'social_image',?,?,?,?,?,'completed',?)")
                ->execute([$uid, mb_substr('من موقعك: ' . $label, 0, 250), $path, $path, $jpeg[1], $jpeg[2], json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            $added[] = (int) $this->pdo->lastInsertId();
        }
        $msg = $added
            ? 'سحبنا ' . count($added) . ' صورة من موقعك.'
            : ($candidates ? 'كل صور موقعك المناسبة موجودة في مكتبتك بالفعل، أو صغيرة أوي للنشر.' : 'مالقيناش صور كبيرة كفاية على موقعك. ارفع صورك من جهازك.');
        return ['added' => $added, 'found' => count($candidates), 'message' => $msg];
    }

    private function alreadyImported(int $uid, string $url): bool
    {
        $s = $this->pdo->prepare("SELECT 1 FROM media_items WHERE user_id=? AND deleted_at IS NULL AND generation_meta LIKE ? LIMIT 1");
        $s->execute([$uid, '%' . str_replace(['%', '_'], ['\%', '\_'], json_encode($url, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '%']);
        return (bool) $s->fetchColumn();
    }

    /** روابط صفحات الرحلات من نفس الموقع */
    public function tourLinks(string $html, string $base, string $host): array
    {
        $out = [];
        if (!preg_match_all('/<a\s[^>]*href=["\']([^"\'#]+)["\'][^>]*>(.*?)<\/a>/is', $html, $m, PREG_SET_ORDER)) {
            return [];
        }
        foreach ($m as $a) {
            $url = self::absUrl($base, html_entity_decode($a[1]));
            if (!$url || preg_match('/\.(jpe?g|png|webp|gif|pdf|zip)(\?|$)/i', $url)) {
                continue;
            }
            $h = strtolower(preg_replace('/^www\./i', '', (string) parse_url($url, PHP_URL_HOST)));
            if ($h !== $host) {
                continue;
            }
            $text = $url . ' ' . strip_tags($a[2]);
            if (preg_match(self::PAGE_HINTS, $text) && rtrim($url, '/') !== rtrim($base, '/')) {
                $out[rtrim($url, '/')] = true;
            }
        }
        return array_keys($out);
    }

    /** @return array<string,string> رابط الصورة => وصفها */
    public function images(string $html, string $base): array
    {
        $out = [];
        $add = static function (?string $url, string $alt) use (&$out) {
            if ($url && !preg_match(self::SKIP_IMG, $url) && !preg_match('/logo|icon/i', $alt) && !isset($out[$url])) {
                $out[$url] = mb_substr(trim(preg_replace('/\s+/u', ' ', $alt)), 0, 120);
            }
        };
        if (preg_match_all('/<meta[^>]+(?:property|name)=["\'](?:og:image|twitter:image)["\'][^>]*>/i', $html, $m)) {
            foreach ($m[0] as $tag) {
                if (preg_match('/content=["\']([^"\']+)["\']/i', $tag, $c)) {
                    $add(self::absUrl($base, html_entity_decode($c[1])), '');
                }
            }
        }
        if (preg_match_all('/<img\s[^>]*>/i', $html, $m)) {
            foreach ($m[0] as $tag) {
                $alt = preg_match('/\balt=["\']([^"\']*)["\']/i', $tag, $a) ? html_entity_decode($a[1]) : '';
                $src = null;
                // أكبر نسخة في srcset، وبعدين صور التحميل المتأخر، وبعدين src
                foreach (['data-srcset', 'srcset'] as $attr) {
                    if (!$src && preg_match('/\b' . $attr . '=["\']([^"\']+)["\']/i', $tag, $s)) {
                        $best = 0;
                        foreach (explode(',', html_entity_decode($s[1])) as $part) {
                            $bits = preg_split('/\s+/', trim($part));
                            $w = isset($bits[1]) ? (int) $bits[1] : 1;
                            if ($bits[0] !== '' && $w >= $best) {
                                $best = $w;
                                $src = $bits[0];
                            }
                        }
                    }
                }
                foreach (['data-lazy-src', 'data-src', 'data-original', 'src'] as $attr) {
                    if (!$src && preg_match('/\b' . $attr . '=["\']([^"\']+)["\']/i', $tag, $s) && stripos($s[1], 'data:') !== 0) {
                        $src = html_entity_decode($s[1]);
                    }
                }
                if (preg_match('/\b(?:width|height)=["\']?(\d+)/i', $tag, $d) && (int) $d[1] > 0 && (int) $d[1] < 120) {
                    continue; // أيقونة صغيرة
                }
                $add($src ? self::absUrl($base, $src) : null, $alt);
            }
        }
        if (preg_match_all('/background(?:-image)?\s*:\s*url\(\s*["\']?([^"\')]+)["\']?\s*\)/i', $html, $m)) {
            foreach ($m[1] as $u) {
                $add(self::absUrl($base, html_entity_decode($u)), '');
            }
        }
        return $out;
    }

    /**
     * صورة كبيرة كفاية => JPG بمقاس مناسب للنشر (قص بسيط من النص لو النسبة برّه حدود إنستجرام)
     * @return array{0:string,1:int,2:int}|null
     */
    public static function prepare(string $binary): ?array
    {
        $im = @imagecreatefromstring($binary);
        if (!$im) {
            return null;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w < self::MIN_W || $h < self::MIN_H && $w < $h * 2) {
            imagedestroy($im);
            return null;
        }
        $ratio = $w / $h;
        $cw = $w;
        $ch = $h;
        if ($ratio > 1.91) {
            $cw = (int) round($h * 1.91);
        } elseif ($ratio < 0.8) {
            $ch = (int) round($w / 0.8);
        }
        if ($ch < self::MIN_H * 0.8) {
            imagedestroy($im);
            return null; // شريط رفيع (بانر) مش صورة
        }
        $scale = min(1, 1440 / max($cw, $ch));
        $nw = (int) round($cw * $scale);
        $nh = (int) round($ch * $scale);
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $im, 0, 0, (int) (($w - $cw) / 2), (int) (($h - $ch) / 2), $nw, $nh, $cw, $ch);
        imagedestroy($im);
        // صورة لون واحد تقريبًا (خلفية) مش مفيدة
        $small = imagecreatetruecolor(8, 8);
        imagecopyresampled($small, $out, 0, 0, 0, 0, 8, 8, $nw, $nh);
        $vals = [];
        for ($x = 0; $x < 8; $x++) {
            for ($y = 0; $y < 8; $y++) {
                $c = imagecolorat($small, $x, $y);
                $vals[] = (($c >> 16) & 255) + (($c >> 8) & 255) + ($c & 255);
            }
        }
        imagedestroy($small);
        if (max($vals) - min($vals) < 60) {
            imagedestroy($out);
            return null;
        }
        ob_start();
        imagejpeg($out, null, 88);
        imagedestroy($out);
        return [(string) ob_get_clean(), $nw, $nh];
    }

    public static function absUrl(string $base, string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || preg_match('#^(data|javascript|mailto|tel):#i', $href)) {
            return null;
        }
        if (strpos($href, '//') === 0) {
            return (parse_url($base, PHP_URL_SCHEME) ?: 'https') . ':' . $href;
        }
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $p = parse_url($base);
        if (empty($p['host'])) {
            return null;
        }
        $origin = ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if ($href[0] === '/') {
            return $origin . $href;
        }
        $path = $p['path'] ?? '/';
        $dir = substr($path, -1) === '/' ? rtrim($path, '/') : rtrim(dirname($path), '/');
        return $origin . $dir . '/' . $href;
    }

    /** @return array{body:string,url:string}|null */
    public static function httpFetch(string $url, int $maxBytes): ?array
    {
        $r = SafeHttp::exec($url, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_HTTPHEADER => ['Accept-Language: ar,en;q=0.8'],
        ], 5);
        if (!is_string($r['response']) || $r['code'] < 200 || $r['code'] >= 300 || $r['response'] === '' || strlen($r['response']) > $maxBytes) {
            return null;
        }
        return ['body' => $r['response'], 'url' => (string) $r['url']];
    }
}
