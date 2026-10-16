<?php
/**
 * سعر الصرف مربوط بالبنك المركزي: قراية الجدول، الحساب، الميعاد، البديل، والحماية من الأرقام الغلط.
 * DB_NAME=tourfecto_test DB_USER=tf DB_PASS=tfpass DB_HOST=127.0.0.1 ENCRYPTION_KEY=... php tests/Automation/fx_bank_test.php
 */
require_once __DIR__ . '/../../cron/bootstrap.php';
require_once ROOT_PATH . '/app/Services/Payment/CurrencyService.php';
$fails = 0; $n = 0;
function check($c, $m) { global $fails, $n; $n++; echo ($c ? '  ok  ' : '  FAIL ') . $m . "\n"; if (!$c) $fails++; }
$set = new SystemSettingsService();
$keep = ['fx_rates' => $set->get('fx_rates', ''), 'fx_auto' => $set->get('fx_auto', '')];
$set->set('fx_rates', ''); $set->set('fx_auto', ''); CurrencyService::resetCache();

$cbe = '<html><body><script>var x="US Dollar 1.00 2.00";</script><table class="table"><thead><tr><th>Currency</th><th>Buy</th><th>Sell</th></tr></thead><tbody>'
    . '<tr><td>US Dollar</td><td>48.6500</td><td>48.7500</td></tr>'
    . '<tr><td>Euro</td><td>&nbsp;52.9800</td><td>53.1200</td></tr>'
    . '<tr><td>Pound Sterling</td><td>61.4000</td><td>61.5700</td></tr>'
    . '<tr><td>Saudi Riyal</td><td>12.9600</td><td>12.9900</td></tr>'
    . '</tbody></table></body></html>';
$t = CurrencyService::parseCbe($cbe);
check(($t['USD'] ?? null) === ['buy' => 48.65, 'sell' => 48.75] && isset($t['EUR'], $t['GBP'], $t['SAR']), 'reads the CBE table (ignores scripts)');
$r = CurrencyService::ratesFromCbe($t, 'sell');
check($r['EGP'] == 48.75 && abs($r['EUR'] - 48.75 / 53.12) < 0.000001 && abs($r['SAR'] - 48.75 / 12.99) < 0.000001, 'EGP = sell rate; other currencies derived from the EGP rates');
check(CurrencyService::ratesFromCbe($t, 'mid')['EGP'] == 48.7, 'mid rate option');
check(CurrencyService::parseCbe('<p>maintenance</p>') === [], 'unknown page: nothing read');

$calls = [];
$fetch = function ($src) use (&$calls, &$cbe) { $calls[] = $src; return $src === 'cbe' ? $cbe : ['EGP' => 49.9, 'SAR' => 3.75]; };
check(CurrencyService::autoRefresh(false, $fetch)['ran'] === false && !$calls, 'auto link off: does nothing');
CurrencyService::saveRates(['EGP' => ['base' => 50, 'locked' => true]]);
CurrencyService::saveAutoConfig(['enabled' => true, 'source' => 'cbe', 'side' => 'sell', 'every' => 15]);
check(CurrencyService::rates()['EGP']['locked'] === false, 'turning on the bank link unlocks the manual EGP rate');
$x = CurrencyService::autoRefresh(false, $fetch);
check($x['source'] === 'cbe' && in_array('EGP', $x['updated'], true) && CurrencyService::rate('EGP') == 48.75 && CurrencyService::rates()['EGP']['source'] === 'cbe', 'EGP updated from the Central Bank (48.75)');
$calls = [];
check(CurrencyService::autoRefresh(false, $fetch)['ran'] === false && !$calls, 'not due yet (every 15 min): skipped');
$c = json_decode($set->get('fx_auto'), true); $c['last_run'] = date('Y-m-d H:i:s', time() - 16 * 60); $set->set('fx_auto', json_encode($c));
$cbe = str_replace('48.7500', '48.9000', $cbe);
check(CurrencyService::autoRefresh(false, $fetch)['ran'] && CurrencyService::rate('EGP') == 48.9, 'after 15 min: follows the bank (48.90)');
// موقع البنك واقع: سعر السوق مؤقتًا
$c['last_run'] = date('Y-m-d H:i:s', time() - 3600); $set->set('fx_auto', json_encode($c));
$down = fn ($src) => $src === 'cbe' ? null : ['EGP' => 49.1];
$x = CurrencyService::autoRefresh(false, $down);
check($x['source'] === 'market' && CurrencyService::rate('EGP') == 49.1 && str_contains(CurrencyService::autoConfig()['last_error'], 'البنك المركزي'), 'bank site down: market rate used and the admin is told');
// قراية غلط (فرق كبير مرة واحدة) مابتتسجلش
$c = json_decode($set->get('fx_auto'), true); $c['last_run'] = date('Y-m-d H:i:s', time() - 3600); $set->set('fx_auto', json_encode($c));
$x = CurrencyService::autoRefresh(false, fn ($src) => $src === 'cbe' ? str_replace(['48.6500', '48.9000'], ['4.8650', '4.8900'], $cbe) : null);
check(CurrencyService::rate('EGP') == 49.1 && in_array('EGP', $x['skipped'], true), 'sudden 90% jump ignored (bad reading)');
CurrencyService::saveRates(['EGP' => ['margin' => 2]]);
$c = json_decode($set->get('fx_auto'), true); $c['last_run'] = date('Y-m-d H:i:s', time() - 3600); $set->set('fx_auto', json_encode($c));
CurrencyService::autoRefresh(false, $fetch);
check(abs(CurrencyService::rate('EGP') - 48.9 * 1.02) < 0.0001, 'admin margin applies on top of the bank rate');
check(CurrencyService::fromUsd(59, 'EGP') == 2945.0, '$59 shows as 2,945 EGP');
$x = CurrencyService::autoRefresh(true, fn ($s) => null);
check(!empty($x['error']), 'both sources down: clear error, old rate kept (' . CurrencyService::rate('EGP') . ')');

foreach ($keep as $k => $v) $set->set($k, $v);
echo "\n" . ($n - $fails) . "/$n passed\n";
exit($fails ? 1 : 0);
