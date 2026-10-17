<?php
require_once __DIR__.'/../../app/Services/SeoExpert/SeoSchemaBuilder.php';
require_once __DIR__.'/../../app/Services/AutoFix/PageContext.php';
$n=0;
function check($ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label.PHP_EOL;}
function page(string $path,string $heading):array{return ['url'=>'https://site.example'.$path,'page_path'=>$path,'h1'=>$heading,'title'=>$heading,'schema'=>[],'text'=>''];}
function evidence(string $url,string $heading,array $extra=[]):array{return array_replace(['url'=>$url,'final_url'=>$url,'h1'=>$heading,'title'=>$heading,'ok'=>true,'http_status'=>200],$extra);}
function items(array $page,array $parents=[]):array{
    $built=SeoSchemaBuilder::forPage($page,['site_url'=>'https://site.example','business_name'=>'Site','verified_pages'=>$parents]);
    foreach($built['graph']['@graph']??[] as $node)if($node['@type']==='BreadcrumbList')return $node['itemListElement'];return [];
}
$policy=page('/page/privacy','Privacy Policy');
$items=items($policy);
check(array_column($items,'item')===['https://site.example/',$policy['url']],'unknown directory is omitted instead of inventing /page/');
check(array_column($items,'name')===['Site','Privacy Policy'],'home and policy labels remain intact');
foreach(['privacy'=>'Privacy Policy','cookies'=>'Cookie Policy','terms'=>'Terms and Conditions','cancellation'=>'Cancellation Policy'] as $slug=>$heading){
    $p=page('/page/'.$slug,$heading);$bad=evidence('https://site.example/page/','Pages',['ok'=>false,'http_status'=>404]);
    check(array_column(items($p,[$bad]),'item')===['https://site.example/',$p['url']],'404 parent excluded for '.$slug);
}
$destination=page('/tours/luxor','Luxor Tours');$parent=evidence('https://site.example/tours','Egypt tours');
check(array_column(items($destination,[$parent]),'item')===['https://site.example/','https://site.example/tours',$destination['url']],'verified parent keeps the exact live URL without adding a slash');
check(array_column(items($destination,[$parent]),'name')===['Site','Egypt tours','Luxor Tours'],'parent label comes from its inspected heading');
$trailing=evidence('https://site.example/tours/','Egypt tours');
check(items($destination,[$trailing])[1]['item']===$trailing['url'],'a verified trailing slash is preserved');
foreach([
    'unverified'=>['ok'=>false],
    'missing status'=>['http_status'=>null],
    'redirect response'=>['http_status'=>301],
    'maintenance'=>['coming_soon'=>true],
    'external redirect'=>['final_url'=>'https://other.example/tours'],
    'redirect to homepage'=>['final_url'=>'https://site.example/'],
    'different scheme'=>['final_url'=>'http://site.example/tours'],
    'different port'=>['final_url'=>'https://site.example:8443/tours'],
    'query URL'=>['final_url'=>'https://site.example/tours?sort=price'],
    'fragment URL'=>['final_url'=>'https://site.example/tours#featured'],
    'credentials'=>['final_url'=>'https://name:secret@site.example/tours'],
] as $label=>$change)check(count(items($destination,[array_replace($parent,$change)]))===2,$label.' cannot supply a breadcrumb parent');
$nested=page('/destinations/egypt/luxor','Luxor');
check(array_column(items($nested,[evidence('https://site.example/destinations/egypt','Egypt')]),'position')===[1,2,3],'positions remain consecutive when an intermediate parent is missing');
$deep=page('/a/b/c/d/e/f/g','Current page');$parents=[];$path='';foreach(['a','b','c','d','e','f'] as $part){$path.='/'.$part;$parents[]=evidence('https://site.example'.$path,strtoupper($part));}
$items=items($deep,$parents);
check(count($items)<=6&&end($items)['item']===$deep['url'],'bounded breadcrumbs always retain the current page');
$existing=['@type'=>'BreadcrumbList','itemListElement'=>[['@type'=>'ListItem','position'=>1,'name'=>'Existing']]];
check(SeoSchemaBuilder::forPage(array_replace($policy,['schema'=>[$existing]]),['site_url'=>'https://site.example'])===null,'existing customer breadcrumbs are not replaced or duplicated');
$trip=page('/tour/luxor-day-tour','Luxor Day Tour');$built=SeoSchemaBuilder::forPage($trip,['site_url'=>'https://site.example','business_name'=>'Site']);
check(in_array('TouristTrip',$built['types'],true)&&in_array('BreadcrumbList',$built['types'],true),'single-trip structured data remains available');
class BreadcrumbPageReader extends PageContext
{
    public int $status=200;
    protected function isSafe(string $url):bool{return true;}
    protected function isImage(string $url):bool{return false;}
    protected function get(string $url,bool $withBody,string $method='GET'):array{return ['http'=>$this->status,'body'=>'<html><head><title>Egypt Tours</title></head><body><h1>Egypt Tours</h1></body></html>','headers'=>[],'error'=>null,'final_url'=>$url];}
}
$reader=new BreadcrumbPageReader();$observed=$reader->fetch('https://site.example/tours');
check($observed['ok']&&$observed['http_status']===200&&count(items($destination,[$observed]))===3,'successful page reads supply usable parent evidence');
$reader->status=404;$observed=$reader->fetch('https://site.example/tours');
check(!$observed['ok']&&$observed['http_status']===404&&count(items($destination,[$observed]))===2,'failed page reads cannot authorize a parent');
echo "$n assertions passed\n";
