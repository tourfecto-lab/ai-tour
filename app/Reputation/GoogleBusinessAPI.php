<?php
/**
 * Tourfecto - Google Business API Integration
 * تكامل مع Google Business Profile API
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class GoogleBusinessAPI {
    /**
     * @var string $apiKey - مفتاح API
     */
    private $apiKey;
    
    /**
     * @var string $accessToken - رمز الوصول
     */
    private $accessToken;
    
    /**
     * @var string $baseUrl - رابط API الأساسي
     */
    private $baseUrl = 'https://mybusiness.googleapis.com/v4';
    
    /**
     * @var int $timeout - مهلة الطلب
     */
    private $timeout = 30;
    
    /**
     * @var string $accountId - معرف الحساب
     */
    private $accountId;
    
    /**
     * @var string $locationId - معرف الموقع
     */
    private $locationId;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->apiKey = getenv('GOOGLE_API_KEY') ?: '';
        $this->accessToken = getenv('GOOGLE_ACCESS_TOKEN') ?: '';
        $this->accountId = getenv('GOOGLE_BUSINESS_ACCOUNT_ID') ?: '';
        $this->locationId = getenv('GOOGLE_BUSINESS_LOCATION_ID') ?: '';
    }
    
    /**
     * جلب المراجعات
     * @param array $params - معاملات الطلب
     * @return array
     */
    public function getReviews(array $params = []): array {
        try {
            $locationId = $params['location_id'] ?? $this->locationId;
            if (!$locationId) {
                return [
                    'success' => false,
                    'error' => 'Location ID is required'
                ];
            }
            
            $endpoint = "/accounts/{$this->accountId}/locations/{$locationId}/reviews";
            
            $query = [
                'pageSize' => $params['limit'] ?? 20,
                'pageToken' => $params['page_token'] ?? null
            ];
            
            $response = $this->makeRequest('GET', $endpoint, $query);
            
            if (!$response['success']) {
                return $response;
            }
            
            $reviews = $this->parseReviews($response['data']);
            
            return [
                'success' => true,
                'reviews' => $reviews,
                'total' => $response['data']['totalReviewCount'] ?? 0,
                'next_page_token' => $response['data']['nextPageToken'] ?? null,
                'source' => 'google_business'
            ];
            
        } catch (Exception $e) {
            Logger::error('Google Business Get Reviews Error', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * إرسال رد على مراجعة
     * @param string $reviewId - معرف المراجعة
     * @param string $reply - نص الرد
     * @return array
     */
    public function sendReply(string $reviewId, string $reply): array {
        try {
            $endpoint = "/accounts/{$this->accountId}/locations/{$this->locationId}/reviews/{$reviewId}/reply";
            
            $data = [
                'comment' => $reply,
                'languageCode' => 'ar'
            ];
            
            $response = $this->makeRequest('PUT', $endpoint, [], $data);
            
            return [
                'success' => $response['success'],
                'review_id' => $reviewId,
                'reply_sent' => $response['success'],
                'message' => $response['success'] ? 'Reply sent successfully' : 'Failed to send reply'
            ];
            
        } catch (Exception $e) {
            Logger::error('Google Business Send Reply Error', [
                'review_id' => $reviewId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * جلب معلومات الموقع
     * @param string $locationId
     * @return array
     */
    public function getLocation(?string $locationId = null): array {
        try {
            $locationId = $locationId ?? $this->locationId;
            if (!$locationId) {
                return [
                    'success' => false,
                    'error' => 'Location ID is required'
                ];
            }
            
            $endpoint = "/accounts/{$this->accountId}/locations/{$locationId}";
            
            $response = $this->makeRequest('GET', $endpoint);
            
            if (!$response['success']) {
                return $response;
            }
            
            return [
                'success' => true,
                'location' => [
                    'id' => $response['data']['name'] ?? null,
                    'name' => $response['data']['title'] ?? null,
                    'rating' => $response['data']['averageRating'] ?? null,
                    'review_count' => $response['data']['totalReviewCount'] ?? 0,
                    'address' => $response['data']['address'] ?? null,
                    'phone' => $response['data']['phoneNumbers']['primary'] ?? null,
                    'website' => $response['data']['websiteUrl'] ?? null,
                    'categories' => $response['data']['categories'] ?? []
                ]
            ];
            
        } catch (Exception $e) {
            Logger::error('Google Business Get Location Error', [
                'location_id' => $locationId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * جلب إحصائيات الموقع
     * @param string $locationId
     * @param array $metrics
     * @return array
     */
    public function getLocationStats(?string $locationId = null, array $metrics = []): array {
        try {
            $locationId = $locationId ?? $this->locationId;
            if (!$locationId) {
                return [
                    'success' => false,
                    'error' => 'Location ID is required'
                ];
            }
            
            $endpoint = "/accounts/{$this->accountId}/locations/{$locationId}/metrics";
            
            $data = [
                'metrics' => $metrics ?: [
                    'CALLS', 'WEBSITE_ACTIONS', 'DIRECTIONS', 'VIEWS_MAPS', 
                    'VIEWS_SEARCH', 'ACTIONS', 'REVIEWS'
                ],
                'timeRange' => [
                    'startTime' => date('Y-m-d\TH:i:s\Z', strtotime('-30 days')),
                    'endTime' => date('Y-m-d\TH:i:s\Z')
                ]
            ];
            
            $response = $this->makeRequest('POST', $endpoint, [], $data);
            
            if (!$response['success']) {
                return $response;
            }
            
            return [
                'success' => true,
                'metrics' => $this->parseMetrics($response['data']),
                'source' => 'google_business'
            ];
            
        } catch (Exception $e) {
            Logger::error('Google Business Get Stats Error', [
                'location_id' => $locationId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * تحديث رمز الوصول
     * @param string $refreshToken
     * @return array
     */
    public function refreshAccessToken(string $refreshToken): array {
        try {
            $clientId = (getenv('GOOGLE_CLIENT_ID') ?: (isset($_ENV['GOOGLE_CLIENT_ID']) ? (string) $_ENV['GOOGLE_CLIENT_ID'] : false)) ?: '';
            $clientSecret = (getenv('GOOGLE_CLIENT_SECRET') ?: (isset($_ENV['GOOGLE_CLIENT_SECRET']) ? (string) $_ENV['GOOGLE_CLIENT_SECRET'] : false)) ?: '';
            
            $url = 'https://oauth2.googleapis.com/token';
            
            $data = [
                'refresh_token' => $refreshToken,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'refresh_token'
            ];
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($data),
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_SSL_VERIFYPEER => true
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                return [
                    'success' => false,
                    'error' => "Failed to refresh token: {$httpCode}"
                ];
            }
            
            $data = json_decode($response, true);
            
            return [
                'success' => true,
                'access_token' => $data['access_token'],
                'expires_in' => $data['expires_in'] ?? 3600
            ];
            
        } catch (Exception $e) {
            Logger::error('Google Business Refresh Token Error', [
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
     * @param string $endpoint
     * @param array $query
     * @param array $data
     * @return array
     */
    private function makeRequest(string $method, string $endpoint, array $query = [], array $data = []): array {
        $url = $this->baseUrl . $endpoint;
        
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        
        $ch = curl_init($url);
        
        $headers = [
            'Accept: application/json'
        ];
        
        if ($this->accessToken) {
            $headers[] = 'Authorization: Bearer ' . $this->accessToken;
        }
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Tourfecto/1.0'
        ]);
        
        if ($method === 'POST' || $method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                $headers[] = 'Content-Type: application/json';
            }
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
            $errorMessage = $decoded['error']['message'] ?? 'Unknown error';
            return [
                'success' => false,
                'error' => "Google Business API Error ({$httpCode}): {$errorMessage}",
                'http_code' => $httpCode,
                'raw_response' => $response
            ];
        }
        
        return [
            'success' => true,
            'data' => $decoded,
            'http_code' => $httpCode
        ];
    }
    
    /**
     * تحليل بيانات المراجعات
     * @param array $data
     * @return array
     */
    private function parseReviews(array $data): array {
        $reviews = [];
        
        $items = $data['reviews'] ?? [];
        foreach ($items as $item) {
            $reviews[] = [
                'id' => $this->extractId($item['name'] ?? ''),
                'rating' => $item['starRating'] ?? 0,
                'text' => $item['comment'] ?? '',
                'language' => 'en',
                'date' => $item['createTime'] ?? null,
                'reviewer' => [
                    'name' => $item['reviewer']['displayName'] ?? 'Guest'
                ],
                'response' => $item['reply'] ?? null,
                'helpful_count' => $item['helpfulCount'] ?? 0
            ];
        }
        
        return $reviews;
    }
    
    /**
     * تحليل بيانات المقاييس
     * @param array $data
     * @return array
     */
    private function parseMetrics(array $data): array {
        $metrics = [];
        
        foreach ($data['metrics'] ?? [] as $metric) {
            $metrics[$metric['metric']] = [
                'value' => $metric['value'] ?? 0,
                'change' => $metric['change'] ?? 0
            ];
        }
        
        return $metrics;
    }
    
    /**
     * استخراج المعرف من الاسم
     * @param string $name
     * @return string
     */
    private function extractId(string $name): string {
        $parts = explode('/', $name);
        return end($parts);
    }
    
    /**
     * التحقق من صلاحية الرمز
     * @return bool
     */
    public function validateAccessToken(): bool {
        try {
            $response = $this->makeRequest('GET', "/accounts/{$this->accountId}");
            return $response['success'];
            
        } catch (Exception $e) {
            return false;
        }
    }
}