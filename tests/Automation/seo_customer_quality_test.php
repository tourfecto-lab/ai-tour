<?php
require_once __DIR__.'/../../app/Services/SeoExpert/SeoSchemaBuilder.php';
require_once __DIR__.'/../../app/Services/SeoExpert/SeoPagePolicy.php';
require_once __DIR__.'/../../app/Services/AI/ArticleGenerator.php';
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";$n++;}
function page($path,$h1,$extra=[]){return array_replace(['url'=>'https://example.com'.$path,'page_path'=>$path,'h1'=>$h1,'title'=>$h1.' | Example Tours','text'=>'From USD 65','schema'=>[]],$extra);}
foreach([['/quiz','Choose your trip'],['/tours','All tours'],['/tours/marsa-alam','Marsa Alam Tours'],['/tours/shore-excursions','Shore Excursions']] as [$path,$title])check(!SeoSchemaBuilder::isTourPage(page($path,$title)),'not a single trip: '.$path);
check(!SeoPagePolicy::route('https://example.com/quiz')['eligible'],'quiz cannot receive marketing edits');
check(SeoSchemaBuilder::isTourPage(page('/tour/luxor-day-tour','Luxor Day Tour')),'singular trip route retained');
check(SeoSchemaBuilder::isTourPage(page('/tours/luxor-day-tour','Luxor Day Tour')),'plural route can still carry actual single tour');
check(!SeoSchemaBuilder::isTourPage(page('/tours/luxor','Luxor Day Tour',['schema'=>[['@type'=>'ItemList']]])),'existing collection schema prevents trip classification');
$collection=SeoSchemaBuilder::forPage(page('/tours/marsa-alam','Marsa Alam Tours'),['business_name'=>'Example','site_url'=>'https://example.com']);
check($collection['types']===['BreadcrumbList'],'collection retains safe breadcrumb feature');
check(!SeoSchemaBuilder::taskCompatible(page('/tours/marsa-alam','Marsa Alam Tours'),'jsonld','{"@graph":[{"@type":"TouristTrip"}]}'),'historical trip proposal blocked on current collection');
check(!SeoSchemaBuilder::taskCompatible(page('/tours/marsa-alam','Marsa Alam Tours'),'related_links','[]'),'historical related-trip proposal blocked on current collection');
check(SeoSchemaBuilder::taskCompatible(page('/tours/marsa-alam','Marsa Alam Tours'),'jsonld','{"@type":"BreadcrumbList"}'),'historical safe breadcrumb proposal retained');
$brief=['confirmed_facts'=>['business_facts'=>'Local Luxor tours with guides','offers'=>'','audience'=>'families and children','source_site_url'=>'https://example.com'],'verified_internal_urls'=>['https://example.com/','https://example.com/tours/luxor']];
$validate=fn($body,$b=null)=>ArticleGenerator::validateBriefOutput(['content'=>$body],$b??$brief);
check($validate('Most day tours last between 6 to 8 hours.')!==null,'unsupported numerical duration rejected');
check($validate('Our tours cater to families and travelers of all ages.')!==null,'audience does not prove family suitability');
check($validate('Our tours are suitable for families.')!==null,'unsupported family statement rejected');
check($validate('رحلاتنا مناسبة للعائلات والأطفال.')!==null,'unsupported Arabic family statement rejected');
check($validate('Confirm the exact duration and family suitability before booking.')===null,'requesting confirmation remains valid');
check($validate('Are the tours suitable for families?')===null,'question alone is not an affirmative claim');
check($validate('Our tours last 6 hours.',['confirmed_facts'=>['business_facts'=>'Our tours last 6 hours.']])===null,'literal confirmed duration statement accepted');
check($validate('Our tours cater to families.',['confirmed_facts'=>['business_facts'=>'Our tours cater to families.']])===null,'literal confirmed family statement accepted');
check(ArticleGenerator::validateBriefOutput(['title'=>'6-hour tour'],$brief)!==null,'hyphenated title duration also needs evidence');
check(ArticleGenerator::validateBriefOutput(['faqs'=>[['question'=>'Duration?','answer'=>'Tours last 6 hours.']]],$brief)!==null,'FAQ answers cannot bypass duration guard');
check($validate('[Luxor](https://example.com/tours/luxor)')===null,'known internal target accepted');
check($validate('[Luxor](https://example.com/tours/luxor#details)')===null,'fragment on known internal target accepted');
check($validate('[Invented trip](https://example.com/tours/invented)')!==null,'unknown internal article link requires review');
check($validate('[Other](https://elsewhere.test/info)')===null,'external citations remain separate from internal-link evidence');
echo "$n assertions passed\n";
