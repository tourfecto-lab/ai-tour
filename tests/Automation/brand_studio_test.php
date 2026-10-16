<?php
/**
 * هوية الشركة + تعلّم الصور + تصميم البوستات + خلط الأشكال في الخطة الشهرية.
 * DB_NAME=tourfecto_test DB_USER=tf DB_PASS=tfpass DB_HOST=127.0.0.1 ENCRYPTION_KEY=... php tests/Automation/brand_studio_test.php
 */
require_once __DIR__ . '/../../cron/bootstrap.php';
require_once ROOT_PATH . '/app/Services/SocialMedia/SocialMonthlyPlanService.php';
$fails = 0; $n = 0;
function check($c, $m) { global $fails, $n; $n++; echo ($c ? '  ok  ' : '  FAIL ') . $m . "\n"; if (!$c) $fails++; }

echo "ArabicShaper\n";
check(ArabicShaper::shape('لا') === "\u{FEFB}", 'lam-alef ligature');
check(ArabicShaper::shape('من') === "\u{FEE3}\u{FEE6}", 'initial + final forms');
check(ArabicShaper::shape('دار') === 'دار' && ArabicShaper::shape('باب') === "\u{FE91}\u{FE8E}ب", 'right-joining letters do not join forward');
check(ArabicShaper::line('رحلة 4 أيام') === implode('', array_reverse(mb_str_split(ArabicShaper::shape('أيام')))) . ' 4 ' . implode('', array_reverse(mb_str_split(ArabicShaper::shape('رحلة')))), 'RTL order with number kept');
check(ArabicShaper::line('Book now') === 'Book now', 'English untouched');

echo "formats\n";
$raw = json_encode(['strategy' => 'A good monthly strategy', 'posts' => [
    ['theme' => 'Luxor', 'objective' => 'bookings', 'visual_brief' => 'temple photo', 'captions' => ['facebook' => 'Visit Luxor with us this winter!'], 'format' => 'design', 'asset' => 1, 'headline' => 'رحلة الأقصر', 'subline' => 'احجز دلوقتي'],
    ['theme' => 'Tip', 'objective' => 'trust', 'visual_brief' => 'text poster', 'captions' => ['facebook' => 'Best time to visit Egypt is winter.'], 'format' => 'card', 'headline' => ''],
    ['theme' => 'Old', 'objective' => 'trust', 'visual_brief' => 'beach photo', 'captions' => ['facebook' => 'Old format response without extras']],
]]);
$r = SocialMonthlyPlanService::parseResult($raw, ['facebook'], 3);
check($r['posts'][0]['format'] === 'design' && $r['posts'][0]['asset'] === 1 && $r['posts'][0]['headline'] === 'رحلة الأقصر', 'design post parsed');
check($r['posts'][1]['format'] === 'photo', 'card without headline falls back to photo');
check($r['posts'][2]['format'] === 'photo' && $r['posts'][2]['asset'] === null, 'old responses still work');
check(strpos(SocialMonthlyPlanService::formatRules('photo', true), 'for every post') !== false, 'photo-only rule');
check(strpos(SocialMonthlyPlanService::formatRules('mix', true), 'agency') !== false, 'mix rule');

echo "brand kit + design\n";
$pdo = Database::getInstance()->getConnection();
$email = 'brandtest' . getmypid() . '@example.com';
$pdo->prepare("INSERT INTO users(company_name,email,password) VALUES('Test Co',?,'x')")->execute([$email]);
$uid = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO websites(user_id,main_url,company_name,domain) VALUES(?,'https://luxor-test.com','Luxor Test','luxor-test.com')")->execute([$uid]);
$wid = (int) $pdo->lastInsertId();
$tmp = sys_get_temp_dir() . '/tf-brand-' . getmypid();
@mkdir($tmp . '/uploads/social/' . $uid, 0755, true);
$studio = new SocialBrandStudio($pdo, $tmp);
$kit = $studio->kit($uid, $wid);
check($kit['primary'] === SocialBrandStudio::DEFAULT_PRIMARY && !$kit['has_logo'] && $kit['domain'] === 'luxor-test.com', 'default kit');

// لوجو برتقالي على أزرق
$logo = imagecreatetruecolor(300, 120);
imagefill($logo, 0, 0, imagecolorallocate($logo, 255, 255, 255));
imagefilledrectangle($logo, 0, 0, 180, 120, imagecolorallocate($logo, 20, 60, 140));
imagefilledrectangle($logo, 180, 0, 300, 120, imagecolorallocate($logo, 240, 140, 30));
imagepng($logo, $tmp . '/logo.png');
$kit = $studio->saveKit($uid, $wid, ['tmp_name' => $tmp . '/logo.png', 'error' => UPLOAD_ERR_OK, 'name' => 'logo.png'], '', '');
check($kit['has_logo'] && $kit['id'] > 0, 'logo saved in brand_profiles');
check($kit['primary'] !== SocialBrandStudio::DEFAULT_PRIMARY && $kit['accent'] !== SocialBrandStudio::DEFAULT_ACCENT, 'colors taken from logo (' . $kit['primary'] . ', ' . $kit['accent'] . ')');
$kit = $studio->saveKit($uid, $wid, null, '#112233', 'bad');
check($kit['primary'] === '#112233' && $kit['has_logo'], 'manual color kept logo');

// صورة حقيقية في المكتبة
$photo = imagecreatetruecolor(1200, 800);
imagefill($photo, 0, 0, imagecolorallocate($photo, 200, 160, 90));
$path = '/uploads/social/' . $uid . '/p1.jpg';
imagejpeg($photo, $tmp . $path);
$pdo->prepare("INSERT INTO media_items(user_id,type,prompt,file_path,status) VALUES(?,'social_image','رفع من الجهاز: p1.jpg',?,'completed')")->execute([$uid, $path]);
$mid = (int) $pdo->lastInsertId();

// تعلّم الصورة (رؤية وهمية)
$sent = null;
$v = $studio->learn($uid, $mid, function ($jpeg, $prompt) use (&$sent) { $sent = $jpeg; return ['success' => true, 'data' => '{"description":"Karnak temple at sunset","place":"Luxor","kind":"destination","tags":["temple","sunset"],"has_text":false,"quality":5}']; });
check($v && $v['place'] === 'Luxor' && $sent && @imagecreatefromstring($sent), 'photo learned');
$again = $studio->learn($uid, $mid, function () { throw new Exception('should not call again'); });
check($again['description'] === 'Karnak temple at sunset', 'learned once, cached');
check($studio->learnedSummary($uid)['places'] === ['Luxor'], 'learned summary');

$did = $studio->design($uid, $wid, 'design', 'رحلة الأقصر في 4 أيام', 'احجز دلوقتي', $mid, 1);
$row = $pdo->query('SELECT * FROM media_items WHERE id=' . $did)->fetch(PDO::FETCH_ASSOC);
$meta = json_decode($row['generation_meta'], true);
check($meta['source'] === 'brand_design' && $meta['template'] === 'panel' && $meta['base_media_id'] === $mid, 'design saved with meta');
$img = @imagecreatefromjpeg($tmp . $row['file_path']);
check($img && imagesx($img) === 1080 && imagesy($img) === 1350, 'design is 1080x1350');
$cid = $studio->design($uid, $wid, 'card', 'نصيحة: احجز بدري', '', null, 0);
check(json_decode($pdo->query('SELECT generation_meta FROM media_items WHERE id=' . $cid)->fetchColumn(), true)['format'] === 'card', 'card without photo');

echo "plan mixing\n";
$svc = new SocialMonthlyPlanService();
$m = new ReflectionMethod($svc, 'attachMedia'); $m->setAccessible(true);
$assets = [['id' => 999999, 'video' => true, 'description' => 'video'], ['id' => $mid, 'video' => false, 'description' => 'Karnak']];
$posts = $m->invoke($svc, $uid, $wid, [
    ['format' => 'photo', 'asset' => 1, 'headline' => '', 'subline' => ''],
    ['format' => 'design', 'asset' => 0, 'headline' => 'سفاري الغردقة', 'subline' => 'احجز'],
    ['format' => 'card', 'asset' => null, 'headline' => 'نصيحة اليوم', 'subline' => ''],
], $assets, $studio);
check($posts[0]['media_id'] === $mid, 'photo post uses the AI-picked real photo');
$d1 = json_decode($pdo->query('SELECT generation_meta FROM media_items WHERE id=' . (int) $posts[1]['media_id'])->fetchColumn(), true);
check(($d1['source'] ?? '') === 'brand_design' && $d1['base_media_id'] === $mid, 'design post built on a photo (video pick replaced)');
$d2 = json_decode($pdo->query('SELECT generation_meta FROM media_items WHERE id=' . (int) $posts[2]['media_id'])->fetchColumn(), true);
check(($d2['format'] ?? '') === 'card', 'card post rendered');

$pdo->exec("DELETE FROM media_items WHERE user_id=$uid");
$pdo->exec("DELETE FROM brand_profiles WHERE user_id=$uid");
$pdo->exec("DELETE FROM websites WHERE user_id=$uid");
$pdo->exec("DELETE FROM users WHERE id=$uid");
exec('rm -rf ' . escapeshellarg($tmp));
echo "\n" . ($n - $fails) . "/$n passed\n";
exit($fails ? 1 : 0);
