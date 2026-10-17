<?php
// Read-only child of the guarded Phase 6 fixture suite; never opens the production DB.
if(getenv('TF_GEO_ALLOW_FIXTURES')!=='1')exit(2);
define('DB_HOST',getenv('DB_HOST')?:'127.0.0.1');define('DB_PORT',3306);define('DB_NAME','tourfecto_test');define('DB_USER',getenv('DB_USER')?:'tf');define('DB_PASS',getenv('DB_PASS')?:'tfpass');define('DB_CHARSET','utf8mb4');define('DB_OPTIONS',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);define('APP_URL','https://platform.example');
$root=dirname(__DIR__,3);$map=require $root.'/app/Config/project_classmap.php';spl_autoload_register(static function($class)use($map){if(isset($map[$class]))require_once $map[$class];});
$db=Database::getInstance();if(!$db->query("SHOW TABLES LIKE 'seo_phase3_fixture'"))exit(2);
$rc=new ReflectionClass(WebsiteBuilderController::class);$c=$rc->newInstanceWithoutConstructor();(new ReflectionProperty(Controller::class,'db'))->setValue($c,$db);
$c->siteSitemap(['slug'=>'fixture']);
