<?php
/**
 * الباقة بتتحكم فعلًا: الخصم من الباقة ← المحفظة ← منع، وكل باقة بتفتح خدماتها بس.
 * DB_NAME=tourfecto_test DB_USER=tf DB_PASS=tfpass DB_HOST=127.0.0.1 ENCRYPTION_KEY=... php tests/Automation/plan_gate_test.php
 */
require_once __DIR__ . '/../../cron/bootstrap.php';
require_once ROOT_PATH . '/app/Services/Subscription/PlanExtras.php';
require_once ROOT_PATH . '/app/Middleware/PlanGateMiddleware.php';
$fails = 0; $n = 0;
function check($c, $m) { global $fails, $n; $n++; echo ($c ? '  ok  ' : '  FAIL ') . $m . "\n"; if (!$c) $fails++; }
$pdo = Database::getInstance()->getConnection();
$plansBefore = $pdo->query('SELECT * FROM subscription_plans')->fetchAll(PDO::FETCH_ASSOC);
$x = new PlanExtras();
$x->applyPreset();
$ws = new WalletService();
$users = [];

function subscriber(string $tier): int {
    global $pdo, $users, $ws;
    $pdo->prepare("INSERT INTO users(company_name,email,password) VALUES(?,?,'x')")->execute([$tier . ' Co', $tier . getmypid() . mt_rand() . '@example.com']);
    $uid = (int) $pdo->lastInsertId();
    $users[] = $uid;
    $ws->adminAddBalance($uid, 1000, $uid, 'test');
    $r = $ws->subscribeWithBalance($uid, $tier, 'monthly');
    if (empty($r['success'])) { echo "subscribe failed: " . json_encode($r) . "\n"; }
    return $uid;
}
function gate(int $uid, string $mode): ?array {
    $_SERVER['auth_user_id'] = $uid; $_SERVER['REQUEST_URI'] = '/api/test';
    $r = (new PlanGateMiddleware())->applyModifier($mode)->handle();
    http_response_code(200);
    return $r;
}
function setUsed(int $uid, string $feature, int $used): void {
    global $pdo;
    $q = PlanUsage::quota($uid, $feature);
    for ($i = $q['used']; $i < $used; $i++) PlanUsage::consume($uid, $feature, 'test');
}

$s = subscriber('starter');
$p = subscriber('professional');
$e = subscriber('enterprise');
check(Subscription::activeSubscriptionRow($s)['plan_name'] === 'starter' && abs($ws->getBalance($s) - (1000 - 59)) < 0.01, 'starter subscribed: $59 taken from wallet');

echo "deduction: plan first, then wallet, then blocked\n";
$q = PlanUsage::quota($s, 'ai_tool');
check($q['limit'] === 200 && $q['remaining'] === 200, 'starter has 200 AI tool uses');
check(gate($s, 'ai_tool') === null && PlanUsage::quota($s, 'ai_tool')['remaining'] === 199, 'one use deducted from the plan (199 left), wallet untouched');
check(abs($ws->getBalance($s) - 941) < 0.01, 'wallet unchanged while plan has quota');
setUsed($s, 'ai_tool', 200);
check(PlanUsage::quota($s, 'ai_tool')['remaining'] === 0, 'plan quota finished');
check(gate($s, 'ai_tool') === null && abs($ws->getBalance($s) - (941 - PlanGateMiddleware::AI_TOOL_PRICE)) < 0.001, 'after the plan: charged $0.02 from wallet');
$pdo->prepare("INSERT INTO wallet_transactions(user_id,type,amount,currency,status,reference_note) VALUES(?,'admin_adjustment',?,'USD','completed','test empty')")->execute([$s, -round($ws->getBalance($s), 2)]);
$bal = $ws->getBalance($s);
$r = gate($s, 'ai_tool');
check($bal < 0.02 ? (($r['code'] ?? 0) === 402 && ($r['upgrade_url'] ?? '') === '/subscription') : true, 'no plan quota + no balance: blocked (402) with upgrade link (balance ' . $bal . ')');

$q = PlanUsage::quota($s, 'article_generation');
check($q['limit'] === 6, 'starter: 6 articles');
setUsed($s, 'article_generation', 6);
check(!PlanUsage::consume($s, 'article_generation', 'x'), 'article #7 not taken from plan');
check(PlanUsage::quota($p, 'article_generation')['limit'] === 20 && PlanUsage::quota($e, 'article_generation')['limit'] === 60, 'growth 20 / leader 60 articles');
check(PlanUsage::quota($s, 'media_video')['limit'] === 1 && PlanUsage::quota($p, 'media_video')['limit'] === 4, 'videos: 1 / 4');
check(PlanUsage::quota($s, 'media_image')['limit'] === 30 && PlanUsage::quota($s, 'marketing_tool')['limit'] === 40, 'starter images 30, marketing 40');

echo "chatbot replies\n";
$sv = new SubscriptionValidator();
$v = $sv->validateSubscription($s);
check(($v['chat_credits_remaining'] ?? 0) === 1000, 'starter: 1000 chatbot replies');
$sv->consumeChatCredits($s, 1);
check(($sv->validateSubscription($s)['chat_credits_remaining'] ?? 0) === 999, 'one reply deducted (999)');
$sv->consumeChatCredits($s, 999);
check(($sv->validateSubscription($s)['chat_credits_remaining'] ?? 1) <= 0, 'replies finished: chatbot stops (or uses wallet if funded)');
check(($sv->validateSubscription($e)['chat_credits_remaining'] ?? 0) === 10000, 'leader: 10000 replies');

echo "services only in their plan\n";
check(!PlanGateMiddleware::hasFlag($s, 'auto_pilot') && PlanGateMiddleware::hasFlag($p, 'auto_pilot'), 'auto pilot: off in Starter, on in Growth');
check(!PlanGateMiddleware::hasFlag($p, 'advanced_analytics') && PlanGateMiddleware::hasFlag($e, 'advanced_analytics'), 'advanced analytics (CEO advisor): Leader only');
check((gate($p, 'flag_advanced_analytics')['code'] ?? 0) === 403, 'Growth opening the CEO advisor: 403 "not in your plan"');
check(gate($e, 'flag_advanced_analytics') === null, 'Leader opens it');
check(PlanGateMiddleware::hasFlag($s, 'whatsapp_bot'), 'WhatsApp bot in all plans');
check((int) FeatureResolver::value($s, 'team_members') === 2 && (int) FeatureResolver::value($p, 'team_members') === 5 && (int) FeatureResolver::value($e, 'team_members') === 15, 'team members 2 / 5 / 15');
check((int) FeatureResolver::value($s, 'multiple_websites') === 1 && (int) FeatureResolver::value($e, 'multiple_websites') === 8, 'websites 1 / 8');
// استثناء الأدمن
if ($pdo->query("SHOW COLUMNS FROM subscriptions LIKE 'feature_overrides'")->fetch()) {
    FeatureResolver::saveOverrides($s, ['auto_pilot' => true]);
    check(PlanGateMiddleware::hasFlag($s, 'auto_pilot'), 'admin can grant one customer an extra service');
    FeatureResolver::saveOverrides($s, []);
}
check((gate(0, 'ai_tool')['code'] ?? 0) === 401, 'not logged in: refused');

// تنظيف
foreach ($users as $uid) {
    foreach (['plan_usage', 'plan_extra_credits', 'plan_custom', 'wallet_transactions', 'subscriptions', 'invoices', 'usage_logs'] as $tbl) { try { $pdo->exec("DELETE FROM $tbl WHERE user_id=$uid"); } catch (Throwable $ex) {} }
    $pdo->exec("DELETE FROM users WHERE id=$uid");
}
foreach ($plansBefore as $r) { $pdo->prepare('UPDATE subscription_plans SET name=?,price=?,ai_analysis_limit=?,ai_message_limit=?,review_auto_reply_limit=?,websites_limit=?,features_json=? WHERE id=?')->execute([$r['name'], $r['price'], $r['ai_analysis_limit'], $r['ai_message_limit'], $r['review_auto_reply_limit'], $r['websites_limit'], $r['features_json'], $r['id']]); }
echo "\n" . ($n - $fails) . "/$n passed\n";
exit($fails ? 1 : 0);
