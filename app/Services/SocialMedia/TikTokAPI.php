<?php

/**
 * Tourfecto - TikTok Content Posting API (v2)
 * نشر فيديوهات قصيرة على حساب العميل من رابط عام (PULL_FROM_URL).
 *
 * - الدومين اللي الفيديو جاي منه لازم يكون متأكد في TikTok Developers (URL properties)
 * - قبل موافقة TikTok على الـ App (Audit) النشر بيبقى "لصاحب الحساب بس" (SELF_ONLY)،
 *   فبنسأل TikTok عن الخصوصيات المسموحة للحساب ونختار العامة لو متاحة
 * - التوكن بيخلص كل 24 ساعة؛ refresh() بتجدده بالـ refresh_token (صالح سنة)
 * @version 2.0.0
 */
class TikTokAPI
{
    public const API = 'https://open.tiktokapis.com';
    public const SCOPES = 'user.info.basic,video.publish';

    private string $accessToken = '';
    private string $openId = '';

    /**
     * @var callable|null حقنة اختيارية للاختبارات - بتستقبل ['method','url','headers','body']
     * وترجع ['body'=>string,'http_code'=>int,'error'=>?string] بدل curl.
     */
    private $transport;

    public function __construct(string $accessToken, string $openId, ?callable $transport = null)
    {
        $this->accessToken = $accessToken;
        $this->openId = $openId;
        $this->transport = $transport;
    }

    /** الخصوصيات المسموحة للحساب + أقصى مدة فيديو */
    public function creatorInfo(): array
    {
        return $this->request('POST', self::API . '/v2/post/publish/creator_info/query/', []);
    }

    /**
     * نشر فيديو عبر رابط عام.
     * @param string $privacyLevel 'auto' = عام لو مسموح، غير كده أول خيار متاح
     * @return array ['success'=>bool, 'publish_id'=>?string, 'privacy'=>?string, 'error'=>?string]
     */
    public function publishVideo(string $videoUrl, string $title, string $privacyLevel = 'auto'): array
    {
        $info = $this->creatorInfo();
        if (!$info['success']) {
            return $info;
        }
        $options = (array) ($info['data']['privacy_level_options'] ?? []);
        $privacy = strtoupper($privacyLevel);
        if ($privacyLevel === 'auto' || $privacyLevel === 'public' || !in_array($privacy, $options, true)) {
            $privacy = in_array('PUBLIC_TO_EVERYONE', $options, true) ? 'PUBLIC_TO_EVERYONE' : (string) ($options[0] ?? 'SELF_ONLY');
        }
        $result = $this->request('POST', self::API . '/v2/post/publish/video/init/', [
            'post_info' => [
                'title' => mb_substr($title, 0, 2200),
                'privacy_level' => $privacy,
                'disable_duet' => !empty($info['data']['duet_disabled']),
                'disable_comment' => !empty($info['data']['comment_disabled']),
                'disable_stitch' => !empty($info['data']['stitch_disabled']),
            ],
            'source_info' => ['source' => 'PULL_FROM_URL', 'video_url' => $videoUrl],
        ]);
        if ($result['success']) {
            $result['publish_id'] = $result['data']['publish_id'] ?? null;
            $result['privacy'] = $privacy;
        }
        return $result;
    }

    /**
     * حالة النشر (غير متزامن).
     * @return array ['success'=>bool, 'status'=>PENDING|PUBLISHED|FAILED, 'error'=>?string]
     */
    public function checkPublishStatus(string $publishId): array
    {
        $result = $this->request('POST', self::API . '/v2/post/publish/status/fetch/', ['publish_id' => $publishId]);
        if (!$result['success']) {
            return $result;
        }
        $raw = (string) ($result['data']['status'] ?? '');
        $status = $raw === 'PUBLISH_COMPLETE' || $raw === 'SEND_TO_USER_INBOX' ? 'PUBLISHED' : ($raw === 'FAILED' ? 'FAILED' : 'PENDING');
        return ['success' => true, 'status' => $status, 'raw_status' => $raw, 'error' => $result['data']['fail_reason'] ?? null];
    }

    /** اسم الحساب وصورته (عشان العميل يشوف إنه ربط الحساب الصح) */
    public function userInfo(): array
    {
        $r = $this->request('GET', self::API . '/v2/user/info/?fields=open_id,display_name,avatar_url', null);
        if ($r['success']) {
            $r['user'] = $r['data']['user'] ?? [];
        }
        return $r;
    }

    // ------------------------------------------------------------ OAuth

    /** كود الموافقة => توكن. @return array{success:bool, access_token?:string, refresh_token?:string, open_id?:string, expires_in?:int, scope?:string, error?:string} */
    public static function exchangeCode(string $clientKey, string $clientSecret, string $code, string $redirectUri, ?callable $transport = null): array
    {
        return self::tokenCall(['client_key' => $clientKey, 'client_secret' => $clientSecret, 'code' => $code, 'grant_type' => 'authorization_code', 'redirect_uri' => $redirectUri], $transport);
    }

    public static function refresh(string $clientKey, string $clientSecret, string $refreshToken, ?callable $transport = null): array
    {
        return self::tokenCall(['client_key' => $clientKey, 'client_secret' => $clientSecret, 'grant_type' => 'refresh_token', 'refresh_token' => $refreshToken], $transport);
    }

    private static function tokenCall(array $form, ?callable $transport): array
    {
        $api = new self('', '', $transport);
        $res = $api->httpRequest('POST', self::API . '/v2/oauth/token/', ['Content-Type: application/x-www-form-urlencoded', 'Cache-Control: no-cache'], http_build_query($form));
        if ($res['error']) {
            return ['success' => false, 'error' => 'Network error: ' . $res['error']];
        }
        $d = json_decode($res['body'], true);
        if (!is_array($d) || empty($d['access_token'])) {
            return ['success' => false, 'error' => (string) ($d['error_description'] ?? $d['error'] ?? ('TikTok token error (HTTP ' . $res['http_code'] . ')'))];
        }
        return ['success' => true] + $d;
    }

    // ------------------------------------------------------------ HTTP

    protected function request(string $method, string $url, ?array $data = []): array
    {
        try {
            $headers = ['Authorization: Bearer ' . $this->accessToken];
            $body = null;
            if ($method === 'POST') {
                $headers[] = 'Content-Type: application/json; charset=UTF-8';
                $body = json_encode($data ?: new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $result = $this->httpRequest($method, $url, $headers, $body);
            if ($result['error']) {
                return ['success' => false, 'error' => 'cURL Error: ' . $result['error'], 'outcome_state' => 'uncertain'];
            }
            $decoded = json_decode($result['body'], true);
            $code = $decoded['error']['code'] ?? 'ok';
            if ($result['http_code'] < 200 || $result['http_code'] >= 300 || !in_array($code, ['ok', 0, '0'], true)) {
                return [
                    'success' => false,
                    'error' => self::friendly((string) $code, (string) ($decoded['error']['message'] ?? "TikTok API error (HTTP {$result['http_code']})")),
                    'error_code' => (string) $code,
                    'outcome_state' => $result['http_code'] >= 500 ? 'uncertain' : 'failed',
                ];
            }
            return ['success' => true, 'data' => $decoded['data'] ?? []];
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('TikTok API request failed', ['url' => $url, 'error' => $e->getMessage()]);
            }
            return ['success' => false, 'error' => $e->getMessage(), 'outcome_state' => 'uncertain'];
        }
    }

    private static function friendly(string $code, string $message): string
    {
        return match ($code) {
            'access_token_invalid', 'token_expired' => 'ربط تيك توك انتهى - اربط الحساب تاني من صفحة السوشيال.',
            'scope_not_authorized' => 'الحساب ماوافقش على صلاحية النشر - اربط تيك توك تاني ووافق على كل الصلاحيات.',
            'url_ownership_unverified' => 'دومين الفيديو مش متأكد في تيك توك (URL properties) - كلّم الدعم.',
            'spam_risk_too_many_posts', 'spam_risk_user_banned_from_posting' => 'تيك توك موقف النشر مؤقتًا للحساب ده (كتر النشر). جرّب بعد شوية.',
            'unaudited_client_can_only_post_to_private_accounts' => 'تطبيق Tourfecto لسه تحت مراجعة تيك توك: الحساب لازم يكون Private مؤقتًا لحد الموافقة.',
            'privacy_level_option_mismatch' => 'إعداد الخصوصية مش مسموح للحساب ده.',
            default => $message,
        };
    }

    /** @return array ['body'=>string,'http_code'=>int,'error'=>?string] */
    private function httpRequest(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if ($this->transport !== null) {
            $fake = call_user_func($this->transport, ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body]);
            return [
                'body' => (string) ($fake['body'] ?? ''),
                'http_code' => (int) ($fake['http_code'] ?? 0),
                'error' => isset($fake['error']) ? (string) $fake['error'] : null,
            ];
        }
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        return ['body' => (string) $response, 'http_code' => (int) $httpCode, 'error' => $curlError ?: null];
    }
}
