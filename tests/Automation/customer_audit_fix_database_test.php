<?php
require __DIR__.'/fixtures/geo_database.php';
define('TOURFECTO_STORAGE',sys_get_temp_dir().'/tf-audit-db-'.getmypid());mkdir(TOURFECTO_STORAGE.'/cache',0700,true);
$n=0;$fails=0;function check($c,$m){global $n,$fails;$n++;if(!$c)$fails++;echo($c?'  ok  ':'  FAIL ').$m.PHP_EOL;}function denied($fn){try{$fn();return false;}catch(InvalidArgumentException $e){return true;}}
$pdo->exec(file_get_contents($root.'/database/migrations/2026_10_12_000002_seo_expert.sql'));
$pdo->exec('DELETE FROM seo_expert_tasks;DELETE FROM seo_expert_runs;DELETE FROM seo_expert_sites');
$pdo->exec("INSERT INTO seo_expert_sites(website_id,user_id,brief_json,policy_json,budget_month,created_at,updated_at) VALUES(1,1,'{\"source_site_url\":\"https://example.com\"}','{}','2026-10',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$pdo->exec("INSERT INTO seo_expert_runs(id,website_id,user_id,request_key,status,snapshot_json,created_at,updated_at) VALUES(9001,1,1,'audit-run','completed','{}',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
require_once $root.'/app/Services/SeoExpert/SeoExpertService.php';
$service=new SeoExpertService($db);
check(denied(fn()=>$service->recheck(2,9001)),'foreign owner cannot request verification');
check(denied(fn()=>$service->recheck(1,9001)),'missing worker cannot create a misleading verification job');
file_put_contents(TOURFECTO_STORAGE.'/cache/queue-heartbeat.json',json_encode(['time'=>time(),'status'=>'running']));
$pdo->exec("UPDATE seo_expert_runs SET status='executing' WHERE id=9001");check(denied(fn()=>$service->recheck(1,9001)),'active cycle cannot be replayed by verification button');$pdo->exec("UPDATE seo_expert_runs SET status='completed' WHERE id=9001");
$billing=(int)$pdo->query('SELECT COUNT(*) FROM plan_usage_events')->fetchColumn();
$r=$service->recheck(1,9001);check($r['verification_queued']&&$r['run']['status']==='completed','recheck queues observation without creating or applying a new plan');
$job=$pdo->query("SELECT payload FROM jobs WHERE job_class='SeoExpertExecuteJob'")->fetchColumn();$payload=json_decode($job,true);
check($payload['operation']==='verify'&&(int)$payload['attempt']===3&&(int)$payload['run_id']===9001,'queued operation verifies once without applying or publishing');
$service->recheck(1,9001);check((int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE job_class='SeoExpertExecuteJob'")->fetchColumn()===1,'repeated recheck does not create duplicate pending jobs');
check((int)$pdo->query('SELECT COUNT(*) FROM plan_usage_events')->fetchColumn()===$billing,'verification request consumes no generation allowance');
class AuditPublicationReader extends SeoExpertPageContext {
    public function publicationSitemap(string $siteUrl,string $articleUrl,bool $connector): array {return ['state'=>'not_measured'];}
    public bool $complete=false;
    public function fresh(string $url): array {return ['ok'=>true,'final_url'=>$url,'title'=>'Guide title','text'=>$this->complete?'Intro Heading Main content with 2 temples.':'Intro Heading','values'=>[]];}
}
$pdo->exec("INSERT INTO seo_expert_tasks(run_id,website_id,user_id,kind,category,page_url,page_path,title,instructions,field_name,expected_value,status,is_active,published_url,created_at,updated_at) VALUES(9001,1,1,'content','aeo','https://example.com/','/','Guide title','Approved article','content','Intro **Heading** Main content with 2 temples.','verified',1,'https://example.com/guides/guide',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$reader=new AuditPublicationReader();$executor=new SeoExpertService($db,null,$reader);$executor->execute(9001,'verify',3);
$row=$pdo->query('SELECT * FROM seo_expert_tasks WHERE run_id=9001')->fetch();
check($row['status']==='needs_client','public title plus partial content does not verify the complete article');
check((int)$row['is_active']===1&&$row['expected_value']==='Intro **Heading** Main content with 2 temples.','failed verification preserves the approved active article and its body');
$reader->complete=true;$executor->execute(9001,'verify',3);
$row=$pdo->query('SELECT * FROM seo_expert_tasks WHERE run_id=9001')->fetch();check($row['status']==='verified','restored complete article can be reverified after a needs-client observation');
check((int)$pdo->query('SELECT COUNT(*) FROM plan_usage_events')->fetchColumn()===$billing,'complete verification worker also consumes no generation allowance');
@unlink(TOURFECTO_STORAGE.'/cache/queue-heartbeat.json');@rmdir(TOURFECTO_STORAGE.'/cache');@rmdir(TOURFECTO_STORAGE);
echo PHP_EOL.($n-$fails)."/$n passed".PHP_EOL;exit($fails?1:0);
