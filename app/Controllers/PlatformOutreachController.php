<?php
require_once __DIR__ . '/../Services/PlatformGrowth/PlatformOutreachService.php';

/**
 * Tourfecto - لوحة "تسويق المنصة" (أدمن) + لينكات التتبع وإلغاء الاشتراك العامة
 */
class PlatformOutreachController extends Controller
{
    private function allowed(): bool
    {
        return $this->isAuthenticated() && in_array($this->user['role'] ?? '', ['admin', 'super_admin'], true);
    }

    private function guard(): ?array
    {
        if (!$this->allowed()) {
            return $this->error('غير مصرح', 403);
        }
        if ($e = $this->verifyCsrf()) {
            return $e;
        }
        return $this->rateLimitGuard('user', 'platform_outreach_admin', 60, 60);
    }

    private function service(): PlatformOutreachService
    {
        return new PlatformOutreachService();
    }

    /** GET /admin/growth */
    public function index(array $params = []): array
    {
        if (!$this->allowed()) {
            return $this->error('غير مصرح', 403);
        }
        $css = htmlspecialchars(asset_v('/assets/css/admin-growth.css'), ENT_QUOTES, 'UTF-8');
        $js = htmlspecialchars(asset_v('/assets/js/admin-growth.js'), ENT_QUOTES, 'UTF-8');
        $body = '<link rel="stylesheet" href="' . $css . '"><section id="growthRoot" class="gr" dir="rtl"><p>جاري التحميل…</p></section><script src="' . $js . '"></script>';
        echo $this->renderPanelPage('platform-growth', 'تسويق المنصة', 'جيب شركات سياحة جديدة بالإيميل - بشكل احترافي وآمن', $body, 'window.AdminGrowth&&window.AdminGrowth.init(window.Panel);');
        exit;
    }

    /** GET /api/admin/growth */
    public function overview(array $params = []): array
    {
        if (!$this->allowed()) {
            return $this->error('غير مصرح', 403);
        }
        header('Cache-Control: private, no-store');
        try {
            $s = $this->service();
            return $this->success(['stats' => $s->stats(), 'settings' => $s->getSettings(true), 'csrf_token' => Csrf::token(), 'admin_email' => (string) ($this->user['email'] ?? '')]);
        } catch (Throwable $e) {
            Logger::error('Growth overview failed', ['error' => $e->getMessage()]);
            return $this->error('تعذر تحميل البيانات: ' . $e->getMessage(), 503);
        }
    }

    /** GET /api/admin/growth/leads */
    public function leads(array $params = []): array
    {
        if (!$this->allowed()) {
            return $this->error('غير مصرح', 403);
        }
        return $this->success($this->service()->leads(['status' => $this->get('status', ''), 'country' => $this->get('country', ''), 'q' => $this->get('q', ''), 'page' => $this->get('page', 1)]));
    }

    /** POST /api/admin/growth/settings */
    public function saveSettings(array $params = []): array
    {
        if ($e = $this->guard()) {
            return $e;
        }
        try {
            $in = $this->get('settings', []);
            if (!is_array($in)) {
                throw new InvalidArgumentException('بيانات غير صالحة');
            }
            $s = $this->service();
            if (($in['enabled'] ?? '') === '1') {
                (new SystemSettingsService())->set('outreach_paused_reason', '');
            }
            return $this->success(['settings' => $s->saveSettings($in)], 'اتحفظ');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** POST /api/admin/growth/import */
    public function import(array $params = []): array
    {
        if ($e = $this->guard()) {
            return $e;
        }
        @set_time_limit(180);
        try {
            $s = $this->service();
            $url = trim((string) $this->get('url', ''));
            $text = (string) $this->get('text', '');
            $r = $url !== '' ? $s->importFromUrl($url) : ($text !== '' ? $s->importText($text) : null);
            if ($r === null) {
                return $this->error('حط رابط البيانات أو الصق البيانات', 422);
            }
            return $this->success($r, 'تم الاستيراد');
        } catch (InvalidArgumentException | RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Growth import failed', ['error' => $e->getMessage()]);
            return $this->error('حصلت مشكلة في الاستيراد', 500);
        }
    }

    /** POST /api/admin/growth/leads/{id}/status */
    public function leadStatus(array $params = []): array
    {
        if ($e = $this->guard()) {
            return $e;
        }
        try {
            $id = (int) ($params['id'] ?? 0);
            if ($this->get('status') === 'delete') {
                $this->service()->deleteLead($id);
            } else {
                $note = $this->get('note');
                $this->service()->setLeadStatus($id, (string) $this->get('status', ''), is_string($note) && $note !== '' ? mb_substr($note, 0, 1000) : null);
            }
            return $this->success([]);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** POST /api/admin/growth/preview - شكل الرسالة لشركة حقيقية من القائمة */
    public function preview(array $params = []): array
    {
        if ($e = $this->guard()) {
            return $e;
        }
        $s = $this->service();
        $set = $s->getSettings();
        $tpl = $this->get('templates');
        if (is_array($tpl)) {
            $set['templates'] = array_replace_recursive($set['templates'], $tpl);
        }
        $lead = $s->sampleLead();
        $lang = in_array($this->get('lang'), ['ar', 'en'], true) ? $this->get('lang') : $lead['language'];
        $lead['language'] = $lang;
        $step = max(1, min(3, (int) $this->get('step', 1)));
        $m = $s->compose($lead, $step, $set);
        return $this->success(['subject' => $m['subject'], 'html' => $m['html'], 'lead' => ['company' => $lead['company'], 'city' => $lead['city'] ?? '', 'rating' => $lead['rating'] ?? null, 'reviews' => $lead['reviews'] ?? null]]);
    }

    /** POST /api/admin/growth/test - يبعت الرسالة لإيميلك انت */
    public function test(array $params = []): array
    {
        if ($e = $this->guard()) {
            return $e;
        }
        $to = trim((string) $this->get('email', $this->user['email'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->error('اكتب إيميل صحيح', 422);
        }
        $s = $this->service();
        $set = $s->getSettings();
        $lead = $s->sampleLead();
        $step = max(1, min(3, (int) $this->get('step', 1)));
        $m = $s->compose($lead, $step, $set);
        $mailer = new Mailer();
        if ($set['smtp_host'] !== '') {
            $mailer->configure(['host' => $set['smtp_host'], 'port' => (int) $set['smtp_port'], 'username' => $set['smtp_username'], 'password' => $set['smtp_password'], 'encryption' => $set['smtp_encryption']]);
        }
        $mailer->configure(['from_email' => $set['from_email'], 'from_name' => $set['sender_name']]);
        $r = $mailer->send($to, 'Test', '[تجربة] ' . $m['subject'], $m['html'], $set['reply_to'] !== '' ? ['Reply-To' => $set['reply_to']] : []);
        return !empty($r['success']) ? $this->success([], 'اتبعتت لـ ' . $to) : $this->error('الإرسال فشل: ' . ($r['error'] ?? ''), 502);
    }

    /** POST /api/admin/growth/run - دفعة دلوقتي (بتحترم الحد اليومي) */
    public function runNow(array $params = []): array
    {
        if ($e = $this->guard()) {
            return $e;
        }
        @set_time_limit(120);
        $s = $this->service();
        $what = $this->get('what', 'send');
        if ($what === 'enrich') {
            return $this->success(['found' => $s->enrich(15, 50)]);
        }
        $r = $s->run(null, null, true);
        (new SystemSettingsService())->set('outreach_last_run', date('Y-m-d H:i:s'));
        return $this->success($r);
    }

    // ------------------------------------------------------------ لينكات عامة (من غير تسجيل دخول)

    /** GET/POST /p/u/{token} - إلغاء الاشتراك */
    public function unsubscribe(array $params = []): array
    {
        $s = $this->service();
        $token = (string) ($params['token'] ?? '');
        $lead = $s->leadByToken($token);
        $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        if ($post && $lead) {
            $s->unsubscribe($token);
        }
        $ar = ($lead['language'] ?? 'ar') === 'ar';
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        $done = $post || ($lead && $lead['status'] === 'unsubscribed');
        $title = $done ? ($ar ? 'تم إلغاء الاشتراك' : 'You are unsubscribed') : ($ar ? 'إلغاء الاشتراك' : 'Unsubscribe');
        $msg = !$lead ? ($ar ? 'الرابط ده مش صالح.' : 'This link is not valid.')
            : ($done ? ($ar ? 'مش هنبعتلكم رسايل تاني. آسفين على الإزعاج.' : 'You will not receive any more emails from us. Sorry for the bother.')
                : ($ar ? 'متأكدين إنكم مش عاوزين رسايل تاني من Tourfecto؟' : 'Stop all emails from Tourfecto?'));
        $btn = ($lead && !$done) ? '<form method="post"><button style="background:#0E4D64;color:#fff;border:0;padding:12px 22px;border-radius:8px;font-size:15px;cursor:pointer">' . ($ar ? 'أيوه، ألغِ الاشتراك' : 'Yes, unsubscribe') . '</button></form>' : '';
        echo '<!doctype html><html lang="' . ($ar ? 'ar' : 'en') . '" dir="' . ($ar ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . $title . '</title></head><body style="font-family:Arial,Tahoma,sans-serif;background:#f4f6f8;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0"><div style="background:#fff;padding:32px;border-radius:14px;max-width:420px;text-align:center;box-shadow:0 4px 20px #0001"><h1 style="font-size:22px">' . $title . '</h1><p style="color:#555;line-height:1.7">' . $msg . '</p>' . $btn . '</div></body></html>';
        exit;
    }

    /** GET /p/c/{token}/{step} - ضغطة على اللينك */
    public function click(array $params = []): array
    {
        $s = $this->service();
        $step = max(1, min(3, (int) ($params['step'] ?? 1)));
        $lead = $s->track((string) ($params['token'] ?? ''), $step, 'click');
        $url = $lead ? $s->signupLinkFor($lead, $step) : (defined('APP_URL') ? rtrim(APP_URL, '/') : '/') . '/register';
        header('Location: ' . $url, true, 302);
        exit;
    }

    /** GET /p/o/{token}/{step} - فتح الرسالة (لو التتبع مفعّل) */
    public function open(array $params = []): array
    {
        try {
            $this->service()->track((string) ($params['token'] ?? ''), max(1, min(3, (int) ($params['step'] ?? 1))), 'open');
        } catch (Throwable $e) {
        }
        header('Content-Type: image/gif');
        header('Cache-Control: no-store');
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        exit;
    }
}
