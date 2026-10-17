<?php
/** SQL-contract double, deliberately no PDO connection, SQLite or production bootstrap. */
class Database
{
    public array $sites=[41=>['id'=>41,'user_id'=>701,'main_url'=>'https://www.customer.example/trips']];
    public array $markets=[9=>['id'=>9,'website_id'=>41,'location_code'=>2818,'language_code'=>'ar','is_primary'=>1],10=>['id'=>10,'website_id'=>41,'location_code'=>2276,'language_code'=>'de','is_primary'=>0]];
    public array $cache=[],$research=[],$competitors=[],$competitorKeywords=[],$tracked=[],$history=[],$writes=[],$locks=[];
    public int $schemaChecks=0;
    public bool $lockAvailable=true;
    public function getConnection(): ResearchConnection {return new ResearchConnection($this);}
    public function query(string $sql,array $params=[]): array {
        if(str_starts_with($sql,'SELECT * FROM websites WHERE id=? AND user_id=?')){
            $s=$this->sites[$params[0]]??null;return $s&&$s['user_id']===$params[1]?[$s]:[];
        }
        if(str_starts_with($sql,'SELECT owner_user_id FROM users'))return [['owner_user_id'=>null]];
        if(str_starts_with($sql,'SELECT * FROM seo_markets WHERE website_id=? ORDER BY')){
            $r=array_values(array_filter($this->markets,fn($m)=>$m['website_id']===$params[0]));usort($r,fn($a,$b)=>$b['is_primary']<=>$a['is_primary']?:$a['id']<=>$b['id']);return $r;
        }
        if(str_starts_with($sql,'SELECT result_json FROM seo_research_cache')){
            if(!str_contains($sql,'market_id=? AND cache_key=? AND fetched_at>='))throw new RuntimeException('Unscoped cache read');
            $row=$this->cache[implode('|',$params)]??null;return $row&&strtotime($row['fetched_at'].' UTC')>=time()-30*86400?[$row]:[];
        }
        if(str_starts_with($sql,'SELECT id,competitor_domain FROM competitors'))return array_slice(array_values(array_filter($this->competitors,fn($c)=>$c['website_id']===$params[0]&&$c['user_id']===$params[1]&&$c['is_active']&&!$c['monitoring_paused'])),0,5);
        if(str_starts_with($sql,'SELECT id FROM competitor_keywords'))return array_slice(array_values(array_filter($this->competitorKeywords,fn($k)=>$k['competitor_id']===$params[0]&&$k['market_id']===$params[1]&&$this->fresh($k))),0,1);
        if(str_starts_with($sql,'SELECT DISTINCT k.keyword FROM competitor_keywords')){
            $rows=array_filter($this->competitorKeywords,fn($k)=>$this->ownedCompetitor($k,$params[0],$params[1])&&$k['market_id']===$params[2]&&$this->fresh($k)&&$k['position']>=1&&$k['position']<=10);
            $words=array_unique(array_column($rows,'keyword'));sort($words);return array_map(fn($k)=>['keyword'=>$k],array_slice($words,0,50));
        }
        if(str_starts_with($sql,'SELECT k.*,c.competitor_domain FROM competitor_keywords'))return array_values(array_map(fn($k)=>$k+['competitor_domain'=>$this->competitors[$k['competitor_id']]['competitor_domain']],array_filter($this->competitorKeywords,fn($k)=>$this->ownedCompetitor($k,$params[0],$params[1])&&$this->markets[$k['market_id']]['is_primary']&&$this->fresh($k))));
        if(str_starts_with($sql,'SELECT keyword,market_id,current_position,last_checked_at FROM tracked_keywords'))return array_values(array_filter($this->tracked,fn($k)=>$k['website_id']===$params[0]&&$k['user_id']===$params[1]));
        if(str_starts_with($sql,'SELECT id,keyword FROM tracked_keywords'))return array_values(array_filter($this->tracked,fn($k)=>$k['website_id']===$params[0]&&$k['user_id']===$params[1]&&$k['market_id']===$params[2]&&(!$k['last_checked_at']||strtotime($k['last_checked_at'].' UTC')<=time()-86400)&&(!str_contains($sql,'keyword IN (')||in_array($k['keyword'],array_slice($params,3),true))));
        throw new RuntimeException('Unexpected mock read: '.$sql);
    }
    private function fresh(array $k): bool {return $k['source']==='dataforseo'&&strtotime($k['fetched_at'].' UTC')>=time()-30*86400;}
    private function ownedCompetitor(array $k,int $wid,int $uid): bool {$c=$this->competitors[$k['competitor_id']]??null;return $c&&$c['website_id']===$wid&&$c['user_id']===$uid&&$c['is_active'];}
    public function exec(string $sql,array $p=[]): bool {
        $this->writes[]=[$sql,$p];
        if(str_starts_with($sql,'INSERT INTO seo_keyword_research')){$this->research[$p[0].'|'.$p[1].'|'.$p[2]]=array_combine(['website_id','market_id','keyword','volume','difficulty','cpc','intent','fetched_at'],$p);return true;}
        if(str_starts_with($sql,'REPLACE INTO seo_research_cache')){
            if(!preg_match('/\(website_id,market_id,cache_key,result_json,fetched_at\) VALUES \(\?,\?,\?,\?,UTC_TIMESTAMP\(\)\)/',$sql)||count($p)!==4)throw new RuntimeException('Wrong research cache schema');
            $this->cache[implode('|',array_slice($p,0,3))]=['result_json'=>$p[3],'fetched_at'=>gmdate('Y-m-d H:i:s')];return true;
        }
        if(str_starts_with($sql,'DELETE FROM competitor_keywords')){$this->competitorKeywords=array_values(array_filter($this->competitorKeywords,fn($k)=>!($k['competitor_id']===$p[0]&&$k['market_id']===$p[1]&&$k['source']==='dataforseo')));return true;}
        if(str_starts_with($sql,'INSERT INTO competitor_keywords')){$this->competitorKeywords[]=array_combine(['competitor_id','market_id','keyword','search_volume','difficulty','position','url'],$p)+['source'=>'dataforseo','fetched_at'=>gmdate('Y-m-d H:i:s')];return true;}
        if(str_starts_with($sql,'INSERT IGNORE INTO tracked_keywords')){
            foreach($this->tracked as $k)if([$k['website_id'],$k['user_id'],$k['market_id'],$k['keyword']]===$p)return true;
            $id=count($this->tracked)+1;$this->tracked[$id]=array_combine(['website_id','user_id','market_id','keyword'],$p)+['id'=>$id,'current_position'=>null,'last_checked_at'=>null];return true;
        }
        if(str_starts_with($sql,'INSERT INTO seo_rank_tracking_history')){$this->history[]=array_combine(['website_id','user_id','market_id','keyword','position','url','source','checked_at'],$p);return true;}
        if(str_starts_with($sql,'UPDATE tracked_keywords SET current_position=')){
            [$pos,$at,$id,$wid,$uid,$mid]=$p;$k=&$this->tracked[$id];if([$k['website_id'],$k['user_id'],$k['market_id']]!==[$wid,$uid,$mid])throw new RuntimeException('Rank update leaked tenancy');$k['current_position']=$pos;$k['last_checked_at']=$at;return true;
        }
        if(str_starts_with($sql,'UPDATE websites SET last_rank_tracked_at='))return true;
        throw new RuntimeException('Unexpected mock write: '.$sql);
    }
    public function transaction(callable $callback) {return $callback();}
}
class ResearchConnection
{
    public function __construct(private Database $db){}
    public function prepare(string $sql): ResearchStatement {return new ResearchStatement($this->db,$sql);}
}
class ResearchStatement
{
    public function __construct(private Database $db,private string $sql){}
    public function execute(array $params): bool {$this->db->locks[]=[$this->sql,$params];return true;}
    public function fetchColumn(): int {return $this->db->lockAvailable?1:0;}
}
// Market plan/schema dependencies have no bearing on research; retain production ownership/market logic.
class SeoDataSchema {public static function ensureSchema(Database $db): void {$db->schemaChecks++;}}
class Subscription {public static function activeSubscriptionRow(int $uid): array {return ['plan_name'=>'enterprise','features_json'=>'{"seo_markets":6}'];}}
