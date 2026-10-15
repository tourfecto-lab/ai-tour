<?php
/**
 * Webhook Meta الموحّد: رسالة ماسنجر/إنستجرام بتوصل للشركة الصح بمعرّف
 * الصفحة، ومن غير تكرار، ورسايلنا (echo) بتتجاهل.
 * php tests/Automation/meta_webhook_test.php
 */
require_once __DIR__ . '/../../cron/bootstrap.php';
$fails = 0; $n = 0;
function check($c, $m) { global $fails, $n; $n++; echo ($c ? '  ok  ' : '  FAIL ') . $m . "\n"; if (!$c) $fails++; }
class FakeChatManagerMW { public $got = []; public function processIncomingMessage(array $d) { $this->got[] = $d; return ['success' => true]; } }

$db = Database::getInstance();
$db->exec("DELETE FROM users WHERE email = 'meta-webhook@test.local'");
$uid = (int) $db->query("INSERT INTO users (email, password_hash, company_name, role, status) VALUES ('meta-webhook@test.local', 'x', 'Meta Hook', 'manager', 'active')");
$wid = (int) $db->query("INSERT INTO websites (user_id, main_url, company_name) VALUES (?, 'https://example.net', 'Meta Hook')", [$uid]);
$db->exec("DELETE FROM ai_webhook_events WHERE external_event_id LIKE 'mw-test-%'");
$db->query("INSERT INTO platform_connections (website_id, user_id, platform, status, external_location_id, access_token) VALUES (?, ?, 'messenger', 'connected', 'PAGE-777', 'x')", [$wid, $uid]);
$db->query("INSERT INTO platform_connections (website_id, user_id, platform, status, external_location_id, access_token) VALUES (?, ?, 'instagram', 'connected', 'IG-888', 'x')", [$wid, $uid]);

$fake = new FakeChatManagerMW();
$page = ['object' => 'page', 'entry' => [['id' => 'PAGE-777', 'messaging' => [
    ['sender' => ['id' => 'PSID-1'], 'message' => ['mid' => 'mw-test-1', 'text' => 'How much is the Luxor tour?']],
    ['sender' => ['id' => 'PAGE-777'], 'message' => ['mid' => 'mw-test-2', 'text' => 'our reply', 'is_echo' => true]],
]]]];
$r = MetaWebhookController::route($page, $fake);
check($r['messages'] === 1 && count($fake->got) === 1, 'Messenger message routed once (' . json_encode($r) . ')');
check(($fake->got[0]['website_id'] ?? 0) === $wid && $fake->got[0]['platform'] === 'messenger' && $fake->got[0]['phone_number'] === 'PSID-1', 'to the right website, platform and sender');
MetaWebhookController::route($page, $fake);
check(count($fake->got) === 1, 'Meta retry of the same message is not answered twice');

$ig = ['object' => 'instagram', 'entry' => [['id' => 'IG-888', 'messaging' => [['sender' => ['id' => 'IGSID-5'], 'message' => ['mid' => 'mw-test-3', 'text' => 'Hi']]]]]];
MetaWebhookController::route($ig, $fake);
check(count($fake->got) === 2 && $fake->got[1]['platform'] === 'instagram', 'Instagram DM routed');

$r = MetaWebhookController::route(['object' => 'page', 'entry' => [['id' => 'OTHER', 'messaging' => [['sender' => ['id' => 'x'], 'message' => ['mid' => 'mw-test-4', 'text' => 'hi']]]]]], $fake);
check($r['unknown'] === 1 && count($fake->got) === 2, 'unknown page ignored');
check(strpos(MetaWebhookController::verifyToken(), 'tfm_') === 0, 'verify token format');

$db->exec("DELETE FROM ai_webhook_events WHERE external_event_id LIKE 'mw-test-%'");
$db->exec('DELETE FROM platform_connections WHERE user_id = ?', [$uid]);
$db->exec('DELETE FROM websites WHERE id = ?', [$wid]);
$db->exec('DELETE FROM users WHERE id = ?', [$uid]);
echo "\n" . ($n - $fails) . "/$n passed\n";
exit($fails ? 1 : 0);
