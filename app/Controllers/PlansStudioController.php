<?php
require_once __DIR__ . '/../Services/Subscription/PlanExtras.php';
require_once __DIR__ . '/../Services/Payment/CurrencyService.php';

/**
 * Tourfecto - الباقات المرنة + العملات
 * العميل: التجربة المجانية، الإضافات، ابني باقتك، اختيار العملة
 * الأدمن: الباقات المقترحة، أسعار الإضافات والخدمات، أسعار الصرف
 */
class PlansStudioController extends Controller
{
    private function isAdmin(): bool
    {
        return $this->isAuthenticated() && in_array($this->user['role'] ?? '', ['admin', 'super_admin'], true);
    }

    private function ar(): bool
    {
        $l = strtolower((string) ($_COOKIE['tf_lang'] ?? $this->user['language'] ?? 'ar'));
        return !str_starts_with($l, 'en');
    }

    /** أعضاء الفريق بيشتروا على حساب صاحب الشركة (زي PlanUsage) */
    private function owner(): int
    {
        $uid = (int) $this->user['id'];
        $r = $this->db->query('SELECT owner_user_id FROM users WHERE id = ? LIMIT 1', [$uid]);
        $owner = (int) ($r[0]['owner_user_id'] ?? 0);
        return $owner > 0 ? $owner : $uid;
    }

    private function money(float $usd, string $cur): array
    {
        return ['usd' => round($usd, 2), 'local' => CurrencyService::fromUsd($usd, $cur), 'label' => CurrencyService::format($usd, $cur, $this->ar()), 'usd_label' => CurrencyService::format($usd, 'USD')];
    }

    private function fail(Throwable $e): array
    {
        if ($e instanceof DomainException) {
            $d = json_decode($e->getMessage(), true) ?: [];
            $cur = CurrencyService::forViewer($this->user);
            $msg = $d['error'] ?? 'رصيد محفظتك مش كفاية.';
            if (isset($d['shortfall']) && $d['shortfall'] > 0) {
                $msg .= ' ناقص ' . CurrencyService::format((float) $d['shortfall'], $cur, $this->ar()) . ' - اشحن المحفظة وكمّل.';
            }
            return $this->error($msg, 402, ['action' => '/subscription#wallet'] + $d);
        }
        if ($e instanceof InvalidArgumentException) {
            return $this->error($e->getMessage(), 422);
        }
        Logger::error('Plans studio action failed', ['error' => $e->getMessage()]);
        return $this->error('حصلت مشكلة، جرّب تاني أو كلّم الدعم.', 500);
    }

    // ------------------------------------------------------------------ عام

    /** POST /api/currency - اختيار عملة العرض */
    public function setCurrency(array $params = []): array
    {
        $code = strtoupper((string) $this->get('code', ''));
        if (!CurrencyService::valid($code)) {
            return $this->error('عملة غير مدعومة', 422);
        }
        setcookie(CurrencyService::COOKIE, $code, ['expires' => time() + 365 * 86400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => false, 'samesite' => 'Lax']);
        $_COOKIE[CurrencyService::COOKIE] = $code;
        return $this->success(['currency' => $code]);
    }

    // ------------------------------------------------------------------ العميل

    /** GET /api/plans/overview */
    public function overview(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        header('Cache-Control: private, no-store');
        try {
            $uid = $this->owner();
            $x = new PlanExtras();
            $cfg = $x->config();
            $cur = CurrencyService::forViewer($this->user);
            $ar = $this->ar();
            $plans = [];
            foreach (BillingPlanCatalog::read() as $key => $p) {
                $plans[] = ['key' => $key, 'name' => (string) $p['name'], 'monthly' => $this->money((float) $p['price_monthly'], $cur), 'yearly' => $this->money((float) $p['price_yearly'], $cur), 'features' => $p['features']];
            }
            $sub = Subscription::activeSubscriptionRow($uid) ?: null;
            $current = null;
            if ($sub) {
                $current = [
                    'id' => (int) $sub['id'], 'plan' => (string) ($sub['plan_name'] ?? ''), 'cycle' => (string) ($sub['plan_type'] ?? ''),
                    'status' => (string) ($sub['lifecycle_status'] ?? $sub['status'] ?? ''), 'period_end' => (string) ($sub['current_period_end'] ?? ''),
                    'trial_ends_at' => (string) ($sub['trial_ends_at'] ?? ''), 'price' => $this->money((float) ($sub['price'] ?? 0), $cur),
                    'custom' => $x->customFor((int) $sub['id']), 'extras' => $x->activeExtras((int) $sub['id']),
                ];
            }
            $packs = [];
            foreach ($cfg['packs'] as $k => $p) {
                if (!$p['enabled'] || !$cfg['services'][$p['service']]['enabled']) {
                    continue;
                }
                $s = $cfg['services'][$p['service']];
                $packs[] = ['key' => $k, 'icon' => $p['icon'], 'qty' => $p['qty'], 'service' => $p['service'], 'label' => $ar ? $s['ar'] : $s['en'], 'price' => $this->money((float) $p['price'], $cur)];
            }
            $services = [];
            foreach ($cfg['services'] as $k => $s) {
                if ($s['enabled']) {
                    $services[] = ['key' => $k, 'label' => $ar ? $s['ar'] : $s['en'], 'unit' => $s['unit'], 'step' => $s['step'], 'max' => $s['max'], 'local_rate' => CurrencyService::rate($cur)];
                }
            }
            $balance = (new WalletService())->getBalance($uid);
            return $this->success([
                'currency' => $cur, 'currencies' => CurrencyService::publicList($ar), 'rate' => CurrencyService::rate($cur),
                'plans' => $plans, 'current' => $current, 'trial' => ['eligible' => $x->trialEligible($uid), 'days' => $cfg['trial_days'], 'plan' => $cfg['trial_plan']],
                'packs' => $packs, 'builder' => ['enabled' => $cfg['builder_enabled'], 'services' => $services],
                'wallet' => $this->money($balance, $cur), 'csrf_token' => Csrf::token(),
            ]);
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    /** POST /api/plans/trial */
    public function trial(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($e = $this->verifyCsrf()) || ($e = $this->rateLimitGuard('user', 'plans_trial', 5, 3600))) {
            return $e;
        }
        try {
            $r = (new PlanExtras())->startTrial($this->owner());
            return $this->success($r, 'التجربة المجانية بدأت 🎉');
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    /** POST /api/plans/addon */
    public function addon(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($e = $this->verifyCsrf()) || ($e = $this->rateLimitGuard('user', 'plans_addon', 20, 300))) {
            return $e;
        }
        try {
            $key = $this->get('request_key');
            $r = (new PlanExtras())->buyPack($this->owner(), (string) $this->get('pack', ''), is_string($key) && strlen($key) >= 8 ? $key : null);
            return $this->success($r, 'الإضافة اتضافت لباقتك ✅');
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    private function extrasInput(): array
    {
        $in = $this->get('extras', []);
        $out = [];
        if (is_array($in)) {
            foreach ($in as $k => $v) {
                if (is_string($k) && is_numeric($v)) {
                    $out[$k] = (int) $v;
                }
            }
        }
        return $out;
    }

    /** POST /api/plans/custom/quote */
    public function customQuote(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try {
            $q = (new PlanExtras())->quote((string) $this->get('base', ''), (string) $this->get('cycle', 'monthly'), $this->extrasInput());
            $cur = CurrencyService::forViewer($this->user);
            $q['base_money'] = $this->money($q['base_price'], $cur);
            $q['extras_money'] = $this->money($q['extras_price'], $cur);
            $q['total_money'] = $this->money($q['total'], $cur);
            return $this->success($q);
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    /** POST /api/plans/custom/buy */
    public function customBuy(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($e = $this->verifyCsrf()) || ($e = $this->rateLimitGuard('user', 'plans_custom', 10, 600))) {
            return $e;
        }
        try {
            $key = $this->get('request_key');
            $r = (new PlanExtras())->buyCustom($this->owner(), (string) $this->get('base', ''), (string) $this->get('cycle', 'monthly'), $this->extrasInput(), is_string($key) ? $key : null);
            return $this->success($r, 'باقتك المخصصة اشتغلت ✅');
        } catch (Throwable $e) {
            return $this->fail($e);
        }
    }

    // ------------------------------------------------------------------ الأدمن

    /** GET /admin/plans-studio */
    public function adminPage(array $params = []): array
    {
        if (!$this->isAdmin()) {
            return $this->error('غير مصرح', 403);
        }
        $css = htmlspecialchars(asset_v('/assets/css/plans-studio.css'), ENT_QUOTES, 'UTF-8');
        $js = htmlspecialchars(asset_v('/assets/js/plans-studio-admin.js'), ENT_QUOTES, 'UTF-8');
        $body = '<link rel="stylesheet" href="' . $css . '"><section id="plansAdmin" class="ps" dir="rtl"><p>جاري التحميل…</p></section><script src="' . $js . '"></script>';
        echo $this->renderPanelPage('plans-studio', 'الباقات والعملات', 'باقات جاهزة، إضافات حسب الطلب، ابني باقتك، وأسعار الصرف', $body, 'window.PlansAdmin&&window.PlansAdmin.init(window.Panel);');
        exit;
    }

    /** GET /api/admin/plans-studio */
    public function adminData(array $params = []): array
    {
        if (!$this->isAdmin()) {
            return $this->error('غير مصرح', 403);
        }
        header('Cache-Control: private, no-store');
        $x = new PlanExtras();
        return $this->success(['config' => $x->config(), 'preset' => PlanExtras::PRESET, 'plans' => BillingPlanCatalog::read(), 'rates' => CurrencyService::rates(), 'currencies' => CurrencyService::CURRENCIES, 'csrf_token' => Csrf::token()]);
    }

    private function adminGuard(): ?array
    {
        if (!$this->isAdmin()) {
            return $this->error('غير مصرح', 403);
        }
        return $this->verifyCsrf() ?: $this->rateLimitGuard('user', 'plans_studio_admin', 30, 60);
    }

    /** POST /api/admin/plans-studio/config */
    public function adminSaveConfig(array $params = []): array
    {
        if ($e = $this->adminGuard()) {
            return $e;
        }
        $in = $this->get('config', []);
        return $this->success(['config' => (new PlanExtras())->saveConfig(is_array($in) ? $in : [])], 'اتحفظ');
    }

    /** POST /api/admin/plans-studio/preset */
    public function adminPreset(array $params = []): array
    {
        if ($e = $this->adminGuard()) {
            return $e;
        }
        try {
            $done = (new PlanExtras())->applyPreset();
            $this->log('Admin applied recommended plans', ['plans' => $done]);
            return $this->success(['applied' => $done, 'plans' => BillingPlanCatalog::read()], 'اتطبقت الباقات المقترحة');
        } catch (Throwable $e) {
            Logger::error('Preset failed', ['error' => $e->getMessage()]);
            return $this->error('مقدرناش نطبق الباقات: ' . $e->getMessage(), 500);
        }
    }

    /** POST /api/admin/currency/rates */
    public function adminSaveRates(array $params = []): array
    {
        if ($e = $this->adminGuard()) {
            return $e;
        }
        try {
            $in = $this->get('rates', []);
            return $this->success(['rates' => CurrencyService::saveRates(is_array($in) ? $in : [])], 'اتحفظت أسعار الصرف');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** POST /api/admin/currency/refresh */
    public function adminRefreshRates(array $params = []): array
    {
        if ($e = $this->adminGuard()) {
            return $e;
        }
        try {
            $u = CurrencyService::refreshFromMarket();
            return $this->success(['updated' => $u, 'rates' => CurrencyService::rates()], $u ? 'اتحدّثت: ' . implode('، ', $u) : 'كل العملات مقفولة على سعر يدوي');
        } catch (Throwable $e) {
            return $this->error($e->getMessage(), 502);
        }
    }
}
