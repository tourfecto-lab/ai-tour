<?php

/**
 * Tourfecto - صورة حقيقية للمقال من صفحات الموقع
 * التشغيل: phpunit --configuration tests/phpunit.xml --filter ArticleImagePickerTest
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/SeoExpert/ArticleImagePicker.php';

final class ArticleImagePickerTest extends TestCase
{
    public function testPicksMostRelevantPageAndHomeLast(): void
    {
        $pages = [
            ['url' => 'https://t.com/', 'title' => 'Egypt Tours | T'],
            ['url' => 'https://t.com/tours/cairo', 'title' => 'Cairo Tours: Giza Pyramids'],
            ['url' => 'https://t.com/tour/luxor-hot-air-balloon-sunrise-ride', 'title' => 'Luxor Hot Air Balloon Sunrise Ride'],
        ];
        $ranked = ArticleImagePicker::rankPages('A Guide to Luxor Hot Air Balloon Rides', 'Sunrise balloon over Luxor', $pages);
        $this->assertSame('https://t.com/tour/luxor-hot-air-balloon-sunrise-ride', $ranked[0]);
        $this->assertSame('https://t.com/', end($ranked));
    }

    public function testOgImageThenContentImageHttpsOnly(): void
    {
        $this->assertSame('https://t.com/og.jpg', ArticleImagePicker::fromContext(['og' => ['og:image' => 'https://t.com/og.jpg'], 'h1' => 'Luxor'])['src']);
        $img = ArticleImagePicker::fromContext(['og' => ['og:image' => 'http://t.com/og.jpg'], 'content_images' => [['src' => 'https://t.com/a.jpg', 'alt' => 'Karnak']]]);
        $this->assertSame(['src' => 'https://t.com/a.jpg', 'alt' => 'Karnak'], $img);
        $this->assertNull(ArticleImagePicker::fromContext(['content_images' => []]));
    }
}
