<?php
/**
 * جودة الرد على التقييمات لما الذكاء الاصطناعي مش متاح:
 *  - الرد الجاهز بلغة صاحب التقييم (مش عربي على تقييم إنجليزي).
 *  - الرد التلقائي مش بينشر رد جاهز باسم العميل.
 *  - التقييم الجديد مش بيتعلّم "اتبعت" لو الإرسال فشل.
 * php tests/Automation/review_reply_quality_test.php
 */
require_once __DIR__ . '/../../cron/bootstrap.php';
class FakeGoogleQ { public static $sent = []; }
ReviewAutoReplyRunner::$transport = function ($review, $text) { FakeGoogleQ::$sent[$review['external_review_id']] = $text; return ['success' => true]; };
$fails = 0; $n = 0;
function check($c, $m) { global $fails, $n; $n++; echo ($c ? '  ok  ' : '  FAIL ') . $m . "\n"; if (!$c) $fails++; }

echo "Fallback language\n";
check(ReviewReplyFallback::language('Amazing Nile cruise!') === 'en', 'English review -> en');
check(ReviewReplyFallback::language('رحلة ممتازة جدا') === 'ar', 'Arabic review -> ar');
check(!preg_match('/\p{Arabic}/u', ReviewReplyFallback::forReview('Terrible guide', 'negative')), 'English fallback has no Arabic');

$db = Database::getInstance();
$db->exec("DELETE FROM users WHERE email = 'reply-quality@test.local'");
$uid = (int) $db->query("INSERT INTO users (email, password_hash, company_name, role, status) VALUES ('reply-quality@test.local', 'x', 'Reply Quality', 'manager', 'active')");
$wid = (int) $db->query("INSERT INTO websites (user_id, main_url, company_name) VALUES (?, 'https://example.org', 'Reply Quality')", [$uid]);
$db->exec('DELETE FROM automation_decisions WHERE user_id = ?', [$uid]);
$db->exec('DELETE FROM reviews WHERE user_id = ?', [$uid]);
// باقة فيها auto_pilot (professional) عشان نختبر مسار الإرسال القديم كمان
$planId = (int) ($db->query("SELECT id FROM subscription_plans WHERE plan_code = 'professional_monthly' LIMIT 1")[0]['id'] ?? 0);
$db->query("INSERT INTO subscriptions (user_id, plan_id, plan_name, plan_type, status, price, currency, current_period_start, current_period_end, created_at)
    VALUES (?, ?, 'professional', 'monthly', 'active', 99.00, 'USD', NOW(), DATE_ADD(NOW(), INTERVAL 1 MONTH), NOW())", [$uid, $planId]);
$db->query("INSERT INTO platform_connections (website_id, user_id, platform, status, external_account_id, external_location_id) VALUES (?, ?, 'google_business', 'connected', 'acc', 'loc')", [$wid, $uid]);

echo "Generator without AI\n";
$gen = new ReplyGenerator();
$reply = (string) $gen->generate('The bus was late and the guide was rude', ['label' => 'negative', 'score' => 0.2], 'google_business', $uid);
check($gen->lastWasFallback, 'marked as fallback');
check($reply !== '' && !preg_match('/\p{Arabic}/u', $reply), 'English reply for English review: ' . $reply);

echo "New review arrives (sync path)\n";
$res = (new ReputationManager())->processWebhook([
    'user_id' => $uid, 'website_id' => $wid, 'platform' => 'google_business',
    'platform_review_id' => 'q-1', 'reviewer_name' => 'Tom', 'review_text' => 'Lovely day trip to Luxor',
    'rating' => 5, 'review_date' => date('Y-m-d H:i:s'), 'review_language' => 'en',
]);
$row = $db->query('SELECT * FROM reviews WHERE id = ?', [(int) ($res['review_id'] ?? 0)])[0] ?? [];
check(!empty($row), 'review saved ' . (empty($row) ? json_encode($res, JSON_UNESCAPED_UNICODE) : ''));
check(empty($row['reply_sent_at']), 'not marked as replied when nothing reached Google');
check(empty($row['ai_generated_reply']), 'canned reply not stored as the draft to publish');

echo "Auto-reply runner without AI\n";
$db->query("INSERT INTO wallet_transactions (user_id, type, amount, currency, status, reference_note, approved_at) VALUES (?, 'admin_adjustment', 50, 'USD', 'completed', 'test', NOW())", [$uid]);
$engine = new AutomationEngine($db);
AutomationEngine::setInstance($engine);
$out = (new ReviewAutoReplyRunner($db, $engine))->runForWebsite($uid, $wid);
check($out['sent'] === 0, 'nothing auto-posted (' . json_encode($out) . ')');
check(!isset(FakeGoogleQ::$sent['q-1']), 'Google got no canned reply');

$db->exec('DELETE FROM reviews WHERE user_id = ?', [$uid]);
$db->exec('DELETE FROM automation_decisions WHERE user_id = ?', [$uid]);
$db->exec('DELETE FROM platform_connections WHERE user_id = ?', [$uid]);
$db->exec('DELETE FROM wallet_transactions WHERE user_id = ?', [$uid]);
$db->exec('DELETE FROM subscriptions WHERE user_id = ?', [$uid]);
$db->exec('DELETE FROM websites WHERE id = ?', [$wid]);
$db->exec('DELETE FROM users WHERE id = ?', [$uid]);

echo "\n" . ($n - $fails) . "/$n passed\n";
exit($fails ? 1 : 0);
