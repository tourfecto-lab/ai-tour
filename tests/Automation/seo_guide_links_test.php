<?php
class SafeHttp{public static string $html='';public static function exec($url,$options,$redirects){if(isset($options[CURLOPT_WRITEFUNCTION]))$options[CURLOPT_WRITEFUNCTION](null,self::$html);return ['code'=>200,'body'=>self::$html,'headers'=>[],'error'=>'','url'=>$url];}}
require_once __DIR__.'/../../app/Services/Seo/GuideContent.php';
require_once __DIR__.'/../../app/Services/SeoExpert/SeoPublicationAudit.php';
require_once __DIR__.'/../../app/Services/SeoExpert/SeoExpertPageContext.php';
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label.PHP_EOL;}
$source='Read [**Luxor tours**](https://site.example/tours/luxor?q=one&lang=en) before booking.';$html=GuideContent::html($source);
check(str_contains($html,'<a href="https://site.example/tours/luxor?q=one&amp;lang=en"><strong>Luxor tours</strong></a>'),'safe URL and bold label render as a real anchor');
check(!str_contains($html,'[**Luxor tours**]'),'Markdown link syntax is removed from visible copy');
check(SeoVerificationState::content($source,true)===SeoVerificationState::content($html),'approved source and rendered HTML have the same visible-text comparison');
check(SeoVerificationState::content($source,true)!==SeoVerificationState::content($source),'old literal Markdown output is distinguishable');
foreach(['javascript:alert(1)','data:text/html,bad','file:///tmp/file','https://user:pass@site.example/path'] as $url)check(!str_contains(GuideContent::html('[Unsafe]('.$url.')'),'<a '),'unsafe target remains inert: '.explode(':',$url)[0]);
$unsafe=GuideContent::html('[<img src=x onerror=alert(1)>](https://site.example/path)');check(!str_contains($unsafe,'<img')&&str_contains($unsafe,'&lt;img'),'link label markup stays escaped');
check(str_contains(GuideContent::html('[Label](https://site.example/path#part)'),'<a href="https://site.example/path#part">'),'fragments remain supported');
$article='https://site.example/guides/luxor';$task=['published_url'=>$article,'title'=>'Luxor guide','expected_value'=>$source];$ctx=['ok'=>true,'text'=>'Read Luxor tours before booking.','publication_links'=>['https://site.example/tours/luxor?q=one&lang=en'],'title'=>'Luxor guide','canonical'=>$article,'guide_layout'=>'site','headings'=>['Luxor'],'schema'=>[]];
$audit=SeoPublicationAudit::inspect($task,$ctx,[],['state'=>'passed']);check($audit['checks']['approved_content']==='passed'&&$audit['checks']['inline_links']==='passed','publication audit accepts visible copy with the expected clickable URL');
$ctx['publication_links']=[];$audit=SeoPublicationAudit::inspect($task,$ctx,[],['state'=>'passed']);check($audit['checks']['inline_links']==='missing','a matching label without its link cannot pass link quality');
class GuideLinkReader extends SeoExpertPageContext{protected function isSafe(string $url):bool{return true;}}
SafeHttp::$html='<html><head><title>Guide</title></head><body><header><a href="https://site.example/not-body">Navigation</a></header><main data-tourfecto-guide>'.$html.'</main></body></html>';
$reader=new GuideLinkReader();$observed=$reader->freshPublication($article);check(($observed['publication_links']??[])===['https://site.example/tours/luxor?q=one&lang=en'],'publication reader takes article anchors, not header navigation');
SafeHttp::$html='<html><head><title>Other</title></head><body><main data-tourfecto-guide>Other article.</main></body></html>';
check($reader->freshPublication($article)['publication_links']===[],'links from the previous page do not leak into a later verification');
echo "$n assertions passed\n";
