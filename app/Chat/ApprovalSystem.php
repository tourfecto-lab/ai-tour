<?php
/**
 * Tourfecto - Approval System
 * نظام إدارة الموافقات على ردود البوت (Human-in-the-Loop)
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class ApprovalSystem {
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;
    
    /**
     * @var NotificationService $notificationService - خدمة الإشعارات
     */
    private $notificationService;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->db = Database::getInstance();
        $this->notificationService = new NotificationService();
    }
    
    /**
     * إضافة طلب موافقة جديد
     * @param int $messageId - معرف الرسالة
     * @param int $userId - معرف المستخدم
     * @return bool
     */
    public function addPendingApproval(int $messageId, int $userId): bool {
        try {
            // التحقق من عدم وجود طلب مسبق
            $sql = "SELECT id FROM chat_messages 
                    WHERE id = :message_id 
                    AND bot_status = 'pending_approval'";
            
            $result = $this->db->query($sql, [':message_id' => $messageId]);
            
            if (!empty($result)) {
                return true;
            }
            
            // تحديث حالة الرسالة
            $sql = "UPDATE chat_messages 
                    SET bot_status = 'pending_approval',
                        updated_at = NOW()
                    WHERE id = :message_id";
            
            $this->db->query($sql, [':message_id' => $messageId]);
            
            // إرسال إشعار للمستخدم
            $this->notificationService->sendApprovalNotification($userId, $messageId);
            
            return true;
            
        } catch (Exception $e) {
            Logger::error('Add Pending Approval Error', [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * الموافقة على رد
     * @param int $messageId - معرف الرسالة
     * @param int $userId - معرف المستخدم الموافق
     * @return array
     */
    public function approve(int $messageId, int $userId): array {
        try {
            // التحقق من الرسالة
            $sql = "SELECT * FROM chat_messages 
                    WHERE id = :message_id 
                    AND bot_status = 'pending_approval'";
            
            $message = $this->db->query($sql, [':message_id' => $messageId]);
            
            if (empty($message)) {
                return [
                    'success' => false,
                    'error' => 'Message not found or not pending approval.'
                ];
            }
            
            $msg = $message[0];
            
            // تحديث حالة الرسالة
            $sql = "UPDATE chat_messages 
                    SET bot_status = 'approved',
                        approved_by_user_id = :user_id,
                        approved_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :message_id";
            
            $this->db->query($sql, [
                ':message_id' => $messageId,
                ':user_id' => $userId
            ]);
            
            // إرسال الرد
            if (!empty($msg['ai_reply_generated'])) {
                $chatManager = new ChatManager();
                $sent = $chatManager->sendMessage(
                    $msg['customer_phone'],
                    $msg['ai_reply_generated'],
                    $msg['platform']
                );
                
                if ($sent) {
                    $this->markAsSent($messageId);
                }
            }
            
            // تسجيل النشاط
            Logger::info('Message Approved', [
                'message_id' => $messageId,
                'user_id' => $userId
            ]);
            
            return [
                'success' => true,
                'message_id' => $messageId,
                'status' => 'approved',
                'message' => 'Message approved successfully.'
            ];
            
        } catch (Exception $e) {
            Logger::error('Approve Error', [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * رفض رد
     * @param int $messageId - معرف الرسالة
     * @param int $userId - معرف المستخدم الرافض
     * @param string $reason - سبب الرفض
     * @return array
     */
    public function reject(int $messageId, int $userId, string $reason = ''): array {
        try {
            $sql = "SELECT * FROM chat_messages 
                    WHERE id = :message_id 
                    AND bot_status = 'pending_approval'";
            
            $message = $this->db->query($sql, [':message_id' => $messageId]);
            
            if (empty($message)) {
                return [
                    'success' => false,
                    'error' => 'Message not found or not pending approval.'
                ];
            }
            
            $sql = "UPDATE chat_messages 
                    SET bot_status = 'rejected',
                        approved_by_user_id = :user_id,
                        approved_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :message_id";
            
            $this->db->query($sql, [
                ':message_id' => $messageId,
                ':user_id' => $userId
            ]);
            
            // تسجيل سبب الرفض
            if ($reason) {
                $sql = "INSERT INTO chat_approval_logs 
                        (message_id, user_id, action, reason, created_at) 
                        VALUES 
                        (:message_id, :user_id, 'rejected', :reason, NOW())";
                
                $this->db->query($sql, [
                    ':message_id' => $messageId,
                    ':user_id' => $userId,
                    ':reason' => $reason
                ]);
            }
            
            Logger::info('Message Rejected', [
                'message_id' => $messageId,
                'user_id' => $userId,
                'reason' => $reason
            ]);
            
            return [
                'success' => true,
                'message_id' => $messageId,
                'status' => 'rejected',
                'message' => 'Message rejected.'
            ];
            
        } catch (Exception $e) {
            Logger::error('Reject Error', [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * تحديث حالة الرسالة إلى مرسلة
     * @param int $messageId
     */
    public function markAsSent(int $messageId): void {
        try {
            $sql = "UPDATE chat_messages 
                    SET bot_status = 'sent',
                        sent_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :message_id";
            
            $this->db->query($sql, [':message_id' => $messageId]);
            
        } catch (Exception $e) {
            Logger::error('Mark As Sent Error', [
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * الحصول على قائمة طلبات الموافقة المعلقة
     * @param int $userId - معرف المستخدم
     * @param int $limit - عدد النتائج
     * @return array
     */
    public function getPendingApprovals(int $userId, int $limit = 50): array {
        try {
            $urlCol = Website::urlColumn();
            $sql = "SELECT 
                        cm.*,
                        u.company_name,
                        w.{$urlCol} AS main_url
                    FROM chat_messages cm
                    JOIN users u ON cm.user_id = u.id
                    JOIN websites w ON cm.website_id = w.id
                    WHERE cm.user_id = :user_id
                    AND cm.bot_status = 'pending_approval'
                    AND cm.message_direction = 'incoming'
                    ORDER BY cm.created_at ASC
                    LIMIT :limit";
            
            $results = $this->db->query($sql, [
                ':user_id' => $userId,
                ':limit' => $limit
            ]);
            
            // فك تشفير البيانات الحساسة
            $encryption = new Encryption();
            foreach ($results as &$row) {
                if (!empty($row['encrypted_phone'])) {
                    $row['customer_phone'] = $encryption->decryptCustomerData(
                        $row['encrypted_phone'],
                        $row['customer_phone'] ?? ''
                    );
                }
                unset($row['encrypted_phone']);
                unset($row['encrypted_email']);
            }
            
            return $results;
            
        } catch (Exception $e) {
            Logger::error('Get Pending Approvals Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * الحصول على عدد طلبات الموافقة المعلقة
     * @param int $userId
     * @return int
     */
    public function getPendingCount(int $userId): int {
        try {
            $sql = "SELECT COUNT(*) as count 
                    FROM chat_messages 
                    WHERE user_id = :user_id 
                    AND bot_status = 'pending_approval'
                    AND message_direction = 'incoming'";
            
            $result = $this->db->query($sql, [':user_id' => $userId]);
            
            return (int) ($result[0]['count'] ?? 0);
            
        } catch (Exception $e) {
            Logger::error('Get Pending Count Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * الحصول على سجل الموافقات
     * @param int $userId
     * @param int $limit
     * @return array
     */
    public function getApprovalHistory(int $userId, int $limit = 20): array {
        try {
            $sql = "SELECT 
                        cm.id,
                        cm.customer_phone,
                        cm.message_text,
                        cm.ai_reply_generated,
                        cm.bot_status,
                        cm.approved_by_user_id,
                        cm.approved_at,
                        cm.sent_at,
                        u.company_name as approver_name
                    FROM chat_messages cm
                    LEFT JOIN users u ON cm.approved_by_user_id = u.id
                    WHERE cm.user_id = :user_id
                    AND cm.bot_status IN ('approved', 'rejected', 'sent')
                    ORDER BY cm.updated_at DESC
                    LIMIT :limit";
            
            $results = $this->db->query($sql, [
                ':user_id' => $userId,
                ':limit' => $limit
            ]);
            
            // فك تشفير البيانات الحساسة
            $encryption = new Encryption();
            foreach ($results as &$row) {
                if (!empty($row['customer_phone'])) {
                    $row['customer_phone'] = $encryption->decryptCustomerData(
                        $row['customer_phone'],
                        $row['customer_phone']
                    );
                }
            }
            
            return $results;
            
        } catch (Exception $e) {
            Logger::error('Get Approval History Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}

/**
 * Class NotificationService - خدمة الإشعارات (داخل نفس الملف)
 */
class NotificationService {
    /**
     * @var Database $db
     */
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * إرسال إشعار موافقة
     * @param int $userId
     * @param int $messageId
     */
    public function sendApprovalNotification(int $userId, int $messageId): void {
        try {
            // هنا يمكن إرسال إشعار عبر البريد الإلكتروني أو التطبيق
            Logger::info('Approval Notification Sent', [
                'user_id' => $userId,
                'message_id' => $messageId
            ]);
            
        } catch (Exception $e) {
            // تجاهل
        }
    }
}