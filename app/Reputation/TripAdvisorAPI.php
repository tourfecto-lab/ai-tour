<?php
/**
 * Tourfecto - TripAdvisor API Integration
 * تكامل مع API تريب أدفايسر
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class TripAdvisorAPI {
    /**
     * @var string $apiKey - مفتاح API
     */
    private $apiKey;
    
    /**
     * @var string $apiSecret - السر API
     */
    private $apiSecret;
    
    /**
     * @var string $baseUrl - رابط API الأساسي
     */
    private $baseUrl = 'https://api.tripadvisor.com/v1';
    
    /**
     * @var int $timeout - مهلة الطلب
     */
    private $timeout = 30;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->apiKey = (getenv('TRIPADVISOR_API_KEY') ?: (isset($_ENV['TRIPADVISOR_API_KEY']) ? (string) $_ENV['TRIPADVISOR_API_KEY'] : false)) ?: '';
        $this->apiSecret = (getenv('TRIPADVISOR_API_SECRET') ?: (isset($_ENV['TRIPADVISOR_API_SECRET']) ? (string) $_ENV['TRIPADVISOR_API_SECRET'] : false)) ?: '';
    }
    
    /**
     * جلب المراجعات
     * @param array $params - معاملات الطلب
     * @return array
     */
    public function getReviews(array $params = []): array {
        try {
            $locationId = $params['location_id'] ?? null;
            if (!$locationId) {
                return [
                    'success' => false,
                    'error' => 'Location ID is required'
                ];
            }
            
            $endpoint = "/location/{$locationId}/reviews";
            $query = [
                'key' => $this->apiKey,
                'limit' => $params['limit'] ?? 20,
                'offset' => $params['offset'] ?? 0,
                'sort' => $params['sort'] ?? 'newest'
            ];
            
            if (isset($params['language'])) {
                $query['language'] = $params['language'];
            }
            
            $response = $this->makeRequest('GET', $endpoint, $query);
            
            if (!$response['success']) {
                return $response;
            }
            
            $reviews = $this->parseReviews($response['data']);
            
            return [
                'success' => true,
                'reviews' => $reviews,
                'total' => $response['data']['paging']['total_results'] ?? 0,
                'source' => 'tripadvisor'
            ];
            
        } catch (Exception $e) {
            Logger::error('TripAdvisor Get Reviews Error', [
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
            $endpoint = "/review/{$reviewId}/reply";
            
            $data = [
                'content' => $reply,
                'language' => 'ar'
            ];
            
            $response = $this->makeRequest('POST', $endpoint, [], $data);
            
            return [
                'success' => $response['success'],
                'review_id' => $reviewId,
                'reply_sent' => $response['success'],
                'message' => $response['success'] ? 'Reply sent successfully' : 'Failed to send reply'
            ];
            
        } catch (Exception $e) {
            Logger::error('TripAdvisor Send Reply Error', [
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
    public function getLocation(string $locationId): array {
        try {
            $endpoint = "/location/{$locationId}";
            $query = [
                'key' => $this->apiKey
            ];
            
            $response = $this->makeRequest('GET', $endpoint, $query);
            
            if (!$response['success']) {
                return $response;
            }
            
            return [
                'success' => true,
                'location' => [
                    'id' => $response['data']['location_id'] ?? null,
                    'name' => $response['data']['name'] ?? null,
                    'rating' => $response['data']['rating'] ?? null,
                    'review_count' => $response['data']['num_reviews'] ?? 0,
                    'address' => $response['data']['address'] ?? null,
                    'phone' => $response['data']['phone'] ?? null,
                    'website' => $response['data']['website'] ?? null
                ]
            ];
            
        } catch (Exception $e) {
            Logger::error('TripAdvisor Get Location Error', [
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
        
        if ($this->apiKey) {
            $headers[] = 'X-TripAdvisor-API-Key: ' . $this->apiKey;
        }
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Tourfecto/1.0'
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
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
                'error' => "TripAdvisor API Error ({$httpCode}): {$errorMessage}",
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
        
        $items = $data['data'] ?? [];
        foreach ($items as $item) {
            $reviews[] = [
                'id' => $item['id'] ?? null,
                'rating' => $item['rating'] ?? 0,
                'title' => $item['title'] ?? '',
                'text' => $item['text'] ?? '',
                'language' => $item['language'] ?? 'en',
                'date' => $item['published_date'] ?? null,
                'reviewer' => [
                    'name' => $item['user']['username'] ?? 'Guest',
                    'avatar' => $item['user']['avatar'] ?? null
                ],
                'response' => $item['response'] ?? null,
                'helpful_count' => $item['helpful_votes'] ?? 0
            ];
        }
        
        return $reviews;
    }
    
    /**
     * التحقق من صلاحية المفتاح
     * @return bool
     */
    public function validateApiKey(): bool {
        try {
            $response = $this->makeRequest('GET', '/location/1', ['key' => $this->apiKey]);
            return $response['success'];
            
        } catch (Exception $e) {
            return false;
        }
    }
}