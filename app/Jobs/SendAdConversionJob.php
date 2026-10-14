<?php

/**
 * Tourfecto - Send Ad Conversion Job (CAPI)
 * بيبعث حدث تحويل حقيقي للمنصة الإعلانية اللي الحجز اتبع من كليكها
 * (Meta Conversions API أو Google Enhanced Conversions) بشكل غير متزامن
 * بعد تأكيد الحجز. بيتشغّل بواسطة cron/process_queue.php مثل أي job تاني.
 *
 * الأمان:
 *   - بيشتغل فقط لو الحجز confirmed وله attributed_utm_link_id صالح.
 *   - بيانات العميل بتتبعت SHA-256 بس (AdPiiHasher) - أي معرّف خام مبيتسابش.
 *   - الأسرار (Pixel ID / Conversion Action / tokens) من إعدادات النظام
 *     أو .env - مفيش hardcode.
 *   - أي فشل بيحصل retry من الطابور (Max 3) وبعدها بيفشل للسجل.
 * @version 1.0.0  @date 2026-08-28
 */
class SendAdConversionJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $bookingId = (int) ($payload['booking_id'] ?? 0);
        if ($bookingId <= 0) {
            throw new Exception('booking_id مفقود في payload');
        }

        $db = Database::getInstance();

        $rows = $db->query(
            "SELECT b.id, b.booking_reference, b.total_amount, b.currency,
                    b.customer_email, b.customer_phone,
                    pc.platform AS platform, pc.access_token AS connection_token
             FROM bookings b
             JOIN ad_utm_links u ON u.id = b.attributed_utm_link_id
             JOIN ad_campaigns c ON c.id = u.campaign_id
             LEFT JOIN platform_connections pc ON pc.id = c.platform_connection_id
             WHERE b.id = ? AND b.status = 'confirmed' AND b.attributed_utm_link_id IS NOT NULL
             LIMIT 1",
            [$bookingId]
        );
        if (empty($rows)) {
            // مش مرتبط بحملة/منصة أو لسه مش confirmed - مفيش حدث يُرسل
            return;
        }
        $row = $rows[0];

        $emailHash = AdPiiHasher::hashEmail($row['customer_email'] ?? null);
        $phoneHash = AdPiiHasher::hashPhone($row['customer_phone'] ?? null);
        if ($emailHash === null && $phoneHash === null) {
            // من غير أي معرّف قابل للتطبيع الحدث مش هيتطابق - بنسجّل ونسيب
            if (class_exists('Logger')) {
                Logger::info('Ad conversion skipped: no PII to hash', ['booking_id' => $bookingId]);
            }
            return;
        }

        $platform = (string) ($row['platform'] ?? '');
        $value = round((float) ($row['total_amount'] ?? 0), 2);
        $currency = (string) ($row['currency'] ?? 'USD');

        $settings = class_exists('SystemSettingsService') ? new SystemSettingsService() : null;

        if ($platform === 'meta_ads') {
            $this->sendMetaConversion($row, $settings, $emailHash, $phoneHash, $value, $currency);
            return;
        }

        if ($platform === 'google_ads') {
            $this->sendGoogleConversion($row, $settings, $emailHash, $phoneHash, $value, $currency);
            return;
        }

        if (class_exists('Logger')) {
            Logger::info('Ad conversion skipped: unsupported platform', ['booking_id' => $bookingId, 'platform' => $platform]);
        }
    }

    /** Factory قابلة للاستبدال في الاختبارات (منع أي استدعاء شبكة فعلي) */
    protected function makeMetaApi(string $token): MetaAdsAPI
    {
        return new MetaAdsAPI($token);
    }

    protected function makeGoogleApi(string $token): GoogleAdsAPI
    {
        return new GoogleAdsAPI($token);
    }

    private function sendMetaConversion(array $row, ?object $settings, ?string $emailHash, ?string $phoneHash, float $value, string $currency): void
    {
        $pixelId = $this->setting($settings, 'meta_capi_pixel_id', 'META_CAPI_PIXEL_ID');
        if ($pixelId === '') {
            throw new Exception('META_CAPI_PIXEL_ID غير مضبوط في إعدادات النظام/.env');
        }

        $token = (string) (env('META_CAPI_ACCESS_TOKEN') ?: '');
        if ($token === '' && !empty($row['connection_token'])) {
            $token = (new Encryption())->decrypt((string) $row['connection_token']);
        }
        if ($token === '') {
            throw new Exception('META_CAPI_ACCESS_TOKEN غير مضبوط - يلزم توكن CAPI فعال');
        }

        $result = $this->makeMetaApi($token)->sendConversionEvent($pixelId, [
            'event_id' => (string) $row['booking_reference'],
            'value' => $value,
            'currency' => $currency,
            'email_hash' => $emailHash,
            'phone_hash' => $phoneHash,
        ]);

        if (!$result['success']) {
            throw new Exception('Meta CAPI failed: ' . ($result['error'] ?? 'خطأ غير معروف'));
        }
    }

    private function sendGoogleConversion(array $row, ?object $settings, ?string $emailHash, ?string $phoneHash, float $value, string $currency): void
    {
        $customerId = $this->setting($settings, 'google_ads_customer_id', 'GOOGLE_ADS_CUSTOMER_ID');
        $conversionAction = $this->setting($settings, 'google_ads_conversion_action', 'GOOGLE_ADS_CONVERSION_ACTION');
        if ($customerId === '' || $conversionAction === '') {
            throw new Exception('GOOGLE_ADS_CUSTOMER_ID و GOOGLE_ADS_CONVERSION_ACTION مطلوبين');
        }

        $token = (string) (env('GOOGLE_ADS_ACCESS_TOKEN') ?: '');
        if ($token === '' && !empty($row['connection_token'])) {
            $token = (new Encryption())->decrypt((string) $row['connection_token']);
        }
        if ($token === '') {
            throw new Exception('GOOGLE_ADS_ACCESS_TOKEN غير مضبوط');
        }

        $result = $this->makeGoogleApi($token)->sendEnhancedConversion($customerId, $conversionAction, [
            'event_id' => (string) $row['booking_reference'],
            'value' => $value,
            'currency' => $currency,
            'email_hash' => $emailHash,
            'phone_hash' => $phoneHash,
        ]);

        if (!$result['success']) {
            throw new Exception('Google CAPI failed: ' . ($result['error'] ?? 'خطأ غير معروف'));
        }
    }

    private function setting(?object $settings, string $key, string $envKey): string
    {
        if ($settings !== null) {
            $stored = $settings->get($key);
            if ($stored !== '') {
                return $stored;
            }
        }
        return (string) (env($envKey) ?: '');
    }
}
