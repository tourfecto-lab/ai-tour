<?php
/**
 * Tourfecto - WhatsApp API Integration
 * تكامل مع WhatsApp Cloud API و Business API
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class WhatsAppAPI {
    /**
     * @var string $accessToken - رمز الوصول
     */
    private $accessToken;
    
    /**
     * @var string $phoneId - معرف رقم الهاتف
     */
    private $phoneId;
    
    /**
     * @var string $baseUrl - رابط API الأساسي
     */
    private $baseUrl = 'https://graph.facebook.com/v18.0';
    
    /**
     * @var int $timeout - مهلة الطلب
     */
    private $timeout = 30;
    
    /**
     * @var string $webhookVerifyToken - رمز التحقق من Webhook
     */
    private $webhookVerifyToken;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->accessToken = WHATSAPP_ACCESS_TOKEN;
        $this->phoneId = WHATSAPP_PHONE_ID;
        $this->webhookVerifyToken = WHATSAPP_WEBHOOK_VERIFY_TOKEN;
    }
    
    /**
     * إرسال رسالة نصية
     * @param string $phoneNumber - رقم المستلم
     * @param string $message - نص الرسالة
     * @return bool
     */
    public function sendMessage(string $phoneNumber, string $message): bool {
        try {
            // تنظيف رقم الهاتف
            $phoneNumber = $this->cleanPhoneNumber($phoneNumber);
            
            $endpoint = "/{$this->phoneId}/messages";
            $url = $this->baseUrl . $endpoint;
            
            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $phoneNumber,
                'type' => 'text',
                'text' => [
                    'body' => $message,
                    'preview_url' => false
                ]
            ];
            
            $response = $this->makeRequest('POST', $url, $payload);
            
            return $response['success'];
            
        } catch (Exception $e) {
            Logger::error('WhatsApp Send Message Error', [
                'phone' => $phoneNumber,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * إرسال رسالة قالب
     * @param string $phoneNumber
     * @param string $templateName
     * @param array $parameters
     * @return bool
     */
    public function sendTemplate(string $phoneNumber, string $templateName, array $parameters = []): bool {
        try {
            $phoneNumber = $this->cleanPhoneNumber($phoneNumber);
            
            $endpoint = "/{$this->phoneId}/messages";
            $url = $this->baseUrl . $endpoint;
            
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $phoneNumber,
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => [
                        'code' => 'ar'
                    ],
                    'components' => $this->buildTemplateComponents($parameters)
                ]
            ];
            
            $response = $this->makeRequest('POST', $url, $payload);
            
            return $response['success'];
            
        } catch (Exception $e) {
            Logger::error('WhatsApp Send Template Error', [
                'phone' => $phoneNumber,
                'template' => $templateName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * إرسال رسالة تفاعلية (أزرار)
     * @param string $phoneNumber
     * @param string $body
     * @param array $buttons
     * @return bool
     */
    public function sendInteractive(string $phoneNumber, string $body, array $buttons): bool {
        try {
            $phoneNumber = $this->cleanPhoneNumber($phoneNumber);
            
            $endpoint = "/{$this->phoneId}/messages";
            $url = $this->baseUrl . $endpoint;
            
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $phoneNumber,
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'button',
                    'body' => [
                        'text' => $body
                    ],
                    'action' => [
                        'buttons' => $this->buildButtons($buttons)
                    ]
                ]
            ];
            
            $response = $this->makeRequest('POST', $url, $payload);
            
            return $response['success'];
            
        } catch (Exception $e) {
            Logger::error('WhatsApp Send Interactive Error', [
                'phone' => $phoneNumber,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * إرسال صورة
     * @param string $phoneNumber
     * @param string $imageUrl
     * @param string $caption
     * @return bool
     */
    public function sendImage(string $phoneNumber, string $imageUrl, string $caption = ''): bool {
        try {
            $phoneNumber = $this->cleanPhoneNumber($phoneNumber);
            
            $endpoint = "/{$this->phoneId}/messages";
            $url = $this->baseUrl . $endpoint;
            
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $phoneNumber,
                'type' => 'image',
                'image' => [
                    'link' => $imageUrl,
                    'caption' => $caption
                ]
            ];
            
            $response = $this->makeRequest('POST', $url, $payload);
            
            return $response['success'];
            
        } catch (Exception $e) {
            Logger::error('WhatsApp Send Image Error', [
                'phone' => $phoneNumber,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * إرسال مستند
     * @param string $phoneNumber
     * @param string $documentUrl
     * @param string $filename
     * @return bool
     */
    public function sendDocument(string $phoneNumber, string $documentUrl, string $filename): bool {
        try {
            $phoneNumber = $this->cleanPhoneNumber($phoneNumber);
            
            $endpoint = "/{$this->phoneId}/messages";
            $url = $this->baseUrl . $endpoint;
            
            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $phoneNumber,
                'type' => 'document',
                'document' => [
                    'link' => $documentUrl,
                    'filename' => $filename
                ]
            ];
            
            $response = $this->makeRequest('POST', $url, $payload);
            
            return $response['success'];
            
        } catch (Exception $e) {
            Logger::error('WhatsApp Send Document Error', [
                'phone' => $phoneNumber,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * التحقق من Webhook
     * @param string $mode
     * @param string $token
     * @param string $challenge
     * @return array
     */
    public function verifyWebhook(string $mode, string $token, string $challenge): array {
        if ($mode === 'subscribe' && $token === $this->webhookVerifyToken) {
            return [
                'success' => true,
                'challenge' => $challenge
            ];
        }
        
        return [
            'success' => false,
            'error' => 'Invalid verification token'
        ];
    }
    
    /**
     * معالجة Webhook الوارد
     * @param array $data
     * @return array
     */
    public function processWebhook(array $data): array {
        try {
            $entry = $data['entry'][0] ?? null;
            $changes = $entry['changes'][0] ?? null;
            $value = $changes['value'] ?? null;
            
            if (!$value) {
                return [
                    'success' => false,
                    'error' => 'Invalid webhook data'
                ];
            }
            
            $messages = $value['messages'] ?? [];
            $contacts = $value['contacts'] ?? [];
            
            if (empty($messages)) {
                return [
                    'success' => true,
                    'message' => 'No messages to process'
                ];
            }
            
            $processed = [];
            foreach ($messages as $index => $message) {
                $contact = $contacts[$index] ?? [];
                
                $processed[] = [
                    'id' => $message['id'] ?? null,
                    'from' => $message['from'] ?? null,
                    'type' => $message['type'] ?? 'text',
                    'text' => $message['text']['body'] ?? '',
                    'timestamp' => $message['timestamp'] ?? null,
                    'contact' => [
                        'profile_name' => $contact['profile']['name'] ?? null,
                        'wa_id' => $contact['wa_id'] ?? null
                    ],
                    'raw' => $message
                ];
            }
            
            return [
                'success' => true,
                'messages' => $processed,
                'count' => count($processed)
            ];
            
        } catch (Exception $e) {
            Logger::error('WhatsApp Process Webhook Error', [
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
     * @param array $data
     * @return array
     */
    private function makeRequest(string $method, string $url, array $data = []): array {
        $ch = curl_init($url);
        
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Tourfecto/1.0'
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
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
        
        if ($httpCode < 200 || $httpCode >= 300) {
            $errorData = json_decode($response, true);
            $errorMessage = $errorData['error']['message'] ?? 'Unknown error';
            
            return [
                'success' => false,
                'error' => "WhatsApp API Error ({$httpCode}): {$errorMessage}",
                'http_code' => $httpCode
            ];
        }
        
        return [
            'success' => true,
            'data' => json_decode($response, true),
            'http_code' => $httpCode
        ];
    }
    
    /**
     * تنظيف رقم الهاتف
     * @param string $phoneNumber
     * @return string
     */
    private function cleanPhoneNumber(string $phoneNumber): string {
        // إزالة أي أحرف غير رقمية
        $cleaned = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // التأكد من وجود رمز الدولة
        if (strlen($cleaned) < 10) {
            // إضافة رمز مصر الافتراضي
            $cleaned = '20' . $cleaned;
        }
        
        return $cleaned;
    }
    
    /**
     * بناء مكونات القالب
     * @param array $parameters
     * @return array
     */
    private function buildTemplateComponents(array $parameters): array {
        $components = [];
        
        if (!empty($parameters)) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(function($param) {
                    return ['type' => 'text', 'text' => $param];
                }, $parameters)
            ];
        }
        
        return $components;
    }
    
    /**
     * بناء أزرار تفاعلية
     * @param array $buttons
     * @return array
     */
    private function buildButtons(array $buttons): array {
        $result = [];
        $types = ['reply', 'call'];
        
        foreach ($buttons as $index => $button) {
            $type = $types[$index % 2] ?? 'reply';
            
            $result[] = [
                'type' => $type,
                'reply' => [
                    'id' => 'btn_' . ($index + 1),
                    'title' => $button
                ]
            ];
        }
        
        return $result;
    }
}