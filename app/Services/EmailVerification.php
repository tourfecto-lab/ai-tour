<?php

/**
 * Tourfecto - تأكيد البريد الإلكتروني
 *
 * إزاي بيشتغل:
 *  1. أول ما العميل يسجّل، بنبعتله إيميل فيه رابط /verify-email/{token}
 *     (التوكن بيتخزن مشفّر sha256 وصلاحيته 24 ساعة).
 *  2. العميل يقدر يستخدم المنصة عادي لمدة "مهلة" (افتراضي 72 ساعة)
 *     وفوق اللوحة بيظهرله شريط "أكّد بريدك" فيه زرار إعادة إرسال.
 *  3. بعد المهلة، صفحات اللوحة بتحوّله لصفحة /verify-email لحد ما يأكّد.
 *
 * مابيتقفلش على:
 *  - الأدمن، وأعضاء فريق العمل (اللي صاحب الحساب ضافهم - إيميلهم اتعرف من الدعوة).
 *  - الحسابات القديمة (الـ migration بيعلّمها متأكدة).
 *  - لو البريد (SMTP) مش متظبط - عشان محدش يتقفل بسبب إعداد ناقص عندنا.
 *  - لو جدول التوكنات مش موجود (الـ migration لسه ماتشغّلش).
 *
 * إعدادات اختيارية في .env:
 *   EMAIL_VERIFICATION_REQUIRED=true   (false = الشريط بس من غير قفل)
 *   EMAIL_VERIFICATION_GRACE_HOURS=72
 */
class EmailVerification
{
    private const TOKEN_TTL_HOURS = 24;
    private const ADMIN_ROLES = ['super_admin', 'admin'];

    private static ?bool $tableReady = null;

    public static function tableReady(): bool
    {
        if (self::$tableReady !== null) {
            return self::$tableReady;
        }
        try {
            $rows = Database::getInstance()->query("SHOW TABLES LIKE 'email_verification_tokens'");
            self::$tableReady = !empty($rows);
        } catch (Throwable $e) {
            self::$tableReady = false;
        }
        return self::$tableReady;
    }

    public static function graceHours(): int
    {
        $v = function_exists('env') ? env('EMAIL_VERIFICATION_GRACE_HOURS') : getenv('EMAIL_VERIFICATION_GRACE_HOURS');
        $h = ($v === null || $v === false || $v === '') ? 72 : (int) $v;
        return max(0, min($h, 24 * 30));
    }

    public static function enforcementOn(): bool
    {
        $v = function_exists('env') ? env('EMAIL_VERIFICATION_REQUIRED', 'default') : getenv('EMAIL_VERIFICATION_REQUIRED');
        if (is_bool($v)) {
            return $v;
        }
        if ($v === null || $v === false || $v === '' || $v === 'default') {
            return true;
        }
        return !in_array(strtolower((string) $v), ['0', 'false', 'no', 'off'], true);
    }

    /** @var array<int,array> */
    private static array $fresh = [];

    /**
     * بيانات المستخدم من الداتابيز مباشرة (بيانات السيشن ممكن تكون قديمة أو
     * ناقصها created_at حسب طريقة تسجيل الدخول).
     */
    private static function fresh(array $user): ?array
    {
        $id = (int) ($user['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        if (!array_key_exists($id, self::$fresh)) {
            try {
                $rows = Database::getInstance()->query(
                    'SELECT id, email, role, owner_user_id, email_verified, email_verified_at, created_at FROM users WHERE id = ? LIMIT 1',
                    [$id]
                );
                self::$fresh[$id] = $rows[0] ?? null;
            } catch (Throwable $e) {
                self::$fresh[$id] = null;
            }
        }
        return self::$fresh[$id];
    }

    /** المستخدم ده محتاج يأكّد بريده؟ (من غير ما نحدد لسه هل نقفل ولا لأ) */
    public static function isPending(?array $user): bool
    {
        if (empty($user['id']) || !self::tableReady()) {
            return false;
        }
        $user = self::fresh($user);
        if (!$user) {
            return false;
        }
        if (!empty($user['email_verified_at']) || !empty($user['email_verified'])) {
            return false;
        }
        if (in_array((string) ($user['role'] ?? ''), self::ADMIN_ROLES, true)) {
            return false;
        }
        if (!empty($user['owner_user_id'])) {
            return false;
        }
        return true;
    }

    /** الساعات المتبقية من المهلة (0 = خلصت) */
    public static function hoursLeft(array $user): int
    {
        $user = self::fresh($user) ?? $user;
        $created = strtotime((string) ($user['created_at'] ?? '')) ?: time();
        $left = ($created + self::graceHours() * 3600) - time();
        return $left > 0 ? (int) ceil($left / 3600) : 0;
    }

    /** نقفل اللوحة عليه دلوقتي؟ */
    public static function mustBlock(?array $user): bool
    {
        if (!self::isPending($user) || !self::enforcementOn()) {
            return false;
        }
        if (self::hoursLeft($user) > 0) {
            return false;
        }
        try {
            if (!(new Mailer())->isConfigured()) {
                return false;
            }
        } catch (Throwable $e) {
            return false;
        }
        return true;
    }

    /**
     * بيعمل توكن جديد ويبعت الإيميل.
     * @return array{success:bool, error?:string, throttled?:bool}
     */
    public static function send(int $userId): array
    {
        if (!self::tableReady()) {
            return ['success' => false, 'error' => 'not_ready'];
        }
        $db = Database::getInstance();
        $rows = $db->query("SELECT id, email, first_name, company_name, email_verified_at FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (empty($rows)) {
            return ['success' => false, 'error' => 'not_found'];
        }
        $u = $rows[0];
        if (!empty($u['email_verified_at'])) {
            return ['success' => true, 'already' => true];
        }

        // حماية من الإغراق: 5 إيميلات كحد أقصى في الساعة لنفس الحساب
        $recent = $db->query(
            "SELECT COUNT(*) AS c FROM email_verification_tokens WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)",
            [$userId]
        );
        if ((int) ($recent[0]['c'] ?? 0) >= 5) {
            return ['success' => false, 'throttled' => true, 'error' => 'throttled'];
        }

        $raw = bin2hex(random_bytes(32));
        $db->exec("UPDATE email_verification_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL", [$userId]);
        $db->query(
            "INSERT INTO email_verification_tokens (user_id, token_hash, expires_at, created_at) VALUES (?, ?, NOW() + INTERVAL " . self::TOKEN_TTL_HOURS . " HOUR, NOW())",
            [$userId, hash('sha256', $raw)]
        );

        $url = rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/verify-email/' . $raw;
        $name = trim((string) ($u['first_name'] ?: $u['company_name'] ?: ''));

        try {
            $mailer = new Mailer();
            if (!$mailer->isConfigured()) {
                Logger::warning('Email verification: mail not configured', ['user_id' => $userId]);
                return ['success' => false, 'error' => 'mail_not_configured'];
            }
            $res = $mailer->context('email_verification', $userId)
                ->send((string) $u['email'], $name, 'أكّد بريدك الإلكتروني - ' . (defined('APP_NAME') ? APP_NAME : 'Tourfecto'), self::emailHtml($name, $url));
            if (empty($res['success'])) {
                Logger::error('Email verification send failed', ['user_id' => $userId, 'error' => $res['error'] ?? '']);
                return ['success' => false, 'error' => 'send_failed'];
            }
        } catch (Throwable $e) {
            Logger::error('Email verification exception', ['user_id' => $userId, 'message' => $e->getMessage()]);
            return ['success' => false, 'error' => 'send_failed'];
        }
        return ['success' => true];
    }

    /**
     * يتحقق من التوكن ويعلّم الحساب متأكد.
     * @return int|null رقم المستخدم لو نجح
     */
    public static function verify(string $rawToken): ?int
    {
        if (!self::tableReady() || !preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
            return null;
        }
        $db = Database::getInstance();
        $rows = $db->query(
            "SELECT id, user_id FROM email_verification_tokens
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1",
            [hash('sha256', $rawToken)]
        );
        if (empty($rows)) {
            return null;
        }
        $uid = (int) $rows[0]['user_id'];
        $db->exec("UPDATE email_verification_tokens SET used_at = NOW() WHERE id = ?", [(int) $rows[0]['id']]);
        self::markVerified($uid);
        return $uid;
    }

    public static function markVerified(int $userId): void
    {
        $db = Database::getInstance();
        $db->exec("UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()), email_verified = 1 WHERE id = ?", [$userId]);
        unset(self::$fresh[$userId]);
        $db->exec("UPDATE email_verification_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL", [$userId]);
        if (session_status() === PHP_SESSION_ACTIVE && (int) ($_SESSION['user_id'] ?? 0) === $userId && is_array($_SESSION['user'] ?? null)) {
            $_SESSION['user']['email_verified_at'] = date('Y-m-d H:i:s');
            $_SESSION['user']['email_verified'] = 1;
        }
    }

    /** شريط التنبيه اللي فوق اللوحة (فاضي لو مش محتاج) */
    public static function bannerHtml(?array $user): string
    {
        if (!self::isPending($user)) {
            return '';
        }
        $user = self::fresh($user) ?? $user;
        $email = htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8');
        $left = self::hoursLeft($user);
        $when = (self::enforcementOn() && $left > 0)
            ? ' لازم تأكّده خلال ' . ($left >= 24 ? ceil($left / 24) . ' يوم' : $left . ' ساعة') . ' عشان تفضل تقدر تستخدم اللوحة.'
            : '';
        return <<<HTML
<div class="ev-banner" id="evBanner" role="status" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:0 0 16px;padding:12px 16px;border-radius:12px;background:rgba(239,176,94,.14);border:1px solid rgba(239,176,94,.45);font-size:14px;line-height:1.7">
  <span style="font-size:18px" aria-hidden="true">✉️</span>
  <span style="flex:1;min-width:220px">بعتنالك رابط تأكيد على <b dir="ltr">{$email}</b>.{$when}</span>
  <button type="button" id="evResend" style="padding:7px 16px;border-radius:20px;border:0;background:#EFB05E;color:#14100a;font-weight:600;cursor:pointer;font-family:inherit">ابعت الرابط تاني</button>
</div>
<script>
(function(){var b=document.getElementById('evResend');if(!b)return;b.addEventListener('click',function(){b.disabled=true;var t=b.textContent;b.textContent='بيتبعت...';
fetch('/api/auth/resend-verification',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:'{}'})
.then(function(r){return r.json().catch(function(){return {};});}).then(function(d){d=d||{};b.textContent=d.success?(d.message||'تم الإرسال ✓'):(d.error||'حصلت مشكلة، جرّب تاني');setTimeout(function(){b.textContent=t;b.disabled=false;},d.success?30000:4000);})
.catch(function(){b.textContent='حصلت مشكلة، جرّب تاني';b.disabled=false;});});})();
</script>
HTML;
    }

    private static function emailHtml(string $name, string $url): string
    {
        $appName = htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'Tourfecto', ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars($name ?: 'عزيزي العميل', ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        return <<<HTML
<div dir="rtl" style="font-family: sans-serif; max-width: 480px; margin: 0 auto; padding: 24px;">
    <h2 style="color: #14100a;">أهلًا {$safeName} 👋</h2>
    <p style="color: #444; line-height: 1.8;">شكرًا إنك سجّلت في {$appName}. فاضل خطوة واحدة: أكّد إن البريد ده بتاعك عشان نقدر نبعتلك التنبيهات والفواتير ورسايل استعادة الحساب.</p>
    <p style="text-align: center; margin: 30px 0;">
        <a href="{$safeUrl}" style="background:#EFB05E;color:#14100a;padding:14px 32px;border-radius:30px;text-decoration:none;font-weight:bold;">تأكيد البريد الإلكتروني</a>
    </p>
    <p style="color: #888; font-size: 12.5px;">الرابط صالح لمدة 24 ساعة. لو مش انت اللي سجّلت، تجاهل الإيميل ده.</p>
    <p style="color: #888; font-size: 11.5px; word-break: break-all;">أو انسخ الرابط ده: {$safeUrl}</p>
</div>
HTML;
    }
}
