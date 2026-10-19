<?php
/** Offline actual controller/provider regressions; mock Database, no SQLite or live services. */
ob_start();
require __DIR__.'/fixtures/dataforseo_research_database.php';
require __DIR__.'/../../app/Core/Contracts/KeywordRankingSourceInterface.php';
foreach (['DataForSeoClient','DataForSeoRankingSource','SeoMarketService','DataForSeoResearchService','RankTrackingService'] as $name) require __DIR__.'/../../app/Services/Seo/'.$name.'.php';
class Controller {
    protected array $user=['id'=>701];
    public bool $csrf=true,$authenticated=true;
    public function __construct(public array $input,protected Database $db){}
    protected function get($key,$default=null){return $this->input[$key]??$default;}
    protected function isAuthenticated(){return $this->authenticated;}
    protected function verifyCsrf(){return $this->csrf?null:$this->error('CSRF',419);}
    protected function success($data){return ['success'=>true,'data'=>$data];}
    protected function error($error,$code){return ['success'=>false,'error'=>$error,'code'=>$code];}
}
require __DIR__.'/../../app/Controllers/SeoDataController.php';
class RepairDatabase extends Database {
    public function getConnection(): ResearchConnection {return new RepairConnection($this);}
    public array $row=['id'=>13,'website_id'=>41,'user_id'=>701,'market_id'=>9,'keyword'=>'Luxor tours (Transactional, High)','search_volume'=>null,'current_position'=>null];
    public bool $rankHistory=false,$researchHistory=false,$duplicate=false;
    public array $reads=[];
    public function query(string $sql,array $params=[]): array {
        $this->reads[]=[$sql,$params];
        if(str_starts_with($sql,'SELECT id FROM websites'))return $params===[41,701]?[['id'=>41]]:[];
        if(str_starts_with($sql,'SELECT * FROM tracked_keywords'))return $params===[$this->row['id'],41,701,9]?[$this->row]:[];
        if(str_starts_with($sql,'SELECT id FROM seo_rank_tracking_history'))return $this->rankHistory?[['id'=>1]]:[];
        if(str_starts_with($sql,'SELECT id FROM seo_keyword_research'))return $this->researchHistory?[['id'=>2]]:[];
        if(str_starts_with($sql,'SELECT id FROM tracked_keywords'))return $this->duplicate?[['id'=>14]]:[];
        return parent::query($sql,$params);
    }
    public function exec(string $sql,array $params=[]): bool {
        if(str_starts_with($sql,'UPDATE tracked_keywords SET keyword=')){$this->writes[]=[$sql,$params];$this->row['keyword']=$params[0];return true;}
        return parent::exec($sql,$params);
    }
}
class RepairConnection extends ResearchConnection {
    public function __construct(private RepairDatabase $repairDb){parent::__construct($repairDb);}
    public function prepare(string $sql): ResearchStatement{return new RepairStatement($this->repairDb,$sql);}
}
class RepairStatement extends ResearchStatement {
    private array $result=[];
    public function __construct(private RepairDatabase $repairDb,private string $repairSql){parent::__construct($repairDb,$repairSql);}
    public function execute(array $params):bool{$this->result=$this->repairDb->query($this->repairSql,$params);return true;}
    public function fetchAll(int $mode):array{return $this->result;}
}
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";$n++;}
$cases=[['Luxor tours (Transactional, High)','Luxor tours'],['Tours (Informational/Commercial, Medium)','Tours'],['رحلات الأقصر (تجارية، عالية)','رحلات الأقصر'],[' Tours (commercial, low) ','Tours'],['Tours (private guide)','Tours (private guide)'],['Tours (High)','Tours (High)'],['Tours (Cairo, Egypt)','Tours (Cairo, Egypt)'],['Tours (Commercial, High) tickets','Tours (Commercial, High) tickets']];
foreach($cases as [$raw,$expected])check(DataForSeoRankingSource::keywordPhrase($raw)===$expected,'only recognized trailing annotations removed: '.$raw);
$db=new RepairDatabase();$input=['website_id'=>41,'market_id'=>9,'expected_keyword'=>$db->row['keyword'],'reviewed'=>true];
$r=(new SeoDataController($input,$db))->repairKeyword(['id'=>13]);
check($r['success']&&$db->row['keyword']==='Luxor tours'&&$r['data']['previous_keyword']===$input['expected_keyword'],'explicit reviewed correction keeps original in response and exact phrase');
check($db->writes[0][1]===['Luxor tours',13,41,701,9]&&count($db->writes)===1,'only the owned unmeasured row keyword changes, with no delete or quota write');
foreach(['last_checked_at'=>'2026-10-01','enriched_at'=>'2026-10-01','gsc_checked_at'=>'2026-10-01','current_position'=>0,'search_volume'=>0,'difficulty'=>0,'impressions'=>0,'gsc_position'=>0] as $field=>$value){$db=new RepairDatabase();$db->row[$field]=$value;$r=(new SeoDataController($input,$db))->repairKeyword(['id'=>13]);check(!$r['success']&&!$db->writes,'any stored measurement including zero prevents rename: '.$field);}
foreach(['rankHistory','researchHistory','duplicate'] as $field){$db=new RepairDatabase();$db->$field=true;$r=(new SeoDataController($input,$db))->repairKeyword(['id'=>13]);check(!$r['success']&&!$db->writes,'existing history or canonical duplicate retained: '.$field);}
foreach([['website_id'=>99],['market_id'=>999],['reviewed'=>false],['expected_keyword'=>'stale']] as $change){$db=new RepairDatabase();$r=(new SeoDataController(array_replace($input,$change),$db))->repairKeyword(['id'=>13]);check(!$r['success']&&!$db->writes,'foreign, unreviewed or stale request has no writes: '.array_key_first($change));}
$db=new RepairDatabase();$c=new SeoDataController($input,$db);$c->csrf=false;$r=$c->repairKeyword(['id'=>13]);check(!$r['success']&&$r['code']===419&&!$db->reads&&!$db->writes,'invalid CSRF rejected before reading or writing');
$c=new SeoDataController($input,$db);$c->authenticated=false;$r=$c->repairKeyword(['id'=>13]);check(!$r['success']&&$r['code']===401&&!$db->writes,'anonymous correction rejected');
$db=new RepairDatabase();$r=(new SeoDataController(['website_id'=>41,'market_id'=>9,'keyword'=>'Tours (Commercial, High)'],$db))->addKeyword();check(!$r['success']&&!$db->writes,'new tracked input rejects analysis labels instead of silently saving them');
$settings=['enabled'=>'1','login'=>'offline','password'=>'fixture','monthly_budget_usd'=>10,'location_code'=>2818,'language_code'=>'ar'];
$ledger=new class{public int $calls=0;public function reserve(...$args){$this->calls++;return 1;}public function settle(...$args){}};
$client=new DataForSeoClient($settings,fn()=>throw new RuntimeException('No transport expected'),$ledger);
$source=new DataForSeoRankingSource($settings,null,$client,41);
$r=$source->check('customer.example',['Tours','Tours (Commercial, High)']);check(!$r['available']&&$r['reason']==='keyword_annotations_require_review'&&$ledger->calls===0,'ranking validates the whole batch before any quota reservation');
$words=array_merge(array_map(fn($i)=>'tour '.$i,range(1,20)),['Tours (Commercial, High)']);
$r=RankTrackingService::batches($source,'customer.example',$words);check(!$r['available']&&$ledger->calls===0,'invalid later chunk cannot spend an earlier chunk first');
try{(new DataForSeoResearchService(new Database(),$client))->research(41,701,9,['Tours (Commercial, High)'],true);$rejected=false;}catch(InvalidArgumentException $e){$rejected=true;}
check($rejected&&$ledger->calls===0,'research blocks contaminated seeds before quota, cache writes or provider requests');
echo "$n assertions passed\n";
