<?php
// Only a disposable database carrying our explicit fixture marker may be reset.
if(getenv('TF_GEO_ALLOW_FIXTURES')!=='1'){fwrite(STDERR,"Set TF_GEO_ALLOW_FIXTURES=1 for the disposable tourfecto_test database\n");exit(2);}
define('DB_HOST',getenv('DB_HOST')?:'127.0.0.1');define('DB_PORT',3306);define('DB_NAME','tourfecto_test');define('DB_USER',getenv('DB_USER')?:'tf');define('DB_PASS',getenv('DB_PASS')?:'tfpass');define('DB_CHARSET','utf8mb4');
define('DB_OPTIONS',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
define('ENCRYPTION_KEY',base64_encode(random_bytes(32)));define('ENCRYPTION_METHOD','openssl');
$root=dirname(__DIR__,3);
class Logger{public static array $errors=[];public static function error($s,$c=[]){self::$errors[]=$s.' '.($c['message']??$c['error']??'');}public static function warning($s,$c=[]){} }
class ActivityLog{public static function record(...$args){} }
class ContentLanguageService{public static function forWebsite($wid,$uid=null):string{return 'en';}}
$map=require $root.'/app/Config/project_classmap.php';spl_autoload_register(static function($class)use($map){if(isset($map[$class]))require_once $map[$class];});
$db=Database::getInstance();$pdo=$db->getConnection();$tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if($tables&&!in_array('seo_phase2_fixture',$tables,true)&&!in_array('seo_phase3_fixture',$tables,true)){fwrite(STDERR,"Refusing a non-fixture database\n");exit(2);}
$pdo->exec('CREATE TABLE IF NOT EXISTS seo_phase3_fixture(id INT PRIMARY KEY)');
$known=['seo_markets','seo_keyword_research','seo_research_cache','seo_provider_alerts','dataforseo_calls','users','websites','subscriptions','subscription_plans','plan_usage_events','wallet_transactions','pay_per_use_pricing','system_settings','notifications','activity_logs','competitors','competitor_keywords','tracked_keywords','seo_rank_tracking_history','ci_keyword_rankings','business_hub_profiles','jobs','seo_ai_prompt_sets','seo_ai_entities','seo_ai_calls','seo_ai_answers','seo_ai_tasks','seo_ai_budget_alerts'];
foreach ($known as $table) $pdo->exec('DROP TABLE IF EXISTS `'.$table.'`');
$fixture=[
'users'=>"id INT PRIMARY KEY,owner_user_id INT NULL,role VARCHAR(30),notification_preferences TEXT NULL",
'websites'=>"id INT PRIMARY KEY,user_id INT,main_url VARCHAR(255),is_connected INT DEFAULT 1,last_rank_tracked_at DATETIME NULL,company_name VARCHAR(100)",
'subscription_plans'=>"id INT PRIMARY KEY,plan_code VARCHAR(100),billing_cycle VARCHAR(20),features_json TEXT,ai_analysis_limit INT DEFAULT 0,ai_message_limit INT DEFAULT 0,review_auto_reply_limit INT DEFAULT 0",
'subscriptions'=>"id INT PRIMARY KEY,user_id INT,plan_id INT,price DECIMAL(10,2) DEFAULT 10,currency VARCHAR(3) DEFAULT 'USD',status VARCHAR(20),current_period_start DATETIME,current_period_end DATETIME,trial_ends_at DATETIME NULL,usage_ai_analysis_count INT DEFAULT 0,usage_ai_message_count INT DEFAULT 0,usage_review_reply_count INT DEFAULT 0,competitor_analysis_used INT DEFAULT 0",
'plan_usage_events'=>"id INT AUTO_INCREMENT PRIMARY KEY,subscription_id INT,user_id INT,feature VARCHAR(50),note VARCHAR(255),created_at DATETIME",
'wallet_transactions'=>"id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,type VARCHAR(50),amount DECIMAL(12,4),currency VARCHAR(3),status VARCHAR(30),reference_note VARCHAR(255),feature_key VARCHAR(100),approved_at DATETIME NULL,idempotency_key VARCHAR(100) NULL",
'pay_per_use_pricing'=>"id INT AUTO_INCREMENT PRIMARY KEY,feature_key VARCHAR(50) UNIQUE,label VARCHAR(100),price DECIMAL(12,4),currency VARCHAR(3),currency_symbol VARCHAR(5),is_active INT",
'system_settings'=>"setting_key VARCHAR(100) PRIMARY KEY,setting_value TEXT,is_secret INT,category VARCHAR(50)",
'notifications'=>"id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,type VARCHAR(50),title VARCHAR(200),body TEXT,link VARCHAR(200)",
'activity_logs'=>"id INT AUTO_INCREMENT PRIMARY KEY,module VARCHAR(50),action VARCHAR(100),user_id INT,created_at DATETIME",
'competitors'=>"id INT PRIMARY KEY,website_id INT,user_id INT,competitor_domain VARCHAR(255),competitor_name VARCHAR(255),my_score INT,competitor_score INT,last_analyzed_at DATETIME,is_active INT DEFAULT 1,monitoring_paused INT DEFAULT 0",
'competitor_keywords'=>"id BIGINT AUTO_INCREMENT PRIMARY KEY,competitor_id INT,keyword VARCHAR(255),search_volume INT NULL,difficulty INT NULL,source VARCHAR(100),discovered_at DATETIME DEFAULT CURRENT_TIMESTAMP",
'ci_keyword_rankings'=>"id INT AUTO_INCREMENT PRIMARY KEY,competitor_id INT,keyword VARCHAR(255),position INT NULL,url VARCHAR(1000),source VARCHAR(100),checked_at DATETIME",
// Simulate pre-phase-2 deployment, including its old unique constraint.
'tracked_keywords'=>"id INT AUTO_INCREMENT PRIMARY KEY,website_id INT,user_id INT,keyword VARCHAR(255),current_position INT NULL,search_volume INT NULL,difficulty INT NULL,last_checked_at DATETIME NULL,enriched_at DATETIME NULL,search_intent VARCHAR(50),commercial_intent VARCHAR(50),opportunity_score INT NULL,target_page VARCHAR(255),priority VARCHAR(20),UNIQUE KEY uniq_website_keyword(website_id,keyword)",
'seo_rank_tracking_history'=>"id INT AUTO_INCREMENT PRIMARY KEY,website_id INT,user_id INT,keyword VARCHAR(255),position INT NULL,url VARCHAR(1000),source VARCHAR(100),checked_at DATETIME"
];
foreach ($fixture as $t=>$columns) $pdo->exec('CREATE TABLE `'.$t.'` ('.$columns.') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$pdo->exec("INSERT INTO users VALUES (1,NULL,'customer',NULL),(2,NULL,'customer',NULL),(3,NULL,'super_admin',NULL);
INSERT INTO websites (id,user_id,main_url,company_name) VALUES (1,1,'https://example.com','Fixture'),(2,2,'https://other.test','Other');
INSERT INTO subscription_plans (id,plan_code,billing_cycle,features_json) VALUES (1,'professional_monthly','monthly','{\"ai_visibility\":2,\"seo_data\":2,\"seo_markets\":3}');
INSERT INTO subscriptions (id,user_id,plan_id,status,current_period_start,current_period_end) VALUES (1,1,1,'active',UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 MONTH));
INSERT INTO wallet_transactions (user_id,type,amount,currency,status) VALUES (1,'deposit',0.15,'USD','completed');");

$pdo->exec("CREATE TABLE business_hub_profiles(website_id INT PRIMARY KEY,user_id INT,version INT,facts_json TEXT,confirmed_at DATETIME);
CREATE TABLE jobs(id INT AUTO_INCREMENT PRIMARY KEY,queue VARCHAR(50),job_class VARCHAR(100),payload TEXT,status VARCHAR(30),attempts INT DEFAULT 0,available_at DATETIME,created_at DATETIME,reserved_at DATETIME NULL,completed_at DATETIME NULL,last_error TEXT NULL);");
