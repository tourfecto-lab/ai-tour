<?php
/**
 * الباقات المرنة والعملات: سعر الصرف، الباقات المقترحة، التجربة المجانية، الإضافات،
 * ابني باقتك، والتجديد بسعر الباقة المخصصة.
 * DB_NAME=tourfecto_test DB_USER=tf DB_PASS=tfpass DB_HOST=127.0.0.1 ENCRYPTION_KEY=... php tests/Automation/plans_studio_test.php
 */
require_once __DIR__ . '/../../cron/bootstrap.php';
require_once ROOT_PATH . '/app/Services/Subscription/PlanExtras.php';
require_once ROOT_PATH . '/app/Services/Payment/CurrencyService.php';
$fails = 0; $n = 0;
function check($c, $m) { global $fails, $n; $n++; echo ($c ? '  ok  ' : '  FAIL ') . $m . "\n"; if (!$c) $fails++; }
$pdo = Database::getInstance()->getConnection();
$set = new SystemSettingsService();
$keep = ['fx_rates' => $set->get('fx_rates', ''), 'plan_extras_config' => $set->get('plan_extras_config', '')];
$plansBefore = $pdo->query('SELECT * FROM subscription_plans')->fetchAll(PDO::FETCH_ASSOC);
$displayBefore = $pdo->query('SELECT * FROM plan_pricing_display')->fetchAll(PDO::FETCH_ASSOC);

echo "currency\n";
$set->set('fx_rates', '');
CurrencyService::resetCache();
CurrencyService::saveRates(['EGP' => ['base' => 50, 'margin' => 2, 'locked' => true]]);
check(abs(CurrencyService::rate('EGP') - 51) < 0.0001, 'EGP rate = base 50 + 2% margin');
check(CurrencyService::fromUsd(39, 'EGP') == 1990.0, '$39 = 1,990 EGP (rounded to 5)');
check(CurrencyService::format(39, 'EGP') === '1,990 ج.م' && CurrencyService::format(39, 'EGP', false) === 'EGP 1,990', 'formatted in Arabic and English');
check(abs(CurrencyService::toUsd(1990, 'EGP') - 39.02) < 0.01, 'EGP payment converts back to USD');
$u = CurrencyService::refreshFromMarket(fn () => ['EGP' => 49.1, 'SAR' => 3.76]);
check($u === ['SAR'] && abs(CurrencyService::rate('EGP') - 51) < 0.0001, 'market refresh skips the locked EGP rate');
$_COOKIE[CurrencyService::COOKIE] = 'EGP';
check(CurrencyService::forViewer() === 'EGP', 'viewer currency from cookie');
unset($_COOKIE[CurrencyService::COOKIE]);
check(CurrencyService::forViewer(['country_code' => 'SA']) === 'SAR', 'viewer currency from country');

echo "plans\n";
$x = new PlanExtras();
check($x->applyPreset() === ['starter', 'professional', 'enterprise'], 'recommended plans applied');
$cat = BillingPlanCatalog::read();
check((float) $cat['starter']['price_monthly'] === 39.0 && $cat['professional']['features']['articles'] === 25 && $cat['enterprise']['features']['advanced_analytics'] === true, 'catalog shows the new prices and allowances');

echo "trial\n";
$pdo->prepare("INSERT INTO users(company_name,email,password) VALUES('Trial Co',?,'x')")->execute(['plans' . getmypid() . '@example.com']);
$uid = (int) $pdo->lastInsertId();
check($x->trialEligible($uid), 'new account can start a trial');
$t = $x->startTrial($uid);
$sub = Subscription::activeSubscriptionRow($uid);
check($sub && $sub['status'] === 'trialing' && (float) $sub['price'] === 0.0 && $sub['plan_name'] === 'professional', 'trial is active on Growth, free');
check(!$x->trialEligible($uid), 'trial only once');
check(PlanUsage::quota($uid, 'article_generation')['limit'] === 25, 'trial gets the plan quota');

echo "add-ons\n";
try { $x->buyPack($uid, 'articles_10'); $ok = false; } catch (DomainException $e) { $ok = json_decode($e->getMessage(), true)['shortfall'] == 10; }
check($ok, 'no balance: clear shortfall');
(new WalletService())->adminAddBalance($uid, 300, $uid, 'test');
$r = $x->buyPack($uid, 'articles_10', 'k1');
check($r['qty'] === 10 && abs((new WalletService())->getBalance($uid) - 290) < 0.01, 'pack charged from wallet');
check($x->buyPack($uid, 'articles_10', 'k1')['already'] ?? false, 'same request not charged twice');
check(PlanUsage::quota($uid, 'article_generation')['limit'] === 35, 'quota grows by the pack');
$x->buyPack($uid, 'chat_2000');
$v = (new SubscriptionValidator())->validateSubscription($uid);
check(($v['chat_credits_remaining'] ?? 0) === 5000 + 2000, 'chatbot replies grow by the pack (' . ($v['chat_credits_remaining'] ?? 'x') . ')');

echo "build your plan\n";
$q = $x->quote('professional', 'monthly', ['article_generation' => 10, 'chat' => 1000]);
check($q['extras_price'] == 20.0 && $q['total'] == 109.0, 'quote: $89 + 10 articles ($12) + 1000 replies ($8) = $109');
$qy = $x->quote('professional', 'yearly', ['article_generation' => 10]);
check($qy['extras_price'] == 120.0 && $qy['total'] == 1010.0, 'yearly extras = 10 months');
try { $x->quote('professional', 'monthly', ['article_generation' => 7]); $ok = false; } catch (InvalidArgumentException $e) { $ok = true; }
check($ok, 'quantities follow the step');
$balBefore = (new WalletService())->getBalance($uid);
$b = $x->buyCustom($uid, 'professional', 'monthly', ['article_generation' => 10, 'chat' => 1000], 'c1');
$sub = Subscription::activeSubscriptionRow($uid);
check($sub['status'] === 'active' && (float) $sub['price'] === 109.0, 'custom plan active at $109');
check(abs($balBefore - (new WalletService())->getBalance($uid) - 109) < 0.01, 'charged $109 (trial converted at full price)');
check(PlanUsage::quota($uid, 'article_generation')['limit'] === 25 + 10 + 10, 'articles: plan 25 + custom 10 + earlier pack 10 kept');
$v = (new SubscriptionValidator())->validateSubscription($uid);
check(($v['chat_credits_remaining'] ?? 0) === 5000 + 1000 + 2000, 'chatbot replies: plan + custom + pack');

echo "renewal\n";
$sid = (int) $sub['id'];
$pdo->prepare('UPDATE subscriptions SET current_period_start=DATE_SUB(NOW(),INTERVAL 31 DAY), current_period_end=DATE_SUB(NOW(),INTERVAL 1 HOUR) WHERE id=?')->execute([$sid]);
$pdo->prepare('UPDATE plan_extra_credits SET expires_at=DATE_SUB(NOW(),INTERVAL 1 HOUR) WHERE subscription_id=? AND source=\'custom\'')->execute([$sid]);
$balBefore = (new WalletService())->getBalance($uid);
$rn = (new WalletService())->renewSubscriptionFromBalance($sid);
check(!empty($rn['success']) && abs($balBefore - (new WalletService())->getBalance($uid) - 109) < 0.01, 'renews at the custom price $109 (' . json_encode($rn) . ')');
check(PlanExtras::extra($sid, 'article_generation') === 20, 'custom extras granted again for the new period (+10) and pack still on (+10)');

// تنظيف
$pdo->exec("DELETE FROM plan_extra_credits WHERE user_id=$uid"); $pdo->exec("DELETE FROM plan_custom WHERE user_id=$uid");
foreach (['wallet_transactions', 'subscriptions', 'invoices'] as $tbl) { try { $pdo->exec("DELETE FROM $tbl WHERE user_id=$uid"); } catch (Throwable $e) {} }
$pdo->exec("DELETE FROM users WHERE id=$uid");
foreach ($plansBefore as $r) { $pdo->prepare('UPDATE subscription_plans SET name=?,price=?,ai_analysis_limit=?,ai_message_limit=?,review_auto_reply_limit=?,websites_limit=?,features_json=? WHERE id=?')->execute([$r['name'], $r['price'], $r['ai_analysis_limit'], $r['ai_message_limit'], $r['review_auto_reply_limit'], $r['websites_limit'], $r['features_json'], $r['id']]); }
foreach ($displayBefore as $r) { $pdo->prepare('UPDATE plan_pricing_display SET name=?,price_monthly=?,price_yearly=? WHERE id=?')->execute([$r['name'], $r['price_monthly'], $r['price_yearly'], $r['id']]); }
foreach ($keep as $k => $val) { $set->set($k, $val); }
echo "\n" . ($n - $fails) . "/$n passed\n";
exit($fails ? 1 : 0);
