<?php
/** Real service SQL against the marker-guarded disposable database. No external calls. */
require __DIR__.'/fixtures/geo_database.php';
require_once $root.'/app/Services/SeoImpact/SeoPlatformProductService.php';
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label.PHP_EOL;}
function denied($fn){try{$fn();return false;}catch(InvalidArgumentException $e){return true;}}
foreach(['refunds','payment_transactions','bookings','crm_products'] as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
$catalogMigration=file_get_contents($root.'/database/migrations/2026_08_08_000009_create_crm_products_and_deal_items_tables.sql');
$pdo->exec(explode('CREATE TABLE IF NOT EXISTS `crm_deal_items`',$catalogMigration)[0]);
$pdo->exec('ALTER TABLE crm_products ADD COLUMN website_id INT NULL,ADD COLUMN tour_slug VARCHAR(255) NULL');
$pdo->exec('CREATE TABLE bookings(id INT PRIMARY KEY,user_id INT,product_id INT,status VARCHAR(30),created_at DATETIME)');
$pdo->exec('CREATE TABLE payment_transactions(id INT PRIMARY KEY,user_id INT,booking_id INT,amount DECIMAL(12,2),currency VARCHAR(10),status VARCHAR(30),gateway VARCHAR(40))');
$pdo->exec('CREATE TABLE refunds(id INT PRIMARY KEY,payment_transaction_id INT,user_id INT,amount DECIMAL(12,2),currency VARCHAR(10),status VARCHAR(30))');
$pdo->exec(file_get_contents($root.'/database/migrations/2026_10_18_000001_seo_platform_booking_products.sql'));
$pdo->exec('DELETE FROM seo_platform_booking_products');
$pdo->exec("INSERT INTO websites(id,user_id,main_url,company_name) VALUES(3,1,'https://second.example','Second'); INSERT INTO crm_products(id,user_id,name,sku,price,currency,website_id) VALUES(10,1,'Existing product','original',17.00,'EUR',123),(11,2,'Other account product','foreign',999.00,'USD',NULL); INSERT INTO seo_platform_booking_products VALUES(10,1,1,UTC_TIMESTAMP()); INSERT INTO bookings VALUES(1,1,10,'confirmed',UTC_TIMESTAMP()); INSERT INTO payment_transactions VALUES(1,1,1,17.00,'EUR','succeeded','stripe');");
$s=new SeoPlatformProductService($db,static fn()=>'<h1>Read-only import</h1>');
$input=['confirmed'=>true,'name'=>'Confirmed Nile tour','price'=>'255.50','currency'=>'USD','description'=>'Two confirmed days','request_key'=>str_repeat('a',32),'source_url'=>''];
try {
check(denied(fn()=>$s->create(2,1,$input)),'another account cannot create a product for this site');
check(denied(fn()=>$s->create(1,1,array_replace($input,['confirmed'=>false]))),'unreviewed facts cannot be saved');
foreach([['price'=>''],['price'=>'1.234'],['currency'=>'$'],['name'=>''],['request_key'=>'invalid'],['source_url'=>'https://other.test/tour']] as $bad)check(denied(fn()=>$s->create(1,1,array_replace($input,$bad))),'invalid facts or foreign source rejected before product creation: '.json_encode($bad));
check((int)$pdo->query('SELECT COUNT(*) FROM crm_products')->fetchColumn()===2,'all rejected actions preserve the original catalog');
$r=$s->create(1,1,$input+['user_id'=>2,'website_id'=>2]);$id=$r['product']['id'];
$row=$pdo->query('SELECT * FROM crm_products WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
check($r['created']&&$row['user_id']===1&&$row['price']==='255.50'&&$row['currency']==='USD'&&$row['name']==='Confirmed Nile tour','reviewed manual creation stores exact owned catalog facts without FX conversion');
check($row['website_id']===null&&$row['tour_slug']===null,'SEO mapping never populates Builder website foreign keys or invents a tour slug');
check((int)$pdo->query('SELECT COUNT(*) FROM seo_platform_booking_products WHERE website_id=1 AND user_id=1')->fetchColumn()===2,'new product is mapped additively without replacing the prior selection');
$retry=$s->create(1,1,$input);check(!$retry['created']&&$retry['product']['id']===$id&&(int)$pdo->query('SELECT COUNT(*) FROM crm_products')->fetchColumn()===3,'a repeated manual save has one catalog product and one mapping');
$changed=$s->create(1,1,array_replace($input,['price'=>'999','name'=>'Changed retry']));
check(!$changed['created']&&$changed['product']['name']==='Confirmed Nile tour'&&$changed['product']['price']==='255.50','a retry with edited fields returns existing facts without silently overwriting the product');
$import=array_replace($input,['source_url'=>'https://example.com/tour/luxor?utm_source=test','request_key'=>str_repeat('b',32)]);$r=$s->create(1,1,$import);
$again=$s->create(1,1,array_replace($import,['source_url'=>'https://example.com/tour/luxor','request_key'=>str_repeat('c',32)]));
check(!$again['created']&&$again['product']['id']===$r['product']['id'],'reimporting a tour page with another request key or tracking tag never duplicates it');
$alias=$s->create(1,1,array_replace($import,['source_url'=>'https://www.example.com/tour/luxor']));
check(!$alias['created']&&$alias['product']['id']===$r['product']['id'],'the allowed www host alias shares the same imported product identity');
$pdo->exec('UPDATE seo_platform_booking_products SET website_id=3 WHERE product_id='.$r['product']['id']);
check(denied(fn()=>$s->create(1,1,$import)),'an existing imported product moved to another site is not silently reassigned');
$catalogCount=(int)$pdo->query('SELECT COUNT(*) FROM crm_products')->fetchColumn();$mapCount=(int)$pdo->query('SELECT COUNT(*) FROM seo_platform_booking_products')->fetchColumn();
$pdo->exec("CREATE TRIGGER fixture_reject_product_map BEFORE INSERT ON seo_platform_booking_products FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture mapping failure'");
$failed=false;try{$s->create(1,1,array_replace($input,['request_key'=>str_repeat('d',32)]));}catch(Throwable $e){$failed=true;}finally{$pdo->exec('DROP TRIGGER fixture_reject_product_map');}
check($failed&&(int)$pdo->query('SELECT COUNT(*) FROM crm_products')->fetchColumn()===$catalogCount&&(int)$pdo->query('SELECT COUNT(*) FROM seo_platform_booking_products')->fetchColumn()===$mapCount,'a mapping failure rolls back the catalog insertion and preserves existing mappings');
$before=(int)$pdo->query('SELECT COUNT(*) FROM crm_products')->fetchColumn();$preview=$s->preview(1,1,'https://example.com/tour');
check($preview['draft']['name']==='Read-only import'&&$preview['draft']['price']===''&&(int)$pdo->query('SELECT COUNT(*) FROM crm_products')->fetchColumn()===$before,'actual-service preview writes no draft/product or fake default price');
check((int)$pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn()===1&&(int)$pdo->query('SELECT COUNT(*) FROM payment_transactions')->fetchColumn()===1&&(int)$pdo->query('SELECT COUNT(*) FROM refunds')->fetchColumn()===0,'catalog creation never creates, changes or duplicates bookings, payments or refunds');
check((int)$pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn()===0&&(int)$pdo->query('SELECT COUNT(*) FROM wallet_transactions')->fetchColumn()===1,'catalog operations create no background job or wallet charge');
check($pdo->query('SELECT name FROM crm_products WHERE id=11')->fetchColumn()==='Other account product','the foreign customer catalog remains unchanged');
$pdo->beginTransaction();
for($i=0;$i<499;$i++){$pdo->exec("INSERT INTO crm_products(user_id,name,price,currency) VALUES(1,'Existing capacity fixture',1,'USD')");$productId=(int)$pdo->lastInsertId();$pdo->exec('INSERT INTO seo_platform_booking_products VALUES('.$productId.',3,1,UTC_TIMESTAMP())');}
$pdo->commit();$fullCount=(int)$pdo->query('SELECT COUNT(*) FROM crm_products')->fetchColumn();
check(denied(fn()=>$s->create(1,3,array_replace($input,['request_key'=>str_repeat('e',32)])))&&(int)$pdo->query('SELECT COUNT(*) FROM crm_products')->fetchColumn()===$fullCount,'the existing 500-product measurement limit rejects an addition atomically without an orphan catalog product');
} finally {
    $pdo->exec('DELETE FROM seo_platform_booking_products');
    // The real catalog schema has a users FK; remove our fixture tables so the
    // shared guarded fixture can recreate users on a later suite/repeated run.
    foreach(['refunds','payment_transactions','bookings','crm_products'] as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
}
echo $n.' assertions passed'.PHP_EOL;
