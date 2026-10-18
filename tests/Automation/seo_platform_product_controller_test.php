<?php
/** Exercise the actual public controller methods with offline auth/CSRF/permission guards. */
ob_start();
class Database {public array $calls=[];public static $instance;public static function getInstance(){return self::$instance??=new self();}public function query($sql,$params=[]){$this->calls[]=$sql;return [];}}
class Controller {
    protected array $user=['id'=>30];protected Database $db;public array $data=[];public bool $auth=true,$csrf=false,$limited=false;
    public function __construct(){$this->db=Database::getInstance();}
    protected function isAuthenticated(){return $this->auth;}
    protected function verifyCsrf(){return $this->csrf?null:$this->error('CSRF',419);}
    protected function rateLimitGuard(...$args){return $this->limited?$this->error('Limited',429):null;}
    protected function error($error,$code=422){return ['success'=>false,'error'=>$error,'code'=>$code];}
    protected function success($data){return ['success'=>true,'data'=>$data];}
}
class CrmPermissionService {public function can($uid,$permission){return false;}}
class Logger {public static function warning(...$args){}}
require_once __DIR__.'/../../app/Controllers/SeoImpactController.php';
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label.PHP_EOL;}
$c=new SeoImpactController();$p=['website_id'=>33];
check($c->platformProductCreate($p)['code']===419&&$c->platformProductPreview($p)['code']===419,'actual create and preview actions require CSRF before service access');
$c->auth=false;check($c->platformProductCreate($p)['code']===401&&$c->platformProductPreview($p)['code']===401,'unauthenticated callers cannot create or preview products');
$c->auth=true;$c->csrf=true;$c->limited=true;check($c->platformProductCreate($p)['code']===429&&$c->platformProductPreview($p)['code']===429,'catalog and remote preview share the existing write rate guard');
$c->limited=false;check($c->platformProductCreate($p)['code']===403,'a CRM role without create permission cannot use the SEO endpoint to bypass product permissions');
check(Database::getInstance()->calls===[],'all denied auth, CSRF, rate and permission cases perform no database operation');
$c->data=['source_url'=>'https://example.com/tour'];$r=$c->platformProductPreview($p);
check($r['code']===422&&count(Database::getInstance()->calls)===1&&str_contains(Database::getInstance()->calls[0],'FROM websites WHERE id=? AND user_id=?'),'preview checks website ownership before any remote request or parser use');
echo $n.' assertions passed'.PHP_EOL;ob_end_flush();
