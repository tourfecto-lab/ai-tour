<?php
require __DIR__.'/fixtures/geo_database.php';
$n=0;$fails=0;function check($ok,$name){global $n,$fails;$n++;if(!$ok)$fails++;echo($ok?'  ok  ':'  FAIL ').$name.PHP_EOL;}function denied(callable $fn):bool{try{$fn();return false;}catch(RuntimeException|InvalidArgumentException $e){return true;}}
$pdo->exec(file_get_contents($root.'/database/migrations/2026_10_12_000002_seo_expert.sql'));
$pdo->exec(file_get_contents($root.'/database/migrations/2026_10_17_000003_content_quality.sql'));
$pdo->exec('DELETE FROM seo_content_quality;DELETE FROM seo_expert_tasks;DELETE FROM seo_expert_runs;DELETE FROM seo_expert_sites');
$pdo->exec('ALTER TABLE business_hub_profiles ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL');
$profile=['business_name'=>'Nile Travel','offers'=>'USD 65','source_site_url'=>'https://example.com'];
$pdo->prepare('INSERT INTO business_hub_profiles(website_id,user_id,version,facts_json,confirmed_at) VALUES(1,1,1,?,UTC_TIMESTAMP())')->execute([json_encode($profile)]);
$quality=new ContentQualityService($db);$brief=['keyword'=>'Luxor tour','profile_version'=>1,'confirmed_facts'=>$profile];$data=['title'=>'Luxor guide','meta_description'=>'A confirmed and useful Luxor guide.','faqs'=>[['question'=>'What to bring?','answer'=>'Bring water.']]];
$pages=[['url'=>'https://example.com/tours/luxor','title'=>'Luxor day tour','ok'=>true]];
$quality->remember(1,1,'task:99',$brief,$data,null,99,$pages,'https://example.com');
$url='https://example.com/?tf_article=luxor-guide';
$quality->published(1,1,'task:99',$url,'What to bring? Bring water.');
$row=$db->query('SELECT * FROM seo_content_quality')[0];
check($row['page_url']===$url&&count(json_decode($row['faqs_json'],true))===1,'fallback URL and visibly matching FAQ persist in the real schema');
$rules=ContentQualityService::rules([$row],'example.com');
check(isset($rules['/?tf_article=luxor-guide']['jsonld'])&&!isset($rules['/']),'persisted fallback schema is isolated from homepage');
check($rules['/tours/luxor']['related_links'][0]['url']===$url,'persisted pillar links to actual fallback publication');
check(denied(fn()=>$quality->published(2,1,'task:99',$url)),'foreign owner cannot update publication evidence');
check(denied(fn()=>$quality->published(1,1,'task:99',$url.'&preview=1')),'arbitrary query parameters cannot expand rule matching');
$task=['id'=>99,'title'=>'Revised Luxor guide','expected_value'=>'A confirmed tour for USD 65. What to bring? Bring water.'];
$quality->assertTaskCurrent(1,1,$task);
check($pdo->query('SELECT title FROM seo_content_quality')->fetchColumn()===$task['title'],'approved edited title synchronizes Article schema evidence before publishing');
$bad=$task;$bad['expected_value']='Price USD 99';check(denied(fn()=>$quality->assertTaskCurrent(1,1,$bad)),'unconfirmed edited price is rejected before either publication path');
$bad=$task;$bad['title']='Luxor tour USD 99';check(denied(fn()=>$quality->assertTaskCurrent(1,1,$bad)),'unconfirmed title price is rejected before publishing');
$pdo->exec('UPDATE business_hub_profiles SET version=2 WHERE website_id=1');check(denied(fn()=>$quality->assertTaskCurrent(1,1,$task)),'changed confirmed profile blocks stale draft publication');$pdo->exec('UPDATE business_hub_profiles SET version=1 WHERE website_id=1');
$quality->published(1,1,'task:99',$url,'Article edited without FAQ.');
check($pdo->query('SELECT faqs_json FROM seo_content_quality')->fetchColumn()==='[]','FAQ removed from visible article is not retained as invisible schema');
$pdo->exec("INSERT INTO seo_expert_runs(id,website_id,user_id,request_key,status,snapshot_json,created_at,updated_at) VALUES(9001,1,1,'polish-fixture','completed','{}',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$pdo->prepare("INSERT INTO seo_expert_tasks(id,run_id,website_id,user_id,kind,category,page_url,page_path,title,instructions,field_name,expected_value,status,is_active,published_url,created_at,updated_at) VALUES(99,9001,1,1,'content','aeo','https://example.com/','/','Luxor guide','Reviewed content','content','What to bring? Bring water.','verified',1,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$url]);
$quality->published(1,1,'task:99','https://example.com/guides/luxor-guide'); // historical record before fallback URL repair
$edge=EdgeRules::rulesFor($db,1,'example.com');
check(isset($edge['page_rules']['/?tf_article=luxor-guide']['jsonld'])&&!isset($edge['page_rules']['/guides/luxor-guide']),'real edge compiler repairs historical quality URL to currently published fallback URL');
check($edge['articles']['luxor-guide']['url']===$url,'article payload carries actual canonical route for guide index/sitemap');
echo PHP_EOL.($n-$fails)."/$n passed".PHP_EOL;exit($fails?1:0);
