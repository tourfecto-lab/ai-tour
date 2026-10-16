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
check(CurrencyService::forViewer(['country_code' => 'EG', 'currency' => 'EGP']) === 'USD', 'default is USD for everyone (foreign customers)');
$set->set('fx_auto_local', '1'); CurrencyService::resetCache();
check(CurrencyService::forViewer(['country_code' => 'SA']) === 'SAR', 'country currency only when the admin enables it');
$set->set('fx_auto_local', '0');
check(CurrencyService::egpHint(39) === '1,990 ج.م', 'EGP shown next to USD for the payment bank');

echo "plans\n";
$x = new PlanExtras();
check($x->applyPreset() === ['starter', 'professional', 'enterprise'], 'recommended plans applied');
$cat = BillingPlanCatalog::read();
check((float) $cat['starter']['price_monthly'] === 59.0 && (float) $cat['professional']['price_monthly'] === 149.0 && $cat['professional']['features']['articles'] === 20 && $cat['enterprise']['features']['media_videos'] === 12, 'catalog shows the new prices and allowances');

echo "trial\n";
$pdo->prepare("INSERT INTO users(company_name,email,password) VALUES('Trial Co',?,'x')")->execute(['plans' . getmypid() . '@example.com']);
$uid = (int) $pdo->lastInsertId();
check($x->trialEligible($uid), 'new account can start a trial');
check($x->config()['trial_days'] === 3, 'trial is 3 days by default (old 14-day setting ignored)');
try { $x->startTrial($uid); $ok = false; } catch (DomainException $e) { $ok = (json_decode($e->getMessage(), true)['error'] ?? '') === 'card_required'; }
check($ok && !Subscription::activeSubscriptionRow($uid), 'no card yet: trial does not start');
$pdo->prepare("INSERT INTO payment_transactions(internal_transaction_id,user_id,amount,currency,payment_method,gateway,status,reference,metadata) VALUES(?,?,149,'USD','card','xpay','succeeded',?,'{}')")->execute(['PTX-T' . $uid, $uid, 'XTX-T' . $uid]);
check($x->trialCardAmount() == 149.0, 'trial needs the first month ($149) paid by card');
check(!$x->trialNeedsCard($uid), 'card linked through XPay (first month paid)');
$t = $x->startTrial($uid);
check(abs(strtotime($t['ends_at']) - time() - 3 * 86400) < 60, 'trial ends after 3 days');
$sub = Subscription::activeSubscriptionRow($uid);
check($sub && $sub['status'] === 'trialing' && (float) $sub['price'] === 0.0 && $sub['plan_name'] === 'professional', 'trial is active on Growth, free');
check(!$x->trialEligible($uid), 'trial only once');
check(PlanUsage::quota($uid, 'article_generation')['limit'] === 2 && PlanUsage::quota($uid, 'media_video')['limit'] === 1 && PlanUsage::quota($uid, 'ai_tool')['limit'] === 20, 'small trial allowance per service (2 articles, 1 video, 20 AI tools)');
PlanUsage::consume($uid, 'article_generation'); PlanUsage::consume($uid, 'article_generation');
check(!PlanUsage::consume($uid, 'article_generation') && PlanUsage::quota($uid, 'article_generation')['remaining'] === 0, 'trial allowance used up: stops');
$pdo->exec("DELETE FROM plan_usage_events WHERE user_id=$uid");
$v0 = (new SubscriptionValidator())->validateSubscription($uid);
check(($v0['chat_credits_remaining'] ?? 0) === 100, 'trial chatbot replies capped at 100');

echo "add-ons\n";
try { $x->buyPack($uid, 'articles_10'); $ok = false; } catch (DomainException $e) { $ok = json_decode($e->getMessage(), true)['shortfall'] == 10; }
check($ok, 'no balance: clear shortfall');
(new WalletService())->adminAddBalance($uid, 400, $uid, 'test');
$r = $x->buyPack($uid, 'articles_10', 'k1');
check($r['qty'] === 10 && abs((new WalletService())->getBalance($uid) - 390) < 0.01, 'pack charged from wallet');
check($x->buyPack($uid, 'articles_10', 'k1')['already'] ?? false, 'same request not charged twice');
check(PlanUsage::quota($uid, 'article_generation')['limit'] === 12, 'pack adds on top of the trial cap');
$x->buyPack($uid, 'chat_2000');
$v = (new SubscriptionValidator())->validateSubscription($uid);
check(($v['chat_credits_remaining'] ?? 0) === 100 + 2000, 'chatbot replies grow by the pack (' . ($v['chat_credits_remaining'] ?? 'x') . ')');

echo "build your plan\n";
$q = $x->quote('professional', 'monthly', ['article_generation' => 10, 'chat' => 1000]);
check($q['extras_price'] == 20.0 && $q['total'] == 169.0, 'quote: $149 + 10 articles ($12) + 1000 replies ($8) = $169');
$qy = $x->quote('professional', 'yearly', ['article_generation' => 10]);
check($qy['extras_price'] == 120.0 && $qy['total'] == 1610.0, 'yearly extras = 10 months');
try { $x->quote('professional', 'monthly', ['article_generation' => 7]); $ok = false; } catch (InvalidArgumentException $e) { $ok = true; }
check($ok, 'quantities follow the step');
$balBefore = (new WalletService())->getBalance($uid);
$b = $x->buyCustom($uid, 'professional', 'monthly', ['article_generation' => 10, 'chat' => 1000], 'c1');
$sub = Subscription::activeSubscriptionRow($uid);
check($sub['status'] === 'active' && (float) $sub['price'] === 169.0, 'custom plan active at $169');
check(abs($balBefore - (new WalletService())->getBalance($uid) - 169) < 0.01, 'charged $169 (trial converted at full price)');
check(PlanUsage::quota($uid, 'article_generation')['limit'] === 20 + 10 + 10, 'paid: trial cap gone; plan 20 + custom 10 + earlier pack 10 kept');
$v = (new SubscriptionValidator())->validateSubscription($uid);
check(($v['chat_credits_remaining'] ?? 0) === 3500 + 1000 + 2000, 'chatbot replies: plan + custom + pack');

echo "renewal\n";
$sid = (int) $sub['id'];
$pdo->prepare('UPDATE subscriptions SET current_period_start=DATE_SUB(NOW(),INTERVAL 31 DAY), current_period_end=DATE_SUB(NOW(),INTERVAL 1 HOUR) WHERE id=?')->execute([$sid]);
$pdo->prepare('UPDATE plan_extra_credits SET expires_at=DATE_SUB(NOW(),INTERVAL 1 HOUR) WHERE subscription_id=? AND source=\'custom\'')->execute([$sid]);
(new WalletService())->adminAddBalance($uid, 200, $uid, 'test');
$balBefore = (new WalletService())->getBalance($uid);
$rn = (new WalletService())->renewSubscriptionFromBalance($sid);
check(!empty($rn['success']) && abs($balBefore - (new WalletService())->getBalance($uid) - 169) < 0.01, 'renews at the custom price $169 (' . json_encode($rn) . ')');
check(PlanExtras::extra($sid, 'article_generation') === 20, 'custom extras granted again for the new period (+10) and pack still on (+10)');

echo "after the trial: automatic charge\n";
$mk = function (string $tag) use ($pdo, $x) {
    $pdo->prepare("INSERT INTO users(company_name,email,password) VALUES(?,?,'x')")->execute([$tag, $tag . getmypid() . '@example.com']);
    $id = (int) $pdo->lastInsertId();
    $x->startTrial($id, true);
    $pdo->prepare("UPDATE subscriptions SET trial_ends_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE), current_period_end=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE user_id=?")->execute([$id]);
    return $id;
};
$a1 = $mk('Auto Co'); (new WalletService())->adminAddBalance($a1, 149, $a1, 'first month via card');
$a2 = $mk('Cancel Co'); (new WalletService())->adminAddBalance($a2, 149, $a2, 'first month via card');
$pdo->prepare("UPDATE subscriptions SET trial_ends_at=DATE_ADD(NOW(),INTERVAL 1 DAY) WHERE user_id=?")->execute([$a2]);
check($x->cancelTrialRenewal($a2), 'customer can cancel renewal during the trial');
$pdo->prepare("UPDATE subscriptions SET trial_ends_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE user_id=?")->execute([$a2]);
$a3 = $mk('Empty Co');
(new SubscriptionLifecycleService())->runLifecycleChecks();
$s1 = Subscription::activeSubscriptionRow($a1);
check($s1 && $s1['status'] === 'active' && (float) $s1['price'] === 149.0 && abs((new WalletService())->getBalance($a1)) < 0.01, 'trial ended: $149 charged automatically, plan active');
check(!Subscription::activeSubscriptionRow($a2) && abs((new WalletService())->getBalance($a2) - 149) < 0.01, 'cancelled before the end: no charge, $149 stays in the wallet');
check($pdo->query("SELECT status FROM subscriptions WHERE user_id=$a3 ORDER BY id DESC LIMIT 1")->fetchColumn() === 'past_due', 'no balance: past due (services stop after grace)');
foreach ([$a1, $a2, $a3] as $id) { foreach (['wallet_transactions', 'subscriptions', 'invoices', 'plan_usage_events'] as $tbl) { try { $pdo->exec("DELETE FROM $tbl WHERE user_id=$id"); } catch (Throwable $e) {} } $pdo->exec("DELETE FROM users WHERE id=$id"); }

echo "new prices applied automatically\n";
$set->set('plans_preset_auto', '');
foreach (['starter' => 49, 'professional' => 99, 'enterprise' => 299] as $k => $p) { $pdo->prepare("UPDATE subscription_plans SET price=? WHERE plan_code=?")->execute([$p, $k . '_monthly']); $pdo->prepare("UPDATE plan_pricing_display SET price_monthly=? WHERE plan_key=?")->execute([$p, $k]); }
check($x->autoApplyPresetIfLegacy() && (float) BillingPlanCatalog::read()['starter']['price_monthly'] === 59.0, 'old $49/$99/$299 prices replaced by $59/$149/$349 once');
check(!$x->autoApplyPresetIfLegacy(), 'only once (admin edits are kept afterwards)');
$set->set('plans_preset_auto', '');

echo "admin grant without card\n";
$pdo->prepare("INSERT INTO users(company_name,email,password) VALUES('NoCard Co',?,'x')")->execute(['nocard' . getmypid() . '@example.com']);
$u2 = (int) $pdo->lastInsertId();
check($x->trialNeedsCard($u2), 'new customer without card needs the card step');
$g = $x->startTrial($u2, true);
check(Subscription::activeSubscriptionRow($u2)['status'] === 'trialing', 'admin started the trial without a card');
try { $x->startTrial($u2, true); $ok = false; } catch (InvalidArgumentException $e) { $ok = true; }
check($ok, 'admin cannot stack a second trial');
$x->saveConfig(['trial_caps' => ['article_generation' => 5]]);
check(PlanUsage::quota($u2, 'article_generation')['limit'] === 5, 'admin can change a trial allowance');
$x->saveConfig(['trial_days' => 7]);
check($x->config()['trial_days'] === 7, 'admin can change trial days');
$pdo->exec("DELETE FROM plan_usage_events WHERE user_id=$u2"); $pdo->exec("DELETE FROM subscriptions WHERE user_id=$u2"); $pdo->exec("DELETE FROM users WHERE id=$u2");

// تنظيف
$pdo->exec("DELETE FROM plan_extra_credits WHERE user_id=$uid"); $pdo->exec("DELETE FROM plan_custom WHERE user_id=$uid");
foreach (['wallet_transactions', 'subscriptions', 'invoices', 'payment_transactions', 'plan_usage_events'] as $tbl) { try { $pdo->exec("DELETE FROM $tbl WHERE user_id=$uid"); } catch (Throwable $e) {} }
$pdo->exec("DELETE FROM users WHERE id=$uid");
foreach ($plansBefore as $r) { $pdo->prepare('UPDATE subscription_plans SET name=?,price=?,ai_analysis_limit=?,ai_message_limit=?,review_auto_reply_limit=?,websites_limit=?,features_json=? WHERE id=?')->execute([$r['name'], $r['price'], $r['ai_analysis_limit'], $r['ai_message_limit'], $r['review_auto_reply_limit'], $r['websites_limit'], $r['features_json'], $r['id']]); }
foreach ($displayBefore as $r) { $pdo->prepare('UPDATE plan_pricing_display SET name=?,price_monthly=?,price_yearly=? WHERE id=?')->execute([$r['name'], $r['price_monthly'], $r['price_yearly'], $r['id']]); }
foreach ($keep as $k => $val) { $set->set($k, $val); }
echo "\n" . ($n - $fails) . "/$n passed\n";
exit($fails ? 1 : 0);
