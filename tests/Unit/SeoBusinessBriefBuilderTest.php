<?php

/**
 * Tourfecto - تعريف النشاط التلقائي من صفحات الموقع
 * التشغيل: phpunit --configuration tests/phpunit.xml --filter SeoBusinessBriefBuilderTest
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/SeoExpert/SeoBusinessBriefBuilder.php';

final class SeoBusinessBriefBuilderTest extends TestCase
{
    private function site(): array
    {
        $home = [
            'title' => 'Luxury Egypt Tours & Nile Cruises | Tourfecto Travel', 'site_name' => '', 'lang' => 'en',
            'description' => 'Private Luxor, Aswan and Cairo tours with licensed Egyptologist guides.',
            'links' => [
                ['title' => 'Blog', 'url' => 'https://t.com/blog'],
                ['title' => 'Nile Cruises', 'url' => 'https://t.com/tours/nile-cruise'],
                ['title' => 'About us', 'url' => 'https://t.com/about'],
                ['title' => 'Contact', 'url' => 'https://t.com/contact'],
            ],
            'schema' => [
                ['@type' => 'TravelAgency', 'name' => 'Tourfecto Travel', 'telephone' => '+201000000000',
                    'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Luxor', 'addressCountry' => 'EG']],
                ['@type' => 'FAQPage', 'mainEntity' => [['@type' => 'Question', 'name' => 'Is cancellation free?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Yes, up to 24 hours before.']]]],
            ],
            'contacts' => ['phones' => ['+201000000000'], 'emails' => ['info@t.com'], 'whatsapp' => ['+201000000000']],
            'social' => ['https://facebook.com/tourfecto'], 'text' => 'Book now your Luxor day tour.',
        ];
        $tour = ['h1' => 'Luxor & Aswan 4-Day Nile Cruise', 'lang' => 'en', 'schema' => [['@type' => 'TouristTrip', 'name' => 'Karnak Temple Tour', 'offers' => ['@type' => 'Offer', 'price' => '45', 'priceCurrency' => 'USD']], ['@type' => 'TouristDestination', 'name' => 'Luxor']]];
        return [$home, $tour];
    }

    public function testImportantPagesComeFirst(): void
    {
        $ranked = SeoBusinessBriefBuilder::rankLinks($this->site()[0]['links']);
        $this->assertSame('https://t.com/about', $ranked[0]);
        $this->assertSame('https://t.com/blog', end($ranked));
    }

    public function testBuildsStructuredFactsFromRealPageData(): void
    {
        $b = SeoBusinessBriefBuilder::build($this->site(), 'fallback', false);
        $this->assertSame('Tourfecto Travel', $b['business_name']);
        $this->assertStringContainsString('About: Private Luxor, Aswan and Cairo tours', $b['business_facts']);
        $this->assertStringContainsString('Karnak Temple Tour', $b['business_facts']);
        $this->assertStringContainsString('Luxor & Aswan 4-Day Nile Cruise', $b['business_facts']);
        $this->assertStringContainsString('Destinations: Luxor', $b['business_facts']);
        $this->assertStringNotContainsString('Address EG', $b['business_facts']);
        $this->assertStringContainsString('WhatsApp +201000000000', $b['business_facts']);
        $this->assertStringContainsString('info@t.com', $b['business_facts']);
        $this->assertStringContainsString('Is cancellation free? — Yes, up to 24 hours before.', $b['business_facts']);
        $this->assertStringContainsString('English', $b['audience']);
        $this->assertStringContainsString('direct bookings', $b['goal']);
        $this->assertSame('+201000000000', $b['phone']);
        $this->assertSame('info@t.com', $b['email']);
        $this->assertSame('https://wa.me/201000000000', $b['cta']);
        $this->assertSame('Karnak Temple Tour — 45 USD', $b['offers']);
        $this->assertStringContainsString('WhatsApp', $b['goal']);
    }

    public function testRealSiteShape(): void
    {
        // نفس شكل tourfecto.com الحقيقي: صفحات أقسام + زراير بحث
        $home = ['title' => 'Tourfecto Travel', 'lang' => 'en', 'description' => 'Egypt tour operator.',
            'links' => [['title' => 'Luxor Tours', 'url' => 'https://t.com/tours/luxor'], ['title' => 'Aswan Tours', 'url' => 'https://t.com/tours/aswan'],
                ['title' => 'Find my tour', 'url' => 'https://t.com/quiz'], ['title' => 'Nile Cruises', 'url' => 'https://t.com/tours/nile-cruises']]];
        $luxor = ['h1' => 'Luxor Tours', 'final_url' => 'https://t.com/tours/luxor',
            'headings' => ['Luxor East & West Bank Full-Day Tour', 'Hot Air Balloon Ride over Luxor', 'Why book with us?', 'Guest reviews']];
        $all = ['h1' => 'All Tours', 'final_url' => 'https://t.com/tours'];
        $quiz = ['h1' => 'Find Your Perfect Tour', 'final_url' => 'https://t.com/quiz'];
        $b = SeoBusinessBriefBuilder::build([$home, $luxor, $all, $quiz], 'x', false);
        $this->assertStringContainsString('Services: Luxor Tours', $b['business_facts']);
        $this->assertStringNotContainsString('All Tours', $b['business_facts']);
        $this->assertStringNotContainsString('Find', $b['business_facts']);
        $this->assertStringContainsString('Tours listed on the site: Luxor East & West Bank Full-Day Tour, Hot Air Balloon Ride over Luxor', $b['business_facts']);
        $this->assertStringNotContainsString('Why book', $b['business_facts']);
        $this->assertStringContainsString('Destinations: Luxor, Aswan', $b['business_facts']);
        $this->assertTrue(strpos($b['business_facts'], 'Destinations:') < strpos($b['business_facts'], 'Tours listed'));
        $this->assertStringNotContainsString('Destinations: Luxor, Aswan, Nile', $b['business_facts']);
    }

    public function testArabicLabels(): void
    {
        $b = SeoBusinessBriefBuilder::build($this->site(), 'fallback', true);
        $this->assertStringContainsString('الخدمات: ', $b['business_facts']);
        $this->assertStringContainsString('الإنجليزي', $b['audience']);
    }

    public function testFallbackNameAndTextWhenNoStructuredData(): void
    {
        $b = SeoBusinessBriefBuilder::build([['title' => '', 'text' => 'We run desert safaris in Hurghada.']], 'My Co', false);
        $this->assertSame('My Co', $b['business_name']);
        $this->assertStringContainsString('desert safaris', $b['business_facts']);
        $this->assertStringContainsString('inquiries', $b['goal']);
    }
}
