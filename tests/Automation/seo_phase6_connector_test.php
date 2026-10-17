<?php
/** Exercises generated customer runtimes through local HTTP, never a provider. */
$root=dirname(__DIR__,2);require $root.'/app/Services/AutoFix/EdgeRules.php';
$n=0;$fails=0;function check($c,$m){global $n,$fails;$n++;echo($c?'  ok  ':'  FAIL ').$m."\n";if(!$c)$fails++;}
$dir=sys_get_temp_dir().'/tf-phase6-'.getmypid();mkdir($dir);$site=900000+getmypid();$key=str_repeat('b',32);
$cache=sys_get_temp_dir().'/tourfecto-edge-'.md5(EdgeRules::token($site)).'.json';
file_put_contents($cache,json_encode(['t'=>time(),'f'=>0,'rules'=>['indexnow_key'=>$key]]));
file_put_contents($dir.'/connector.php',EdgeRules::phpConnector($site,'http://127.0.0.1:9'));
$wp=var_export($root.'/app/Services/AutoFix/WordPressBundle/tourfecto-international.php',true);
file_put_contents($dir.'/router.php','<?php if(isset($_GET["wp"])){define("ABSPATH",__DIR__);function add_filter(...$a){}function add_action(...$a){}function status_header($s){http_response_code($s);}function tf_edge_rules(){return ["indexnow_key"=>"'.$key.'"];}require '.$wp.';TourfectoInternational::keyRoute();}else require __DIR__."/connector.php";echo "<html><head><title>Existing page</title></head><body>Existing feature</body></html>";');
$port=19200+getmypid()%100;$server=proc_open(['php','-S','127.0.0.1:'.$port,$dir.'/router.php'],[1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);usleep(400000);
function getLocal($path,$method='GET'){global $port;$ctx=stream_context_create(['http'=>['method'=>$method,'ignore_errors'=>true,'timeout'=>4]]);$body=file_get_contents('http://127.0.0.1:'.$port.$path,false,$ctx);return [$body,$http_response_header??[]];}
try{
 foreach([''=>'PHP','?wp=1'=>'WordPress'] as $query=>$label){
  [$body,$headers]=getLocal('/'.$key.'.txt'.$query);check($body===$key&&str_contains(implode(' ',$headers),'text/plain'),'actual '.$label.' customer-domain root key route returns plain key only');
  [$body]=getLocal('/'.$key.'.txt'.$query,'HEAD');check($body==='',$label.' HEAD serves no key body');
  [$body]=getLocal('/not-the-key.txt'.$query);check(str_contains($body,'Existing feature')&&!str_contains($body,$key),$label.' unknown key path preserves existing site');
 }
 // Test adapters in isolated processes so WPML and Polylang detection cannot contaminate each other.
 $fixture=<<<'WP'
<?php
define('ABSPATH',__DIR__);$mode=$argv[1];if($mode==='wpml')define('ICL_SITEPRESS_VERSION','fixture');
function add_action(...$a){}function add_filter(...$a){}function get_queried_object_id(){return 10;}function is_singular(){return true;}function is_front_page(){return false;}function get_post_type($id){return 'page';}
function get_post_status($id){return $id===30?'draft':'publish';}function get_permalink($id){return [10=>'https://customer.example/en/trip',20=>'https://customer.example/de/reise',30=>'https://customer.example/ar/draft'][$id];}
function apply_filters($hook,$value,...$args){if($hook==='wpml_active_languages')return ['en'=>[],'de'=>[],'ar'=>[]];if($hook==='wpml_default_language')return 'en';if($hook==='wpml_object_id'){if($args[1]!==false)throw new RuntimeException('Guessed fallback translation');return ['en'=>10,'de'=>20,'ar'=>30][$args[2]];}return $value;}
function pll_get_post_translations($id){return ['en'=>10,'de'=>20,'ar'=>30];}function pll_default_language($field){return 'en';}
function esc_attr($v){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}function esc_url($v){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
WP;
 $fixture.='require '.$wp.';echo json_encode(["map"=>TourfectoInternational::map(),"head"=>TourfectoInternational::head(\'<title>Keep</title><link rel="alternate" hreflang="xx" href="https://bad.example/guess">\')]);';file_put_contents($dir.'/wp-map.php',$fixture);
 foreach(['wpml','polylang'] as $mode){
  $p=proc_open(['php',$dir.'/wp-map.php',$mode],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);$r=json_decode($out,true);
  check($code===0&&($r['map']['de']??'')==='https://customer.example/de/reise',$mode.' reads actual translation IDs and permalinks');
  check(!isset($r['map']['ar'])&&($r['map']['x-default']??'')==='https://customer.example/en/trip',$mode.' excludes draft translation and uses known default language');
  check(substr_count($r['head']??'','hreflang=')===3&&!str_contains($r['head']??'','bad.example')&&str_contains($r['head']??'','<title>Keep</title>'),$mode.' replaces duplicate alternates and preserves other head features');
  if($code!==0)echo $err;
 }
}finally{proc_terminate($server);proc_close($server);@unlink($cache);foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
echo "\n".($n-$fails)."/$n passed\n";exit($fails?1:0);
