<?php

/**
 * Tourfecto - Ad PII Hasher (Privacy by Design)
 * تحويل البيانات الشخصية للعميل لمجرد hashes SHA-256 قبل إرسالها لأي
 * منصة إعلانية (Meta CAPI / Google Enhanced Conversions). أي معرّف شخصي
 * خام مبيتخزنش ومبيتسابش - دي هي القاعدة الإلزامية في مسار الإسناد ده:
 *   - الإيميل: lowercase + trim ثم hash.
 *   - الهاتف: أرقام (مع كود الدولة) فقط ثم hash.
 * لو القيمة مش قابلة للتطبيع (إيميل غير صالح/فاضي، هاتف فاضي) بترجع null
 * ويعني إحنا مش هنبعت الحقل ده خالص.
 * @version 1.0.0  @date 2026-08-28
 */
class AdPiiHasher
{
    public static function hashEmail(?string $email): ?string
    {
        if (!is_string($email)) {
            return null;
        }
        $normalized = mb_strtolower(trim($email));
        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        return hash('sha256', $normalized);
    }

    public static function hashPhone(?string $phone): ?string
    {
        if (!is_string($phone)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', trim($phone)) ?? '';
        if ($digits === '') {
            return null;
        }
        return hash('sha256', $digits);
    }
}
