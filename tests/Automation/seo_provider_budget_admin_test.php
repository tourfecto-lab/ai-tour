<?php
/** Actual controller role boundary; no production DB, secrets, provider calls or budget writes. */
class Database {public static function getInstance(){return new self;}}
class Controller {public array $user=[];protected Database $db;public function __construct(){$this->db=new Database;}protected function error($message,$code=422){return ['success'=>false,'error'=>$message,'code'=>$code];}protected function success($data){return ['success'=>true,'data'=>$data];}}
class SystemSettingsService {public static int $reads=0;public function getAllForAdmin(){self::$reads++;return ['services'=>[['key'=>'serp_login','is_secret'=>true,'is_set'=>true,'value'=>'']]];}}
class DataForSeoClient {public static int $reads=0;public function budgetSummary($db){self::$reads++;return ['stats_available'=>true,'cap'=>1,'actual_cost'=>0,'held_cost'=>0.95,'remaining'=>0.05,'unknown_calls'=>1];}}
class Logger {public static function error(...$args){}}
require __DIR__.'/../../app/Controllers/AdminController.php';
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
$c=new AdminController;
foreach([[],['role'=>'customer'],['role'=>'admin']] as $user){$c->user=$user;check($c->getSystemSettings()['code']===403,'unprivileged role cannot read global provider figures: '.($user['role']??'anonymous'));}
check(SystemSettingsService::$reads===0&&DataForSeoClient::$reads===0,'denied requests never read settings or global budget');
$c->user=['id'=>1,'role'=>'super_admin'];$r=$c->getSystemSettings();check($r['success']&&$r['data']['seo_provider_budget']['remaining']===0.05,'authorized Super Admin receives the read-only global budget summary');
check($r['data']['settings']['services'][0]['value']===''&&SystemSettingsService::$reads===1&&DataForSeoClient::$reads===1,'existing masked settings response survives without extra reads or any mutation');
echo "$n assertions passed\n";
