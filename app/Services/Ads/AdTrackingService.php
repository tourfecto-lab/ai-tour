<?php

/**
 * Tourfecto - Ad Tracking Service (روابط UTM القابلة للتتبع)
 * بيبني روابط UTM قصيرة لكل حملة، وبيسجّل النقرات الحقيقية عبر /r/{code}
 * (رابط عام بدون تسجيل دخول - اللي بيضغط عليه زائر من إعلان)، وبيرجّع
 * قائمة الروابط بتاعة حملة معداد النقرات الفعلي.
 * @version 1.0.0
 */
class AdTrackingService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * يبني رابط UTM قصير ويحفظه للحملة. بيضمّن معاملات UTM جوا الرابط
     * نفسه (utm_source/utm_medium/utm_campaign + utm_content/utm_term اختياريين)
     * عشان التحويل النهائي يوصّل الزائر لصفحة الهبوط ومعاه بيانات التتبع.
     *
     * @return array{code:string, short_path:string, link:string, id:int, clicks:int}
     */
    public function buildLink(int $userId, AdCampaign $campaign, string $destinationUrl, string $utmSource = 'google', string $utmMedium = 'cpc', ?string $utmContent = null, ?string $utmTerm = null): array
    {
        $destinationUrl = trim($destinationUrl);
        if ($destinationUrl === '') {
            throw new InvalidArgumentException('رابط الوجهة مطلوب');
        }
        if (!preg_match('#^https?://#i', $destinationUrl)) {
            $destinationUrl = 'https://' . $destinationUrl;
        }
        if (mb_strlen($destinationUrl) > 900) {
            throw new InvalidArgumentException('الرابط أطول من اللازم');
        }

        $campaignName = (string) $campaign->getAttribute('name');
        $utmCampaign = $campaignName !== '' ? mb_substr($campaignName, 0, 255) : 'campaign-' . $campaign->getAttribute('id');

        $params = [
            'utm_source' => $utmSource,
            'utm_medium' => $utmMedium,
            'utm_campaign' => $utmCampaign,
        ];
        if ($utmContent !== null && trim((string) $utmContent) !== '') {
            $params['utm_content'] = trim((string) $utmContent);
        }
        if ($utmTerm !== null && trim((string) $utmTerm) !== '') {
            $params['utm_term'] = trim((string) $utmTerm);
        }

        $fullUrl = $destinationUrl . (strpos($destinationUrl, '?') !== false ? '&' : '?') . http_build_query($params);

        // كود فريد قصير - إعادة محاولة لو صادف كودًا موجود (احتمال ضئيل)
        $code = '';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = bin2hex(random_bytes(5));
            $exists = $this->db->query("SELECT id FROM ad_utm_links WHERE code = ? LIMIT 1", [$code]);
            if (empty($exists)) {
                break;
            }
        }

        $this->db->exec(
            "INSERT INTO ad_utm_links (campaign_id, code, destination_url, utm_source, utm_medium, utm_campaign, utm_content, utm_term)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                (int) $campaign->getAttribute('id'), $code, $fullUrl,
                $utmSource, $utmMedium, $utmCampaign, $utmContent, $utmTerm,
            ]
        );

        $idRow = $this->db->query("SELECT LAST_INSERT_ID() AS id");
        $id = (int) ($idRow[0]['id'] ?? 0);

        return [
            'code' => $code,
            'short_path' => '/r/' . $code,
            'short_redirect_url' => '/r/' . $code,
            'link' => $fullUrl,
            'id' => $id,
            'clicks' => 0,
        ];
    }

    /** قائمة روابط UTM لحملة معينة - الأحدث أولًا */
    public function listForCampaign(int $campaignId): array
    {
        return $this->db->query(
            "SELECT id, code, destination_url, utm_source, utm_medium, utm_campaign, utm_content, utm_term, clicks, created_at
             FROM ad_utm_links WHERE campaign_id = ? ORDER BY created_at DESC",
            [$campaignId]
        );
    }

    /**
     * يبحث عن الرابط بالكود، يسلّم النقرة (زيادة العداد)، ويرجّع وجهة
     * التحويل النهائية مع بيانات الإسناد (معرّف الرابط والمنصة) اللازمة
     * لتسجيل إسناد الحجز لاحقًا (نافذة 30 يوم). بيرجّع null لو الكود مش موجود.
     *
     * @return array{destination:string, utm_link_id:int, platform:string}|null
     */
    public function resolveAndTrackClick(string $code): ?array
    {
        $rows = $this->db->query(
            "SELECT u.id AS utm_link_id, u.destination_url, COALESCE(pc.platform, '') AS platform
             FROM ad_utm_links u
             LEFT JOIN ad_campaigns c ON c.id = u.campaign_id
             LEFT JOIN platform_connections pc ON pc.id = c.platform_connection_id
             WHERE u.code = ? LIMIT 1",
            [$code]
        );
        if (empty($rows)) {
            return null;
        }

        $this->db->exec("UPDATE ad_utm_links SET clicks = clicks + 1 WHERE id = ?", [(int) $rows[0]['utm_link_id']]);

        return [
            'destination' => (string) $rows[0]['destination_url'],
            'utm_link_id' => (int) $rows[0]['utm_link_id'],
            'platform' => (string) ($rows[0]['platform'] ?? ''),
        ];
    }

    /**
     * اسم كوكي الإسناد (نافذة 30 يوم). الكوكي بيخزّن معرّف رابط UTM ومنصته
     * مش أي بيانات شخصية للزائر - مبني على الـ Privacy by Design.
     */
    public const ATTRIBUTION_COOKIE = 'tf_utm_attribution';

    /** مدة نافذة الإسناد بالثواني (30 يوم) */
    public const ATTRIBUTION_WINDOW = 2592000;

    /**
     * يخزّن إسناد النقرة في كوكي (ويفضّل في الجلسة لو شغالة) قبل تحويل
     * الزائر لصفحة الهبوط - عشان أي حجز يتم خلال نافذة 30 يوم يتنسب
     * للرابط الإعلاني اللي جاب الزائر.
     */
    public function storeAttribution(int $utmLinkId, string $platform): void
    {
        $payload = json_encode([
            'utm_link_id' => $utmLinkId,
            'platform' => $platform,
            'ts' => time(),
        ]);

        if (!headers_sent()) {
            setcookie(self::ATTRIBUTION_COOKIE, $payload, [
                'expires' => time() + self::ATTRIBUTION_WINDOW,
                'path' => '/',
                'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        // نفس-الطلب: بحاكي القيمة كأنها رجعت من المتصفح عشان أي قراءة في نفس
        // الـ request تشوفها (في الحقيقة الكوكي بيرجع في الطلب اللي بعده).
        $_COOKIE[self::ATTRIBUTION_COOKIE] = $payload;

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::ATTRIBUTION_COOKIE] = $payload;
        }
    }

    /**
     * يقرأ الإسناد الحالي من الكوكي أو الجلسة. بيرجّع null لو مفيش إسناد
     * أو انتهت نافذة الـ 30 يوم.
     *
     * @return array{utm_link_id:int, platform:string}|null
     */
    public function readAttribution(): ?array
    {
        $raw = $_COOKIE[self::ATTRIBUTION_COOKIE] ?? null;
        if ($raw === null && session_status() === PHP_SESSION_ACTIVE) {
            $raw = $_SESSION[self::ATTRIBUTION_COOKIE] ?? null;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['utm_link_id'])) {
            return null;
        }

        $ts = (int) ($data['ts'] ?? 0);
        if ($ts > 0 && (time() - $ts) > self::ATTRIBUTION_WINDOW) {
            $this->clearAttribution();
            return null;
        }

        return [
            'utm_link_id' => (int) $data['utm_link_id'],
            'platform' => (string) ($data['platform'] ?? ''),
        ];
    }

    /** يمسح كوكي/جلسة الإسناد بعد استخدامها (مثلاً بعد تسجيل الحجز). */
    public function clearAttribution(): void
    {
        if (isset($_COOKIE[self::ATTRIBUTION_COOKIE])) {
            unset($_COOKIE[self::ATTRIBUTION_COOKIE]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::ATTRIBUTION_COOKIE]);
        }
        if (!headers_sent()) {
            setcookie(self::ATTRIBUTION_COOKIE, '', [
                'expires' => time() - 3600,
                'path' => '/',
            ]);
        }
    }
}
