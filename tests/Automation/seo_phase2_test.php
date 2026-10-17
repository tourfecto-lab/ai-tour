<?php
/** Phase 2 offline regression coverage. Earlier phase documentation referenced an untracked suite; this file tests the shipped contracts. */
$root=dirname(__DIR__,2);
require $root.'/app/Core/Contracts/KeywordRankingSourceInterface.php';
foreach(['DataForSeoClient','DataForSeoRankingSource','DataForSeoResearchService','SeoKeywordGapService','SeoMarketService'] as $name)require $root.'/app/Services/Seo/'.$name.'.php';
$n=0;$fails=0;function check($c,$m){global $n,$fails;$n++;echo ($c?'  ok  ':'  FAIL ').$m."\n";if(!$c)$fails++;}
$settings=['enabled'=>'1','login'=>'offline','password'=>'fixture','monthly_budget_usd'=>10,'location_code'=>2818,'language_code'=>'ar'];
$ledger=new class {public array $settled=[];public function reserve(...$args){return 1;}public function settle(...$args){$this->settled[]=$args;}};
$calls=[];$client=new DataForSeoClient($settings,function($endpoint,$tasks)use(&$calls){$calls[]=[$endpoint,$tasks];return ['status_code'=>20000,'cost'=>0.01,'tasks'=>[['status_code'=>20000,'cost'=>0.01,'result'=>[['items'=>[['type'=>'organic','rank_group'=>2,'url'=>'https://site.example/tours/luxor']]]]]]];},$ledger);
$source=new DataForSeoRankingSource($settings,null,$client,1);$r=$source->check('site.example',['Luxor tour']);
check($r['available']&&$r['rankings'][0]['position']===2,'organic measured position parsed');
check($calls[0][1][0]['location_code']===2818&&$calls[0][1][0]['language_code']==='ar','SERP uses explicit country and language');
check($ledger->settled[0][1]===0.01,'top-level cost not double counted with per-task cost');
check(!isset($calls[0][1][0]['login'],$calls[0][1][0]['password']),'provider credentials absent from task data');
check($source->check('site.example',array_fill(0,21,'different'))['available'],'duplicate keywords deduplicated before requests');
$many=array_map(fn($i)=>'keyword '.$i,range(1,21));check($source->check('site.example',$many)['reason']==='rank_batch_limit_20','rank batch bounded at twenty distinct keywords');
check($source->check('site.example',['site:other.example'])['reason']==='search_operators_not_supported','advanced search operators rejected');
check(DataForSeoRankingSource::matchesHost('https://www.site.example/a','site.example'),'www host matches owned domain');
check(!DataForSeoRankingSource::matchesHost('https://site.example.attacker/a','site.example'),'lookalike host cannot supply customer ranking');
$parsed=DataForSeoResearchService::parse(['keyword'=>'luxor','keyword_info'=>['search_volume'=>0,'cpc'=>0],'keyword_properties'=>['keyword_difficulty'=>0]]);
check($parsed['volume']===0&&(float)$parsed['difficulty']===0.0&&(float)$parsed['cpc']===0.0,'zero keyword measurements preserved');
check(DataForSeoResearchService::parse(['keyword'=>'luxor'])['volume']===null,'unknown volume is null');
$off=new DataForSeoClient(array_replace($settings,['enabled'=>'0']),fn()=>throw new RuntimeException('must not call'),$ledger);
check(!$off->call('serp/google/organic/live/advanced',[[]],1,1,.1)['available'],'disabled provider makes no HTTP call');
$broken=new DataForSeoClient($settings,fn()=>throw new RuntimeException('credential-secret'),$ledger);
$r=$broken->call('serp/google/organic/live/advanced',[[]],1,1,.1);check($r['reason']==='dataforseo_request_failed'&&!str_contains(json_encode($r),'credential-secret'),'transport failure does not leak credentials');
$absentClient=new DataForSeoClient($settings,fn()=>['status_code'=>20000,'tasks'=>[['status_code'=>20000,'cost'=>0,'result'=>[['items'=>[]]]]]],$ledger);
$absent=(new DataForSeoRankingSource($settings,null,$absentClient,1))->check('site.example',['luxor']);check($absent['available']&&$absent['rankings'][0]['position']===null,'measured absence stays null rather than fake rank');
check(SeoMarketService::defaultMarket('ar')['location_code']===2818,'Arabic default market is Egypt');
echo "\n".($n-$fails)."/$n passed\n";exit($fails?1:0);
