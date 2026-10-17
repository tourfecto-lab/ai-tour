<?php
/** Offline presentation, exact article routing and publication-evidence contracts. */
$root=dirname(__DIR__,2);
foreach(['Seo/GuideContent','Seo/ContentQualityService','SeoExpert/SeoPublicationAudit','SeoExpert/SeoExpertPageContext','AI/ArticleGenerator'] as $file)require_once $root.'/app/Services/'.$file.'.php';
$n=0;$fails=0;function check($ok,$name){global $n,$fails;$n++;if(!$ok)$fails++;echo($ok?'  ok  ':'  FAIL ').$name.PHP_EOL;}
$body="Intro text.\n\n## Luxor temples\nA confirmed itinerary.\n\n### Getting there\nUse your agreed pickup.\n\n- Water\n- Hat\n\n**Booking**\nContact the company.\n\n<script>alert(1)</script>";
$html=GuideContent::html($body);
check(str_contains($html,'<h2>Luxor temples</h2>')&&str_contains($html,'<h3>Getting there</h3>'),'Markdown headings have semantic h2/h3 levels without a duplicate h1');
check(str_contains($html,'<ul><li>Water</li><li>Hat</li></ul>'),'lists remain structured');
check(str_contains($html,'<h2>Booking</h2>'),'bold-only headings render as headings');
check(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'),'article markup cannot execute scripts');
check(str_contains(GuideContent::html("Luxor: A visitor’s guide\nA helpful paragraph."),'<h2>Luxor: A visitor’s guide</h2>'),'old standalone section line is recognized without changing approved wording');
check(!str_contains(GuideContent::html("A full sentence.\nAnother sentence."),'<h2>'),'ordinary sentences do not become headings');
$source='<html lang="en"><head><title>Home</title><link rel="stylesheet" href="/site.css"><script>const s="<main>not a main</main>";</script><script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization","name":"Confirmed company"},{"@type":"FAQPage","mainEntity":[]}]}</script></head><body><header id="site-header"><a href="/">Brand</a></header><main class="homepage"><h1>Home</h1></main><footer id="site-footer">Customer footer</footer><script src="/site.js"></script></body></html>';
$theme=GuideContent::theme($source,'<article><h1>Actual article</h1>'.$html.'</article>');
check($theme!==null&&str_contains($theme,'id="site-header"')&&str_contains($theme,'id="site-footer"'),'automatic theme retains the site header/footer');
check(str_contains($theme,'href="/site.css"')&&str_contains($theme,'src="/site.js"')&&str_contains($theme,'const s="<main>not a main</main>"'),'theme keeps site assets and script bytes');
check(!str_contains($theme,'<h1>Home</h1>')&&str_contains($theme,'data-tourfecto-guide'),'only homepage main content is replaced');
check(str_contains($theme,'Confirmed company')&&!str_contains($theme,'FAQPage'),'home-only FAQ schema is removed while organization identity is retained');
check(GuideContent::theme('<html><head></head><body><main>A</main><main>B</main></body></html>','X')===null,'ambiguous themes decline replacement for safe standalone fallback');
check(GuideContent::theme('<html><head></head><body>No main</body></html>','X')===null,'missing main declines replacement');
$article='https://site.example/?tf_article=luxor-guide';
check(ContentQualityService::publicationPath($article,'site.example')==='/?tf_article=luxor-guide','exact fallback article has an isolated rules key');
foreach(['https://site.example/?tf_article[]=luxor-guide','https://site.example/?tf_article=luxor-guide&preview=1','https://site.example/?tf_article=luxor-guide&tf_article=other','https://evil.example/?tf_article=luxor-guide','https://site.example/tours?tf_article=luxor-guide','https://site.example/?tf_article=../admin'] as $bad)check(ContentQualityService::publicationPath($bad,'site.example')===null,'unsafe/ambiguous query cannot create article rules');
$row=['page_url'=>$article,'title'=>'Luxor guide','description'=>'A real guide','published_at'=>'2026-10-17 12:00:00','faqs_json'=>'[{"question":"Is pickup included?","answer":"Confirm pickup before booking."}]','pillar_url'=>'https://site.example/tours/luxor','pillar_title'=>'Luxor day tour'];
$rules=ContentQualityService::rules([$row],'site.example');
check(isset($rules['/?tf_article=luxor-guide']['jsonld'])&&!isset($rules['/']),'query FAQ rules never target the homepage');
check($rules['/tours/luxor']['related_links'][0]['url']===$article,'pillar links back to the actual accessible query publication');
check(ContentQualityService::rules([$row],'site.example',$rules)===$rules,'query schema and reciprocal links are repeatable');
$schema=ContentQualityService::schema($row);$ctx=['ok'=>true,'guide_layout'=>'site','title'=>'Luxor guide','canonical'=>$article,'schema'=>[$schema],'headings'=>['Luxor temples'],'tf_related'=>[$row['pillar_url']],'related_link_checks'=>[$row['pillar_url']=>'passed'],'text'=>'Is pickup included? Confirm pickup before booking.'];$task=['title'=>'Luxor guide','published_url'=>$article,'expected_value'=>'Is pickup included? Confirm pickup before booking.'];
$audit=SeoPublicationAudit::inspect($task,$ctx,$row,['state'=>'passed','url'=>'https://site.example/tourfecto-guides-sitemap.xml']);
check($audit['state']==='complete','all observed quality requirements produce complete quality status');
$bad=$ctx;$bad['canonical']='https://site.example/';$audit=SeoPublicationAudit::inspect($task,$bad,$row,['state'=>'passed']);
check($audit['state']==='needs_review'&&$audit['checks']['canonical']==='missing','homepage canonical cannot pass the publication checklist');
$bad=$ctx;$bad['text']='Question and answer removed.';
check(SeoPublicationAudit::inspect($task,$bad,$row,['state'=>'passed'])['checks']['faq_schema']==='missing','FAQ JSON-LD without visibly matching answers fails');
$bad=$ctx;$bad['tf_related']=[];
check(SeoPublicationAudit::inspect($task,$bad,$row,['state'=>'passed'])['checks']['internal_links']==='missing','missing expected pillar link fails');
$bad=$ctx;$bad['related_link_checks']=[$row['pillar_url']=>'missing'];
check(SeoPublicationAudit::inspect($task,$bad,$row,['state'=>'passed'])['checks']['link_targets']==='missing','visible link to a missing page cannot pass link target checks');
$bad=$ctx;$bad['robots']='noindex,follow';
check(SeoPublicationAudit::inspect($task,$bad,$row,['state'=>'passed'])['checks']['indexable']==='missing','noindex is surfaced for review without removing site policy');
check(SeoPublicationAudit::inspect($task,$ctx,$row,['state'=>'not_measured'])['state']==='needs_review','failed sitemap access never becomes a successful check');
class PolishSitemapReader extends SeoExpertPageContext {
    public array $responses=[];public array $requests=[];
    protected function get(string $url,bool $withBody,string $method='GET'): array {$this->requests[]=$url;return ['error'=>null,'http'=>200,'body'=>$this->responses[$url]??'not XML','final_url'=>$url];}
}
$reader=new PolishSitemapReader();$reader->responses['https://site.example/?tf_guides_sitemap=1']='<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>'.$article.'</loc></url></urlset>';
check($reader->publicationSitemap('https://site.example',$article,true)['state']==='passed','real XML parser finds exact query URL in connector sitemap');
$reader->responses['https://site.example/?tf_guides_sitemap=1']='<urlset><url><loc>https://site.example/guides/luxor-guide</loc></url></urlset>';
$reader->responses['https://site.example/tourfecto-guides-sitemap.xml']=$reader->responses['https://site.example/?tf_guides_sitemap=1'];
check($reader->publicationSitemap('https://site.example',$article,true)['state']==='missing','unrouted /guides entry cannot prove the fallback URL is listed');
$reader->responses['https://site.example/?tf_guides_sitemap=1']='<!DOCTYPE x [<!ENTITY leak SYSTEM "file:///etc/passwd">]><urlset>&leak;</urlset>';
$reader->responses['https://site.example/tourfecto-guides-sitemap.xml']=$reader->responses['https://site.example/?tf_guides_sitemap=1'];
check($reader->publicationSitemap('https://site.example',$article,true)['state']==='not_measured','DTD/entity XML is rejected without resolution');
$reader=new PolishSitemapReader();$reader->responses=['https://site.example/wp-sitemap.xml'=>'<sitemapindex><sitemap><loc>https://site.example/posts.xml</loc></sitemap><sitemap><loc>https://evil.example/external.xml</loc></sitemap></sitemapindex>','https://site.example/posts.xml'=>'<urlset><url><loc>'.$article.'</loc></url></urlset>'];
check($reader->publicationSitemap('https://site.example',$article,false)['state']==='passed','WordPress sitemap index child is inspected with fake transport');
check(!in_array('https://evil.example/external.xml',$reader->requests,true)&&count($reader->requests)<=5,'sitemap traversal stays same-site within request budget');
check(ArticleGenerator::validateBriefOutput(['content'=>'Price USD 65.'],['confirmed_facts'=>['offers'=>'USD 65']])===null,'sentence punctuation does not alter a confirmed price');
$reader=new PolishSitemapReader();$targets=$reader->publicationLinks(['https://site.example/tours/luxor','https://evil.example/tours/luxor'],'https://site.example');
check($targets['https://site.example/tours/luxor']==='passed'&&$targets['https://evil.example/tours/luxor']==='not_measured','related target HTTP check measures only the customer origin');
check($reader->requests===['https://site.example/tours/luxor'],'cross-origin link is not fetched');
$brief=['confirmed_facts'=>['business_name'=>'Nile Travel','offers'=>'USD 65']];
check(ArticleGenerator::validateBriefOutput(['title'=>'Tour USD 99','content'=>'Confirmed body'],$brief)!==null,'title prices are checked with body prices');
check(ArticleGenerator::validateBriefOutput(['meta_description'=>'Tour USD 99','content'=>'Confirmed body'],$brief)!==null,'SEO description prices are checked too');
echo PHP_EOL.($n-$fails)."/$n passed".PHP_EOL;exit($fails?1:0);
