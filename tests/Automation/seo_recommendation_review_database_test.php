<?php
define('TOURFECTO_STORAGE',sys_get_temp_dir().'/tf-recommendation-review-'.getmypid());
mkdir(TOURFECTO_STORAGE.'/cache',0700,true);file_put_contents(TOURFECTO_STORAGE.'/cache/queue-heartbeat.json',json_encode(['time'=>time(),'status'=>'running']));
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
$page=['url'=>'https://example.com/tours/marsa-alam','h1'=>'Marsa Alam Tours','title'=>'Marsa Alam Tours | Example Tours','description'=>'Observed destination details and published tours for visitors considering a confirmed booking.','schema'=>[['@type'=>'ItemList']],'robots'=>''];
$snapshot=['brief'=>$brief,'config_version'=>1,'opportunities'=>['scope_version'=>2],'pages'=>[$page]];
$pdo->prepare("INSERT INTO seo_expert_runs(id,website_id,user_id,request_key,status,version,snapshot_json,created_at,updated_at) VALUES(9001,1,1,'recommendation-fixture','completed',1,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([json_encode($snapshot)]);
$insert=$pdo->prepare("INSERT INTO seo_expert_tasks(id,run_id,website_id,user_id,kind,category,page_url,page_path,title,instructions,field_name,expected_value,before_json,status,evidence_json,created_at,updated_at) VALUES(?,9001,1,1,?,'seo',?,'/tours/marsa-alam',?,'Review this retained proposal.',?,?,'[]',?,'{\"client_evidence\":\"Keep this customer review note.\"}',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$insert->execute([9021,'metadata',$page['url'],'Trip proposal','jsonld','{"@type":"TouristTrip"}','pending']);
$insert->execute([9022,'metadata',$page['url'],'Safe breadcrumb','jsonld','{"@type":"BreadcrumbList"}','pending']);
$insert->execute([9023,'manual','https://example.com/','Submit your sitemap in Search Console','gsc_sitemap','https://example.com/sitemap.xml','verified']);
$insert->execute([9024,'connection','https://example.com/','Connected Google','gsc_connection',null,'verified']);
$insert->execute([9025,'metadata',$page['url'],'Observed title','title',$page['title'],'verified']);
$pdo->exec("UPDATE seo_expert_tasks SET checked_at='2026-10-17 12:00:00' WHERE id IN (9023,9024)");
class ReviewPageReader extends SeoExpertPageContext {
 public array $reads=[];
 public function fresh(string $url):array{$this->reads[]=$url;return ['ok'=>true,'title'=>'Marsa Alam Tours | Example Tours','values'=>['title'=>['Marsa Alam Tours | Example Tours']]];}
}
$reader=new ReviewPageReader();$service=new SeoExpertService($db,null,$reader);
$rows=fn()=>$pdo->query('SELECT * FROM seo_expert_tasks ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$before=$rows();$detail=$service->detail(1,9001);
check($detail['tasks'][0]['recommendation_review']['state']==='excluded','completed legacy task is labelled using full stored page context');
check($detail['tasks'][1]['recommendation_review']['state']==='reviewable','safe collection breadcrumb remains available');
check($rows()===$before,'detail assessment changes no task rows or customer evidence');
check(denied(fn()=>$service->detail(2,9001)),'review does not expose another customer plan');
$approval=fn($id,$version=1)=>$service->approve(1,9001,['reviewed'=>true,'version'=>$version,'task_ids'=>[$id]]);
check(denied(fn()=>$approval(9021)),'forged approval selection cannot queue a collection trip schema');
check($pdo->query('SELECT status FROM seo_expert_runs')->fetchColumn()==='completed'&&(int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0,'rejected approval cannot activate automation or queue execution');
$r=$approval(9022);check($r['run']['status']==='executing'&&$r['tasks'][1]['status']==='approved','legitimate breadcrumb can still be approved normally');
check((int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE job_class='SeoExpertExecuteJob'")->fetchColumn()===1,'safe approved task uses existing execution queue');
$pdo->exec("UPDATE seo_expert_sites SET status='draft';UPDATE seo_expert_runs SET status='completed';UPDATE seo_expert_tasks SET status='pending' WHERE id=9022;DELETE FROM jobs");
$googleBefore=array_values(array_filter($rows(),fn($t)=>in_array($t['id'],[9023,9024])));
$service->execute(9001,'verify',3);
$googleAfter=array_values(array_filter($rows(),fn($t)=>in_array($t['id'],[9023,9024])));
check($googleBefore===$googleAfter,'public verification preserves manual/connection Google status, timestamps and customer proof byte for byte');
check($reader->reads===[$page['url']],'public worker fetches only an applied metadata page');
$applied=$pdo->query('SELECT * FROM seo_expert_tasks WHERE id=9025')->fetch(PDO::FETCH_ASSOC);
check($applied['status']==='verified'&&json_decode($applied['evidence_json'],true)['state']==='verified','normal applied metadata still receives fresh public verification');
check((int)$pdo->query('SELECT COUNT(*) FROM seo_expert_tasks')->fetchColumn()===5,'excluded and completed tasks are retained, never deleted');
check((int)$pdo->query('SELECT COUNT(*) FROM plan_usage_events')->fetchColumn()===0,'review and verification do not consume generation allowance');
unlink(TOURFECTO_STORAGE.'/cache/queue-heartbeat.json');rmdir(TOURFECTO_STORAGE.'/cache');
echo "$n assertions passed\n";
