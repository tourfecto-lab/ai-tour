<?php
/**
 * XPay: الإعدادات من الأدمن، جلسة الدفع، التأكيد من XPay نفسه، ومفيش شحن مرتين.
 * DB_NAME=tourfecto_test DB_USER=tf DB_PASS=tfpass DB_HOST=127.0.0.1 ENCRYPTION_KEY=... php tests/Automation/xpay_test.php
 */
require_once __DIR__ . '/../../cron/bootstrap.php';
require_once ROOT_PATH . '/app/Services/Payment/XPayGateway.php';
$fails = 0; $n = 0;
function check($c, $m) { global $fails, $n; $n++; echo ($c ? '  ok  ' : '  FAIL ') . $m . "\n"; if (!$c) $fails++; }
$pdo = Database::getInstance()->getConnection();
$set = new SystemSettingsService();
$keep = [XPayGateway::CONFIG_KEY => $set->get(XPayGateway::CONFIG_KEY, ''), XPayGateway::SECRET_KEY => $set->get(XPayGateway::SECRET_KEY, ''), XPayGateway::WEBHOOK_SECRET => $set->get(XPayGateway::WEBHOOK_SECRET, ''), 'fx_rates' => $set->get('fx_rates', '')];
$set->set(XPayGateway::CONFIG_KEY, ''); $set->set(XPayGateway::SECRET_KEY, '');
$set->set('fx_rates', ''); CurrencyService::resetCache(); CurrencyService::saveRates(['EGP' => ['base' => 50, 'margin' => 0, 'locked' => true]]);

// XPay وهمي: بيسجّل الطلبات، والجلسة بتتغير حالتها من الاختبار
$calls = []; $remote = [];
$fake = function (string $m, string $url, ?array $body, string $key) use (&$calls, &$remote) {
    $calls[] = [$m, $url, $body, $key];
    global $rejectEmbedded;
    if ($key !== 'sk_test_good123') return ['code' => 401, 'body' => ['error' => ['type' => 'authentication_error', 'message' => 'Invalid API key']]];
    if ($m === 'GET' && str_contains($url, 'cs_test_tourfecto_probe')) return ['code' => 404, 'body' => ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing']]];
    global $egpOnly;
    if ($m === 'POST' && !empty($egpOnly) && $body['lineItems'][0]['priceData']['currency'] !== 'EGP') return ['code' => 400, 'body' => ['error' => ['type' => 'invalid_request_error', 'code' => 'invalid_request', 'message' => 'Price price_74hLoiVxzKTEijwydG6qTk currency (USD) does not match the session currency (EGP).', 'param' => 'lineItems[0][price]']]];
    if ($m === 'POST' && !empty($rejectEmbedded) && $body['uiMode'] === 'embedded') return ['code' => 400, 'body' => ['error' => ['type' => 'invalid_request_error', 'message' => 'uiMode embedded not enabled', 'param' => 'uiMode']]];
    if ($m === 'POST') { $id = 'cs_test_' . count($calls); $remote[$id] = ['id' => $id, 'status' => ['type' => 'open'], 'currency' => $body['lineItems'][0]['priceData']['currency'], 'amountSubtotal' => $body['lineItems'][0]['priceData']['unitAmount'], 'amountTotal' => $body['lineItems'][0]['priceData']['unitAmount'] + 1750, 'url' => 'https://checkout.xpay.app/pay/' . $id]; return ['code' => 200, 'body' => $remote[$id] + ['clientSecret' => $id . '_secret_x']]; }
    if ($m === 'GET') { $id = basename(parse_url($url, PHP_URL_PATH)); return isset($remote[$id]) ? ['code' => 200, 'body' => $remote[$id]] : ['code' => 404, 'body' => null]; }
    return ['code' => 500, 'body' => null];
};
$g = new XPayGateway($fake);

echo "admin settings\n";
check(!$g->isReady() && !$g->config()['has_secret'], 'not connected by default');
foreach ([['secret_key' => 'pk_test_abc'], ['secret_key' => 'rk_test_abc'], ['public_key' => 'sk_test_abc'], ['enabled' => true]] as $bad) {
    try { $g->saveConfig($bad); $ok = false; } catch (InvalidArgumentException $e) { $ok = true; }
    check($ok, 'rejected: ' . json_encode($bad) . ($ok ? ' (' . $e->getMessage() . ')' : ''));
}
$c = $g->saveConfig(['public_key' => 'pk_test_pub1', 'secret_key' => 'sk_test_good123', 'enabled' => true, 'charge_currency' => 'EGP']);
check($c['enabled'] && $c['has_secret'] && $c['mode'] === 'test' && $g->isReady(), 'connected in test mode');
$raw = $pdo->query("SELECT setting_value, is_secret FROM system_settings WHERE setting_key='" . XPayGateway::SECRET_KEY . "'")->fetch(PDO::FETCH_ASSOC);
check($raw['is_secret'] == 1 && !str_contains($raw['setting_value'], 'sk_test_good123'), 'secret key stored encrypted');
check(!str_contains(json_encode($g->config()), 'sk_test_good123'), 'secret key never returned to the admin screen');
try { $g->saveConfig(['public_key' => 'pk_live_x1']); $ok = false; } catch (InvalidArgumentException $e) { $ok = true; }
check($ok, 'test + live keys mixed: rejected');
check($g->testConnection()['ok'], 'connection test passes with the right key');
$g->saveConfig(['secret_key' => 'sk_test_wrong9']);
$t = $g->testConnection();
check(!$t['ok'] && str_contains($t['message'], 'رفض'), 'wrong key: clear message');
$g->saveConfig(['secret_key' => 'sk_test_good123']);

echo "payment\n";
$pdo->prepare("INSERT INTO users(company_name,email,password) VALUES('Pay Co',?,'x')")->execute(['xpay' . getmypid() . '@example.com']);
$uid = (int) $pdo->lastInsertId();
$ws = new WalletService();
try { $g->createWalletTopUp($uid, 2, 'https://t.test/r', 'https://t.test/c'); $ok = false; } catch (InvalidArgumentException $e) { $ok = true; }
check($ok, 'below minimum amount: refused');
$s = $g->createWalletTopUp($uid, 50, 'https://t.test/wallet/xpay/return', 'https://t.test/c');
$sent = end($calls)[2];
check($sent['lineItems'][0]['priceData'] === ['unitAmount' => 250000, 'currency' => 'EGP', 'productData' => ['name' => 'Tourfecto wallet top-up ($50.00)']], '$50 charged as 2,500.00 EGP (250000 piasters)');
check($sent['uiMode'] === 'embedded' && str_contains($sent['afterCompletion']['redirect']['url'], 'session_id={CHECKOUT_SESSION_ID}'), 'XPay checkout window with return link');
check($s['checkout_url'] === 'https://checkout.xpay.app/pay/' . $s['session_id'], 'customer gets the XPay payment link');
$sid = $s['session_id'];
check($g->confirm($sid)['status'] === 'pending' && $ws->getBalance($uid) == 0, 'not paid yet: no balance');
// رسالة مزورة بتقول "اتدفع" بس XPay نفسه بيقول لأ
check(($g->handleWebhook(json_encode(['type' => 'checkout.session.completed', 'data' => ['id' => $sid, 'paymentStatus' => 'paid']]))['status'] ?? '') === 'pending' && $ws->getBalance($uid) == 0, 'fake "paid" webhook ignored (we ask XPay ourselves)');
$remote[$sid]['status'] = ['type' => 'complete', 'paymentStatus' => 'paid']; $remote[$sid]['paymentStatus'] = 'paid';
$remote[$sid]['amountSubtotal'] = 100000;
check($g->confirm($sid)['status'] === 'review' && $ws->getBalance($uid) == 0, 'paid less than asked: held for review, nothing credited');
$remote[$sid]['amountSubtotal'] = 250000;
$pdo->prepare("UPDATE payment_transactions SET status='pending' WHERE gateway_transaction_id=?")->execute([$sid]);
$r = $g->handleWebhook(json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => $sid]]]));
check(($r['status'] ?? '') === 'paid' && abs($ws->getBalance($uid) - 50) < 0.01, 'paid: wallet +$50 (fees on the customer not counted)');
check($g->confirm($sid)['status'] === 'paid' && abs($ws->getBalance($uid) - 50) < 0.01, 'return page after webhook: not credited twice');
$pdo->prepare("UPDATE payment_transactions SET status='pending' WHERE gateway_transaction_id=?")->execute([$sid]);
$g->confirm($sid);
check(abs($ws->getBalance($uid) - 50) < 0.01, 'even if our record is reset, wallet guard blocks a double credit');
$note = $pdo->query("SELECT reference_note FROM wallet_transactions WHERE user_id=$uid AND type='deposit'")->fetchColumn();
check($note === 'xpay:' . $sid, 'wallet entry tagged xpay');
$s2 = $g->createWalletTopUp($uid, 20, 'https://t.test/r', 'https://t.test/c');
$remote[$s2['session_id']]['status'] = ['type' => 'expired'];
check($g->confirm($s2['session_id'])['status'] === 'expired', 'expired session marked cancelled');
check($g->confirm('cs_unknown_1')['status'] === 'unknown' && $g->confirm('../../x')['status'] === 'invalid', 'unknown / malformed session ids ignored');
$g->saveConfig(['charge_currency' => 'USD']);
check($g->chargeFor(50) === ['currency' => 'USD', 'minor' => 5000, 'label' => '$50.00'], 'admin can switch to charging in USD');

echo "diagnostics\n";
$rejectEmbedded = true;
$s3 = $g->createWalletTopUp($uid, 10, 'https://t.test/r', 'https://t.test/c');
check(end($calls)[2]['uiMode'] === 'hosted' && $s3['session_id'] !== '', 'embedded refused: falls back to the XPay page (hosted)');
$rejectEmbedded = false;
$g->saveConfig(['secret_key' => 'sk_test_wrong9']);
try { $g->createWalletTopUp($uid, 10, 'https://t.test/r', 'https://t.test/c'); $ok = false; } catch (RuntimeException $e) { $ok = true; }
$le = $g->config()['last_error'] ?? [];
check($ok && str_contains($le['detail'] ?? '', '401') && str_contains($le['detail'] ?? '', 'Invalid API key') && !str_contains(json_encode($le), 'sk_test_wrong9'), 'XPay error saved for the admin (no key inside): ' . ($le['detail'] ?? ''));
$g->saveConfig(['secret_key' => 'sk_test_good123']);
check(empty($g->config()['last_error']), 'new key clears the old error');
$g->saveConfig(['webhook_secret' => 'whsec_' . base64_encode('topsecretkey123')]);
$pl = json_encode(['type' => 'checkout.session.completed', 'data' => ['id' => $sid]]);
$ts = (string) time();
$good = base64_encode(hash_hmac('sha256', 'msg_1.' . $ts . '.' . $pl, 'topsecretkey123', true));
check($g->signatureState($pl, ['Webhook-Id' => 'msg_1', 'Webhook-Timestamp' => $ts, 'Webhook-Signature' => 'v1,' . $good]) === 'ok', 'webhook signature (standard format) verified');
check($g->signatureState($pl, ['XPay-Signature' => 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $pl, 'whsec_' . base64_encode('topsecretkey123'))]) === 'ok', 'webhook signature (t=,v1= format) verified');
check($g->signatureState($pl, ['Webhook-Id' => 'msg_1', 'Webhook-Timestamp' => $ts, 'Webhook-Signature' => 'v1,AAAA']) === 'mismatch', 'wrong signature detected');
$g->handleWebhook($pl, ['Webhook-Signature' => 'v1,AAAA']);
check(($g->config()['last_webhook']['type'] ?? '') === 'checkout.session.completed', 'last webhook shown to the admin');

echo "account in EGP only\n";
$egpOnly = true;
$g->saveConfig(['charge_currency' => 'USD']);
$s4 = $g->createWalletTopUp($uid, 10, 'https://t.test/r', 'https://t.test/c');
$sent = end($calls)[2]['lineItems'][0]['priceData'];
check($s4['session_id'] !== '' && $sent['currency'] === 'EGP' && $sent['unitAmount'] === 50000 && end($calls)[2]['uiMode'] === 'embedded', 'XPay says the account is EGP: $10 resent as 500.00 EGP automatically');
check($g->config()['charge_currency'] === 'EGP' && $g->config()['account_currency'] === 'EGP' && empty($g->config()['last_error']), 'setting switched to EGP and the old error cleared');
$remote[$s4['session_id']]['status'] = ['type' => 'complete', 'paymentStatus' => 'paid']; $remote[$s4['session_id']]['paymentStatus'] = 'paid';
$before = $ws->getBalance($uid);
check($g->confirm($s4['session_id'])['status'] === 'paid' && abs($ws->getBalance($uid) - $before - 10) < 0.01, 'paid in EGP: wallet +$10');
$egpOnly = false;

try { $g->saveConfig(['api_base' => 'https://evil.example.com']); $ok = false; } catch (InvalidArgumentException $e) { $ok = true; }
check($ok, 'API address outside xpay.app refused (secret key never sent elsewhere)');
$g->saveConfig(['api_base' => 'https://api.xpay.app/v1/']);
check($g->config()['api_base'] === 'https://api.xpay.app/v1', 'admin can set a different XPay API address');
$g->saveConfig(['api_base' => 'https://api.xpay.app']);

// تنظيف
foreach (['payment_transactions', 'wallet_transactions'] as $tbl) { $pdo->exec("DELETE FROM $tbl WHERE user_id=$uid"); }
$pdo->exec("DELETE FROM users WHERE id=$uid");
foreach ($keep as $k => $val) { $set->set($k, $val); }
echo "\n" . ($n - $fails) . "/$n passed\n";
exit($fails ? 1 : 0);
