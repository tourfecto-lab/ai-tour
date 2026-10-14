<?php
/**
 * Tourfecto - Usage Tracker
 * تتبع استخدام الميزات والحدود الشهرية
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class UsageTracker {
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;
    
    /**
     * @var array $usageData - بيانات الاستخدام
     */
    private $usageData = [];
    
    /**
     * @var int $userId - معرف المستخدم الحالي
     */
    private $userId;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * تتبع استخدام الميزة
     * @param int $userId
     * @param string $feature
     * @param int $amount
     * @param array $metadata
     * @return bool
     */
    public function track(int $userId, string $feature, int $amount = 1, array $metadata = []): bool {
        try {
            $this->userId = $userId;
            
            // تسجيل الاستخدام
            $sql = "INSERT INTO usage_tracking (
                        user_id, feature, amount, metadata, created_at
                    ) VALUES (
                        :user_id, :feature, :amount, :metadata, NOW()
                    )";
            
            $this->db->query($sql, [
                ':user_id' => $userId,
                ':feature' => $feature,
                ':amount' => $amount,
                ':metadata' => json_encode($metadata)
            ]);
            
            // تحديث إحصائيات الاستخدام الشهري
            $this->updateMonthlyUsage($userId, $feature, $amount);
            
            return true;
            
        } catch (Exception $e) {
            Logger::error('Track Usage Error', [
                'user_id' => $userId,
                'feature' => $feature,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * الحصول على إحصائيات الاستخدام
     * @param int $userId
     * @param string $period - daily, weekly, monthly
     * @return array
     */
    public function getStats(int $userId, string $period = 'monthly'): array {
        try {
            $this->userId = $userId;
            
            $interval = $this->getPeriodInterval($period);
            
            $sql = "SELECT 
                        feature,
                        SUM(amount) as total_usage,
                        COUNT(*) as total_events,
                        MAX(created_at) as last_used
                    FROM usage_tracking 
                    WHERE user_id = :user_id 
                    AND created_at > DATE_SUB(NOW(), INTERVAL :interval)
                    GROUP BY feature
                    ORDER BY total_usage DESC";
            
            $usage = $this->db->query($sql, [
                ':user_id' => $userId,
                ':interval' => $interval
            ]);
            
            // الحصول على الحدود الشهرية
            $limits = $this->getFeatureLimits($userId);
            
            $stats = [];
            foreach ($usage as $row) {
                $feature = $row['feature'];
                $stats[$feature] = [
                    'used' => (int) $row['total_usage'],
                    'limit' => $limits[$feature] ?? null,
                    'remaining' => isset($limits[$feature]) 
                        ? max(0, $limits[$feature] - (int) $row['total_usage'])
                        : null,
                    'percentage' => isset($limits[$feature]) && $limits[$feature] > 0
                        ? round(((int) $row['total_usage'] / $limits[$feature]) * 100, 2)
                        : 0,
                    'events' => (int) $row['total_events'],
                    'last_used' => $row['last_used']
                ];
            }
            
            return $stats;
            
        } catch (Exception $e) {
            Logger::error('Get Usage Stats Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * الحصول على حدود الميزات
     * @param int $userId
     * @return array
     */
    public function getFeatureLimits(int $userId): array {
        try {
            $sql = "SELECT 
                        plan_name,
                        ai_credits,
                        chat_credits,
                        review_credits,
                        competitor_analysis_limit
                    FROM subscriptions 
                    WHERE user_id = :user_id 
                    AND status = 'active' 
                    ORDER BY id DESC LIMIT 1";
            
            $result = $this->db->query($sql, [':user_id' => $userId]);
            
            if (empty($result)) {
                return [];
            }
            
            $sub = $result[0];
            
            return [
                'ai_analysis' => (int) $sub['ai_credits'],
                'chat_messages' => (int) $sub['chat_credits'],
                'review_processing' => (int) $sub['review_credits'],
                'competitor_analysis' => (int) $sub['competitor_analysis_limit']
            ];
            
        } catch (Exception $e) {
            Logger::error('Get Feature Limits Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * التحقق من تجاوز الحد
     * @param int $userId
     * @param string $feature
     * @param int $amount
     * @return bool
     */
    public function isLimitExceeded(int $userId, string $feature, int $amount = 1): bool {
        try {
            $limits = $this->getFeatureLimits($userId);
            
            if (!isset($limits[$feature])) {
                return false;
            }
            
            // الحصول على الاستخدام الحالي
            $sql = "SELECT SUM(amount) as total 
                    FROM usage_tracking 
                    WHERE user_id = :user_id 
                    AND feature = :feature 
                    AND created_at > DATE_SUB(NOW(), INTERVAL 1 MONTH)";
            
            $result = $this->db->query($sql, [
                ':user_id' => $userId,
                ':feature' => $feature
            ]);
            
            $currentUsage = (int) ($result[0]['total'] ?? 0);
            
            return ($currentUsage + $amount) > $limits[$feature];
            
        } catch (Exception $e) {
            Logger::error('Check Limit Error', [
                'user_id' => $userId,
                'feature' => $feature,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * تحديث الاستخدام الشهري
     * @param int $userId
     * @param string $feature
     * @param int $amount
     */
    private function updateMonthlyUsage(int $userId, string $feature, int $amount): void {
        try {
            $month = date('Y-m');
            
            $sql = "INSERT INTO monthly_usage (
                        user_id, month, feature, total_usage, updated_at
                    ) VALUES (
                        :user_id, :month, :feature, :amount, NOW()
                    ) ON DUPLICATE KEY UPDATE
                        total_usage = total_usage + :amount,
                        updated_at = NOW()";
            
            $this->db->query($sql, [
                ':user_id' => $userId,
                ':month' => $month,
                ':feature' => $feature,
                ':amount' => $amount
            ]);
            
        } catch (Exception $e) {
            // تجاهل الخطأ
        }
    }
    
    /**
     * الحصول على فترة التقرير
     * @param string $period
     * @return string
     */
    private function getPeriodInterval(string $period): string {
        switch ($period) {
            case 'daily':
                return '1 DAY';
            case 'weekly':
                return '7 DAY';
            case 'monthly':
            default:
                return '1 MONTH';
        }
    }
    
    /**
     * الحصول على سجل استخدام مفصل
     * @param int $userId
     * @param string $feature
     * @param int $limit
     * @return array
     */
    public function getDetailedLog(int $userId, string $feature, int $limit = 100): array {
        try {
            $sql = "SELECT 
                        id, feature, amount, metadata, created_at
                    FROM usage_tracking 
                    WHERE user_id = :user_id 
                    AND feature = :feature
                    ORDER BY created_at DESC 
                    LIMIT :limit";
            
            $logs = $this->db->query($sql, [
                ':user_id' => $userId,
                ':feature' => $feature,
                ':limit' => $limit
            ]);
            
            // فك تشفير الميتاداتا
            foreach ($logs as &$log) {
                $log['metadata'] = json_decode($log['metadata'], true);
            }
            
            return $logs;
            
        } catch (Exception $e) {
            Logger::error('Get Detailed Log Error', [
                'user_id' => $userId,
                'feature' => $feature,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * الحصول على إحصائيات الاستخدام حسب اليوم
     * @param int $userId
     * @param int $days
     * @return array
     */
    public function getDailyStats(int $userId, int $days = 30): array {
        try {
            $sql = "SELECT 
                        DATE(created_at) as date,
                        feature,
                        SUM(amount) as total
                    FROM usage_tracking 
                    WHERE user_id = :user_id 
                    AND created_at > DATE_SUB(NOW(), INTERVAL :days DAY)
                    GROUP BY DATE(created_at), feature
                    ORDER BY date ASC";
            
            $results = $this->db->query($sql, [
                ':user_id' => $userId,
                ':days' => $days
            ]);
            
            $stats = [];
            foreach ($results as $row) {
                $date = $row['date'];
                $feature = $row['feature'];
                
                if (!isset($stats[$date])) {
                    $stats[$date] = [];
                }
                
                $stats[$date][$feature] = (int) $row['total'];
            }
            
            return $stats;
            
        } catch (Exception $e) {
            Logger::error('Get Daily Stats Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * تنظيف سجلات الاستخدام القديمة
     * @param int $days
     * @return int
     */
    public function cleanOldLogs(int $days = 90): int {
        try {
            $sql = "DELETE FROM usage_tracking 
                    WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)";
            
            return (int) $this->db->query($sql, [':days' => $days]);
            
        } catch (Exception $e) {
            Logger::error('Clean Old Logs Error', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * الحصول على تصنيف المستخدمين حسب الاستخدام
     * @param string $feature
     * @param int $limit
     * @return array
     */
    public function getTopUsers(string $feature, int $limit = 10): array {
        try {
            $sql = "SELECT 
                        user_id,
                        SUM(amount) as total_usage,
                        COUNT(*) as total_events,
                        MAX(created_at) as last_used
                    FROM usage_tracking 
                    WHERE feature = :feature 
                    AND created_at > DATE_SUB(NOW(), INTERVAL 1 MONTH)
                    GROUP BY user_id
                    ORDER BY total_usage DESC
                    LIMIT :limit";
            
            return $this->db->query($sql, [
                ':feature' => $feature,
                ':limit' => $limit
            ]);
            
        } catch (Exception $e) {
            Logger::error('Get Top Users Error', [
                'feature' => $feature,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}