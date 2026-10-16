<?php
/**
 * تركيب ملف الربط تلقائيًا عن طريق FTP: تعديل index.php آمن، والرجوع
 * للأصل لو الموقع وقع أو الربط مااشتغلش.
 * php tests/Unit/PhpConnectorInstallerTest.php
 */
require_once __DIR__ . '/../../app/Services/AutoFix/PhpConnectorInstaller.php';
$fails = 0; $n = 0;
function check($c, $m) { global $fails, $n; $n++; echo ($c ? '  ok  ' : '  FAIL ') . $m . "\n"; if (!$c) $fails++; }

class FakeFtp {
    public $files; public $err;
    function __construct(array $files, ?string $err = null) { $this->files = $files; $this->err = $err; }
    function connect($h, $p, $u, $pw) { return $this->err; }
    function exists($p) { return isset($this->files[$p]); }
    function get($p) { return $this->files[$p] ?? null; }
    function put($p, $d) { $this->files[$p] = $d; return true; }
    function close() {}
}
$L = PhpConnectorInstaller::LINE;

echo "injectRequire\n";
check(PhpConnectorInstaller::injectRequire("<?php\necho 1;") === "<?php $L\necho 1;", 'plain php file');
check(strpos(PhpConnectorInstaller::injectRequire("<?php declare(strict_types=1);\necho 1;"), "declare(strict_types=1);\n$L") !== false, 'after declare()');
check(strpos(PhpConnectorInstaller::injectRequire("<?php\nnamespace App;\nfoo();"), "namespace App;\n$L") !== false, 'after namespace');
check(PhpConnectorInstaller::injectRequire("<?php namespace App { }") === null, 'braced namespace refused');
check(PhpConnectorInstaller::injectRequire("<!doctype html><p>x</p>") === "<?php $L ?>\n<!doctype html><p>x</p>", 'html file');
check(PhpConnectorInstaller::injectRequire("<? echo 1;") === null, 'short tags refused');
$already = "<?php $L\necho 1;";
check(PhpConnectorInstaller::injectRequire($already) === $already, 'already installed: unchanged');
$bom = PhpConnectorInstaller::injectRequire("\xEF\xBB\xBF<?php\necho 1;");
check(strncmp($bom, "\xEF\xBB\xBF<?php $L", 3 + 6 + strlen($L)) === 0, 'BOM kept first');
// الناتج لازم يبقى PHP سليم
$tmp = tempnam(sys_get_temp_dir(), 'tf'); file_put_contents($tmp, PhpConnectorInstaller::injectRequire("<?php declare(strict_types=1);\necho 1;"));
exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $o, $rc); check($rc === 0, 'patched file is valid PHP'); unlink($tmp);

echo "install\n";
$okHttp = function ($url) { return strpos($url, 'tf_check') ? ['status' => 200, 'body' => 'Tourfecto Connect v4: the file is installed'] : ['status' => 200, 'body' => '<html>']; };
$ftp = new FakeFtp(['public_html/index.php' => "<?php\nrun();"]);
$r = (new PhpConnectorInstaller($ftp, $okHttp))->install(['host' => 'h', 'username' => 'u', 'password' => 'p'], 'https://example.com', 'CONNECTOR');
check($r['ok'] && $r['root'] === 'public_html', 'installed in public_html (' . $r['message'] . ')');
check(($ftp->files['public_html/tourfecto-connect.php'] ?? '') === 'CONNECTOR', 'connector uploaded');
check(strpos($ftp->files['public_html/index.php'], $L) !== false, 'index.php patched');
check(($ftp->files['public_html/index.php.tourfecto-backup'] ?? '') === "<?php\nrun();", 'backup saved');

$brokenHttp = function ($url) { static $calls = 0; $calls++; return $calls === 1 ? ['status' => 200, 'body' => 'x'] : ['status' => 500, 'body' => 'Fatal']; };
$ftp = new FakeFtp(['index.php' => "<?php\nrun();"]);
$inst = new PhpConnectorInstaller($ftp, $brokenHttp); $inst->waits = [0]; $r = $inst->install(['host' => 'h', 'username' => 'u', 'password' => 'p'], 'https://example.com', 'C');
check(!$r['ok'] && $ftp->files['index.php'] === "<?php\nrun();", 'site broke -> index.php restored');

$noMarker = function ($url) { return ['status' => 200, 'body' => '<html>home</html>']; };
$ftp = new FakeFtp(['index.php' => "<?php\nrun();"]);
$inst = new PhpConnectorInstaller($ftp, $noMarker); $inst->waits = [0, 0]; $r = $inst->install(['host' => 'h', 'username' => 'u', 'password' => 'p'], 'https://example.com', 'C');
check(!$r['ok'] && $ftp->files['index.php'] === "<?php\nrun();", 'connector not active -> restored');

$r = (new PhpConnectorInstaller(new FakeFtp([], 'اسم المستخدم أو كلمة السر بتاعة FTP غلط.'), $okHttp))->install(['host' => 'h', 'username' => 'u', 'password' => 'x'], 'https://example.com', 'C');
check(!$r['ok'] && strpos($r['message'], 'غلط') !== false, 'bad login message');
$r = (new PhpConnectorInstaller(new FakeFtp(['other.txt' => '']), $okHttp))->install(['host' => 'h', 'username' => 'u', 'password' => 'p'], 'https://example.com', 'C');
check(!$r['ok'] && strpos($r['message'], 'index.php') !== false, 'no index.php found message');

check(!PhpConnectorInstaller::publicHost('127.0.0.1') && !PhpConnectorInstaller::publicHost('10.0.0.5'), 'internal hosts refused');

echo "\n" . ($n - $fails) . "/$n passed\n";
exit($fails ? 1 : 0);
