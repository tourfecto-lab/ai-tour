<?php

/**
 * Tourfecto - SEO: Scheduled Email Reports Service (G6)
 * @version 1.0.0
 *
 * يسد فجوة "تقارير مجدولة (Email)" (Ahrefs/SEMrush) عبر cron
 * `cron/seo_scheduled_reports.php`: جدول `seo_report_schedules` يحدد
 * متى (daily/weekly/monthly) ولأي بريد، وبيُبنى تقرير HTML ملخص من
 * بيانات حقيقية (آخر درجة تدقيق + أهم مشاكل + أحدث التدقيقات) ويُرسل
 * عبر `Mailer`.
 *
 * Guardrail: لو Mailer مش متظبط → الـ cron يتجاوز الموقع بأمان ويسجّل
 * warning (لا إرسال وهمي). PDF خارج النطاق (يحتاج مكتبة توليد PDF -
 * بلا تبعيات خارجية).
 */
class SeoScheduledReportService
{
    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /** قائمة الجداول لموقع معيّن (عزل تينانت). */
    public function listSchedules(int $websiteId, int $userId): array
    {
        return $this->db->query(
            "SELECT id, website_id, frequency, weekday, hour, recipient_email, is_active, last_sent_at, created_at
             FROM seo_report_schedules WHERE website_id = ? AND user_id = ? ORDER BY id DESC",
            [$websiteId, $userId]
        );
    }

    /**
     * إنشاء/تحديث جدول. frequency إجباري؛ hour 0-23؛ weekday 0-6
     * (مطلوب للـ weekly)؛ recipient_email بريد صالح.
     * @return array{success:bool, schedule:?array, error:?string}
     */
    public function saveSchedule(int $websiteId, int $userId, array $data, ?int $scheduleId = null): array
    {
        $frequency = (string) ($data['frequency'] ?? '');
        if (!in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
            return ['success' => false, 'schedule' => null, 'error' => 'تردد غير صالح (daily/weekly/monthly)'];
        }
        $hour = isset($data['hour']) ? (int) $data['hour'] : 8;
        if ($hour < 0 || $hour > 23) {
            return ['success' => false, 'schedule' => null, 'error' => 'الساعة لازم تكون بين 0 و 23'];
        }
        $weekday = ($data['weekday'] ?? '') !== '' ? (int) $data['weekday'] : null;
        if ($weekday !== null && ($weekday < 0 || $weekday > 6)) {
            return ['success' => false, 'schedule' => null, 'error' => 'اليوم لازم يكون بين 0 و 6'];
        }
        $email = trim((string) ($data['recipient_email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'schedule' => null, 'error' => 'بريد المستلم غير صالح'];
        }
        $isActive = isset($data['is_active']) ? (int) $data['is_active'] : 1;

        if ($scheduleId !== null) {
            $exists = $this->db->query(
                "SELECT id FROM seo_report_schedules WHERE id = ? AND website_id = ? AND user_id = ? LIMIT 1",
                [$scheduleId, $websiteId, $userId]
            );
            if (empty($exists)) {
                return ['success' => false, 'schedule' => null, 'error' => 'الجدول غير موجود'];
            }
            $this->db->exec(
                "UPDATE seo_report_schedules
                 SET frequency = ?, weekday = ?, hour = ?, recipient_email = ?, is_active = ?
                 WHERE id = ? AND website_id = ? AND user_id = ?",
                [$frequency, $weekday, $hour, $email, $isActive, $scheduleId, $websiteId, $userId]
            );
            $id = $scheduleId;
        } else {
            $id = (int) $this->db->query(
                "INSERT INTO seo_report_schedules
                    (website_id, user_id, frequency, weekday, hour, recipient_email, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$websiteId, $userId, $frequency, $weekday, $hour, $email, $isActive]
            );
        }

        $rows = $this->db->query(
            "SELECT id, website_id, frequency, weekday, hour, recipient_email, is_active, last_sent_at, created_at
             FROM seo_report_schedules WHERE id = ? LIMIT 1",
            [$id]
        );
        return ['success' => true, 'schedule' => $rows[0] ?? null, 'error' => null];
    }

    /** حذف جدول (عزل تينانت). */
    public function deleteSchedule(int $websiteId, int $userId, int $scheduleId): bool
    {
        return $this->db->query(
            "DELETE FROM seo_report_schedules WHERE id = ? AND website_id = ? AND user_id = ?",
            [$scheduleId, $websiteId, $userId]
        ) > 0;
    }

    /**
     * الجداول المستحقة الآن (نشطة + بلغ موعدها حسب التردد).
     * @return array صفوف (id, website_id, user_id, frequency, weekday, hour, recipient_email, last_sent_at) + main_url
     */
    public function dueSchedules(int $limit = 50): array
    {
        $today = (int) date('w'); // 0=Sunday ... 6=Saturday
        $weekdayMatch = in_array($today, [5, 6], true) ? 5 : $today; // مقارنة بالتقويم الغربي
        $nowHour = (int) date('G');

        $rows = $this->db->query(
            "SELECT s.id, s.website_id, s.user_id, s.frequency, s.weekday, s.hour,
                    s.recipient_email, s.last_sent_at, w.main_url
             FROM seo_report_schedules s
             INNER JOIN websites w ON w.id = s.website_id
             WHERE s.is_active = 1
               AND (
                   (s.frequency = 'daily'   AND (s.last_sent_at IS NULL OR DATE(s.last_sent_at) < CURDATE()))
                   OR (s.frequency = 'weekly' AND s.weekday = ? AND (s.last_sent_at IS NULL OR DATE(s.last_sent_at) < CURDATE()))
                   OR (s.frequency = 'monthly' AND (s.last_sent_at IS NULL OR DATE_FORMAT(s.last_sent_at, '%Y-%m') < DATE_FORMAT(CURDATE(), '%Y-%m')))
               )
               AND s.hour <= ?
             ORDER BY s.last_sent_at ASC
             LIMIT ?",
            [$weekdayMatch, $nowHour, $limit]
        );
        return $rows;
    }

    /**
     * إرسال التقارير المستحقة الآن.
     * @return array{attempted:int, sent:int, skipped_no_mailer:int, errors:array}
     */
    public function sendDue(int $limit = 50): array
    {
        $due = $this->dueSchedules($limit);
        $mailer = new Mailer();
        $attempted = 0;
        $sent = 0;
        $skipped = 0;
        $errors = [];

        foreach ($due as $s) {
            $attempted++;
            if (!$mailer->isConfigured()) {
                $skipped++;
                if (class_exists('Logger')) {
                    Logger::warning('SEO scheduled report skipped: mailer not configured', ['schedule_id' => $s['id']]);
                }
                continue;
            }

            $body = $this->buildReportHtml(
                (int) $s['website_id'],
                (int) $s['user_id'],
                (string) ($s['main_url'] ?? '')
            );
            $result = $mailer->send(
                (string) $s['recipient_email'],
                '',
                'تقرير SEO دوري — ' . ($s['main_url'] ?? 'موقعك'),
                $body
            );
            if (!empty($result['success'])) {
                $this->db->exec("UPDATE seo_report_schedules SET last_sent_at = NOW() WHERE id = ?", [$s['id']]);
                $sent++;
            } else {
                $errors[] = ['schedule_id' => $s['id'], 'error' => $result['error'] ?? 'send failed'];
            }
        }

        return ['attempted' => $attempted, 'sent' => $sent, 'skipped_no_mailer' => $skipped, 'errors' => $errors];
    }

    /**
     * بناء HTML التقرير البريدي من بيانات حقيقية (آخر تدقيق + أهم مشاكل +
     * أحدث لقطات). Escaping كامل لكل المدخلات.
     */
    public function buildReportHtml(int $websiteId, int $userId, string $mainUrl): string
    {
        $siteName = htmlspecialchars($mainUrl, ENT_QUOTES, 'UTF-8');

        $lastAudit = $this->db->query(
            "SELECT id, overall_score, completed_at FROM wo_audits
             WHERE website_id = ? AND user_id = ? AND status = 'completed' ORDER BY id DESC LIMIT 1",
            [$websiteId, $userId]
        );
        $score = !empty($lastAudit) && $lastAudit[0]['overall_score'] !== null
            ? round((float) $lastAudit[0]['overall_score'], 1)
            : null;
        $scoreLabel = $score !== null ? $score . ' / 100' : 'لا يوجد تدقيق بعد';

        $issues = [];
        if (!empty($lastAudit)) {
            $issues = $this->db->query(
                "SELECT title, severity FROM wo_audit_findings
                 WHERE audit_id = ? AND status IN ('fail','warn')
                 ORDER BY FIELD(severity, 'critical','high','medium','low') LIMIT 5",
                [$lastAudit[0]['id']]
            );
        }

        $recent = $this->db->query(
            "SELECT overall_score, created_at FROM seo_reports
             WHERE website_id = ? AND user_id = ? AND overall_score IS NOT NULL
             ORDER BY created_at DESC LIMIT 5",
            [$websiteId, $userId]
        );

        $issuesHtml = '';
        if (empty($issues)) {
            $issuesHtml = '<li style="color:#059669;">لا توجد مشاكل حرجة مسجّلة.</li>';
        } else {
            foreach ($issues as $i) {
                $sevColors = ['critical' => '#dc2626', 'high' => '#ea580c', 'medium' => '#d97706', 'low' => '#ca8a04'];
                $color = $sevColors[$i['severity']] ?? '#92400e';
                $issuesHtml .= '<li style="margin-bottom:6px;color:' . $color . ';">['
                    . htmlspecialchars((string) $i['severity'], ENT_QUOTES, 'UTF-8') . '] '
                    . htmlspecialchars((string) $i['title'], ENT_QUOTES, 'UTF-8') . '</li>';
            }
        }

        $recentRows = '';
        foreach ($recent as $r) {
            $recentRows .= '<tr>'
                . '<td style="padding:6px 10px;border-bottom:1px solid #eee;">' . htmlspecialchars((string) $r['created_at'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="padding:6px 10px;border-bottom:1px solid #eee;text-align:right;">' . round((float) $r['overall_score'], 1) . '</td>'
                . '</tr>';
        }
        if ($recentRows === '') {
            $recentRows = '<tr><td colspan="2" style="padding:6px 10px;color:#888;">لا توجد لقطات بعد</td></tr>';
        }

        return <<<HTML
<div style="font-family:Arial,'Helvetica Neue',sans-serif;max-width:620px;margin:0 auto;direction:rtl;">
  <h2 style="color:#1e293b;border-bottom:2px solid #6366f1;padding-bottom:8px;">تقرير SEO دوري — {$siteName}</h2>
  <table style="width:100%;border-collapse:collapse;margin:14px 0;">
    <tr>
      <td style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;text-align:center;">
        <div style="font-size:13px;color:#64748b;">آخر درجة تدقيق</div>
        <div style="font-size:28px;font-weight:bold;color:#0f172a;">{$scoreLabel}</div>
      </td>
    </tr>
  </table>
  <h3 style="color:#334155;">أهم المشاكل المفتوحة</h3>
  <ul style="color:#334155;padding-right:18px;">{$issuesHtml}</ul>
  <h3 style="color:#334155;">أحدث لقطات الدرجات</h3>
  <table style="width:100%;border-collapse:collapse;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
    <thead><tr><th style="padding:6px 10px;text-align:right;color:#64748b;">التاريخ</th><th style="padding:6px 10px;text-align:right;color:#64748b;">الدرجة</th></tr></thead>
    <tbody>{$recentRows}</tbody>
  </table>
  <p style="color:#94a3b8;font-size:12px;margin-top:18px;">تم إنشاء هذا التقرير تلقائيًا من بيانات حقيقية في منصة Tourfecto.</p>
</div>
HTML;
    }
}
