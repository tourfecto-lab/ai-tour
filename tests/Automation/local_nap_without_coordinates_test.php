<?php
/** Actual profile controller with fake Google/DB dependencies; no network or SQLite. */
ob_start();
class Database {
    public array $writes=[];
    public function exec($sql,$args): void {$this->writes[]=[$sql,$args];}
}
class Controller {
    protected Database $db;
    protected array $user=['id'=>1];
    public function __construct(public array $input,Database $db){$this->db=$db;}
    protected function isAuthenticated(): bool{return true;}
    protected function verifyCsrf(){return null;}
    protected function rateLimitGuard(...$args){return null;}
    protected function get($key,$default=null){return $this->input[$key]??$default;}
    protected function success($data): array{return ['success'=>true,'data'=>$data];}
    protected function error($message,$code): array{return ['success'=>false,'error'=>$message,'code'=>$code];}
}
class SeoMarketService {
    public function __construct(Database $db){}
    public function site($wid,$uid): array{if($wid!==33||$uid!==1)throw new InvalidArgumentException('Website not found');return ['main_url'=>'https://customer.example'];}
}
class GbpSyncService {
    public static bool $connected=true;
    public function findConnection($wid,$uid){return new class {public function getAttribute($key){return $key==='status'?(GbpSyncService::$connected?'connected':'error'):'offline-id';}};}
}
class GoogleReviewSyncService {public function getValidAccessToken($connection){return 'offline';}}
class GoogleBusinessAPI {
    public static array $location=[];
    public function __construct(...$args){}
    public function getLocation(): array{return ['success'=>true,'location'=>self::$location];}
}
class Logger {public static function warning(...$args){}}
require_once __DIR__.'/../../app/Services/GoogleBusiness/LocalRankGridService.php';
require_once __DIR__.'/../../app/Controllers/LocalSeoController.php';
$n=0;
function check($ok,string $label): void{global $n;if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label.PHP_EOL;}
function denied(callable $fn): bool{try{$fn();return false;}catch(InvalidArgumentException $e){return true;}}
$db=new Database();
$profile=['name'=>'Confirmed Travel','phone'=>'+201001234567','website'=>'https://customer.example','address'=>['locality'=>'Cairo','regionCode'=>'EG'],'maps_uri'=>'https://maps.google.com/maps?cid=123456','latitude'=>null,'longitude'=>null,'place_id'=>'confirmed-place'];
GoogleBusinessAPI::$location=$profile;
$input=['website_id'=>33,'confirmed'=>true,'directory_profiles'=>['https://directory.example/company']];
$result=(new LocalSeoController($input,$db))->profile();
check($result['success']===true,'a connected business can confirm its real NAP profile without coordinates');
check($result['data']['maps_grid_ready']===false,'the same confirmation explicitly reports Maps measurement unavailable');
$stored=json_decode($db->writes[0][1][2],true);
check($stored['name']===$profile['name']&&$stored['address']===$profile['address']&&$stored['phone']===$profile['phone'],'saved NAP facts come unchanged from the Google profile');
check($stored['latitude']===null&&$stored['longitude']===null,'missing coordinates remain null rather than becoming a fabricated point');
check($stored['directory_profiles']===$input['directory_profiles']&&$stored['cid']==='123456','directory profiles and stable Maps identity survive confirmation');
check($db->writes[0][1][0]===33&&$db->writes[0][1][1]===1,'profile persistence keeps both customer and website ownership');
foreach([[],['latitude'=>null,'longitude'=>null],['latitude'=>30,'longitude'=>null],['latitude'=>null,'longitude'=>31],['latitude'=>false,'longitude'=>false],['latitude'=>'invalid','longitude'=>31],['latitude'=>90,'longitude'=>31],['latitude'=>30,'longitude'=>INF]] as $i=>$invalid){
    check(denied(fn()=>LocalRankGridService::gridForProfile($invalid)),'grid rejects missing or invalid profile coordinates #'.$i);
}
$zero=LocalRankGridService::gridForProfile(['latitude'=>0,'longitude'=>0]);
check(count($zero)===25&&$zero[12]['latitude']===0.0&&$zero[12]['longitude']===0.0,'explicitly provided zero coordinates remain valid and distinct from missing values');
GoogleBusinessAPI::$location=$profile;
GoogleBusinessAPI::$location['latitude']='30.0444';GoogleBusinessAPI::$location['longitude']='31.2357';
$result=(new LocalSeoController($input,$db))->profile();
check($result['success']&&$result['data']['maps_grid_ready']===true,'confirmed real numeric coordinates enable grid eligibility');
$before=count($db->writes);
$result=(new LocalSeoController(array_replace($input,['confirmed'=>false]),$db))->profile();
check(!$result['success']&&count($db->writes)===$before,'customer confirmation remains required before storing facts');
$result=(new LocalSeoController(array_replace($input,['website_id'=>99]),$db))->profile();
check(!$result['success']&&count($db->writes)===$before,'foreign website IDs cannot save the profile');
GbpSyncService::$connected=false;
$result=(new LocalSeoController($input,$db))->profile();
check(!$result['success']&&count($db->writes)===$before,'the no-coordinate path still requires a connected GBP account');
echo $n.' assertions passed'.PHP_EOL;
ob_end_flush();
