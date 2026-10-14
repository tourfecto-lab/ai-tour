<?php

/**
 * Tourfecto - Stripe Revenue Mapper
 * @version 1.0.0
 *
 * v1.5.0 (section A): تحويل بيانات Stripe إلى سجلات الموديول الداخلية.
 *
 * شفافية كاملة: هذا mapper خالص (pure) يطبّع فقط أحداث Stripe القياسية
 * إلى صفوف `biz_subscriptions` / `biz_subscription_events` الجاهزة للإدراج.
 * لا يتصل بشبكة، لا يخترع قيمًا، ولا يتطلب مفاتيح Stripe في هذا الكود.
 * المفتاح السري يبقى حصريًا في إعدادات المستخدم (STRIPE_SECRET_KEY)
 * ولا يُسجَّل أبدًا.
 *
 * Pure functions (قابلة للاختبار بفيكسشرات):
 *   - normalizeAmountForCurrency(int $amount, string $currency): float  - من سنتات إلى عملة
 *   - mapIntervalToCycle(string $interval): string                       - month/week -> monthly...
 *   - convertSubscriptionToMrr(float $amount, string $cycle): float      - تحويل إلى MRR شهري
 *   - mapSubscriptionCreated(array $payload): array                      - سجلات للجداول
 *   - mapInvoicePaymentSucceeded(array $payload): array                  - سجل event expansion/new
 *   - mapSubscriptionDeleted(array $payload): array                      - سجل event churn
 */
class StripeRevenueMapper
{
    /**
     * تحويل مبلغ Stripe (سنتات) إلى العملة، للأرقام القياسية بـ 6 خانات عشرية
     * (USD/EUR تبقى دولارَين كما هي مع تقسيم على 100).
     */
    public static function normalizeAmountForCurrency(int $amount, string $currency): float
    {
        $currency = strtolower($currency);
        // عملات بلا كسور عشرية (JPY, KRW, VND ...)
        $noDecimal = ['jpy', 'krw', 'vnd', 'clp', 'pyg', 'xof', 'kwd', 'bhd', 'omr', 'jod', 'tnd', 'lyd'];
        if (in_array($currency, $noDecimal, true)) {
            return (float) $amount;
        }
        return round($amount / 100, 6);
    }

    /** تعيين interval خطط Stripe إلى دورة فوترة الموديول. */
    public static function mapIntervalToCycle(string $interval): string
    {
        $map = [
            'day' => 'monthly',
            'week' => 'monthly',
            'month' => 'monthly',
            'year' => 'yearly',
            'quarter' => 'quarterly',
            '6-month' => 'quarterly',
        ];
        return $map[strtolower(trim($interval))] ?? 'monthly';
    }

    /** تحويل مبلغ أي دورة إلى MRR شهري حقيقي (لا تقدير). */
    public static function convertSubscriptionToMrr(float $amount, string $cycle): float
    {
        switch ($cycle) {
            case 'yearly':
                return round($amount / 12, 2);
            case 'quarterly':
                return round($amount / 3, 2);
            default:
                return round($amount, 2);
        }
    }

    /** سجلات الاشتراك لحدث customer.subscription.created. */
    public static function mapSubscriptionCreated(array $payload): array
    {
        $subscription = $payload['data']['object'] ?? $payload;
        $customer = (string) ($subscription['customer'] ?? '');
        $currency = (string) ($subscription['currency'] ?? 'usd');
        $plan = $subscription['plan'] ?? $subscription['items']['data'][0]['plan'] ?? [];
        $amount = isset($plan['amount']) ? (int) $plan['amount'] : 0;
        $interval = (string) ($plan['interval'] ?? 'month');
        $cycle = self::mapIntervalToCycle($interval);
        $amountNormalized = self::normalizeAmountForCurrency($amount, $currency);
        $mrr = self::convertSubscriptionToMrr($amountNormalized, $cycle);

        $status = (string) ($subscription['status'] ?? 'active');

        $subscriptionRow = [
            'stripe_subscription_id' => (string) ($subscription['id'] ?? ''),
            'stripe_customer_id' => $customer,
            'customer_name' => (string) ($subscription['customer_name'] ?? ''),
            'customer_email' => (string) ($subscription['customer_email'] ?? ''),
            'plan_name' => (string) ($plan['nickname'] ?? $plan['id'] ?? ''),
            'status' => in_array($status, ['active', 'trialing'], true) ? $status : 'active',
            'billing_cycle' => $cycle,
            'amount' => $amountNormalized,
            'currency' => strtoupper($currency),
            'mrr' => $mrr,
            'current_period_start' => isset($subscription['current_period_start']) ? gmdate('Y-m-d H:i:s', (int) $subscription['current_period_start']) : null,
            'current_period_end' => isset($subscription['current_period_end']) ? gmdate('Y-m-d H:i:s', (int) $subscription['current_period_end']) : null,
        ];

        $eventRow = [
            'stripe_event_id' => (string) ($payload['id'] ?? ''),
            'event_type' => 'new',
            'mrr_delta' => $mrr,
            'occurred_at' => isset($payload['created']) ? gmdate('Y-m-d H:i:s', (int) $payload['created']) : gmdate('Y-m-d H:i:s'),
            'description' => 'subscription.created via Stripe',
        ];

        return ['subscription' => $subscriptionRow, 'event' => $eventRow];
    }

    /**
     * سجل event لفواتير Stripe الناجحة (مصدر موثوق للـ MRR الفعلي الشهري).
     * يشمل التوسعات عند تغيّر المبلغ.
     */
    public static function mapInvoicePaymentSucceeded(array $payload): array
    {
        $invoice = $payload['data']['object'] ?? $payload;
        $lines = $invoice['lines']['data'] ?? [];
        $customer = (string) ($invoice['customer'] ?? '');

        $totalAmount = 0;
        $interval = 'month';
        foreach ($lines as $line) {
            $lineAmount = isset($line['amount']) ? (int) $line['amount'] : (int) ($line['price']['unit_amount'] ?? 0);
            $totalAmount += $lineAmount;
            if (isset($line['price']['recurring']['interval'])) {
                $interval = (string) $line['price']['recurring']['interval'];
            }
        }
        $currency = (string) ($invoice['currency'] ?? 'usd');
        $cycle = self::mapIntervalToCycle($interval);
        $amountNormalized = self::normalizeAmountForCurrency($totalAmount, $currency);
        $mrr = self::convertSubscriptionToMrr($amountNormalized, $cycle);

        $eventRow = [
            'stripe_event_id' => (string) ($payload['id'] ?? ''),
            'stripe_subscription_id' => (string) ($invoice['subscription'] ?? ''),
            'event_type' => 'expansion',
            'mrr_delta' => $mrr,
            'occurred_at' => isset($payload['created']) ? gmdate('Y-m-d H:i:s', (int) $payload['created']) : gmdate('Y-m-d H:i:s'),
            'description' => 'invoice.payment_succeeded via Stripe',
            'stripe_customer_id' => $customer,
        ];

        return ['event' => $eventRow];
    }

    /** سجل event عند إلغاء الاشتراك (churn). */
    public static function mapSubscriptionDeleted(array $payload): array
    {
        $subscription = $payload['data']['object'] ?? $payload;
        $customer = (string) ($subscription['customer'] ?? '');
        $currency = (string) ($subscription['currency'] ?? 'usd');
        $plan = $subscription['plan'] ?? $subscription['items']['data'][0]['plan'] ?? [];
        $amount = isset($plan['amount']) ? (int) $plan['amount'] : 0;
        $interval = (string) ($plan['interval'] ?? 'month');
        $cycle = self::mapIntervalToCycle($interval);
        $amountNormalized = self::normalizeAmountForCurrency($amount, $currency);
        $mrr = self::convertSubscriptionToMrr($amountNormalized, $cycle);

        $eventRow = [
            'stripe_event_id' => (string) ($payload['id'] ?? ''),
            'stripe_subscription_id' => (string) ($subscription['id'] ?? ''),
            'event_type' => 'churn',
            'mrr_delta' => -abs($mrr),
            'occurred_at' => isset($payload['created']) ? gmdate('Y-m-d H:i:s', (int) $payload['created']) : gmdate('Y-m-d H:i:s'),
            'description' => 'customer.subscription.deleted via Stripe',
            'stripe_customer_id' => $customer,
        ];

        // صف اشتراك (status=cancelled) حتى يتمكن الخادم من تحديث حالة الاشتراك
        // المرتبط عند استلام حدث الحذف - إضافة فقط بدون فقدان البيانات.
        $subscriptionRow = [
            'stripe_subscription_id' => (string) ($subscription['id'] ?? ''),
            'stripe_customer_id' => $customer,
            'customer_name' => (string) ($subscription['customer_name'] ?? ''),
            'customer_email' => (string) ($subscription['customer_email'] ?? ''),
            'plan_name' => (string) ($plan['nickname'] ?? $plan['id'] ?? ''),
            'status' => 'cancelled',
            'billing_cycle' => $cycle,
            'amount' => $amountNormalized,
            'currency' => strtoupper($currency),
            'mrr' => 0.0,
            'current_period_start' => null,
            'current_period_end' => null,
        ];

        return ['event' => $eventRow, 'subscription' => $subscriptionRow];
    }
}
