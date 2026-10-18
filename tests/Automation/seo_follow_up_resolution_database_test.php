<?php
/** Real services against the guarded disposable fixture; no provider or production writes. */
require __DIR__.'/fixtures/geo_database.php';
require_once $root.'/app/Services/SeoExpert/SeoExpertService.php';
require_once $root.'/app/Services/SeoImpact/SeoImpactService.php';
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label.PHP_EOL;}
function denied($fn):bool{try{$fn();return false;}catch(InvalidArgumentException $e){return true;}}
foreach(['2026_10_12_000002_seo_expert.sql','2026_10_13_seo_impact.sql','2026_10_17_000004_local_links_reporting.sql'] as $file)$pdo->exec(file_get_contents($root.'/database/migrations/'.$file));
foreach(['seo_auto_impact_monitors','seo_impact_monitors','seo_impact_changes','seo_expert_tasks','seo_expert_runs','seo_expert_sites','jobs','seo_booking_sources','seo_ai_observations'] as $table)$pdo->exec('DELETE FROM '.$table);
$pdo->exec('ALTER TABLE business_hub_profiles ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL');
$facts=['business_name'=>'Example Tours','business_facts'=>'Confirmed tours','source_site_url'=>'https://example.com'];
$pdo->prepare('INSERT INTO business_hub_profiles VALUES(1,1,1,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute([json_encode($facts)]);
$brief=['source_site_url'=>'https://example.com','business_name'=>'Example Tours','shared_profile'=>['version'=>1,'facts'=>$facts]];
$pdo->prepare("INSERT INTO seo_expert_sites(website_id,user_id,status,brief_json,policy_json,budget_month,created_at,updated_at) VALUES(1,1,'draft',?,'{}','2026-10',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([json_encode($brief)]);
$page=['url'=>'https://example.com/tours','title'=>'Actual tours','description'=>'Detailed current routes and facts.','robots'=>''];
$pdo->prepare("INSERT INTO seo_expert_runs(id,website_id,user_id,request_key,status,version,snapshot_json,created_at,updated_at) VALUES(9001,1,1,'resolution-fixture','completed',1,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([json_encode(['brief'=>$brief,'opportunities'=>['scope_version'=>2],'pages'=>[$page]])]);
$insert=$pdo->prepare("INSERT INTO seo_expert_tasks(id,run_id,website_id,user_id,kind,category,page_url,page_path,title,instructions,field_name,expected_value,status,evidence_json,checked_at,created_at,updated_at) VALUES(?,9001,1,1,?,'seo',?,? ,?,'Retained instructions',?,?,'needs_client',?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$evidence=['client_evidence'=>'Previously reviewed, not applied.','check'=>['state'=>'pending','observed'=>['Current detailed description']]];
$insert->execute([9021,'metadata',$page['url'],'/tours','Review description','description','Generic proposed description',json_encode($evidence)]);
$insert->execute([9022,'manual','https://example.com/quiz','/quiz','Not indexed: operational quiz',null,null,json_encode($evidence)]);
$service=new SeoExpertService($db);$task=fn($id)=>$pdo->query('SELECT * FROM seo_expert_tasks WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
$version=fn()=>(int)$pdo->query('SELECT version FROM seo_expert_runs WHERE id=9001')->fetchColumn();
$reason='The existing description preserves the confirmed itinerary details.';
$review=fn($id,$decision='keep_current',$uid=1,$v=null)=>$service->reviewDecision($uid,$id,['review_decision'=>$decision,'reason'=>$reason,'version'=>$v??$version()]);
$before=$task(9021);$result=$review(9021);$after=$task(9021);$stored=json_decode($after['evidence_json'],true);
check($result['tasks'][0]['recommendation_review']['code']==='client_kept_current'&&$result['tasks'][0]['follow_up']['state']==='excluded','keeping existing metadata closes review without claiming an executed improvement');
check($before['status']===$after['status']&&$before['checked_at']===$after['checked_at']&&$before['expected_value']===$after['expected_value']&&$after['is_active']==0,'review preserves status, verification date and unapplied proposal');
check($stored['client_evidence']===$evidence['client_evidence']&&$stored['check']===$evidence['check']&&$stored['review_decision']['reason']===$reason,'review reason is additive and original verification evidence survives');
check($version()===2,'review increments plan version for concurrent approval protection');
check(denied(fn()=>$service->clientDone(1,9021,'Forged completion while proposal is excluded.')),'generic completion cannot erase a keep-current review decision');
require_once $root.'/app/Services/Seo/SeoMonthlyReportService.php';
$plan=SeoMonthlyReportService::followUpPlan([$task(9021)+['snapshot_json'=>json_encode(['pages'=>[$page]])]]);
check(count($plan['excluded_proposals'])===1&&!$plan['next_plan'],'monthly next-plan excludes a reviewed keep-current proposal and retains its reason');

check(denied(fn()=>$review(9021,'reopen',1,1)),'stale review version is rejected');
check(denied(fn()=>$review(9021,'reopen',2)),'another tenant cannot change a review decision');
check(denied(fn()=>$service->approve(1,9001,['reviewed'=>true,'version'=>$version(),'task_ids'=>[9021]])),'a forged approval cannot apply a proposal retained by review');
$result=$review(9021,'reopen');$stored=json_decode($task(9021)['evidence_json'],true);
check($result['tasks'][0]['recommendation_review']['state']==='reviewable'&&$stored['review_history'][0]['decision']==='keep_current','reopen restores review and retains dated decision history');
$result=$review(9022);check($result['tasks'][1]['recommendation_review']['code']==='client_kept_current'&&$task(9022)['status']==='needs_client','operational-page decision excludes the indexing suggestion without claiming Google indexed it');
check($pdo->query("SELECT status FROM seo_expert_sites WHERE website_id=1")->fetchColumn()==='draft'&&(int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM plan_usage_events')->fetchColumn()===0,'review starts no publishing, automation, jobs or billed cycle');

// Real automatic monitor creation and repair of an already-complete mapping set.
$date=gmdate('Y-m-d',time()-86400);$clean='https://example.com/guides/route-guide';
foreach([9023,9024] as $id){$insert->execute([$id,'content','https://example.com/','/','Reviewed route guide','content','Approved body','{}']);$pdo->prepare("UPDATE seo_expert_tasks SET status='verified',is_active=1,published_url=?,checked_at=?,updated_at=? WHERE id=?")->execute([$clean,$date.' 12:00:00',$date.' 12:00:00',$id]);}
$impact=new SeoImpactService($db);$old=$impact->recordAppliedTask(1,1,9023);$shared=$impact->recordAppliedTask(1,1,9024);
check(count($old)===3&&$old===$shared,'same-page same-day changes share three real 7/14/28-day comparisons');
check(denied(fn()=>$impact->recordAppliedTask(2,1,9023)),'foreign tenant cannot repair or create comparison mappings');
check(denied(fn()=>$review(9023)),'review decision cannot hide applied or verified content');
$pdo->exec("UPDATE seo_impact_monitors SET result_json='{\"historic\":\"original evidence\"}'");
$query='https://example.com/?tf_article=route-guide';
$pdo->prepare('UPDATE seo_expert_tasks SET published_url=?,checked_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=9023')->execute([$query]);
check($impact->syncAutomatic(9001)===3,'backfill detects a changed publication URL even when all three mappings already exist');
$current=$pdo->query('SELECT a.window_days,m.* FROM seo_auto_impact_monitors a JOIN seo_impact_monitors m ON m.id=a.monitor_id WHERE a.task_id=9023 ORDER BY a.window_days')->fetchAll();
check(array_column($current,'window_days')===[7,14,28]&&array_unique(array_column($current,'page_url'))===[$query],'all windows measure the exact live connector article URL');
check(array_unique(array_column($current,'change_date'))===[$date],'repair preserves the original application date instead of moving it to the latest verification');
check(array_unique(array_column($current,'page_path'))===['/?tf_article=route-guide']&&$current[0]['page_hash']!==hash('sha256','/'),'query articles cannot be mixed with homepage measurements');
check($pdo->query('SELECT page_url FROM seo_impact_changes WHERE external_key=\'seo_task_9023\'')->fetchColumn()===$query,'change event follows the corrected publication URL');
check((int)$pdo->query("SELECT COUNT(*) FROM seo_impact_monitors WHERE status='superseded'")->fetchColumn()===0,'shared old comparisons remain active while another task still uses them');
$pdo->prepare('UPDATE seo_expert_tasks SET published_url=?,updated_at=UTC_TIMESTAMP() WHERE id=9024')->execute(['https://example.com/?tf_article=another-guide']);
check($impact->syncAutomatic(9001)===3,'a second article receives independent exact-query windows');
$historic=$pdo->query('SELECT * FROM seo_impact_monitors WHERE id IN ('.implode(',',$old).')')->fetchAll();
check(array_unique(array_column($historic,'status'))===['superseded']&&array_unique(array_column($historic,'result_json'))===['{"historic":"original evidence"}'],'unreferenced old comparisons are retained with original results and marked superseded');
$jobs=(int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
check($impact->syncAutomatic(9001)===0&&$impact->recordAppliedTask(1,1,9023)===array_map('intval',array_column($current,'id'))&&(int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===$jobs,'backfill and verification repeats create no duplicate windows or queued jobs');
$impact->collect($old[0]);check($pdo->query('SELECT result_json FROM seo_impact_monitors WHERE id='.$old[0])->fetchColumn()==='{"historic":"original evidence"}','a queued job for a superseded URL is a no-op and preserves evidence');
check(denied(fn()=>$impact->refresh(1,$old[0])),'historic superseded comparison cannot be manually refreshed');
$overview=$impact->overview(1,1);$m=array_values(array_filter($overview['monitors'],fn($m)=>$m['page_url']===$query))[0];
check($m['result']===null&&$m['periods']['days']===(int)$m['window_days']&&!$m['periods']['ready'],'queued comparisons expose actual windows and due date before metrics exist');
check($m['periods']['before']['end']===gmdate('Y-m-d',time()-2*86400)&&$m['periods']['after']['start']===gmdate('Y-m-d'),'before/after exclude the original change day');
$booking=SeoImpactRules::booking(['booking_key'=>str_repeat('a',64),'source_version'=>1,'paid_date'=>gmdate('Y-m-d'),'amount'=>10,'currency'=>'USD','status'=>'paid','landing_url'=>$query],'https://example.com');
check($booking['page_path']==='/?tf_article=route-guide','received booking landing identity retains the article query rather than the homepage');
check((int)$pdo->query('SELECT COUNT(*) FROM seo_expert_tasks')->fetchColumn()===4,'all original task rows survive review and monitoring repairs');
echo "$n assertions passed\n";
