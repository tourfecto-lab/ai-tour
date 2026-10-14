<?php
/**
 * Tourfecto - Wallet Service
 * منطق المحفظة: طلب إيداع، موافقة الأدمن، الخصم التلقائي وقت الاشتراك.
 * الرصيد بيتحسب دايمًا من مجموع الحركات المكتملة (مش عمود منفصل) -
 * أضمن وأسهل في التدقيق، وميحصلش تضارب بيانات لو حصل خطأ في مكان.
 * @version 1.0.0
 */
class WalletService {
    /** @var Database */
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * الرصيد الحالي = مجموع الإيداعات المكتملة - مجموع الخصومات (المخزّنة كأرقام سالبة أصلاً)
     */
    public function getBalance(int $userId): float {
        $rows = $this->db->query(
            "SELECT COALESCE(SUM(amount), 0) AS balance FROM wallet_transactions WHERE user_id = ? AND status = 'completed'",
            [$userId]
        );
        return (float) ($rows[0]['balance'] ?? 0);
    }

    /**
     * طلب إيداع جديد - بيتسجّل كـ "pending" لحد ما الأدمن يوافق بعد ما
     * يستلم التحويل فعليًا (بنك/PayPal) ويتأكد عن طريق واتساب.
     */
    public function requestDeposit(int $userId, float $amount, string $paymentMethod, string $note = ''): WalletTransaction {
        if ($amount <= 0) {
            throw new Exception('المبلغ لازم يكون أكبر من صفر');
        }
        if (!in_array($paymentMethod, ['iban', 'paypal'], true)) {
            throw new Exception('طريقة دفع غير معروفة');
        }

        $tx = new WalletTransaction();
        $tx->fill([
            'user_id' => $userId,
            'type' => 'deposit',
            'amount' => $amount,
            'currency' => 'USD',
            'status' => 'pending',
            'payment_method' => $paymentMethod,
            'reference_note' => $note,
        ]);
        $tx->save();

        ActivityLog::record('wallet', 'wallet.deposit_requested', [
            'user_id' => $userId, 'subject_type' => 'wallet_transactions', 'subject_id' => (int) $tx->getAttribute('id'),
            'meta' => ['amount' => $amount, 'method' => $paymentMethod],
        ]);

        return $tx;
    }

    /** موافقة الأدمن على إيداع - بيحوّل حالته لـ completed فيدخل في حساب الرصيد */
    public function approveDeposit(int $transactionId, int $adminId, string $adminNote = ''): WalletTransaction {
        $tx = (new WalletTransaction())->find($transactionId);
        if (!$tx || $tx->getAttribute('type') !== 'deposit') {
            throw new Exception('طلب الإيداع غير موجود');
        }
        if ($tx->getAttribute('status') !== 'pending') {
            throw new Exception('الطلب ده اتعالج بالفعل');
        }

        $tx->setAttribute('status', 'completed');
        $tx->setAttribute('admin_note', $adminNote);
        $tx->setAttribute('approved_by', $adminId);
        $tx->setAttribute('approved_at', date('Y-m-d H:i:s'));
        $tx->save();

        $userId = (int) $tx->getAttribute('user_id');
        if (class_exists('Notification')) {
            Notification::notify($userId, 'wallet_deposit_approved', 'تم شحن رصيدك',
                'تمت الموافقة على إيداعك بمبلغ ' . $tx->getAttribute('amount') . '$ وبقى متاح في محفظتك.', '/subscription');
        }

        ActivityLog::record('wallet', 'wallet.deposit_approved', [
            'user_id' => $adminId, 'subject_type' => 'wallet_transactions', 'subject_id' => $transactionId,
        ]);

        return $tx;
    }

    /** رفض طلب إيداع (لو الفلوس ما وصلتش فعليًا مثلاً) */
    public function rejectDeposit(int $transactionId, int $adminId, string $adminNote = ''): WalletTransaction {
        $tx = (new WalletTransaction())->find($transactionId);
        if (!$tx || $tx->getAttribute('type') !== 'deposit') {
            throw new Exception('طلب الإيداع غير موجود');
        }
        if ($tx->getAttribute('status') !== 'pending') {
            throw new Exception('الطلب ده اتعالج بالفعل');
        }

        $tx->setAttribute('status', 'rejected');
        $tx->setAttribute('admin_note', $adminNote);
        $tx->setAttribute('approved_by', $adminId);
        $tx->setAttribute('approved_at', date('Y-m-d H:i:s'));
        $tx->save();

        ActivityLog::record('wallet', 'wallet.deposit_rejected', [
            'user_id' => $adminId, 'subject_type' => 'wallet_transactions', 'subject_id' => $transactionId,
            'meta' => ['admin_note' => $adminNote],
        ]);

        return $tx;
    }

    /**
     * الاشتراك التلقائي من الرصيد: لو رصيد العميل كافي لسعر الباقة،
     * بنخصم المبلغ فورًا وننشئ الاشتراك مباشرة - من غير أي تدخل بشري
     * أو واتساب. بيرجّع نتيجة واضحة لو الرصيد مش كافي بدل ما يفشل صامت.
     *
     * تصحيح (2026-08-09 / Phase 1): الدالة دي كانت بتُستخدم أيضًا كمسار
     * الترقية/التخفيض الوحيد الشغّال فعليًا (زر "Upgrade" في /subscription
     * كان بيودّي هنا في النهاية عن طريق /plans)، لكنها كانت بتعامل كل
     * استدعاء كأنه اشتراك جديد بالكامل: بتخصم السعر الكامل للباقة الجديدة
     * حتى لو العميل عنده اشتراك فعّال شغال بالفعل، وبتسيب الاشتراك القديم
     * "active" من غير إلغاء - يعني العميل يدفع مرتين ويتراكم عنده أكتر من
     * صف "active" في نفس الوقت.
     *
     * الإصلاح: لو فيه اشتراك فعّال حالي، بنحسب الفرق بين سعر الباقة
     * الجديدة والسعر الفعلي المعروض حاليًا للعميل (نفس القيمة اللي شايفها
     * فعليًا في /subscription)، ونخصم الفرق بس (مش السعر الكامل)، ونلغي
     * الاشتراك القديم قبل ما ننشئ الجديد. الترقية (فرق موجب) لازم تتطلب
     * رصيد كافي زي أي عملية تانية. التخفيض (فرق سالب) بيتم من غير أي رد
     * فلوس تلقائي - سياسة محافظة موثّقة في CHANGELOG، ممكن تتغيّر لاحقًا
     * لو المشروع احتاج استرجاع جزئي حقيقي.
     *
     * @param int $userId
     * @param string $planKey
     * @param string $planType 'monthly' أو 'yearly'
     * @param string|null $idempotencyKey مفتاح فريد اختياري من العميل (مثلاً UUID
     *        مولّد في الفرونت وقت الضغطة) لمنع تكرار الخصم لو الطلب اتبعت
     *        مرتين (دبل-كليك أو إعادة محاولة شبكة). لو مش متبعت، بيتولّد
     *        سيرفر-سايد.
     */
    public function subscribeWithBalance(int $userId, string $planKey, string $planType, ?string $idempotencyKey = null): array {
        $plans = SubscriptionPlan::allAsLegacyArray();
        $plan = $plans[$planKey] ?? null;
        if (!$plan) {
            return ['success' => false, 'error' => 'الباقة غير موجودة'];
        }
        if (!in_array($planType, ['monthly', 'yearly'], true)) {
            return ['success' => false, 'error' => 'نوع الباقة غير معروف'];
        }

        $newPrice = $planType === 'yearly' ? (float) $plan['price_yearly'] : (float) $plan['price_monthly'];
        $idempotencyKey = $idempotencyKey ?: ('plan_change_' . $userId . '_' . (function_exists('generate_uuid') ? generate_uuid() : bin2hex(random_bytes(16))));

        // لو فيه اشتراك فعّال حالي فعلاً، ده تغيير باقة (upgrade/downgrade)
        // مش اشتراك جديد من الصفر - بنحسب الفرق بس.
        $currentRow = Subscription::activeSubscriptionRow($userId);
        $isPlanChange = $currentRow !== null;
        $oldPrice = $isPlanChange ? (float) $currentRow['price'] : 0.0;
        $chargeAmount = $isPlanChange ? round($newPrice - $oldPrice, 2) : $newPrice;

        $balance = $this->getBalance($userId);

        if ($chargeAmount > 0 && $balance < $chargeAmount) {
            return [
                'success' => false,
                'error' => $isPlanChange ? 'رصيدك الحالي غير كافي لدفع فرق السعر' : 'رصيدك الحالي غير كافي',
                'balance' => $balance,
                'required' => $chargeAmount,
                'shortfall' => round($chargeAmount - $balance, 2),
                'is_plan_change' => $isPlanChange,
            ];
        }

        try {
            return $this->db->transaction(function () use ($userId, $planKey, $planType, $plan, $newPrice, $oldPrice, $chargeAmount, $isPlanChange, $currentRow, $idempotencyKey) {
                // خصم الفرق (أو السعر الكامل لو اشتراك جديد) - كحركة مكتملة
                // فورًا. لو الفرق صفر أو سالب (تخفيض)، مفيش حركة محفظة خالص
                // (لا خصم ولا استرجاع تلقائي).
                $chargeTx = null;
                if ($chargeAmount > 0) {
                    $chargeTx = new WalletTransaction();
                    $chargeTx->fill([
                        'user_id' => $userId,
                        'type' => 'subscription_charge',
                        'amount' => -$chargeAmount,
                        'currency' => 'USD',
                        'status' => 'completed',
                        'related_subscription_plan' => $planKey,
                        'approved_at' => date('Y-m-d H:i:s'),
                        'idempotency_key' => $idempotencyKey,
                    ]);
                    $chargeTx->save();
                }

                // لو ده تغيير باقة، نلغي الاشتراك القديم أولاً عشان ميفضلش
                // صف "active" يتيم من غير ما يتحسب في أي مكان.
                if ($isPlanChange && !empty($currentRow['id'])) {
                    $this->db->exec("UPDATE `subscriptions` SET `status` = 'cancelled' WHERE `id` = ?", [(int) $currentRow['id']]);
                }

                // إنشاء الاشتراك الفعلي - نفس الدالة الحقيقية اللي بينده عليها
                // الأدمن وقت التفعيل اليدوي، عشان يبقى نفس المسار المضمون.
                $subscription = Subscription::createSubscription($userId, $planKey, $planType);
                if (!$subscription) {
                    throw new Exception('تعذر إنشاء الاشتراك الجديد بعد الخصم');
                }

                ActivityLog::record('wallet', $isPlanChange ? 'wallet.plan_changed' : 'wallet.auto_subscribed', [
                    'user_id' => $userId,
                    'subject_type' => 'wallet_transactions',
                    'subject_id' => $chargeTx ? (int) $chargeTx->getAttribute('id') : null,
                    'meta' => [
                        'plan' => $planKey, 'type' => $planType,
                        'old_price' => $oldPrice, 'new_price' => $newPrice, 'charged' => $chargeAmount,
                    ],
                ]);

                if (class_exists('Notification')) {
                    $msg = $isPlanChange
                        ? ($chargeAmount > 0
                            ? 'تم خصم فرق السعر ' . $chargeAmount . '$ من محفظتك وتفعيل باقة "' . $plan['name'] . '" فورًا.'
                            : 'تم تفعيل باقة "' . $plan['name'] . '" فورًا من غير أي خصم إضافي.')
                        : 'تم خصم ' . $chargeAmount . '$ من محفظتك وتفعيل باقة "' . $plan['name'] . '" فورًا.';
                    Notification::notify($userId, 'subscription_activated', 'تم تفعيل اشتراكك', $msg, '/subscription');
                }

                return [
                    'success' => true,
                    'subscription' => $subscription,
                    'new_balance' => $this->getBalance($userId),
                    'charged' => $chargeAmount,
                    'is_plan_change' => $isPlanChange,
                ];
            });
        } catch (Exception $e) {
            // مفتاح idempotency مكرر = نفس الطلب اتبعت قبل كده ونفّذ بنجاح -
            // نرجّع النتيجة الحالية بدل ما نخصم تاني أو نرجّع خطأ مربك.
            if (strpos($e->getMessage(), 'idempotency_key') !== false || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                Logger::warning('subscribeWithBalance duplicate idempotency key - returning current state', ['user_id' => $userId, 'idempotency_key' => $idempotencyKey]);
                return [
                    'success' => true,
                    'subscription' => Subscription::activeSubscriptionRow($userId),
                    'new_balance' => $this->getBalance($userId),
                    'duplicate_request' => true,
                ];
            }

            Logger::error('subscribeWithBalance Error', ['user_id' => $userId, 'message' => $e->getMessage()]);
            return ['success' => false, 'error' => 'تعذر إنشاء الاشتراك - تواصل مع الدعم الفني'];
        }
    }

    /** كل حركات محفظة مستخدم معيّن (لعرضها في صفحة الاشتراك) */
    public function getHistory(int $userId, int $limit = 30): array {
        return (new WalletTransaction())->where(['user_id' => $userId], ['created_at' => 'DESC'], $limit);
    }

    /**
     * الأدمن يضيف رصيد مباشر لعميل معيّن - بيتسجّل "مكتمل" فورًا (مفيش
     * موافقة لازمة، الأدمن نفسه هو اللي بيوافق بالفعل بضغطة الزرار).
     * (محتفَظ بيها للتوافق - بتستدعي adminAdjustBalance)
     */
    public function adminAddBalance(int $userId, float $amount, int $adminId, string $note = ''): WalletTransaction {
        if ($amount <= 0) {
            throw new Exception('المبلغ لازم يكون أكبر من صفر');
        }
        return $this->adminAdjustBalance($userId, $amount, $adminId, $note);
    }

    /** الأدمن يسحب رصيد من عميل (بيتسجّل كحركة سالبة، الرصيد الأصلي ما بيتمسحش) */
    public function adminDeductBalance(int $userId, float $amount, int $adminId, string $note = '', bool $allowNegative = false): WalletTransaction {
        if ($amount <= 0) {
            throw new Exception('المبلغ لازم يكون أكبر من صفر');
        }
        return $this->adminAdjustBalance($userId, -$amount, $adminId, $note, $allowNegative);
    }

    /**
     * تعديل رصيد عميل بحركة signed (موجب = إضافة، سالب = سحب).
     * - بيتنفّذ جوه transaction مع قفل صف العميل عشان عمليتين متزامنتين
     *   ميعدّوش فحص الرصيد مع بعض (race condition).
     * - السحب ممنوع يخلّي الرصيد سالب إلا لو $allowNegative = true صراحةً.
     * - نوع الحركة admin_adjustment فمش بيتحسب في إيرادات الإيداعات.
     */
    public function adminAdjustBalance(int $userId, float $amount, int $adminId, string $note = '', bool $allowNegative = false): WalletTransaction {
        $amount = round($amount, 2);
        if ($amount == 0.0) {
            throw new Exception('المبلغ لازم يكون مختلف عن صفر');
        }
        if (abs($amount) > 99999999.99) {
            throw new Exception('المبلغ أكبر من الحد المسموح');
        }

        $user = $this->db->query('SELECT id FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1', [$userId]);
        if (empty($user)) {
            throw new Exception('العميل غير موجود');
        }

        $isDeduct = $amount < 0;
        $note = trim(mb_substr($note, 0, 200));
        $defaultNote = $isDeduct ? 'سحب رصيد يدوي من الأدمن' : 'إضافة رصيد يدوية من الأدمن';

        $tx = $this->db->transaction(function () use ($userId, $amount, $adminId, $note, $defaultNote, $isDeduct, $allowNegative) {
            // قفل صف العميل لحد نهاية الـ transaction
            $this->db->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);

            if ($isDeduct && !$allowNegative) {
                $balance = $this->getBalance($userId);
                if ($balance + $amount < -0.00001) {
                    throw new Exception('رصيد العميل الحالي (' . number_format($balance, 2) . '$) أقل من المبلغ المطلوب سحبه (' . number_format(abs($amount), 2) . '$)');
                }
            }

            $tx = new WalletTransaction();
            $tx->fill([
                'user_id' => $userId,
                'type' => 'admin_adjustment',
                'amount' => $amount,
                'currency' => 'USD',
                'status' => 'completed',
                'payment_method' => 'admin',
                'reference_note' => $note !== '' ? $note : $defaultNote,
                'admin_note' => $defaultNote . ' (أدمن #' . $adminId . ')',
                'approved_by' => $adminId,
                'approved_at' => date('Y-m-d H:i:s'),
            ]);
            $tx->save();
            return $tx;
        });

        ActivityLog::record('wallet', $isDeduct ? 'wallet.admin_deducted' : 'wallet.admin_added', [
            'user_id' => $adminId, 'subject_type' => 'wallet_transactions', 'subject_id' => (int) $tx->getAttribute('id'),
            'meta' => ['target_user_id' => $userId, 'amount' => $amount, 'note' => $note],
        ]);

        if (class_exists('Notification')) {
            try {
                Notification::notify(
                    $userId,
                    $isDeduct ? 'wallet_admin_deduct' : 'wallet_admin_credit',
                    $isDeduct ? 'تم تعديل رصيد محفظتك' : 'تم شحن رصيدك',
                    ($isDeduct ? 'تم خصم ' : 'تمت إضافة ') . number_format(abs($amount), 2) . '$ ' . ($isDeduct ? 'من' : 'إلى') . ' محفظتك بواسطة الإدارة.',
                    '/subscription'
                );
            } catch (\Throwable $e) {
                // الإشعار مش لازم يفشّل العملية المالية نفسها
            }
        }

        return $tx;
    }

    // ============================================
    // رصيد المنصة (إجمالي أرصدة العملاء) - عرض وتصفير
    // ============================================

    /** إجمالي أرصدة كل العملاء الفعلي (من الدفتر) من غير أي تصفير عرض */
    private function getRawPlatformBalance(): float {
        $rows = $this->db->query("SELECT COALESCE(SUM(amount), 0) AS total FROM wallet_transactions WHERE status = 'completed'");
        return round((float) ($rows[0]['total'] ?? 0), 2);
    }

    /**
     * ملخص رصيد المنصة: الإجمالي الفعلي، نقطة التصفير (baseline)، والمعروض.
     * "تصفير رصيد المنصة" بيحفظ الإجمالي الحالي كنقطة صفر - مفيش أي
     * حركة عميل بتتمسح ولا أرصدة العملاء بتتغيّر، وممكن الرجوع عنه.
     */
    public function getPlatformBalanceSummary(): array {
        $raw = $this->getRawPlatformBalance();
        $settings = $this->getPaymentSettings();
        $baseline = round((float) ($settings['platform_balance_baseline'] ?? 0), 2);
        return [
            'raw' => $raw,
            'baseline' => $baseline,
            'displayed' => round($raw - $baseline, 2),
            'baseline_set_at' => $settings['platform_balance_baseline_at'] ?? null,
        ];
    }

    /** تصفير عدّاد رصيد المنصة (غير مدمّر - مجرد نقطة صفر جديدة) */
    public function resetPlatformBalance(int $adminId): array {
        $raw = $this->getRawPlatformBalance();
        $this->updatePaymentSetting('platform_balance_baseline', (string) $raw);
        $this->updatePaymentSetting('platform_balance_baseline_at', date('Y-m-d H:i:s'));
        ActivityLog::record('wallet', 'wallet.platform_balance_reset', [
            'user_id' => $adminId, 'meta' => ['baseline' => $raw],
        ]);
        return $this->getPlatformBalanceSummary();
    }

    /** الرجوع لعرض الإجمالي الحقيقي (إلغاء التصفير) */
    public function restorePlatformBalance(int $adminId): array {
        $this->updatePaymentSetting('platform_balance_baseline', '0');
        $this->updatePaymentSetting('platform_balance_baseline_at', '');
        ActivityLog::record('wallet', 'wallet.platform_balance_restored', ['user_id' => $adminId]);
        return $this->getPlatformBalanceSummary();
    }

    /**
     * ⚠️ مدمّر: تصفير رصيد كل عميل فعليًا بحركة مقابلة (admin_adjustment)
     * لكل عميل رصيده مش صفر. الحركات القديمة بتفضل في السجل للتدقيق.
     */
    public function zeroAllCustomerBalances(int $adminId, string $note = ''): array {
        $note = trim(mb_substr($note, 0, 200)) ?: 'تصفير جماعي لأرصدة المنصة بواسطة الأدمن';

        $result = $this->db->transaction(function () use ($adminId, $note) {
            $rows = $this->db->query(
                "SELECT user_id, ROUND(SUM(amount), 2) AS bal FROM wallet_transactions
                 WHERE status = 'completed' GROUP BY user_id HAVING ABS(SUM(amount)) >= 0.01"
            );
            $affected = 0;
            $net = 0.0;
            foreach ($rows as $row) {
                $bal = (float) $row['bal'];
                $tx = new WalletTransaction();
                $tx->fill([
                    'user_id' => (int) $row['user_id'],
                    'type' => 'admin_adjustment',
                    'amount' => -$bal,
                    'currency' => 'USD',
                    'status' => 'completed',
                    'payment_method' => 'admin',
                    'reference_note' => $note,
                    'admin_note' => 'تصفير جماعي (أدمن #' . $adminId . ')',
                    'approved_by' => $adminId,
                    'approved_at' => date('Y-m-d H:i:s'),
                ]);
                $tx->save();
                $affected++;
                $net += $bal;
            }
            return ['users_affected' => $affected, 'total_removed' => round($net, 2)];
        });

        $this->updatePaymentSetting('platform_balance_baseline', '0');
        $this->updatePaymentSetting('platform_balance_baseline_at', '');
        ActivityLog::record('wallet', 'wallet.all_balances_zeroed', ['user_id' => $adminId, 'meta' => $result]);

        return $result;
    }

    /**
     * توليد دفعة بطاقات شحن جديدة - كل بطاقة بكود فريد بصيغة سهلة
     * القراءة (زي أمازون بالظبط: TRFC-XXXX-XXXX-XXXX).
     */
    public function generateRechargeCards(int $count, float $value, int $adminId, string $batchLabel = ''): array {
        if ($count <= 0 || $count > 500) {
            throw new Exception('عدد البطاقات لازم يكون بين 1 و 500');
        }
        if ($value <= 0) {
            throw new Exception('قيمة البطاقة لازم تكون أكبر من صفر');
        }

        $cards = [];
        for ($i = 0; $i < $count; $i++) {
            $code = $this->generateUniqueCardCode();
            $card = new WalletRechargeCard();
            $card->fill([
                'code' => $code,
                'value' => $value,
                'status' => 'unused',
                'batch_label' => $batchLabel ?: date('Y-m-d H:i'),
                'created_by_admin_id' => $adminId,
            ]);
            $card->save();
            $cards[] = $card->toArray();
        }

        return $cards;
    }

    private function generateUniqueCardCode(): string {
        do {
            $code = 'TRFC-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));
            $exists = !empty((new WalletRechargeCard())->where(['code' => $code], [], 1));
        } while ($exists);
        return $code;
    }

    /**
     * العميل يشحن بطاقة - بيتأكد إن الكود صحيح ومستخدمش قبل كده،
     * ويضيف قيمتها لرصيد محفظته فورًا.
     */
    public function redeemCard(int $userId, string $code): array {
        $code = strtoupper(trim($code));
        $rows = (new WalletRechargeCard())->where(['code' => $code], [], 1);

        if (empty($rows)) {
            throw new Exception('كود البطاقة غير صحيح');
        }

        $card = $rows[0];
        if ($card->getAttribute('status') === 'used') {
            throw new Exception('البطاقة دي اتشحنت قبل كده');
        }

        $value = (float) $card->getAttribute('value');

        $tx = new WalletTransaction();
        $tx->fill([
            'user_id' => $userId,
            'type' => 'card_redemption',
            'amount' => $value,
            'currency' => 'USD',
            'status' => 'completed',
            'payment_method' => 'recharge_card',
            'reference_note' => 'شحن بطاقة: ' . $code,
        ]);
        $tx->save();

        $card->setAttribute('status', 'used');
        $card->setAttribute('used_by_user_id', $userId);
        $card->setAttribute('used_at', date('Y-m-d H:i:s'));
        $card->save();

        return ['value' => $value, 'new_balance' => $this->getBalance($userId)];
    }

    /** كل طلبات الإيداع المعلّقة (لعرضها في لوحة الأدمن) */
    public function getPendingDeposits(): array {
        return (new WalletTransaction())->where(['type' => 'deposit', 'status' => 'pending'], ['created_at' => 'ASC']);
    }

    /**
     * إحصائيات مجمّعة للمحفظة (لوحة الأدمن): إجمالي الإيداعات المعتمدة
     * هذا الشهر، عدد الطلبات المعلّقة، إجمالي أرصدة كل العملاء، وإجمالي
     * خصومات "ادفع حسب الاستخدام" هذا الشهر.
     */
    public function getAdminStats(): array {
        try {
            $depositsThisMonth = $this->db->query(
                "SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS count FROM wallet_transactions
                 WHERE type = 'deposit' AND status = 'completed' AND MONTH(approved_at) = MONTH(NOW()) AND YEAR(approved_at) = YEAR(NOW())"
            );
            $pendingCount = $this->db->query(
                "SELECT COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total FROM wallet_transactions WHERE type = 'deposit' AND status = 'pending'"
            );
            $platformBalance = $this->getPlatformBalanceSummary();
            $usageChargesThisMonth = $this->db->query(
                "SELECT COALESCE(SUM(ABS(amount)), 0) AS total, COUNT(*) AS count FROM wallet_transactions
                 WHERE type = 'subscription_charge' AND status = 'completed' AND MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())"
            );

            return array_merge([
                'deposits_this_month' => (float) ($depositsThisMonth[0]['total'] ?? 0),
                'deposits_this_month_count' => (int) ($depositsThisMonth[0]['count'] ?? 0),
                'pending_count' => (int) ($pendingCount[0]['count'] ?? 0),
                'pending_total' => (float) ($pendingCount[0]['total'] ?? 0),
                'total_customer_balances' => (float) $platformBalance['displayed'],
                'total_customer_balances_raw' => (float) $platformBalance['raw'],
                'platform_balance_baseline' => (float) $platformBalance['baseline'],
                'platform_balance_baseline_at' => $platformBalance['baseline_set_at'] ?: null,
                'usage_charges_this_month' => (float) ($usageChargesThisMonth[0]['total'] ?? 0),
                'usage_charges_this_month_count' => (int) ($usageChargesThisMonth[0]['count'] ?? 0),
            ], $this->getBillingAnalytics());
        } catch (Exception $e) {
            Logger::error('WalletService getAdminStats Error', ['message' => $e->getMessage()]);
            return array_merge([
                'deposits_this_month' => 0, 'deposits_this_month_count' => 0, 'pending_count' => 0,
                'pending_total' => 0, 'total_customer_balances' => 0, 'usage_charges_this_month' => 0,
                'usage_charges_this_month_count' => 0,
            ], $this->getBillingAnalytics());
        }
    }

    /**
     * Phase 8: MRR/ARR وباقي مقاييس الاشتراكات - محسوبة من الجداول
     * الحقيقية (subscriptions + subscription_plans، محرك الفوترة الفعلي
     * اللي اكتُشف في Phase 1)، مش من أي جدول إحصائيات وهمي. كل استعلام
     * معزول بـ try/catch مستقل (زي نمط getPlatformOverview() الموجود في
     * AdminController) عشان لو جدول واحد فيه مشكلة، باقي الأرقام تفضل
     * شغالة.
     *
     * ملحوظة منهجية (مهم): churn_rate_this_month تقريبي - بيستخدم
     * updated_at كبديل لعمود "تاريخ الإلغاء" غير الموجود فعليًا في
     * الجدول الحقيقي. لو فيه تعديلات تانية على نفس الصف (مش إلغاء) في
     * نفس الشهر، الرقم ممكن يبقى أعلى شوية من الحقيقي - محدود التأثير
     * لأن الصف بيتحدّث غالبًا وقت الإلغاء بس فعليًا حسب الكود الحالي.
     */
    private function getBillingAnalytics(): array {
        $safe = function (string $sql) {
            try {
                $rows = $this->db->query($sql);
                return $rows[0] ?? [];
            } catch (Exception $e) {
                Logger::error('getBillingAnalytics query failed', ['message' => $e->getMessage()]);
                return null;
            }
        };

        $mrrRow = $safe(
            "SELECT COALESCE(SUM(CASE WHEN sp.billing_cycle = 'yearly' THEN sp.price / 12 ELSE sp.price END), 0) AS mrr,
                    COUNT(*) AS active_count
             FROM subscriptions s
             JOIN subscription_plans sp ON sp.id = s.plan_id
             WHERE s.status = 'active' AND s.current_period_end > NOW()"
        );
        $newThisMonth = $safe(
            "SELECT COUNT(*) AS count FROM subscriptions
             WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())"
        );
        $cancelledThisMonth = $safe(
            "SELECT COUNT(*) AS count FROM subscriptions
             WHERE status = 'cancelled' AND MONTH(updated_at) = MONTH(NOW()) AND YEAR(updated_at) = YEAR(NOW())"
        );

        if ($mrrRow === null || $newThisMonth === null || $cancelledThisMonth === null) {
            // فشل استعلام أساسي - أفضل نرجع "مفيش بيانات كافية" بدل ما
            // نعرض رقم جزئي ممكن يكون مضلّل.
            return [
                'mrr' => null, 'arr' => null, 'active_subscriptions' => null,
                'new_subscriptions_this_month' => null, 'cancelled_this_month' => null,
                'churn_rate_this_month' => null, 'average_revenue_per_subscription' => null,
            ];
        }

        $mrr = (float) ($mrrRow['mrr'] ?? 0);
        $activeCount = (int) ($mrrRow['active_count'] ?? 0);
        $cancelled = (int) ($cancelledThisMonth['count'] ?? 0);
        $churnDenominator = $activeCount + $cancelled;

        return [
            'mrr' => round($mrr, 2),
            'arr' => round($mrr * 12, 2),
            'active_subscriptions' => $activeCount,
            'new_subscriptions_this_month' => (int) ($newThisMonth['count'] ?? 0),
            'cancelled_this_month' => $cancelled,
            // null صراحةً لو مفيش قاعدة مقارنة (Not enough data) بدل ما نخترع 0%.
            'churn_rate_this_month' => $churnDenominator > 0 ? round(($cancelled / $churnDenominator) * 100, 1) : null,
            'average_revenue_per_subscription' => $activeCount > 0 ? round($mrr / $activeCount, 2) : null,
        ];
    }

    /** بيانات الدفع (IBAN/PayPal) القابلة للتعديل من لوحة الأدمن */
    public function getPaymentSettings(): array {
        try {
            $rows = $this->db->query("SELECT setting_key, setting_value FROM wallet_payment_settings");
        } catch (Exception $e) {
            return [];
        }
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }

    /** تحديث إعداد دفع واحد من لوحة الأدمن */
    public function updatePaymentSetting(string $key, string $value): void {
        $this->db->exec(
            "INSERT INTO wallet_payment_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
            [$key, $value]
        );
    }

    // ============================================
    // ادفع حسب الاستخدام (Pay-As-You-Go)
    // العميل من غير اشتراك (أو اللي خلّص حد باقته) يقدر يستخدم أي ميزة
    // وتتخصم تلقائيًا من رصيد محفظته بدل ما يتمنع خالص.
    // ============================================

    /** كل أسعار الاستخدام الفردي النشطة، مفهرسة بمفتاح الميزة */
    public function getUsagePricing(): array {
        try {
            $rows = $this->db->query("SELECT * FROM pay_per_use_pricing WHERE is_active = 1");
        } catch (Exception $e) {
            return [];
        }
        $pricing = [];
        foreach ($rows as $row) {
            $pricing[$row['feature_key']] = $row;
        }
        return $pricing;
    }

    /** فحص: هل رصيد العميل كافي لاستخدام ميزة معيّنة مرة واحدة؟ */
    public function canAffordUsage(int $userId, string $featureKey): array {
        $pricing = $this->getUsagePricing();
        $price = $pricing[$featureKey] ?? null;

        if (!$price) {
            return ['can_afford' => false, 'reason' => 'الميزة دي مش متاحة كـ"ادفع حسب الاستخدام" حاليًا'];
        }

        $balance = $this->getBalance($userId);
        $unitPrice = (float) $price['price'];

        if ($balance < $unitPrice) {
            return [
                'can_afford' => false, 'reason' => 'رصيدك غير كافي', 'balance' => $balance,
                'required' => $unitPrice, 'shortfall' => round($unitPrice - $balance, 2),
            ];
        }

        return ['can_afford' => true, 'balance' => $balance, 'price' => $unitPrice, 'currency_symbol' => $price['currency_symbol']];
    }

    /**
     * خصم فعلي لثمن استخدام ميزة واحدة من المحفظة. بترجع false لو الرصيد
     * مش كافي (بدل ما تخصم قيمة سالبة/تدّي رصيد بالسالب بالغلط).
     */
    public function chargeForUsage(int $userId, string $featureKey, string $note = ''): bool {
        $check = $this->canAffordUsage($userId, $featureKey);
        if (!$check['can_afford']) {
            return false;
        }

        $tx = new WalletTransaction();
        $tx->fill([
            'user_id' => $userId,
            'type' => 'subscription_charge',
            'amount' => -$check['price'],
            'currency' => 'USD',
            'status' => 'completed',
            'reference_note' => $note ?: $featureKey,
            'approved_at' => date('Y-m-d H:i:s'),
        ]);
        $tx->save();

        return true;
    }

    /** كل أسعار الاستخدام (نشطة وغير نشطة) - لعرضها وتعديلها في لوحة الأدمن */
    public function getAllUsagePricingForAdmin(): array {
        try {
            return $this->db->query("SELECT * FROM pay_per_use_pricing ORDER BY id ASC");
        } catch (Exception $e) {
            return [];
        }
    }

    /** تحديث سعر استخدام ميزة واحدة من لوحة الأدمن */
    public function updateUsagePricing(int $id, float $price, bool $isActive): void {
        $this->db->exec(
            "UPDATE pay_per_use_pricing SET price = ?, is_active = ? WHERE id = ?",
            [$price, $isActive ? 1 : 0, $id]
        );
    }
}
