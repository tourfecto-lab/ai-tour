<?php
$root=dirname(__DIR__,2);require $root.'/app/Services/Http/ApiRouteBridge.php';require $root.'/app/Services/SeoExpert/SeoVerificationState.php';require $root.'/app/Core/Router.php';
$n=0;$fails=0;function check($c,$m){global $n,$fails;$n++;if(!$c)$fails++;echo($c?'  ok  ':'  FAIL ').$m.PHP_EOL;}
foreach(['/api/local-seo/sites','/api/international-seo/publish','/api/seo/data/markets','/api/plans/overview','/api/xpay/status','/api/currency','/api/website-optimizer/edge/php-connector','/api/edge/rules'] as $path){
 $server=['REQUEST_URI'=>'/index.php?__tf_api='.rawurlencode($path).'&website_id=32','REQUEST_METHOD'=>'POST'];$q=['__tf_api'=>$path,'website_id'=>'32'];
 check(ApiRouteBridge::normalize($server,$q)&&$server['REQUEST_URI']===$path.'?website_id=32'&&$server['REQUEST_METHOD']==='POST'&&!isset($q['__tf_api']),'bridge normalizes '.$path.' without changing method');
}
foreach(['/login','https://attacker.example/api/plans/overview','/api/../login','/api/plans?x=1','/api/plans#bad','/api/plans%2Foverview','//api/plans','/api/plans.php'] as $path)check(!ApiRouteBridge::allowed($path),'rejects non-canonical route '.$path);
$s=['REQUEST_URI'=>'/login'];$q=['__tf_api'=>'/api/plans/overview'];check(!ApiRouteBridge::normalize($s,$q),'bridge is available only through the front controller');
$s=['REQUEST_URI'=>'/index.php'];$q=['__tf_api'=>['/api/plans']];check(!ApiRouteBridge::normalize($s,$q),'array route parameter is rejected');
// The real router still invokes middleware after normalization.
class AuditAuthMiddleware {public function handle(){return empty($_SERVER['AUDIT_AUTH'])?['success'=>false,'status'=>401]:null;}}
class AuditCsrfMiddleware {public function handle(){return empty($_SERVER['AUDIT_CSRF'])?['success'=>false,'status'=>419]:null;}}
class AuditBridgeController {public function save(){return ['success'=>true,'body'=>'unchanged'];}}
$router=new Router();$router->post('/api/local-seo/profile',AuditBridgeController::class,'save',[AuditAuthMiddleware::class,AuditCsrfMiddleware::class]);
$s=['REQUEST_URI'=>'/index.php'];$q=['__tf_api'=>'/api/local-seo/profile'];ApiRouteBridge::normalize($s,$q);
check($router->dispatch('POST',$s['REQUEST_URI'])['status']===401,'normalized API still rejects missing authentication');$_SERVER['AUDIT_AUTH']=1;
check($router->dispatch('POST',$s['REQUEST_URI'])['status']===419,'normalized API still rejects missing CSRF');$_SERVER['AUDIT_CSRF']=1;
check($router->dispatch('POST',$s['REQUEST_URI'])['success'],'authorized normalized request reaches the original action');
$now=strtotime('2026-10-17 12:00:00 UTC');
check(SeoVerificationState::forTask(['status'=>'verified','checked_at'=>'2026-10-15 12:00:00'],$now)['state']==='historical','old verification is historical, not a current guarantee');
check(SeoVerificationState::forTask(['status'=>'verified','checked_at'=>'2026-10-17 11:00:00'],$now)['state']==='verified_recently','recent verification remains a dated observation');
check(SeoVerificationState::forTask(['status'=>'verified','checked_at'=>null],$now)['state']==='historical','missing timestamp cannot manufacture a current verification');
check(SeoVerificationState::forTask(['status'=>'active','checked_at'=>'2026-10-17 11:00:00'],$now)['state']==='not_verified','active publication is not counted as verified');
check(SeoVerificationState::forTask(['status'=>'verified','checked_at'=>'2026-10-18 11:00:00'],$now)['state']==='historical','future timestamp cannot create fresh verification');
$a=SeoVerificationState::content("Intro.\n\n**Luxor**\nA 2 day trip.\n\n- Nile cruise");$b=SeoVerificationState::content('<p>Intro.</p><h2>Luxor</h2><p>A 2 day trip.</p><ul><li>Nile cruise</li></ul>');
check($a===$b,'full article comparison accepts rendered headings/lists instead of markdown');
check(!str_contains(SeoVerificationState::content('Intro. Luxor A 2 day trip.'),$a),'matching title and first paragraph cannot verify a truncated article');
check(!str_contains(SeoVerificationState::content('Home. Book your trip.'),$a),'homepage response cannot verify an article');
require $root.'/app/Services/AutoFix/PageContext.php';require $root.'/app/Services/SeoExpert/SeoExpertPageContext.php';
class AuditTextReader extends SeoExpertPageContext {public function fresh(string $url): array {return ['text'=>str_repeat('x',$this->textLimit())];}}
$reader=new AuditTextReader();check(strlen($reader->fresh('https://example.com')['text'])===2500,'planning retains its existing bounded text excerpt');
check(strlen($reader->freshPublication('https://example.com')['text'])===60000,'publication verification can inspect the complete approved article');
check(strlen($reader->fresh('https://example.com')['text'])===2500,'full verification does not enlarge later planning prompts');
echo PHP_EOL.($n-$fails)."/$n passed".PHP_EOL;exit($fails?1:0);
