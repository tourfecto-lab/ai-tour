<?php
/** Actual ledger/research/quote SQL with fake provider only; guarded disposable MariaDB. */
require __DIR__.'/fixtures/geo_database.php';
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
function denied(callable $fn){try{$fn();return false;}catch(InvalidArgumentException $e){return true;}}
SeoDataSchema::ensureSchema($db);$market=(new SeoMarketService($db))->resolve(1,1);$mid=(int)$market['id'];
$settings=new SystemSettingsService();foreach(['serp_enabled'=>'1','serp_login'=>'fixture','serp_password'=>'fixture','serp_monthly_budget_usd'=>'10'] as $key=>$value)$settings->set($key,$value);
$pdo->exec("INSERT INTO wallet_transactions(user_id,type,amount,currency,status) VALUES(1,'deposit',10,'USD','completed')");
for($i=1;$i<=5;$i++)$pdo->prepare('INSERT INTO tracked_keywords(website_id,user_id,market_id,keyword) VALUES(1,1,?,?)')->execute([$mid,'confirmed tour '.$i]);
$requests=[];$provider=function($endpoint,$tasks)use(&$requests){$requests[]=[$endpoint,$tasks];$items=[];foreach($tasks[0]['keywords'] as $i=>$word)$items[]=str_contains($endpoint,'difficulty')?['keyword'=>$word,'keyword_difficulty'=>12]:['keyword'=>$word,'keyword_info'=>['search_volume'=>$i===0?0:100,'cpc'=>0.2]];return ['status_code'=>20000,'cost'=>0.002,'tasks'=>[['status_code'=>20000,'cost'=>0.002,'result'=>[['items'=>$items]]]]];};
$factory=fn($ledger)=>new DataForSeoClient(null,$provider,$ledger);$s=new SeoKeywordMeasurementService($db,$factory);$quote=$s->quote(1,1,$mid);
check($quote['available']&&$quote['max_units']===10&&count($quote['keywords'])===5,'free quote bounds demand and difficulty to five saved owned words and ten units');
check($quote['wallet_max']===0.4&&$quote['wallet_unit_price']===0.05,'quote combines two plan units with eight priced wallet units');
check(count($requests)===0&&(int)$pdo->query('SELECT COUNT(*) FROM dataforseo_calls')->fetchColumn()===0,'reading a quote creates no provider reservation');
check(denied(fn()=>$s->quote(2,1,$mid))&&denied(fn()=>$s->quote(1,1,999)),'foreign owner and foreign market cannot quote or spend');
$input=['reviewed'=>true,'quote_hash'=>$quote['quote_hash'],'max_wallet_charge'=>0.4];
check(denied(fn()=>$s->measure(1,1,$mid,array_replace($input,['reviewed'=>false]))),'unreviewed measurement is rejected');
check(denied(fn()=>$s->measure(1,1,$mid,array_replace($input,['max_wallet_charge'=>0.39])))&&!$requests,'insufficient accepted cap is rejected before quota or transport');
check(denied(fn()=>$s->measure(1,1,$mid,array_replace($input,['quote_hash'=>'stale'])))&&!$requests,'stale quote is rejected before transport');
$pdo->exec("UPDATE pay_per_use_pricing SET price=0.10 WHERE feature_key='seo_data'");
check(denied(fn()=>$s->measure(1,1,$mid,$input))&&!$requests,'price changes invalidate the displayed quote');$pdo->exec("UPDATE pay_per_use_pricing SET price=0.05 WHERE feature_key='seo_data'");
$before=(new WalletService())->getBalance(1);$r=$s->measure(1,1,$mid,$input);$after=(new WalletService())->getBalance(1);
check($r['available']&&count($r['data'])===5&&$r['wallet_charged']===0.4&&abs($before-$after-0.4)<0.000001,'actual research and difficulty share one hard wallet cap');
check(count($requests)===2&&$requests[0][0]==='dataforseo_labs/google/keyword_overview/live','the bounded path performs exact overview then difficulty, not broad discovery');
check($r['data'][0]['volume']===0&&(int)$pdo->query('SELECT COUNT(*) FROM seo_keyword_research')->fetchColumn()===5,'measured zero volume and real source records survive');
check($s->quote(1,1,$mid)['already_measured']&&$s->quote(1,1,$mid)['max_units']===0,'fresh demand disappears from the next paid batch');
$again=$s->quote(1,1,$mid);$s->measure(1,1,$mid,['reviewed'=>true,'quote_hash'=>$again['quote_hash'],'max_wallet_charge'=>0]);check(count($requests)===2,'fresh batch retry requires no new transport or wallet charge');
// Provider-side price changes between the two HTTP calls remain within one cap.
$pdo->prepare('INSERT INTO tracked_keywords(website_id,user_id,market_id,keyword) VALUES(1,1,?,?)')->execute([$mid,'confirmed tour 6']);
$changedCalls=0;$changing=function($endpoint,$tasks)use(&$changedCalls,$pdo,$provider){$changedCalls++;if(str_contains($endpoint,'keyword_overview'))$pdo->exec("UPDATE pay_per_use_pricing SET price=0.25 WHERE feature_key='seo_data'");return $provider($endpoint,$tasks);};
$bounded=new SeoKeywordMeasurementService($db,fn($ledger)=>new DataForSeoClient(null,$changing,$ledger));$q=$bounded->quote(1,1,$mid);$r=$bounded->measure(1,1,$mid,['reviewed'=>true,'quote_hash'=>$q['quote_hash'],'max_wallet_charge'=>0.10]);
check(!$r['available']&&$r['reason']==='seo_wallet_limit_reached'&&$r['wallet_charged']===0.05&&$changedCalls===1,'mid-operation price increase stops before second HTTP call and cannot exceed approved cap');
check((int)$pdo->query('SELECT COUNT(*) FROM seo_keyword_research')->fetchColumn()===5,'partial provider work never fabricates completed keyword measurements or erases prior records');$pdo->exec("UPDATE pay_per_use_pricing SET price=0.05 WHERE feature_key='seo_data'");
// Per-call SQL failures roll back BOTH plan and wallet charges. Prior successful calls stay recorded.
$pdo->exec('DELETE FROM dataforseo_calls');$pdo->exec('DELETE FROM plan_usage_events');$count=(int)$pdo->query('SELECT COUNT(*) FROM wallet_transactions')->fetchColumn();$ledger=new SeoDataLedger($db,0.1);$failed=false;try{$ledger->reserve('fixture',1,5,0.1,10);}catch(RuntimeException $e){$failed=$e->getMessage()==='seo_wallet_limit_reached';}
check($failed&&$ledger->walletSpent()===0.0&&(int)$pdo->query('SELECT COUNT(*) FROM plan_usage_events')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM wallet_transactions')->fetchColumn()===$count,'cap reached midway through a reservation rolls back all its charges');
$ledger=new SeoDataLedger($db,0);$id=$ledger->reserve('fixture',1,2,0.1,10);$ledger->settle($id,0,'complete');check($ledger->walletSpent()===0.0&&(int)$pdo->query('SELECT COUNT(*) FROM plan_usage_events')->fetchColumn()===2,'zero wallet cap permits available plan-only units');
$failed=false;try{$ledger->reserve('fixture',1,1,0.1,10);}catch(RuntimeException $e){$failed=$e->getMessage()==='seo_wallet_limit_reached';}check($failed&&(int)$pdo->query('SELECT COUNT(*) FROM wallet_transactions')->fetchColumn()===$count,'quota exhaustion cannot silently fall back to wallet under zero cap');
$pdo->exec("UPDATE pay_per_use_pricing SET currency='EUR' WHERE feature_key='seo_data'");$failed=false;try{(new SeoDataLedger($db,1))->reserve('fixture',1,1,0.1,10);}catch(RuntimeException $e){$failed=$e->getMessage()==='seo_wallet_price_unavailable';}check($failed,'unsupported wallet currency is rejected without treating its price as USD');
check((int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0,'keyword measurement never publishes content or starts a background SEO cycle');
// Discovery and rank/gap routes use the same actual ledger and reviewed operation.
$pdo->exec("UPDATE pay_per_use_pricing SET currency='USD' WHERE feature_key='seo_data'");
$modeRequests=[];$modeProvider=function($endpoint,$tasks)use(&$modeRequests){$modeRequests[]=[$endpoint,$tasks];$items=[];
 if(str_contains($endpoint,'bulk_keyword_difficulty'))foreach($tasks[0]['keywords'] as $word)$items[]=['keyword'=>$word,'keyword_difficulty'=>9];
 elseif(str_contains($endpoint,'keywords_for_site'))for($i=1;$i<=20;$i++)$items[]=['keyword'=>'real discovery '.$i,'keyword_info'=>['search_volume'=>50]];
 elseif(str_contains($endpoint,'ranked_keywords'))$items[]=['keyword_data'=>['keyword'=>'real gap query','keyword_info'=>['search_volume'=>60]],'ranked_serp_element'=>['serp_item'=>['rank_group'=>3,'url'=>'https://rival.test/tour']]];
 elseif(str_starts_with($endpoint,'serp/'))$items=[]; // Measured customer absence within the provider's top 100.
 return ['status_code'=>20000,'cost'=>0.001,'tasks'=>[['status_code'=>20000,'cost'=>0.001,'result'=>[['items'=>$items]]]]];};
$modes=new SeoKeywordMeasurementService($db,fn($ledger)=>new DataForSeoClient(null,$modeProvider,$ledger));
$q=$modes->quote(1,1,$mid,'discovery');$input=['reviewed'=>true,'quote_hash'=>$q['quote_hash'],'max_wallet_charge'=>1,'operation'=>'discover_site'];
check(denied(fn()=>$modes->measure(1,1,$mid,array_replace($input,['operation'=>'rank'])))&&!$modeRequests,'changing the operation invalidates the signed quote before spending');
$r=$modes->measure(1,1,$mid,$input);
check($r['available']&&count($r['data'])===5&&$r['max_units']===6&&$r['wallet_charged']===0.3,'discovery ignores oversized provider results and bills at most one site plus five difficulty units');
check((int)$pdo->query("SELECT COUNT(*) FROM tracked_keywords WHERE keyword LIKE 'real discovery %' AND website_id=1 AND user_id=1 AND market_id=".$mid)->fetchColumn()===5,'only actual bounded discovery words become owned tracked keywords');
$q=$modes->quote(1,1,$mid,'rank');$r=$modes->measure(1,1,$mid,['reviewed'=>true,'quote_hash'=>$q['quote_hash'],'max_wallet_charge'=>1,'operation'=>'rank']);
check($q['max_units']===5&&$r['available']&&$r['recorded']===5&&$r['wallet_charged']===0.25,'rank operation reads and measures only the five displayed due keywords');
check((int)$pdo->query('SELECT COUNT(*) FROM seo_rank_tracking_history WHERE website_id=1 AND user_id=1 AND market_id='.$mid.' AND position IS NULL')->fetchColumn()===5,'a measured top-100 absence stays null in append-only market-specific history');
$pdo->exec("INSERT INTO competitors(id,website_id,user_id,competitor_domain,monitoring_paused) VALUES(1,1,1,'rival.test',0),(2,1,1,'paused.test',1),(3,2,2,'foreign.test',0)");
$q=$modes->quote(1,1,$mid,'gap');$r=$modes->measure(1,1,$mid,['reviewed'=>true,'quote_hash'=>$q['quote_hash'],'max_wallet_charge'=>3,'operation'=>'gap']);
check($q['max_units']===51&&count($q['competitors'])===1&&$r['available']&&count($r['gaps'])===1&&$r['wallet_charged']===0.1,'gap quote excludes paused and foreign competitors and verifies customer absence before claiming a gap');
$secondary=(new SeoMarketService($db))->add(1,1,['location_code'=>2826,'language_code'=>'en','label'=>'UK English']);
check(denied(fn()=>$modes->quote(1,1,(int)$secondary['id'],'gap')),'gap operation refuses a secondary market before quoting or billing');
// Automatic/default requests are plan-only until the owner explicitly saves a monthly limit.
$pdo->exec('DROP TABLE IF EXISTS seo_expert_sites');$pdo->exec('CREATE TABLE seo_expert_sites(website_id INT PRIMARY KEY,user_id INT,policy_json TEXT)');
$pdo->exec('ALTER TABLE wallet_transactions ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP');
$auto=new SeoDataLedger($db);$before=(new WalletService())->getBalance(1);$failed=false;
try{$auto->reserve('auto-fixture',1,1,0.1,10);}catch(RuntimeException $e){$failed=$e->getMessage()==='seo_auto_wallet_limit_reached';}
check($failed&&(new WalletService())->getBalance(1)===$before,'without saved policy automatic requests never fall back to wallet');
$pdo->exec("INSERT INTO seo_expert_sites VALUES(1,1,'{\"max_data_wallet_monthly\":0.10}')");
for($i=0;$i<2;$i++){$id=$auto->reserve('auto-fixture',1,1,0.1,10);$auto->settle($id,0,'complete');}
$failed=false;try{(new SeoDataLedger($db))->reserve('auto-fixture',1,1,0.1,10);}catch(RuntimeException $e){$failed=$e->getMessage()==='seo_auto_wallet_limit_reached';}
check($failed&&$auto->walletSpent()===0.1&&abs($before-(new WalletService())->getBalance(1)-0.1)<0.000001,'two automatic calls exhaust the shared site monthly cap across ledger instances without double counting');
$id=(new SeoDataLedger($db,0.05))->reserve('manual-fixture',1,1,0.1,10);
check(abs($before-(new WalletService())->getBalance(1)-0.15)<0.000001,'separately approved manual cap remains independent of the automatic monthly cap');
$pdo->exec('DELETE FROM plan_usage_events');$id=$auto->reserve('auto-fixture',1,2,0.1,10);
check($id>0&&$auto->walletSpent()===0.1,'monthly wallet exhaustion preserves remaining plan allowance');
$pdo->exec('DROP TABLE seo_expert_sites');
echo "$n assertions passed\n";
