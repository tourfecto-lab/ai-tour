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
    return @file_get_contents('http://127.0.0.1:' . $port . $path);
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

file_put_contents($cache, json_encode(['t' => time(), 'f' => 0, 'rules' => []]));
$plain = (string) render($connector, '/', $page);
check(strpos($plain, '<title>Old</title>') !== false && strpos($plain, 'content="old"') !== false, 'no rules: page unchanged');

proc_terminate($server);
@unlink($cache); @unlink($connector); @unlink($dir . '/page.html'); @unlink($dir . '/router.php'); @rmdir($dir);
echo "\n" . ($n - $fails) . "/$n passed\n";
exit($fails ? 1 : 0);
