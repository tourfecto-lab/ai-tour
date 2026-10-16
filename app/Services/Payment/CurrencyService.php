<?php

/**
 * Tourfecto - العملات
 *
 * كل الأسعار والمحفظة متسجلة بالدولار (USD) زي ما هي. العملات التانية للعرض
 * والدفع بس: السعر بيتحسب = السعر بالدولار × سعر الصرف (+ هامش اختياري).
 *
 * سعر الصرف بيتحط من الأدمن (يدوي - مثلًا سعر البنك) أو بيتحدّث من السوق،
 * والسعر الثابت بيكسب دايمًا لو الأدمن قفله.
 */
class CurrencyService
{
    /** العملات المدعومة (نفس اللي XPay بيقبلها + الخليج) */
    public const CURRENCIES = [
        'USD' => ['ar' => 'دولار', 'en' => 'US Dollar', 'symbol' => '$', 'ar_symbol' => '$', 'decimals' => 0, 'fallback' => 1.0],
        'EGP' => ['ar' => 'جنيه مصري', 'en' => 'Egyptian Pound', 'symbol' => 'EGP', 'ar_symbol' => 'ج.م', 'decimals' => 0, 'fallback' => 50.0],
        'SAR' => ['ar' => 'ريال سعودي', 'en' => 'Saudi Riyal', 'symbol' => 'SAR', 'ar_symbol' => 'ر.س', 'decimals' => 0, 'fallback' => 3.75],
        'AED' => ['ar' => 'درهم إماراتي', 'en' => 'UAE Dirham', 'symbol' => 'AED', 'ar_symbol' => 'د.إ', 'decimals' => 0, 'fallback' => 3.6725],
        'KWD' => ['ar' => 'دينار كويتي', 'en' => 'Kuwaiti Dinar', 'symbol' => 'KWD', 'ar_symbol' => 'د.ك', 'decimals' => 1, 'fallback' => 0.307],
        'QAR' => ['ar' => 'ريال قطري', 'en' => 'Qatari Riyal', 'symbol' => 'QAR', 'ar_symbol' => 'ر.ق', 'decimals' => 0, 'fallback' => 3.64],
        'EUR' => ['ar' => 'يورو', 'en' => 'Euro', 'symbol' => '€', 'ar_symbol' => '€', 'decimals' => 0, 'fallback' => 0.92],
        'GBP' => ['ar' => 'جنيه إسترليني', 'en' => 'British Pound', 'symbol' => '£', 'ar_symbol' => '£', 'decimals' => 0, 'fallback' => 0.79],
    ];
    /** البلد => العملة الافتراضية */
    private const COUNTRY = ['EG' => 'EGP', 'SA' => 'SAR', 'AE' => 'AED', 'KW' => 'KWD', 'QA' => 'QAR', 'GB' => 'GBP', 'DE' => 'EUR', 'FR' => 'EUR', 'IT' => 'EUR', 'ES' => 'EUR', 'NL' => 'EUR', 'BE' => 'EUR', 'AT' => 'EUR', 'IE' => 'EUR', 'PT' => 'EUR', 'GR' => 'EUR'];
    public const COOKIE = 'tf_currency';

    private static ?array $cache = null;

    private static function settings(): ?SystemSettingsService
    {
        try {
            return class_exists('SystemSettingsService') ? new SystemSettingsService() : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function valid(string $code): bool
    {
        return isset(self::CURRENCIES[$code]);
    }

    /**
     * أسعار الصرف (عدد وحدات العملة مقابل 1 دولار) بعد الهامش.
     * @return array<string, array{rate:float, base:float, margin:float, source:string, updated_at:string, locked:bool}>
     */
    public static function rates(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $s = self::settings();
        $saved = $s ? json_decode($s->get('fx_rates', ''), true) : null;
        $saved = is_array($saved) ? $saved : [];
        $out = [];
        foreach (self::CURRENCIES as $code => $meta) {
            $r = $saved[$code] ?? [];
            $base = isset($r['base']) && is_numeric($r['base']) && $r['base'] > 0 ? (float) $r['base'] : $meta['fallback'];
            $margin = isset($r['margin']) && is_numeric($r['margin']) ? max(0.0, min(20.0, (float) $r['margin'])) : 0.0;
            $out[$code] = [
                'rate' => $code === 'USD' ? 1.0 : round($base * (1 + $margin / 100), 6),
                'base' => $code === 'USD' ? 1.0 : $base,
                'margin' => $code === 'USD' ? 0.0 : $margin,
                'source' => $code === 'USD' ? 'base' : (string) ($r['source'] ?? 'default'),
                'updated_at' => (string) ($r['updated_at'] ?? ''),
                'locked' => !empty($r['locked']),
                'enabled' => $code === 'USD' || $code === 'EGP' || !isset($r['enabled']) || !empty($r['enabled']),
            ];
        }
        return self::$cache = $out;
    }

    public static function rate(string $code): float
    {
        return self::rates()[$code]['rate'] ?? 1.0;
    }

    /** دولار => عملة */
    public static function fromUsd(float $usd, string $code): float
    {
        $c = self::CURRENCIES[$code] ?? self::CURRENCIES['USD'];
        $v = $usd * self::rate($code);
        // الأسعار الكبيرة بتتقرب لأقرب رقم مقبول (مثلًا 2,450 مش 2,447.35)
        if ($c['decimals'] === 0 && $v >= 100) {
            return round($v / 5) * 5;
        }
        return round($v, $c['decimals'] === 0 ? 0 : $c['decimals']);
    }

    /** عملة => دولار (للدفع: المبلغ اللي اتدفع بالجنيه يتسجل في المحفظة بالدولار) */
    public static function toUsd(float $amount, string $code): float
    {
        $r = self::rate($code);
        return $r > 0 ? round($amount / $r, 2) : 0.0;
    }

    public static function format(float $usd, string $code, bool $arabic = true): string
    {
        $c = self::CURRENCIES[$code] ?? self::CURRENCIES['USD'];
        $v = self::fromUsd($usd, $code);
        $num = number_format($v, $v == floor($v) ? 0 : ($c['decimals'] ?: 2));
        if ($code === 'USD') {
            return '$' . $num;
        }
        return $arabic ? $num . ' ' . $c['ar_symbol'] : $c['symbol'] . ' ' . $num;
    }

    /** عملة الزائر: اختياره (كوكي/حسابه) ← بلده ← دولار */
    /**
     * عملة العرض: المنصة موجّهة للأجانب، فالدولار هو الأساس للكل.
     * العملة بتتغير بس لو الزائر اختارها بنفسه (الكوكي)، أو لو الأدمن فعّل
     * "العملة المحلية تلقائي حسب البلد".
     * (عملة حساب العميل في الإعدادات دي عملة حجوزاته هو، مش عملة اشتراكه في المنصة)
     */
    public static function forViewer(?array $user = null): string
    {
        $c = strtoupper((string) ($_COOKIE[self::COOKIE] ?? ''));
        if (self::valid($c) && self::rates()[$c]['enabled']) {
            return $c;
        }
        $s = self::settings();
        if ($s && $s->get('fx_auto_local', '0') === '1') {
            $country = strtoupper((string) ($user['country_code'] ?? $_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
            if (isset(self::COUNTRY[$country]) && self::rates()[self::COUNTRY[$country]]['enabled']) {
                return self::COUNTRY[$country];
            }
        }
        return 'USD';
    }

    /** السعر بالجنيه المصري جنب الدولار (شرط بنك الدفع المحلي) - فاضي لو الجنيه مقفول */
    public static function egpHint(float $usd, bool $arabic = true): string
    {
        if (!self::rates()['EGP']['enabled'] || $usd <= 0) {
            return '';
        }
        return self::format($usd, 'EGP', $arabic);
    }

    /** للواجهة: العملات المتاحة وأسعارها */
    public static function publicList(bool $arabic = true): array
    {
        $out = [];
        foreach (self::rates() as $code => $r) {
            if (!$r['enabled']) {
                continue;
            }
            $m = self::CURRENCIES[$code];
            $out[] = ['code' => $code, 'name' => $arabic ? $m['ar'] : $m['en'], 'symbol' => $arabic ? $m['ar_symbol'] : $m['symbol'], 'rate' => $r['rate'], 'decimals' => $m['decimals']];
        }
        return $out;
    }

    // ------------------------------------------------------------------ الأدمن

    /** @param array<string, array{base?:float, margin?:float, locked?:bool, enabled?:bool}> $in */
    public static function saveRates(array $in): array
    {
        $s = self::settings();
        if (!$s) {
            throw new RuntimeException('Settings unavailable');
        }
        $saved = json_decode($s->get('fx_rates', ''), true);
        $saved = is_array($saved) ? $saved : [];
        foreach ($in as $code => $r) {
            if (!self::valid((string) $code) || $code === 'USD' || !is_array($r)) {
                continue;
            }
            $cur = $saved[$code] ?? [];
            if (isset($r['base'])) {
                if (!is_numeric($r['base']) || $r['base'] <= 0 || $r['base'] > 100000) {
                    throw new InvalidArgumentException('سعر صرف غير صالح لـ ' . $code);
                }
                if ((float) $r['base'] !== (float) ($cur['base'] ?? 0)) {
                    $cur['base'] = (float) $r['base'];
                    $cur['source'] = 'manual';
                    $cur['updated_at'] = date('Y-m-d H:i');
                }
            }
            if (isset($r['margin'])) {
                $cur['margin'] = max(0.0, min(20.0, (float) $r['margin']));
            }
            if (array_key_exists('locked', $r)) {
                $cur['locked'] = !empty($r['locked']);
            }
            if (array_key_exists('enabled', $r)) {
                $cur['enabled'] = !empty($r['enabled']);
            }
            $saved[$code] = $cur;
        }
        $s->set('fx_rates', json_encode($saved));
        self::$cache = null;
        return self::rates();
    }

    /**
     * تحديث من السوق (العملات اللي مش مقفولة بس).
     * @param callable|null $fetch للاختبار: fn():?array{code=>rate}
     */
    public static function refreshFromMarket(?callable $fetch = null): array
    {
        $fetch ??= static function (): ?array {
            $r = SafeHttp::exec('https://open.er-api.com/v6/latest/USD', [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true], 3);
            $d = is_string($r['response']) ? json_decode($r['response'], true) : null;
            return is_array($d['rates'] ?? null) ? $d['rates'] : null;
        };
        $market = $fetch();
        if (!$market) {
            throw new RuntimeException('مقدرناش نجيب أسعار الصرف دلوقتي');
        }
        $s = self::settings();
        $saved = json_decode($s->get('fx_rates', ''), true);
        $saved = is_array($saved) ? $saved : [];
        $updated = [];
        foreach (array_keys(self::CURRENCIES) as $code) {
            if ($code === 'USD' || !empty($saved[$code]['locked']) || empty($market[$code]) || !is_numeric($market[$code])) {
                continue;
            }
            $saved[$code] = array_merge($saved[$code] ?? [], ['base' => round((float) $market[$code], 4), 'source' => 'market', 'updated_at' => date('Y-m-d H:i')]);
            $updated[] = $code;
        }
        $s->set('fx_rates', json_encode($saved));
        self::$cache = null;
        return $updated;
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }
}
