<?php
/**
 * Tourfecto - UltraMsg API Integration
 * تكامل مع UltraMsg لخدمات WhatsApp
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class UltraMsgAPI {
    /**
     * @var string $instanceId - معرف المثيل
     */
    private $instanceId;
    
    /**
     * @var string $apiKey - مفتاح API
     */
    private $apiKey;
    
    /**
     * @var string $baseUrl - رابط API الأساسي
     */
    private $baseUrl;
    
    /**
     * @var int $timeout - مهلة الطلب
     */
    private $timeout = 30;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->instanceId = ULTRAMSG_INSTANCE_ID;
        $this->apiKey = ULTRAMSG_API_KEY;
        $this->baseUrl = ULTRAMSG_BASE_URL;
    }
    
    /**
     * إرسال رسالة نصية
     * @param string $phoneNumber - رقم المستلم
     * @param string $message - نص الرسالة
     * @return bool
     */
    public function sendMessage(string $phoneNumber, string $message): bool {
        try {
            $phoneNumber = $this->cleanPhoneNumber($phoneNumber);
            
            $endpoint = "/messages/chat";
            $url = $this->baseUrl . $endpoint;
            
            $params = [
                'token' => $this->apiKey,
                'to' => $phoneNumber,
                'body' => $message,
                'priority' => 1,
                'referenceId' => ''
            ];
            
            $response = $this->makeRequest('POST', $url, $params);
            
            return $response['success'] && ($response['data']['sent'] ?? false);
            
        } catch (Exception $e) {
            Logger::error('UltraMsg Send Message Error', [
                'phone' => $phoneNumber,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * إرسال رسالة مع صورة
     * @param string $phoneNumber
     * @param string $imageUrl
     * @param string $caption
     * @return bool
     */
    public function sendImage(string $phoneNumber, string $imageUrl, string $caption = ''): bool {
        try {
            $phoneNumber = $this->cleanPhoneNumber($phoneNumber);
            
            $endpoint = "/messages/image";
            $url = $this->baseUrl . $endpoint;
            
            $params = [
                'token' => $this->apiKey,
                'to' => $phoneNumber,
                'image' => $imageUrl,
                'caption' => $caption,
                'priority' => 1
            ];
            
            $response = $this->makeRequest('POST', $url, $params);
            
            return $response['success'] && ($response['data']['sent'] ?? false);
            
        } catch (Exception $e) {
            Logger::error('UltraMsg Send Image Error', [
                'phone' => $phoneNumber,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * إرسال رسالة مع مستند
     * @param string $phoneNumber
     * @param string $documentUrl
     * @param string $filename
     * @return bool
     */
    public function sendDocument(string $phoneNumber, string $documentUrl, string $filename): bool {
        try {
            $phoneNumber = $this->cleanPhoneNumber($phoneNumber);
            
            $endpoint = "/messages/document";
            $url = $this->baseUrl . $endpoint;
            
            $params = [
                'token' => $this->apiKey,
                'to' => $phoneNumber,
                'document' => $documentUrl,
                'filename' => $filename,
                'priority' => 1
            ];
            
            $response = $this->makeRequest('POST', $url, $params);
            
            return $response['success'] && ($response['data']['sent'] ?? false);
            
        } catch (Exception $e) {
            Logger::error('UltraMsg Send Document Error', [
                'phone' => $phoneNumber,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * إرسال قالب
     * @param string $phoneNumber
     * @param string $templateName
     * @param array $parameters
     * @return bool
     */
    public function sendTemplate(string $phoneNumber, string $templateName, array $parameters = []): bool {
        try {
            $phoneNumber = $this->cleanPhoneNumber($phoneNumber);
            
            $endpoint = "/messages/template";
            $url = $this->baseUrl . $endpoint;
            
            $params = [
                'token' => $this->apiKey,
                'to' => $phoneNumber,
                'template' => $templateName,
                'params' => implode(';', $parameters),
                'priority' => 1
            ];
            
            $response = $this->makeRequest('POST', $url, $params);
            
            return $response['success'] && ($response['data']['sent'] ?? false);
            
        } catch (Exception $e) {
            Logger::error('UltraMsg Send Template Error', [
                'phone' => $phoneNumber,
                'template' => $templateName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * الحصول على حالة الرسالة
     * @param string $messageId
     * @return array
     */
    public function getMessageStatus(string $messageId): array {
        try {
            $endpoint = "/messages/status";
            $url = $this->baseUrl . $endpoint;
            
            $params = [
                'token' => $this->apiKey,
                'referenceId' => $messageId
            ];
            
            $response = $this->makeRequest('GET', $url, $params);
            
            if (!$response['success']) {
                return [
                    'success' => false,
                    'error' => $response['error'] ?? 'Unknown error'
                ];
            }
            
            return [
                'success' => true,
                'status' => $response['data']['status'] ?? 'unknown',
                'sent' => $response['data']['sent'] ?? false,
                'delivered' => $response['data']['delivered'] ?? false,
                'read' => $response['data']['read'] ?? false
            ];
            
        } catch (Exception $e) {
            Logger::error('UltraMsg Get Status Error', [
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
     * معالجة Webhook الوارد
     * @param array $data
     * @return array
     */
    public function processWebhook(array $data): array {
        try {
            if (!isset($data['data']['message'])) {
                return [
                    'success' => false,
                    'error' => 'Invalid webhook data'
                ];
            }
            
            $message = $data['data']['message'];
            
            return [
                'success' => true,
                'message' => [
                    'id' => $message['id'] ?? null,
                    'from' => $message['from'] ?? null,
                    'to' => $message['to'] ?? null,
                    'body' => $message['body'] ?? '',
                    'type' => $message['type'] ?? 'text',
                    'timestamp' => $message['timestamp'] ?? null,
                    'sender_name' => $message['pushName'] ?? null,
                    'is_forwarded' => $message['isForwarded'] ?? false,
                    'raw' => $data
                ]
            ];
            
        } catch (Exception $e) {
            Logger::error('UltraMsg Process Webhook Error', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * إرسال طلب إلى API
     * @param string $method
     * @param string $url
     * @param array $params
     * @return array
     */
    private function makeRequest(string $method, string $url, array $params = []): array {
        $ch = curl_init($url);
        
        $fullUrl = $url . '?' . http_build_query($params);
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Tourfecto/1.0'
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            return [
                'success' => false,
                'error' => 'cURL Error: ' . $curlError
            ];
        }
        
        $decoded = json_decode($response, true);
        
        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMessage = $decoded['error'] ?? 'Unknown error';
            
            return [
                'success' => false,
                'error' => "UltraMsg API Error ({$httpCode}): {$errorMessage}",
                'http_code' => $httpCode
            ];
        }
        
        return [
            'success' => true,
            'data' => $decoded,
            'http_code' => $httpCode
        ];
    }
    
    /**
     * تنظيف رقم الهاتف
     * @param string $phoneNumber
     * @return string
     */
    private function cleanPhoneNumber(string $phoneNumber): string {
        $cleaned = preg_replace('/[^0-9+]/', '', $phoneNumber);
        
        // إزالة علامة + إذا كانت موجودة
        $cleaned = ltrim($cleaned, '+');
        
        // التأكد من وجود رمز الدولة
        if (strlen($cleaned) < 10) {
            $cleaned = '20' . $cleaned;
        }
        
        return $cleaned;
    }
}