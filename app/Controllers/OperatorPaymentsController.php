<?php

/**
 * استلام فلوس الحجوزات (منشئ المواقع ← 💳 استلام الفلوس)
 *  GET    /api/operator-payments          الإعدادات الحالية
 *  POST   /api/operator-payments          {mode, stripe_secret_key?}
 *  DELETE /api/operator-payments/key      شيل مفتاح Stripe
 */
class OperatorPaymentsController extends Controller
{
    private function ownerId(): ?int
    {
        if (empty($this->user['id'])) {
            return null;
        }
        // عضو الفريق مايغيّرش حساب استلام الفلوس - صاحب الحساب بس
        return empty($this->user['owner_user_id']) ? (int) $this->user['id'] : null;
    }

    public function show(array $params = []): array
    {
        $uid = (int) ($this->user['id'] ?? 0);
        if ($uid <= 0) {
            return $this->error('غير مسجّل', 401);
        }
        $owner = !empty($this->user['owner_user_id']) ? (int) $this->user['owner_user_id'] : $uid;
        return $this->success(OperatorPayments::settings($owner) + ['can_edit' => $this->ownerId() !== null]);
    }

    public function save(array $params = []): array
    {
        $uid = $this->ownerId();
        if ($uid === null) {
            return $this->error('صاحب الحساب بس يقدر يغيّر طريقة استلام الفلوس', 403);
        }
        if ($rate = $this->rateLimitGuard('user', 'operator_payments', 10, 60)) {
            return $rate;
        }
        $key = $this->get('stripe_secret_key');
        $r = OperatorPayments::save($uid, (string) $this->get('mode', 'request'), $key !== null ? (string) $key : null);
        if (empty($r['success'])) {
            return $this->error($r['error'] ?? 'تعذر الحفظ', 422);
        }
        $this->log('operator_payments_updated', ['mode' => $this->get('mode'), 'key_changed' => $key !== null && $key !== '']);
        return $this->success(OperatorPayments::settings($uid), 'تم الحفظ');
    }

    public function removeKey(array $params = []): array
    {
        $uid = $this->ownerId();
        if ($uid === null) {
            return $this->error('صاحب الحساب بس يقدر يغيّر طريقة استلام الفلوس', 403);
        }
        OperatorPayments::removeKey($uid);
        return $this->success(OperatorPayments::settings($uid), 'اتشال مفتاح Stripe - الحجوزات بقت طلبات حجز من غير دفع أونلاين');
    }
}
