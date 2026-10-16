<?php
require_once __DIR__ . '/CurrencyService.php';

/**
 * Tourfecto - بوابة XPay (شحن المحفظة بالكارت / المحافظ)
 *
 * الإعدادات كلها من لوحة الأدمن (/admin/plans-studio):
 *   - المفتاح العام pk_test_/pk_live_ (عادي)
 *   - المفتاح السري sk_test_/sk_live_ (متشفّر في قاعدة البيانات ومابيرجعش للواجهة أبدًا)
 *   - عملة الخصم (الجنيه افتراضيًا - شرط البنك) أو الدولار
 *
 * المسار: العميل يختار المبلغ بالدولار ← نعمل Checkout Session عند XPay (hosted)
 * ← العميل يدفع في صفحة XPay ← يرجع لموقعنا / أو يوصلنا Webhook
 * ← في الحالتين بنسأل XPay بنفسنا بالمفتاح السري عن الجلسة (مابنصدّقش الرسالة اللي جاية)
 * ← لو مدفوعة بنفس المبلغ والعملة: المحفظة تتشحن بالدولار مرة واحدة بس.
 */
class XPayGateway
{
    public const CONFIG_KEY = 'xpay_config';
    public const SECRET_KEY = 'integration_xpay_secret_key';
    public const DEFAULT_API = 'https://api.xpay.app';

    /** @var callable|null للاختبار: fn(string $method, string $url, ?array $body, string $key): array{code:int, body:?array} */
    private $transport;
    private ?SystemSettingsService $settings;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
        $this->settings = class_exists('SystemSettingsService') ? new SystemSettingsService() : null;
    }

    // ------------------------------------------------------------------ الإعدادات

    public function config(): array
    {
        $raw = $this->settings ? json_decode($this->settings->get(self::CONFIG_KEY, ''), true) : null;
        $raw = is_array($raw) ? $raw : [];
        $pk = (string) ($raw['public_key'] ?? '');
        $secret = $this->secret();
        return [
            'enabled' => !empty($raw['enabled']),
            'public_key' => $pk,
            'has_secret' => $secret !== '',
            'secret_hint' => $secret !== '' ? substr($secret, 0, 8) . '••••' . substr($secret, -4) : '',
            'mode' => str_starts_with($secret, 'sk_live_') || str_starts_with($pk, 'pk_live_') ? 'live' : 'test',
            'charge_currency' => in_array($raw['charge_currency'] ?? 'EGP', ['EGP', 'USD'], true) ? ($raw['charge_currency'] ?? 'EGP') : 'EGP',
            'min_usd' => max(1, (float) ($raw['min_usd'] ?? 5)),
            'max_usd' => min(10000, max(10, (float) ($raw['max_usd'] ?? 5000))),
            'api_base' => self::cleanBase((string) ($raw['api_base'] ?? '')),
            'last_test' => $raw['last_test'] ?? null,
        ];
    }

    public function isReady(): bool
    {
        $c = $this->config();
        return $c['enabled'] && $c['has_secret'];
    }

    private function secret(): string
    {
        return $this->settings ? trim($this->settings->get(self::SECRET_KEY, '')) : '';
    }

    private static function cleanBase(string $u): string
    {
        $u = rtrim(trim($u), '/');
        // عشان المفتاح السري مايتبعتش لأي مكان غير XPay
        return preg_match('#^https://([a-z0-9-]+\.)*xpay\.app$#i', $u) ? $u : self::DEFAULT_API;
    }

    /** حفظ من الأدمن - المفتاح السري الفاضي = سيب القديم */
    public function saveConfig(array $in): array
    {
        if (!$this->settings) {
            throw new RuntimeException('Settings unavailable');
        }
        $raw = json_decode($this->settings->get(self::CONFIG_KEY, ''), true);
        $raw = is_array($raw) ? $raw : [];
        if (array_key_exists('public_key', $in)) {
            $pk = trim((string) $in['public_key']);
            if ($pk !== '' && !preg_match('/^pk_(test|live)_[A-Za-z0-9_]+$/', $pk)) {
                throw new InvalidArgumentException('المفتاح العام لازم يبدأ بـ pk_test_ أو pk_live_');
            }
            $raw['public_key'] = $pk;
        }
        $sk = trim((string) ($in['secret_key'] ?? ''));
        if ($sk !== '') {
            if (str_starts_with($sk, 'pk_') || str_starts_with($sk, 'rk_')) {
                throw new InvalidArgumentException('ده مش المفتاح السري. المفتاح السري بيبدأ بـ sk_test_ أو sk_live_');
            }
            if (!preg_match('/^sk_(test|live)_[A-Za-z0-9_]+$/', $sk)) {
                throw new InvalidArgumentException('المفتاح السري لازم يبدأ بـ sk_test_ أو sk_live_');
            }
        }
        foreach (['charge_currency', 'min_usd', 'max_usd', 'api_base'] as $k) {
            if (array_key_exists($k, $in)) {
                $raw[$k] = $in[$k];
            }
        }
        if (array_key_exists('enabled', $in)) {
            $raw['enabled'] = !empty($in['enabled']);
        }
        $pk = (string) ($raw['public_key'] ?? '');
        $secret = $sk !== '' ? $sk : $this->secret();
        if ($pk !== '' && $secret !== '' && (str_contains($pk, '_live_') !== str_contains($secret, '_live_'))) {
            throw new InvalidArgumentException('المفتاحين لازم يكونوا من نفس الوضع: الاتنين test أو الاتنين live');
        }
        if (!empty($raw['enabled']) && $secret === '') {
            throw new InvalidArgumentException('حط المفتاح السري الأول قبل التفعيل');
        }
        if ($sk !== '') {
            $this->settings->set(self::SECRET_KEY, $sk);
        }
        $this->settings->set(self::CONFIG_KEY, json_encode($raw));
        return $this->config();
    }

    /** اختبار الاتصال بالمفتاح السري */
    public function testConnection(): array
    {
        if ($this->secret() === '') {
            return ['ok' => false, 'message' => 'مفيش مفتاح سري محفوظ'];
        }
        $r = $this->call('GET', '/checkout/sessions?limit=1');
        $ok = $r['code'] >= 200 && $r['code'] < 300;
        $msg = $ok ? 'الاتصال بـ XPay شغال ✅' : ($r['code'] === 401 || $r['code'] === 403 ? 'XPay رفض المفتاح السري - اتأكد إنك نسخته صح' : 'XPay مردّش صح (كود ' . $r['code'] . ')');
        if ($this->settings) {
            $raw = json_decode($this->settings->get(self::CONFIG_KEY, ''), true) ?: [];
            $raw['last_test'] = ['ok' => $ok, 'at' => date('Y-m-d H:i'), 'code' => $r['code']];
            $this->settings->set(self::CONFIG_KEY, json_encode($raw));
        }
        return ['ok' => $ok, 'code' => $r['code'], 'message' => $msg];
    }

    // ------------------------------------------------------------------ الدفع

    /** المبلغ اللي هيتخصم من العميل بأصغر وحدة (قروش / سنت) */
    public function chargeFor(float $usd): array
    {
        $cur = $this->config()['charge_currency'];
        $minor = $cur === 'USD' ? (int) round($usd * 100) : (int) round($usd * CurrencyService::rate('EGP') * 100);
        return ['currency' => $cur, 'minor' => $minor, 'label' => $cur === 'USD' ? '$' . number_format($usd, 2) : number_format($minor / 100, 2) . ' EGP'];
    }

    /**
     * جلسة دفع لشحن المحفظة.
     * @return array{checkout_url:?string, session_id:string, client_secret:?string, public_key:string, charge:array}
     */
    public function createWalletTopUp(int $userId, float $usd, string $returnUrl, string $cancelUrl, string $email = ''): array
    {
        $c = $this->config();
        if (!$this->isReady()) {
            throw new DomainException('الدفع بالكارت مش متاح دلوقتي');
        }
        $usd = round($usd, 2);
        if ($usd < $c['min_usd'] || $usd > $c['max_usd']) {
            throw new InvalidArgumentException('المبلغ لازم يكون بين $' . $c['min_usd'] . ' و $' . $c['max_usd']);
        }
        $charge = $this->chargeFor($usd);
        $db = Database::getInstance();
        $internal = 'PTX-' . strtoupper(bin2hex(random_bytes(12)));
        $reference = 'XTX-' . strtoupper(bin2hex(random_bytes(8)));
        $meta = ['purpose' => 'wallet_topup', 'charge_currency' => $charge['currency'], 'charge_minor' => $charge['minor'], 'rate' => $charge['currency'] === 'EGP' ? CurrencyService::rate('EGP') : 1];
        $db->query(
            'INSERT INTO payment_transactions (internal_transaction_id, user_id, amount, currency, payment_method, gateway, status, reference, metadata, idempotency_key) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$internal, $userId, $usd, 'USD', 'card', 'xpay', 'pending', $reference, json_encode($meta), 'xpay-' . $reference]
        );
        $txId = (int) ($db->query('SELECT id FROM payment_transactions WHERE internal_transaction_id = ? LIMIT 1', [$internal])[0]['id'] ?? 0);

        $sep = str_contains($returnUrl, '?') ? '&' : '?';
        $body = [
            'uiMode' => 'hosted',
            'lineItems' => [[
                'priceData' => ['unitAmount' => $charge['minor'], 'currency' => $charge['currency'], 'productData' => ['name' => 'Tourfecto wallet top-up ($' . number_format($usd, 2) . ')']],
                'quantity' => 1,
            ]],
            'afterCompletion' => ['type' => 'redirect', 'redirect' => ['url' => $returnUrl . $sep . 'session_id={CHECKOUT_SESSION_ID}']],
        ];
        // الحقول الموثّقة بس (حقل زيادة ممكن XPay يرفض الطلب بسببه) - الربط بيننا وبينهم برقم الجلسة
        $r = $this->call('POST', '/checkout/sessions', $body, 'xpay-' . $reference);
        $s = $r['body'] ?? [];
        $sid = (string) ($s['id'] ?? '');
        if ($r['code'] >= 300 || $sid === '') {
            $db->query('UPDATE payment_transactions SET status = ? WHERE id = ?', ['failed', $txId]);
            $err = (string) ($s['error']['message'] ?? $s['message'] ?? '');
            if (class_exists('Logger')) Logger::error('XPay session failed', ['code' => $r['code'], 'error' => $err]);
            throw new RuntimeException('XPay رفض إنشاء الدفع' . ($err !== '' ? ': ' . $err : ''));
        }
        $url = (string) ($s['url'] ?? $s['hostedUrl'] ?? $s['checkoutUrl'] ?? '');
        $meta += ['checkout_url' => $url, 'livemode' => !empty($s['livemode'])];
        $db->query('UPDATE payment_transactions SET gateway_transaction_id = ?, metadata = ? WHERE id = ?', [$sid, json_encode($meta), $txId]);
        return [
            'checkout_url' => $url !== '' ? $url : null,
            'session_id' => $sid,
            'client_secret' => isset($s['clientSecret']) ? (string) $s['clientSecret'] : null,
            'public_key' => $c['public_key'],
            'charge' => $charge,
        ];
    }

    /**
     * التأكيد: بنسأل XPay عن الجلسة بالمفتاح السري، ولو مدفوعة نشحن المحفظة (مرة واحدة).
     * بيتنده من صفحة الرجوع ومن الـ Webhook.
     * @return array{status:string, credited?:float, balance?:float}
     */
    public function confirm(string $sessionId): array
    {
        if (!preg_match('/^[A-Za-z0-9_\-]{6,191}$/', $sessionId)) {
            return ['status' => 'invalid'];
        }
        $db = Database::getInstance();
        $tx = $db->query("SELECT * FROM payment_transactions WHERE gateway = 'xpay' AND gateway_transaction_id = ? LIMIT 1", [$sessionId])[0] ?? null;
        if (!$tx) {
            return ['status' => 'unknown'];
        }
        if ($tx['status'] === 'succeeded') {
            return ['status' => 'paid', 'already' => true, 'credited' => (float) $tx['amount'], 'user_id' => (int) $tx['user_id']];
        }
        $r = $this->call('GET', '/checkout/sessions/' . rawurlencode($sessionId));
        $s = $r['body'] ?? [];
        if ($r['code'] >= 300 || ($s['id'] ?? '') !== $sessionId) {
            return ['status' => 'pending'];
        }
        $meta = json_decode((string) $tx['metadata'], true) ?: [];
        $statusType = is_array($s['status'] ?? null) ? (string) ($s['status']['type'] ?? '') : (string) ($s['status'] ?? '');
        $paid = ($s['paymentStatus'] ?? ($s['status']['paymentStatus'] ?? '')) === 'paid';
        if (!$paid) {
            if ($statusType === 'expired') {
                $db->query("UPDATE payment_transactions SET status = 'cancelled' WHERE id = ? AND status = 'pending'", [$tx['id']]);
                return ['status' => 'expired'];
            }
            return ['status' => 'pending'];
        }
        // المبلغ قبل رسوم البوابة لازم يساوي اللي طلبناه بنفس العملة
        $got = (int) ($s['amountSubtotal'] ?? $s['amountTotal'] ?? -1);
        if (strtoupper((string) ($s['currency'] ?? '')) !== ($meta['charge_currency'] ?? '') || $got < (int) ($meta['charge_minor'] ?? PHP_INT_MAX)) {
            if (class_exists('Logger')) Logger::warning('XPay amount mismatch - held for review', ['tx' => $tx['id'], 'got' => $got, 'currency' => $s['currency'] ?? '']);
            $db->query("UPDATE payment_transactions SET status = 'processing' WHERE id = ?", [$tx['id']]);
            return ['status' => 'review'];
        }
        $top = (new WalletService())->applyCardTopUp((int) $tx['user_id'], (float) $tx['amount'], 'USD', $sessionId, (int) $tx['id'], 'xpay');
        if (empty($top['success'])) {
            throw new RuntimeException('Wallet top-up failed: ' . ($top['error'] ?? ''));
        }
        $db->query("UPDATE payment_transactions SET status = 'succeeded', related_wallet_transaction_id = ? WHERE id = ?", [$top['transaction_id'] ?? null, $tx['id']]);
        return ['status' => 'paid', 'credited' => (float) $tx['amount'], 'balance' => $top['balance'] ?? null, 'user_id' => (int) $tx['user_id']];
    }

    /** Webhook: بناخد رقم الجلسة بس، والتأكيد من XPay نفسه */
    public function handleWebhook(string $payload): array
    {
        $e = json_decode($payload, true);
        if (!is_array($e)) {
            return ['handled' => false];
        }
        $obj = $e['data']['object'] ?? $e['data'] ?? [];
        $sid = (string) ($obj['id'] ?? $e['sessionId'] ?? '');
        if ($sid === '' || !str_starts_with((string) ($e['type'] ?? 'checkout.session'), 'checkout.session')) {
            return ['handled' => false];
        }
        return ['handled' => true] + $this->confirm($sid);
    }

    // ------------------------------------------------------------------ HTTP

    private function call(string $method, string $path, ?array $body = null, string $idem = ''): array
    {
        $url = $this->config()['api_base'] . $path;
        $key = $this->secret();
        if ($this->transport) {
            return ($this->transport)($method, $url, $body, $key);
        }
        $ch = curl_init($url);
        $h = ['Authorization: Bearer ' . $key, 'Accept: application/json'];
        if ($body !== null) {
            $h[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        if ($idem !== '') {
            $h[] = 'Idempotency-Key: ' . $idem;
        }
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => false]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $res === false ? 0 : $code, 'body' => is_string($res) ? (json_decode($res, true) ?: null) : null];
    }
}
