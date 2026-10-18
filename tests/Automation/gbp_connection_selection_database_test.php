<?php
/** Actual Model queries against the guarded disposable database; no OAuth/API requests. */
require __DIR__.'/fixtures/geo_database.php';
$pdo->exec('CREATE TABLE IF NOT EXISTS platform_connections(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,website_id INT,platform VARCHAR(60),status VARCHAR(30),access_token TEXT,token_expires_at DATETIME NULL)');
$n=0;
function check($ok,string $label): void {global $n;if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label.PHP_EOL;}
// Selection has no dependency on token refresh. Avoid constructing API clients for this read-only test.
$service=(new ReflectionClass(GbpSyncService::class))->newInstanceWithoutConstructor();
$pdo->beginTransaction();
try {
    $pdo->exec('DELETE FROM platform_connections WHERE website_id IN (1,2)');
    $insert=$pdo->prepare('INSERT INTO platform_connections(id,user_id,website_id,platform,status) VALUES(?,?,?,?,?)');
    foreach([[101,1,1,'google_business','error'],[102,1,1,'google_business','connected']] as $row)$insert->execute($row);
    check((int)$service->findConnection(1,1)?->getAttribute('id')===102,'a stale failed connection cannot hide a newer connected GBP record');
    $insert->execute([103,1,1,'google_business','connected']);
    check((int)$service->findConnection(1,1)?->getAttribute('id')===103,'the newest connected GBP record is selected deterministically');
    $insert->execute([104,1,1,'google_business','error']);
    $insert->execute([105,1,1,'google_business','disconnected']);
    check((int)$service->findConnection(1,1)?->getAttribute('id')===103,'a newer unsuccessful reconnect does not replace the working connection');
    $insert->execute([106,2,1,'google_business','connected']);
    $insert->execute([107,1,2,'google_business','connected']);
    $insert->execute([108,1,1,'facebook','connected']);
    check((int)$service->findConnection(1,1)?->getAttribute('id')===103,'selection excludes another user, another site and another platform');
    check($service->findConnection(2,2)===null,'no foreign website connection is returned to a different owner');
    $pdo->exec("UPDATE platform_connections SET status='error' WHERE user_id=1 AND website_id=1 AND platform='google_business' AND status='connected'");
    check((int)$service->findConnection(1,1)?->getAttribute('id')===105,'when no connection is active the newest saved state remains available for reconnect guidance');
    check($service->findConnection(999,1)===null,'a site without GBP records returns null');
    check((int)$pdo->query("SELECT COUNT(*) FROM platform_connections WHERE user_id=1 AND website_id=1 AND platform='google_business'")->fetchColumn()===5,'selection retains every historical connection record');
} finally {
    $pdo->rollBack();
}
echo $n.' assertions passed'.PHP_EOL;
