<?php
/** Reuses the guarded disposable GEO fixture database; never accepts an unmarked populated DB. */
require __DIR__.'/fixtures/geo_database.php';
$n=0;$fails=0;function check($ok,$message){global $n,$fails;$n++;echo ($ok?'  ok  ':'  FAIL ').$message."\n";if(!$ok)$fails++;}
$sql=file_get_contents($root.'/database/migrations/2026_10_17_000003_content_quality.sql');$pdo->exec($sql);$pdo->exec($sql);
$pdo->exec('DELETE FROM seo_content_quality; DELETE FROM seo_content_refresh_tasks');
$service=new ContentQualityService($db);
check($service->ready(),'additive migration installs content state and repeats');
$brief=['keyword'=>'Luxor tour','volume'=>100,'market'=>['id'=>1],'profile_version'=>1,'confirmed_facts'=>['business_name'=>'Fixture'],'people_also_ask'=>['Old question?'],'ranking_pages'=>[['url'=>'https://example.com/guides/luxor','position'=>2],['url'=>'https://rival.example/luxor','position'=>6]]];
$data=['title'=>'Luxor guide','meta_description'=>'Real Luxor advice','faqs'=>[['question'=>'What to bring?','answer'=>'Bring water.']]];
$pages=[['url'=>'https://example.com/tours/luxor','title'=>'Luxor tour']];
$service->remember(1,1,'article:11',$brief,$data,11,null,$pages,'https://example.com');$service->remember(1,1,'article:11',$brief,$data,11,null,$pages,'https://example.com');
check((int)$pdo->query('SELECT COUNT(*) FROM seo_content_quality')->fetchColumn()===1,'brief storage is repeatable per site and source');
$stored=$pdo->query('SELECT * FROM seo_content_quality')->fetch();
check(json_decode($stored['faqs_json'],true)===$data['faqs'] && $stored['pillar_url']===$pages[0]['url'],'FAQs and measured keyword cluster persist');
$denied=false;try{$service->remember(2,1,'article:12',$brief,$data,12);}catch(InvalidArgumentException $e){$denied=true;}
check($denied,'foreign owner cannot save site content evidence');
$service->published(1,1,'article:11','https://example.com/guides/luxor');
$rows=$db->query('SELECT * FROM seo_content_quality');$rules=ContentQualityService::rules($rows,'example.com');
check(isset($rules['/guides/luxor']['jsonld'],$rules['/tours/luxor']['related_links']),'stored publication compiles exact page schema and reciprocal links');
$requests=[];$publisher=new WordPressPublisher(function($r)use(&$requests){$requests[]=$r;return ['http_code'=>200,'body'=>json_encode(str_ends_with($r['url'],'preview')?['token'=>'signed']:['success'=>true,'post_id'=>42])];});
$seo=$service->syncWordPress(['id'=>11,'user_id'=>1,'website_id'=>1,'title'=>'Luxor guide','meta_description'=>'Real advice'],$publisher,'https://example.com','fixture','offline',42,'https://example.com/guides/luxor');
check($seo['success'] && $pdo->query('SELECT seo_state FROM seo_content_quality')->fetchColumn()==='applied','publication sync applies signed SEO fields and saves success');
$offline=new WordPressPublisher(fn($r)=>['http_code'=>404,'body'=>'{"message":"Update plugin"}']);
$service->syncWordPress(['id'=>11,'user_id'=>1,'website_id'=>1,'title'=>'Luxor guide'],$offline,'https://example.com','fixture','offline',42,'https://example.com/guides/luxor');
check($pdo->query('SELECT seo_state FROM seo_content_quality')->fetchColumn()==='needs_plugin_update','old plugin stores visible actionable SEO status without republishing the post');
$windows=ContentDecayService::windows();$api=new GoogleSearchConsoleAPI('offline',function($request)use($windows){$in=json_decode($request['body'],true);$old=$in['startDate']===$windows['previous'][0];return ['http_code'=>200,'body'=>json_encode(['rows'=>[['keys'=>['https://example.com/guides/luxor'],'clicks'=>$old?100:70,'impressions'=>1000,'position'=>$old?2:6]]])];});
$fresh=array_replace($brief,['people_also_ask'=>['New question?'],'ranking_pages'=>[['url'=>'https://example.com/guides/luxor','position'=>6],['url'=>'https://rival.example/luxor','position'=>2]]]);
$briefSource=new class($fresh){public function __construct(private array $brief){} public function forKeyword(...$args){return $this->brief;}};
$reader=new ContentBriefService(null,fn($url)=>'<html><h1>Luxor 2024</h1><p>Price USD 65</p></html>');
$decay=new ContentDecayService($db,$briefSource,$reader);$result=$decay->run(1,1,$api,'sc-domain:example.com');
check($result['created']===1,'real GSC response parser creates a weekly decay review task');
$task=$pdo->query('SELECT * FROM seo_content_refresh_tasks')->fetch();$refresh=json_decode($task['brief_json'],true);
check($task['status']==='pending' && $refresh['comparison']['click_loss_ratio']===0.3 && $refresh['comparison']['position_loss']===4,'decay evidence preserves exact clicks/rank math and pending review state');
check($refresh['new_questions']===['New question?'] && count($refresh['competitors_that_overtook'])===1 && $refresh['prices_to_confirm']===['USD 65'],'persisted refresh brief contains new questions, measured overtaking and literal price confirmation');
check($decay->run(1,1,$api,'sc-domain:example.com')['created']===0,'weekly rerun creates no duplicate refresh task');
$badApi=new GoogleSearchConsoleAPI('offline',fn($r)=>['http_code'=>503,'body'=>'{}']);
check($decay->run(1,1,$badApi,'sc-domain:example.com')['state']==='unavailable','GSC outage is unavailable rather than fake traffic decline');
echo "\n".($n-$fails)."/$n passed\n";exit($fails?1:0);
