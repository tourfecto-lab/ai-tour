<?php

/**
 * Tourfecto - ربط واتساب الرسمي (Cloud API) لكل شركة برقمها
 *
 * GET  /api/whatsapp-cloud/status?website_id=     حالة الربط + رابط الـ webhook + حالة القوالب
 * POST /api/whatsapp-cloud/connect                الربط (بيتحقق من البيانات مع Meta قبل الحفظ)
 * POST /api/whatsapp-cloud/disconnect/{website_id}
 * GET  /api/whatsapp-cloud/templates?website_id=  مزامنة حالة القوالب من Meta
 * POST /api/whatsapp-cloud/templates              إنشاء القوالب الناقصة في حساب الشركة
 * POST /api/whatsapp-cloud/test                   رسالة تجربة
 * GET/POST /api/whatsapp-cloud/webhook/{website_id}?secret=   (من Meta - من غير تسجيل دخول)
 */
require_once dirname(__DIR__).'/Services/Chat/WhatsAppWebhookInbox.php';

class WhatsAppCloudController extends Controller
{
    // ------------------------------------------------------------------ أسرار مشتقة لكل موقع

    private static function key(): string
    {
        return (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY) ? (string) ENCRYPTION_KEY : (defined('SALT') ? (string) SALT : 'tourfecto');
    }

    public static function webhookSecret(int $websiteId): string
    {
        return substr(hash_hmac('sha256', 'wa-cloud-webhook:' . $websiteId, self::key()), 0, 24);
    }

    public static function verifyToken(int $websiteId): string
    {
        return 'tf_' . substr(hash_hmac('sha256', 'wa-cloud-verify:' . $websiteId, self::key()), 0, 20);
    }

    /** Verify token للـ webhook العام (تطبيق المنصة - الربط بضغطة) */
    public static function platformVerifyToken(): string
    {
        return 'tfp_' . substr(hash_hmac('sha256', 'wa-platform-verify', self::key()), 0, 24);
    }

    public static function webhookUrl(int $websiteId): string
    {
        $base = rtrim(defined('APP_URL') ? (string) APP_URL : '', '/');
        return $base . '/api/whatsapp-cloud/webhook/' . $websiteId . '?secret=' . self::webhookSecret($websiteId);
    }

    /** الموقع لازم يكون بتاع المستخدم */
    private function ownedWebsite(int $websiteId): ?array
    {
        if ($websiteId <= 0) {
            return null;
        }
        $r = $this->db->query('SELECT id, user_id, company_name FROM websites WHERE id = ? LIMIT 1', [$websiteId]);
        if (!$r || (int) $r[0]['user_id'] !== (int) ($this->user['id'] ?? 0)) {
            return null;
        }
        return $r[0];
    }

    private function conn(int $websiteId): ?array
    {
        return WhatsAppCloudAPI::connectionRow($websiteId);
    }

    private function templatesView(?array $row): array
    {
        $st = $row ? WhatsAppTemplates::statuses($row) : [];
        $meta = $row ? (json_decode((string) ($row['meta_json'] ?? ''), true) ?: []) : [];
        $out = [];
        foreach (WhatsAppTemplates::DEFS as $name => $d) {
            $out[] = ['name' => $name, 'label' => $d['label'], 'category' => $d['category'], 'params' => $d['params'],
                'ar' => $st[$name]['ar'] ?? null, 'en' => $st[$name]['en'] ?? null,
                'rejected' => $meta['rejected'][$name] ?? null, 'text_ar' => $d['ar']['text'], 'text_en' => $d['en']['text']];
        }
        return $out;
    }

    // ------------------------------------------------------------------ لوحة العميل

    public function status(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        $wid = (int) $this->get('website_id');
        if (!$this->ownedWebsite($wid)) {
            return $this->error('الموقع غير موجود', 404);
        }
        $row = $this->conn($wid);
        $meta = $row ? (json_decode((string) ($row['meta_json'] ?? ''), true) ?: []) : [];
        $ultra = (bool) $this->db->query("SELECT id FROM platform_connections WHERE website_id = ? AND platform = 'ultramsg' AND status = 'connected' LIMIT 1", [$wid]);
        return $this->success([
            'connected' => (bool) $row,
            'phone_number_id' => $row['external_account_id'] ?? null,
            'waba_id' => $row['external_location_id'] ?? null,
            'display_phone' => $meta['display_phone'] ?? null,
            'verified_name' => $meta['verified_name'] ?? null,
            'quality' => $meta['quality'] ?? null,
            'has_app_secret' => $row ? !empty($row['refresh_token']) : false,
            'webhook_secure' => $row ? $this->connectionSecret($row) !== '' : false,
            'webhook_url' => self::webhookUrl($wid),
            'verify_token' => self::verifyToken($wid),
            'templates' => $this->templatesView($row),
            'templates_checked_at' => $meta['templates_checked_at'] ?? null,
            'ultramsg_connected' => $ultra,
            'last_webhook_at' => $meta['last_webhook_at'] ?? null,
            'embedded' => !empty($meta['embedded']),
            'register_error' => $meta['register_error'] ?? null,
        ]);
    }

    public function connect(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        $wid = (int) $this->get('website_id');
        if (!$this->ownedWebsite($wid)) {
            return $this->error('الموقع غير موجود', 404);
        }
        $pnid = preg_replace('/\D/', '', (string) $this->get('phone_number_id', ''));
        $waba = preg_replace('/\D/', '', (string) $this->get('waba_id', ''));
        $token = trim((string) $this->get('access_token', ''));
        $appSecret = trim((string) $this->get('app_secret', ''));
        if ($pnid === '' || $waba === '' || $token === '') {
            return $this->error('Phone Number ID و WhatsApp Business Account ID والتوكن مطلوبين', 422);
        }
        if (!preg_match('/^[a-f0-9]{32}$/i', $appSecret)) {
            return $this->error('App Secret مطلوب لتأمين استقبال الرسائل (32 حرف من 0-9 و a-f)', 422);
        }

        $api = new WhatsAppCloudAPI($pnid, $token, $waba);
        $info = $api->phoneInfo();
        if (!$info['ok']) {
            return $this->error('Meta رفضت البيانات: ' . $info['error'], 422);
        }
        $tpl = $api->listTemplates();
        if (!$tpl['ok']) {
            return $this->error('الرقم صح بس الـ WABA ID أو صلاحيات التوكن غلط (محتاج whatsapp_business_management): ' . $tpl['error'], 422);
        }
        $sub = $api->subscribeApp();

        $connId = $this->saveConnection($wid, $pnid, $waba, $token, $appSecret, $info, ['embedded' => false]);
        WhatsAppTemplates::sync($api, $connId);
        if (class_exists('ActivityLog')) {
            ActivityLog::record('chat', 'whatsapp_cloud.connected', ['user_id' => (int) $this->user['id'], 'subject_type' => 'websites', 'subject_id' => $wid, 'meta' => ['phone' => $info['display_phone']]]);
        }
        return $this->success([
            'display_phone' => $info['display_phone'], 'verified_name' => $info['verified_name'],
            'webhook_url' => self::webhookUrl($wid), 'verify_token' => self::verifyToken($wid),
            'subscribed' => $sub['ok'], 'subscribe_error' => $sub['ok'] ? null : $sub['error'],
        ], 'اتربط رقم ' . $info['display_phone'] . ' ✓');
    }

    private function connectionSecret(array $row): string
    {
        if (!empty($row['refresh_token'])) {
            try { return (new Encryption())->decrypt((string)$row['refresh_token']); }
            catch (Throwable $e) { return ''; }
        }
        $meta=json_decode((string)($row['meta_json']??''),true)?:[];
        // Only embedded connections belong to the platform Meta application.
        return !empty($meta['embedded']) ? MetaOAuthClient::setting('meta_app_secret','META_APP_SECRET') : '';
    }

    public function deliveryStatuses(array $params=[]): array
    {
        $wid=(int)$this->get('website_id');
        if (!$this->isAuthenticated() || !$this->ownedWebsite($wid)) return $this->error('غير مصرح',403);
        return $this->success(['statuses'=>$this->db->query('SELECT wamid,status,error_message,event_timestamp,updated_at FROM whatsapp_delivery_statuses WHERE website_id=? AND user_id=? ORDER BY updated_at DESC,id DESC LIMIT 20',[$wid,(int)$this->user['id']])]);
    }

    /** حفظ/تحديث الاتصال (مشترك بين الربط اليدوي والربط بضغطة) @return int connection id */
    private function saveConnection(int $wid, string $pnid, string $waba, string $token, string $appSecret, array $info, array $extraMeta = []): int
    {
        $enc = new Encryption();
        $meta = array_merge(['display_phone' => $info['display_phone'], 'verified_name' => $info['verified_name'], 'quality' => $info['quality'], 'connected_by' => (int) $this->user['id']], $extraMeta);
        $existing = $this->db->query("SELECT id, meta_json FROM platform_connections WHERE website_id = ? AND platform = 'whatsapp_cloud' LIMIT 1", [$wid]);
        $name = mb_substr(trim(($info['verified_name'] ?: 'WhatsApp') . ' · ' . $info['display_phone']), 0, 480);
        $secretEnc = $appSecret !== '' ? $enc->encrypt($appSecret) : null;
        if ($existing) {
            $old = json_decode((string) ($existing[0]['meta_json'] ?? ''), true) ?: [];
            $this->db->query(
                "UPDATE platform_connections SET user_id = ?, access_token = ?, refresh_token = ?, external_account_id = ?, external_location_id = ?, external_location_name = ?, status = 'connected', last_error = NULL, connected_at = NOW(), meta_json = ? WHERE id = ?",
                [(int) $this->user['id'], $enc->encrypt($token), $secretEnc, $pnid, $waba, $name, json_encode(array_merge($old, $meta), JSON_UNESCAPED_UNICODE), (int) $existing[0]['id']]
            );
            return (int) $existing[0]['id'];
        }
        return (int) $this->db->query(
            "INSERT INTO platform_connections (website_id, user_id, platform, access_token, refresh_token, external_account_id, external_location_id, external_location_name, status, connected_at, meta_json)
             VALUES (?, ?, 'whatsapp_cloud', ?, ?, ?, ?, ?, 'connected', NOW(), ?)",
            [$wid, (int) $this->user['id'], $enc->encrypt($token), $secretEnc, $pnid, $waba, $name, json_encode($meta, JSON_UNESCAPED_UNICODE)]
        );
    }

    /** GET /api/whatsapp-cloud/embedded-config - بيانات زرار "اربط بضغطة" (لو الأدمن ظبطها) */
    public function embeddedConfig(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        $appId = MetaOAuthClient::setting('meta_app_id', 'META_APP_ID');
        $cfg = preg_replace('/\D/', '', MetaOAuthClient::setting('meta_wa_config_id', 'META_WA_CONFIG_ID'));
        $ok = $appId !== '' && $cfg !== '' && MetaOAuthClient::setting('meta_app_secret', 'META_APP_SECRET') !== '';
        return $this->success(['enabled' => $ok, 'app_id' => $ok ? $appId : null, 'config_id' => $ok ? $cfg : null, 'version' => MetaOAuthClient::apiVersion()]);
    }

    /**
     * POST /api/whatsapp-cloud/embedded {website_id, code, waba_id, phone_number_id}
     * الشركة دخلت بفيسبوك واختارت/عملت رقمها في نافذة Meta - هنا بنكمّل: توكن ← تسجيل الرقم ← الاشتراك في الرسايل ← الحفظ
     */
    public function embedded(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        $wid = (int) $this->get('website_id');
        if (!$this->ownedWebsite($wid)) {
            return $this->error('الموقع غير موجود', 404);
        }
        $code = trim((string) $this->get('code', ''));
        $pnid = preg_replace('/\D/', '', (string) $this->get('phone_number_id', ''));
        $waba = preg_replace('/\D/', '', (string) $this->get('waba_id', ''));
        if ($code === '') {
            return $this->error('الربط اتلغى أو ماكملش - جرّب تاني', 422);
        }
        if ($pnid === '' || $waba === '') {
            return $this->error('Meta مارجعتش الرقم - لازم تكمّل لحد اختيار رقم الواتساب في النافذة', 422);
        }
        $ex = WhatsAppCloudAPI::exchangeCode($code);
        if (!$ex['ok']) {
            return $this->error('تعذر إكمال الربط مع Meta: ' . $ex['error'], 422);
        }
        $api = new WhatsAppCloudAPI($pnid, (string) $ex['token'], $waba);
        $info = $api->phoneInfo();
        if (!$info['ok']) {
            return $this->error('Meta رفضت الرقم: ' . $info['error'], 422);
        }
        $pin = (string) random_int(100000, 999999);
        $reg = $api->registerNumber($pin);
        $sub = $api->subscribeApp();
        $connId = $this->saveConnection($wid, $pnid, $waba, (string) $ex['token'], '', $info, [
            'embedded' => true, 'pin_enc' => (new Encryption())->encrypt($pin), 'registered' => $reg['ok'], 'register_error' => $reg['error'],
        ]);
        WhatsAppTemplates::sync($api, $connId);
        if (class_exists('ActivityLog')) {
            ActivityLog::record('chat', 'whatsapp_cloud.connected', ['user_id' => (int) $this->user['id'], 'subject_type' => 'websites', 'subject_id' => $wid, 'meta' => ['phone' => $info['display_phone'], 'embedded' => true]]);
        }
        $warn = [];
        if (!$reg['ok']) {
            $warn[] = 'تسجيل الرقم: ' . $reg['error'];
        }
        if (!$sub['ok']) {
            $warn[] = 'الاشتراك في الرسايل الواردة: ' . $sub['error'];
        }
        return $this->success(['display_phone' => $info['display_phone'], 'warnings' => $warn], 'اتربط رقم ' . $info['display_phone'] . ' ✓');
    }

    public function disconnect(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        $wid = (int) ($params['website_id'] ?? 0);
        if (!$this->ownedWebsite($wid)) {
            return $this->error('الموقع غير موجود', 404);
        }
        $this->db->query("UPDATE platform_connections SET status = 'disconnected', access_token = NULL, refresh_token = NULL WHERE website_id = ? AND platform = 'whatsapp_cloud'", [$wid]);
        return $this->success([], 'اتفصل الربط');
    }

    public function templates(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        $wid = (int) $this->get('website_id');
        if (!$this->ownedWebsite($wid) || !($row = $this->conn($wid)) || !($api = WhatsAppCloudAPI::forWebsite($wid))) {
            return $this->error('واتساب الرسمي مش مربوط للموقع ده', 404);
        }
        $r = WhatsAppTemplates::sync($api, (int) $row['id']);
        if (!$r['ok']) {
            return $this->error('تعذر جلب القوالب من Meta: ' . $r['error'], 502);
        }
        return $this->success(['templates' => $this->templatesView($this->conn($wid))]);
    }

    public function createTemplates(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        $wid = (int) $this->get('website_id');
        if (!$this->ownedWebsite($wid) || !($row = $this->conn($wid)) || !($api = WhatsAppCloudAPI::forWebsite($wid))) {
            return $this->error('واتساب الرسمي مش مربوط للموقع ده', 404);
        }
        $r = WhatsAppTemplates::createMissing($api, (int) $row['id']);
        $msg = $r['created'] ? 'اتبعت ' . count($r['created']) . ' قالب لمراجعة Meta - الموافقة بتاخد من دقايق لـ 24 ساعة' : 'كل القوالب موجودة بالفعل';
        if ($r['errors']) {
            return $this->error(($r['created'] ? $msg . ' - بس فيه أخطاء: ' : '') . implode(' | ', $r['errors']), 422, ['templates' => $this->templatesView($this->conn($wid))]);
        }
        return $this->success(['templates' => $this->templatesView($this->conn($wid)), 'created' => $r['created']], $msg);
    }

    /** رسالة تجربة: قالب التذكير لو متوافق عليه، وإلا hello_world (موجود في كل حساب جديد) */
    public function test(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('غير مسجل دخول', 401);
        }
        $wid = (int) $this->get('website_id');
        $site = $this->ownedWebsite($wid);
        try{$phone = WhatsAppCloudAPI::normalizePhone((string) $this->get('phone', ''));}catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}
        if (!$site || !($row = $this->conn($wid)) || !($api = WhatsAppCloudAPI::forWebsite($wid))) {
            return $this->error('واتساب الرسمي مش مربوط للموقع ده', 404);
        }
        if (strlen($phone) < 8) {
            return $this->error('اكتب رقم واتساب صحيح بالكود الدولي (مثال 2010xxxxxxxx)', 422);
        }
        $wantLanguage=(string)$this->get('language',ContentLanguageService::forWebsite($wid,(int)$this->user['id']));
        $pick = WhatsAppTemplates::pickLanguage($row, 'tf_trip_reminder', $wantLanguage);
        $st = WhatsAppTemplates::statuses($row)['tf_trip_reminder'] ?? [];
        if ($pick['lang'] && in_array('APPROVED', $st, true)) {
            $co = trim((string) ($site['company_name'] ?? '')) ?: 'Tourfecto';
            $r = $api->sendTemplate($phone, 'tf_trip_reminder', $pick['lang'], $pick['lang'] === 'en' ? ['Test', $co, 'Test trip', 'tomorrow at 09:00'] : ['تجربة', $co, 'رحلة تجريبية', 'بكرة الساعة 09:00']);
            $used = 'tf_trip_reminder';
        } else {
            $r = $api->sendTemplate($phone, 'hello_world', 'en_US');
            $used = 'hello_world';
        }
        if (!$r['ok']) {
            return $this->error('ماوصلتش: ' . $r['error'], 422);
        }
        return $this->success(['template' => $used, 'id' => $r['id']], 'اتبعتت رسالة تجربة (' . $used . ') - شوف الواتساب');
    }

    // ------------------------------------------------------------------ Webhook (من Meta)

    public function webhookVerify(array $params = []): array
    {
        $wid = (int) ($params['website_id'] ?? 0);
        $secret = (string) ($_GET['secret'] ?? '');
        $mode = (string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
        $token = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
        $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
        if ($wid > 0 && hash_equals(self::webhookSecret($wid), $secret) && $mode === 'subscribe' && hash_equals(self::verifyToken($wid), $token)) {
            http_response_code(200);
            header('Content-Type: text/plain');
            echo preg_replace('/[^A-Za-z0-9_\-]/', '', $challenge);
            exit;
        }
        http_response_code(403);
        header('Content-Type: text/plain');
        echo 'Forbidden';
        exit;
    }

    public function webhook(array $params = []): array
    {
        $wid = (int) ($params['website_id'] ?? 0);
        if ($wid <= 0 || !hash_equals(self::webhookSecret($wid), (string) ($_GET['secret'] ?? ''))) {
            return $this->error('Unauthorized webhook', 401);
        }
        $row = $this->db->query("SELECT * FROM platform_connections WHERE website_id = ? AND platform = 'whatsapp_cloud' AND status = 'connected' ORDER BY id DESC LIMIT 1", [$wid])[0] ?? null;
        if (!$row) {
            return $this->success([], 'not connected');
        }
        $raw = (string) file_get_contents('php://input');
        $appSecret = $this->connectionSecret($row);
        if (!WhatsAppWebhookInbox::verifiedSignature($raw, (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256']??''), $appSecret)) {
            return $this->error('Invalid signature or missing App Secret', 401);
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) return $this->error('Invalid JSON', 400);
        return $this->success(self::processPayload($wid, (int) $row['user_id'], (string) $row['external_account_id'], $payload, (int) $row['id']));
    }

    /** GET /api/whatsapp-cloud/webhook - تحقق Meta من الـ webhook العام (تطبيق المنصة) */
    public function platformWebhookVerify(array $params = []): array
    {
        $mode = (string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
        $token = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
        $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
        header('Content-Type: text/plain');
        if ($mode === 'subscribe' && hash_equals(self::platformVerifyToken(), $token)) {
            http_response_code(200);
            echo preg_replace('/[^A-Za-z0-9_\-]/', '', $challenge);
            exit;
        }
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    /**
     * POST /api/whatsapp-cloud/webhook - رسايل كل الشركات اللي ربطت بضغطة (بتيجي على تطبيق المنصة).
     * التوقيع بـ App Secret بتاع المنصة إجباري، والتوجيه للشركة الصح بـ phone_number_id.
     */
    public function platformWebhook(array $params = []): array
    {
        $raw = (string) file_get_contents('php://input');
        $secret = MetaOAuthClient::setting('meta_app_secret', 'META_APP_SECRET');
        $sig = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
        if (!WhatsAppWebhookInbox::verifiedSignature($raw, $sig, $secret)) {
            return $this->error('Invalid signature', 401);
        }
        $payload = json_decode($raw, true);
        if(!is_array($payload))return $this->error('Invalid JSON',400);
        return $this->success(self::routePlatformPayload($payload));
    }

    /** يوزّع دفعة webhook عامة على الشركات حسب phone_number_id */
    public static function routePlatformPayload(array $payload, ?object $chatManager = null): array
    {
        $db = Database::getInstance();
        $total = ['messages' => 0, 'statuses' => 0, 'skipped' => 0, 'unknown' => 0];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $ch) {
                $pn = (string) ($ch['value']['metadata']['phone_number_id'] ?? '');
                $rows = $pn !== '' ? $db->query("SELECT c.id,c.website_id,c.user_id FROM platform_connections c JOIN websites w ON w.id=c.website_id AND w.user_id=c.user_id WHERE c.platform='whatsapp_cloud' AND c.status='connected' AND c.external_account_id=? ORDER BY c.id DESC LIMIT 2",[$pn]) : [];
                $row=count($rows)===1?$rows[0]:null;
                if (!$row) {
                    $total['unknown']++;
                    continue;
                }
                $o = self::processPayload((int) $row['website_id'], (int) $row['user_id'], $pn, ['entry' => [['changes' => [$ch]]]], (int) $row['id'], $chatManager);
                foreach (['messages', 'statuses', 'skipped'] as $k) {
                    $total[$k] += $o[$k];
                }
            }
        }
        return $total;
    }

    /**
     * معالجة دفعة الـ webhook: رسايل واردة ← الشات (ChatManager) · حالات الإرسال ← whatsapp_delivery_statuses (via durable queue)
     * @return array{messages:int, statuses:int, skipped:int}
     */
    public static function processPayload(int $wid, int $userId, string $phoneNumberId, array $payload, int $connId = 0, ?object $chatManager = null): array
    {
        $db = Database::getInstance();
        $out = ['messages' => 0, 'statuses' => 0, 'skipped' => 0];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $ch) {
                $v = $ch['value'] ?? [];
                // رسالة لرقم تاني على نفس الحساب - مش بتاعة الموقع ده
                if (!empty($v['metadata']['phone_number_id']) && $phoneNumberId !== '' && (string) $v['metadata']['phone_number_id'] !== $phoneNumberId) {
                    $out['skipped']++;
                    continue;
                }
                $names = [];
                foreach ((array) ($v['contacts'] ?? []) as $c) {
                    $names[(string) ($c['wa_id'] ?? '')] = (string) ($c['profile']['name'] ?? '');
                }
                foreach ((array) ($v['messages'] ?? []) as $m) {
                    $id = (string) ($m['id'] ?? '');
                    if ($id === '') { $out['skipped']++; continue; }
                    $type = (string) ($m['type'] ?? 'text');
                    $flow=null;if($type==='interactive'&&isset($m['interactive']['nfm_reply'])){$raw=$m['interactive']['nfm_reply']['response_json']??'';if(!is_string($raw)||strlen($raw)>20000||!is_object(json_decode($raw))){$out['skipped']++;continue;}$flow=json_decode($raw,true);}

                    $text = match ($type) {
                        'text' => (string) ($m['text']['body'] ?? ''),
                        'button' => (string) ($m['button']['text'] ?? ''),
                        'interactive' => $flow!==null?'[WhatsApp Flow response] '.json_encode($flow,JSON_UNESCAPED_UNICODE):(string) ($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? ''),
                        'image' => '[Image] ' . (string) ($m['image']['caption'] ?? ''),
                        'video' => '[Video] ' . (string) ($m['video']['caption'] ?? ''),
                        'audio' => '[Voice message]',
                        'document' => '[Document] ' . (string) ($m['document']['filename'] ?? ''),
                        'location' => '[Location] ' . ($m['location']['latitude'] ?? '') . ',' . ($m['location']['longitude'] ?? ''),
                        'sticker' => '[Sticker]',
                        'reaction' => '',
                        default => '[' . $type . ']',
                    };
                    $text = trim($text);
                    $from = (string) ($m['from'] ?? '');
                    if ($text === '' || $from === '') {
                        continue;
                    }
                    $message = [
                        'user_id'=>$userId, 'website_id'=>$wid, 'platform'=>'whatsapp',
                        'phone_number'=>$from, 'sender_name'=>$names[$from]??null,
                        'message'=>$text, 'platform_message_id'=>$id, 'timestamp'=>$m['timestamp']??null,
                    ];
                    if($flow!==null){$message['flow_response']=$flow;$message['flow_reply_to']=(string)($m['context']['id']??'');}
                    if (WhatsAppWebhookInbox::enqueue($wid,$userId,'message',$id,$message)) {
                        $out['messages']++;
                    } else { $out['skipped']++; }

                }
                foreach ((array) ($v['statuses'] ?? []) as $st) {
                    $eventKey=(string)($st['id']??'').'|'.(string)($st['status']??'').'|'.(string)($st['timestamp']??'');
                    if (!empty($st['id']) && WhatsAppWebhookInbox::enqueue($wid,$userId,'delivery',$eventKey,$st)) $out['statuses']++;

                }
            }
        }
        if ($connId > 0) {
            try {
                WhatsAppTemplates::saveMeta($connId, ['last_webhook_at' => gmdate('Y-m-d H:i:s')]);
            } catch (Throwable $e) {
            }
        }
        return $out;
    }
}
