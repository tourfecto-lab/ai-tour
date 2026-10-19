<?php
/** Guarded disposable MariaDB; actual controller, market service and SQL, no providers. */
ob_start();
require __DIR__.'/fixtures/geo_database.php';
class KeywordReviewController extends SeoDataController {
    public function __construct(Database $db,array $data,int $uid=1){$this->db=$db;$this->data=$data;$this->authenticated=true;$this->user=['id'=>$uid];}
    protected function verifyCsrf():?array{return null;}
}
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
SeoDataSchema::ensureSchema($db);
$market=(new SeoMarketService($db))->resolve(1,1);$mid=(int)$market['id'];
$raw='Luxor tours (Transactional, High)';
$insert=$pdo->prepare('INSERT INTO tracked_keywords(website_id,user_id,market_id,keyword,target_page,priority) VALUES(1,1,?,?,?,?)');
$insert->execute([$mid,$raw,'https://example.com/tours/luxor','high']);$id=(int)$pdo->lastInsertId();
$input=['website_id'=>1,'market_id'=>$mid,'expected_keyword'=>$raw,'reviewed'=>true];
$act=fn($change=[],$uid=1)=>(new KeywordReviewController($db,array_replace($input,$change),$uid))->repairKeyword(['id'=>$id]);
$read=fn()=>$pdo->query('SELECT * FROM tracked_keywords WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
$before=$read();$r=$act();$after=$read();$expected=$before;$expected['keyword']='Luxor tours';
check($r['success']&&$after===$expected,'actual SQL changes only the phrase, retaining row ID, market and target mapping');
check(!$act()['success']&&$read()===$after,'stale replay never overwrites the reviewed phrase');
$pdo->prepare('UPDATE tracked_keywords SET keyword=? WHERE id=?')->execute([$raw,$id]);
check(!$act([],2)['success']&&$read()['keyword']===$raw,'actual SQL rejects another customer before writes');
$pdo->exec('UPDATE tracked_keywords SET search_volume=0 WHERE id='.$id);
check(!$act()['success']&&$read()['search_volume']===0,'measured zero remains measured and cannot be overwritten');
$pdo->exec('UPDATE tracked_keywords SET search_volume=NULL WHERE id='.$id);
$history=$pdo->prepare("INSERT INTO seo_rank_tracking_history(website_id,user_id,market_id,keyword,position,source,checked_at) VALUES(1,1,?,?,NULL,'fixture',UTC_TIMESTAMP())");$history->execute([$mid,$raw]);
$oldHistory=$pdo->query('SELECT * FROM seo_rank_tracking_history')->fetchAll(PDO::FETCH_ASSOC);
check(!$act()['success']&&$pdo->query('SELECT * FROM seo_rank_tracking_history')->fetchAll(PDO::FETCH_ASSOC)===$oldHistory,'even measured absence blocks rename and original ranking history stays intact');
$pdo->exec('DELETE FROM seo_rank_tracking_history');
$pdo->prepare("INSERT INTO seo_keyword_research(website_id,market_id,keyword,volume,source,fetched_at) VALUES(1,?,?,0,'dataforseo',UTC_TIMESTAMP())")->execute([$mid,$raw]);
check(!$act()['success']&&$read()['keyword']===$raw,'research provenance alone blocks a rename even when tracked fields are null');
$pdo->exec('DELETE FROM seo_keyword_research');
$insert->execute([$mid,'Luxor tours','https://example.com/other','low']);
$all=$pdo->query('SELECT * FROM tracked_keywords ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
check(!$act()['success']&&$pdo->query('SELECT * FROM tracked_keywords ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$all,'canonical collision never deletes or merges either keyword');
$pdo->exec('DELETE FROM tracked_keywords WHERE id<>'.$id);
$pdo->exec('ALTER TABLE seo_rank_tracking_history RENAME COLUMN keyword TO unavailable_keyword');
try{$r=$act();check(!$r['success']&&$read()['keyword']===$raw&&!$pdo->inTransaction(),'failed history read cannot be mistaken for an empty history');}
finally{$pdo->exec('ALTER TABLE seo_rank_tracking_history RENAME COLUMN unavailable_keyword TO keyword');}
$pdo->exec("CREATE TRIGGER fixture_keyword_repair_failure BEFORE UPDATE ON tracked_keywords FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture failure'");
$failed=false;try{$failed=!$act()['success'];}catch(Throwable $e){$failed=true;}finally{$pdo->exec('DROP TRIGGER fixture_keyword_repair_failure');}
check($failed&&$read()['keyword']===$raw&&!$pdo->inTransaction(),'SQL failure rolls back cleanly and retains original phrase');
check((int)$pdo->query('SELECT COUNT(*) FROM dataforseo_calls')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM wallet_transactions')->fetchColumn()===1,'review never buys research, queues work or charges the wallet');
echo "$n assertions passed\n";
