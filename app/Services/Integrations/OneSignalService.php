<?php

/**
 * Tourfecto - OneSignal Integration
 * @version 1.0.0
 *
 * إرسال إشعارات Push (Web + Mobile) لمستخدمي موقعك.
 */

class OneSignalService extends BaseIntegrationService
{
    public function key(): string
    {
        return 'onesignal';
    }

    public function isConfigured(): bool
    {
        return $this->conf('ONESIGNAL_APP_ID', 'ONESIGNAL_APP_ID') !== ''
            && $this->conf('ONESIGNAL_REST_API_KEY', 'ONESIGNAL_REST_API_KEY') !== '';
    }

    /**
     * إرسال إشعار.
     * @param array $params headings, contents, included_segments, include_player_ids, url, data...
     */
    public function sendNotification(array $params = []): array
    {
        $appId = $this->conf('ONESIGNAL_APP_ID', 'ONESIGNAL_APP_ID');
        $body = array_merge([
            'app_id'            => $appId,
            'included_segments' => ['Subscribed Users'],
        ], $params);

        $result = $this->httpJson('POST', 'https://onesignal.com/api/v1/notifications', [
            'Authorization: Basic ' . $this->conf('ONESIGNAL_REST_API_KEY', 'ONESIGNAL_REST_API_KEY'),
        ], $body);

        if ($result['success'] && isset($result['data']['id'])) {
            return $result;
        }

        return $result;
    }

    public function request(string $action, array $params = [], array $context = []): array
    {
        switch ($action) {
            case 'send_notification':
                return $this->sendNotification($params);
            case 'test':
                return $this->httpJson('GET', 'https://onesignal.com/api/v1/apps/' . rawurlencode($this->conf('ONESIGNAL_APP_ID', 'ONESIGNAL_APP_ID')), [
                    'Authorization: Basic ' . $this->conf('ONESIGNAL_REST_API_KEY', 'ONESIGNAL_REST_API_KEY'),
                ]);
            default:
                return ['success' => false, 'data' => null, 'error' => "action '{$action}' غير مدعوم في OneSignalService", 'http_code' => 0];
        }
    }
}
