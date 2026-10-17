<?php
// Provider and billing transports are fakes; run/task/snapshot writes use a guarded disposable MariaDB.
class AiText {public static int $calls=0;public static array $response=['success'=>true,'data'=>'PRIVATE provider payload NOT JSON'];public static function generate(...$args){self::$calls++;return self::$response;}}
class ServiceUsageReservation {public static array $states=[];public static function settle($token,$state){self::$states[$token]=$state;return true;}}
require __DIR__.'/fixtures/geo_database.php';
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;$n++;}
$pdo->exec(file_get_contents($root.'/database/migrations/2026_10_12_000002_seo_expert.sql'));
$pdo->exec('DELETE FROM seo_expert_tasks;DELETE FROM seo_expert_runs;DELETE FROM seo_expert_sites');
$pdo->exec('ALTER TABLE business_hub_profiles ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL');
$facts=['business_name'=>'Example tours','business_facts'=>'Egypt Luxor tours','source_site_url'=>'https://example.com'];
$pdo->prepare('INSERT INTO business_hub_profiles VALUES(1,1,1,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute([json_encode($facts)]);
$brief=['source_site_url'=>'https://example.com','language'=>'en','business_name'=>'Example tours','shared_profile'=>['version'=>1,'facts'=>$facts]];
$pdo->prepare("INSERT INTO seo_expert_sites(website_id,user_id,brief_json,policy_json,budget_month,cycles_used,created_at,updated_at) VALUES(1,1,?,'{}',DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m'),1,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([json_encode($brief)]);
$page=['url'=>'https://example.com/tours/luxor','title'=>'Luxor tour','h1'=>'Luxor tour','description'=>'Visit Luxor','lang'=>'en','text'=>'Confirmed Luxor tour service','counts'=>[],'robots'=>'','has_viewport'=>true,'values'=>[],'ok'=>true];
$snapshot=['pages'=>[$page],'tech'=>[]];
$pdo->prepare("INSERT INTO seo_expert_runs(id,website_id,user_id,request_key,usage_token,status,snapshot_json,created_at,updated_at) VALUES(9001,1,1,'failure-fixture','fake-token','scanned',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([json_encode($snapshot)]);
require_once $root.'/app/Services/SeoExpert/SeoExpertService.php';
$service=new SeoExpertService($db);$service->build(9001);
$row=$pdo->query('SELECT * FROM seo_expert_runs WHERE id=9001')->fetch();$snapshot=json_decode($row['snapshot_json'],true);
check(AiText::$calls===1,'one fake provider call');
check($row['status']==='failed','invalid generated plan fails closed');
check(($snapshot['planning_diagnostic']['code']??'')==='invalid_generated_plan','validation failure has a precise safe stage code');
check(str_contains($row['error_message'],'SEO-9001'),'public failure has run reference');
check(!str_contains(json_encode($snapshot).$row['error_message'],'PRIVATE'),'raw provider payload never enters diagnostics');
check(ServiceUsageReservation::$states['fake-token']==='released','definite failure releases reservation');
check((int)$pdo->query('SELECT cycles_used FROM seo_expert_sites')->fetchColumn()===0,'definite failure returns cycle allowance');
check((int)$pdo->query('SELECT COUNT(*) FROM seo_expert_tasks')->fetchColumn()===0,'invalid plan creates no tasks');
$service->build(9001);check(AiText::$calls===1,'failed cycle cannot silently regenerate');
check((int)$pdo->query('SELECT cycles_used FROM seo_expert_sites')->fetchColumn()===0,'replay cannot release allowance twice');
$pdo->exec("UPDATE seo_expert_runs SET status='planning' WHERE id=9001");$service->build(9001);$row=$pdo->query('SELECT * FROM seo_expert_runs WHERE id=9001')->fetch();
check(ServiceUsageReservation::$states['fake-token']==='uncertain','interrupted provider outcome held for review');
check(json_decode($row['snapshot_json'],true)['planning_diagnostic']['code']==='provider_outcome_unknown','unknown outcome distinguished from invalid response');
check(AiText::$calls===1,'unknown provider outcome never retried');
$pdo->exec("UPDATE seo_expert_runs SET status='scanned' WHERE id=9001");$service->build(9001);
check((int)$pdo->query('SELECT cycles_used FROM seo_expert_sites')->fetchColumn()===0,'zero unsigned cycle allowance cannot underflow on definite failure');
AiText::$response=['success'=>true,'data'=>json_encode(['summary'=>'Review confirmed public service metadata and missing connections.','changes'=>[],'client_tasks'=>[],'content_drafts'=>[]])];
$pdo->prepare("UPDATE seo_expert_runs SET status='scanned',snapshot_json=? WHERE id=9001")->execute([json_encode(['pages'=>[$page],'tech'=>[]])]);
$service->build(9001);$row=$pdo->query('SELECT * FROM seo_expert_runs WHERE id=9001')->fetch();
check($row['status']==='draft','valid plan persists tasks using the captured site context');
check(ServiceUsageReservation::$states['fake-token']==='completed','successful plan settles once as completed');
check((int)$pdo->query('SELECT COUNT(*) FROM seo_expert_tasks')->fetchColumn()>0,'deterministic review tasks survive valid planning');
echo "$n assertions passed\n";
