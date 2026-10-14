<?php

/**
 * Tourfecto - Connection Guide Unit Test
 * دليل الربط في صفحة "الربط والتكاملات" - منطق خالص offline.
 *
 * التشغيل: phpunit --configuration tests/phpunit.xml --filter ConnectionGuideTest
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/Integrations/ConnectionGuide.php';

final class ConnectionGuideTest extends TestCase
{
    public function testEveryGuideHasStepsAndAutoActions(): void
    {
        foreach (ConnectionGuide::all() as $key => $guide) {
            $this->assertNotEmpty($guide['steps'], "{$key} بدون خطوات ربط");
            $this->assertNotEmpty($guide['auto'], "{$key} مش موضح المنصة هتعمل إيه");
            $this->assertNotEmpty($guide['requires'], "{$key} بدون مفاتيح منصة مطلوبة");
        }
    }

    public function testMissingKeysWhenNothingConfigured(): void
    {
        $missing = ConnectionGuide::missingKeys('meta_ads', fn (string $k) => '');
        $this->assertSame(['META_APP_ID', 'META_APP_SECRET'], $missing);
    }

    public function testPlaceholderValuesCountAsMissing(): void
    {
        $values = ['META_APP_ID' => '123', 'META_APP_SECRET' => 'your-secret'];
        $missing = ConnectionGuide::missingKeys('meta_ads', fn (string $k) => $values[$k] ?? '');
        $this->assertSame(['META_APP_SECRET'], $missing);
    }

    public function testReadyWhenAllKeysPresent(): void
    {
        $this->assertSame([], ConnectionGuide::missingKeys('google_ads', fn (string $k) => 'set'));
    }

    public function testUnknownPlatformHasNoGuide(): void
    {
        $this->assertNull(ConnectionGuide::get('nope'));
        $this->assertSame('', ConnectionGuide::renderHtml('nope'));
        $this->assertSame([], ConnectionGuide::missingKeys('nope', fn (string $k) => ''));
    }

    public function testTripAdvisorExplainsManualReply(): void
    {
        $html = ConnectionGuide::renderHtml('tripadvisor');
        $this->assertStringContainsString('حاجات لازم تعملها بنفسك', $html);
        $this->assertStringContainsString('<ol', $html);
    }
}
