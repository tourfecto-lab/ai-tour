<?php

/**
 * Tourfecto - استلام فلوس الحجوزات لصاحب النشاط
 *
 * مواقع العملاء (المولّدة) وروابط الدفع من لوحة الحجوزات كانت بتقبض على حساب Stripe
 * بتاع المنصة. دلوقتي كل صاحب نشاط بيختار طريقته (شوف migration 000005):
 *   request    = طلب حجز بس (الافتراضي) - هو يأكد ويقبض بنفسه
 *   own_stripe = Stripe بتاعه - الفلوس تروح حسابه مباشرة
 *   platform   = المنصة تقبض (لو الأدمن مفعّل PLATFORM_COLLECTS_BOOKINGS=true)
 *
 * حساب Stripe بتاع صاحب النشاط مش بيبعتلنا Webhook، فبنتحقق من الدفع:
 *   1) لما الزائر يرجع لصفحة تأكيد الحجز (session_id في الرابط)
 *   2) كرون كل 10 دقايق (cron/verify_operator_payments.php) للي قفل الصفحة قبل ما يرجع
 */
class OperatorPayments
{
    private const SALT = 'operator_stripe';

    public static function platformAllowed(): bool
    {
        $v = function_exists('env') ? env('PLATFORM_COLLECTS_BOOKINGS', false) : getenv('PLATFORM_COLLECTS_BOOKINGS');
        return $v === true || in_array(strtolower((string) $v), ['1', 'true', 'yes'], true);
    }

    private static function row(int $userId): ?array
    {
        try {
            $r = Database::getInstance()->query('SELECT * FROM operator_payment_settings WHERE user_id = ? LIMIT 1', [$userId]);
            return $r[0] ?? null;
        } catch (Throwable $e) {
            return null; // الجدول لسه ماتعملش
        }
    }

    /** الإعدادات للعرض (من غير المفتاح نفسه) */
    public static function settings(int $userId): array
    {
        $r = self::row($userId);
        return [
            'mode' => self::effectiveMode($userId),
            'saved_mode' => $r['mode'] ?? 'request',
            'has_stripe_key' => !empty($r['stripe_secret_key']),
            'stripe_key_last4' => $r['stripe_key_last4'] ?? null,
            'stripe_account_name' => $r['stripe_account_name'] ?? null,
            'verified_at' => $r['verified_at'] ?? null,
            'platform_allowed' => self::platformAllowed(),
        ];
    }

    public static function effectiveMode(int $userId): string
    {
        $r = self::row($userId);
        $mode = $r['mode'] ?? 'request';
        if ($mode === 'own_stripe' && !empty($r['stripe_secret_key'])) {
            return 'own_stripe';
        }
        if ($mode === 'platform' && self::platformAllowed()) {
            return 'platform';
        }
        return 'request';
    }

    /**
     * Stripe اللي هيقبض لصاحب النشاط ده، أو null لو "طلب حجز" بس.
     */
    public static function stripeFor(int $userId): ?StripeCheckoutService
    {
        $mode = self::effectiveMode($userId);
        if ($mode === 'own_stripe') {
            $key = self::secretKey($userId);
            return $key ? (new StripeCheckoutService())->withApiKey($key) : null;
        }
        if ($mode === 'platform') {
            $s = new StripeCheckoutService();
            return $s->isConfigured() ? $s : null;
        }
        return null;
    }

    private static function secretKey(int $userId): ?string
    {
        $r = self::row($userId);
        if (empty($r['stripe_secret_key'])) {
            return null;
        }
        try {
            $k = (new Encryption())->decrypt((string) $r['stripe_secret_key'], self::SALT);
            return $k !== '' ? $k : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * حفظ الإعدادات. لو فيه مفتاح جديد بنختبره عند Stripe الأول.
     * @return array{success:bool, error?:string}
     */
    public static function save(int $userId, string $mode, ?string $secretKey): array
    {
        if (!in_array($mode, ['request', 'own_stripe', 'platform'], true)) {
            return ['success' => false, 'error' => 'اختيار غير صحيح'];
        }
        if ($mode === 'platform' && !self::platformAllowed()) {
            return ['success' => false, 'error' => 'القبض عن طريق المنصة مش متاح حاليًا'];
        }
        $db = Database::getInstance();
        $existing = self::row($userId);
        $enc = $existing['stripe_secret_key'] ?? null;
        $last4 = $existing['stripe_key_last4'] ?? null;
        $acct = $existing['stripe_account_name'] ?? null;
        $verified = $existing['verified_at'] ?? null;

        $secretKey = $secretKey !== null ? trim($secretKey) : null;
        if ($secretKey !== null && $secretKey !== '') {
            if (!preg_match('/^sk_(live|test)_[A-Za-z0-9]{10,}$/', $secretKey)) {
                return ['success' => false, 'error' => 'ده مش مفتاح Stripe سري صحيح - لازم يبدأ بـ sk_live_ أو sk_test_'];
            }
            try {
                (new StripeCheckoutService())->withApiKey($secretKey)->testKey();
            } catch (Throwable $e) {
                return ['success' => false, 'error' => 'Stripe رفض المفتاح ده - اتأكد إنك نسخته صح من Developers ← API keys'];
            }
            $acct = null;
            $enc = (new Encryption())->encrypt($secretKey, self::SALT);
            $last4 = substr($secretKey, -4);
            $verified = date('Y-m-d H:i:s');
            if (strpos($secretKey, '_test_') !== false) {
                $acct = 'وضع التجربة (Test mode)';
            }
        }
        if ($mode === 'own_stripe' && !$enc) {
            return ['success' => false, 'error' => 'حط مفتاح Stripe السري الأول'];
        }
        $db->exec(
            'INSERT INTO operator_payment_settings (user_id, mode, stripe_secret_key, stripe_key_last4, stripe_account_name, verified_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE mode = VALUES(mode), stripe_secret_key = VALUES(stripe_secret_key), stripe_key_last4 = VALUES(stripe_key_last4),
                                     stripe_account_name = VALUES(stripe_account_name), verified_at = VALUES(verified_at)',
            [$userId, $mode, $enc, $last4, $acct, $verified]
        );
        return ['success' => true];
    }

    public static function removeKey(int $userId): void
    {
        Database::getInstance()->exec(
            "UPDATE operator_payment_settings SET stripe_secret_key = NULL, stripe_key_last4 = NULL, stripe_account_name = NULL, verified_at = NULL,
                    mode = IF(mode = 'own_stripe', 'request', mode) WHERE user_id = ?",
            [$userId]
        );
    }

    /**
     * يتحقق من دفع حجز اتعمل على حساب Stripe بتاع صاحب النشاط ويأكده لو اتدفع.
     * @return string paid | unpaid | expired | none | error
     */
    public static function verifyBooking(int $bookingId): string
    {
        $db = Database::getInstance();
        $tx = $db->query(
            "SELECT * FROM payment_transactions WHERE booking_id = ? AND gateway = 'stripe_operator' AND status IN ('pending','processing','succeeded','partially_refunded','refunded') ORDER BY id DESC LIMIT 1",
            [$bookingId]
        );
        if (!$tx) {
            return 'none';
        }
        $tx = $tx[0];
        if (in_array($tx['status'], ['succeeded', 'partially_refunded', 'refunded'], true)) {
            PaymentLinkService::afterPayment((int) $tx['id']);
            return 'paid';
        }
        $key = self::secretKey((int) $tx['user_id']);
        if (!$key || empty($tx['gateway_transaction_id'])) {
            return 'error';
        }
        try {
            $session = (new StripeCheckoutService())->withApiKey($key)->retrieveSession((string) $tx['gateway_transaction_id']);
        } catch (Throwable $e) {
            return 'error';
        }
        if (($session['payment_status'] ?? '') === 'paid') {
            // المبلغ والعملة لازم يطابقوا الحجز
            if (!StripeCheckoutService::matchesPaidSession($session, $tx)) {
                Logger::warning('Operator payment amount/currency mismatch', ['booking_id' => $bookingId]);
                return 'error';
            }
            try {
                (new BookingEngine())->confirmBookingFromPayment($bookingId);
            } catch (Throwable $e) {
                // الحجز اتلغى قبل ما الدفع يوصل - صاحب النشاط لازم يرجّع الفلوس من Stripe
                Logger::warning('Operator payment for non-confirmable booking', ['booking_id' => $bookingId, 'error' => $e->getMessage()]);
                $db->exec("UPDATE payment_transactions SET status = 'succeeded', metadata = JSON_SET(COALESCE(metadata,'{}'), '$.needs_refund', true) WHERE id = ?", [(int) $tx['id']]);
                if (class_exists('PaymentLinkService')) {
                    PaymentLinkService::afterPayment((int) $tx['id']);
                }
                if (class_exists('Notification')) {
                    Notification::notify((int) $tx['user_id'], 'booking_refund_needed', 'حجز ملغي اتدفع', 'الحجز ' . $tx['reference'] . ' اتلغى قبل ما الدفع يوصل - رجّع المبلغ للعميل من لوحة Stripe.', '/crm');
                }
                return 'paid';
            }
            $db->exec("UPDATE payment_transactions SET status = 'succeeded' WHERE id = ?", [(int) $tx['id']]);
            if (class_exists('PaymentLinkService')) {
                PaymentLinkService::afterPayment((int) $tx['id']);
            }
            return 'paid';
        }
        if (($session['status'] ?? '') === 'expired') {
            $db->exec("UPDATE payment_transactions SET status = 'cancelled' WHERE id = ?", [(int) $tx['id']]);
            return 'expired';
        }
        return 'unpaid';
    }
}
