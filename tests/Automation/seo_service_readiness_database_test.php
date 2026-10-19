<?php
/** Real owned/read-only source assembly; no HTTP, email or paid provider. */
require __DIR__.'/fixtures/geo_database.php';
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}function denied($fn){try{$fn();return false;}catch(InvalidArgumentException $e){return true;}}
foreach(['seo_expert_sites','seo_expert_tasks','platform_connections','seo_report_schedules'] as $t)$pdo->exec('DROP TABLE IF EXISTS '.$t);
$pdo->exec('CREATE TABLE seo_expert_sites(website_id INT,user_id INT,brief_json TEXT,policy_json TEXT,status VARCHAR(30))');$pdo->exec('CREATE TABLE seo_expert_tasks(id INT,website_id INT,user_id INT,status VARCHAR(30))');$pdo->exec('CREATE TABLE platform_connections(id INT,user_id INT,website_id INT,platform VARCHAR(60),status VARCHAR(30))');$pdo->exec('CREATE TABLE seo_report_schedules(website_id INT,user_id INT,is_active INT)');
$pdo->exec(file_get_contents($root.'/database/migrations/2026_10_13_seo_impact.sql'));$pdo->exec('DELETE FROM seo_impact_monitors');
SeoDataSchema::ensureSchema($db);$market=(new SeoMarketService($db))->resolve(1,1);$mid=(int)$market['id'];
$facts=['business_name'=>'Confirmed Travel','business_facts'=>'Confirmed Luxor tours','source_site_url'=>'https://example.com'];$profile=['version'=>1,'facts'=>$facts,'confirmed_at'=>'2026-10-01','needs_reconfirmation'=>false];$brief=['source_site_url'=>'https://example.com','shared_profile'=>$profile];
$pdo->prepare("INSERT INTO business_hub_profiles(website_id,user_id,version,facts_json,confirmed_at) VALUES(1,1,1,?,UTC_TIMESTAMP())")->execute([json_encode($facts)]);$pdo->exec('ALTER TABLE business_hub_profiles ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL');
$pdo->prepare("INSERT INTO seo_expert_sites VALUES(1,1,?,'{}','active')")->execute([json_encode($brief)]);
$pdo->exec("INSERT INTO platform_connections VALUES(1,1,1,'google_search_console','connected'),(2,2,2,'google_analytics','connected'); INSERT INTO seo_report_schedules VALUES(2,2,1); INSERT INTO seo_expert_tasks VALUES(1,1,1,'pending'),(2,2,2,'pending');");
$pdo->prepare('INSERT INTO tracked_keywords(website_id,user_id,market_id,keyword) VALUES(1,1,?,?)')->execute([$mid,'Luxor tours (Commercial, High)']);
$pdo->prepare("INSERT INTO seo_keyword_research(website_id,market_id,keyword,volume,source,fetched_at) VALUES(1,?,'Luxor tours',0,'dataforseo',UTC_TIMESTAMP())")->execute([$mid]);
$service=new SeoServiceReadiness($db);$r=$service->inspect(1,1);$checks=array_column($r['checks'],null,'id');
check($r['website_id']===1&&$r['basis']==='stored_records'&&!isset($r['score']), 'readiness exposes its recorded evidence basis and no invented SEO score');
check($checks['facts']['state']==='recorded'&&$checks['setup']['state']==='recorded','current confirmed source identity and matching SEO setup are recognized');
check($checks['connector']['state']==='check'&&$checks['search']['state']==='saved','saved connection does not become an invented live connector or Google verification');
check($checks['analytics']['state']==='action'&&$checks['reports']['state']==='action','another customer connection or report cannot satisfy this site');
check($checks['research']['counts']['measured']===1&&$checks['research']['counts']['invalid']===1&&$checks['research']['state']==='action','measured zero volume is retained while annotated input still requires review');
check($checks['rankings']['state']==='action'&&$checks['impact']['state']==='action','no rank or collected impact is invented from stored keyword demand');
check($checks['plan']['pending_count']===1,'pending plan work stays site-and-account scoped');
check(denied(fn()=>$service->inspect(2,1)),'foreign owner is rejected before source assembly');
$pdo->exec('UPDATE business_hub_profiles SET version=2 WHERE website_id=1');$after=array_column($service->inspect(1,1)['checks'],null,'id');check($after['setup']['state']==='action','changed business facts invalidate old setup readiness');
$pdo->prepare('UPDATE business_hub_profiles SET facts_json=? WHERE website_id=1')->execute([json_encode(array_replace($facts,['source_site_url'=>'https://other.example']))]);$after=array_column($service->inspect(1,1)['checks'],null,'id');check($after['facts']['state']==='action','a different customer domain cannot supply confirmed facts');
check((int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM dataforseo_calls')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM wallet_transactions')->fetchColumn()===1,'readiness creates no job, provider call or charge');
foreach(['seo_expert_sites','seo_expert_tasks','platform_connections','seo_report_schedules'] as $t)$pdo->exec('DROP TABLE IF EXISTS '.$t);
echo "$n assertions passed\n";
