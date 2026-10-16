<?php
/**
 * تسويق المنصة بالإيميل: استيراد من Apify، إيميلات من المواقع، السلسلة، والوقف.
 * DB_NAME=tourfecto_test DB_USER=tf DB_PASS=tfpass DB_HOST=127.0.0.1 ENCRYPTION_KEY=... php tests/Automation/platform_outreach_test.php
 */
require_once __DIR__ . '/../../cron/bootstrap.php';
require_once ROOT_PATH . '/app/Services/PlatformGrowth/PlatformOutreachService.php';
$fails = 0; $n = 0;
function check($c, $m) { global $fails, $n; $n++; echo ($c ? '  ok  ' : '  FAIL ') . $m . "\n"; if (!$c) $fails++; }

$pdo = Database::getInstance()->getConnection();
$pdo->exec('DROP TABLE IF EXISTS platform_leads'); $pdo->exec('DROP TABLE IF EXISTS platform_lead_messages');
foreach (array_keys(PlatformOutreachService::defaults()) as $k) { $pdo->prepare('DELETE FROM system_settings WHERE setting_key=?')->execute(['outreach_' . $k]); }

echo "mapping\n";
$m = PlatformOutreachService::mapRow(['title' => 'Nile Dream Tours', 'website' => 'https://www.niledream.com/', 'emails' => ['noreply@niledream.com', 'info@niledream.com', 'x@gmail.com'], 'city' => 'Luxor', 'countryCode' => 'EG', 'totalScore' => 4.8, 'reviewsCount' => 214, 'categoryName' => 'Tour agency', 'url' => 'https://www.google.com/maps/place/x']);
check($m['email'] === 'info@niledream.com' && $m['domain'] === 'niledream.com' && $m['language'] === 'ar' && $m['rating'] == 4.8, 'Apify Google Maps row mapped, company-domain email preferred');
check(PlatformOutreachService::mapRow(['name' => 'Sea Travel', 'website' => 'https://facebook.com/seatravel', 'countryCode' => 'GB'])['website'] === null, 'facebook page is not a website');
check(PlatformOutreachService::bestEmail(['logo@2x.png', 'test@example.com'], null) === null, 'junk emails rejected');

echo "import\n";
$pdo->exec("DELETE FROM users WHERE email='owner@already-customer.com'");
$pdo->exec("INSERT INTO users(company_name,email,password) VALUES('Cust','owner@already-customer.com','x')");
$svc = new PlatformOutreachService($pdo, null, function ($url) {
    $pages = [
        'https://www.redsea-diving.com/' => '<a href="/contact-us">Contact</a>',
        'https://www.redsea-diving.com/contact' => null,
        'https://www.redsea-diving.com/contact-us' => '<p>Write to us: booking@redsea-diving.com</p>',
        'https://data.test/items.json' => json_encode([['title' => 'Imported Co', 'emails' => ['hello@imported.co'], 'countryCode' => 'US']]),
    ];
    return isset($pages[$url]) && $pages[$url] !== null ? ['body' => $pages[$url], 'url' => $url] : null;
});
$svc->checkMx = false;
$rows = [
    ['title' => 'Nile Dream Tours', 'website' => 'https://niledream.com', 'emails' => ['info@niledream.com'], 'city' => 'Luxor', 'countryCode' => 'EG', 'totalScore' => 4.8, 'reviewsCount' => 214],
    ['title' => 'Nile Dream Tours', 'website' => 'https://niledream.com', 'emails' => ['info@niledream.com'], 'phone' => '+20 100'],
    ['title' => 'Red Sea Diving', 'website' => 'https://www.redsea-diving.com', 'city' => 'Hurghada', 'countryCode' => 'EG'],
    ['title' => 'London Trips', 'email' => 'sales@londontrips.co.uk', 'countryCode' => 'GB', 'totalScore' => 4.5, 'reviewsCount' => 40],
    ['title' => 'Customer', 'email' => 'owner@already-customer.com'],
    ['nothing' => 1],
];
$r = $svc->importRows($rows, 'apify');
check($r['added'] === 3 && $r['updated'] === 1 && $r['skipped'] === 2 && $r['no_email'] === 1, 'dedupe, existing customers and empty rows skipped (' . json_encode($r) . ')');
check($pdo->query("SELECT phone FROM platform_leads WHERE email='info@niledream.com'")->fetchColumn() === '+20 100', 'duplicate row fills missing data');
$u = $svc->importFromUrl('https://data.test/items.json');
check($u['added'] === 1, 'import from JSON URL');
check($svc->enrich() === 1 && $pdo->query("SELECT email FROM platform_leads WHERE company='Red Sea Diving'")->fetchColumn() === 'booking@redsea-diving.com', 'email found on contact page');

echo "sending\n";
$svc->saveSettings(['from_email' => 'hello@try-tourfecto.com', 'sender_name' => 'Ahmed', 'per_run' => '10', 'daily_limit' => '100']);
$sent = [];
$send = function ($to, $name, $subject, $html, $headers) use (&$sent) { $sent[] = compact('to', 'subject', 'html', 'headers'); return ['success' => true]; };
$ai = fn ($p) => 'Your 4.8-star reviews in Luxor really stand out.';
$res = $svc->run($send, $ai, true);
check($res['sent'] === 4, 'first message sent to every lead with an email (' . $res['sent'] . ')');
$nile = array_values(array_filter($sent, fn ($s) => $s['to'] === 'info@niledream.com'))[0];
check(strpos($nile['subject'], 'Nile Dream Tours') !== false && strpos($nile['html'], 'dir="rtl"') !== false, 'Egyptian company gets the Arabic email with its name');
check(strpos($nile['html'], 'Your 4.8-star reviews') !== false, 'AI opener used');
check(strpos($nile['html'], '/p/u/') !== false && strpos($nile['headers']['List-Unsubscribe'], '/p/u/') !== false && ($nile['headers']['List-Unsubscribe-Post'] ?? '') === 'List-Unsubscribe=One-Click', 'unsubscribe link and one-click headers');
check(strpos($nile['html'], '/p/c/') !== false && strpos($nile['html'], '{{') === false, 'tracked signup link, no leftover placeholders');
$london = array_values(array_filter($sent, fn ($s) => $s['to'] === 'sales@londontrips.co.uk'))[0];
check(strpos($london['html'], 'dir="ltr"') !== false && strpos($london['subject'], 'London Trips') !== false, 'UK company gets English');
$sent = [];
check($svc->run($send, $ai, true)['sent'] === 0, 'no follow-up before the gap');
$pdo->exec("UPDATE platform_leads SET next_send_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE)");
// شركة ألغت الاشتراك وشركة سجّلت
$tok = $pdo->query("SELECT token FROM platform_leads WHERE email='sales@londontrips.co.uk'")->fetchColumn();
check($svc->unsubscribe($tok), 'unsubscribe by token');
$pdo->exec("DELETE FROM users WHERE email='hello@imported.co'");
$pdo->exec("INSERT INTO users(company_name,email,password) VALUES('Imp','hello@imported.co','x')");
$res = $svc->run($send, $ai, true);
$to = array_column($sent, 'to');
check(in_array('info@niledream.com', $to, true) && !in_array('sales@londontrips.co.uk', $to, true) && !in_array('hello@imported.co', $to, true), 'follow-up sent; unsubscribed and signed-up companies skipped');
check($pdo->query("SELECT status FROM platform_leads WHERE email='hello@imported.co'")->fetchColumn() === 'signed_up', 'signed up detected automatically');
check(strpos($sent[0]['subject'], 'تحليل مجاني') !== false || strpos($sent[0]['subject'], 'Free website') !== false, 'second message is the free analysis');
$pdo->exec("UPDATE platform_leads SET next_send_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE status='contacted'");
$svc->run($send, $ai, true);
check($pdo->query("SELECT status FROM platform_leads WHERE email='info@niledream.com'")->fetchColumn() === 'finished', 'sequence finishes after the 3rd message');

echo "safety\n";
$pdo->exec("INSERT INTO platform_leads(company,email,status,token,language) VALUES('Bounce Co','gone@bounce.co','new','" . str_repeat('b', 32) . "','en')");
$svc->run(fn () => ['success' => false, 'error' => '550 5.1.1 User unknown'], $ai, true);
check($pdo->query("SELECT status FROM platform_leads WHERE email='gone@bounce.co'")->fetchColumn() === 'bounced', 'hard bounce stops that address');
$set = $svc->getSettings();
$set['first_send_date'] = date('Y-m-d'); check($svc->todayLimit($set) === 15, 'warm-up: 15 on the first day');
$set['first_send_date'] = date('Y-m-d', time() - 3 * 86400); check($svc->todayLimit($set) === 45 || $svc->todayLimit($set) === min(45, (int) $set['daily_limit']), 'warm-up grows each day');
$set['hour_from'] = '9'; $set['hour_to'] = '17'; $set['skip_days'] = '5'; $set['timezone'] = 'Africa/Cairo';
check($svc->inWindow($set, new DateTimeImmutable('2026-10-15 10:00', new DateTimeZone('Africa/Cairo'))) && !$svc->inWindow($set, new DateTimeImmutable('2026-10-16 10:00', new DateTimeZone('Africa/Cairo'))) && !$svc->inWindow($set, new DateTimeImmutable('2026-10-15 20:00', new DateTimeZone('Africa/Cairo'))), 'only in working hours, not on Friday');
check($svc->run($send, $ai, false)['reason'] === 'disabled', 'nothing sends while disabled');
$svc->saveSettings(['smtp_password' => 'secret123']);
check($svc->getSettings()['smtp_password'] === 'secret123' && $svc->getSettings(true)['smtp_password'] === '' && $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='outreach_smtp_password'")->fetchColumn() !== 'secret123', 'SMTP password stored encrypted, hidden from the page');

$pdo->exec("DELETE FROM users WHERE email IN ('owner@already-customer.com','hello@imported.co')");
$pdo->exec('DROP TABLE platform_leads'); $pdo->exec('DROP TABLE platform_lead_messages');
foreach (array_keys(PlatformOutreachService::defaults()) as $k) { $pdo->prepare('DELETE FROM system_settings WHERE setting_key=?')->execute(['outreach_' . $k]); }
echo "\n" . ($n - $fails) . "/$n passed\n";
exit($fails ? 1 : 0);
