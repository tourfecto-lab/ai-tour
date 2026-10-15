<?php
/**
 * بيبني public_html/assets/i18n/ar-dict.js من نفس قاموس الترجمة العكسية
 * (auto_en.php معكوس + auto_ar.php). شغّله بعد أي تعديل في القواميس:
 *   php tools/build_ar_dict.php
 */
require __DIR__ . '/../app/Helpers/ui_autotranslate.php';
$dict = tf_autotranslate_ar_dict();
ksort($dict);
$dir = __DIR__ . '/../public_html/assets/i18n';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}
$json = json_encode($dict, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
file_put_contents($dir . '/ar-dict.js', "window.TF_AR_DICT=" . $json . ";\n");
echo count($dict) . " entries\n";
