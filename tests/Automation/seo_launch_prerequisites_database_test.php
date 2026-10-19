<?php
/** Additive migration remains safe when old tables/prices and data already exist. */
require __DIR__.'/fixtures/geo_database.php';
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
SeoDataSchema::ensureSchema($db);$pdo->exec("UPDATE pay_per_use_pricing SET price=0.77,is_active=0 WHERE feature_key='seo_data'");$before=$pdo->query("SELECT * FROM pay_per_use_pricing WHERE feature_key='seo_data'")->fetch(PDO::FETCH_ASSOC);
$sql=file_get_contents($root.'/database/migrations/2026_10_19_000001_seo_launch_prerequisites.sql');$pdo->exec($sql);$pdo->exec($sql);
check($pdo->query("SELECT * FROM pay_per_use_pricing WHERE feature_key='seo_data'")->fetch(PDO::FETCH_ASSOC)===$before,'reapplying launch prerequisites preserves configured prices and disabled overage switches');
check((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('billing_usage_reservations','service_usage_reservations','external_execution_attempts','seo_report_schedules')")->fetchColumn()===4,'existing reservation and report prerequisites install idempotently');
check((int)$pdo->query('SELECT COUNT(*) FROM wallet_transactions')->fetchColumn()===1&&(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()===3,'migration never charges wallets, changes accounts or imports a replacement database');
echo "$n assertions passed\n";
