<?php
require __DIR__.'/../../app/Services/SeoExpert/SeoRecommendationReview.php';
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
$page=['url'=>'https://example.com/tours/marsa-alam','h1'=>'Marsa Alam Tours','title'=>'Marsa Alam Tours and Excursions | Example Tours','description'=>'Explore the published Marsa Alam tour routes and confirm the itinerary and final booking details.','schema'=>[['@type'=>'ItemList']]];
$task=['kind'=>'metadata','status'=>'needs_client','page_url'=>$page['url'],'field_name'=>'jsonld','expected_value'=>'{"@graph":[{"@type":"TouristTrip"}]}'];
$a=fn($t=null,$p=null)=>SeoRecommendationReview::assess($t??$task,$p??[$page]);
check($a()['code']==='single_trip_mismatch'&&$a()['state']==='excluded','legacy collection trip schema visibly excluded');
$links=array_replace($task,['field_name'=>'related_links','expected_value'=>'[]']);check($a($links)['state']==='excluded','legacy related-trip overlay excluded on collection');
$quiz=array_replace($task,['page_url'=>'https://example.com/quiz']);check($a($quiz)['code']==='outside_marketing_scope','quiz marketing proposal excluded without fetching');
$breadcrumbs=array_replace($task,['expected_value'=>'{"@type":"BreadcrumbList"}']);check($a($breadcrumbs)['state']==='reviewable','safe collection breadcrumb feature retained');
$trip=array_replace($page,['url'=>'https://example.com/tour/luxor','h1'=>'Luxor 2-day trip','schema'=>[]]);check($a(array_replace($task,['page_url'=>$trip['url']]),[$trip])['state']==='reviewable','genuine single-trip schema remains reviewable');
check($a(null,[])['state']==='needs_evidence','missing page context cannot falsely establish collection or approve');
foreach([['is_active'=>1],['status'=>'verified'],['status'=>'publishing'],['published_url'=>'https://example.com/guide'],['external_post_id'=>45],['previous_task_id'=>4]] as $history)check($a(array_replace($quiz,$history))['state']==='retained','existing execution history retained: '.key($history));
$manual=['kind'=>'manual','status'=>'needs_client','page_url'=>$page['url'],'title'=>'Not indexed: old fetch problem'];check($a($manual)['state']==='reviewable','Google indexing task remains separate from marketing eligibility');
check($a(array_replace($manual,['page_url'=>'https://example.com/quiz']))['code']==='indexing_intent_review','operational indexing is an intent decision, not a fabricated fetch error');
$desc=array_replace($task,['field_name'=>'description','expected_value'=>'A generic shorter replacement description that omits route detail.']);check($a($desc)['code']==='existing_metadata_no_detected_issue','good metadata warns reviewer to preserve factual detail without banning human edits');
$noindex=array_replace($page,['robots'=>'noindex']);check($a($breadcrumbs,[$noindex])['code']==='indexing_or_canonical_intent','noindex intent requires review before marketing');
$schemaTask=['kind'=>'manual','status'=>'needs_client','page_url'=>$page['url'],'title'=>'Implement structured data for tours','instructions'=>'Add schema to improve the existing tour listings.'];check($a($schemaTask)['state']==='excluded','generic schema instruction unsupported by existing schema is excluded');
$faq=array_replace($schemaTask,['title'=>'Add FAQPage schema','instructions'=>'Add FAQPage schema matching the visible questions and answers on this page.']);check($a($faq)['state']==='reviewable','specific missing schema type may still be reviewed');
$alreadyFaq=array_replace($page,['schema'=>[['@graph'=>[['@type'=>['FAQPage','WebPage']]]]]]);check($a($faq,[$alreadyFaq])['state']==='excluded','already present named schema is not recommended again');
$repair=array_replace($schemaTask,['title'=>'Repair malformed schema','instructions'=>'Fix the invalid schema on this page and retest.']);check($a($repair)['state']==='reviewable','explicit schema repair remains supported');
$scoped=[['page_index'=>0,'title'=>'Add H1 to /quiz','instructions'=>'Add a visible main heading to this page.'],['page_index'=>1,'title'=>'Add H1 to /tour/luxor','instructions'=>'Add a visible descriptive main heading to this page.']];
check(count(SeoPlanQualityGate::filterClientTasks($scoped,[array_replace($page,['url'=>'https://example.com/quiz']),$trip]))===1,'new manual tasks filtered against actual page scope');
foreach(['manual','connection'] as $kind)check(!SeoRecommendationReview::publicVerificationEligible(['kind'=>$kind,'status'=>'verified']),'public worker cannot replace connected proof: '.$kind);
check(SeoRecommendationReview::publicVerificationEligible(['kind'=>'metadata','status'=>'verified']),'metadata continues to reverify');
check(SeoRecommendationReview::publicVerificationEligible(['kind'=>'content','status'=>'needs_client','is_active'=>1]),'active publication recovery continues to reverify');
check(!SeoRecommendationReview::publicVerificationEligible(['kind'=>'metadata','status'=>'needs_client','is_active'=>0]),'unapplied proposal is not treated as an applied rule');
echo "$n assertions passed\n";
