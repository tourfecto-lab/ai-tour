<?php

/**
 * Tourfecto - TikTok Content Posting API Client
 * نشر فيديوهات قصيرة على TikTok عبر TikTok Content Posting API.
 * يستخدم PULL_FROM_URL (فيديو مولد من Creative Studio برابط عام).
 * @version 1.0.0
 */
class TikTokAPI
{
    private string $accessToken = '';
    private string $openId = '';

    /**
     * @var callable|null حقنة اختيارية للاختبارات - بتستقبل وصف الطلب
     * ['method','url','headers','body'] وترجع رد محاكى
     * ['body'=>string,'http_code'=>int,'error'=>?string] بدل curl.
     */
    private $transport;

    public function __construct(string $accessToken, string $openId, ?callable $transport = null)
    {
        $this->accessToken = $accessToken;
        $this->openId = $openId;
        $this->transport = $transport;
    }

    /**
     * نشر فيديو على TikTok عبر رابط عام (PULL_FROM_URL).
     * @param string $videoUrl رابط فيديو عام (mp4) - لازم يكون accessible
     * @param string $title عنوان/وصف الفيديو
     * @param string $privacyLevel 'public' | 'private' | 'mutual_follow_friend'
     * @return array ['success'=>bool, 'publish_id'=>?string, 'error'=>?string]
     */
    public function publishVideo(string $videoUrl, string $title, string $privacyLevel = 'public'): array
    {
        $endpoint = 'https://open-api.tiktok.com/share/video/upload/';

        $payload = [
            'access_token' => $this->accessToken,
            'open_id'      => $this->openId,
            'source_info'  => json_encode([
                'source' => 'PULL_FROM_URL',
                'url'    => $videoUrl,
            ]),
            'title'         => $title,
            'privacy_level' => $privacyLevel,
            'disable_duet'  => false,
            'disable_comment' => false,
            'disable_stitch'  => false,
        ];

        $result = $this->post($endpoint, $payload);

        // رفع publish_id للمستوى الأعلى - المستهلكين (Job/تحكم) بيعتمدوا
        // على result['publish_id'] مباشرة مش متداخل جوه data.
        if ($result['success']) {
            $data = $result['data'] ?? [];
            if (is_array($data) && array_key_exists('publish_id', $data)) {
                $result['publish_id'] = $data['publish_id'];
            }
        }

        return $result;
    }

    /**
     * فحص حالة نشر الفيديو (غير متزامن).
     * @return array ['success'=>bool, 'status'=>?string, 'error'=>?string]
     */
    public function checkPublishStatus(string $publishId): array
    {
        $endpoint = 'https://open-api.tiktok.com/share/video/upload/check/';
        $result = $this->get($endpoint, ['publish_id' => $publishId]);

        if (!$result['success']) {
            return $result;
        }

        $data = $result['data']['data'] ?? [];
        return [
            'success' => true,
            'status'  => $data['status'] ?? 'unknown', // PENDING | PUBLISHED | FAILED
            'error'   => $data['fail_reason'] ?? null,
        ];
    }

    private function get(string $url, array $query = []): array
    {
        $query['access_token'] = $this->accessToken;
        $query['open_id'] = $this->openId;
        return $this->request('GET', $url . '?' . http_build_query($query));
    }

    private function post(string $url, array $data = []): array
    {
        return $this->request('POST', $url, $data);
    }

    protected function request(string $method, string $url, array $data = []): array
    {
        try {
            $headers = [];
            $body = null;
            if ($method === 'POST') {
                $headers = ['Content-Type: application/x-www-form-urlencoded'];
                $body = http_build_query($data);
            }

            $result = $this->httpRequest($method, $url, $headers, $body);

            if ($result['error']) {
                return ['success' => false, 'error' => 'cURL Error: ' . $result['error']];
            }

            $decoded = json_decode($result['body'], true);
            $httpCode = $result['http_code'];

            if ($httpCode < 200 || $httpCode >= 300 || ($decoded['error']['code'] ?? 0) !== 0) {
                return [
                    'success' => false,
                    'error'   => $decoded['error']['message'] ?? "TikTok API error (HTTP {$httpCode})",
                ];
            }

            return ['success' => true, 'data' => $decoded['data'] ?? $decoded];
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('TikTok API request failed', ['url' => $url, 'error' => $e->getMessage()]);
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * تنفيذ طلب HTTP عبر الـ transport الوهمي (لو محقون) أو curl العادي.
     * نفس سلوك curl السابق بالظبط - لا تغيير في الإنتاج.
     * @return array ['body'=>string,'http_code'=>int,'error'=>?string]
     */
    private function httpRequest(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        if ($this->transport !== null) {
            $fake = call_user_func($this->transport, [
                'method' => $method,
                'url' => $url,
                'headers' => $headers,
                'body' => $body,
            ]);
            return [
                'body' => (string) ($fake['body'] ?? ''),
                'http_code' => (int) ($fake['http_code'] ?? 0),
                'error' => isset($fake['error']) ? (string) $fake['error'] : null,
            ];
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
            $options[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        return [
            'body' => (string) $response,
            'http_code' => (int) $httpCode,
            'error' => $curlError ?: null,
        ];
    }
}
