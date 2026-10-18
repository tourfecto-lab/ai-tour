<?php
/** Actual profile controller with fake Google/DB dependencies; no network or SQLite. */
ob_start();
class Database {
    public array $writes=[];
    public array $reads=[];
    public ?array $savedProfile=null;
    public function exec($sql,$args): void {$this->writes[]=[$sql,$args];}
    public function query($sql,$args): array {
        $this->reads[]=[$sql,$args];
        if(str_contains($sql,'SELECT profile_json'))return $this->savedProfile?[['profile_json'=>json_encode($this->savedProfile)]]:[];
        if(str_contains($sql,'seo_local_grid_runs')||str_contains($sql,'seo_citation_tasks'))return [];
        throw new RuntimeException('Unexpected offline query');
    }
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
    public static ?string $status='connected';
    public function findConnection($wid,$uid){if(self::$status===null)return null;return new class {public function getAttribute($key){return $key==='status'?GbpSyncService::$status:'offline-id';}};}
}
class GoogleReviewSyncService {
    public static bool $valid=true;
    public function getValidAccessToken($connection){if(!self::$valid)throw new RuntimeException('Offline token denied');return 'offline';}
}
class GoogleBusinessAPI {
    public static array $location=[];
    public static bool $available=true;
    public static int $calls=0;
    public function __construct(...$args){}
    public function getLocation(): array{self::$calls++;return ['success'=>self::$available,'location'=>self::$location];}
}
class SeoPublicPageReader {public function fetch(string $url): array{return ['success'=>false];}}
class Logger {public static function warning(...$args){}}
require_once __DIR__.'/../../app/Core/Contracts/KeywordRankingSourceInterface.php';
require_once __DIR__.'/../../app/Services/Seo/DataForSeoRankingSource.php';
require_once __DIR__.'/../../app/Services/GoogleBusiness/LocalRankGridService.php';
require_once __DIR__.'/../../app/Services/GoogleBusiness/NapConsistencyService.php';
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
GbpSyncService::$status='disconnected';
$result=(new LocalSeoController($input,$db))->profile();
check(!$result['success']&&count($db->writes)===$before,'an explicitly disconnected GBP account cannot confirm its profile');
GbpSyncService::$status='error';
$result=(new LocalSeoController($input,$db))->profile();
check($result['success']&&count($db->writes)===$before+1,'a saved sync error does not block confirmation when Google returns the actual profile');
GoogleBusinessAPI::$available=false;$before=count($db->writes);
$result=(new LocalSeoController($input,$db))->profile();
check(!$result['success']&&count($db->writes)===$before,'a failed live Google read cannot save or confirm a historical error profile');
GoogleBusinessAPI::$available=true;GoogleReviewSyncService::$valid=false;
$result=(new LocalSeoController($input,$db))->profile();
check(!$result['success']&&count($db->writes)===$before,'invalid OAuth credentials still prevent profile confirmation');
GoogleReviewSyncService::$valid=true;
$db->savedProfile=$profile+['directory_profiles'=>$input['directory_profiles'],'confirmed_at'=>'2026-10-18T08:00:00Z','private_marker'=>'must not be exposed'];
$result=(new LocalSeoController(['website_id'=>33],$db))->data();
check($result['success']&&$result['data']['profile']['directory_profiles']===$input['directory_profiles'],'reopening the page restores the customer-approved directory profiles');
check(!array_intersect(['private_marker','place_id','latitude'],array_keys($result['data']['profile'])),'read-only guidance exposes only the required business facts');
check($result['data']['maps_grid_ready']===false,'saved NAP facts do not imply map coordinates are available');
check(array_reduce($db->reads,fn($ok,$read)=>$ok&&$read[1]===[33,1],true),'saved guidance reads remain scoped by website and customer');
$result=(new NapConsistencyService($db))->check(1,33);
check(count($result['observations'])===2&&$result['observations'][0]['fetch_state']==='unavailable','citation comparison retries a sync-error account and uses a fresh successful Google profile');
check($result['observations'][0]['checks']['name']['expected']===GoogleBusinessAPI::$location['name'],'comparison uses the live official facts rather than claiming a stale profile is verified');
check(count(array_filter($result['observations'][0]['checks'],fn($c)=>$c['state']==='unknown'))===4&&count($db->writes)===$before,'unavailable website and directory sources stay unknown and create no mismatch tasks');
foreach(['disconnected','pending',null] as $status){
    GbpSyncService::$status=$status;$calls=GoogleBusinessAPI::$calls;
    $r=(new LocalSeoController($input,$db))->profile();
    check(!$r['success']&&denied(fn()=>(new NapConsistencyService($db))->check(1,33))&&GoogleBusinessAPI::$calls===$calls,'inactive or absent connection cannot trigger Google reads or NAP confirmation: '.($status??'absent'));
}
GbpSyncService::$status='error';GoogleBusinessAPI::$available=false;
try{(new NapConsistencyService($db))->check(1,33);$blocked=false;}catch(RuntimeException $e){$blocked=true;}
check($blocked&&count($db->writes)===$before,'comparison fails closed when the live profile cannot be verified');
GoogleBusinessAPI::$available=true;GoogleReviewSyncService::$valid=false;
try{(new NapConsistencyService($db))->check(1,33);$blocked=false;}catch(RuntimeException $e){$blocked=true;}
check($blocked&&count($db->writes)===$before,'comparison fails closed when token validation fails');
GoogleReviewSyncService::$valid=true;$fresh=GoogleBusinessAPI::$location;GoogleBusinessAPI::$location['place_id']='another-branch';
check(denied(fn()=>(new NapConsistencyService($db))->check(1,33))&&count($db->writes)===$before,'retrying a sync error cannot silently switch the confirmed business branch');
GoogleBusinessAPI::$location=$fresh;
echo $n.' assertions passed'.PHP_EOL;
ob_end_flush();
