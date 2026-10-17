<?php
/** Offline: mock Database + fake provider transport/ledger; no SQLite, network, accounts or billing. */
$root=dirname(__DIR__,2);
require __DIR__.'/fixtures/dataforseo_research_database.php';
require $root.'/app/Core/Contracts/KeywordRankingSourceInterface.php';
foreach(['DataForSeoClient','DataForSeoRankingSource','SeoMarketService','DataForSeoResearchService','RankTrackingService','SeoKeywordGapService'] as $name)require $root.'/app/Services/Seo/'.$name.'.php';
$n=0;$fails=0;
function check(bool $ok,string $message): void {global $n,$fails;$n++;if(!$ok)$fails++;echo($ok?'  ok  ':'  FAIL ').$message.PHP_EOL;}
function denied(callable $fn): bool {try{$fn();return false;}catch(InvalidArgumentException $e){return true;}}
function responseItems(array $items): array {return ['status_code'=>20000,'cost'=>0.002,'tasks'=>[['status_code'=>20000,'cost'=>0.002,'result'=>[['items'=>$items]]]]];}
function keywordItem(string $keyword='luxor tours',int $volume=120): array {return ['keyword'=>$keyword,'keyword_info'=>['search_volume'=>$volume,'cpc'=>0],'search_intent_info'=>['main_intent'=>'transactional'],'keyword_properties'=>['keyword_difficulty'=>12]];}
function competitorItem(string $keyword='luxor tours',int $volume=120): array {return ['keyword_data'=>keywordItem($keyword,$volume),'ranked_serp_element'=>['serp_item'=>['rank_group'=>3,'url'=>'https://rival.example/luxor']]];}
class ResearchLedger {
    public array $reservations=[],$settlements=[];
    public function reserve(...$args): int {$this->reservations[]=$args;return count($this->reservations);}
    public function settle(...$args): void {$this->settlements[]=$args;}
}
$settings=['enabled'=>'1','login'=>'offline','password'=>'fixture','monthly_budget_usd'=>10,'location_code'=>2818,'language_code'=>'ar'];
function fixture(?callable $provider=null): array {
    global $settings;$db=new Database();$ledger=new ResearchLedger();$requests=[];
    $transport=function($endpoint,$tasks)use(&$requests,$provider){$requests[]=[$endpoint,$tasks];if($provider)return $provider($endpoint,$tasks);
        if(str_contains($endpoint,'bulk_keyword_difficulty'))return responseItems([['keyword'=>'luxor tours','keyword_difficulty'=>37]]);
        if(str_contains($endpoint,'ranked_keywords'))return responseItems([competitorItem()]);
        if(str_contains($endpoint,'serp/google/organic'))return responseItems([]);
        return responseItems([keywordItem(),keywordItem('zero demand',0)]);
    };
    $client=new DataForSeoClient($settings,$transport,$ledger);$research=new DataForSeoResearchService($db,$client);
    return [$db,$ledger,$client,$research];
}
check(DataForSeoClient::items([])===[]&&DataForSeoClient::items(['response'=>['tasks'=>[]]])===[],'items helper safely defaults missing response/task/result to empty');
check(DataForSeoClient::items(['response'=>responseItems([keywordItem()])])[0]['keyword']==='luxor tours','items helper reads the call response envelope');
$parsed=DataForSeoResearchService::parse(competitorItem());
check($parsed['volume']===120&&$parsed['position']===3&&$parsed['url']==='https://rival.example/luxor','ranked keyword parsing retains demand, position and URL');
check($parsed['intent']==='transactional'&&$parsed['cpc']===0.0,'intent and measured zero CPC survive parsing');
$unknown=DataForSeoResearchService::parse(['keyword'=>'unknown']);check($unknown['volume']===null&&$unknown['difficulty']===null&&$unknown['intent']===null,'missing demand is unknown rather than fabricated zero');
[$db,$ledger,$client,$research]=fixture();check($research->configured(),'db-first constructor accepts injected configured client');
$r=$research->research(41,701,9,[' luxor tours ','luxor tours']);
check($r['available']&&!$r['from_cache']&&count($r['data'])===2,'cache miss performs real parsing through fake transport');
check($r['data'][0]['difficulty']===37.0&&$r['data'][1]['volume']===0,'bulk difficulty enriches results without dropping measured zero volume');
check(count($db->research)===2&&$db->research['41|9|luxor tours']['volume']===120,'research records are saved with website and market');
$cache=array_values($db->cache)[0];check(json_decode($cache['result_json'],true)===$r['data'],'cache stores the parsed result JSON using the actual schema');
check($ledger->reservations[0][2]===1&&$ledger->reservations[1][2]===2,'discovery bills one call, difficulty bills actual keyword count rather than user ID');
check($ledger->reservations[0][1]===41&&$ledger->reservations[0][3]===0.10,'quota request uses the website and bounded dollar ceiling');
$before=count($ledger->reservations);$writes=count($db->writes);$hit=$research->research(41,701,9,['luxor tours']);
check($hit['from_cache']&&$hit['data']===$r['data']&&count($ledger->reservations)===$before,'fresh cache hit returns identical data with no paid provider calls');
check(count($db->writes)===$writes,'fresh cache hit does not rewrite research records');
check(str_contains(end($db->locks)[0],'RELEASE_LOCK'),'research releases advisory lock on cache hit');
$research->research(41,701,10,['luxor tours']);check(count($db->cache)===2&&count($ledger->reservations)===$before+2,'same keyword in another market gets an independent cache and measurement');
foreach($db->cache as &$c)$c['fetched_at']=gmdate('Y-m-d H:i:s',time()-31*86400);unset($c);
$before=count($ledger->reservations);$research->research(41,701,9,['luxor tours']);check(count($ledger->reservations)===$before+2,'expired cache is a miss and gets refreshed');
$before=count($ledger->reservations);check(denied(fn()=>$research->research(41,702,9,['luxor tours']))&&count($ledger->reservations)===$before,'foreign owner cannot read cached data or spend quota');
check(denied(fn()=>$research->research(41,701,999,['luxor tours'])),'foreign market is rejected before provider requests');
[$db,$ledger,$client,$research]=fixture();$research->research(41,701,9,['luxor tours','zero demand'],true);
check(str_contains($ledger->reservations[0][0],'keyword_overview')&&$ledger->reservations[0][2]===2,'exact lookup charges number of distinct requested terms');
$research->research(41,701,9);check(str_contains($ledger->reservations[2][0],'keywords_for_site')&&$ledger->reservations[2][2]===1,'site discovery charges one unit');
[$db,$ledger,$client,$research]=fixture(function($endpoint,$tasks){check(($tasks[0]['target']??'')==='customer.example','site target uses existing normalizeHost helper');return responseItems([]);});
$r=$research->research(41,701,9);$hit=$research->research(41,701,9);
check($r['available']&&$r['data']===[]&&$hit['from_cache']&&count($ledger->reservations)===1,'measured empty result is cached and does not trigger bulk request');
[$db,$ledger,$client,$research]=fixture(fn()=>['status_code'=>20000,'cost'=>0,'tasks'=>[['status_code'=>20000,'result'=>[[]]]]]);
$r=$research->research(41,701,9);check(!$r['available']&&$r['reason']==='dataforseo_invalid_response'&&!$db->writes,'missing items are rejected without persisting fabricated data');
check(str_contains(end($db->locks)[0],'RELEASE_LOCK'),'research releases advisory lock after invalid response');
[$db,$ledger,$client,$research]=fixture();$db->lockAvailable=false;
check($research->research(41,701,9)['reason']==='research_busy'&&!$ledger->reservations,'busy research lock prevents billing');
[$db,$ledger,$client,$research]=fixture(function($endpoint){return responseItems(str_contains($endpoint,'bulk_keyword_difficulty')?[]:array_map(fn($i)=>keywordItem('term '.$i),range(1,105)));});
$r=$research->research(41,701,9);check(count($r['data'])===100&&$ledger->reservations[1][2]===100,'oversized provider results cannot create a bulk request above 100 units');
$db=new Database();$off=new DataForSeoClient(['enabled'=>'0'],fn()=>throw new RuntimeException('Transport must not run'),new ResearchLedger());$offResearch=new DataForSeoResearchService($db,$off);
check(!$offResearch->configured()&&$offResearch->research(41,701)['reason']==='not_measured'&&$offResearch->competitors(41,701)['reason']==='not_measured','disabled configuration returns explicit unavailable contracts without transport');
[$db,$ledger,$client,$research]=fixture();$db->competitors[5]=['id'=>5,'website_id'=>41,'user_id'=>701,'competitor_domain'=>'https://www.rival.example/trip','is_active'=>1,'monitoring_paused'=>0];
$r=$research->competitors(41,701);check($r['available']&&array_key_exists('reason',$r)&&$r['reason']===null&&$r['recorded']===1,'competitor success always includes nullable reason and recorded count');
$k=$db->competitorKeywords[0];check($k['competitor_id']===5&&$k['market_id']===9&&$k['position']===3&&$k['search_volume']===120&&$k['source']==='dataforseo','competitor keyword insert preserves ownership, market, demand and ranking provenance');
check($ledger->reservations[0][2]===1,'competitor discovery is one quota unit, not owner ID or requested result limit');
$before=count($ledger->reservations);$r=$research->competitors(41,701);check($r['available']&&$r['reason']===null&&$r['recorded']===0&&count($ledger->reservations)===$before,'fresh competitor keywords skip additional paid calls');
$db->competitorKeywords[0]['fetched_at']=gmdate('Y-m-d H:i:s',time()-31*86400);$research->competitors(41,701);
check(count($db->competitorKeywords)===1&&count($ledger->reservations)===$before+1,'stale competitor measurements are replaced instead of duplicated');
[$emptyDb,$emptyLedger,$emptyClient,$emptyResearch]=fixture();$r=$emptyResearch->competitors(41,701);check($r['available']&&$r['reason']===null&&!$emptyLedger->reservations,'no active competitors returns valid empty success without billing');
[$failDb,$failLedger,$failClient,$failResearch]=fixture(fn()=>throw new RuntimeException('offline failure'));$failDb->competitors=$db->competitors;
$r=$failResearch->competitors(41,701);check(!$r['available']&&$r['reason']==='dataforseo_request_failed'&&$r['recorded']===0&&!$failDb->writes,'competitor transport failure exposes a reason and does not replace old measurements');
// Use the real gap service, real rank tracker and real organic parser, all sharing fake transport.
$ranking=new RankTrackingService(new DataForSeoRankingSource($settings,null,$client,41));
$r=SeoKeywordGapService::refresh($db,41,701,$research,$ranking);
check($r['available']&&$r['reason']===null&&count($r['gaps'])===1,'gap refresh completes competitor research and customer SERP measurement');
check($r['gaps'][0]['evidence_state']==='VERIFIED'&&$r['gaps'][0]['customer_position']===null&&!empty($r['gaps'][0]['customer_checked_at']),'gap is reported only after measured customer absence, never an untracked assumption');
check(count($db->tracked)===1&&count($db->history)===1&&$db->history[0]['website_id']===41&&$db->history[0]['market_id']===9,'gap refresh inserts candidates and records market-scoped ranking history');
$before=count($ledger->reservations);SeoKeywordGapService::refresh($db,41,701,$research,$ranking);
check(count($db->tracked)===1&&count($db->history)===1&&count($ledger->reservations)===$before,'repeat gap refresh reuses fresh competitor and customer measurements');
$failDb->competitorKeywords=[];$r=SeoKeywordGapService::refresh($failDb,41,701,$failResearch,$ranking);
check(!$r['available']&&$r['reason']==='dataforseo_request_failed'&&!$failDb->tracked,'failed competitor refresh never invents customer candidates or gaps');
$r=SeoKeywordGapService::refresh($emptyDb,41,701,$emptyResearch,$ranking);
check($r['available']&&$r['reason']===null&&$r['gaps']===[],'empty gap refresh preserves the available/reason contract');
[$presentDb,$presentLedger,$presentClient,$presentResearch]=fixture(function($endpoint,$tasks){
    if(str_contains($endpoint,'ranked_keywords'))return responseItems([competitorItem()]);
    return responseItems([['type'=>'organic','rank_group'=>7,'url'=>'https://customer.example/trips/luxor']]);
});$presentDb->competitors=$db->competitors;
$presentRank=new RankTrackingService(new DataForSeoRankingSource($settings,null,$presentClient,41));
$r=SeoKeywordGapService::refresh($presentDb,41,701,$presentResearch,$presentRank);
check($r['available']&&$r['gaps']===[]&&$presentDb->history[0]['position']===7,'customer already ranking in top 20 is measured and excluded from gaps');
[$rankFailDb,$rankFailLedger,$rankFailClient,$rankFailResearch]=fixture(function($endpoint){
    if(str_contains($endpoint,'ranked_keywords'))return responseItems([competitorItem()]);
    throw new RuntimeException('offline rank failure');
});$rankFailDb->competitors=$db->competitors;
$rankFailRank=new RankTrackingService(new DataForSeoRankingSource($settings,null,$rankFailClient,41));
$r=SeoKeywordGapService::refresh($rankFailDb,41,701,$rankFailResearch,$rankFailRank);
check(!$r['available']&&$r['reason']==='dataforseo_request_failed'&&$r['gaps']===[]&&!$rankFailDb->history,'failed customer rank measurement cannot be labeled verified absence');
[$bulkDb,$bulkLedger,$bulkClient,$bulkResearch]=fixture(function($endpoint){
    if(str_contains($endpoint,'bulk_keyword_difficulty'))throw new RuntimeException('offline difficulty failure');
    return responseItems([keywordItem()]);
});$r=$bulkResearch->research(41,701,9,['luxor tours']);
check(!$r['available']&&$r['reason']==='dataforseo_request_failed'&&!$bulkDb->cache&&!$bulkDb->research,'failed difficulty request does not cache incomplete successful research');
echo PHP_EOL.($n-$fails)."/$n passed".PHP_EOL;exit($fails?1:0);
