<?php
/** Exercise the same output-translation hook as the front controller over real local HTTP. */
require __DIR__.'/../../app/Helpers/ui_autotranslate.php';
require __DIR__.'/../../app/Services/Seo/SeoMonthlyReportService.php';
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
check(tf_autotranslate_path_excluded('/api/local-seo/report'),'monthly report is excluded from account UI translation');
check(!tf_autotranslate_path_excluded('/local-seo')&&!tf_autotranslate_path_excluded('/api/local-seo/data')&&!tf_autotranslate_path_excluded('/api/local-seo/report-other'),'neighboring UI and API paths retain existing translation');
$dir=sys_get_temp_dir().'/tf-report-language-'.getmypid();mkdir($dir,0700,true);$root=dirname(__DIR__,2);
$data=SeoMonthlyReportService::assemble(['tasks'=>[['title'=>'تقرير عميل عربي — خدمات مؤكدة','status'=>'verified']]],['start'=>'2026-09-01','end'=>'2026-09-30']);$html=SeoMonthlyReportService::html($data,'https://example.com');file_put_contents($dir.'/report.html',$html);
$router= <<<'ROUTER'
<?php
function current_lang():string {return ($_GET['ui']??'en')==='ar'?'ar':'en';}
require ROOT.'/app/Services/Http/ApiRouteBridge.php';
ApiRouteBridge::normalize($_SERVER,$_GET);
require ROOT.'/app/Helpers/ui_autotranslate.php';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
tf_autotranslate_start($path);
if($path==='/api/local-seo/report'){
    class Controller {protected $db=null;protected $user=['id'=>1];protected function isAuthenticated(){return ($_GET['authenticated']??'1')==='1';}protected function get($key){return $_GET[$key]??32;}}
    class SeoMarketService {public function __construct(...$args){}public function site(...$args){return ['main_url'=>'https://example.com'];}}
    class SeoMonthlyReportService {public function __construct(...$args){}public function data(...$args){if(($_GET['fail']??'')==='1')throw new RuntimeException('Offline failure');return [];}public static function html(...$args){return file_get_contents(__DIR__.'/report.html');}}
    require ROOT.'/app/Controllers/LocalSeoController.php';
    (new LocalSeoController())->report();
    // Mirrors the real Router::_raw_text fall-through under PHP-FPM output buffering.
    if(!tf_output_started()){http_response_code(200);header('Content-Type: text/plain; charset=utf-8');}
    echo 'Unexpected API dispatch fall-through';
}else{header('Content-Type: text/html; charset=utf-8');echo '<html lang="ar" dir="rtl"><head><meta charset="utf-8"></head><body>الخدمات</body></html>';}
ROUTER;
file_put_contents($dir.'/router.php',str_replace('ROOT',var_export($root,true),$router));
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
$server=proc_open(['php','-d','output_buffering=4096','-S','127.0.0.1:'.$port,$dir.'/router.php'],[1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']],$pipes);usleep(250000);
try{
 foreach(['en','ar'] as $lang)foreach(['/api/local-seo/report?ui='.$lang,'/index.php?__tf_api=%2Fapi%2Flocal-seo%2Freport&ui='.$lang] as $path){$body=file_get_contents('http://127.0.0.1:'.$port.$path);check(str_contains(strtolower(implode(' ', $http_response_header??[])),'content-type: text/html'),'report retains HTML Content-Type under buffered dispatch');check($body===$html,'report bytes, Arabic labels and RTL survive '.$lang.' UI via '.(str_starts_with($path,'/index.php')?'bridge':'direct route'));}
 $failure=file_get_contents('http://127.0.0.1:'.$port.'/api/local-seo/report?ui=en&fail=1',false,stream_context_create(['http'=>['ignore_errors'=>true]]));check(str_contains($http_response_header[0]??'','503'),'failed report retains its HTTP error status');
 $unauthenticated=file_get_contents('http://127.0.0.1:'.$port.'/api/local-seo/report?ui=en&authenticated=0',false,stream_context_create(['http'=>['ignore_errors'=>true]]));check(str_contains($http_response_header[0]??'','401')&&$unauthenticated==='','unauthenticated report cannot fall through into HTTP 200');
 $neighbor=file_get_contents('http://127.0.0.1:'.$port.'/local-seo?ui=en');check(str_contains($neighbor,'<html lang="en" dir="ltr">'),'normal English account UI still translates its document attributes');
 $ar=file_get_contents('http://127.0.0.1:'.$port.'/local-seo?ui=ar');check(str_contains($ar,'<html lang="ar" dir="rtl">'),'normal Arabic account UI remains RTL');
 $other=file_get_contents('http://127.0.0.1:'.$port.'/api/local-seo/report-other?ui=en');check(str_contains($other,'<html lang="en" dir="ltr">'),'exception is exact, not an entire report prefix');
}finally{proc_terminate($server);proc_close($server);}
echo "$n assertions passed\n";
