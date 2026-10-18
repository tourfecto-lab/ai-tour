<?php
require __DIR__.'/../../app/Services/SearchConsole/GoogleSearchConsoleAPI.php';
require __DIR__.'/../../app/Services/SeoExpert/SeoConnectedTaskVerifier.php';
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
$payload=['verdict'=>'NEUTRAL','coverageState'=>'Discovered - currently not indexed','pageFetchState'=>'PAGE_FETCH_STATE_UNSPECIFIED'];$calls=[];
$api=new GoogleSearchConsoleAPI('offline',function($request)use(&$payload,&$calls){$calls[]=$request;return ['body'=>json_encode(['inspectionResult'=>['indexStatusResult'=>$payload]]),'http_code'=>200,'error'=>null];});
check($api->inspectUrl('sc-domain:example.com','https://example.com/tours')['reason']==='discovered_not_crawled','unspecified fetch preserves discovered, not crawled meaning');
$payload['coverageState']='URL is unknown to Google';check($api->inspectUrl('sc-domain:example.com','https://example.com/tours')['reason']==='unknown_to_google','unspecified fetch preserves unknown URL meaning');
$payload['pageFetchState']='SERVER_ERROR';check($api->inspectUrl('sc-domain:example.com','https://example.com/tours')['reason']==='fetch_server_error','actual fetch errors remain errors');
$payload['robotsTxtState']='DISALLOWED';check($api->inspectUrl('sc-domain:example.com','https://example.com/tours')['reason']==='robots_blocked','robots block keeps precedence');
$payload['robotsTxtState']='ALLOWED';$payload['indexingState']='BLOCKED_BY_META_TAG';check($api->inspectUrl('sc-domain:example.com','https://example.com/tours')['reason']==='noindex','noindex keeps precedence');
class ConnectedFakeGoogle {
 public array $maps=['ok'=>true,'sitemaps'=>[['path'=>'https://example.com/sitemap.xml','errors'=>0,'warnings'=>1,'pending'=>true,'last_downloaded'=>null]]];
 public array $index=['ok'=>true,'url'=>'https://example.com/tours','indexed'=>false,'verdict'=>'NEUTRAL','coverage'=>'Discovered - currently not indexed','reason'=>'discovered_not_crawled','last_crawl'=>null];
 public int $calls=0;public bool $fail=false;public $during=null;
 public function listSitemaps($p){$this->calls++;if($this->during){$f=$this->during;$this->during=null;$f();}if($this->fail)throw new RuntimeException('private-token');return $this->maps;}
 public function inspectUrl($p,$u){$this->calls++;return $this->index;}
}
$fake=new ConnectedFakeGoogle();$task=['kind'=>'manual','status'=>'needs_client','title'=>'Submit your sitemap in Search Console','page_url'=>'https://example.com/','instructions'=>"URL: https://example.com/sitemap.xml"];
$run=fn($t=null,$property='sc-domain:example.com')=>SeoConnectedTaskVerifier::check($t??$task,'https://example.com',$fake,$property);
$r=$run();check($r['state']==='verified'&&$r['reason_code']==='sitemap_submitted'&&$r['observed']['pending']===true,'pending sitemap confirms submission only and exposes warnings');
$fake->maps['sitemaps'][0]['path']='https://example.com/another.xml';check($run()['reason_code']==='sitemap_not_listed','a different listed sitemap cannot verify this task');
$fake->maps['sitemaps'][0]['path']='https://example.com/sitemap.xml';$fake->maps['sitemaps'][0]['errors']=2;check($run()['state']==='needs_review','reported sitemap errors remain unresolved');
$fake->maps['ok']=false;check($run()['state']==='not_measured','API failure is not a negative Google observation');$fake->maps['ok']=true;
$fake->fail=true;$r=$run();check($r['state']==='not_measured'&&!str_contains(json_encode($r),'private-token'),'exceptions do not expose credentials');$fake->fail=false;
$count=$fake->calls;check($run(null,'sc-domain:ample.com')['reason_code']==='property_mismatch'&&$fake->calls===$count,'wrong property rejected before API call');
$cross=$task;$cross['expected_value']='https://evil.test/sitemap.xml';check($run($cross)['reason_code']==='property_mismatch','customer tasks cannot check foreign domains');
$index=['kind'=>'manual','status'=>'needs_client','title'=>'Not indexed: old fetch message','page_url'=>'https://example.com/?tf_article=guide'];
check($run($index)['reason_code']==='discovered_not_crawled','query article URLs can be inspected without inventing indexing');
$fake->index['indexed']=true;$fake->index['verdict']='PASS';check($run($index)['state']==='verified','positive current Google inspection verifies indexing');
$fake->index=['ok'=>true];check($run($index)['state']==='not_measured','empty successful API response cannot unverify a task');
$index['page_url']='https://example.com/quiz';check(SeoConnectedTaskVerifier::kind($index)===null,'operational pages require a separate indexing intent decision');
$index['page_url']='https://example.com/tours';$index['kind']='metadata';check(SeoConnectedTaskVerifier::kind($index)===null,'metadata tasks cannot use connected manual verification');
check(!SeoConnectedTaskVerifier::covers('https://example.com','https://example.com/tours','https://example.com/tourstuff'),'URL prefix property has path boundary');
check(!SeoConnectedTaskVerifier::covers('https://example.com','sc-domain:example.com','https://user@example.com/'),'credential URL rejected');
check(!SeoConnectedTaskVerifier::covers('https://example.com','sc-domain:example.com','https://example.com:444/'),'foreign port rejected');
check(!SeoConnectedTaskVerifier::covers('https://example.com','sc-domain:example.com','https://example.com/#fragment'),'fragment URL rejected');
check(count($calls)===5&&count(array_filter($calls,fn($r)=>$r['method']==='POST'&&str_contains($r['url'],'urlInspection/index:inspect')))===5,'inspection uses only read-only inspection requests');
echo "$n assertions passed\n";
