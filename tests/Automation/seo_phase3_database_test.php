<?php
/** Offline providers with real MySQL schema, encryption, plan quota, wallet and queue. */
require __DIR__.'/fixtures/geo_database.php';
$n=0;$fails=0;function check($c,$m){global $n,$fails;$n++;echo ($c?'  ok  ':'  FAIL ').$m."\n";if(!$c)$fails++;}
function denied(callable $fn):bool{try{$fn();return false;}catch(InvalidArgumentException|RuntimeException $e){return true;}}
$pdo->beginTransaction();$blocked=denied(fn()=>AiVisibilitySchema::ensureSchema($db));$pdo->rollBack();check($blocked,'GEO runtime refuses DDL in an active transaction');
$sql=file_get_contents($root.'/database/migrations/2026_10_17_000002_ai_visibility.sql');$pdo->exec($sql);$pdo->exec($sql);
check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('seo_ai_prompt_sets','seo_ai_entities','seo_ai_calls','seo_ai_answers','seo_ai_tasks','seo_ai_budget_alerts')")->fetchColumn()===6,'additive uploaded migration installs six GEO tables and repeats');
$pdo->exec("UPDATE pay_per_use_pricing SET price=.19 WHERE feature_key='ai_visibility'");$pdo->exec($sql);check((float)$pdo->query("SELECT price FROM pay_per_use_pricing WHERE feature_key='ai_visibility'")->fetchColumn()===.19,'repeated migration preserves admin wallet price');
foreach(['seo_ai_prompt_sets','seo_ai_entities','seo_ai_calls','seo_ai_answers','seo_ai_tasks','seo_ai_budget_alerts'] as $t)$pdo->exec('DROP TABLE '.$t);
AiVisibilitySchema::ensureSchema($db);AiVisibilitySchema::ensureSchema($db);$pdo->exec("UPDATE pay_per_use_pricing SET price=.15 WHERE feature_key='ai_visibility'");
check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('seo_ai_prompt_sets','seo_ai_entities','seo_ai_calls','seo_ai_answers','seo_ai_tasks','seo_ai_budget_alerts')")->fetchColumn()===6,'runtime fallback installs same schema safely');
check(PlanUsage::charge(1,'ai_visibility','ai_visibility','first')==='plan','GEO first unit consumes plan allowance');
check(PlanUsage::charge(1,'ai_visibility','ai_visibility','second')==='plan','GEO second unit consumes plan allowance');
check(PlanUsage::charge(1,'ai_visibility','ai_visibility','third')==='wallet','GEO overage consumes wallet');
check(PlanUsage::charge(1,'ai_visibility','ai_visibility','fourth')==='failed','GEO blocks after both plan and wallet are exhausted');
check((new WalletService())->getBalance(1)===0.0,'wallet never goes negative');
$pdo->exec("UPDATE pay_per_use_pricing SET price=0 WHERE feature_key='ai_visibility'");check(PlanUsage::charge(1,'ai_visibility','ai_visibility','free fallback forbidden')==='failed','zero-priced overage cannot enable unlimited paid API use');$pdo->exec("UPDATE pay_per_use_pricing SET price=.15 WHERE feature_key='ai_visibility'");
$pdo->exec("UPDATE subscription_plans SET features_json='{\"ai_visibility\":2000,\"seo_markets\":3}';DELETE FROM plan_usage_events");
$marketService=new SeoMarketService($db);$us=$marketService->markets(1,1)[0];$uk=$marketService->add(1,1,['location_code'=>2826,'language_code'=>'en','label'=>'UK – English']);$de=$marketService->add(1,1,['location_code'=>2276,'language_code'=>'de','label'=>'Germany – German']);
$facts=['business_name'=>'Tourfecto Travel','business_facts'=>'Luxor day tour from Hurghada and Nile cruise','offers'=>'Guided Luxor tours','phone'=>'+201001234567','language'=>'en','source_site_url'=>'https://example.com'];
$stmt=$pdo->prepare('INSERT INTO business_hub_profiles VALUES(1,1,1,?,UTC_TIMESTAMP())');$stmt->execute([json_encode($facts)]);
$pdo->exec("INSERT INTO seo_keyword_research(website_id,market_id,keyword,volume,difficulty,cpc,intent,source,fetched_at) VALUES(1,".$uk['id'].",'Luxor day tour from Hurghada',100,20,1,'transactional','dataforseo',UTC_TIMESTAMP())");
$pdo->exec("INSERT INTO competitors(id,website_id,user_id,competitor_name,competitor_domain) VALUES(1,1,1,'Nile Star Tours','nilestar.example')");
$config=['enabled'=>'1','provider_enabled'=>'1','key'=>'offline-fixture'];$requests=[];
$open=['status'=>'completed','model'=>'gpt-4.1-mini','usage'=>['input_tokens'=>100,'output_tokens'=>100],'output'=>[['type'=>'web_search_call','status'=>'completed'],['type'=>'message','content'=>[['type'=>'output_text','text'=>'Nile Star Tours then Tourfecto Travel.','annotations'=>[['type'=>'url_citation','url'=>'https://www.example.com/tours/luxor'],['type'=>'url_citation','url'=>'https://nilestar.example/tours/luxor']]]]]]];
$provider=new OpenAiVisibilityProvider($config,function($url,$headers,$payload)use(&$requests,$open){$requests[]=$payload;return $open;});
$evidence=new AiVisibilityEvidence(fn($url,$method)=>['code'=>200,'url'=>$url,'body'=>'<html lang="en"><title>Luxor tour</title><meta name="description" content="Published itinerary"><h1>Luxor tour</h1><p>Karnak pickup $65 per person FAQ</p></html>']);
$s=new AiVisibilityService($db,[$provider],$evidence);$settings=new SystemSettingsService();$settings->set('geo_monthly_budget_usd','20');
check(denied(fn()=>$s->site(2,1)),'cross-tenant website access denied');
check(denied(fn()=>$s->context(1,1,9999)),'foreign market cannot be used to run paid checks');
$draft=$s->generate(1,1,(int)$uk['id']);$questions=$draft['questions'];check(count($questions)===10&&!$draft['approved'],'local generation creates an unapproved factual list without provider calls');
check(count($requests)===0,'question generation incurs no paid model calls');
check($s->run(1,1,(int)$uk['id'])['reason']==='questions_need_current_approval','unapproved questions cannot run');
$set=$s->save(1,1,(int)$uk['id'],$questions,0,true);check((int)$set['version']===1&&!empty($set['approved_at']),'customer approval stores profile/version provenance');
check(denied(fn()=>$s->save(1,1,(int)$uk['id'],$questions,0,true)),'optimistic approval version prevents stale overwrite');
$off=new AiVisibilityService($db,[], $evidence);$no=$off->run(1,1,(int)$uk['id']);check($no['state']==='not_measured'&&count($requests)===0,'no configured provider means no paid calls');
$view=$off->overview(1,1,(int)$uk['id']);check($view['state']==='not_measured'&&$view['metrics']['providers']['openai']['share_of_voice']===null&&count($view['setup_steps'])===3,'empty configuration returns setup steps and null metrics');
$settings->set('geo_monthly_budget_usd','0');$r=$s->run(1,1,(int)$uk['id']);check($r['state']==='blocked'&&count($requests)===0,'zero budget stops before transport and quota');
$settings->set('geo_monthly_budget_usd','0.09');$r=$s->run(1,1,(int)$uk['id']);check($r['state']==='blocked'&&count($requests)===0,'request ceiling must fit monthly budget before any call');
check((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type='geo_budget'")->fetchColumn()===1,'budget notification sent once per month');
$settings->set('geo_monthly_budget_usd','20');$r=$s->run(1,1,(int)$uk['id'],2);check($r['checked']===2&&!empty($r['more'])&&count($requests)===2,'bounded run queues continuation after two paid checks');
$pending=$r;for($i=0;$i<6&&!empty($pending['more']);$i++)$pending=$s->run(1,1,(int)$uk['id'],2);
check(count($requests)===10&&(int)$pdo->query('SELECT COUNT(*) FROM seo_ai_answers')->fetchColumn()===10,'resuming batches completes the approved list exactly once');
$before=count($requests);$s->run(1,1,(int)$uk['id'],2);check(count($requests)===$before,'weekly rerun makes no duplicate paid requests');
check(count(array_filter($requests,fn($p)=>str_contains($p['input'],'United Kingdom')&&!str_contains($p['input'],'Tourfecto')))===10,'every request uses selected market without brand context');
$answer=$pdo->query('SELECT * FROM seo_ai_answers LIMIT 1')->fetch();check($answer['mentioned_brand']==1&&$answer['brand_position']==2&&$answer['cited_domain']==1&&$answer['model']==='gpt-4.1-mini','actual sample saves mention order, citation, model and known cost');
check((int)$pdo->query("SELECT COUNT(*) FROM plan_usage_events WHERE feature='ai_visibility'")->fetchColumn()===10,'billing recorded once for each attempted question/provider');
$m=$s->metrics(1,1,(int)$uk['id']);check($m['providers']['openai']['share_of_voice']===1&&$m['providers']['openai']['questions']===10&&count($m['evidence'])===10,'SEO Expert receives measured rates with dated source evidence');
check(count($s->overview(1,1,(int)$uk['id'])['tasks'])>=1,'competitor citation creates persisted improvement task');
$s->save(1,1,(int)$de['id'],$questions,0,true);$r=$s->run(1,1,(int)$de['id'],1);check($r['checked']===1&&str_contains($requests[count($requests)-1]['input'],'Germany')&&str_contains($requests[count($requests)-1]['input'],'language de'),'same question in another market is a separate localized check');
check($s->metrics(1,1)['providers']['openai']['questions']===11,'cross-market aggregation does not collapse identical question hashes');
$pdo->exec('UPDATE business_hub_profiles SET version=version+1');$before=count($requests);check($s->run(1,1,(int)$uk['id'])['reason']==='questions_need_current_approval'&&count($requests)===$before,'changed confirmed business profile needs fresh approval');
$set=$s->set(1,1,(int)$uk['id']);$set=$s->save(1,1,(int)$uk['id'],$questions,(int)$set['version'],true);
$s->entities(1,1,['تورفكتو للسياحة'],['https://www.tripadvisor.com/Profile/real-tourfecto'],'Cairo',0,true);check(!$s->set(1,1,(int)$uk['id'])['approved_at'],'new identity aliases revoke previous question approvals');
check(AiVisibilityService::customerProfiles(1,1)===['https://www.tripadvisor.com/Profile/real-tourfecto']&&AiVisibilityService::customerProfiles(2,1)===[],'Organization sameAs uses owner-confirmed profile URLs only');
check(denied(fn()=>$s->entities(1,1,[],['http://127.0.0.1/'],'',1,true)),'private profile URLs rejected');
$ledger=new AiVisibilityLedger($db);$set=$s->set(1,1,(int)$uk['id']);$set=$s->save(1,1,(int)$uk['id'],$questions,(int)$set['version'],true);
$week='2026-01-05';$id=$ledger->reserve($set,'openai','What is included in a private Luxor itinerary?',$week,20);$ledger->finish($id,['available'=>false,'cost'=>null],null,20);
check($ledger->reserve($set,'openai','What is included in a private Luxor itinerary?',$week,20)===null,'uncertain failed request not retried or charged twice');
$unknown=$pdo->query('SELECT * FROM seo_ai_calls WHERE id='.$id)->fetch();check($unknown['status']==='unknown'&&$unknown['cost']===null&&(float)$unknown['reserved_cost']===.1,'unknown provider cost keeps conservative budget reservation');
$spent=(float)$pdo->query('SELECT SUM(IF(cost IS NULL,reserved_cost,cost)) FROM seo_ai_calls')->fetchColumn();$before=(int)$pdo->query('SELECT COUNT(*) FROM plan_usage_events')->fetchColumn();check(denied(fn()=>$ledger->reserve($set,'openai','Where do Luxor day trips pick up travelers?',$week,$spent+.09)),'spent and outstanding reservations both count toward cap');
check((int)$pdo->query('SELECT COUNT(*) FROM plan_usage_events')->fetchColumn()===$before,'budget-blocked request deducts no quota');
// A failed claim transaction must roll back billing as well.
$pdo->exec("UPDATE subscription_plans SET features_json='{\"ai_visibility\":0,\"seo_markets\":3}'");check(denied(fn()=>$ledger->reserve($set,'gemini','Where should I book a safe Luxor day trip?',$week,20)),'no quota and empty wallet prevents durable call claim');
$pdo->exec("UPDATE subscription_plans SET features_json='{\"ai_visibility\":2000,\"seo_markets\":3}'");
$r=$s->enqueue(1,1,(int)$uk['id']);check($r['state']==='queued'&&(int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE job_class='AiVisibilityCheckJob'")->fetchColumn()===1,'approved measurement is enqueued in actual DB-backed queue');
$prior=count($requests);(new AiVisibilityCheckJob())->handle(['user_id'=>1,'website_id'=>1,'market_id'=>$uk['id'],'week'=>'2000-01-03','version'=>$set['version']]);check(count($requests)===$prior,'stale weekly job exits without calling a provider');
class GeoAdminFixture extends AdminController{public function __construct($db,$data,$user){$this->db=$db;$this->data=$data;$this->user=$user;$this->authenticated=true;}protected function log(string $action,array $data=[]):void{}}
$values=['geo_enabled'=>'1','geo_monthly_budget_usd'=>'25','geo_openai_enabled'=>'1','geo_gemini_enabled'=>'0','geo_perplexity_enabled'=>'0','geo_openai_api_key'=>'offline-openai','geo_gemini_api_key'=>'offline-gemini','geo_perplexity_api_key'=>'offline-sonar'];
$admin=new GeoAdminFixture($db,$values,['id'=>3,'role'=>'super_admin']);check($admin->updateSystemSettings()['success'],'real admin save accepts all eight GEO settings');
check($settings->get('geo_openai_api_key')==='offline-openai'&&$settings->get('geo_gemini_enabled')==='0','encrypted secret roundtrip and disabled provider persist');
$secrets=$pdo->query("SELECT setting_value,is_secret FROM system_settings WHERE setting_key LIKE 'geo_%_api_key'")->fetchAll();check(count($secrets)===3&&array_sum(array_column($secrets,'is_secret'))===3&&!in_array('offline-openai',array_column($secrets,'setting_value'),true),'all three provider keys encrypted at rest');
$visible=$admin->getSystemSettings()['data']['settings']['geo'];check(count(array_filter($visible,fn($r)=>$r['is_secret']&&$r['value']===''))===3,'admin readback never exposes API keys');
$bad=new GeoAdminFixture($db,['geo_monthly_budget_usd'=>'-1'],['id'=>3,'role'=>'super_admin']);check(!$bad->updateSystemSettings()['success']&&$settings->get('geo_monthly_budget_usd')==='25','invalid budget rejected before settings mutation');
$bad=new GeoAdminFixture($db,['geo_enabled'=>'0'],['id'=>1,'role'=>'customer']);check(!$bad->updateSystemSettings()['success']&&$settings->get('geo_enabled')==='1','customer cannot enable or disable paid providers');
// Empty every GEO/shared provider key before testing the production provider factory. No network is possible.
foreach(['geo_openai_api_key','geo_gemini_api_key','geo_perplexity_api_key','integration_openai_api_key','integration_gemini_api_key','gemini_api_key'] as $key)$settings->set($key,'');
$defaultService=new AiVisibilityService($db);check($defaultService->run(1,1,(int)$uk['id'])['state']==='not_measured','production factory with empty provider settings makes zero API calls');
class GeoApiFixture extends AiVisibilityController{public function __construct($db,$data,$uid=1){$this->db=$db;$this->data=$data;$this->user=['id'=>$uid,'role'=>'customer'];$this->authenticated=$uid>0;}}
$api=new GeoApiFixture($db,['website_id'=>1,'market_id'=>$uk['id']]);$dto=$api->data();check($dto['success']&&$dto['data']['state']==='not_measured'&&$dto['data']['overage_price']===.15,'real customer API returns scoped unmeasured state and configured wallet price');
check(!str_contains(json_encode($dto),'offline-openai')&&!str_contains(json_encode($dto),'api_key'),'customer API DTO contains no provider keys');
$foreign=new GeoApiFixture($db,['website_id'=>2,'market_id'=>$uk['id']]);check(!$foreign->data()['success'],'real customer endpoint rejects another tenant website');
$anon=new GeoApiFixture($db,['website_id'=>1],0);check(!$anon->data()['success'],'anonymous customer endpoint is blocked');
$before=(int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn();check($api->enqueue()['data']['state']==='not_measured'&&(int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===$before,'real enqueue API adds no job without configured providers');
if(getenv('TF_GEO_UI_ARTIFACT')){require_once $root.'/app/Controllers/AiVisibilityController.php';file_put_contents(getenv('TF_GEO_UI_ARTIFACT'),AiVisibilityController::pageBody().'<script>'.file_get_contents($root.'/public_html/assets/js/ai-visibility.js').'</script>');}
check(Logger::$errors===[],'integration produced no hidden SQL or application errors');
if(Logger::$errors)fwrite(STDERR,implode("\n",Logger::$errors)."\n");
echo "\n".($n-$fails)."/$n passed\n";exit($fails?1:0);
