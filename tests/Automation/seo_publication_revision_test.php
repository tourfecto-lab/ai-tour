<?php
define('ENCRYPTION_KEY',base64_encode(random_bytes(32)));
require __DIR__.'/../../app/Services/SeoExpert/SeoPublicationRevision.php';
require __DIR__.'/../../app/Services/AI/ArticleGenerator.php';
require __DIR__.'/../../app/Services/Seo/ContentQualityService.php';
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
function denied($fn){try{$fn();return false;}catch(InvalidArgumentException $e){return true;}}
$t=['id'=>1,'kind'=>'content','status'=>'verified','is_active'=>1,'title'=>'Published guide','expected_value'=>'Previous content','published_url'=>'https://example.com/?tf_article=guide'];
check(SeoPublicationRevision::eligible($t,'https://example.com'),'exact connector query publication is eligible');
foreach(['https://example.com/?tf_article[]=guide','https://example.com/?tf_article=guide&preview=1','https://other.test/?tf_article=guide','https://user:pass@example.com/guides/guide','https://example.com/guides/guide#x','http://example.com/guides/guide'] as $bad)check(!SeoPublicationRevision::eligible(array_replace($t,['published_url'=>$bad]),'https://example.com'),'ambiguous, external or different-origin publication rejected');
check(!SeoPublicationRevision::eligible(array_replace($t,['external_post_id'=>42]),'https://example.com'),'WordPress publication keeps its native editor flow');
check(!SeoPublicationRevision::eligible(array_replace($t,['is_active'=>0]),'https://example.com'),'historical inactive source cannot create a competing revision');
$b=['user'=>1,'site'=>2,'task'=>3,'version'=>4,'base'=>'a','next'=>'b'];$token=SeoPublicationRevision::token($b,1000);
SeoPublicationRevision::verify($token,$b,1001);check(true,'valid signed preview binds the selected revision');
check(denied(fn()=>SeoPublicationRevision::verify($token,array_replace($b,['user'=>2]),1001)),'preview cannot cross customer accounts');
check(denied(fn()=>SeoPublicationRevision::verify($token,array_replace($b,['base'=>'changed']),1001)),'preview cannot overwrite an intervening source edit');
check(denied(fn()=>SeoPublicationRevision::verify($token,$b,1900)),'expired preview requires another review');
check(denied(fn()=>SeoPublicationRevision::verify($token.'x',$b,1001)),'tampering with the signature is rejected');
$snapshot=['brief'=>['shared_profile'=>['version'=>1,'facts'=>['business_facts'=>'Local guides and private tours.']]]];
$body='Compare the available published routes and confirm the itinerary for your chosen departure. Ask the operator to confirm current prices, inclusions, availability and any child or cancellation conditions before booking.';
$q=['brief_json'=>json_encode(['profile_version'=>1,'confirmed_facts'=>$snapshot['brief']['shared_profile']['facts']]),'description'=>'Review the confirmed routes.','faqs_json'=>json_encode([['question'=>'Child discount?','answer'=>'Children travel free.']])];
$p=SeoPublicationRevision::prepare($t,$snapshot,$q,['value'=>$body]);
check($p['faqs']===[]&&$p['removed_faqs']===1,'FAQ omitted from revised visible text is explicitly removed in preview');
check($p['meta_description']===$q['description'],'existing reviewed description is retained by default');
check(denied(fn()=>SeoPublicationRevision::prepare($t,$snapshot,$q,['value'=>$body,'meta_description'=>'Book with free cancellation.'])),'description cannot bypass the claim guard');
check(denied(fn()=>SeoPublicationRevision::prepare($t,$snapshot,$q,['value'=>$body.' Most tours last 9 hours.'])),'existing duration validation remains enforced');
check(denied(fn()=>SeoPublicationRevision::prepare($t,$snapshot,$q,['value'=>$body.' Price USD 999.'])),'existing price validation remains enforced');
echo "$n assertions passed\n";
