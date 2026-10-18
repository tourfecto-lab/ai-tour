<?php
/** Native payment cohorts on the guarded disposable database. No real booking, gateway or email. */
require __DIR__.'/fixtures/geo_database.php';
require_once $root.'/app/Services/SeoImpact/SeoImpactRules.php';
require_once $root.'/app/Services/SeoImpact/SeoPlatformBookingService.php';
$n=0;function check($ok,$label):void{global $n;if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label.PHP_EOL;}
function denied($fn):bool{try{$fn();return false;}catch(InvalidArgumentException $e){return true;}}
// These are test-only tables in the marker-protected DB; never operate on production.
$pdo->exec('DROP TABLE IF EXISTS refunds');$pdo->exec('DROP TABLE IF EXISTS payment_transactions');$pdo->exec('DROP TABLE IF EXISTS bookings');$pdo->exec('DROP TABLE IF EXISTS crm_products');
$pdo->exec('CREATE TABLE crm_products(id INT PRIMARY KEY,user_id INT,name VARCHAR(255),currency VARCHAR(10),is_active INT,website_id INT NULL)');
$pdo->exec('CREATE TABLE bookings(id INT PRIMARY KEY,user_id INT,product_id INT,status VARCHAR(30),created_at DATETIME)');
$pdo->exec('CREATE TABLE payment_transactions(id INT PRIMARY KEY,user_id INT,booking_id INT,amount DECIMAL(12,2),currency VARCHAR(10),status VARCHAR(30),gateway VARCHAR(40))');
$pdo->exec('CREATE TABLE refunds(id INT PRIMARY KEY,payment_transaction_id INT,user_id INT,amount DECIMAL(12,2),currency VARCHAR(10),status VARCHAR(30))');
$pdo->exec(file_get_contents($root.'/database/migrations/2026_10_18_000001_seo_platform_booking_products.sql'));
$pdo->exec('DELETE FROM seo_platform_booking_products');
try {
$pdo->exec("INSERT INTO websites(id,user_id,main_url,company_name) VALUES(3,1,'https://second.example','Second')");
$pdo->exec("INSERT INTO crm_products VALUES(10,1,'Nile tour','USD',1,999),(11,1,'Red Sea tour','EUR',1,999),(12,2,'Foreign tour','USD',1,1),(13,1,'Another site tour','USD',0,1)");
$s=new SeoPlatformBookingService($db);
check($s->ready(),'additive mapping schema and native ledger prerequisites are detected');
check($s->overview(1,1)['state']==='not_configured','a numeric Builder website id never silently assigns a product to an SEO website');
check(denied(fn()=>$s->configure(1,1,[10],false)),'product-to-site assignment requires explicit factual review');
check(denied(fn()=>$s->configure(2,1,[12],true)),'another account cannot configure this site');
check(denied(fn()=>$s->configure(1,1,[12],true)),'a forged foreign product cannot be assigned');
$s->configure(1,1,[10,11],true);$s->configure(1,1,[11,10,10],true);
check((int)$pdo->query('SELECT COUNT(*) FROM seo_platform_booking_products')->fetchColumn()===2,'repeating a reviewed selection creates no duplicate assignments');
check(denied(fn()=>$s->configure(1,3,[10],true)),'one product cannot double-count revenue across two sites');
check(denied(fn()=>$s->configure(1,1,[10,12],true))&&(int)$pdo->query('SELECT COUNT(*) FROM seo_platform_booking_products WHERE website_id=1')->fetchColumn()===2,'a failed selection preserves the previous reviewed mapping atomically');
$list=$s->products(1,1);check(count($list['products'])===3&&count($list['selected'])===2&&!in_array(12,array_column($list['products'],'id'),true),'product choices expose only owned records and restore the saved selection');
check($s->products(1,3)['products'][0]['bound_elsewhere'],'the other site sees that the product already has a measurement assignment');
$pdo->exec("INSERT INTO bookings VALUES(1,1,10,'confirmed','2026-10-05 12:00:00'),(2,1,10,'confirmed','2026-10-06'),(3,1,11,'cancelled','2026-10-07'),(4,2,12,'confirmed','2026-10-08'),(5,1,13,'confirmed','2026-10-08'),(6,1,10,'confirmed','2026-09-01'),(7,1,10,'confirmed','2026-10-18'),(8,1,10,'confirmed','2026-10-09')");
$pdo->exec("INSERT INTO payment_transactions VALUES(1,1,1,100,'USD','succeeded','stripe'),(2,1,1,50,'USD','partially_refunded','paymob'),(3,1,1,999,'USD','failed','stripe'),(4,1,2,200,'USD','pending','stripe'),(5,1,3,80,'EUR','refunded','stripe_operator'),(6,2,4,999,'USD','succeeded','stripe'),(7,1,5,999,'USD','succeeded','stripe'),(8,1,6,40,'USD','succeeded','wallet'),(9,1,7,90,'USD','succeeded','stripe'),(10,2,1,999,'USD','succeeded','stripe'),(11,1,1,999,'USD','succeeded','manual'),(12,1,NULL,999,'USD','succeeded','stripe'),(13,1,8,20,'USD','refunded','stripe')");
$pdo->exec("INSERT INTO refunds VALUES(1,2,1,10,'USD','succeeded'),(2,2,1,5,'USD','succeeded'),(3,2,1,4,'USD','processing'),(4,5,1,80,'EUR','succeeded'),(5,1,2,50,'USD','succeeded')");
$r=$s->overview(1,1,'2026-10-18');$c=array_column($r['current']['currencies'],null,'currency');
check($r['periods']['current']===['start'=>'2026-09-20','end'=>'2026-10-17']&&$r['periods']['previous']===['start'=>'2026-08-23','end'=>'2026-09-19'],'booking cohorts use equal complete UTC windows and exclude today');
check($r['current']['paid_bookings']===2,'multiple captured payments count one booking and manually confirmed unpaid bookings count none');
check($c['USD']['gross_captured']==='150.00'&&$c['USD']['succeeded_refunds']==='15.00'&&$c['USD']['net_captured']==='135.00','multiple refunds are aggregated per payment without multiplying captured amounts');
check($c['USD']['pending_refunds']==='4.00','processing refunds are visible but are not treated as money already returned');
check($c['EUR']['net_captured']==='0.00'&&$r['current']['cancelled_bookings_with_payments']===1,'a genuine full refund yields measured zero and cancelled bookings remain visible');
check($r['current']['excluded_inconsistent_payments']===1&&$r['current']['state']==='needs_review','refunded status without matching refund evidence is excluded and flagged rather than inventing a refund amount');
check($r['previous']['paid_bookings']===1&&$r['previous']['currencies'][0]['net_captured']==='40.00','the previous cohort uses its own real captured payments');
check($r['traffic_channel']==='not_inferred'&&$r['page_attribution']==='not_inferred'&&$r['cohort_basis']==='booking_created_at_utc','product sales are never mislabeled as organic landing-page attribution or payment-date cash flow');
check($s->overview(1,1,'2026-10-18')===$r,'read-only reload cannot duplicate receipts or change totals');
$pdo->exec("UPDATE refunds SET status='succeeded' WHERE id=3");$r=$s->overview(1,1,'2026-10-18');$c=array_column($r['current']['currencies'],null,'currency');
check($c['USD']['net_captured']==='131.00'&&$c['USD']['pending_refunds']==='0.00','a newly succeeded refund changes the current net value without any new payment event');
$pdo->exec("UPDATE bookings SET status='cancelled' WHERE id=1");$r=$s->overview(1,1,'2026-10-18');$c=array_column($r['current']['currencies'],null,'currency');
check($c['USD']['net_captured']==='131.00'&&$r['current']['cancelled_bookings_with_payments']===2,'cancellation alone never pretends that captured money has been refunded');
$s->configure(1,1,[],true);check($s->overview(1,1)['state']==='not_configured'&&(int)$pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn()===8&&(int)$pdo->query('SELECT COUNT(*) FROM payment_transactions')->fetchColumn()===13,'unassigning measurement preserves every booking and financial record');
check((int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM wallet_transactions')->fetchColumn()===1,'measurement reads and selection changes trigger no payment, queue, email or billing activity');
} finally {$pdo->exec('DELETE FROM seo_platform_booking_products');}
echo $n.' assertions passed'.PHP_EOL;
