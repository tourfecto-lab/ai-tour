<?php
/**
 * Tourfecto - Billing Manager
 * إدارة الفواتير والمدفوعات والاشتراكات
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class BillingManager {
    /**
     * @var Database $db - اتصال قاعدة البيانات
     */
    private $db;
    
    /**
     * @var array $paymentGateways - بوابات الدفع المدعومة
     */
    private $paymentGateways = [];
    
    /**
     * @var string $defaultCurrency - العملة الافتراضية
     */
    private $defaultCurrency = 'USD';
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->db = Database::getInstance();
        $this->defaultCurrency = DEFAULT_CURRENCY;
        $this->loadPaymentGateways();
    }
    
    /**
     * إنشاء فاتورة جديدة
     * @param int $userId
     * @param string $planName
     * @param string $planType
     * @param array $items
     * @return array
     */
    public function createInvoice(int $userId, string $planName, string $planType = 'monthly', array $items = []): array {
        try {
            $plans = $this->getPlans();
            $plan = $plans[$planName] ?? null;
            
            if (!$plan) {
                return [
                    'success' => false,
                    'error' => 'Invalid plan'
                ];
            }
            
            $amount = $planType === 'yearly' ? $plan['price_yearly'] : $plan['price_monthly'];
            
            // إنشاء رقم فاتورة فريد
            $invoiceNumber = $this->generateInvoiceNumber();
            
            $sql = "INSERT INTO invoices 
                    (user_id, invoice_number, plan_name, plan_type, amount, currency, 
                     status, items, created_at, due_date) 
                    VALUES 
                    (:user_id, :invoice_number, :plan_name, :plan_type, :amount, :currency,
                     'pending', :items, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))";
            
            $invoiceId = $this->db->query($sql, [
                ':user_id' => $userId,
                ':invoice_number' => $invoiceNumber,
                ':plan_name' => $planName,
                ':plan_type' => $planType,
                ':amount' => $amount,
                ':currency' => $this->defaultCurrency,
                ':items' => json_encode($items ?: [
                    [
                        'description' => $plan['name'] . ' - ' . ($planType === 'yearly' ? 'سنوي' : 'شهري'),
                        'amount' => $amount,
                        'quantity' => 1
                    ]
                ])
            ]);
            
            return [
                'success' => true,
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoiceNumber,
                'amount' => $amount,
                'currency' => $this->defaultCurrency,
                'status' => 'pending'
            ];
            
        } catch (Exception $e) {
            Logger::error('Create Invoice Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * معالجة الدفع
     * @param int $invoiceId
     * @param string $paymentMethod
     * @param array $paymentData
     * @return array
     */
    public function processPayment(int $invoiceId, string $paymentMethod, array $paymentData): array {
        try {
            // جلب بيانات الفاتورة
            $sql = "SELECT * FROM invoices WHERE id = :invoice_id AND status = 'pending' LIMIT 1";
            $invoice = $this->db->query($sql, [':invoice_id' => $invoiceId]);
            
            if (empty($invoice)) {
                return [
                    'success' => false,
                    'error' => 'Invoice not found or already processed'
                ];
            }
            
            $invoice = $invoice[0];
            
            // معالجة الدفع حسب الطريقة
            $paymentResult = $this->processPaymentMethod($paymentMethod, $invoice, $paymentData);
            
            if (!$paymentResult['success']) {
                return $paymentResult;
            }
            
            // تحديث حالة الفاتورة
            $sql = "UPDATE invoices 
                    SET status = 'paid',
                        payment_method = :payment_method,
                        payment_date = NOW(),
                        transaction_id = :transaction_id,
                        updated_at = NOW()
                    WHERE id = :invoice_id";
            
            $this->db->query($sql, [
                ':invoice_id' => $invoiceId,
                ':payment_method' => $paymentMethod,
                ':transaction_id' => $paymentResult['transaction_id']
            ]);
            
            // تفعيل الاشتراك
            $this->activateSubscription(
                $invoice['user_id'],
                $invoice['plan_name'],
                $invoice['plan_type']
            );
            
            // تسجيل المعاملة
            $this->logTransaction($invoiceId, $paymentResult['transaction_id'], $paymentResult);
            
            return [
                'success' => true,
                'invoice_id' => $invoiceId,
                'transaction_id' => $paymentResult['transaction_id'],
                'status' => 'paid',
                'message' => 'Payment processed successfully'
            ];
            
        } catch (Exception $e) {
            Logger::error('Process Payment Error', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * تفعيل الاشتراك
     * @param int $userId
     * @param string $planName
     * @param string $planType
     * @return bool
     */
    public function activateSubscription(int $userId, string $planName, string $planType = 'monthly'): bool {
        try {
            // إلغاء الاشتراكات القديمة
            $sql = "UPDATE subscriptions 
                    SET status = 'expired' 
                    WHERE user_id = :user_id 
                    AND status = 'active'";
            
            $this->db->query($sql, [':user_id' => $userId]);
            
            // إنشاء اشتراك جديد
            $result = Subscription::createSubscription($userId, $planName, $planType);
            
            return (bool) $result;
            
        } catch (Exception $e) {
            Logger::error('Activate Subscription Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * إلغاء الاشتراك
     * @param int $userId
     * @param string $reason
     * @return bool
     */
    public function cancelSubscription(int $userId, string $reason = ''): bool {
        try {
            $sql = "UPDATE subscriptions 
                    SET status = 'cancelled',
                        cancelled_at = NOW(),
                        cancellation_reason = :reason,
                        updated_at = NOW()
                    WHERE user_id = :user_id 
                    AND status = 'active'";
            
            $result = $this->db->query($sql, [
                ':user_id' => $userId,
                ':reason' => $reason
            ]);
            
            // تسجيل الإلغاء
            if ($result > 0) {
                Logger::info('Subscription Cancelled', [
                    'user_id' => $userId,
                    'reason' => $reason
                ]);
            }
            
            return $result > 0;
            
        } catch (Exception $e) {
            Logger::error('Cancel Subscription Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * الحصول على فواتير المستخدم
     * @param int $userId
     * @param int $limit
     * @return array
     */
    public function getUserInvoices(int $userId, int $limit = 20): array {
        try {
            $sql = "SELECT * FROM invoices 
                    WHERE user_id = :user_id 
                    ORDER BY created_at DESC 
                    LIMIT :limit";
            
            return $this->db->query($sql, [
                ':user_id' => $userId,
                ':limit' => $limit
            ]);
            
        } catch (Exception $e) {
            Logger::error('Get User Invoices Error', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * معالجة الدفع حسب الطريقة
     * @param string $method
     * @param array $invoice
     * @param array $data
     * @return array
     */
    private function processPaymentMethod(string $method, array $invoice, array $data): array {
        switch ($method) {
            case 'stripe':
                return $this->processStripePayment($invoice, $data);
                
            case 'paypal':
                return $this->processPayPalPayment($invoice, $data);
                
            case 'bank_transfer':
                return $this->processBankTransfer($invoice, $data);
                
            default:
                return [
                    'success' => false,
                    'error' => 'Unsupported payment method'
                ];
        }
    }
    
    /**
     * معالجة دفع عبر Stripe
     * @param array $invoice
     * @param array $data
     * @return array
     */
    private function processStripePayment(array $invoice, array $data): array {
        // تنفيذ Stripe API
        // هذا مثال مبسط
        return [
            'success' => true,
            'transaction_id' => 'stripe_' . uniqid(),
            'message' => 'Stripe payment processed'
        ];
    }
    
    /**
     * معالجة دفع عبر PayPal
     * @param array $invoice
     * @param array $data
     * @return array
     */
    private function processPayPalPayment(array $invoice, array $data): array {
        // تنفيذ PayPal API
        return [
            'success' => true,
            'transaction_id' => 'paypal_' . uniqid(),
            'message' => 'PayPal payment processed'
        ];
    }
    
    /**
     * معالجة تحويل بنكي
     * @param array $invoice
     * @param array $data
     * @return array
     */
    private function processBankTransfer(array $invoice, array $data): array {
        return [
            'success' => true,
            'transaction_id' => 'bank_' . uniqid(),
            'message' => 'Bank transfer recorded'
        ];
    }
    
    /**
     * توليد رقم فاتورة فريد
     * @return string
     */
    private function generateInvoiceNumber(): string {
        return 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    }
    
    /**
     * تسجيل المعاملة
     * @param int $invoiceId
     * @param string $transactionId
     * @param array $data
     */
    private function logTransaction(int $invoiceId, string $transactionId, array $data): void {
        try {
            $sql = "INSERT INTO payment_transactions 
                    (invoice_id, transaction_id, data, created_at) 
                    VALUES 
                    (:invoice_id, :transaction_id, :data, NOW())";
            
            $this->db->query($sql, [
                ':invoice_id' => $invoiceId,
                ':transaction_id' => $transactionId,
                ':data' => json_encode($data)
            ]);
            
        } catch (Exception $e) {
            // تجاهل
        }
    }
    
    /**
     * تحميل بوابات الدفع
     */
    private function loadPaymentGateways(): void {
        $this->paymentGateways = [
            'stripe' => [
                'name' => 'Stripe',
                'enabled' => true,
                'test_mode' => true
            ],
            'paypal' => [
                'name' => 'PayPal',
                'enabled' => true,
                'test_mode' => true
            ],
            'bank_transfer' => [
                'name' => 'تحويل بنكي',
                'enabled' => true
            ]
        ];
    }
    
    /**
     * الحصول على الباقات المتاحة
     * @return array
     */
    private function getPlans(): array {
        return SUBSCRIPTION_PLANS;
    }
}