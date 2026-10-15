<?php

/**
 * Tourfecto - بوابة جودة خطة SEO (حالات حقيقية من خطة tourfecto.com)
 * التشغيل: phpunit --configuration tests/phpunit.xml --filter SeoPlanQualityGateTest
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/SeoExpert/SeoPlanQualityGate.php';

final class SeoPlanQualityGateTest extends TestCase
{
    private function pages(): array
    {
        return [
            ['title' => 'Luxury Egypt Tours & Nile Cruises | Tourfecto Travel', 'description' => 'Book private and small-group Luxor, Aswan and Cairo tours and Nile cruises with licensed Egyptologist guides. Free cancellation, instant WhatsApp support.'],
            ['title' => 'All Tours in Egypt | Tourfecto Travel', 'description' => 'Browse the best All tours in Egypt with prices, itineraries and instant booking.'],
            ['title' => 'Day Tours Tours in Egypt | Tourfecto Travel', 'description' => 'Browse the best Day Tours tours in Egypt with prices, itineraries and instant booking.'],
            ['title' => 'Cairo Tours: Giza Pyramids, Sphinx and Museums | Tourfecto Travel', 'description' => 'Private Cairo tours covering the Giza Pyramids, the Sphinx, museums and Islamic Cairo.'],
        ];
    }

    public function testGoodMetadataIsNotRewritten(): void
    {
        $kept = SeoPlanQualityGate::filterChanges([
            ['page_index' => 0, 'field' => 'title', 'value' => 'Explore Luxor, Aswan, and Cairo with Tourfecto Travel'],
            ['page_index' => 3, 'field' => 'title', 'value' => 'Cairo Tours: Pyramids, Museums, and More'],
        ], $this->pages(), 'Tourfecto Travel');
        $this->assertSame([], $kept);
    }

    public function testRealProblemsAreFixedAndBrandKept(): void
    {
        $kept = SeoPlanQualityGate::filterChanges([
            ['page_index' => 2, 'field' => 'title', 'value' => "Day Tours: Explore Egypt's Wonders"],
            ['page_index' => 1, 'field' => 'description', 'value' => 'Discover all tours offered by Tourfecto Travel across Egypt, including day tours, Nile cruises, and excursions with licensed guides.'],
        ], $this->pages(), 'Tourfecto Travel');
        $this->assertCount(2, $kept);
        $this->assertSame("Day Tours: Explore Egypt's Wonders | Tourfecto Travel", $kept[0]['value']);
        $this->assertSame('repeated_word', $kept[0]['issue']);
        $this->assertSame('shared_template', $kept[1]['issue']);
    }

    public function testReplacementMustNotHaveTheSameProblem(): void
    {
        $kept = SeoPlanQualityGate::filterChanges([['page_index' => 2, 'field' => 'title', 'value' => 'Day Trips Trips']], $this->pages(), '');
        $this->assertSame([], $kept);
    }

    public function testGenericClientTasksAreDropped(): void
    {
        $kept = SeoPlanQualityGate::filterClientTasks([['title' => 'Conduct Keyword Research'], ['title' => 'Monitor Analytics'], ['title' => 'Add an H1 to /tours/cairo']]);
        $this->assertSame([['title' => 'Add an H1 to /tours/cairo']], $kept);
    }
}
