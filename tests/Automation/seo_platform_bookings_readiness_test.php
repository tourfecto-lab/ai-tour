<?php
/** Schema metadata compatibility without SQLite, credentials or a live database. */
class Database
{
    public array $queries=[];
    public bool $owned=true;
    public function __construct(public array $rows) {}
    public function query(string $sql,array $params=[]): array {
        $this->queries[]=$sql;
        if(str_contains($sql,'FROM websites'))return $this->owned?[['id'=>33]]:[];
        if(str_contains($sql,'information_schema.columns'))return $this->rows;
        if(str_contains($sql,'COUNT(*) n'))return [['n'=>0]];
        throw new RuntimeException('Unexpected SQL in read-only readiness check.');
    }
}
require_once __DIR__.'/../../app/Services/SeoImpact/SeoImpactRules.php';
require_once __DIR__.'/../../app/Services/SeoImpact/SeoPlatformBookingService.php';
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label.PHP_EOL;}
$required=['seo_platform_booking_products'=>['product_id','website_id','user_id','confirmed_at'],'crm_products'=>['id','user_id','name','currency','is_active'],'bookings'=>['id','user_id','product_id','status','created_at'],'payment_transactions'=>['id','user_id','booking_id','amount','currency','status','gateway'],'refunds'=>['payment_transaction_id','user_id','amount','currency','status']];
$rows=[];foreach($required as $table=>$cols)foreach($cols as $col)$rows[]=['table_name'=>$table,'column_name'=>$col];
$db=new Database($rows);check((new SeoPlatformBookingService($db))->ready(),'lowercase database metadata remains compatible');
$db=new Database(array_map(static fn($r)=>array_change_key_case($r,CASE_UPPER),$rows));
check((new SeoPlatformBookingService($db))->ready(),'uppercase MySQL information_schema result keys do not falsely disable installed measurement');
$db=new Database(array_map(static fn($r)=>['TF_TABLE'=>$r['table_name'],'TF_COLUMN'=>$r['column_name']],$rows));
check((new SeoPlatformBookingService($db))->ready(),'explicit metadata aliases also work with uppercase PDO keys');
$db=new Database(array_map(static fn($r)=>['tf_table'=>$r['table_name'],'tf_column'=>$r['column_name']],$rows));
check((new SeoPlatformBookingService($db))->overview(30,33,'2026-10-18')['state']==='not_configured','a complete installed schema reaches product selection without inventing sales');
$incomplete=array_values(array_filter($rows,static fn($r)=>!($r['table_name']==='payment_transactions'&&$r['column_name']==='gateway')));
$db=new Database($incomplete);$s=new SeoPlatformBookingService($db);
check(!$s->ready(),'a genuinely missing payment column still prevents unsupported queries');
$result=$s->overview(30,33);
check($result===['available'=>false,'state'=>'unavailable','missing_requirements'=>['payment_transactions.gateway']],'owner-scoped unavailable response identifies the exact missing requirement without financial data');
$db=new Database([]);$result=(new SeoPlatformBookingService($db))->overview(30,33);
check(count($result['missing_requirements'])===26,'an empty schema reports every required column without creating tables');
$db->owned=false;$db->queries=[];$denied=false;try{(new SeoPlatformBookingService($db))->overview(31,33);}catch(InvalidArgumentException $e){$denied=true;}
check($denied&&count($db->queries)===1,'ownership is checked before exposing installation diagnostics');
foreach($db->queries as $sql)check(str_starts_with($sql,'SELECT '),'readiness cannot mutate schema or financial records');
echo $n.' assertions passed'.PHP_EOL;
