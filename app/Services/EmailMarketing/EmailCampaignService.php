<?php

/**
 * Tourfecto - Email Marketing Campaign Service
 * @version 1.0.0
 *
 * دورة حياة الحملة الكاملة:
 *   - إنشاء/تحديث/حذف + استرجاع الحملات (عزل تام لكل مستخدم)
 *   - حساب الجمهور (قائمة مفردة أو مجموعة قوائم audience_ids)
 *   - تجهيز سجلات المستلمين (مع توكنات فتح/كليك فريدة)
 *   - الإرسال الفعلي عبر Mailer (SMTP) - بنية إرسال خاصة بـ Tourfecto
 *   - معالجة دفعات في الخلفية عبر SendEmailCampaignBatchJob (cron)
 *   - جدولة/إلغاء/تقرير
 *
 * قاعدة ذهبية للإرسال: لا يُرسل أبدًا لمشترك status IN (unsubscribed,
 * bounced) - احترام كامل لإلغاء الاشتراك (GDPR/anti-spam).
 */
class EmailCampaignService
{
    private const BATCH_SIZE = 100;

    /** @var Database */
    private $db;

    /** @var EmailRenderer */
    private $renderer;

    private $providerFactory;
    public function __construct(?callable $providerFactory=null)
    {
        $this->providerFactory=$providerFactory;
        $this->db = Database::getInstance();
        $this->renderer = new EmailRenderer();
    }

    // ============================ CRUD ============================

    public function create(int $userId, array $data): array
    {
        if (trim((string) ($data['name'] ?? '')) === '') {
            return ['success' => false, 'error' => 'اسم الحملة مطلوب'];
        }
        if (trim((string) ($data['subject'] ?? '')) === '') {
            return ['success' => false, 'error' => 'عنوان البريد (subject) مطلوب'];
        }
        if (trim((string) ($data['html_body'] ?? '')) === '') {
            return ['success' => false, 'error' => 'محتوى البريد مطلوب'];
        }

        $segmentId = !empty($data['segment_id']) ? (int) $data['segment_id'] : 0;
        if ($segmentId > 0 && !$this->segmentOwnedBy($userId, $segmentId)) {
            return ['success' => false, 'error' => 'الشريحة غير موجودة'];
        }

        $campaign = new EmailCampaign([
            'user_id' => $userId,
            'name' => trim((string) $data['name']),
            'subject' => trim((string) $data['subject']),
            'from_name' => !empty($data['from_name']) ? trim((string) $data['from_name']) : null,
            'from_email' => !empty($data['from_email']) ? trim((string) $data['from_email']) : null,
            'template_id' => !empty($data['template_id']) ? (int) $data['template_id'] : null,
            'list_id' => !empty($data['list_id']) ? (int) $data['list_id'] : null,
            'audience_ids' => $this->normalizeAudience($data['audience_ids'] ?? []),
            'segment_id' => $segmentId > 0 ? $segmentId : null,
            'html_body' => (string) $data['html_body'],
            'status' => EmailCampaign::STATUS_DRAFT,
        ]);
        $id = (int) $campaign->save();
        if ($id <= 0) {
            return ['success' => false, 'error' => 'تعذر حفظ الحملة'];
        }
        return ['success' => true, 'id' => $id];
    }

    public function update(int $userId, int $campaignId, array $data): array
    {
        $campaign = $this->findOwned($userId, $campaignId);
        if (!$campaign) {
            return ['success' => false, 'error' => 'الحملة غير موجودة'];
        }
        if (in_array($campaign->getAttribute('status'), [EmailCampaign::STATUS_SENT, EmailCampaign::STATUS_SENDING, EmailCampaign::STATUS_FAILED,'partial','uncertain'], true)) {
            return ['success' => false, 'error' => 'لا يمكن تعديل حملة تم إرسالها أو قيد الإرسال'];
        }

        foreach (['name', 'subject', 'from_name', 'from_email', 'html_body'] as $field) {
            if (array_key_exists($field, $data)) {
                $campaign->setAttribute($field, trim((string) $data[$field]));
            }
        }
        if (array_key_exists('template_id', $data)) {
            $campaign->setAttribute('template_id', !empty($data['template_id']) ? (int) $data['template_id'] : null);
        }
        if (array_key_exists('list_id', $data)) {
            $campaign->setAttribute('list_id', !empty($data['list_id']) ? (int) $data['list_id'] : null);
        }
        if (array_key_exists('audience_ids', $data)) {
            $campaign->setAttribute('audience_ids', $this->normalizeAudience($data['audience_ids']));
        }
        if (array_key_exists('segment_id', $data)) {
            $segmentId = !empty($data['segment_id']) ? (int) $data['segment_id'] : 0;
            if ($segmentId > 0 && !$this->segmentOwnedBy($userId, $segmentId)) {
                return ['success' => false, 'error' => 'الشريحة غير موجودة'];
            }
            $campaign->setAttribute('segment_id', $segmentId > 0 ? $segmentId : null);
        }
        if (array_key_exists('scheduled_at', $data)) {
            $campaign->setAttribute('scheduled_at', !empty($data['scheduled_at']) ? $data['scheduled_at'] : null);
        }
        $campaign->save();
        return ['success' => true];
    }

    public function delete(int $userId, int $campaignId): array
    {
        $campaign = $this->findOwned($userId, $campaignId);
        if (!$campaign) {
            return ['success' => false, 'error' => 'الحملة غير موجودة'];
        }
        if (in_array($campaign->getAttribute('status'),[EmailCampaign::STATUS_SENDING,'uncertain'],true)) {
            return ['success' => false, 'error' => 'لا يمكن حذف حملة قيد الإرسال'];
        }
        $campaign->delete();
        return ['success' => true];
    }

    public function get(int $userId, int $campaignId): ?array
    {
        $campaign = $this->findOwned($userId, $campaignId);
        if (!$campaign) {
            return null;
        }
        $row = $campaign->toArray();
        $row['audience_ids'] = json_decode((string) ($row['audience_ids'] ?? '[]'), true) ?: [];
        $row['recipients'] = $this->recipients($campaignId, 1, 10)['data'];
        $row['list'] = null;
        if (!empty($row['list_id'])) {
            $list = (new EmailList())->find((int) $row['list_id']);
            $row['list'] = $list ? $list->toArray() : null;
        }
        $row['segment'] = null;
        $row['segment_name'] = null;
        if (!empty($row['segment_id'])) {
            $segment = (new EmailSegment())->find((int) $row['segment_id']);
            $row['segment'] = $segment ? $segment->toArray() : null;
            $row['segment_name'] = $segment ? (string) $segment->getAttribute('name') : null;
        }
        return $row;
    }

    public function list(int $userId): array
    {
        return $this->db->query(
            "SELECT c.*, l.name AS list_name, seg.name AS segment_name
             FROM email_campaigns c
             LEFT JOIN email_lists l ON l.id = c.list_id
             LEFT JOIN email_segments seg ON seg.id = c.segment_id
             WHERE c.user_id = ?
             ORDER BY c.created_at DESC",
            [$userId]
        );
    }

    // ============================ Audience & Recipients ============================

    /** يحسب الجمهور: قائمة مفردة أو اتحاد قوائم audience_ids أو شريحة segment_id. */
    public function audience(int $userId, EmailCampaign $campaign): array
    {
        // G2: شريحة ديناميكية تغلب على القوائم لو حُددت (مع عزل تينانت كامل)
        $segmentId = (int) $campaign->getAttribute('segment_id');
        if ($segmentId > 0) {
            return $this->segmentAudience($userId, $segmentId);
        }

        $listId = (int) $campaign->getAttribute('list_id');
        $audienceIds = json_decode((string) $campaign->getAttribute('audience_ids'), true) ?: [];

        $ids = [];
        if ($listId > 0) {
            $ids[] = $listId;
        }
        foreach ($audienceIds as $id) {
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique(array_filter($ids, fn ($v) => $v > 0)));

        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$userId], $ids);

        return $this->db->query(
            "SELECT DISTINCT s.id, s.email, s.name, s.attributes
             FROM email_subscribers s
             JOIN email_list_subscriber els ON els.subscriber_id = s.id
             JOIN email_lists l ON l.id = els.list_id
             WHERE l.user_id = ? AND l.id IN ({$placeholders})
               AND s.status = 'subscribed'
               AND NOT EXISTS (
                   SELECT 1 FROM email_suppressions sup
                   WHERE sup.user_id = l.user_id AND sup.email = s.email
               )
             ORDER BY s.id ASC",
            $params
        );
    }

    /**
     * جمهور شريحة (segment_id) مع استبعاد الممنوعين ونفس قواعد القوائم
     */
    public function segmentAudience(int $userId, int $segmentId): array
    {
        $seg = (new ContactManagementService())->evaluateSegment($userId, $segmentId);
        $ids = $seg['ids'] ?? [];
        if (empty($ids)) {
            return [];
        }
        $in = implode(',', array_map('intval', $ids));
        $params = [$userId];
        return $this->db->query(
            "SELECT DISTINCT s.id, s.email, s.name, s.attributes
             FROM email_subscribers s
             WHERE s.user_id = ? AND s.id IN ({$in})
               AND s.status = 'subscribed'
               AND NOT EXISTS (
                   SELECT 1 FROM email_suppressions sup
                   WHERE sup.user_id = s.user_id AND sup.email = s.email
               )
             ORDER BY s.id ASC",
            $params
        );
    }

    /**
     * يجهّز سجلات المستلمين للحملة (حالة pending) مع توكنات التتبع.
     * @return array ['success'=>bool, 'recipients'=>int, 'error'=>?string]
     */
    public function prepareRecipients(int $userId, int $campaignId): array
    {
        $campaign = $this->findOwned($userId, $campaignId);
        if (!$campaign) {
            return ['success' => false, 'recipients' => 0, 'error' => 'الحملة غير موجودة'];
        }

        // تجهيز سجلات قديمة من محاولات سابقة - نمسح الـ pending فقط ونبني تاني
        $this->db->query(
            "DELETE FROM email_campaign_recipients WHERE campaign_id = ? AND status = 'pending'",
            [$campaignId]
        );

        $audience = $this->audience($userId, $campaign);
        $inserted = 0;

        foreach ($audience as $member) {
            $exists = $this->db->query(
                "SELECT id FROM email_campaign_recipients
                 WHERE campaign_id = ? AND email = ? LIMIT 1",
                [$campaignId, $member['email']]
            );
            if (!empty($exists)) {
                continue; // سبق إرساله في محاولة سابقة (مش هيعاد)
            }

            $recipient = new EmailCampaignRecipient([
                'campaign_id' => $campaignId,
                'subscriber_id' => (int) $member['id'],
                'email' => $member['email'],
                'name' => $member['name'] !== null ? (string) $member['name'] : null,
                'status' => EmailCampaignRecipient::STATUS_PENDING,
                'open_token' => $this->token(),
                'click_token' => $this->token(),
            ]);
            if ($recipient->save()) {
                $inserted++;
            }
        }

        $total = (int) $this->db->query(
            "SELECT COUNT(*) AS total FROM email_campaign_recipients WHERE campaign_id = ?",
            [$campaignId]
        )[0]['total'];

        $campaign->setAttribute('total_recipients', $total);
        $campaign->save();

        return ['success' => true, 'recipients' => $inserted, 'total' => $total];
    }

    /**
     * يجهّز سجلات المستلمين لحملة لكن لقائمة محددة من معرّفات المشتركين
     * (يستخدمه اختبار أ/ب لتقسيم الجمهور بين المتغيرين). يمسح سجلات
     * pending السابقة لنفس الحملة ثم يبني من الـ ids المعطاة فقط.
     *
     * @param int[] $subscriberIds
     * @return array ['success'=>bool, 'recipients'=>int, 'total'=>int, 'error'=>?string]
     */
    public function prepareRecipientsForSubset(int $userId, int $campaignId, array $subscriberIds): array
    {
        $campaign = $this->findOwned($userId, $campaignId);
        if (!$campaign) {
            return ['success' => false, 'recipients' => 0, 'total' => 0, 'error' => 'الحملة غير موجودة'];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $subscriberIds), fn ($v) => $v > 0)));
        if (empty($ids)) {
            return ['success' => true, 'recipients' => 0, 'total' => 0];
        }

        $this->db->query(
            "DELETE FROM email_campaign_recipients WHERE campaign_id = ? AND status = 'pending'",
            [$campaignId]
        );

        $in = implode(',', array_fill(0, count($ids), '?'));
        $members = $this->db->query(
            "SELECT id, email, name, attributes FROM email_subscribers
             WHERE user_id = ? AND id IN ({$in}) AND status = 'subscribed'
             ORDER BY id ASC",
            array_merge([$userId], $ids)
        );

        $inserted = 0;
        foreach ($members as $member) {
            $recipient = new EmailCampaignRecipient([
                'campaign_id' => $campaignId,
                'subscriber_id' => (int) $member['id'],
                'email' => $member['email'],
                'name' => $member['name'] !== null ? (string) $member['name'] : null,
                'status' => EmailCampaignRecipient::STATUS_PENDING,
                'open_token' => $this->token(),
                'click_token' => $this->token(),
            ]);
            if ($recipient->save()) {
                $inserted++;
            }
        }

        $total = (int) $this->db->query(
            "SELECT COUNT(*) AS total FROM email_campaign_recipients WHERE campaign_id = ?",
            [$campaignId]
        )[0]['total'];

        $campaign->setAttribute('total_recipients', $total);
        $campaign->save();

        return ['success' => true, 'recipients' => $inserted, 'total' => $total];
    }

    // ============================ Sending ============================

    /**
     * إرسال دفعة من المستلمين (يستدعيه SendEmailCampaignBatchJob من cron).
     * يرسل حتى BATCH_SIZE ثم يعيد whether تبقى مهام. الإرسال عبر Mailer (SMTP).
     *
     * @return array ['processed'=>int, 'failed'=>int, 'remaining'=>bool, 'error'=>?string]
     */
    public function sendBatch(int $userId, int $campaignId): array
    {
        $key='email-campaign:'.$campaignId;
        if(!ExternalExecution::lock($key))return ['processed'=>0,'failed'=>0,'remaining'=>true,'busy'=>true];
        try {
        $campaign = $this->findOwned($userId, $campaignId);
        if (!$campaign) {
            return ['processed' => 0, 'failed' => 0, 'remaining' => false, 'error' => 'الحملة غير موجودة'];
        }

        // تحويل الحالة لـ sending لو لسه draft/scheduled
        $status = $campaign->getAttribute('status');
        if (!in_array($status, [EmailCampaign::STATUS_SENDING, EmailCampaign::STATUS_SCHEDULED, EmailCampaign::STATUS_DRAFT], true)) {
            return ['processed' => 0, 'failed' => 0, 'remaining' => false, 'error' => "حالة الحملة لا تسمح بالإرسال ({$status})"];
        }
        if ($status !== EmailCampaign::STATUS_SENDING) {
            $q=$this->db->getConnection()->prepare("UPDATE email_campaigns SET status='sending' WHERE id=? AND status IN ('draft','scheduled')");$q->execute([$campaignId]);
            if($q->rowCount()!==1)return ['processed'=>0,'failed'=>0,'remaining'=>false,'error'=>'Campaign state changed'];
            $campaign->setAttribute('status',EmailCampaign::STATUS_SENDING);
        }

        $pending = $this->db->query(
            "SELECT r.* FROM email_campaign_recipients r
             WHERE r.campaign_id = ? AND r.status = 'pending'
             ORDER BY r.id ASC LIMIT " . self::BATCH_SIZE,
            [$campaignId]
        );

        $provider = $this->resolveProvider($userId, $campaign);
        $baseUrl = $this->trackingBaseUrl();
        $companyName = $this->companyName($userId);
        $fromEmail = $campaign->getAttribute('from_email') ?: (defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'noreply@tourfecto.com');
        $fromName = $campaign->getAttribute('from_name') ?: (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Tourfecto');

        $processed = 0;
        $failed = 0;

        $pdo=$this->db->getConnection();
        foreach ($pending as $row) {
            $claim=$pdo->prepare("UPDATE email_campaign_recipients r JOIN email_campaigns c ON c.id=r.campaign_id LEFT JOIN email_subscribers s ON s.id=r.subscriber_id SET r.status='sending' WHERE r.id=? AND r.status='pending' AND c.status='sending' AND (r.subscriber_id IS NULL OR s.status='subscribed') AND NOT EXISTS (SELECT 1 FROM email_suppressions x WHERE x.user_id=c.user_id AND x.email=r.email)");
            $claim->execute([(int)$row['id']]);if($claim->rowCount()!==1)continue;
            try {
            $recipientData = [
                'name' => $row['name'],
                'first_name' => $row['name'],
                'email' => $row['email'],
                'company_name' => $companyName,
                'campaign_name' => $campaign->getAttribute('name'),
            ];
            // تفاصيل المشترك + attributes إضافية
            if (!empty($row['subscriber_id'])) {
                $subRow = $this->db->query(
                    "SELECT attributes FROM email_subscribers WHERE id = ? AND status = 'subscribed' LIMIT 1",
                    [(int) $row['subscriber_id']]
                );
                if (!empty($subRow)) {
                    $recipientData['attributes'] = json_decode((string) ($subRow[0]['attributes'] ?? 'null'), true) ?: [];
                }
            }

            $unsubscribeToken = $this->subscriberToken($row['subscriber_id']);
            $unsubscribeUrl = $baseUrl . '/api/email-marketing/unsubscribe/' . rawurlencode($unsubscribeToken);

            // RFC 8058: هيدرز List-Unsubscribe + List-Unsubscribe-Post للتوافق
            // مع متطلبات Gmail/Yahoo (فبراير 2024) — mailto + رابط one-click.
            $extraHeaders = [
                'List-Unsubscribe' => '<mailto:unsubscribe@' . $provider['mail_domain'] . '?subject=unsubscribe>, <' . $unsubscribeUrl . '>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ];

            $subject = $this->renderer->personalize((string) $campaign->getAttribute('subject'), $recipientData);
            $html = $this->renderer->finalize(
                (string) $campaign->getAttribute('html_body'),
                $recipientData,
                (string) $row['open_token'],
                (string) $row['click_token'],
                $baseUrl,
                $unsubscribeUrl
            );

            $result = ExternalExecution::run('email:'.$campaignId.':'.$row['id'],$userId,fn()=>$provider['send']($row['email'], (string) ($row['name'] ?? ''), $subject, $html, $fromEmail, $fromName, $extraHeaders));

            if (!empty($result['success'])) {
                $q=$pdo->prepare("UPDATE email_campaign_recipients SET status='sent',sent_at=NOW() WHERE id=? AND status='sending'");$q->execute([(int)$row['id']]);if($q->rowCount()!==1)throw new RuntimeException('Send persistence failed');
                $processed++;
            } else {
                $this->db->query(
                    "UPDATE email_campaign_recipients
                     SET status = ?, error_message = ? WHERE id = ?",
                    [($result['outcome_state']??'uncertain')==='failed'?'failed':'uncertain', substr((string) ($result['error'] ?? 'unknown error'), 0, 1000), (int) $row['id']]
                );
                $failed++;
            }
            } catch(Throwable $e) {
                $q=$pdo->prepare("UPDATE email_campaign_recipients SET status='uncertain',error_message='Send outcome requires provider review' WHERE id=? AND status='sending'");$q->execute([(int)$row['id']]);$failed++;
            }
        }
        // Suppressed/withdrawn recipients from old campaigns are never left pending.
        $q=$pdo->prepare("UPDATE email_campaign_recipients r JOIN email_campaigns c ON c.id=r.campaign_id LEFT JOIN email_subscribers s ON s.id=r.subscriber_id SET r.status='unsubscribed' WHERE r.campaign_id=? AND r.status='pending' AND ((r.subscriber_id IS NOT NULL AND (s.id IS NULL OR s.status<>'subscribed')) OR EXISTS (SELECT 1 FROM email_suppressions x WHERE x.user_id=c.user_id AND x.email=r.email))");$q->execute([$campaignId]);
        // A process interrupted after claiming must be reviewed, never replayed automatically.
        $q=$pdo->prepare("UPDATE email_campaign_recipients r LEFT JOIN external_execution_attempts e ON BINARY e.operation_key=BINARY SHA2(CONCAT('email:',r.campaign_id,':',r.id),256) SET r.status='uncertain' WHERE r.campaign_id=? AND r.status='sending' AND (e.state IN ('started','uncertain') OR e.operation_key IS NULL) AND r.updated_at<DATE_SUB(NOW(),INTERVAL 30 MINUTE)");$q->execute([$campaignId]);

        $remainingCount = (int) $this->db->query(
            "SELECT COUNT(*) AS total FROM email_campaign_recipients WHERE campaign_id = ? AND status = 'pending'",
            [$campaignId]
        )[0]['total'];

        if ($remainingCount === 0) {
            $q=$pdo->prepare("SELECT status,COUNT(*) n FROM email_campaign_recipients WHERE campaign_id=? GROUP BY status");$q->execute([$campaignId]);$counts=$q->fetchAll(PDO::FETCH_KEY_PAIR);
            $sent=($counts['sent']??0)+($counts['opened']??0)+($counts['clicked']??0);
            $uncertain=($counts['uncertain']??0)+($counts['sending']??0);
            $final=$uncertain?'uncertain':(($counts['failed']??0)?($sent?'partial':'failed'):'sent');
            $q=$pdo->prepare("UPDATE email_campaigns SET status=?,sent_at=IF(?='sent',NOW(),sent_at) WHERE id=? AND status='sending'");$q->execute([$final,$final,$campaignId]);
        }

        $trackingService = new EmailTrackingService();
        $trackingService->recomputeCampaignCounts($campaignId);

        return [
            'processed' => $processed,
            'failed' => $failed,
            'remaining' => $remainingCount > 0,
        ];
        }finally{ExternalExecution::unlock($key);}
    }

    /**
     * إعادة محاولة الإرسال لحملة فشلت بالكامل: يعيد المستلمين الفاشلين
     * إلى حالة pending (مع مسح خطأ كل مستلم) كي يعيد طابور الإرسال محاولته.
     *
     * @return array ['success'=>bool, 'reset'=>int, 'error'=>?string]
     */
    public function retryFailed(int $userId, int $campaignId): array
    {
        $campaign = $this->findOwned($userId, $campaignId);
        if (!$campaign) {
            return ['success' => false, 'reset' => 0, 'error' => 'الحملة غير موجودة'];
        }
        if (!in_array($campaign->getAttribute('status'), [EmailCampaign::STATUS_FAILED, 'partial', 'uncertain'], true)) {
            return ['success' => false, 'reset' => 0, 'error' => 'لا يمكن إعادة محاولة حملة لم تفشل'];
        }

        $failedCount = (int) $this->db->query(
            "SELECT COUNT(*) AS total FROM email_campaign_recipients WHERE campaign_id = ? AND status = ?",
            [$campaignId, EmailCampaignRecipient::STATUS_FAILED]
        )[0]['total'];

        if ($failedCount === 0) {
            return ['success' => false, 'reset' => 0, 'error' => 'لا يوجد مستلمون فاشلون لإعادة المحاولة'];
        }

        $this->db->query(
            "UPDATE email_campaign_recipients
             SET status = ?, error_message = NULL
             WHERE campaign_id = ? AND status = ?",
            [EmailCampaignRecipient::STATUS_PENDING, $campaignId, EmailCampaignRecipient::STATUS_FAILED]
        );

        $campaign->setAttribute('status', EmailCampaign::STATUS_SENDING);
        $campaign->setAttribute('error_message', null);
        $campaign->save();

        return ['success' => true, 'reset' => $failedCount];
    }

    /**
     * إرسال فوري متزامن (يستخدمه اختبار "إرسال اختبار" والقوائم الصغيرة).
     * @return array ['success'=>bool, 'sent'=>int, 'error'=>?string]
     */
    public function sendTest(int $userId, int $campaignId, string $toEmail): array
    {
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'sent' => 0, 'error' => 'بريد إرسال الاختبار غير صالح'];
        }
        $campaign = $this->findOwned($userId, $campaignId);
        if (!$campaign) {
            return ['success' => false, 'sent' => 0, 'error' => 'الحملة غير موجودة'];
        }

        $token = $this->token();
        $baseUrl = $this->trackingBaseUrl();
        $data = [
            'name' => 'Test Recipient',
            'first_name' => 'Test',
            'email' => $toEmail,
            'company_name' => $this->companyName($userId),
            'campaign_name' => $campaign->getAttribute('name'),
        ];
        $html = $this->renderer->finalize(
            (string) $campaign->getAttribute('html_body'),
            $data,
            $token,
            $token,
            $baseUrl,
            $baseUrl . '/api/email-marketing/unsubscribe/test-token'
        );

        $provider = $this->resolveProvider($userId, $campaign);
        $fromEmail = $campaign->getAttribute('from_email') ?: (defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'noreply@tourfecto.com');
        $fromName = $campaign->getAttribute('from_name') ?: (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Tourfecto');
        $subject = $this->renderer->personalize((string) $campaign->getAttribute('subject'), $data);

        $extraHeaders = [
            'List-Unsubscribe' => '<mailto:unsubscribe@' . $provider['mail_domain'] . '?subject=unsubscribe>, <' . $baseUrl . '/api/email-marketing/unsubscribe/test-token>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];

        $result = $provider['send']($toEmail, 'Test Recipient', $subject, $html, $fromEmail, $fromName, $extraHeaders);
        return $result['success']
            ? ['success' => true, 'sent' => 1]
            : ['success' => false, 'sent' => 0, 'error' => $result['error']];
    }

    /**
     * جدولة حملة: ينشئ مهمة QueueManager بوقت التنفيذ = موعد الجدولة.
     */
    public function schedule(int $userId, int $campaignId, string $scheduledAt): array
    {
        try{$ts=InternationalSettingsService::scheduledTimestamp($userId,$scheduledAt);}catch(InvalidArgumentException $e){return ['success'=>false,'error'=>$e->getMessage()];}
        if ($ts === false || $ts <= time()) {
            return ['success' => false, 'error' => 'موعد الجدولة يجب أن يكون في المستقبل'];
        }

        $campaign = $this->findOwned($userId, $campaignId);
        if (!$campaign) {
            return ['success' => false, 'error' => 'الحملة غير موجودة'];
        }
        $status = $campaign->getAttribute('status');
        if (!in_array($status, [EmailCampaign::STATUS_DRAFT, EmailCampaign::STATUS_SCHEDULED], true)) {
            return ['success' => false, 'error' => 'لا يمكن جدولة حملة بهذه الحالة'];
        }

        $prepared = $this->prepareRecipients($userId, $campaignId);
        if (!$prepared['success']) {
            return $prepared;
        }

        $campaign->setAttribute('status', EmailCampaign::STATUS_SCHEDULED);
        $campaign->setAttribute('scheduled_at', gmdate('Y-m-d H:i:s', $ts));
        $campaign->save();

        if (!class_exists('QueueManager')) {
            return ['success' => false, 'error' => 'نظام الطوابير غير متاح'];
        }
        $queue = new QueueManager();
        $delay = max(1, $ts - time());
        $queue->push(SendEmailCampaignBatchJob::class, [
            'user_id' => $userId,
            'campaign_id' => $campaignId,
        ], 'email', $delay);

        return ['success' => true];
    }

    /** إلغاء حملة مجدولة. */
    public function cancel(int $userId, int $campaignId): array
    {
        $campaign = $this->findOwned($userId, $campaignId);
        if (!$campaign) {
            return ['success' => false, 'error' => 'الحملة غير موجودة'];
        }
        if ($campaign->getAttribute('status') !== EmailCampaign::STATUS_SCHEDULED) {
            return ['success' => false, 'error' => 'فقط الحملات المجدولة يمكن إلغاؤها'];
        }
        $campaign->setAttribute('status', EmailCampaign::STATUS_CANCELLED);
        $campaign->save();
        return ['success' => true];
    }

    /** تقرير إحصائي كامل لحملة. */
    public function report(int $userId, int $campaignId): ?array
    {
        $campaign = $this->findOwned($userId, $campaignId);
        if (!$campaign) {
            return null;
        }
        $row = $campaign->toArray();
        $total = max(1, (int) ($row['sent_count'] ?? 0));
        $row['open_rate'] = round(((int) ($row['opened_count'] ?? 0)) / $total * 100, 1);
        $clickRateBase = max(1, (int) ($row['opened_count'] ?? 0));
        $row['click_rate'] = round(((int) ($row['clicked_count'] ?? 0)) / $total * 100, 1);
        $row['click_to_open_rate'] = round(((int) ($row['clicked_count'] ?? 0)) / $clickRateBase * 100, 1);
        $row['unsubscribe_rate'] = round(((int) ($row['unsubscribed_count'] ?? 0)) / $total * 100, 1);
        return $row;
    }

    // ============================ Provider & Helpers ============================

    /**
     * يختار مزوّد الإرسال. الموديول منافس كامل لـ Brevo/Mailchimp - الإرسال
     * بيتم حصريًا عبر البنية التحتية الخاصة بـ Tourfecto (SMTP عبر Mailer).
     * @return array ['name'=>string, 'send'=>callable]
     */
    private function resolveProvider(int $userId, EmailCampaign $campaign): array
    {
        if($this->providerFactory)return ($this->providerFactory)($userId,$campaign);
        $mailer = (new SmtpSettingsService())->mailerForUser($userId);
        $campaignFromEmail = $campaign->getAttribute('from_email') ?: null;
        $campaignFromName = $campaign->getAttribute('from_name') ?: null;
        if ($campaignFromEmail) {
            $mailer->configure(['from_email' => $campaignFromEmail]);
        }
        if ($campaignFromName) {
            $mailer->configure(['from_name' => $campaignFromName]);
        }
        return [
            'name' => 'mailer',
            'mail_domain' => $this->domainFromEmail($mailer->getFromEmail()),
            'send' => function (string $toEmail, string $toName, string $subject, string $htmlBody, string $fromEmail, string $fromName, array $extraHeaders = []) use ($mailer) {
                return $mailer->send($toEmail, $toName, $subject, $htmlBody, $extraHeaders);
            },
        ];
    }

    private function findOwned(int $userId, int $campaignId): ?EmailCampaign
    {
        $campaign = (new EmailCampaign())->find($campaignId);
        if (!$campaign || (int) $campaign->getAttribute('user_id') !== $userId) {
            return null;
        }
        return $campaign;
    }

    private function recipients(int $campaignId, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        return [
            'data' => $this->db->query(
                "SELECT r.*, s.unsubscribe_token
                 FROM email_campaign_recipients r
                 LEFT JOIN email_subscribers s ON s.id = r.subscriber_id
                 WHERE r.campaign_id = ?
                 ORDER BY r.id ASC
                 LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
                [$campaignId]
            ),
            'total' => (int) $this->db->query(
                "SELECT COUNT(*) AS total FROM email_campaign_recipients WHERE campaign_id = ?",
                [$campaignId]
            )[0]['total'],
        ];
    }

    private function normalizeAudience($value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = array_filter(array_map('intval', explode(',', $value)));
            }
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $value), fn ($v) => $v > 0)));
        return json_encode($ids, JSON_UNESCAPED_UNICODE);
    }

    private function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** هل الشريحة مملوكة للمستخدم (عزل تينانت)؟ */
    private function segmentOwnedBy(int $userId, int $segmentId): bool
    {
        $segment = (new EmailSegment())->find($segmentId);
        return $segment !== null && (int) $segment->getAttribute('user_id') === $userId;
    }

    private function trackingBaseUrl(): string
    {
        return rtrim(defined('APP_URL') ? APP_URL : 'https://tourfecto.com', '/');
    }

    private function companyName(int $userId): string
    {
        $row = $this->db->query(
            "SELECT company_name FROM users WHERE id = ? LIMIT 1",
            [$userId]
        );
        return $row[0]['company_name'] ?? 'Tourfecto';
    }

    private function subscriberToken(?int $subscriberId): string
    {
        if ($subscriberId === null || $subscriberId <= 0) {
            return '';
        }
        $rows = $this->db->query(
            "SELECT unsubscribe_token FROM email_subscribers WHERE id = ? LIMIT 1",
            [$subscriberId]
        );
        $token = $rows[0]['unsubscribe_token'] ?? '';
        if ($token === '') {
            // لو الـ token مفقود من السجل، نولّد فريد مستقر من المعرّف بدل
            // قيمة ثابتة يتشاركها كل المشتركين الناقصين.
            $token = 'auto-' . substr(hash('sha256', 'sub:' . (int) $subscriberId), 0, 24);
        }
        return $token;
    }

    /**
     * دومين البريد المستخدم في mailto داخل هيدر List-Unsubscribe.
     * يُشتق من دومين عنوان المرسل الفعلي (إعدادات SMTP/من From)، ولو فشل
     * يقع على دومين APP_URL ثم tourfecto.com كملاذ أخير.
     */
    private function domainFromEmail(string $email): string
    {
        $atPos = strrpos($email, '@');
        if ($atPos !== false) {
            $domain = strtolower(substr($email, $atPos + 1));
            $domain = preg_replace('/[^a-z0-9.\-]/', '', $domain) ?? '';
            if ($domain !== '') {
                return $domain;
            }
        }
        $host = (string) (parse_url($this->trackingBaseUrl(), PHP_URL_HOST) ?? '');
        $host = preg_replace('/[^a-z0-9.\-]/', '', strtolower($host)) ?? '';
        return $host !== '' ? $host : 'tourfecto.com';
    }
}
