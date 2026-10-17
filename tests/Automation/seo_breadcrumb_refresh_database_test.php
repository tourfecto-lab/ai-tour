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
$url='https://example.com/page/privacy';$snapshot=['brief'=>$brief,'pages'=>[['url'=>'https://example.com/page/'],['url'=>$url]]];
$pdo->prepare("INSERT INTO seo_expert_runs(id,website_id,user_id,request_key,status,version,snapshot_json,created_at,updated_at) VALUES(9001,1,1,'breadcrumb-refresh-fixture','completed',1,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([json_encode($snapshot)]);
$old=json_encode(['@context'=>'https://schema.org','@graph'=>[['@type'=>'BreadcrumbList','itemListElement'=>[['@type'=>'ListItem','position'=>1,'name'=>'Home','item'=>'https://example.com/'],['@type'=>'ListItem','position'=>2,'name'=>'Page','item'=>'https://example.com/page/'],['@type'=>'ListItem','position'=>3,'name'=>'Privacy Policy','item'=>$url]]]]]);
$insert=$pdo->prepare("INSERT INTO seo_expert_tasks(id,run_id,website_id,user_id,kind,category,page_url,page_path,title,instructions,field_name,expected_value,before_json,status,evidence_json,created_at,updated_at) VALUES(9021,9001,1,1,'metadata','geo',?,'/page/privacy','Add breadcrumb structured data','Review the current public breadcrumb proposal.','jsonld',?,'[]','needs_client',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$insert->execute([$url,$old,json_encode(['client_evidence'=>'Previous review: /page/ returned 404.'])]);
class RefreshReader extends SeoExpertPageContext {
    public array $reads=[];public array $pageChanges=[];public $duringRead=null;
    public function fresh(string $url):array{
        $this->reads[]=$url;if($this->duringRead){$f=$this->duringRead;$this->duringRead=null;$f();}
        $parent=str_ends_with($url,'/page/');return array_replace(['ok'=>!$parent,'http_status'=>$parent?404:200,'url'=>$url,'final_url'=>$url,'h1'=>'Privacy Policy','title'=>'Privacy Policy','schema'=>[],'text'=>'Read our privacy policy.'],!$parent?$this->pageChanges:[]);
    }
}
$reader=new RefreshReader();$service=new SeoExpertService($db,null,$reader);$edit=fn($uid=1,$version=1)=>$service->editTask($uid,9021,['refresh_breadcrumbs'=>true,'version'=>$version,'value'=>'{"@type":"Organization","name":"Injected"}']);
$task=fn()=>$pdo->query('SELECT * FROM seo_expert_tasks WHERE id=9021')->fetch(PDO::FETCH_ASSOC);
check(denied(fn()=>$edit(2)),'another customer cannot refresh the task');
check(denied(fn()=>$edit(1,0))&&$reader->reads===[],'stale versions fail before any public fetch');
$pdo->exec("UPDATE seo_expert_tasks SET is_active=1");check(denied(fn()=>$edit()),'active rules cannot be rewritten through refresh');$pdo->exec('UPDATE seo_expert_tasks SET is_active=0');
$pdo->exec("UPDATE seo_expert_tasks SET status='verified'");check(denied(fn()=>$edit()),'previously verified tasks cannot be refreshed');$pdo->exec("UPDATE seo_expert_tasks SET status='needs_client'");
$mixed=json_decode($old,true);$mixed['@graph'][]=['@type'=>'Organization','name'=>'Existing'];$pdo->prepare('UPDATE seo_expert_tasks SET expected_value=?')->execute([json_encode($mixed)]);
check(denied(fn()=>$edit()),'business schema mixed with breadcrumbs is not eligible');$pdo->prepare('UPDATE seo_expert_tasks SET expected_value=?')->execute([$old]);
$nested=json_decode($old,true);$nested['@graph'][0]['publisher']=['@type'=>'Organization','name'=>'Existing business'];$pdo->prepare('UPDATE seo_expert_tasks SET expected_value=?')->execute([json_encode($nested)]);
check(denied(fn()=>$edit()),'nested business schema is also protected');$pdo->prepare('UPDATE seo_expert_tasks SET expected_value=?')->execute([$old]);
$reads=count($reader->reads);$pdo->exec("UPDATE seo_expert_tasks SET page_url='https://other.example/page/privacy'");check(denied(fn()=>$edit())&&count($reader->reads)===$reads,'a task on another website is rejected before fetching');$pdo->prepare('UPDATE seo_expert_tasks SET page_url=?')->execute([$url]);
$pdo->exec('UPDATE business_hub_profiles SET version=2');check(denied(fn()=>$edit()),'changed confirmed business facts require review');$pdo->exec('UPDATE business_hub_profiles SET version=1');
foreach([
    'unavailable page'=>['ok'=>false,'http_status'=>404],
    'maintenance page'=>['coming_soon'=>true],
    'redirected page'=>['final_url'=>'https://other.example/privacy'],
    'changed public schema'=>['schema'=>[['@type'=>'Organization','name'=>'New']]],
] as $label=>$changes){$reader->pageChanges=$changes;check(denied(fn()=>$edit())&&$task()['expected_value']===$old,$label.' leaves the proposal unchanged');}
$reader->pageChanges=[];$reader->duringRead=fn()=>$pdo->exec('UPDATE seo_expert_runs SET version=2 WHERE id=9001');
check(denied(fn()=>$edit())&&$task()['expected_value']===$old,'a concurrent plan edit cannot be overwritten after the fetch');$pdo->exec('UPDATE seo_expert_runs SET version=1 WHERE id=9001');
$runs=(int)$pdo->query('SELECT COUNT(*) FROM seo_expert_runs')->fetchColumn();$jobs=(int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn();$usage=(int)$pdo->query('SELECT COUNT(*) FROM plan_usage_events')->fetchColumn();
$reader->reads=[];$result=$edit();$saved=$task();$schema=json_decode($saved['expected_value'],true);$items=$schema['@graph'][0]['itemListElement'];
check(array_column($items,'item')===['https://example.com/',$url],'refresh removes the now-404 parent from the actual saved proposal');
check($saved['status']==='pending'&&(int)$saved['is_active']===0,'refreshed proposal still requires approval before publication');
check((int)$result['run']['version']===2&&$result['run']['status']==='completed','refresh advances the version without restarting the cycle');
$evidence=json_decode($saved['evidence_json'],true);check($evidence['client_evidence']==='Previous review: /page/ returned 404.'&&$evidence['breadcrumb_refresh_history'][0]['previous_value']===$old,'prior review evidence and old proposal remain available');
check(!str_contains($saved['expected_value'],'Injected'),'submitted schema data cannot replace server-generated breadcrumbs');
check($result['tasks'][0]['breadcrumb_refreshable']===true,'detail exposes the dedicated review action');
check((int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===$jobs&&(int)$pdo->query('SELECT COUNT(*) FROM seo_expert_runs')->fetchColumn()===$runs,'refresh neither queues publishing nor starts another run');
check((int)$pdo->query('SELECT COUNT(*) FROM plan_usage_events')->fetchColumn()===$usage&&$pdo->query('SELECT status FROM seo_expert_sites')->fetchColumn()==='draft','refresh consumes no generation allowance and preserves draft automation');
check(denied(fn()=>$edit())&&(int)$pdo->query('SELECT version FROM seo_expert_runs')->fetchColumn()===2,'replaying the old request cannot silently refresh twice');
echo "$n assertions passed\n";
