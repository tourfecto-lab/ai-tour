<?php

/**
 * Tourfecto - لينك دفع لحجز (عربون أو المبلغ كله)
 *
 * صاحب الشركة بيعمل لينك لحجز موجود → بيبعته للعميل (واتساب/شات) → العميل بيفتح صفحة بسيطة فيها
 * تفاصيل الحجز والمبلغ → يدفع على Stripe بتاع صاحب الشركة → الحجز يتأكد لوحده والمبلغ يتسجّل.
 * الفلوس بتروح لحساب صاحب الشركة (نفس OperatorPayments)، مش للمنصة.
 */
class PaymentLinkService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
    }

    public static function url(string $token): string
    {
        $base = defined('APP_URL') ? rtrim((string) APP_URL, '/') : '';
        return $base . '/pay/' . $token;
    }

    private function booking(int $ownerId, int $bookingId): ?array
    {
        return $this->db->query(
            'SELECT b.*, p.name AS product_name FROM bookings b LEFT JOIN crm_products p ON p.id = b.product_id WHERE b.id = ? AND b.user_id = ? LIMIT 1',
            [$bookingId, $ownerId]
        )[0] ?? null;
    }

    /**
     * @param string $kind deposit_pct | deposit_fixed | full | balance
     * @return array{ok:bool, error?:string, link?:array}
     */
    public function create(int $ownerId, int $actorId, int $bookingId, string $kind, float $value, int $hours): array
    {
        if (!is_finite($value)) { return ['ok' => false, 'error' => 'مبلغ غير صالح']; }
        return $this->db->transaction(function () use ($ownerId, $actorId, $bookingId, $kind, $value, $hours) {
            $this->db->query('SELECT id FROM bookings WHERE id = ? AND user_id = ? FOR UPDATE', [$bookingId, $ownerId]);
            return $this->createLocked($ownerId, $actorId, $bookingId, $kind, $value, $hours);
        });
    }

    private function createLocked(int $ownerId, int $actorId, int $bookingId, string $kind, float $value, int $hours): array
    {
        $b = $this->booking($ownerId, $bookingId);
        if (!$b) {
            return ['ok' => false, 'error' => 'الحجز غير موجود'];
        }
        if (!in_array($b['status'], ['pending', 'confirmed'], true)) {
            return ['ok' => false, 'error' => 'الحجز ده ملغي أو خلص'];
        }
        if (!class_exists('OperatorPayments') || OperatorPayments::stripeFor($ownerId) === null) {
            return ['ok' => false, 'error' => 'الدفع الأونلاين مش مفعّل - اربط حساب Stripe بتاعك من صفحة موقع الحجز (استلام الفلوس) الأول', 'needs_stripe' => true];
        }
        $total = round((float) $b['total_amount'], 2);
        $paid = round((float) ($b['amount_paid'] ?? 0), 2);
        $remaining = round(max(0, $total - $paid), 2);
        if ($remaining <= 0) {
            return ['ok' => false, 'error' => 'الحجز ده متدفع بالكامل'];
        }
        switch ($kind) {
            case 'deposit_pct':
                if ($value < 5 || $value > 100) {
                    return ['ok' => false, 'error' => 'النسبة لازم تكون من 5% لـ 100%'];
                }
                $amount = round($total * $value / 100, 2);
                break;
            case 'deposit_fixed':
                $amount = round($value, 2);
                break;
            case 'full':
            case 'balance':
                $amount = $remaining;
                break;
            default:
                return ['ok' => false, 'error' => 'نوع اللينك مش معروف'];
        }
        if ($amount <= 0) {
            return ['ok' => false, 'error' => 'المبلغ لازم يكون أكبر من صفر'];
        }
        if ($amount > $remaining) {
            return ['ok' => false, 'error' => 'المبلغ أكبر من الباقي على الحجز (' . $remaining . ' ' . $b['currency'] . ')'];
        }
        $hours = max(1, min(168, $hours));
        // لينك واحد شغّال لكل حجز: القديم بيتقفل
        $this->db->exec("UPDATE booking_payment_links SET status = 'replaced' WHERE booking_id = ? AND status = 'active'", [$bookingId]);
        $token = bin2hex(random_bytes(12));
        $dbKind = $amount >= $remaining ? ($paid > 0 ? 'balance' : 'full') : 'deposit';
        $this->db->exec(
            'INSERT INTO booking_payment_links (token, booking_id, user_id, kind, amount, currency, status, expires_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, \'active\', DATE_ADD(NOW(), INTERVAL ? HOUR), ?)',
            [$token, $bookingId, $ownerId, $dbKind, $amount, (string) $b['currency'], $hours, $actorId]
        );
        $link = $this->db->query('SELECT * FROM booking_payment_links WHERE token = ? LIMIT 1', [$token])[0] ?? null;
        if (!$link) { throw new RuntimeException('Payment link creation failed'); }
        return ['ok' => true, 'link' => $this->present($link, $b)];
    }

    public function present(array $l, ?array $b = null): array
    {
        $b = $b ?: $this->booking((int) $l['user_id'], (int) $l['booking_id']);
        $url = self::url((string) $l['token']);
        $amountTxt = rtrim(rtrim(number_format((float) $l['amount'], 2, '.', ','), '0'), '.') . ' ' . $l['currency'];
        $msg = 'أهلاً ' . trim((string) ($b['customer_name'] ?? '')) . ' 👋' . "\n"
            . ($l['kind'] === 'deposit' ? 'عشان نأكد حجزك' : 'لدفع حجزك') . ' "' . ($b['product_name'] ?? 'الرحلة') . '" يوم ' . ($b['start_date'] ?? '') . "\n"
            . ($l['kind'] === 'deposit' ? 'العربون: ' : 'المبلغ: ') . $amountTxt . "\n"
            . 'ادفع بأمان من هنا: ' . $url;
        $phone = preg_replace('/\D/', '', (string) ($b['customer_phone'] ?? ''));
        return [
            'id' => (int) $l['id'], 'token' => $l['token'], 'url' => $url, 'kind' => $l['kind'],
            'amount' => (float) $l['amount'], 'currency' => $l['currency'], 'status' => $l['status'],
            'expires_at' => $l['expires_at'], 'paid_at' => $l['paid_at'], 'views' => (int) ($l['views'] ?? 0),
            'message' => $msg,
            'whatsapp_url' => $phone !== '' ? 'https://wa.me/' . $phone . '?text=' . rawurlencode($msg) : null,
        ];
    }

    public function listForBooking(int $ownerId, int $bookingId): array
    {
        $b = $this->booking($ownerId, $bookingId);
        if (!$b) {
            return [];
        }
        $rows = $this->db->query('SELECT * FROM booking_payment_links WHERE booking_id = ? AND user_id = ? ORDER BY id DESC LIMIT 20', [$bookingId, $ownerId]);
        return array_map(fn ($l) => $this->present($l, $b), $rows);
    }

    public function cancel(int $ownerId, int $linkId): bool
    {
        $this->db->exec("UPDATE booking_payment_links SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'active'", [$linkId, $ownerId]);
        return true;
    }

    /** للصفحة العامة: اللينك + الحجز + اسم الشركة. null لو التوكن غلط */
    public function publicView(string $token, bool $countView = false): ?array
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $token)) {
            return null;
        }
        $l = $this->db->query('SELECT *, (expires_at < NOW()) AS is_expired FROM booking_payment_links WHERE token = ? LIMIT 1', [$token])[0] ?? null;
        if (!$l) {
            return null;
        }
        if ($l['status'] === 'active' && (int) $l['is_expired'] === 1) {
            $this->db->exec("UPDATE booking_payment_links SET status = 'expired' WHERE id = ?", [(int) $l['id']]);
            $l['status'] = 'expired';
        }
        if ($countView) {
            $this->db->exec('UPDATE booking_payment_links SET views = views + 1 WHERE id = ?', [(int) $l['id']]);
        }
        $b = $this->booking((int) $l['user_id'], (int) $l['booking_id']);
        $company = '';
        try {
            $company = (string) ($this->db->query('SELECT company_name FROM users WHERE id = ? LIMIT 1', [(int) $l['user_id']])[0]['company_name'] ?? '');
        } catch (Throwable $e) {
        }
        return ['link' => $l, 'booking' => $b, 'company' => $company];
    }

    /** إنشاء جلسة دفع Stripe من اللينك (أو إعادة استخدام جلسة لسه صالحة) */
    public function checkoutUrl(string $token): array
    {
        $v = $this->publicView($token);
        if (!$v || !$v['booking']) {
            return ['ok' => false, 'error' => 'اللينك ده مش صحيح'];
        }
        $l = $v['link'];
        if ($l['status'] !== 'active') {
            return ['ok' => false, 'error' => $l['status'] === 'paid' ? 'اللينك ده اتدفع خلاص ✓' : 'اللينك ده انتهى - اطلب لينك جديد من الشركة'];
        }
        if (!in_array($v['booking']['status'], ['pending', 'confirmed'], true)) {
            return ['ok' => false, 'error' => 'الحجز ده اتلغى - كلّم الشركة'];
        }
        $window = class_exists('StripeCheckoutService') ? StripeCheckoutService::paymentWindowMinutes() : 60;
        $recent = $this->db->query(
            "SELECT metadata FROM payment_transactions WHERE booking_id = ? AND status IN ('pending','processing')
               AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.payment_link_id')) = ? AND created_at > (NOW() - INTERVAL ? MINUTE)
             ORDER BY id DESC LIMIT 1",
            [(int) $l['booking_id'], (string) $l['id'], max(5, $window - 5)]
        );
        if ($recent) {
            $m = json_decode((string) $recent[0]['metadata'], true) ?: [];
            if (!empty($m['checkout_url'])) {
                return ['ok' => true, 'url' => $m['checkout_url']];
            }
        }
        $stripe = OperatorPayments::stripeFor((int) $l['user_id']);
        if ($stripe === null) {
            return ['ok' => false, 'error' => 'الدفع الأونلاين متوقف عند الشركة دلوقتي - كلّمهم'];
        }
        $page = self::url((string) $l['token']);
        try {
            $s = $stripe->createCheckoutSession((int) $l['user_id'], (int) $l['booking_id'], $page . '?paid=1', $page, [
                'amount' => (float) $l['amount'],
                'idempotency_key' => 'plink-' . $l['id'] . '-' . bin2hex(random_bytes(4)),
                'metadata' => ['payment_link_id' => (string) $l['id'], 'payment_kind' => (string) $l['kind']],
                'label' => ($l['kind'] === 'deposit' ? 'Deposit - ' : 'Booking - ') . ($v['booking']['product_name'] ?? '') . ' (' . $v['booking']['booking_reference'] . ')',
                'allow_confirmed' => true,
            ]);
            return ['ok' => true, 'url' => $s['checkout_url']];
        } catch (Throwable $e) {
            // معاملة اتسجّلت ومالهاش جلسة عند Stripe: نقفلها عشان ماتفضلش "pending"
            $this->db->exec(
                "UPDATE payment_transactions SET status = 'failed' WHERE booking_id = ? AND status = 'pending' AND (gateway_transaction_id IS NULL OR gateway_transaction_id = '')
                   AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.payment_link_id')) = ?",
                [(int) $l['booking_id'], (string) $l['id']]
            );
            Logger::warning('payment link checkout failed', ['link' => $l['id'], 'error' => $e->getMessage()]);
            return ['ok' => false, 'error' => 'صفحة الدفع مش متاحة دلوقتي - جرّب كمان شوية'];
        }
    }

    /** بعد ما Stripe يأكد الدفع (Webhook أو التحقق): اللينك "اتدفع" + المبلغ يتسجّل على الحجز + تنبيه لصاحب الشركة */
    public static function afterPayment(int $txId): void
    {
        $db = Database::getInstance();
        try {
            $db->transaction(function () use ($db, $txId) {
            $bookingId = (int) ($db->query('SELECT booking_id FROM payment_transactions WHERE id = ?', [$txId])[0]['booking_id'] ?? 0);
            if (!$bookingId) { return; }
            $db->query('SELECT id FROM bookings WHERE id = ? FOR UPDATE', [$bookingId]);
            $tx = $db->query("SELECT id, booking_id, user_id, amount, currency, metadata FROM payment_transactions WHERE id = ? AND status IN ('succeeded','partially_refunded','refunded') FOR UPDATE", [$txId])[0] ?? null;
            if (!$tx || empty($tx['booking_id'])) {
                return;
            }
            $m = json_decode((string) ($tx['metadata'] ?? ''), true) ?: [];
            if (!empty($m['amount_recorded'])) {
                return; // اتسجّل قبل كده
            }
            $db->exec("UPDATE payment_transactions SET metadata = JSON_SET(COALESCE(metadata,'{}'), '$.amount_recorded', true) WHERE id = ?", [$txId]);
            $db->exec('UPDATE bookings SET amount_paid = LEAST(total_amount, amount_paid + ?) WHERE id = ?', [(float) $tx['amount'], (int) $tx['booking_id']]);
            $linkId = (int) ($m['payment_link_id'] ?? 0);
            if ($linkId > 0) {
                $db->exec("UPDATE booking_payment_links SET status = 'paid', paid_at = NOW() WHERE id = ?", [$linkId]);
                $b = $db->query('SELECT booking_reference, customer_name, user_id FROM bookings WHERE id = ? LIMIT 1', [(int) $tx['booking_id']])[0] ?? null;
                if ($b && class_exists('Notification')) {
                    Notification::notify((int) $b['user_id'], 'booking_payment_received',
                        ($m['payment_kind'] ?? '') === 'deposit' ? '💳 العربون اتدفع' : '💳 الحجز اتدفع',
                        $b['customer_name'] . ' دفع ' . $tx['amount'] . ' ' . $tx['currency'] . ' للحجز ' . $b['booking_reference'] . ' - الحجز اتأكد.',
                        '/bookings');
                }
            }
            });
        } catch (Throwable $e) {
            Logger::warning('payment link afterPayment failed', ['tx' => $txId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
