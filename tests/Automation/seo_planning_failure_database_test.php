<?php
// Provider and billing transports are fakes; run/task/snapshot writes use a guarded disposable MariaDB.
class ContentBriefService {public function __construct(...$args){}public function forKeyword(...$args){return ['keyword'=>'Luxor day tour','profile_version'=>1,'confirmed_facts'=>['business_name'=>'Example tours','business_facts'=>'Egypt Luxor tours']];}}
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
$pdo->exec(file_get_contents($root.'/database/migrations/2026_10_17_000003_content_quality.sql'));
$pdo->exec('DELETE FROM seo_content_quality');
$market=(new SeoMarketService($db))->resolve(1,1);
$pdo->prepare("INSERT INTO seo_keyword_research(website_id,market_id,keyword,volume,difficulty,source,fetched_at) VALUES(1,?,'Luxor day tour',90,0,'dataforseo',UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE fetched_at=UTC_TIMESTAMP()")->execute([$market['id']]);
$brief['shared_profile']['confirmed_at']=gmdate('c');
$pdo->prepare('UPDATE seo_expert_sites SET brief_json=?')->execute([json_encode($brief)]);
AiText::$response=['success'=>true,'data'=>json_encode(['summary'=>'Review the confirmed public destination content and verify customer-specific claims.','changes'=>[],'client_tasks'=>[],'content_drafts'=>[['title'=>'Planning a Luxor day tour','body'=>str_repeat('Visit the confirmed Luxor tours page to compare the listed routes. ',4).'Most tours last 6 to 8 hours.','category'=>'aeo','faqs'=>[],'meta_description'=>'Compare Luxor tours and confirm your chosen route.']]])];
$pdo->exec('UPDATE seo_expert_sites SET cycles_used=1');
$pdo->prepare("UPDATE seo_expert_runs SET status='scanned',snapshot_json=? WHERE id=9001")->execute([json_encode(['pages'=>[$page],'tech'=>[]])]);
$service->build(9001);
check($pdo->query('SELECT status FROM seo_expert_runs WHERE id=9001')->fetchColumn()==='draft','unconfirmed article does not block the safe remainder of the plan');
check((int)$pdo->query("SELECT COUNT(*) FROM seo_expert_tasks WHERE kind='content'")->fetchColumn()===0,'unsupported duration never becomes a publishable content task');
check((int)$pdo->query("SELECT COUNT(*) FROM seo_expert_tasks WHERE kind='manual' AND title='Confirm article facts before writing' AND status='needs_client'")->fetchColumn()===1,'rejected draft becomes an explicit client confirmation task');
// The complete planning transaction must carry crawl evidence into schema generation.
AiText::$response=['success'=>true,'data'=>json_encode(['summary'=>'Review the inspected destination pages and their factual navigation.','changes'=>[],'client_tasks'=>[],'content_drafts'=>[]])];
$parent=array_replace($page,['url'=>'https://example.com/tours','page_path'=>'/tours','h1'=>'Egypt Tours','http_status'=>200]);
$destination=array_replace($page,['url'=>'https://example.com/tours/luxor','page_path'=>'/tours/luxor','h1'=>'Luxor Tours','http_status'=>200]);
$policyPages=[];foreach(['privacy','cookies','terms','cancellation'] as $slug)$policyPages[]=array_replace($page,['url'=>'https://example.com/page/'.$slug,'page_path'=>'/page/'.$slug,'h1'=>ucfirst($slug).' Policy','http_status'=>200]);
$pdo->prepare("UPDATE seo_expert_runs SET status='scanned',snapshot_json=? WHERE id=9001")->execute([json_encode(['pages'=>array_merge([$parent,$destination],$policyPages),'tech'=>[]])]);
$service->build(9001);
check($pdo->query('SELECT status FROM seo_expert_runs WHERE id=9001')->fetchColumn()==='draft','breadcrumb evidence survives normal plan persistence');
$s=$pdo->prepare("SELECT expected_value FROM seo_expert_tasks WHERE run_id=9001 AND field_name='jsonld' AND page_url=? ORDER BY id DESC LIMIT 1");
foreach($policyPages as $policyPage){$s->execute([$policyPage['url']]);$schema=json_decode($s->fetchColumn(),true);$crumbs=array_values(array_filter($schema['@graph'],static fn($node)=>$node['@type']==='BreadcrumbList'))[0];check(array_column($crumbs['itemListElement'],'item')===['https://example.com/',$policyPage['url']],'saved policy proposal excludes nonexistent /page/: '.$policyPage['page_path']);}
$s->execute([$destination['url']]);$schema=json_decode($s->fetchColumn(),true);$crumbs=array_values(array_filter($schema['@graph'],static fn($node)=>$node['@type']==='BreadcrumbList'))[0];
check(array_column($crumbs['itemListElement'],'item')===['https://example.com/','https://example.com/tours',$destination['url']],'saved destination proposal retains the verified exact parent URL');
// Generic schema instructions are checked against real page context during normal persistence.
AiText::$response=['success'=>true,'data'=>json_encode(['summary'=>'Review the specific missing main heading while keeping valid existing identity schema.','changes'=>[],'client_tasks'=>[['page_index'=>0,'category'=>'geo','title'=>'Implement structured data for tours','instructions'=>'Add schema for the business and its tour offerings.'],['page_index'=>0,'category'=>'seo','title'=>'Add H1 to /tours/luxor','instructions'=>'Add one descriptive H1 matching the confirmed destination services.']],'content_drafts'=>[]])];
$identityPage=array_replace($page,['schema'=>[['@type'=>'TravelAgency','name'=>'Example tours']]]);
$pdo->prepare("UPDATE seo_expert_runs SET status='scanned',snapshot_json=? WHERE id=9001")->execute([json_encode(['pages'=>[$identityPage],'tech'=>[]])]);
$service->build(9001);
check((int)$pdo->query("SELECT COUNT(*) FROM seo_expert_tasks WHERE title='Implement structured data for tours'")->fetchColumn()===0,'normal persistence drops unsupported generic schema task');
check((int)$pdo->query("SELECT COUNT(*) FROM seo_expert_tasks WHERE title='Add H1 to /tours/luxor'")->fetchColumn()===1,'normal persistence retains targeted site task');
echo "$n assertions passed\n";
