<?php
/**
 * الترجمة العكسية (إنجليزي => عربي) للواجهة العربية: بتترجم نصوص الواجهة
 * الكاملة بس، ومش بتلمس بيانات العميل أو الكود أو القيم اللي بتتبعت.
 * php tests/Unit/ArabicAutotranslateTest.php
 */
require_once __DIR__ . '/../../app/Helpers/ui_autotranslate.php';
$fails = 0; $n = 0;
function check($c, $m) { global $fails, $n; $n++; echo ($c ? '  ok  ' : '  FAIL ') . $m . "\n"; if (!$c) $fails++; }

$html = '<h2>Email marketing</h2><p>to</p><select><option>Hotel</option><option value="x">All tags</option></select>'
    . '<input placeholder="Search by name, phone or email..." value="Settings"><textarea>Refresh</textarea>'
    . '<b>Luxury Egypt Tours</b><code>Callback URL</code><p>open</p>'
    . "<script>if(s==='Pending'){x='Choose a conversation from the list to view it here'}</script><p>📅 Content calendar</p>";
$out = tf_autotranslate_ar_buffer($html);

check(strpos($out, '<h2>تسويق البريد</h2>') !== false, 'heading translated');
check(strpos($out, '<p>to</p>') !== false && strpos($out, '<p>open</p>') !== false, 'short / lowercase values untouched');
check(strpos($out, '<option value="Hotel">فندق</option>') !== false, 'option without value keeps English value');
check(strpos($out, '<option value="x">كل الوسوم</option>') !== false, 'option with value: single value attribute');
check(strpos($out, 'placeholder="ابحث') !== false, 'placeholder translated');
check(strpos($out, 'value="Settings"') !== false && strpos($out, '<textarea>Refresh</textarea>') !== false, 'field values untouched');
check(strpos($out, '<b>Luxury Egypt Tours</b>') !== false, 'customer content untouched');
check(strpos($out, '<code>Callback URL</code>') !== false, 'code untouched');
check(strpos($out, "s==='Pending'") !== false && strpos($out, 'Choose a conversation') !== false, 'JavaScript untouched (translated in the browser)');
check(strpos($out, '📅 تقويم المحتوى') !== false, 'leading symbol kept');
check(tf_autotranslate_ar_lookup('Send') === null || tf_autotranslate_ar_lookup('Send') !== '', 'lookup returns string or null');

$json = tf_autotranslate_ar_buffer('{"success":false,"error":"Loading..."}');
check(strpos($json, 'جاري التحميل') !== false, 'API error message translated');

echo "\n" . ($n - $fails) . "/$n passed\n";
exit($fails ? 1 : 0);
