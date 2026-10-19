<?php
/** Real calendar/ownership/idempotency SQL; no real email is sent. */
require __DIR__.'/fixtures/geo_database.php';
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
$pdo->exec('DROP TABLE IF EXISTS seo_report_schedules');
$sql=file_get_contents($root.'/database/migrations/2026_08_29_000004_seo_multi_crawl_rank_tracking_reports.sql');$start=strpos($sql,'CREATE TABLE IF NOT EXISTS `seo_report_schedules`');$end=strpos($sql,';', $start);$pdo->exec(substr($sql,$start,$end-$start+1));
$s=new SeoScheduledReportService($db);$input=['frequency'=>'weekly','weekday'=>5,'hour'=>8,'recipient_email'=>'owner@example.test','is_active'=>1];
$r=$s->saveSchedule(1,1,$input);$friday=(int)$r['schedule']['id'];check($r['success']&&$friday>0,'actual schedule save returns an owned persisted row');
$again=$s->saveSchedule(1,1,$input);check((int)$again['schedule']['id']===$friday&&(int)$pdo->query('SELECT COUNT(*) FROM seo_report_schedules')->fetchColumn()===1,'same reviewed save does not duplicate the same recipient/time schedule');
$sat=$s->saveSchedule(1,1,array_replace($input,['weekday'=>6]));$saturday=(int)$sat['schedule']['id'];
$due=$s->dueSchedules(50,new DateTimeImmutable('2026-10-16T08:30:00Z'));check(array_map('intval',array_column($due,'id'))===[$friday],'Friday schedule runs on Friday only');
$pdo->exec("UPDATE seo_report_schedules SET last_sent_at='2026-10-16 08:30:00' WHERE id=".$friday);
$due=$s->dueSchedules(50,new DateTimeImmutable('2026-10-17T08:30:00Z'));check(array_map('intval',array_column($due,'id'))===[$saturday],'Saturday runs its own schedule and never repeats the Friday report');
check(!$s->dueSchedules(50,new DateTimeImmutable('2026-10-17T07:59:59Z')),'report is not due before its configured UTC hour');
check(!$s->dueSchedules(50,new DateTimeImmutable('2026-10-18T08:30:00Z')),'weekly reports do not run on Sunday unless selected');
check(!$s->saveSchedule(1,2,$input,$friday)['success']&&!$s->saveSchedule(2,1,$input)['success'],'foreign user/site cannot create or replace a schedule');
foreach([['weekday'=>''],['hour'=>'bad'],['weekday'=>7],['recipient_email'=>'bad'],['is_active'=>2]] as $bad)check(!$s->saveSchedule(1,1,array_replace($input,$bad))['success'],'invalid schedule rejected: '.array_key_first($bad));
$r=$s->saveSchedule(1,1,array_replace($input,['is_active'=>0]),$friday);check($r['success']&&$r['schedule']['last_sent_at']==='2026-10-16 08:30:00'&&!$r['schedule']['is_active'],'pausing keeps the last sent evidence');
$monthly=$s->saveSchedule(1,1,['frequency'=>'monthly','hour'=>8,'recipient_email'=>'monthly@example.test']);$id=(int)$monthly['schedule']['id'];
$pdo->exec("UPDATE seo_report_schedules SET last_sent_at='2026-10-01 08:00:00' WHERE id=".$id);$due=$s->dueSchedules(50,new DateTimeImmutable('2026-10-19T08:30:00Z'));check(!in_array($id,array_map('intval',array_column($due,'id')),true),'a monthly successful send is not repeated during the same month');
$due=$s->dueSchedules(50,new DateTimeImmutable('2026-11-01T08:30:00Z'));check(in_array($id,array_map('intval',array_column($due,'id')),true),'monthly report becomes due in the following UTC calendar month');
$pdo->exec("CREATE TRIGGER fixture_schedule_failure BEFORE UPDATE ON seo_report_schedules FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture failure'");
try{$r=$s->saveSchedule(1,1,array_replace($input,['recipient_email'=>'new@example.test']),$friday);check(!$r['success']&&$pdo->query('SELECT recipient_email FROM seo_report_schedules WHERE id='.$friday)->fetchColumn()===$input['recipient_email'],'failed SQL save reports failure and preserves the original recipient');}finally{$pdo->exec('DROP TRIGGER fixture_schedule_failure');}
check((int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM wallet_transactions')->fetchColumn()===1,'save and calendar checks send no email, create no job and make no wallet charge');
echo "$n assertions passed\n";
