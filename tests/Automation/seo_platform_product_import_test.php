<?php
/** Pure parsing/URL checks and mock-Database owner guards; no network or SQLite. */
class Database {public array $calls=[];public bool $owned=true;public function query($sql,$params=[]){$this->calls[]=$sql;if(str_contains($sql,'FROM websites'))return $this->owned?[['id'=>33,'main_url'=>'https://example.com']]:[];throw new RuntimeException('Unexpected database access');}}
class Website {public static function urlColumn(){return 'main_url';}}
require_once __DIR__.'/../../app/Services/SeoImpact/SeoPlatformProductService.php';
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label.PHP_EOL;}
function rejects($fn){try{$fn();return false;}catch(InvalidArgumentException $e){return true;}}
$json=static fn($node)=>'<html><head><script type="application/ld+json">'.json_encode($node).'</script></head><body><h1>Fallback tour</h1></body></html>';
$product=['@context'=>'https://schema.org','@type'=>'Product','name'=>'Luxor & temples','description'=>'<b>Two days</b>','offers'=>['@type'=>'Offer','price'=>'255.00','priceCurrency'=>'USD']];
$r=SeoPlatformProductService::parse($json($product));
check($r['draft']===['name'=>'Luxor & temples','description'=>'Two days','price'=>'255.00','currency'=>'USD']&&$r['requires_review'],'explicit Product/Offer facts become a review draft, not an automatic product');
$graph=['@graph'=>[['@type'=>'Organization','name'=>'Ignore organization'],['@type'=>'WebPage','mainEntity'=>$product]]];
check(SeoPlatformProductService::parse($json($graph))['draft']['name']==='Luxor & temples','graph and mainEntity structures retain product identity rather than the business name');
$missing=$product;unset($missing['offers']);$r=SeoPlatformProductService::parse($json($missing));
check($r['draft']['price']===''&&$r['draft']['currency']==='','missing money fields remain empty instead of assuming zero or USD');
$range=$product;$range['offers']=['@type'=>'AggregateOffer','lowPrice'=>'20','highPrice'=>'255','priceCurrency'=>'USD'];
check(SeoPlatformProductService::parse($json($range))['draft']['price']==='','an AggregateOffer minimum is not used as a confirmed product price');
$multi=$product;$multi['offers']=[$product['offers'],['@type'=>'Offer','price'=>'240','priceCurrency'=>'EUR']];$r=SeoPlatformProductService::parse($json($multi));
check($r['draft']['price']===''&&$r['draft']['currency']==='','multiple offers and currencies require a customer-confirmed price');
$other=$product;$other['name']='Another tour';$r=SeoPlatformProductService::parse($json([$product,$other]));
check(count($r['candidates'])===2&&$r['draft']['name']===''&&$r['draft']['price']==='','multiple products are offered as choices without silently selecting a tour');
$r=SeoPlatformProductService::parse('<html><title>Generic page</title><meta name="description" content="A real page"><h1>Tour from Marsa Alam</h1><p>From $99, discounts 20%</p></html>');
check($r['draft']['name']==='Tour from Marsa Alam'&&$r['draft']['description']==='A real page'&&$r['draft']['price']===''&&$r['draft']['currency']==='','a plain page supplies a heading/description but free text prices are never guessed');
check(SeoPlatformProductService::parse('<h1>Trip</h1><script type="application/ld+json">{bad JSON</script>')['draft']['price']==='','invalid schema falls back to an editable draft');
check(rejects(fn()=>SeoPlatformProductService::parse(str_repeat('x',750001))),'oversized imported pages are rejected');
check(SeoPlatformProductService::price('0')==='0.00'&&SeoPlatformProductService::price('000255.5')==='255.50','confirmed free and decimal prices normalize exactly without a currency conversion');
foreach(['',-1,'1,200','NaN','1e2','1.234','1000000000000',true,[]] as $price)check(rejects(fn()=>SeoPlatformProductService::price($price)),'invalid or ambiguous price rejected: '.json_encode($price));
check(SeoPlatformProductService::sourceUrl('https://example.com/tour?utm_source=test&id=2','https://example.com')==='https://example.com/tour?id=2','tracking parameters are removed while genuine routing parameters are retained');
foreach(['http://example.com/tour','https://other.com/tour','https://example.com.evil.test/tour','https://u:p@example.com/tour','https://example.com:8443/tour','https://example.com/tour#preview','https://example.com/%0afoo'] as $url)check(rejects(fn()=>SeoPlatformProductService::sourceUrl($url,'https://example.com')),'foreign or unsafe source is rejected before fetching: '.$url);
$db=new Database();$fetches=[];$s=new SeoPlatformProductService($db,static function($url)use(&$fetches,$json,$product){$fetches[]=$url;return $json($product);});
check($s->preview(30,33,'https://example.com/tour')['source_url']==='https://example.com/tour'&&count($fetches)===1,'owned-site preview uses a fake transport once and does not write any product');
$before=count($fetches);check(rejects(fn()=>$s->preview(30,33,'https://other.com/tour'))&&count($fetches)===$before,'a foreign import URL never reaches the transport');
$db->owned=false;check(rejects(fn()=>$s->preview(31,33,'https://example.com/tour'))&&count($fetches)===$before,'another account cannot preview this site or cause a remote request');
check(rejects(fn()=>$s->create(31,33,['confirmed'=>true])),'a foreign account cannot create or map a product');
$db->owned=true;check(rejects(fn()=>$s->create(30,33,['confirmed'=>false])),'creation requires an explicit factual confirmation before any write');
check(count(array_filter($db->calls,static fn($sql)=>!str_starts_with($sql,'SELECT ')))===0,'previews and denied actions leave all catalog and financial records untouched');
echo $n.' assertions passed'.PHP_EOL;
