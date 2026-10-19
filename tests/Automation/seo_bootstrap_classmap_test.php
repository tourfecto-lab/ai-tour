<?php
/** Exercise the real cron entry point against an intentionally stale generated Composer map. */
if(getenv('TF_GEO_ALLOW_FIXTURES')!=='1'||getenv('DB_NAME')!=='tourfecto_phase4_billing')exit(2);
require __DIR__.'/../../cron/bootstrap.php';
$n=0;function check($ok,$label){global $n;if(!$ok)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
foreach(['PlanUsage','SubscriptionValidator','WalletService','SeoDataLedger'] as $name){$path=(new ReflectionClass($name))->getFileName();$expected=ROOT_PATH.'/app/Services/'.($name==='SeoDataLedger'?'Seo/':'Subscription/').$name.'.php';check(realpath($path)===realpath($expected),'actual cron bootstrap loads the release implementation: '.$name);}
$features=(new ReflectionClass('PlanUsage'))->getConstant('FEATURES');check(isset($features['seo_data'],$features['ai_visibility'],$features['marketing_tool']),'loaded quota code recognizes SEO data, AI visibility and marketing cycle allowances');
echo "$n assertions passed\n";
