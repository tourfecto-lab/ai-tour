<?php
require __DIR__.'/fixtures/geo_database.php';
require_once $root.'/app/Services/SeoExpert/SeoExpertService.php';
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label.PHP_EOL;}
function denied($fn):bool{try{$fn();return false;}catch(InvalidArgumentException $e){return true;}}
$pdo->exec(file_get_contents($root.'/database/migrations/2026_10_12_000002_seo_expert.sql'));
$pdo->exec('DELETE FROM seo_expert_tasks;DELETE FROM seo_expert_runs;DELETE FROM seo_expert_sites');
$pdo->exec('ALTER TABLE business_hub_profiles ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL');
$facts=['business_name'=>'Example Tours','business_facts'=>'Confirmed Egypt tours','source_site_url'=>'https://example.com'];
$pdo->prepare('INSERT INTO business_hub_profiles VALUES(1,1,1,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute([json_encode($facts)]);
$brief=['source_site_url'=>'https://example.com','business_name'=>'Example Tours','shared_profile'=>['version'=>1,'facts'=>$facts]];
$pdo->prepare("INSERT INTO seo_expert_sites(website_id,user_id,status,brief_json,policy_json,budget_month,created_at,updated_at) VALUES(1,1,'draft',?,'{}','2026-10',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([json_encode($brief)]);
$pdo->prepare("INSERT INTO seo_expert_runs(id,website_id,user_id,request_key,status,version,snapshot_json,created_at,updated_at) VALUES(9001,1,1,'connected-fixture','completed',1,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([json_encode(['brief'=>$brief])]);
$pdo->exec("INSERT INTO seo_expert_tasks(id,run_id,website_id,user_id,kind,category,page_url,page_path,title,instructions,status,evidence_json,created_at,updated_at) VALUES(9021,9001,1,1,'manual','seo','https://example.com/','/','Submit your sitemap in Search Console','URL: https://example.com/sitemap.xml','needs_client','{\"client_evidence\":\"Customer already submitted sitemap.\"}',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
class DatabaseFakeGoogle {
 public array $maps=['ok'=>true,'sitemaps'=>[['path'=>'https://example.com/sitemap.xml','errors'=>0,'warnings'=>0,'pending'=>true]]];public int $calls=0;public $during=null;
 public function listSitemaps($p){$this->calls++;if($this->during){$f=$this->during;$this->during=null;$f();}return $this->maps;}
 public function inspectUrl($p,$u){$this->calls++;return ['ok'=>true,'verdict'=>'NEUTRAL','indexed'=>false,'coverage'=>'Discovered - currently not indexed','reason'=>'discovered_not_crawled','last_crawl'=>null];}
}
$fake=new DatabaseFakeGoogle();$service=new SeoExpertService($db,null,null,fn()=>[$fake,'sc-domain:example.com']);
$task=fn()=>$pdo->query('SELECT * FROM seo_expert_tasks WHERE id=9021')->fetch(PDO::FETCH_ASSOC);
$version=fn()=>(int)$pdo->query('SELECT version FROM seo_expert_runs WHERE id=9001')->fetchColumn();
$run=fn($uid=1,$v=null)=>$service->recheckConnectedTask($uid,9021,['version'=>$v??$version()]);
check(denied(fn()=>$run(2)),'customer cannot inspect another customer task');
check(denied(fn()=>$run(1,0))&&$fake->calls===0,'stale version rejected before provider');
$pdo->exec("UPDATE seo_expert_tasks SET kind='metadata'");check(denied(fn()=>$run()),'only recognized manual tasks allowed');$pdo->exec("UPDATE seo_expert_tasks SET kind='manual'");
$beforeJobs=(int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn();$beforeUsage=(int)$pdo->query('SELECT COUNT(*) FROM plan_usage_events')->fetchColumn();$beforeWallet=(int)$pdo->query('SELECT COUNT(*) FROM wallet_transactions')->fetchColumn();
$r=$run();$t=$task();$e=json_decode($t['evidence_json'],true);
check($t['status']==='verified'&&$version()===2,'fresh exact sitemap observation verifies existing task and increments version');
check($e['client_evidence']==='Customer already submitted sitemap.'&&$e['check']['observed']['pending']===true,'customer evidence retained with Google pending status');
check($r['tasks'][0]['connected_check_kind']==='sitemap','detail exposes read-only check action');
check((int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE job_class<>'SeoImpactCollectJob'")->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM plan_usage_events')->fetchColumn()===$beforeUsage&&(int)$pdo->query('SELECT COUNT(*) FROM wallet_transactions')->fetchColumn()===$beforeWallet,'read check creates no publication jobs or generation/wallet usage');
check($pdo->query('SELECT status FROM seo_expert_sites')->fetchColumn()==='draft'&&$pdo->query('SELECT status FROM seo_expert_runs')->fetchColumn()==='completed','automation stays draft and existing cycle stays completed');
$checked=$t['checked_at'];$fake->maps=['ok'=>false];$run();$t=$task();$e=json_decode($t['evidence_json'],true);
check($t['status']==='verified'&&$t['checked_at']===$checked&&$e['check']['state']==='verified'&&$e['latest_connected_attempt']['state']==='not_measured','unavailable connection preserves dated previous proof');
check(count($e['connected_history'])===1,'prior connected observation retained');
$fake->maps=['ok'=>true,'sitemaps'=>[]];$run();check($task()['status']==='needs_client','fresh negative observation reopens task');
$pdo->exec("UPDATE seo_expert_tasks SET title='Not indexed: old fetch error',page_url='https://example.com/tours',instructions='Inspect the URL'");$run();$t=$task();$e=json_decode($t['evidence_json'],true);
check($t['status']==='needs_client'&&$e['check']['reason_code']==='discovered_not_crawled','unindexed live page remains unresolved with correct Google reason');
$pdo->exec("UPDATE seo_expert_tasks SET title='Submit your sitemap in Search Console',page_url='https://example.com/',instructions='URL: https://example.com/sitemap.xml'");
$fake->during=fn()=>$pdo->exec('UPDATE seo_expert_runs SET version=version+1 WHERE id=9001');$old=$task()['evidence_json'];check(denied(fn()=>$run())&&$task()['evidence_json']===$old,'concurrent plan edit cannot be overwritten after network read');
$fake->during=fn()=>$pdo->exec("UPDATE seo_expert_tasks SET evidence_json='{\"client_evidence\":\"Concurrent customer evidence\"}' WHERE id=9021");check(denied(fn()=>$run())&&str_contains($task()['evidence_json'],'Concurrent customer evidence'),'concurrent evidence is preserved even without a version change');
$fake->during=fn()=>$pdo->exec("UPDATE websites SET main_url='https://changed.test' WHERE id=1");check(denied(fn()=>$run()),'website identity rechecked after provider read');$pdo->exec("UPDATE websites SET main_url='https://example.com' WHERE id=1");
$pdo->exec("UPDATE seo_expert_tasks SET title='Not indexed: quiz',page_url='https://example.com/quiz'");check(denied(fn()=>$run()),'quiz indexing intent cannot be automatically closed');
echo "$n assertions passed\n";
