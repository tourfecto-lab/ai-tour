<?php
/** Actual impact service and SQL; guarded disposable database, no provider calls. */
require __DIR__.'/fixtures/geo_database.php';
define('APP_URL','https://platform.example');
foreach(['2026_10_13_seo_impact.sql','2026_10_17_000004_local_links_reporting.sql'] as $file)$pdo->exec(file_get_contents($root.'/database/migrations/'.$file));
foreach(['seo_auto_impact_monitors','seo_impact_monitors','seo_impact_changes','seo_booking_sources','seo_ai_observations'] as $table)$pdo->exec('DELETE FROM '.$table);
$pdo->exec('CREATE TABLE IF NOT EXISTS platform_connections(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,website_id INT,platform VARCHAR(60),status VARCHAR(30),access_token TEXT,external_location_id VARCHAR(1000),token_expires_at DATETIME NULL)');
$pdo->exec('ALTER TABLE platform_connections ADD COLUMN IF NOT EXISTS external_location_id VARCHAR(1000)');
$pdo->exec('DELETE FROM platform_connections WHERE user_id IN (1,2)');
$n=0;function check($ok,$label):void{global $n;if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label.PHP_EOL;}
function denied($fn):bool{try{$fn();return false;}catch(InvalidArgumentException $e){return true;}}
$service=new SeoImpactService($db);
$empty=$service->overview(1,1);
check($empty['summary']['active_comparisons']===0&&$empty['summary']['next_collection_at']===null,'an empty service has no invented measurement or collection date');
check($empty['measurement_sources']['search']==='not_connected'&&$empty['measurement_sources']['analytics']==='not_connected','missing source connections remain explicit');
$insert=$pdo->prepare("INSERT INTO seo_impact_monitors(user_id,website_id,page_url,page_path,page_hash,change_date,anchor_source,window_days,status,result_json,next_due_at,created_at,updated_at) VALUES(?,?,?,?,?,?,'seo_expert_applied',7,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$date=gmdate('Y-m-d',time()-86400);$first=gmdate('Y-m-d H:i:s',time()+5*86400);$later=gmdate('Y-m-d H:i:s',time()+40*86400);
for($i=0;$i<25;$i++){$path='/page-'.$i;$status=$i===1?'reported':($i===2?'sources_unavailable':'queued');$insert->execute([1,1,'https://example.com'.$path,$path,hash('sha256',$path),$date,$status,$status==='reported'?'{}':null,$status==='queued'?($i===0?$first:$later):null]);}
$insert->execute([1,1,'https://example.com/old','/old',hash('sha256','/old'),$date,'superseded','{}',gmdate('Y-m-d H:i:s',time()+86400)]);
$insert->execute([2,2,'https://other.example/foreign','/foreign',hash('sha256','/foreign'),$date,'queued',null,gmdate('Y-m-d H:i:s',time()+86400)]);
$pdo->exec("INSERT INTO platform_connections(id,user_id,website_id,platform,status,external_location_id) VALUES(501,1,1,'google_search_console','connected','sc-domain:example.com'),(502,2,2,'google_analytics','connected','properties/999'),(503,1,1,'wordpress','connected','https://other.example')");
$snapshot=$service->overview(1,1);$summary=$snapshot['summary'];
check(count($snapshot['monitors'])===20&&$summary['history_count']===26,'existing latest-twenty detail limit is explicit while summary covers the full account-site history');
check($summary['active_comparisons']===25&&$summary['monitored_pages']===25,'superseded and foreign-site records are excluded from current measurement totals');
check($summary['collected_comparisons']===1&&$summary['waiting_comparisons']===23&&$summary['needs_review_comparisons']===1,'collected, pending and unavailable comparisons are counted separately');
check($summary['next_collection_at']===$first,'the earliest scheduled collection is found even when its monitor falls outside the displayed twenty');
check($snapshot['measurement_sources']['search']==='saved_connection'&&$snapshot['measurement_sources']['analytics']==='not_connected','source readiness stays scoped to this customer and website');
check($snapshot['measurement_sources']['wordpress_orders_available']===false,'a WordPress connection to a different host cannot enable paid-order setup');
check(array_keys($snapshot['measurement_sources'])===['search','analytics','wordpress_orders_available'],'readiness exposes no credentials or provider property identities');
check(denied(fn()=>$service->overview(2,1)),'a foreign owner cannot read outcome counts or connection readiness');
check(denied(fn()=>$service->configureBookings(1,1,true))&&(int)$pdo->query('SELECT COUNT(*) FROM seo_booking_sources')->fetchColumn()===0,'unsupported booking setup performs no source or tracking-start write');
$pdo->prepare("INSERT INTO seo_booking_sources VALUES(1,1,?,'https://example.com','receiving',1,'2026-09-01','2026-10-01')")->execute([(new Encryption())->encrypt(str_repeat('a',64))]);
$saved=$pdo->query('SELECT * FROM seo_booking_sources WHERE website_id=1')->fetch();
check(denied(fn()=>$service->configureBookings(1,1,true))&&$pdo->query('SELECT * FROM seo_booking_sources WHERE website_id=1')->fetch()===$saved,'a failed WordPress preflight preserves an existing receipt source and its tracking history');
foreach(['http://example.com','https://user:password@example.com','https://example.com/?preview=1'] as $target){
    $pdo->prepare("UPDATE platform_connections SET external_location_id=? WHERE user_id=1 AND website_id=1 AND platform='wordpress'")->execute([$target]);
    check(!$service->overview(1,1)['measurement_sources']['wordpress_orders_available']&&denied(fn()=>$service->configureBookings(1,1,true)),'unsafe or ambiguous WordPress targets fail preflight before receipt configuration');
}
$pdo->exec("UPDATE platform_connections SET external_location_id='https://example.com' WHERE user_id=1 AND website_id=1 AND platform='wordpress'");
check($service->overview(1,1)['measurement_sources']['wordpress_orders_available']===true,'a saved matching WordPress target permits an explicit configuration attempt without claiming plugin verification');
check((int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM wallet_transactions')->fetchColumn()===1,'readiness and rejected setup create no jobs or billing activity');
echo $n.' assertions passed'.PHP_EOL;
