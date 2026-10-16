<?php
/**
 * SEO المرحلة 1: Schema السياحة، فلترة الـ Schema في ملف الربط، السرعة الحقيقية (PageSpeed)،
 * فحص الفهرسة (Search Console)، الروابط الداخلية ووصف الصور، وتنبيه وقوع الزيارات.
 * php tests/Automation/seo_phase1_test.php   (من غير قاعدة بيانات)
 */
define('ROOT_PATH', dirname(__DIR__, 2));
if (!class_exists('FixCapability')) { class FixCapability { const DB_FIELD = []; } }
if (!class_exists('FixValueBuilder')) { class FixValueBuilder { static function looksLikePlaceholder($v) { return false; } } }
require_once ROOT_PATH . '/app/Services/AutoFix/EdgeRules.php';
require_once ROOT_PATH . '/app/Services/AutoFix/PageContext.php';
require_once ROOT_PATH . '/app/Services/SeoExpert/SeoSchemaBuilder.php';
require_once ROOT_PATH . '/app/Services/Seo/PageSpeedService.php';
require_once ROOT_PATH . '/app/Services/SearchConsole/GoogleSearchConsoleAPI.php';
require_once ROOT_PATH . '/app/Services/Seo/SeoTrafficAlertService.php';
$fails = 0; $n = 0;
function check($c, $m) { global $fails, $n; $n++; echo ($c ? '  ok  ' : '  FAIL ') . $m . "\n"; if (!$c) $fails++; }

echo "tourism schema\n";
$tour = ['url' => 'https://nile.example/tours/luxor-day-tour', 'page_path' => '/tours/luxor-day-tour', 'h1' => 'Luxor Full Day Tour from Hurghada', 'title' => 'Luxor Full Day Tour | Nile Co', 'description' => 'Visit Karnak, the Valley of the Kings and Hatshepsut temple in one full day with an Egyptologist guide.', 'text' => 'Price from $65 per person.', 'og' => ['og:image' => 'https://nile.example/img/luxor.jpg'], 'schema' => []];
$b = SeoSchemaBuilder::forPage($tour, ['business_name' => 'Nile Co', 'site_url' => 'https://nile.example']);
$trip = $b['graph']['@graph'][0] ?? [];
check($b['types'] === ['TouristTrip', 'BreadcrumbList'], 'trip page gets TouristTrip + Breadcrumb');
check($trip['offers'] === ['@type' => 'Offer', 'price' => 65.0, 'priceCurrency' => 'USD', 'url' => $tour['url'], 'availability' => 'https://schema.org/InStock'], 'price taken exactly as written on the page ($65)');
check($trip['provider']['name'] === 'Nile Co' && $trip['image'] === 'https://nile.example/img/luxor.jpg', 'company and image filled from real page data');
$noPrice = $tour; $noPrice['text'] = 'Book now and enjoy the trip.';
check(!isset(SeoSchemaBuilder::forPage($noPrice, ['business_name' => 'Nile Co', 'site_url' => 'https://nile.example'])['graph']['@graph'][0]['offers']), 'no price on page: no price invented');
$mixed = $tour; $mixed['text'] = '$65 $80 $120 $45 $30 €50';
check(SeoSchemaBuilder::price($mixed) === null, 'many different prices (other trips listed): price skipped');
$range = $tour; $range['text'] = 'Adult $65, child $35';
check(SeoSchemaBuilder::price($range) === ['low' => 35.0, 'high' => 65.0, 'currency' => 'USD', 'count' => 2], 'adult/child prices become a price range');
check(SeoSchemaBuilder::price(['text' => '2,500 جنيه للفرد']) === null || SeoSchemaBuilder::price(['text' => '2500 جنيه للفرد'])['currency'] === 'EGP', 'Egyptian pound price recognised');
$has = $tour; $has['schema'] = [['@context' => 'https://schema.org', '@type' => 'Product', 'name' => 'x'], ['@type' => 'BreadcrumbList']];
check(SeoSchemaBuilder::forPage($has, ['business_name' => 'Nile Co', 'site_url' => 'https://nile.example']) === null, 'page already has Product + Breadcrumb (Yoast/theme): nothing duplicated');
check(!SeoSchemaBuilder::isTourPage(['page_path' => '/about-us', 'h1' => 'About us', 'text' => '']) && !SeoSchemaBuilder::isTourPage(['page_path' => '/tours', 'h1' => 'All tours', 'card_prices' => ['a', 'b', 'c']]) && !SeoSchemaBuilder::isTourPage(['page_path' => '/blog/best-tours', 'h1' => 'Best tours in Egypt']), 'about, listing and blog pages are not treated as trips');
check(SeoSchemaBuilder::forPage(['url' => 'https://nile.example/', 'page_path' => '/', 'h1' => 'Home'], ['business_name' => 'Nile Co', 'site_url' => 'https://nile.example']) === null, 'home page left to the business (Organization) schema');

echo "connector accepts only safe schema\n";
$json = json_encode($b['graph'], JSON_UNESCAPED_SLASHES);
check(is_string(EdgeRules::sanitize('jsonld', $json, 'nile.example')), 'tourism @graph accepted by the connector');
check(EdgeRules::sanitize('jsonld', json_encode(['@context' => 'https://schema.org', '@graph' => [['@type' => 'Recipe', 'name' => 'x']]]), 'nile.example') === null, 'unrelated schema types rejected');
check(is_string(EdgeRules::sanitize('jsonld', json_encode(['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Nile Co']), 'nile.example')), 'old single Organization schema still accepted');
check(EdgeRules::sanitize('related_links', '[{"url":"https://evil.com/x","title":"x"}]', 'nile.example') === null && is_string(EdgeRules::sanitize('related_links', '[{"url":"https://nile.example/tours/aswan","title":"Aswan"}]', 'nile.example')), 'related links only to the same site');

echo "builder sites\n";
$c = ['business_name' => 'Nile Co', 'about_text' => 'Tours in Egypt since 2010', 'contact' => ['phone' => '+20100', 'email' => 'hi@nile.example'], 'tours' => [['name' => 'Luxor Day Tour', 'slug' => 'luxor', 'price' => '$65', 'short_description' => 'Karnak and Valley of the Kings', 'itinerary' => [['day' => 1, 'title' => 'Karnak Temple', 'description' => 'Guided visit']]]]];
$g = SeoSchemaBuilder::forBuilderTour($c['tours'][0], $c, 'https://nile.example', [5, 4, 5]);
check($g['@graph'][0]['aggregateRating']['ratingValue'] == 4.7 && $g['@graph'][0]['aggregateRating']['reviewCount'] === 3, 'real approved reviews become star rating (3+ reviews)');
check(!isset(SeoSchemaBuilder::forBuilderTour($c['tours'][0], $c, 'https://nile.example', [5, 5])['@graph'][0]['aggregateRating']), 'fewer than 3 reviews: no rating');
check($g['@graph'][0]['itinerary']['itemListElement'][0]['item']['name'] === 'Karnak Temple' && $g['@graph'][0]['offers']['price'] == 65.0, 'itinerary and price from the trip data');
$h = SeoSchemaBuilder::forBuilderHome($c, 'https://nile.example');
check($h['@graph'][0]['@type'] === 'TravelAgency' && $h['@graph'][2]['itemListElement'][0]['url'] === 'https://nile.example/tours/luxor', 'home: TravelAgency + list of trips');
check(strpos(SeoSchemaBuilder::scriptTag(['x' => '</script><b>']), '</script><b>') === false, 'schema tag cannot break out of the script');

echo "PageSpeed\n";
$psi = ['lighthouseResult' => ['categories' => ['performance' => ['score' => 0.42]], 'audits' => [
    'largest-contentful-paint' => ['numericValue' => 4800], 'cumulative-layout-shift' => ['numericValue' => 0.21], 'total-blocking-time' => ['numericValue' => 600],
    'render-blocking-resources' => ['score' => 0.2, 'details' => ['type' => 'opportunity', 'overallSavingsMs' => 1200]],
    'modern-image-formats' => ['score' => 0.4, 'details' => ['type' => 'opportunity', 'overallSavingsMs' => 900, 'overallSavingsBytes' => 512000]],
    'unminified-css' => ['score' => 0.95, 'details' => ['type' => 'opportunity', 'overallSavingsMs' => 50]]]],
    'loadingExperience' => ['metrics' => ['LARGEST_CONTENTFUL_PAINT_MS' => ['percentile' => 4100], 'INTERACTION_TO_NEXT_PAINT' => ['percentile' => 350], 'CUMULATIVE_LAYOUT_SHIFT_SCORE' => ['percentile' => 18]], 'overall_category' => 'SLOW']];
$ps = new PageSpeedService(fn ($u) => ['code' => 200, 'body' => json_encode($psi)]);
$r = $ps->run('https://nile.example/');
check($r['ok'] && $r['score'] === 42 && $r['field']['lcp_ms'] === 4100 && $r['field']['inp_ms'] === 350 && abs($r['field']['cls'] - 0.18) < 0.001, 'real Google score + Chrome user data (LCP, INP, CLS)');
check(array_column($r['opportunities'], 'id') === ['render-blocking-resources', 'modern-image-formats'], 'biggest slowdowns ranked; tiny ones ignored');
$t = PageSpeedService::taskText($r, 'ar');
check($t['needs_work'] && str_contains($t['instructions'], 'WebP') && str_contains($t['instructions'], '4.1s'), 'clear Arabic task with numbers and the fix');
check((new PageSpeedService(fn ($u) => ['code' => 429, 'body' => '{}']))->run('https://nile.example/')['error'] === 'quota', 'Google quota reached: reported, no crash');

echo "indexing (Search Console)\n";
$fake = function ($req) {
    if (str_contains($req['url'], 'urlInspection')) {
        $b = json_decode($req['body'], true);
        $res = str_contains($b['inspectionUrl'], 'aswan') ? ['verdict' => 'NEUTRAL', 'coverageState' => 'Crawled - currently not indexed', 'robotsTxtState' => 'ALLOWED', 'indexingState' => 'INDEXING_ALLOWED', 'pageFetchState' => 'SUCCESSFUL', 'lastCrawlTime' => '2026-10-01T10:00:00Z']
            : ['verdict' => 'PASS', 'coverageState' => 'Submitted and indexed', 'robotsTxtState' => 'ALLOWED', 'indexingState' => 'INDEXING_ALLOWED', 'pageFetchState' => 'SUCCESSFUL'];
        return ['http_code' => 200, 'body' => json_encode(['inspectionResult' => ['indexStatusResult' => $res]])];
    }
    if (str_contains($req['url'], '/sitemaps')) return ['http_code' => 200, 'body' => '{}'];
    return ['http_code' => 404, 'body' => '{}'];
};
$api = new GoogleSearchConsoleAPI('tok', $fake);
$i1 = $api->inspectUrl('sc-domain:nile.example', 'https://nile.example/tours/luxor');
$i2 = $api->inspectUrl('sc-domain:nile.example', 'https://nile.example/tours/aswan');
check($i1['indexed'] && !$i2['indexed'] && $i2['reason'] === 'crawled_not_indexed', 'indexed vs "crawled - not indexed" detected with the reason');
check($api->listSitemaps('sc-domain:nile.example') === ['ok' => true, 'sitemaps' => []], 'no sitemap submitted detected');

echo "related trips + image alt\n";
eval(EdgeRules::bodyHelpers());
$html = tf_edge_fill_alt('<img src="/up/luxor-temple-sunset-1024x768.jpg"><img src="/up/IMG_2231.jpg" alt=""><img src="/x.jpg" alt="Kept">', 'Luxor Day Tour');
check(str_contains($html, 'alt="Luxor temple sunset"') && str_contains($html, 'alt="Luxor Day Tour"') && str_contains($html, 'alt="Kept"'), 'missing alt filled from the file name or page title; existing alt kept');
$ctx = ['ok' => true, 'tf_related' => ['https://nile.example/tours/aswan', 'https://nile.example/tours/cairo']];
check(PageContext::verifyValue('related_links', '[{"url":"https://nile.example/tours/aswan","title":"Aswan"}]', $ctx)['state'] === 'verified', 'related links verified on the live page');
check(PageContext::verifyValue('related_links', '[{"url":"https://nile.example/tours/hurghada","title":"H"}]', $ctx)['state'] !== 'verified', 'missing link not reported as done');

echo "traffic drop alert\n";
$gsc = new class {
    public $now = [['page' => 'https://nile.example/tours/luxor', 'clicks' => 20], ['page' => 'https://nile.example/', 'clicks' => 30]];
    public $prev = [['page' => 'https://nile.example/tours/luxor', 'clicks' => 70], ['page' => 'https://nile.example/', 'clicks' => 35]];
    public function getSearchAnalytics($p, $s, $e, $d, $l) { return ['success' => true, 'rows' => $s > gmdate('Y-m-d', time() - 10 * 86400) ? $this->now : $this->prev]; }
};
$alert = (new SeoTrafficAlertService(fn () => [$gsc, 'sc-domain:nile.example']))->checkWebsite(1, 1, false);
check($alert['alert'] && $alert['clicks'] === 50 && $alert['previous'] === 105 && array_key_first($alert['pages']) === 'https://nile.example/tours/luxor', 'clicks fell 52%: alert with the page that lost most');
$gsc->now = [['page' => 'https://nile.example/', 'clicks' => 95]];
check(!(new SeoTrafficAlertService(fn () => [$gsc, 'p']))->checkWebsite(1, 1, false)['alert'], 'small dip: no alert');

echo "\n" . ($n - $fails) . "/$n passed\n";
exit($fails ? 1 : 0);
