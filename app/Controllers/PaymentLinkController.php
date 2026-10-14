<?php

/**
 * Tourfecto - لينكات الدفع (عربون / المبلغ كله)
 *
 * لصاحب الشركة (بتسجيل دخول):
 *   GET  /api/bookings/{id}/payment-links      اللينكات اللي اتعملت للحجز
 *   POST /api/bookings/{id}/payment-links      لينك جديد {kind: deposit_pct|deposit_fixed|full|balance, value, hours}
 *   POST /api/payment-links/{id}/cancel        إلغاء لينك
 *   GET  /api/chat/customer-bookings?phone=&email=   حجوزات العميل (لزرار "لينك دفع" في الشات)
 * للعميل (عام):
 *   GET  /pay/{token}            صفحة الدفع
 *   POST /pay/{token}/checkout   تحويل لـ Stripe
 */
class PaymentLinkController extends Controller
{
    private function ownerId(): int
    {
        $id = (int) ($this->user['id'] ?? 0);
        try {
            $o = (int) ($this->db->query('SELECT owner_user_id FROM users WHERE id = ? LIMIT 1', [$id])[0]['owner_user_id'] ?? 0);
            return $o > 0 ? $o : $id;
        } catch (Throwable $e) {
            return $id;
        }
    }

    private function isViewer(): bool
    {
        try {
            $r = $this->db->query('SELECT owner_user_id, workspace_role FROM users WHERE id = ? LIMIT 1', [(int) ($this->user['id'] ?? 0)]);
            return !$r || ((int) $r[0]['owner_user_id'] > 0 && !in_array(($r[0]['workspace_role'] ?? ''), ['admin', 'manager', 'sales', 'support'], true));
        } catch (Throwable $e) {
            return true;
        }
    }

    public function listForBooking(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $mode = class_exists('OperatorPayments') ? OperatorPayments::effectiveMode($this->ownerId()) : 'request';
        return $this->success([
            'links' => (new PaymentLinkService())->listForBooking($this->ownerId(), (int) ($params['id'] ?? 0)),
            'online_payments' => $mode !== 'request',
        ]);
    }

    public function create(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($this->isViewer()) {
            return $this->error('صلاحيتك مشاهدة بس', 403);
        }
        if ($rl = $this->rateLimitGuard('user', 'payment_link_create', 30, 600)) {
            return $rl;
        }
        $r = (new PaymentLinkService())->create(
            $this->ownerId(),
            (int) $this->user['id'],
            (int) ($params['id'] ?? 0),
            (string) $this->get('kind', 'deposit_pct'),
            (float) $this->get('value', 30),
            (int) $this->get('hours', 48)
        );
        if (!$r['ok']) {
            return $this->error($r['error'], 422, !empty($r['needs_stripe']) ? ['needs_stripe' => true] : []);
        }
        return $this->success(['link' => $r['link']], 'اتعمل لينك الدفع', 201);
    }

    public function cancel(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($this->isViewer()) {
            return $this->error('صلاحيتك مشاهدة بس', 403);
        }
        (new PaymentLinkService())->cancel($this->ownerId(), (int) ($params['id'] ?? 0));
        return $this->success([], 'اتلغى اللينك');
    }

    /** حجوزات عميل المحادثة (بالموبايل أو الإيميل) - عشان زرار "لينك دفع" في صندوق الشات */
    public function customerBookings(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $phone = preg_replace('/\D/', '', (string) $this->get('phone', ''));
        $email = strtolower(trim((string) $this->get('email', '')));
        if (strlen($phone) < 7 && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->success(['bookings' => []]);
        }
        $tail = strlen($phone) >= 7 ? substr($phone, -9) : '###';
        $rows = $this->db->query(
            "SELECT b.id, b.booking_reference, b.start_date, b.status, b.total_amount, b.amount_paid, b.currency, p.name AS product_name
               FROM bookings b LEFT JOIN crm_products p ON p.id = b.product_id
              WHERE b.user_id = ? AND b.status IN ('pending','confirmed')
                AND (REPLACE(REPLACE(REPLACE(COALESCE(b.customer_phone,''),'+',''),' ',''),'-','') LIKE ? OR LOWER(COALESCE(b.customer_email,'')) = ?)
              ORDER BY b.start_date ASC LIMIT 10",
            [$this->ownerId(), '%' . $tail, $email !== '' ? $email : '###']
        );
        return $this->success(['bookings' => $rows]);
    }

    // ------------------------------------------------------------------ الصفحة العامة

    public function page(array $params = []): array
    {
        $token = (string) ($params['token'] ?? '');
        $svc = new PaymentLinkService();
        $justPaid = isset($_GET['paid']) || isset($_GET['session_id']);
        $v = $svc->publicView($token, !$justPaid);
        if ($v && $justPaid && $v['link']['status'] === 'active' && class_exists('OperatorPayments')) {
            // حساب Stripe بتاع الشركة مش بيبعتلنا Webhook - بنتحقق بنفسنا أول ما العميل يرجع
            try {
                OperatorPayments::verifyBooking((int) $v['link']['booking_id']);
            } catch (Throwable $e) {
            }
            $v = $svc->publicView($token);
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        echo $this->renderPayPage($token, $v, $justPaid);
        exit;
    }

    public function checkout(array $params = []): array
    {
        if ($rl = $this->rateLimitGuard('ip', 'pay_checkout', 20, 600)) {
            return $rl;
        }
        $r = (new PaymentLinkService())->checkoutUrl((string) ($params['token'] ?? ''));
        if (!$r['ok']) {
            return $this->error($r['error'], 422);
        }
        return $this->success(['url' => $r['url']]);
    }

    /** وقت قاعدة البيانات (UTC) بتوقيت الشركة */
    private function localTime(string $utc): string
    {
        try {
            $tz = new DateTimeZone(function_exists('env') ? (string) (env('CRON_TIMEZONE') ?: 'Africa/Cairo') : 'Africa/Cairo');
            return (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d H:i');
        } catch (Throwable $e) {
            return substr($utc, 0, 16);
        }
    }

    private function renderPayPage(string $token, ?array $v, bool $justPaid): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $money = fn ($n, $c) => $e(rtrim(rtrim(number_format((float) $n, 2, '.', ','), '0'), '.') . ' ' . $c);
        if (!$v || !$v['booking']) {
            $body = '<div class="icon">🔗</div><h1>اللينك ده مش صحيح</h1><p class="muted">اتأكد من الرابط أو اطلب لينك جديد من الشركة.</p>';
            $company = 'Tourfecto';
        } else {
            $l = $v['link'];
            $b = $v['booking'];
            $company = $v['company'] !== '' ? $v['company'] : 'Tourfecto';
            $people = (int) $b['adults_count'] + (int) ($b['children_count'] ?? 0);
            $remainingAfter = max(0, (float) $b['total_amount'] - (float) $b['amount_paid'] - ($l['status'] === 'paid' ? 0 : (float) $l['amount']));
            $rows = '<div class="row"><span>الرحلة</span><b>' . $e($b['product_name'] ?: '-') . '</b></div>'
                . '<div class="row"><span>التاريخ</span><b dir="ltr">' . $e($b['start_date']) . ($b['start_time'] ? ' ' . $e(substr((string) $b['start_time'], 0, 5)) : '') . '</b></div>'
                . '<div class="row"><span>الاسم</span><b>' . $e($b['customer_name']) . '</b></div>'
                . '<div class="row"><span>الأفراد</span><b>' . $people . '</b></div>'
                . '<div class="row"><span>إجمالي الحجز</span><b dir="ltr">' . $money($b['total_amount'], $b['currency']) . '</b></div>'
                . '<div class="row"><span>كود الحجز</span><b dir="ltr">' . $e($b['booking_reference']) . '</b></div>';
            if ($l['status'] === 'paid') {
                $body = '<div class="icon ok">✓</div><h1>تم الدفع - حجزك اتأكد</h1>'
                    . '<p class="muted">دفعت ' . $money($l['amount'], $l['currency']) . '. هيوصلك تأكيد من ' . $e($company) . '.</p>' . $rows
                    . ($remainingAfter > 0 ? '<p class="note">الباقي ' . $money($remainingAfter, $b['currency']) . ' بيتدفع حسب اتفاقك مع الشركة.</p>' : '');
            } elseif ($l['status'] !== 'active') {
                $body = '<div class="icon">⌛</div><h1>اللينك ده مابقاش شغّال</h1><p class="muted">اطلب لينك دفع جديد من ' . $e($company) . '.</p>' . $rows;
            } elseif (!in_array($b['status'], ['pending', 'confirmed'], true)) {
                $body = '<div class="icon">⚠️</div><h1>الحجز ده اتلغى</h1><p class="muted">كلّم ' . $e($company) . ' لو محتاج مساعدة.</p>';
            } else {
                $label = $l['kind'] === 'deposit' ? 'العربون المطلوب' : 'المبلغ المطلوب';
                $wait = $justPaid ? '<p class="note">لو لسه دافع حالًا، استنى دقيقة وحدّث الصفحة - التأكيد بيوصل خلال لحظات.</p>' : '';
                $body = '<h1>' . ($l['kind'] === 'deposit' ? 'ادفع العربون وأكّد حجزك' : 'ادفع حجزك') . '</h1>'
                    . '<p class="muted">' . $e($company) . '</p>' . $rows
                    . '<div class="amount"><span>' . $label . '</span><b dir="ltr">' . $money($l['amount'], $l['currency']) . '</b></div>'
                    . ($remainingAfter > 0 ? '<p class="note">الباقي بعد العربون: ' . $money($remainingAfter, $b['currency']) . '</p>' : '')
                    . $wait
                    . '<button id="payBtn" type="button">💳 ادفع بأمان</button><p id="payErr" class="err"></p>'
                    . '<p class="muted small">الدفع عن طريق Stripe - بيانات الكارت مابتوصلناش. اللينك صالح لحد <span dir="ltr">' . $e($this->localTime((string) $l['expires_at'])) . '</span></p>';
            }
        }
        $tokenJs = json_encode($token);
        $title = $e($company) . ' - دفع الحجز';
        return <<<HTML
<!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>{$title}</title>
<style>
:root{--bg:#f4f6fb;--card:#fff;--text:#0f1b2d;--muted:#5d687c;--accent:#1f4fff;--ok:#0e9f6e;--line:#e6e9f0}
@media (prefers-color-scheme:dark){:root{--bg:#0b1220;--card:#121a2b;--text:#e8edf7;--muted:#9aa6bd;--line:#223049}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:Tahoma,"Segoe UI",Arial,sans-serif;display:flex;justify-content:center;padding:24px 16px}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;max-width:440px;width:100%;padding:22px 20px;box-shadow:0 18px 40px -24px rgba(11,27,51,.35)}
h1{font-size:20px;margin:0 0 6px}.muted{color:var(--muted);margin:0 0 14px;font-size:14px}.small{font-size:12px;margin-top:12px}
.row{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px solid var(--line);font-size:14px}.row span{color:var(--muted)}
.amount{display:flex;justify-content:space-between;align-items:center;margin:16px 0 6px;padding:14px;border-radius:12px;background:color-mix(in srgb,var(--accent) 9%,transparent)}
.amount b{font-size:22px}.note{font-size:13px;color:var(--muted)}
button{width:100%;margin-top:14px;padding:14px;border:0;border-radius:12px;background:var(--accent);color:#fff;font-size:16px;font-weight:700;cursor:pointer}
button:disabled{opacity:.6}.err{color:#d33;font-size:13px;min-height:18px}
.icon{font-size:40px;text-align:center;margin-bottom:6px}.icon.ok{color:var(--ok);font-weight:700}
</style></head><body><div class="card">{$body}</div>
<script>
(function(){var b=document.getElementById('payBtn');if(!b)return;b.addEventListener('click',async function(){
b.disabled=true;b.textContent='⏳ بنجهّز صفحة الدفع...';var er=document.getElementById('payErr');er.textContent='';
try{var r=await fetch('/pay/'+encodeURIComponent({$tokenJs})+'/checkout',{method:'POST',headers:{'Accept':'application/json','Content-Type':'application/json'},body:'{}'});
var d=await r.json();if(d.success&&d.data&&d.data.url){location.href=d.data.url;return;}er.textContent=d.error||'حصلت مشكلة، جرّب تاني';}
catch(e){er.textContent='حصلت مشكلة في الاتصال، جرّب تاني';}b.disabled=false;b.textContent='💳 ادفع بأمان';});})();
</script></body></html>
HTML;
    }
}
