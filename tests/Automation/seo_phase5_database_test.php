<?php
/** Guarded disposable MariaDB fixture: never accepts an unmarked populated customer DB. */
require __DIR__.'/fixtures/geo_database.php';
$n=0;$fails=0;function check($c,$m){global $n,$fails;$n++;echo($c?'  ok  ':'  FAIL ').$m."\n";if(!$c)$fails++;}
function denied($fn){try{$fn();return false;}catch(Throwable $e){return true;}}
foreach(['2026_10_12_000002_seo_expert.sql','2026_10_13_seo_impact.sql','2026_08_31_000001_create_monitored_backlinks.sql','2026_10_17_000004_local_links_reporting.sql'] as $file)$pdo->exec(file_get_contents($root.'/database/migrations/'.$file));
$pdo->exec(file_get_contents($root.'/database/migrations/2026_10_17_000004_local_links_reporting.sql'));
foreach(['seo_auto_impact_monitors','seo_impact_monitors','seo_impact_changes','seo_expert_tasks','seo_expert_runs','seo_phase5_claims','seo_local_grid_points','seo_local_grid_runs','seo_local_profiles','seo_citation_tasks','seo_link_domain_snapshots','seo_backlink_evidence','monitored_backlinks'] as $table)$pdo->exec('DELETE FROM '.$table);
check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('seo_local_grid_runs','seo_citation_tasks','seo_auto_impact_monitors')")->fetchColumn()===3,'Phase 5 migration installs repeatably without replacing existing schemas');
$queued=0;$scheduler=new GbpBackgroundScheduler($db,function($p)use(&$queued){$queued++;return 100;});
check($scheduler->enqueueSite(1,1,21600)&&!$scheduler->enqueueSite(1,1,21601)&&$queued===1,'six-hour GBP scheduling claim is idempotent even after a job completes');
check($scheduler->enqueueSite(1,1,43200)&&$queued===2,'next six-hour interval can enqueue again');
check(!$scheduler->enqueueSite(2,1,64800),'GBP enqueue rejects foreign-owned site');
$pdo->exec("INSERT INTO jobs(queue,job_class,payload,status,available_at,created_at) VALUES('gbp_sync','GbpBackgroundSyncJob','{\"website_id\":1,\"user_id\":1}','processing',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
check(!$scheduler->enqueueSite(1,1,64800)&&$queued===2,'pending/processing GBP job prevents overlap across scheduling intervals');
$pdo->exec('DELETE FROM jobs');
$profile=['name'=>'Nile Travel','phone'=>'+201001234567','website'=>'https://example.com','address'=>['addressLines'=>['10 Nile St'],'locality'=>'Cairo','regionCode'=>'EG'],'latitude'=>30.0444,'longitude'=>31.2357,'place_id'=>'fixture-place','directory_profiles'=>['https://directory.example/nile']];
$s=$pdo->prepare('INSERT INTO seo_local_profiles VALUES(1,1,?,UTC_TIMESTAMP())');$s->execute([json_encode($profile)]);
$ledger=new class {public function reserve(...$a){return 1;}public function settle(...$a){}};
$calls=[];$client=new DataForSeoClient(['enabled'=>'1','login'=>'offline','password'=>'fixture','local_enabled'=>'1','backlinks_enabled'=>'1','monthly_budget_usd'=>10],function($endpoint,$tasks)use(&$calls){$calls[]=[$endpoint,$tasks];return ['status_code'=>20000,'cost'=>0.001,'tasks'=>[['status_code'=>20000,'result'=>[['items'=>[['type'=>'maps_search','place_id'=>'fixture-place','rank_group'=>3]]]]]]];},$ledger);
$grid=new LocalRankGridService($db,$client);
$missingCoordinates=$profile;$missingCoordinates['latitude']=null;$missingCoordinates['longitude']=null;
$s=$pdo->prepare('UPDATE seo_local_profiles SET profile_json=? WHERE website_id=1 AND user_id=1');$s->execute([json_encode($missingCoordinates)]);
check(denied(fn()=>$grid->start(1,1,['Luxor tours','Cairo travel'])),'a confirmed NAP profile without coordinates cannot start a Maps grid');
check(count($calls)===0&&(int)$pdo->query('SELECT COUNT(*) FROM seo_local_grid_runs')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0,'missing coordinates create no paid provider calls, grid run or queued worker');
$s->execute([json_encode($profile)]);
$start=$grid->start(1,1,['Luxor tours','Cairo travel']);$id=$start['run_id'];
check($start['maximum_paid_requests']===50&&$start['limits']['keywords']===3,'grid preflight returns request count and current plan limit');
check($grid->start(1,1,['Luxor tours','Cairo travel'])['run_id']===$id,'identical monthly request reuses existing run');
check(denied(fn()=>$grid->start(2,1,['a','b'])),'foreign owner cannot start paid measurement');
$grid->step($id,2);check(count($calls)===2,'grid worker processes bounded paid requests');
check($calls[0][0]==='serp/google/maps/live/advanced'&&str_ends_with($calls[0][1][0]['location_coordinate'],',15z'),'real Maps request carries geographic coordinates and zoom');
$grid->step($id,2);check(count($calls)===4&&(int)$pdo->query('SELECT COUNT(*) FROM seo_local_grid_points')->fetchColumn()===4,'continuation skips already claimed points');
for($i=0;$i<25;$i++)$grid->step($id,5);
check(count($calls)===50&&$pdo->query('SELECT status FROM seo_local_grid_runs WHERE id='.$id)->fetchColumn()==='complete','complete 2-keyword grid makes exactly fifty requests');
$grid->step($id,2);check(count($calls)===50,'completed grid does not replay paid calls');
check(count($grid->history(1,1)[0]['points'])===50,'monthly history retains every keyword/point observation');
$grid->start(1,1,['b','c']);$grid->start(1,1,['c','d']);check(denied(fn()=>$grid->start(1,1,['d','e'])),'account monthly grid allowance enforced');
$reader=new SeoPublicPageReader(fn($url)=>['success'=>true,'http_status'=>200,'html'=>'<script type="application/ld+json">{"@type":"TravelAgency","name":"Different Name","url":"https://example.com","telephone":"+201001234567","address":{"streetAddress":"10 Nile St","addressLocality":"Cairo","addressCountry":"EG"}}</script>']);
$nap=new NapConsistencyService($db,$reader,fn()=>$profile);$nap->check(1,1);$nap->check(1,1);
check((int)$pdo->query('SELECT COUNT(*) FROM seo_citation_tasks')->fetchColumn()===2,'NAP mismatches persist one task per source/evidence without duplicate tasks');
check(denied(fn()=>$nap->check(2,1)),'citation tasks are tenant-scoped');
$pdo->exec("INSERT INTO monitored_backlinks(user_id,website_id,link_url,domain,status) VALUES(1,1,'https://source.example/post','source.example','pending')");$backlinkId=(int)$pdo->lastInsertId();
$html='<a href="https://example.com/tours" rel="nofollow ugc">Nile trips</a>';$http=200;
$monitor=new BacklinkMonitorService(function($url)use(&$html,&$http){return ['success'=>$http===200,'http_status'=>$http,'html'=>$html,'url'=>$url,'error'=>$http===500?'unavailable':null];});
check($monitor->checkLink($backlinkId)['status']==='live','HTTP 200 plus real anchor marks the backlink live');
$e=$pdo->query('SELECT * FROM seo_backlink_evidence WHERE backlink_id='.$backlinkId)->fetch();check(json_decode($e['rel_json'],true)===['nofollow','ugc']&&$e['anchor_text']==='Nile trips','backlink rel and anchor are durably recorded');
$html='<p>No anchor anymore</p>';$before=(int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type='backlink_lost'")->fetchColumn();
check($monitor->checkLink($backlinkId)['status']==='lost','HTTP 200 without anchor correctly becomes lost');$monitor->checkLink($backlinkId);
check((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type='backlink_lost'")->fetchColumn()===$before+1,'disappearance alert sent once per live-to-lost transition');
$html='<a href="https://example.com">Again</a>';$monitor->checkLink($backlinkId);$http=500;
check($monitor->checkLink($backlinkId)['status']==='unavailable'&&$pdo->query('SELECT status FROM monitored_backlinks WHERE id='.$backlinkId)->fetchColumn()==='live','temporary fetch failure does not fabricate lost backlink');
$pdo->exec("INSERT INTO seo_expert_runs(website_id,user_id,request_key,status,created_at,updated_at) VALUES(1,1,'phase5-fixture','completed',UTC_TIMESTAMP(),UTC_TIMESTAMP())");$runId=(int)$pdo->lastInsertId();
$s=$pdo->prepare("INSERT INTO seo_expert_tasks(run_id,website_id,user_id,kind,category,page_url,page_path,title,instructions,status,is_active,created_at,updated_at) VALUES(?,1,1,'metadata','seo','https://example.com/trips/luxor','/trips/luxor','Title fix','Fixture','active',1,UTC_TIMESTAMP(),UTC_TIMESTAMP())");$s->execute([$runId]);$taskId=(int)$pdo->lastInsertId();
$impact=new SeoImpactService($db);$ids=$impact->recordAppliedTask(1,1,$taskId);
check(count($ids)===3&&(int)$pdo->query('SELECT COUNT(*) FROM seo_impact_monitors')->fetchColumn()===3,'applied task automatically creates actual 7/14/28-day monitors');
check($impact->recordAppliedTask(1,1,$taskId)===$ids&&(int)$pdo->query('SELECT COUNT(*) FROM seo_auto_impact_monitors')->fetchColumn()===3,'repeated verification does not duplicate automatic monitor mappings');
check(denied(fn()=>$impact->recordAppliedTask(2,1,$taskId)),'foreign ownership cannot create task monitors');
$pdo->exec("UPDATE seo_expert_tasks SET status='pending' WHERE id=$taskId");check($impact->recordAppliedTask(1,1,$taskId)===[],'unapplied task cannot create evidence monitors');
$pdo->exec("UPDATE seo_expert_tasks SET status='verified' WHERE id=$taskId");
$editorResult=['state'=>'saved','operation_id'=>'fixture-edit','page'=>['url'=>'https://example.com/trips/aswan']];$impact->recordEditorChange(1,1,$editorResult);$impact->recordEditorChange(1,1,$editorResult);
check((int)$pdo->query('SELECT COUNT(*) FROM seo_impact_monitors')->fetchColumn()===6,'stored WordPress editor change also creates exactly three automatic monitors');

$api=new GoogleSearchConsoleAPI('offline',fn($r)=>['http_code'=>200,'body'=>json_encode(['rows'=>[['keys'=>[json_decode($r['body'],true)['dimensions'][0]==='date'?'2026-09-15':'Luxor tours'],'clicks'=>12,'impressions'=>100,'position'=>3]]])]);
$report=(new SeoMonthlyReportService($db,fn()=>[$api,'sc-domain:example.com']))->data(1,1,'2026-10-17');
check($report['gsc']['clicks']===12&&$report['gsc']['impressions']===100&&$report['gsc']['top_queries'][0]['query']==='Luxor tours','monthly report assembles real GSC parser totals and top queries');
check($report['core_web_vitals']===null&&$report['ai_visibility']===[],'missing monthly samples stay unknown/empty rather than fabricated measurements');
// Populate all monthly sources using the real existing schemas.
$pdo->exec(file_get_contents($root.'/database/migrations/2026_10_17_000002_ai_visibility.sql'));
$pdo->exec("DELETE FROM seo_ai_answers;INSERT INTO seo_ai_answers(call_id,website_id,user_id,market_id,provider,question,question_hash,week_start,set_version,mentioned_brand,brand_position,cited_domain,cited_urls,competitors_mentioned,answer_excerpt,model,cost,cost_basis,usage_json,checked_at) VALUES(9001,1,1,1,'openai','Fixture question',REPEAT('a',64),'2026-09-14',1,1,1,1,'[]','[]','Fixture answer','fixture',0,'fixture','{}','2026-09-15 00:00:00')");
$pdo->exec("INSERT INTO seo_rank_tracking_history(website_id,user_id,keyword,position,url,source,checked_at,market_id) VALUES(1,1,'Luxor tours',4,'https://example.com/trips/luxor','dataforseo','2026-09-29 00:00:00',1)");
$snapshot=['tech'=>['checked_at'=>'2026-09-29T00:00:00Z','speed'=>[['url'=>'https://example.com','field'=>['lcp_ms'=>1800,'inp_ms'=>140,'cls'=>.08],'lab'=>['lcp_ms'=>1900]]]]];
$s=$pdo->prepare("INSERT INTO seo_expert_runs(website_id,user_id,request_key,status,snapshot_json,created_at,updated_at) VALUES(1,1,'monthly-fixture','completed',?,'2026-09-29','2026-09-29')");$s->execute([json_encode($snapshot)]);$monthlyRun=(int)$pdo->lastInsertId();
$s=$pdo->prepare("INSERT INTO seo_expert_tasks(run_id,website_id,user_id,kind,category,page_url,page_path,title,instructions,status,is_active,checked_at,created_at,updated_at) VALUES(?,1,1,'metadata','seo','https://example.com/trips/luxor','/trips/luxor','Verified monthly task','Fixture','verified',1,'2026-09-29','2026-09-29','2026-09-29')");$s->execute([$monthlyRun]);
$fullReport=(new SeoMonthlyReportService($db,fn()=>[$api,'sc-domain:example.com']))->data(1,1,'2026-10-17');
check($fullReport['rankings'][0]['position']===4&&$fullReport['rankings'][0]['market_id']===1,'monthly rankings carry actual market and dated rank evidence');
check(count($fullReport['ai_visibility'])===1&&(float)$fullReport['ai_visibility'][0]['share_of_voice']===1.0,'monthly AI share uses actual measured answer denominator');
check($fullReport['core_web_vitals']['samples'][0]['field']['lcp_ms']===1800&&$fullReport['tasks'][0]['title']==='Verified monthly task','monthly CWV evidence and verified task assembly query their real source tables');

// Real link-gap integration with recorded provider responses and monthly cache.
$pdo->exec("DELETE FROM seo_link_domain_snapshots;INSERT INTO competitors(id,website_id,user_id,competitor_domain,competitor_name) VALUES(21,1,1,'one.example','One'),(22,1,1,'two.example','Two')");
$linkCalls=0;$linkClient=new DataForSeoClient(['enabled'=>'1','login'=>'fixture','password'=>'offline','monthly_budget_usd'=>10,'backlinks_enabled'=>'1'],function($endpoint,$tasks)use(&$linkCalls){$linkCalls++;$items=$tasks[0]['target']==='example.com'?[['domain'=>'customer-only.example']]:[['domain'=>'editor.example']];return ['status_code'=>20000,'cost'=>.01,'tasks'=>[['status_code'=>20000,'result'=>[['total_count'=>count($items),'items'=>$items]]]]];},$ledger);
$links=new DataForSeoLinkGapService($db,$linkClient);$g=$links->discover(1,1);
check($g['available']&&count($g['candidates'])===1&&$g['candidates'][0]['domain']==='editor.example'&&$linkCalls===3,'real referring-domain responses become an outreach gap from two competitors');
$links->discover(1,1);check($linkCalls===3,'monthly backlink snapshots prevent repeat provider calls');
$pdo->exec('DELETE FROM seo_link_domain_snapshots');$truncated=new DataForSeoClient(['enabled'=>'1','login'=>'fixture','password'=>'offline','monthly_budget_usd'=>10,'backlinks_enabled'=>'1'],fn()=>['status_code'=>20000,'tasks'=>[['status_code'=>20000,'cost'=>.01,'result'=>[['total_count'=>1001,'items'=>[['domain'=>'partial.example']]]]]]],$ledger);
check((new DataForSeoLinkGapService($db,$truncated))->discover(1,1)['reason']==='referring_domain_coverage_incomplete','truncated customer coverage cannot produce a fake link-gap absence');

// No real SMTP: inject a capturing mailer into the production scheduling service.
$scheduleSql=file_get_contents($root.'/database/migrations/2026_08_29_000004_seo_multi_crawl_rank_tracking_reports.sql');preg_match('/CREATE TABLE IF NOT EXISTS `seo_report_schedules`[\s\S]*?;/',$scheduleSql,$sqlMatch);$pdo->exec($sqlMatch[0]);$pdo->exec('DELETE FROM seo_report_schedules');
$pdo->exec("CREATE TABLE IF NOT EXISTS platform_connections(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,website_id INT,platform VARCHAR(60),status VARCHAR(30),access_token TEXT,token_expires_at DATETIME NULL)");
$mailer=new class {public int $sent=0;public string $body='';public function isConfigured(){return true;}public function send($to,$name,$subject,$body){$this->sent++;$this->body=$body;return ['success'=>true];}};
$reports=new SeoScheduledReportService($db,$mailer);$schedule=$reports->saveSchedule(1,1,['frequency'=>'monthly','hour'=>0,'recipient_email'=>'offline@example.com']);
check($schedule['success']&&!$reports->saveSchedule(1,2,['frequency'=>'monthly','recipient_email'=>'offline@example.com'])['success'],'monthly report schedule verifies website ownership');
$r=$reports->sendDue();check($r['sent']===1&&$mailer->sent===1&&str_contains($mailer->body,'تقرير SEO الشهري'),'monthly scheduler assembles Arabic report and passes it to injected mailer');
$pdo->exec('UPDATE seo_report_schedules SET last_sent_at=NULL');$reports->sendDue();check($mailer->sent===1,'durable monthly delivery claim prevents duplicate send after uncertain timestamp persistence');

echo "\n".($n-$fails)."/$n passed\n";exit($fails?1:0);
