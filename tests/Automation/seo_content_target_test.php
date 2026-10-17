<?php
require_once __DIR__.'/../../app/Services/SeoExpert/SeoContentTargetSelector.php';
require_once __DIR__.'/../../app/Services/SeoExpert/SeoPagePolicy.php';
require_once __DIR__.'/../../app/Services/Seo/ContentQualityService.php';
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
$now=strtotime('2026-10-17 UTC');$profile=['confirmed_at'=>'2026-10-15','facts'=>['business_name'=>'Egypt Travel','business_facts'=>'Private Egypt tours in Luxor and Aswan','audience'=>'Visitors from Greece and Canada']];
$pages=[['url'=>'https://example.com/tours/luxor','title'=>'Private Luxor Egypt day tours'],['url'=>'https://example.com/tours/egypt','title'=>'Egypt tours']];
$base=['market_id'=>1,'source'=>'dataforseo','fetched_at'=>'2026-10-17 00:00:00','intent'=>'informational'];
$rows=[];foreach([['viator tours',40500,31],['best egypt tours',260,21],['luxor day tour',90,0],['greece and egypt tours',50,0]] as [$keyword,$volume,$difficulty])$rows[]=array_merge($base,compact('keyword','volume','difficulty'));
$pick=fn($r,$p=null,$pg=null)=>SeoContentTargetSelector::select($r,$p??$profile,$pg??$pages,'https://example.com',1,$now);
check($pick($rows)['keyword']==='luxor day tour','relevance and measured difficulty beat unrelated volume');
check($pick([$rows[0]])===null,'competitor brand rejected');check($pick([$rows[3]])===null,'audience country does not authorize destination');
foreach(['market_id'=>2,'source'=>'ai','fetched_at'=>'2026-08-01','volume'=>null] as $k=>$v)check($pick([array_replace($rows[2],[$k=>$v])])===null,'reject invalid '.$k);
check($pick([array_replace($rows[2],['fetched_at'=>''])])===null,'missing measurement date rejected');
check($pick([array_replace($rows[2],['fetched_at'=>'2026-11-01'])])===null,'future measurement rejected');
check($pick([array_replace($rows[2],['volume'=>0])])!==null,'measured zero retained');
check($pick([array_replace($rows[2],['difficulty'=>null])])!==null,'unknown difficulty not invented');
check($pick($rows,array_replace($profile,['needs_reconfirmation'=>true]))===null,'stale profile rejected');
check($pick($rows,array_replace($profile,['confirmed_at'=>null]))===null,'unconfirmed profile rejected');
check($pick($rows,null,[])===null,'no suitable pillar means no article target');
check($pick($rows,null,[['url'=>'https://other.test/tours/luxor','title'=>'Luxor Egypt']])===null,'external pillar rejected');
check($pick($rows,null,[['url'=>'https://example.com/tours/luxor','title'=>'Luxor Egypt','robots'=>'noindex']])===null,'noindex pillar rejected');
check($pick($rows,null,[['url'=>'https://example.com/tours/airport-transfer-luxor','title'=>'Luxor Egypt']])===null,'transfer pillar excluded for sightseeing');
check($pick(array_reverse($rows))['keyword']==='luxor day tour','input order independent');
check($pick([array_replace($rows[2],['intent'=>'navigational'])])===null,'navigational intent excluded');
$arabic=['confirmed_at'=>'2026-10-15','facts'=>['business_facts'=>'رحلات الأقصر وأسوان مصر']];
$ar=array_replace($base,['keyword'=>'رحلات الأقصر','volume'=>20,'difficulty'=>1]);
check($pick([$ar],$arabic,[['url'=>'https://example.com/tours/luxor','title'=>'رحلات الأقصر']])!==null,'Arabic confirmed destination supported');
echo "$n assertions passed\n";
