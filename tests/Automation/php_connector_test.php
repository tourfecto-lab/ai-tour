<?php
/**
 * ملف ربط مواقع PHP (tourfecto-connect.php): بيطبّق القواعد على <head>
 * صح، وبيسيب الموقع زي ما هو لو مفيش قواعد.
 * php tests/Automation/php_connector_test.php
 */
require_once __DIR__ . '/../../app/Services/AutoFix/EdgeRules.php';
$fails = 0; $n = 0;
function check($c, $m) { global $fails, $n; $n++; echo ($c ? '  ok  ' : '  FAIL ') . $m . "\n"; if (!$c) $fails++; }

$dir = sys_get_temp_dir() . '/tf-connector-test-' . getmypid();
@mkdir($dir);
$connector = $dir . '/tourfecto-connect.php';
// endpoint مقفول: لو حاول يتصل هيفشل فورًا ويستخدم الكاش
file_put_contents($connector, EdgeRules::phpConnector(32, 'http://127.0.0.1:9'));
exec('php -l ' . escapeshellarg($connector), $o, $lint);
check($lint === 0, 'generated connector is valid PHP');

$token = EdgeRules::token(32);
$cache = sys_get_temp_dir() . '/tourfecto-edge-' . md5($token) . '.json';
$page = '<!doctype html><html lang="ar"><head><title>Old</title><meta name="description" content="old"><script>var s="</head>";</script></head><body>x</body></html>';

// سيرفر PHP محلي (الملف بيتجاهل الـ CLI عمدًا عشان مايأثرش على الكرونات)
file_put_contents($dir . '/page.html', $page);
file_put_contents($dir . '/router.php', '<?php require __DIR__ . "/tourfecto-connect.php"; echo file_get_contents(__DIR__ . "/page.html");');
$port = 18900 + (getmypid() % 50);
$server = proc_open(['php', '-S', '127.0.0.1:' . $port, $dir . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(400000);
function render($connector, $path, $page) {
    global $port;
    $body=@file_get_contents('http://127.0.0.1:' . $port . $path, false, stream_context_create(['http'=>['ignore_errors'=>true]]));
    $GLOBALS['lastConnectorStatus']=$http_response_header[0]??'';
    return $body;
}

file_put_contents($cache, json_encode(['t' => time(), 'f' => 0, 'rules' => [
    'title' => 'New Title For Tours', 'description' => 'A "great" description of Egypt tours.', 'lang' => 'en',
    'jsonld' => '{"@type":"TravelAgency","name":"A </script> B"}',
    'page_rules' => ['/tours' => ['title' => 'Tours Page Title']],
]]));
$home = (string) render($connector, '/', $page);
check(strpos($home, '<meta name="tourfecto-connector" content="4">') !== false, 'connector marker present');
check(strpos($home, '<title>New Title For Tours</title>') !== false && strpos($home, '<title>Old</title>') === false, 'homepage title replaced');
check(strpos($home, 'content="A &quot;great&quot; description of Egypt tours."') !== false && strpos($home, 'content="old"') === false, 'description replaced and escaped');
check(strpos($home, 'var s="</head>";</script>') !== false && substr_count($home, '</head>') === 2, 'script containing </head> untouched');
check(strpos($home, '<html lang="en">') !== false, 'html lang applied');
check(strpos($home, 'A <\/script> B') !== false, 'JSON-LD cannot close the script tag');
$tours = (string) render($connector, '/tours', $page);
check(strpos($tours, '<title>Tours Page Title</title>') !== false, 'page rule applied on its path');
$other = (string) render($connector, '/contact', $page);
check(strpos($other, '<title>Old</title>') !== false, 'homepage values not applied to other pages');

// مقالات منشورة من خلال ملف الربط
file_put_contents($cache, json_encode(['t' => time(), 'f' => 0, 'rules' => ['articles' => [
    'discover-egypt' => ['title' => 'Discover Egypt', 'description' => 'Guide to Egypt tours.', 'published_at' => '2026-10-15',
        'image' => 'https://example.com/img/luxor.jpg', 'image_alt' => 'Luxor "temple"',
        'body' => "Egypt is wonderful.\n\n**Luxor Tours**: temples <script>alert(1)</script> and balloons.\n\n- Valley of the Kings\n- Karnak"],
]]]));
$art = (string) render($connector, '/guides/discover-egypt', $page);
check(strpos($art, '<h1>Discover Egypt</h1>') !== false, 'article served on /guides/{slug}');
check(strpos($art, '<strong>Luxor Tours</strong>') !== false && strpos($art, '<li>Valley of the Kings</li>') !== false, 'bold and list formatting');
check(strpos($art, '<script>alert(1)</script>') === false && strpos($art, '&lt;script&gt;') !== false, 'article text is escaped');
check(strpos($art, 'rel="canonical" href="http://127.0.0.1:' . $port . '/guides/discover-egypt"') !== false, 'article canonical');
check(strpos($art, '"@type":"Article"') !== false && strpos($art, 'tourfecto-connector') !== false, 'Article schema and connector marker');
check(strpos($art, '<img src="https://example.com/img/luxor.jpg" alt="Luxor &quot;temple&quot;"') !== false, 'featured image with escaped alt');
check(strpos($art, '<meta property="og:image" content="https://example.com/img/luxor.jpg">') !== false && strpos($art, '"image":"https://example.com/img/luxor.jpg"') !== false, 'og:image and schema image');
$fallback = (string) render($connector, '/?tf_article=discover-egypt', $page);
check(strpos($fallback, '<h1>Discover Egypt</h1>') !== false, 'fallback ?tf_article= works');
check(str_contains($fallback,'rel="canonical" href="http://127.0.0.1:'.$port.'/?tf_article=discover-egypt"'),'fallback article canonical uses its accessible URL');
$index = (string) render($connector, '/guides', $page);
check(strpos($index, 'href="/guides/discover-egypt"') !== false, 'guides index lists the article');
$missing = (string) render($connector, '/guides/unknown', $page);
check(str_contains($missing,'Guide not found.') && !str_contains($missing,'<title>Old</title>'), 'unknown article is explicit, never a soft homepage success');
check(str_contains($GLOBALS['lastConnectorStatus'],'404'),'unknown article returns an actual HTTP 404');

// Phase 4 uses the customer's explicitly configured local header/footer.
file_put_contents($dir.'/header.php','<!doctype html><html><head><title>Site theme</title></head><body><header>Customer navigation</header>');
file_put_contents($dir.'/footer.php','<footer>Customer footer</footer></body></html>');
file_put_contents($dir.'/router.php','<?php $_SERVER["DOCUMENT_ROOT"]=__DIR__; define("TF_EDGE_ARTICLE_HEADER",__DIR__."/header.php"); define("TF_EDGE_ARTICLE_FOOTER",__DIR__."/footer.php"); require __DIR__."/tourfecto-connect.php"; echo file_get_contents(__DIR__."/page.html");');
$themed=(string)render($connector,'/guides/discover-egypt',$page);
check(str_contains($themed,'Customer navigation') && str_contains($themed,'Customer footer') && str_contains($themed,'<h1>Discover Egypt</h1>'),'guide uses configured site header/footer and keeps article body');
check(str_contains($themed,'<title>Discover Egypt</title>') && !str_contains($themed,'<title>Site theme</title>'),'themed guide retains its own SEO title');
$sitemap=(string)render($connector,'/tourfecto-guides-sitemap.xml',$page);
check(str_contains($sitemap,'<urlset') && str_contains($sitemap,'/guides/discover-egypt</loc>'),'published guides appear in the connector sitemap');
file_put_contents($dir.'/page.html','<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://example.com/existing.xml</loc></sitemap></sitemapindex>');
$siteMapIndex=(string)render($connector,'/sitemap.xml',$page);
check(str_contains($siteMapIndex,'https://example.com/existing.xml') && str_contains($siteMapIndex,'/tourfecto-guides-sitemap.xml'),'existing sitemap index retains entries and adds guide sitemap');
file_put_contents($dir.'/page.html','<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/existing</loc></url></urlset>');
$urlset=(string)render($connector,'/sitemap.xml',$page);
check(str_contains($urlset,'https://example.com/existing') && str_contains($urlset,'/guides/discover-egypt</loc>'),'flat site sitemap retains existing pages and adds published guides');
file_put_contents($dir.'/page.html',$page);
@unlink($dir.'/header.php');@unlink($dir.'/footer.php');

file_put_contents($cache, json_encode(['t' => time(), 'f' => 0, 'rules' => []]));
$plain = (string) render($connector, '/', $page);
check(strpos($plain, '<title>Old</title>') !== false && strpos($plain, 'content="old"') !== false, 'no rules: page unchanged');

$unavailable=(string)render($connector,'/?tf_article=discover-egypt',$page);
check(str_contains($GLOBALS['lastConnectorStatus'],'503') && str_contains($unavailable,'temporarily unavailable') && !str_contains($unavailable,'<title>Old</title>'),'missing article rules return 503 instead of the homepage');

proc_terminate($server);
@unlink($cache); @unlink($connector); @unlink($dir . '/page.html'); @unlink($dir . '/router.php'); @rmdir($dir);
echo "\n" . ($n - $fails) . "/$n passed\n";
exit($fails ? 1 : 0);
