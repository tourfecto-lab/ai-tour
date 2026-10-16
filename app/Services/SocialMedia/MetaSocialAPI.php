<?php

/**
 * Tourfecto - Meta Social Publishing API Client
 * نشر منشورات حقيقية على صفحة فيسبوك وحساب انستجرام العميل عن طريق
 * Meta Graph API الرسمي. نفس تطبيق Meta المستخدم لـ Meta Ads (نفس
 * META_APP_ID/META_APP_SECRET)، بس بصلاحيات إضافية (pages_manage_posts,
 * instagram_content_publish) اتضافت في MetaOAuthClient.
 * @version 1.0.0
 */
class MetaSocialAPI
{
    private string $apiVersion;
    private string $accessToken;

    /**
     * @var callable|null حقنة اختيارية للاختبارات - بتستقبل وصف الطلب
     * ['method','url','headers','body'] وترجع رد محاكى
     * ['body'=>string,'http_code'=>int,'error'=>?string] بدل curl.
     */
    private $transport;

    public function __construct(string $accessToken, ?callable $transport = null)
    {
        $this->apiVersion = env('META_API_VERSION') ?: 'v25.0';
        $this->accessToken = $accessToken;
        $this->transport = $transport;
    }

    /**
     * قائمة صفحات فيسبوك اللي صاحب التوكن أدمن عليها، ومعاها حساب
     * انستجرام بيزنس المربوط بيها لو موجود. كل صفحة برجع معاها
     * page_access_token خاص بيها (مختلف عن توكن المستخدم، وده اللي
     * بيتستخدم فعليًا للنشر).
     * @return array ['success'=>bool, 'pages'=>[['id','name','access_token','instagram_id']], 'error'=>?]
     */
    public function listPages(): array
    {
        $fields = 'id,name,access_token,instagram_business_account{id,username}';

        // 1) الصفحات المباشرة (me/accounts) - مع التصفّح (pagination). قبل كده كنا بناخد
        //    أول دفعة بس (25 صفحة تقريبًا)، فباقي الصفحات كانت بتختفي من قايمة النشر.
        $first = $this->get('me/accounts', ['fields' => $fields, 'limit' => 100]);
        if (!$first['success']) {
            return $first;
        }

        $byId = [];
        $this->collectPages($first, $byId);
        $this->followPaging('me/accounts', ['fields' => $fields, 'limit' => 100], $first, $byId);

        // 2) صفحات الـ Business Portfolio (Business Manager): ما بتظهرش في me/accounts
        //    لو الصفحة مملوكة لبورتفوليو. بنجيبها لو صلاحية business_management موجودة -
        //    ولو مش موجودة بنكمّل بالنتيجة اللي فوق من غير ما نفشل.
        $biz = $this->get('me/businesses', ['fields' => 'id,name', 'limit' => 50]);
        if ($biz['success']) {
            foreach (($biz['data']['data'] ?? []) as $b) {
                $bizId = $b['id'] ?? null;
                if (!$bizId) {
                    continue;
                }
                foreach (['owned_pages', 'client_pages'] as $edge) {
                    $q = ['fields' => $fields, 'limit' => 100];
                    $res = $this->get("{$bizId}/{$edge}", $q);
                    if (!$res['success']) {
                        continue;
                    }
                    $this->collectPages($res, $byId);
                    $this->followPaging("{$bizId}/{$edge}", $q, $res, $byId);
                }
            }
        }

        // 3) أي صفحة ملهاش page token لسه: نحاول نجيبه مباشرة بتوكن المستخدم
        foreach ($byId as $id => $page) {
            if ($page['access_token'] === '') {
                $tok = $this->get((string) $id, ['fields' => 'access_token']);
                if ($tok['success'] && !empty($tok['data']['access_token'])) {
                    $byId[$id]['access_token'] = (string) $tok['data']['access_token'];
                }
            }
        }

        return ['success' => true, 'pages' => array_values($byId)];
    }

    /** يضيف صفحات رد Graph API لمصفوفة الصفحات (من غير تكرار حسب الـ id) */
    private function collectPages(array $result, array &$byId): void
    {
        foreach (($result['data']['data'] ?? []) as $p) {
            $id = $p['id'] ?? null;
            if (!$id) {
                continue;
            }
            $row = [
                'id' => $id,
                'name' => $p['name'] ?? $id,
                'access_token' => $p['access_token'] ?? '',
                'instagram_id' => $p['instagram_business_account']['id'] ?? null,
                'instagram_username' => $p['instagram_business_account']['username'] ?? null,
            ];
            if (!isset($byId[$id])) {
                $byId[$id] = $row;
                continue;
            }
            // ندمج: نحتفظ بالتوكن وحساب انستجرام لو الدفعة الجديدة فيها حاجة ناقصة
            foreach (['access_token', 'instagram_id', 'instagram_username'] as $f) {
                if (empty($byId[$id][$f]) && !empty($row[$f])) {
                    $byId[$id][$f] = $row[$f];
                }
            }
        }
    }

    /** يكمّل صفحات النتائج (paging.next) لحد الآخر بحد أقصى 20 دفعة */
    private function followPaging(string $path, array $query, array $last, array &$byId): void
    {
        $guard = 0;
        while (!empty($last['data']['paging']['next']) && !empty($last['data']['paging']['cursors']['after']) && $guard++ < 20) {
            $query['after'] = $last['data']['paging']['cursors']['after'];
            $last = $this->get($path, $query);
            if (!$last['success']) {
                return;
            }
            $this->collectPages($last, $byId);
        }
    }

    /**
     * نشر منشور نصي (مع صورة اختيارية) على صفحة فيسبوك.
     * @param string $pageId
     * @param string $pageAccessToken توكن الصفحة (مش توكن المستخدم العام)
     * @param string $message نص المنشور
     * @param string|null $imageUrl رابط صورة عام (لازم يكون accessible من الإنترنت، مش رابط محلي)
     * @return array ['success'=>bool, 'post_id'=>?string, 'post_url'=>?string, 'error'=>?string]
     */
    public function publishToFacebookPage(string $pageId, string $pageAccessToken, string $message, ?string $imageUrl = null): array
    {
        $endpoint = $imageUrl ? "{$pageId}/photos" : "{$pageId}/feed";
        $payload = $imageUrl
            ? ['url' => $imageUrl, 'caption' => $message]
            : ['message' => $message];

        $result = $this->post($endpoint, $payload, $pageAccessToken);

        if (!$result['success']) {
            return $result;
        }

        $postId = $result['data']['post_id'] ?? $result['data']['id'] ?? null;

        return [
            'success' => true,
            'post_id' => $postId,
            'post_url' => $postId ? "https://www.facebook.com/{$postId}" : null,
        ];
    }

    /**
     * نشر صورة على انستجرام. خطوتين حسب Instagram Graph API الرسمي:
     * 1) إنشاء "media container" برابط الصورة والكابشن.
     * 2) نشر الـ container ده فعليًا.
     * @param string $igUserId معرف حساب انستجرام بيزنس (instagram_business_account.id)
     * @param string $pageAccessToken توكن صفحة الفيسبوك المرتبطة (انستجرام بيستخدم نفس توكن الصفحة)
     * @param string $imageUrl رابط صورة عام (مطلوب - انستجرام مش بيقبل نشر نص بس من غير صورة)
     * @param string $caption
     */
    public function publishToInstagram(string $igUserId, string $pageAccessToken, string $imageUrl, string $caption = ''): array
    {
        $containerResult = $this->post("{$igUserId}/media", [
            'image_url' => $imageUrl,
            'caption' => $caption,
        ], $pageAccessToken);

        if (!$containerResult['success']) {
            return $containerResult;
        }

        $containerId = $containerResult['data']['id'] ?? null;
        if (!$containerId) {
            return ['success' => false, 'error' => 'تعذر إنشاء container الصورة في انستجرام'];
        }

        $publishResult = $this->post("{$igUserId}/media_publish", [
            'creation_id' => $containerId,
        ], $pageAccessToken);

        if (!$publishResult['success']) {
            return $publishResult;
        }

        $postId = $publishResult['data']['id'] ?? null;

        return ['success' => true, 'post_id' => $postId, 'post_url' => null];
    }

    /**
     * نشر فيديو على صفحة فيسبوك عن طريق رابط عام (file_url) - فيسبوك
     * بيسحب الفيديو بنفسه ويعالجه، مفيش رفع bytes مباشر مطلوب هنا.
     * @param string $videoUrl رابط فيديو عام (mp4) - لازم يكون accessible من الإنترنت
     * @return array ['success'=>bool, 'post_id'=>?string, 'post_url'=>?string, 'error'=>?string]
     */
    public function publishVideoToFacebookPage(string $pageId, string $pageAccessToken, string $message, string $videoUrl): array
    {
        $result = $this->post("{$pageId}/videos", [
            'file_url' => $videoUrl,
            'description' => $message,
        ], $pageAccessToken);

        if (!$result['success']) {
            return $result;
        }

        $postId = $result['data']['id'] ?? null;

        return [
            'success' => true,
            'post_id' => $postId,
            'post_url' => $postId ? "https://www.facebook.com/{$pageId}/videos/{$postId}" : null,
        ];
    }

    /**
     * إنشاء "container" فيديو Reels في انستجرام - مرحلة أولى فقط.
     * انستجرام بيحتاج وقت لمعالجة الفيديو (async)، فمفيش نشر فوري هنا؛
     * لازم فحص checkInstagramContainerStatus() لحد ما status_code
     * يبقى FINISHED قبل النداء على publishInstagramContainer().
     * @return array ['success'=>bool, 'container_id'=>?string, 'error'=>?string]
     */
    public function createInstagramVideoContainer(string $igUserId, string $pageAccessToken, string $videoUrl, string $caption = ''): array
    {
        $result = $this->post("{$igUserId}/media", [
            'media_type' => 'REELS',
            'video_url' => $videoUrl,
            'caption' => $caption,
        ], $pageAccessToken);

        if (!$result['success']) {
            return $result;
        }

        $containerId = $result['data']['id'] ?? null;
        if (!$containerId) {
            return ['success' => false, 'error' => 'تعذر إنشاء container الفيديو في انستجرام'];
        }

        return ['success' => true, 'container_id' => $containerId];
    }

    /**
     * فحص حالة معالجة container الفيديو في انستجرام.
     * @return array ['success'=>bool, 'status'=>?string (IN_PROGRESS|FINISHED|ERROR|EXPIRED), 'error'=>?string]
     */
    public function checkInstagramContainerStatus(string $containerId, string $pageAccessToken): array
    {
        $result = $this->request('GET', $containerId, ['fields' => 'status_code', 'access_token' => $pageAccessToken]);

        if (!$result['success']) {
            return $result;
        }

        return ['success' => true, 'status' => $result['data']['status_code'] ?? 'IN_PROGRESS'];
    }

    /**
     * نشر container (صورة أو فيديو) بعد التأكد إنه جاهز.
     * @return array ['success'=>bool, 'post_id'=>?string, 'error'=>?string]
     */
    public function publishInstagramContainer(string $igUserId, string $pageAccessToken, string $containerId): array
    {
        $publishResult = $this->post("{$igUserId}/media_publish", [
            'creation_id' => $containerId,
        ], $pageAccessToken);

        if (!$publishResult['success']) {
            return $publishResult;
        }

        return ['success' => true, 'post_id' => $publishResult['data']['id'] ?? null];
    }

    /**
     * إحصائيات تفاعل منشور منشور (لايكات/تعليقات/مشاركات/وصول) من Meta Graph.
     * الوصول (reach) محتاج صلاحية read_insights - لو مش متاحة بيرجع null والباقي يشتغل عادي.
     * @return array ['success'=>bool,'likes'=>int,'comments'=>int,'shares'=>int,'reach'=>?int,'permalink'=>?string,'error'=>?string]
     */
    public function getPostMetrics(string $platform, string $postId, string $pageAccessToken): array
    {
        $api = new self($pageAccessToken, $this->transport);
        $out = ['success' => false, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'reach' => null, 'permalink' => null];

        if ($platform === 'instagram') {
            $r = $api->get($postId, ['fields' => 'like_count,comments_count,permalink']);
            if (!$r['success']) {
                return $out + ['error' => $r['error'] ?? 'تعذر جلب إحصائيات إنستجرام'];
            }
            $d = $r['data'];
            $out['likes'] = (int) ($d['like_count'] ?? 0);
            $out['comments'] = (int) ($d['comments_count'] ?? 0);
            $out['permalink'] = $d['permalink'] ?? null;
            $ins = $api->get($postId . '/insights', ['metric' => 'reach,shares']);
            if ($ins['success']) {
                foreach (($ins['data']['data'] ?? []) as $m) {
                    $v = $m['values'][0]['value'] ?? ($m['total_value']['value'] ?? null);
                    if (($m['name'] ?? '') === 'reach' && $v !== null) {
                        $out['reach'] = (int) $v;
                    }
                    if (($m['name'] ?? '') === 'shares' && $v !== null) {
                        $out['shares'] = (int) $v;
                    }
                }
            }
            $out['success'] = true;
            return $out;
        }

        // facebook
        $r = $api->get($postId, ['fields' => 'likes.summary(true).limit(0),comments.summary(true).limit(0),shares,permalink_url']);
        if (!$r['success']) {
            return $out + ['error' => $r['error'] ?? 'تعذر جلب إحصائيات فيسبوك'];
        }
        $d = $r['data'];
        $out['likes'] = (int) ($d['likes']['summary']['total_count'] ?? 0);
        $out['comments'] = (int) ($d['comments']['summary']['total_count'] ?? 0);
        $out['shares'] = (int) ($d['shares']['count'] ?? 0);
        $out['permalink'] = $d['permalink_url'] ?? null;
        $ins = $api->get($postId . '/insights', ['metric' => 'post_impressions_unique']);
        if ($ins['success']) {
            $v = $ins['data']['data'][0]['values'][0]['value'] ?? null;
            if ($v !== null && !is_array($v)) {
                $out['reach'] = (int) $v;
            }
        }
        $out['success'] = true;
        return $out;
    }

    /**
     * يشترك بالصفحة في تطبيق المنصة عشان رسائل Messenger وInstagram توصل
     * على الـ webhook العام. بيحتاج صلاحية pages_messaging.
     */
    public function subscribeMessaging(string $pageId, string $pageAccessToken): array
    {
        return $this->post($pageId . '/subscribed_apps', ['subscribed_fields' => 'messages,messaging_postbacks'], $pageAccessToken);
    }

    /** إلغاء اشتراك الصفحة: رسائلها مش هتوصل للمنصة تاني */
    public function unsubscribeMessaging(string $pageId, string $pageAccessToken): array
    {
        return $this->request('DELETE', $pageId . '/subscribed_apps', ['access_token' => $pageAccessToken]);
    }

    private function get(string $path, array $query = []): array
    {
        $query['access_token'] = $this->accessToken;
        return $this->request('GET', $path, $query);
    }

    private function post(string $path, array $data, ?string $tokenOverride = null): array
    {
        $data['access_token'] = $tokenOverride ?? $this->accessToken;
        return $this->request('POST', $path, [], $data);
    }

    private function request(string $method, string $path, array $query = [], array $data = []): array
    {
        try {
            $url = "https://graph.facebook.com/{$this->apiVersion}/{$path}";
            if (!empty($query)) {
                $url .= '?' . http_build_query($query);
            }

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

            if ($httpCode < 200 || $httpCode >= 300 || isset($decoded['error'])) {
                return [
                    'success' => false,
                    'error' => $decoded['error']['message'] ?? "Meta API error (HTTP {$httpCode})",
                ];
            }

            return ['success' => true, 'data' => $decoded];
        } catch (Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('Meta Social API request failed', ['path' => $path, 'error' => $e->getMessage()]);
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
            CURLOPT_TIMEOUT => 30,
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

        return [
            'body' => (string) $response,
            'http_code' => (int) $httpCode,
            'error' => $curlError ?: null,
        ];
    }
}
