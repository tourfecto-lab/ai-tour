<?php
define('TOURFECTO_STORAGE',sys_get_temp_dir().'/tf-publication-revision-'.getmypid());
mkdir(TOURFECTO_STORAGE.'/cache',0700,true);file_put_contents(TOURFECTO_STORAGE.'/cache/queue-heartbeat.json',json_encode(['time'=>time(),'status'=>'running']));
require __DIR__.'/fixtures/geo_database.php';
require_once $root.'/app/Services/SeoExpert/SeoExpertService.php';
require_once $root.'/app/Services/SeoExpert/SeoPublicationRevision.php';
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label.PHP_EOL;}
function denied($fn):bool{try{$fn();return false;}catch(InvalidArgumentException $e){return true;}}
foreach(['2026_10_12_000002_seo_expert.sql','2026_10_17_000003_content_quality.sql','2026_10_13_seo_impact.sql','2026_10_17_000004_local_links_reporting.sql'] as $file)$pdo->exec(file_get_contents($root.'/database/migrations/'.$file));
foreach(['seo_auto_impact_monitors','seo_impact_monitors','seo_impact_changes','seo_expert_tasks','seo_expert_runs','seo_expert_sites','seo_content_quality'] as $table)$pdo->exec('DELETE FROM '.$table);
$pdo->exec('ALTER TABLE business_hub_profiles ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL');
$facts=['business_name'=>'Example Tours','business_facts'=>'Local guides and private Egypt tours.','source_site_url'=>'https://example.com'];
$pdo->prepare('INSERT INTO business_hub_profiles VALUES(1,1,1,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute([json_encode($facts)]);
$brief=['source_site_url'=>'https://example.com','business_name'=>'Example Tours','shared_profile'=>['version'=>1,'facts'=>$facts]];
$pdo->prepare("INSERT INTO seo_expert_sites(website_id,user_id,status,brief_json,policy_json,budget_month,created_at,updated_at) VALUES(1,1,'draft',?,'{}','2026-10',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([json_encode($brief)]);
$snapshot=['brief'=>$brief,'config_version'=>1,'opportunities'=>['scope_version'=>2],'pages'=>[]];
$pdo->prepare("INSERT INTO seo_expert_runs(id,website_id,user_id,request_key,status,version,snapshot_json,created_at,updated_at) VALUES(9001,1,1,'revision-fixture','completed',1,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([json_encode($snapshot)]);
$oldBody='Explore the published Egypt tour routes. Children under 6 travel free. Book with free cancellation.';
$url='https://example.com/?tf_article=egypt-guide';$title='Egypt route planning guide';
$pdo->prepare("INSERT INTO seo_expert_tasks(id,run_id,website_id,user_id,kind,category,page_url,page_path,title,instructions,field_name,expected_value,before_json,status,is_active,published_url,evidence_json,checked_at,created_at,updated_at) VALUES(9901,9001,1,1,'content','aeo','https://example.com/','/',?,'Review facts','content',?,?,'verified',1,?,'{\"state\":\"verified\",\"client_evidence\":\"Keep original proof\"}','2026-10-17 12:00:00',UTC_TIMESTAMP(),'2026-10-17 12:00:00')")->execute([$title,$oldBody,json_encode(['article_image'=>['src'=>'https://example.com/image.jpg','alt'=>'Egypt'],'article_links'=>[['url'=>'https://example.com/tours','title'=>'Tours']]]),$url]);
$body="Compare the published Egypt tour itineraries and choose the route that fits your plans.\n\n## Before booking\nConfirm the itinerary, final price, inclusions and any child or cancellation conditions for your chosen departure. No policy is assumed here.";
class RevisionPageReader extends SeoExpertPageContext {
 public array $reads=[];public string $body='';public string $url='';public string $title='';
 public function freshPublication(string $url):array{$this->reads[]=$url;return ['ok'=>true,'final_url'=>$this->url,'title'=>$this->title,'text'=>$this->body];}
 public function publicationSitemap(string $site,string $url,bool $connector=false):array{return ['state'=>'not_measured'];}
 public function publicationLinks(array $links,string $site):array{return [];}
}
$reader=new RevisionPageReader();$reader->body=$body;$reader->url=$url;$reader->title=$title;$service=new SeoExpertService($db,null,$reader);
$original=$pdo->query('SELECT * FROM seo_expert_tasks WHERE id=9901')->fetch(PDO::FETCH_ASSOC);
$preview=fn($extra=[])=>$service->editTask(1,9901,array_replace(['publication_revision'=>'preview','version'=>1,'value'=>$body],$extra));
check($service->detail(1,9001)['tasks'][0]['publication_revision_available'],'owned connector publication exposes revision action');
check(denied(fn()=>$service->editTask(2,9901,['publication_revision'=>'preview','version'=>1,'value'=>$body])),'foreign account cannot preview publication');
check(denied(fn()=>$preview(['version'=>2])),'stale plan cannot preview a revision');
check(denied(fn()=>$preview(['value'=>$body.' Children travel free.'])),'unsupported claim blocks correction preview');
check(denied(fn()=>$preview(['value'=>$body.' <script>alert(1)</script>'])),'HTML and scripts cannot enter connector prose');
$p=$preview();
check($p['state']==='preview'&&$p['published_url']===$url&&$p['content']===$body,'preview returns exact corrected text and original URL');
check($pdo->query('SELECT * FROM seo_expert_tasks WHERE id=9901')->fetch(PDO::FETCH_ASSOC)===$original&&(int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0,'preview alters no task, verification, queue or public content');
$apply=fn($extra=[])=>$service->editTask(1,9901,array_replace(['publication_revision'=>'apply','version'=>1,'reviewed'=>true,'value'=>$body,'preview_token'=>$p['preview_token']],$extra));
check(denied(fn()=>$apply(['reviewed'=>false])),'explicit review confirmation is required');
check(denied(fn()=>$apply(['preview_token'=>$p['preview_token'].'x'])),'forged preview signature cannot save');
check(denied(fn()=>$apply(['value'=>$body.' Changed after preview.'])),'changing proposed text invalidates preview binding');
$pdo->exec('UPDATE business_hub_profiles SET version=2 WHERE website_id=1');check(denied(fn()=>$apply()),'changed business facts invalidate a signed preview');$pdo->exec('UPDATE business_hub_profiles SET version=1 WHERE website_id=1');
$result=$apply();$newId=$result['revised_task_id'];$old=$pdo->query('SELECT * FROM seo_expert_tasks WHERE id=9901')->fetch(PDO::FETCH_ASSOC);$new=$pdo->query('SELECT * FROM seo_expert_tasks WHERE id='.$newId)->fetch(PDO::FETCH_ASSOC);
check($newId!==9901&&$new['status']==='active'&&$new['is_active']==1&&$new['checked_at']===null,'saving creates a distinct active revision awaiting verification');
check($new['published_url']===$url&&$new['title']===$title&&(int)$new['previous_task_id']===9901,'revision keeps the same URL and title with a durable previous-version link');
check($old['expected_value']===$original['expected_value']&&$old['status']==='verified'&&$old['checked_at']===$original['checked_at']&&$old['updated_at']===$original['updated_at'],'previous body, verification and timestamps remain intact');
$oldEvidence=json_decode($old['evidence_json'],true);check($old['is_active']==0&&$oldEvidence['client_evidence']==='Keep original proof'&&(int)$oldEvidence['superseded_by_task_id']===$newId,'previous version remains retained and explicitly superseded');
check(json_decode($new['before_json'],true)['article_image']===json_decode($original['before_json'],true)['article_image']&&json_decode($new['before_json'],true)['article_published_at']==='2026-10-17','image, link settings and first publication date are preserved');
check((int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE job_class='SeoExpertExecuteJob'")->fetchColumn()===1,'save queues verification, not a provider publication');
check((int)$pdo->query('SELECT COUNT(*) FROM seo_auto_impact_monitors WHERE task_id='.$newId)->fetchColumn()===3,'new revision receives automatic 7/14/28-day impact mappings');
check($result['tasks'][0]['recommendation_review']['claim_review']['state']==='historical'&&!$result['tasks'][0]['publication_revision_available'],'superseded claims remain historical instead of actionable current work');
check(!SeoRecommendationReview::publicVerificationEligible($old),'old revision will not be falsely rechecked against the new body');
check(SeoTaskFollowUp::forTask($old)['state']==='historical','superseded publication is historical rather than unresolved customer work');
$count=(int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn();$again=$apply();
check($again['revised_task_id']===$newId&&(int)$pdo->query('SELECT COUNT(*) FROM seo_expert_tasks')->fetchColumn()===2&&(int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===$count,'lost-response replay creates no duplicate revision or jobs');
$service->execute(9001,'verify',3);
$verified=$pdo->query('SELECT * FROM seo_expert_tasks WHERE id='.$newId)->fetch(PDO::FETCH_ASSOC);
check($reader->reads===[$url]&&$verified['status']==='verified','normal verification checks only the current revision at its original URL');
check(isset(json_decode($verified['evidence_json'],true)['publication_revision']['token_hash']),'fresh verification retains revision provenance and retry identity');
check($apply()['revised_task_id']===$newId,'retry remains idempotent after verification');
$pdo->exec('CREATE TABLE IF NOT EXISTS auto_seo_applied_fixes(id INT AUTO_INCREMENT PRIMARY KEY,website_id INT,field_name VARCHAR(100),injected_code TEXT,is_active INT)');
$qualityService=new ContentQualityService($db);$qualityService->remember(1,1,'task:9901',['keyword'=>'Old guide','profile_version'=>1,'confirmed_facts'=>$facts],['title'=>$title,'meta_description'=>'Old metadata','faqs'=>[['question'=>'Child discount?','answer'=>'Children travel free.']]],null,9901,[],'https://example.com');$qualityService->published(1,1,'task:9901',$url);
$edge=EdgeRules::rulesFor($db,1,'example.com');
check($edge['articles']['egypt-guide']['body']===$body&&$edge['articles']['egypt-guide']['url']===$url,'actual connector compiler serves the new body at the same URL');
check($edge['articles']['egypt-guide']['published_at']==='2026-10-17','actual connector keeps the original publication date after revision');
check(!str_contains($edge['page_rules']['/?tf_article=egypt-guide']['jsonld'],'Children travel free'),'superseded quality row cannot re-inject a removed FAQ answer');

check((int)$pdo->query('SELECT COUNT(*) FROM plan_usage_events')->fetchColumn()===0,'revision uses no AI allowance or paid provider');
unlink(TOURFECTO_STORAGE.'/cache/queue-heartbeat.json');rmdir(TOURFECTO_STORAGE.'/cache');echo "$n assertions passed\n";
