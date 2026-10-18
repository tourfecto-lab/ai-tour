<?php
require_once __DIR__.'/../../app/Services/Publishing/WordPressConnector.php';
require_once __DIR__.'/../../app/Services/SeoImpact/SeoImpactRules.php';
require_once __DIR__.'/../../app/Services/Analytics/GoogleAnalyticsAPI.php';
require_once __DIR__.'/../../app/Services/SearchConsole/GoogleSearchConsoleAPI.php';
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label.PHP_EOL;}
function denied($fn):bool{try{$fn();return false;}catch(InvalidArgumentException $e){return true;}}
$home='https://example.com';$url=$home.'/?tf_article=reviewed-route';$p=SeoImpactRules::page($url,$home);
check($p['path']==='/?tf_article=reviewed-route','valid connector article route retains exact query identity');
check($p['hash']!==SeoImpactRules::page($home.'/',$home)['hash']&&$p['hash']!==SeoImpactRules::page($home.'/?tf_article=another-route',$home)['hash'],'homepage and different articles have distinct monitor identities');
foreach(['?utm_source=test','?tf_article=one&preview=1','?tf_article=one&tf_article=two','?tf_article=../secret','?tf_article=%2Fsecret','?tf_article[]=one','?tf_article=','?tf_article=one#fragment'] as $bad)check(denied(fn()=>SeoImpactRules::page($home.'/'.$bad,$home)),'reject unsupported or ambiguous route '.$bad);
check(denied(fn()=>SeoImpactRules::page('https://other.example/?tf_article=one',$home)),'query route does not bypass website ownership validation');
$calls=[];$api=new GoogleAnalyticsAPI('offline',function($type,$endpoint,$payload)use(&$calls){$calls[]=$payload;return ['success'=>true,'data'=>['rows'=>[['metricValues'=>array_map(fn($v)=>['value'=>(string)$v],[10,8,.2,3,1,99])]]]];});
$api->getLandingPeriod('123',$url,'2026-09-01','2026-09-07');$filter=$calls[0]['dimensionFilter']['andGroup']['expressions'][1]['filter'];
check($filter['fieldName']==='landingPagePlusQueryString'&&$filter['stringFilter']['value']==='/?tf_article=reviewed-route'&&$filter['stringFilter']['matchType']==='EXACT','GA4 compares the exact article landing route rather than all homepage sessions');
$api->getLandingPeriod('123',$home.'/tours','2026-09-01','2026-09-07');$filter=$calls[1]['dimensionFilter']['andGroup']['expressions'][1]['filter'];
check($filter['fieldName']==='landingPage'&&$filter['stringFilter']['value']==='/tours','existing clean-page GA4 filter remains compatible');
$search=[];$gsc=new GoogleSearchConsoleAPI('offline',function($request)use(&$search){$search[]=json_decode($request['body'],true);return ['http_code'=>200,'body'=>json_encode(['rows'=>[['clicks'=>2,'impressions'=>10,'position'=>3]]])];});
$result=$gsc->getPagePeriod('sc-domain:example.com',$url,'2026-09-01','2026-09-07');
check($search[0]['dimensionFilterGroups'][0]['filters'][0]['expression']===$url&&$result['clicks']===2,'GSC exact-page comparison retains the full article URL');
foreach([7,14,28] as $days){
 $due=(new DateTimeImmutable('2026-10-17'))->modify('+'.($days+3).' days')->format('Y-m-d');
 $prior=(new DateTimeImmutable($due))->modify('-1 day')->format('Y-m-d');
 check(!SeoImpactRules::periods('2026-10-17',$days,$prior)['ready']&&SeoImpactRules::periods('2026-10-17',$days,$due)['ready'],'window '.$days.' waits for a full period plus three days of provider latency');
}
echo "$n assertions passed\n";
