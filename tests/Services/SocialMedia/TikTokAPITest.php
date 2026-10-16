<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../app/Services/SocialMedia/TikTokAPI.php';

/** TikTok Content Posting API v2 (open.tiktokapis.com) */
class TikTokAPITest extends TestCase
{
    /** @param array<string,array> $routes path => [http_code, body] */
    private function api(array $routes, array &$calls = []): TikTokAPI
    {
        return new TikTokAPI('tok_1', 'open_1', function (array $req) use ($routes, &$calls) {
            $calls[] = $req;
            foreach ($routes as $path => [$code, $body]) {
                if (strpos($req['url'], $path) !== false) {
                    return ['http_code' => $code, 'body' => json_encode($body)];
                }
            }
            return ['http_code' => 404, 'body' => '{}'];
        });
    }

    private function ok(array $data): array
    {
        return [200, ['data' => $data, 'error' => ['code' => 'ok', 'message' => '']]];
    }

    public function testPublishUsesPublicPrivacyWhenAllowed(): void
    {
        $calls = [];
        $api = $this->api([
            'creator_info/query' => $this->ok(['privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY']]),
            'video/init' => $this->ok(['publish_id' => 'pub_123']),
        ], $calls);
        $r = $api->publishVideo('https://tourfecto.pro/v.mp4', 'Luxor trip');
        $this->assertTrue($r['success']);
        $this->assertSame('pub_123', $r['publish_id']);
        $this->assertSame('PUBLIC_TO_EVERYONE', $r['privacy']);
        $body = json_decode($calls[1]['body'], true);
        $this->assertSame('PULL_FROM_URL', $body['source_info']['source']);
        $this->assertSame('https://tourfecto.pro/v.mp4', $body['source_info']['video_url']);
        $this->assertContains('Authorization: Bearer tok_1', $calls[1]['headers']);
    }

    public function testUnauditedAppFallsBackToPrivate(): void
    {
        $r = $this->api([
            'creator_info/query' => $this->ok(['privacy_level_options' => ['SELF_ONLY']]),
            'video/init' => $this->ok(['publish_id' => 'p2']),
        ])->publishVideo('https://x/v.mp4', 't');
        $this->assertSame('SELF_ONLY', $r['privacy']);
    }

    public function testApiErrorIsReadable(): void
    {
        $r = $this->api([
            'creator_info/query' => [401, ['data' => [], 'error' => ['code' => 'access_token_invalid', 'message' => 'invalid']]],
        ])->publishVideo('https://x/v.mp4', 't');
        $this->assertFalse($r['success']);
        $this->assertStringContainsString('اربط', $r['error']);
    }

    public function testStatusMapping(): void
    {
        foreach (['PROCESSING_DOWNLOAD' => 'PENDING', 'PUBLISH_COMPLETE' => 'PUBLISHED', 'FAILED' => 'FAILED'] as $raw => $mapped) {
            $r = $this->api(['status/fetch' => $this->ok(['status' => $raw, 'fail_reason' => $raw === 'FAILED' ? 'Policy violation' : null])])->checkPublishStatus('pub_1');
            $this->assertSame($mapped, $r['status']);
        }
    }

    public function testTokenExchangeAndRefresh(): void
    {
        $t = function (array $req) {
            parse_str((string) $req['body'], $form);
            $this->assertStringContainsString('/v2/oauth/token/', $req['url']);
            return $form['grant_type'] === 'refresh_token' && $form['refresh_token'] === 'bad'
                ? ['http_code' => 400, 'body' => json_encode(['error' => 'invalid_grant', 'error_description' => 'Refresh token is invalid'])]
                : ['http_code' => 200, 'body' => json_encode(['access_token' => 'at', 'refresh_token' => 'rt', 'open_id' => 'o', 'expires_in' => 86400, 'scope' => TikTokAPI::SCOPES])];
        };
        $ok = TikTokAPI::exchangeCode('ck', 'cs', 'code', 'https://tourfecto.pro/cb', $t);
        $this->assertTrue($ok['success']);
        $this->assertSame('at', $ok['access_token']);
        $bad = TikTokAPI::refresh('ck', 'cs', 'bad', $t);
        $this->assertFalse($bad['success']);
        $this->assertSame('Refresh token is invalid', $bad['error']);
    }
}
