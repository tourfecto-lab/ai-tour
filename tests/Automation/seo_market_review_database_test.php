<?php
/** Actual SQL: choosing an initial country must not require buying another market or rewriting history. */
require __DIR__.'/fixtures/geo_database.php';
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}function denied($fn){try{$fn();return false;}catch(InvalidArgumentException $e){return true;}}
if($pdo->query("SHOW TABLES LIKE 'seo_content_quality'")->fetchColumn())$pdo->exec('DELETE FROM seo_content_quality WHERE website_id=1 AND user_id=1');
$pdo->exec('DROP TABLE IF EXISTS seo_ai_prompt_sets');
SeoDataSchema::ensureSchema($db);$pdo->exec("UPDATE subscription_plans SET features_json='{\"seo_markets\":1}'");$service=new SeoMarketService($db);$market=$service->resolve(1,1);$mid=(int)$market['id'];
$pdo->prepare("INSERT INTO tracked_keywords(website_id,user_id,market_id,keyword,target_page) VALUES(1,1,?,'Luxor tours','https://example.com/tours')")->execute([$mid]);$before=$pdo->query('SELECT * FROM tracked_keywords')->fetchAll(PDO::FETCH_ASSOC);
$input=['reviewed'=>true,'location_code'=>2826,'language_code'=>'en','label'=>'UK English'];$r=$service->replaceUnmeasured(1,1,$mid,$input);
check((int)$r['id']===$mid&&(int)$r['location_code']===2826&&count($service->markets(1,1))===1,'single-market plan can explicitly correct its untouched default country');
check($pdo->query('SELECT * FROM tracked_keywords')->fetchAll(PDO::FETCH_ASSOC)===$before,'changing an unmeasured target preserves keyword IDs, content and page mappings');
check(denied(fn()=>$service->replaceUnmeasured(2,1,$mid,$input))&&denied(fn()=>$service->replaceUnmeasured(1,1,$mid,array_replace($input,['reviewed'=>false]))),'foreign owner and absent review cannot change target geography');
$pdo->prepare("INSERT INTO seo_keyword_research(website_id,market_id,keyword,volume,source,fetched_at) VALUES(1,?,'Luxor tours',0,'dataforseo',UTC_TIMESTAMP())")->execute([$mid]);
check(denied(fn()=>$service->replaceUnmeasured(1,1,$mid,array_replace($input,['location_code'=>2840]))),'measured zero demand prevents relabeling the country');$pdo->exec('DELETE FROM seo_keyword_research');
$pdo->prepare("INSERT INTO seo_research_cache VALUES(1,?,?,'[]',UTC_TIMESTAMP())")->execute([$mid,str_repeat('a',64)]);check(denied(fn()=>$service->replaceUnmeasured(1,1,$mid,$input)),'even cached empty observations retain their original market identity');$pdo->exec('DELETE FROM seo_research_cache');
$pdo->exec('UPDATE tracked_keywords SET last_checked_at=UTC_TIMESTAMP()');check(denied(fn()=>$service->replaceUnmeasured(1,1,$mid,$input)),'stored keyword check timestamp prevents geography rewrite');$pdo->exec('UPDATE tracked_keywords SET last_checked_at=NULL');
$pdo->prepare("INSERT INTO seo_rank_tracking_history(website_id,user_id,market_id,keyword,position,source,checked_at) VALUES(1,1,?,'Luxor tours',NULL,'fixture',UTC_TIMESTAMP())")->execute([$mid]);check(denied(fn()=>$service->replaceUnmeasured(1,1,$mid,$input)),'measured absence in historical ranks cannot be renamed');$pdo->exec('DELETE FROM seo_rank_tracking_history');
$pdo->exec('CREATE TABLE IF NOT EXISTS seo_ai_prompt_sets(id INT PRIMARY KEY,market_id INT)');$pdo->exec('DELETE FROM seo_ai_prompt_sets');$pdo->exec('INSERT INTO seo_ai_prompt_sets(id,market_id) VALUES(123,'.$mid.')');check(denied(fn()=>$service->replaceUnmeasured(1,1,$mid,$input)),'approved or configured AI market work is not silently moved');$pdo->exec('DROP TABLE seo_ai_prompt_sets');
check((int)$pdo->query('SELECT COUNT(*) FROM dataforseo_calls')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0,'market review does not spend, generate content or queue a cycle');
echo "$n assertions passed\n";
