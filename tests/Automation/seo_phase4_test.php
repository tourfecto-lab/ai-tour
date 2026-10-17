<?php
/** Offline content quality contracts; no provider credentials, WordPress installation or DB required. */
$root=dirname(__DIR__,2);
foreach (['Seo/ContentBriefService','Seo/ContentQualityService','Seo/ContentDecayService','AI/ArticleGenerator','Publishing/WordPressPublisher','AutoFix/EdgeRules','SeoExpert/SeoSchemaBuilder'] as $file) require_once $root.'/app/Services/'.$file.'.php';
$n=0;$fails=0;
function check($ok,$message){global $n,$fails;$n++;echo ($ok?'  ok  ':'  FAIL ').$message."\n";if(!$ok)$fails++;}
function denied(callable $fn):bool{try{$fn();return false;}catch(Throwable $e){return true;}}
$profile=['version'=>3,'confirmed_at'=>gmdate('c'),'facts'=>['business_name'=>'Nile Travel','business_facts'=>'Luxor guided day tour','offers'=>'Price USD 65 on request']];
$research=['keyword'=>'Luxor day tour','volume'=>0,'source'=>'dataforseo','fetched_at'=>gmdate('c')];$items=[];
foreach ([7,2,5,1,3,4,6] as $rank) $items[]=['type'=>'organic','rank_group'=>$rank,'url'=>'https://rank.example/page-'.$rank];
$items[]=['type'=>'people_also_ask','items'=>[['title'=>'What should I bring?'],['title'=>'What should I bring?'],['title'=>'Is pickup included?']]];
$urls=[];$builder=new ContentBriefService(null,function($url)use(&$urls){$urls[]=$url;return '<html><head><script>fake words to exclude</script></head><body><header>ignore header</header><h1>Luxor guide</h1><h2>What to bring</h2><p>Real measured words here.</p></body></html>';});
$brief=$builder->build($research,$items,$profile,['id'=>1,'language_code'=>'en','location_code'=>2840]);
check(count($urls)===5 && str_ends_with($urls[0],'page-1') && str_ends_with($urls[4],'page-5'),'only top five organic pages fetched in rank order');
check($brief['volume']===0 && $brief['source']==='dataforseo','measured zero volume stays zero, never invented demand');
check($brief['ranking_pages'][0]['headings'][1]===['level'=>'h2','text'=>'What to bring'],'competitor headings preserve text and level');
check($brief['ranking_pages'][0]['word_count']===9,'DOM word counts exclude script and site chrome');
check(count($brief['people_also_ask'])===2 && $brief['confirmed_facts']===$profile['facts'],'PAA deduplicated and confirmed customer facts preserved separately');
check(denied(fn()=>$builder->build($research,$items,array_replace($profile,['confirmed_at'=>null]),[])),'unconfirmed facts stop writing');
check(denied(fn()=>$builder->build(array_replace($research,['volume'=>null]),$items,$profile,[])),'missing volume cannot be guessed');
check(denied(fn()=>$builder->build(array_replace($research,['fetched_at'=>'2020-01-01']),$items,$profile,[])),'stale research rejected before generation');
$partial=(new ContentBriefService(null,fn($url)=>null))->build($research,$items,$profile,[]);
check(!$partial['ranking_pages'] && count($partial['unavailable_pages'])===5,'inaccessible competitors recorded as unavailable without guessed headings');
check($builder->fetchPage('file:///etc/passwd')===null && $builder->fetchPage('https://user:pass@rank.example')===null,'non-web URLs and embedded credentials rejected');
check(ArticleGenerator::validateBriefOutput(['content'=>'Price USD 65'],$brief)===null,'confirmed literal price accepted');
check(ArticleGenerator::validateBriefOutput(['content'=>'Price USD 99'],$brief)!==null,'unconfirmed generated price rejected');
check(ArticleGenerator::validateBriefOutput(['content'=>'Price USD 6'],$brief)!==null,'price prefix cannot match a different confirmed amount');
check(ArticleGenerator::validateBriefOutput(['title'=>'Tour USD 99','content'=>'Advice'],$brief)!==null,'unconfirmed title prices rejected too');
check(ArticleGenerator::validateBriefOutput(['content'=>'<script type="application/ld+json">{}</script>'],$brief)!==null,'JSON-LD forbidden inside generated body');
class InternationalSettingsService {public static function languages():array{return ['en'=>'English'];}}
$fake=new class {public string $prompt='';public function generateContent($prompt,$options){$this->prompt=$prompt;return ['success'=>true,'data'=>json_encode(['title'=>'Luxor guide','content'=>'Useful advice.','faqs'=>[['question'=>'What should I bring?','answer'=>'Bring water.']]])];}};
$article=(new ArticleGenerator($fake))->generate('Luxor day tour','en','professional','Nile Travel','https://site.example',[],$brief);
check($article['success'] && str_contains($fake->prompt,'AUTHORITATIVE CONTENT BRIEF') && str_contains($fake->prompt,'confirmed_facts'),'generator receives dated brief and authoritative confirmed facts before writing');
check(str_contains($article['data']['content'],'Bring water.') && $article['data']['faqs'][0]['answer']==='Bring water.','FAQ answers saved and visible in article');
check(ContentQualityService::visibleFaqs($article['data']['faqs'],'Question removed during editing')===[],'edited-away FAQs cannot become hidden schema');
$row=['title'=>'Luxor guide','description'=>'A factual guide','page_url'=>'https://site.example/guides/luxor','pillar_url'=>'https://site.example/tours/luxor','pillar_title'=>'Luxor trip','faqs_json'=>json_encode($article['data']['faqs']),'published_at'=>'2026-10-17 00:00:00'];
$rules=ContentQualityService::rules([$row],'site.example',['/guides/luxor'=>['jsonld'=>'{"@context":"https://schema.org","@type":"BreadcrumbList","itemListElement":[]}']]);
$graph=json_decode($rules['/guides/luxor']['jsonld'],true)['@graph'];
check(array_column($graph,'@type')===['BreadcrumbList','Article','FAQPage'],'page rule merges Article and FAQPage with existing schema');
check(isset($rules['/tours/luxor']['related_links'][0]) && $rules['/guides/luxor']['related_links'][0]['url']===$row['pillar_url'],'cluster adds article-to-pillar and pillar-to-article links');
check(EdgeRules::sanitize('jsonld',$rules['/guides/luxor']['jsonld'],'site.example')!==null,'schema passes the real connector allowlist');
$again=ContentQualityService::rules([$row],'site.example',$rules);
check($again===$rules,'repeated connector compilation does not duplicate schema or links');
check(ContentQualityService::rules([array_replace($row,['page_url'=>'https://attacker.example/guides/luxor'])],'site.example')===[],'off-site publication cannot inject connector rules');
check(ContentQualityService::pagePath('https://site.example/guides/a?x=1','site.example')===null,'query URL cannot leak rules to another page');
$pillar=ContentQualityService::cluster('Luxor temple tips',[['url'=>'https://site.example/tours/luxor','title'=>'Luxor day trip'],['url'=>'https://elsewhere.example/tours/luxor','title'=>'Luxor']],'https://site.example');
check($pillar['url']==='https://site.example/tours/luxor','keyword clusters use a relevant known same-site trip pillar');
check(ContentQualityService::cluster('Rome', [['url'=>'https://site.example/tours/luxor','title'=>'Luxor']],'https://site.example')===null,'unrelated keyword has no fabricated pillar');
$cmp=fn($old,$new)=>ContentDecayService::compare($old,$new);
check(!$cmp(['clicks'=>100,'position'=>4],['clicks'=>75,'position'=>7])['refresh'],'exactly 25 percent clicks and 3 positions do not cross strict thresholds');
check($cmp(['clicks'=>100,'position'=>4],['clicks'=>74,'position'=>4])['refresh'],'click loss greater than 25 percent becomes refresh');
check($cmp(['clicks'=>100,'position'=>4],['clicks'=>100,'position'=>7.1])['refresh'],'position loss greater than 3 becomes refresh');
check(!$cmp(['clicks'=>0,'position'=>null],['clicks'=>0,'position'=>null])['refresh'],'zero baseline and missing positions stay unknown');
check(!$cmp(['clicks'=>10,'position'=>8],['clicks'=>20,'position'=>2])['refresh'],'improved clicks and rank do not trigger refresh');
$windows=ContentDecayService::windows(new DateTimeImmutable('2026-10-17T12:00:00Z'));
check($windows===['current'=>['2026-09-18','2026-10-15'],'previous'=>['2026-08-21','2026-09-17']],'GSC windows contain consecutive non-overlapping 28-day ranges');
$refresh=ContentDecayService::refreshBrief('https://site.example/a',[],['people_also_ask'=>['New question?'],'ranking_pages'=>[['url'=>'https://site.example/a','position'=>6],['url'=>'https://competitor.example/b','position'=>2]]],['people_also_ask'=>['Old question?'],'ranking_pages'=>[['url'=>'https://site.example/a','position'=>2],['url'=>'https://competitor.example/b','position'=>6]]],['excerpt'=>'2024 guide USD 65 and $80'],2026);
check($refresh['new_questions']===['New question?'] && $refresh['years_to_confirm']===['2024'] && count($refresh['prices_to_confirm'])===2,'refresh brief lists new questions and literal years/prices needing confirmation');
check(count($refresh['competitors_that_overtook'])===1,'overtaking requires a crossing in both measured snapshots');
$noBaseline=ContentDecayService::refreshBrief('https://site.example/a',[],['ranking_pages'=>[['url'=>'https://other.example/a','position'=>1]]],[],null,2026);
check($noBaseline['competitors_that_overtook']===[],'no baseline means no invented overtaking assertion');

// Actual plugin endpoint with minimal WordPress host functions.
define('ABSPATH','/offline/');define('TF_NATIVE_HEAD_BRIDGE',true);
function add_action(...$args){} function add_filter(...$args){} function current_theme_supports($x){return true;}
$GLOBALS['plugins']=[];$GLOBALS['meta']=[];$GLOBALS['user']=7;
function get_option($key,$default=[]){return $key==='active_plugins'?$GLOBALS['plugins']:$default;}
function get_current_user_id(){return $GLOBALS['user'];} function home_url(){return 'https://site.example';}
function get_post($id){return $id===42?(object)['ID'=>42,'post_type'=>'post','post_status'=>'publish','post_password'=>'']:null;}
function current_user_can(...$args){return true;} function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function metadata_exists($type,$id,$key){return array_key_exists($key,$GLOBALS['meta'][$id]??[]);}
function update_post_meta($id,$key,$value){$GLOBALS['meta'][$id][$key]=$value;}
function sanitize_text_field($text){return trim(strip_tags($text));} function wp_json_encode($x){return json_encode($x);}
function wp_salt($x){return 'offline-signing-key';} function wp_generate_uuid4(){return bin2hex(random_bytes(16));}
function clean_post_cache($id){} function do_action(...$args){}
require $root.'/app/Services/AutoFix/WordPressBundle/tourfecto-native-seo.php';
require $root.'/app/Services/AutoFix/WordPressBundle/tourfecto-content-seo.php';
$fields=['seo_title'=>'Luxor guide','seo_description'=>'A factual guide','seo_keyword'=>'Luxor tour'];
check(denied(fn()=>TourfectoContentSeo::preview(['post_id'=>42,'fields'=>$fields])),'endpoint refuses a post not created by platform account');
$GLOBALS['meta'][42]['_tourfecto_created_by']='7';
$preview=TourfectoContentSeo::preview(['post_id'=>42,'fields'=>$fields]);$token=$preview['token'];
$plan=TourfectoContentSeo::verify($token,wp_salt('auth'),home_url(),7,time());
check($plan['post_id']===42 && $plan['fields']===$fields,'signed endpoint token binds exact post and fields');
check(denied(fn()=>TourfectoContentSeo::verify($token.'x',wp_salt('auth'),home_url(),7,time())),'tampered signature rejected');
check(denied(fn()=>TourfectoContentSeo::verify($token,wp_salt('auth'),'https://another.example',7,time())),'cross-site signature rejected');
check(denied(fn()=>TourfectoContentSeo::verify($token,wp_salt('auth'),home_url(),8,time())),'cross-account signature rejected');
check(denied(fn()=>TourfectoContentSeo::verify($token,wp_salt('auth'),home_url(),7,time()+301)),'expired signature rejected');
$applied=TourfectoContentSeo::apply($token);
check($applied['success'] && get_post_meta(42,'_tourfecto_native_keyword',true)==='Luxor tour','native adapter stores SEO title, description and focus keyword');
check(TourfectoContentSeo::apply($token)['replayed'],'same signed update is idempotent');
$newToken=TourfectoContentSeo::preview(['post_id'=>42,'fields'=>$fields])['token'];update_post_meta(42,'_tourfecto_native_title','Human changed title');
check(denied(fn()=>TourfectoContentSeo::apply($newToken)),'concurrent human SEO edit prevents stale overwrite');
check(TourfectoNativeSeo::keys('yoast')['seo_keyword']==='_yoast_wpseo_focuskw' && TourfectoNativeSeo::keys('rankmath')['seo_description']==='rank_math_description','Yoast and RankMath share the existing adapter keys');
$requests=[];$publisher=new WordPressPublisher(function($r)use(&$requests){$requests[]=$r;return ['http_code'=>200,'body'=>json_encode(str_ends_with($r['url'],'preview')?['token'=>'signed-token']:['success'=>true,'post_id'=>42])];});
check($publisher->setSeoFields(home_url(),'user','offline-password',42,$fields)['success'] && count($requests)===2,'publisher performs signed preview then apply through authenticated REST');
check(json_decode($requests[1]['body'],true)===['token'=>'signed-token'],'apply sends only the exact plugin-issued signature');
require $root.'/app/Services/AutoFix/TourfectoPluginBundle.php';
$bundle=TourfectoPluginBundle::files(32,'https://platform.example/api/rules','site.example');
check(isset($bundle['tourfecto-seo/modules/tourfecto-content-seo.php']) && str_contains($bundle['tourfecto-seo/tourfecto-seo.php'],'Version: 3.6.0'),'download bundle includes the signed endpoint and updated plugin version');
echo "\n".($n-$fails)."/$n passed\n";exit($fails?1:0);
