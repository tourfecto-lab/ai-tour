<?php
/**
 * Tourfecto - Subscription Validator
 * مدقق الاشتراكات والصلاحيات مع نظام التحقق المتقدم
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class SubscriptionValidator {
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;
    
    /**
     * @var array $subscription - بيانات الاشتراك الحالية
     */
    private $subscription = null;
    
    /**
     * @var array $planFeatures - ميزات الباقات
     */
    private $planFeatures = [];
    
    /**
     * @var int $cacheDuration - مدة تخزين الكاش (بالثواني)
     */
    private $cacheDuration = 300; // 5 دقائق
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->db = Database::getInstance();
        $this->loadPlanFeatures();
    }
    
    /**
     * التحقق من صلاحية الاشتراك لمستخدم معين
     * @param int $userId - معرف المستخدم
     * @param bool $useCache - استخدام الكاش
     * @return array - حالة الاشتراك مع التفاصيل
     */
    public function validateSubscription(int $userId, bool $useCache = true): array {
        try {
            // محاولة جلب من الكاش
            if ($useCache) {
                $cached = $this->getCachedSubscription($userId);
                if ($cached !== null) {
                    return $cached;
                }
            }
            
            // جلب بيانات الاشتراك النشط
            $sql = "SELECT * FROM subscriptions 
                    WHERE user_id = :user_id 
                    AND status = 'active' 
                    AND (expiry_date IS NULL OR expiry_date > NOW())
                    ORDER BY id DESC LIMIT 1";
            
            $subscription = $this->db->query($sql, [':user_id' => $userId]);
            
            if (empty($subscription)) {
                $result = [
                    'valid' => false,
                    'status' => 'inactive',
                    'message' => 'No active subscription found.',
                    'plan' => null,
                    'code' => 403
                ];
                
                $this->cacheSubscription($userId, $result);
                return $result;
            }
            
            $sub = $subscription[0];
            $this->subscription = $sub;
            
            // التحقق من الـ Credits المتبقية
            $result = [
                'valid' => true,
                'status' => 'active',
                'message' => 'Subscription is active.',
                'plan' => $sub['plan_name'],
                'plan_type' => $sub['plan_type'],
                'plan_label' => $this->getPlanLabel($sub['plan_name']),
                'ai_credits' => (int) $sub['ai_credits'],
                'ai_credits_used' => (int) $sub['ai_credits_used'],
                'ai_credits_remaining' => (int) $sub['ai_credits'] - (int) $sub['ai_credits_used'],
                'chat_credits' => (int) $sub['chat_credits'],
                'chat_credits_used' => (int) $sub['chat_credits_used'],
                'chat_credits_remaining' => (int) $sub['chat_credits'] - (int) $sub['chat_credits_used'],
                'review_credits' => (int) $sub['review_credits'],
                'review_credits_used' => (int) $sub['review_credits_used'],
                'review_credits_remaining' => (int) $sub['review_credits'] - (int) $sub['review_credits_used'],
                'competitor_analysis_limit' => (int) $sub['competitor_analysis_limit'],
                'competitor_analysis_used' => (int) $sub['competitor_analysis_used'],
                'competitor_analysis_remaining' => (int) $sub['competitor_analysis_limit'] - (int) $sub['competitor_analysis_used'],
                'auto_pilot' => (bool) $sub['auto_pilot'],
                'start_date' => $sub['start_date'],
                'expiry_date' => $sub['expiry_date'],
                'days_remaining' => $this->calculateDaysRemaining($sub['expiry_date']),
                'features' => $this->getPlanFeatures($sub['plan_name']),
                'code' => 200
            ];
            
            // تخزين في الكاش
            $this->cacheSubscription($userId, $result);
            
            return $result;
            
        } catch (Exception $e) {
            Logger::error('Subscription Validation Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'valid' => false,
                'status' => 'error',
                'message' => 'Error validating subscription.',
                'plan' => null,
                'code' => 500
            ];
        }
    }
    
    /**
     * التحقق من توفر رصيد الذكاء الاصطناعي
     * @param int $userId
     * @param int $requiredCredits
     * @return array
     */
    public function checkAICredits(int $userId, int $requiredCredits = 1): array {
        $subscription = $this->validateSubscription($userId);
        
        if (!$subscription['valid']) {
            return [
                'available' => false,
                'message' => 'No active subscription found.',
                'remaining' => 0,
                'code' => 403
            ];
        }
        
        $remaining = $subscription['ai_credits_remaining'];
        
        if ($remaining < $requiredCredits) {
            return [
                'available' => false,
                'message' => "Insufficient AI credits. Required: {$requiredCredits}, Remaining: {$remaining}",
                'remaining' => $remaining,
                'code' => 429
            ];
        }
        
        return [
            'available' => true,
            'message' => 'Sufficient AI credits available.',
            'remaining' => $remaining,
            'code' => 200
        ];
    }
    
    /**
     * التحقق من توفر رصيد تحليل المنافسين
     * @param int $userId
     * @return array
     */
    public function checkCompetitorAnalysisCredits(int $userId): array {
        $subscription = $this->validateSubscription($userId);
        
        if (!$subscription['valid']) {
            return [
                'available' => false,
                'message' => 'No active subscription found.',
                'remaining' => 0,
                'code' => 403
            ];
        }
        
        $remaining = $subscription['competitor_analysis_remaining'];
        
        if ($remaining <= 0) {
            return [
                'available' => false,
                'message' => 'Competitor analysis limit reached for this month.',
                'remaining' => 0,
                'code' => 429
            ];
        }
        
        return [
            'available' => true,
            'message' => 'Competitor analysis credits available.',
            'remaining' => $remaining,
            'code' => 200
        ];
    }
    
    /**
     * التحقق من توفر رصيد الشات
     * @param int $userId
     * @param int $requiredCredits
     * @return array
     */
    public function checkChatCredits(int $userId, int $requiredCredits = 1): array {
        $subscription = $this->validateSubscription($userId);
        
        if (!$subscription['valid']) {
            return [
                'available' => false,
                'message' => 'No active subscription found.',
                'remaining' => 0,
                'code' => 403
            ];
        }
        
        $remaining = $subscription['chat_credits_remaining'];
        
        if ($remaining < $requiredCredits) {
            return [
                'available' => false,
                'message' => "Insufficient chat credits. Required: {$requiredCredits}, Remaining: {$remaining}",
                'remaining' => $remaining,
                'code' => 429
            ];
        }
        
        return [
            'available' => true,
            'message' => 'Sufficient chat credits available.',
            'remaining' => $remaining,
            'code' => 200
        ];
    }
    
    /**
     * التحقق من توفر رصيد المراجعات
     * @param int $userId
     * @param int $requiredCredits
     * @return array
     */
    public function checkReviewCredits(int $userId, int $requiredCredits = 1): array {
        $subscription = $this->validateSubscription($userId);
        
        if (!$subscription['valid']) {
            return [
                'available' => false,
                'message' => 'No active subscription found.',
                'remaining' => 0,
                'code' => 403
            ];
        }
        
        $remaining = $subscription['review_credits_remaining'];
        
        if ($remaining < $requiredCredits) {
            return [
                'available' => false,
                'message' => "Insufficient review credits. Required: {$requiredCredits}, Remaining: {$remaining}",
                'remaining' => $remaining,
                'code' => 429
            ];
        }
        
        return [
            'available' => true,
            'message' => 'Sufficient review credits available.',
            'remaining' => $remaining,
            'code' => 200
        ];
    }
    
    /**
     * استهلاك رصيد AI
     * @param int $userId
     * @param int $creditsUsed
     * @return bool
     */
    public function consumeAICredits(int $userId, int $creditsUsed = 1): bool {
        try {
            $sql = "UPDATE subscriptions 
                    SET ai_credits_used = ai_credits_used + :credits_used,
                        updated_at = NOW()
                    WHERE user_id = :user_id 
                    AND status = 'active' 
                    AND (expiry_date IS NULL OR expiry_date > NOW())
                    AND (ai_credits - ai_credits_used) >= :credits_used
                    ORDER BY id DESC LIMIT 1";
            
            $result = $this->db->query($sql, [
                ':credits_used' => $creditsUsed,
                ':user_id' => $userId
            ]);
            
            if ($result > 0) {
                // مسح الكاش
                $this->clearCache($userId);
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            Logger::error('Consume AI Credits Error', [
                'user_id' => $userId,
                'credits' => $creditsUsed,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * استهلاك رصيد تحليل المنافسين
     * @param int $userId
     * @return bool
     */
    public function consumeCompetitorAnalysisCredit(int $userId): bool {
        try {
            $sql = "UPDATE subscriptions 
                    SET competitor_analysis_used = competitor_analysis_used + 1,
                        updated_at = NOW()
                    WHERE user_id = :user_id 
                    AND status = 'active' 
                    AND (expiry_date IS NULL OR expiry_date > NOW())
                    AND competitor_analysis_used < competitor_analysis_limit
                    ORDER BY id DESC LIMIT 1";
            
            $result = $this->db->query($sql, [':user_id' => $userId]);
            
            if ($result > 0) {
                $this->clearCache($userId);
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            Logger::error('Consume Competitor Analysis Credit Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * استهلاك رصيد الشات
     * @param int $userId
     * @param int $creditsUsed
     * @return bool
     */
    public function consumeChatCredits(int $userId, int $creditsUsed = 1): bool {
        try {
            $sql = "UPDATE subscriptions 
                    SET chat_credits_used = chat_credits_used + :credits_used,
                        updated_at = NOW()
                    WHERE user_id = :user_id 
                    AND status = 'active' 
                    AND (expiry_date IS NULL OR expiry_date > NOW())
                    AND (chat_credits - chat_credits_used) >= :credits_used
                    ORDER BY id DESC LIMIT 1";
            
            $result = $this->db->query($sql, [
                ':credits_used' => $creditsUsed,
                ':user_id' => $userId
            ]);
            
            if ($result > 0) {
                $this->clearCache($userId);
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            Logger::error('Consume Chat Credits Error', [
                'user_id' => $userId,
                'credits' => $creditsUsed,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * استهلاك رصيد المراجعات
     * @param int $userId
     * @param int $creditsUsed
     * @return bool
     */
    public function consumeReviewCredits(int $userId, int $creditsUsed = 1): bool {
        try {
            $sql = "UPDATE subscriptions 
                    SET review_credits_used = review_credits_used + :credits_used,
                        updated_at = NOW()
                    WHERE user_id = :user_id 
                    AND status = 'active' 
                    AND (expiry_date IS NULL OR expiry_date > NOW())
                    AND (review_credits - review_credits_used) >= :credits_used
                    ORDER BY id DESC LIMIT 1";
            
            $result = $this->db->query($sql, [
                ':credits_used' => $creditsUsed,
                ':user_id' => $userId
            ]);
            
            if ($result > 0) {
                $this->clearCache($userId);
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            Logger::error('Consume Review Credits Error', [
                'user_id' => $userId,
                'credits' => $creditsUsed,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * التحقق من صلاحية المستخدم لاستخدام خاصية معينة
     * @param int $userId
     * @param string $feature
     * @return bool
     */
    public function canUseFeature(int $userId, string $feature): bool {
        $subscription = $this->validateSubscription($userId);
        
        if (!$subscription['valid']) {
            return false;
        }
        
        $plan = $subscription['plan'];
        $features = $this->planFeatures[$plan] ?? [];
        
        return isset($features[$feature]) && $features[$feature];
    }
    
    /**
     * الحصول على إعدادات البوت للمستخدم
     * @param int $userId
     * @param int $websiteId
     * @return array
     */
    public function getBotSettings(int $userId, int $websiteId): array {
        try {
            $sql = "SELECT * FROM bot_settings 
                    WHERE user_id = :user_id 
                    AND website_id = :website_id 
                    AND is_enabled = 1
                    ORDER BY id DESC LIMIT 1";
            
            $settings = $this->db->query($sql, [
                ':user_id' => $userId,
                ':website_id' => $websiteId
            ]);
            
            if (empty($settings)) {
                // إعدادات افتراضية
                return [
                    'is_enabled' => true,
                    'auto_pilot' => false,
                    'requires_approval' => true,
                    'ai_model' => 'gemini-1.5-flash',
                    'ai_temperature' => 0.70,
                    'ai_max_tokens' => 2000,
                    'ai_language' => 'auto',
                    'greeting_message' => 'مرحباً بك! كيف يمكننا مساعدتك اليوم؟',
                    'fallback_message' => 'شكراً لتواصلك معنا. أحد ممثلي خدمة العملاء سيتواصل معك قريباً.',
                    'blocked_keywords' => []
                ];
            }
            
            return $settings[0];
            
        } catch (Exception $e) {
            Logger::error('Get Bot Settings Error', [
                'user_id' => $userId,
                'website_id' => $websiteId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'is_enabled' => false,
                'auto_pilot' => false,
                'requires_approval' => true,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * الحصول على اشتراكات منتهية الصلاحية
     * @param int $days
     * @return array
     */
    public function getExpiringSubscriptions(int $days = 7): array {
        try {
            $sql = "SELECT s.*, u.email, u.company_name 
                    FROM subscriptions s
                    JOIN users u ON s.user_id = u.id
                    WHERE s.status = 'active' 
                    AND s.expiry_date IS NOT NULL
                    AND s.expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL :days DAY)
                    ORDER BY s.expiry_date ASC";
            
            return $this->db->query($sql, [':days' => $days]);
            
        } catch (Exception $e) {
            Logger::error('Get Expiring Subscriptions Error', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * الحصول على اشتراكات منتهية
     * @return array
     */
    public function getExpiredSubscriptions(): array {
        try {
            $sql = "SELECT s.*, u.email, u.company_name 
                    FROM subscriptions s
                    JOIN users u ON s.user_id = u.id
                    WHERE s.status = 'active' 
                    AND s.expiry_date IS NOT NULL
                    AND s.expiry_date < NOW()
                    ORDER BY s.expiry_date ASC";
            
            return $this->db->query($sql);
            
        } catch (Exception $e) {
            Logger::error('Get Expired Subscriptions Error', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * تجديد الاشتراكات التلقائية
     * @return int
     */
    public function autoRenewSubscriptions(): int {
        $renewed = 0;
        
        try {
            $expiring = $this->getExpiringSubscriptions(1);
            
            foreach ($expiring as $sub) {
                // التحقق من وجود طريقة دفع
                if ($this->hasPaymentMethod($sub['user_id'])) {
                    // محاولة التجديد
                    $subscription = new Subscription($sub);
                    if ($subscription->renew()) {
                        $renewed++;
                        Logger::info('Subscription auto-renewed', [
                            'user_id' => $sub['user_id'],
                            'subscription_id' => $sub['id']
                        ]);
                    }
                }
            }
            
            return $renewed;
            
        } catch (Exception $e) {
            Logger::error('Auto Renew Subscriptions Error', [
                'error' => $e->getMessage()
            ]);
            return $renewed;
        }
    }
    
    /**
     * تحميل ميزات الباقات
     */
    private function loadPlanFeatures(): void {
        $this->planFeatures = SUBSCRIPTION_PLANS;
    }
    
    /**
     * الحصول على ميزات باقة معينة
     * @param string $planName
     * @return array
     */
    private function getPlanFeatures(string $planName): array {
        return $this->planFeatures[$planName]['features'] ?? [];
    }
    
    /**
     * الحصول على اسم الباقة المعروض
     * @param string $planName
     * @return string
     */
    private function getPlanLabel(string $planName): string {
        $labels = [
            'starter' => 'الباقة الأساسية',
            'professional' => 'الباقة الاحترافية',
            'enterprise' => 'الباقة المؤسسية'
        ];
        
        return $labels[$planName] ?? $planName;
    }
    
    /**
     * حساب عدد الأيام المتبقية
     * @param string|null $expiryDate
     * @return int
     */
    private function calculateDaysRemaining(?string $expiryDate): int {
        if (!$expiryDate) {
            return 999;
        }
        
        $expiry = strtotime($expiryDate);
        $now = time();
        $diff = $expiry - $now;
        
        return max(0, (int) ceil($diff / (60 * 60 * 24)));
    }
    
    /**
     * التحقق من وجود طريقة دفع
     * @param int $userId
     * @return bool
     */
    private function hasPaymentMethod(int $userId): bool {
        // تنفيذ التحقق من طريقة الدفع
        return true;
    }
    
    /**
     * الحصول على الكاش المخزن
     * @param int $userId
     * @return array|null
     */
    private function getCachedSubscription(int $userId): ?array {
        $cacheKey = "subscription_{$userId}";
        $cache = new Cache();
        return $cache->get($cacheKey);
    }
    
    /**
     * تخزين الاشتراك في الكاش
     * @param int $userId
     * @param array $data
     */
    private function cacheSubscription(int $userId, array $data): void {
        $cacheKey = "subscription_{$userId}";
        $cache = new Cache();
        $cache->set($cacheKey, $data, $this->cacheDuration);
    }
    
    /**
     * مسح الكاش
     * @param int $userId
     */
    private function clearCache(int $userId): void {
        $cacheKey = "subscription_{$userId}";
        $cache = new Cache();
        $cache->delete($cacheKey);
    }
}