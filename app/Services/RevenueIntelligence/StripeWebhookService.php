<?php

/**
 * Tourfecto - Stripe Webhook Service
 * @version 1.0.0
 *
 * v1.6.0 (Section A - live integration): استقبال أحداث Stripe الحقيقية عبر
 * webhook، التحقق من توقيع Stripe-Signature (HMAC-SHA256)، ثم تحويلها إلى
 * سجلات `biz_subscriptions`/`biz_subscription_events` عبر StripeRevenueMapper.
 *
 * الشفافية والأمان:
 *   - السر (webhook secret) يأتي مشفرًا من revai_stripe_settings ولا يُسجَّل.
 *   - التحقق من التوقيع إجباري لأي حدث - أي حدث بدون توقيع صحيح يُرفض 401.
 *   - الـ ingestion idempotent عبر stripe_subscription_id / stripe_event_id
 *     (لو الحدث مستلم من قبل، نعيد نفس النتيجة بدون تكرار).
 *   - لا أرقام مخترعة: mapper يحوّل فقط ما هو موجود في الحمولة.
 *
 * Pure functions قابلة للاختبار بفيكسشرات:
 *   - verifySignature(payload, header, secret)
 *   - handleEvent(userId, payload, settingsRow) - يحوّل ويستدعي gateway
 */
class StripeWebhookService
{
    /** @var RevenueDataGateway */
    private $gateway;

    public function __construct(?RevenueDataGateway $gateway = null)
    {
        $this->gateway = $gateway ?? new RevenueDataGateway();
    }

    /**
     * التحقق من توقيع Stripe القياسي:
     * header = "t=<ts>,v1=<hex>"; signed_payload = "<ts>.<payload>";
     * متوقع = HMAC-SHA256(signed_payload, secret) hex.
     * Pure function - بدون أي I/O.
     */
    public static function verifySignature(string $payload, string $signatureHeader, string $secret): bool
    {
        if ($secret === '' || $signatureHeader === '' || $payload === '') {
            return false;
        }

        // parsing "t=...,v1=..."
        $parts = explode(',', $signatureHeader);
        $timestamp = null;
        $signature = null;
        foreach ($parts as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't') {
                $timestamp = $kv[1];
            } elseif ($kv[0] === 'v1') {
                $signature = $kv[1];
            }
        }
        if ($timestamp === null || $signature === null) {
            return false;
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $signedPayload, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * معالجة حدث Stripe واحد وإدخاله في جداول الموديول (idempotent).
     *
     * @param int   $userId   صاحب الحساب
     * @param array $payload  جسم الحدث المكتمل (مع data.object)
     * @param array $settings صف revai_stripe_settings (اختياري للـ last_event)
     * @return array النتيجة مع status واضح
     */
    public function handleEvent(int $userId, array $payload, array $settings = []): array
    {
        $type = (string) ($payload['type'] ?? '');
        $eventId = (string) ($payload['id'] ?? '');

        // Idempotency: نفس الـ stripe_event_id مسجل من قبل؟
        if ($eventId !== '' && $this->gateway->stripeEventExists($userId, $eventId)) {
            return ['handled' => true, 'status' => 'duplicate', 'event_id' => $eventId, 'type' => $type];
        }

        switch ($type) {
            case 'customer.subscription.created':
                $mapped = StripeRevenueMapper::mapSubscriptionCreated($payload);
                $this->gateway->upsertBizSubscriptionFromStripe($userId, $mapped['subscription']);
                $this->gateway->insertBizSubscriptionEvent($userId, $mapped['event']);
                break;

            case 'invoice.payment_succeeded':
                $mapped = StripeRevenueMapper::mapInvoicePaymentSucceeded($payload);
                $this->gateway->insertBizSubscriptionEvent($userId, $mapped['event']);
                break;

            case 'customer.subscription.deleted':
                $mapped = StripeRevenueMapper::mapSubscriptionDeleted($payload);
                $this->gateway->upsertBizSubscriptionFromStripe($userId, $mapped['subscription'], 'cancelled');
                $this->gateway->insertBizSubscriptionEvent($userId, $mapped['event']);
                break;

            default:
                // أحداث أخرى: نستقبلها بصمت (Stripe ترسل كثيرًا) لكن لا نستحدث صفوفًا.
                $this->gateway->touchStripeEvent($userId, $eventId, $type);
                return ['handled' => true, 'status' => 'ignored_type', 'event_id' => $eventId, 'type' => $type];
        }

        $this->gateway->touchStripeEvent($userId, $eventId, $type);
        return ['handled' => true, 'status' => 'processed', 'event_id' => $eventId, 'type' => $type];
    }
}
