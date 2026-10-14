<?php

/**
 * Tourfecto - SEO Report Normalizer Unit Test
 * الذكاء الاصطناعي بيرجّع أسماء حقول بديلة - التقرير لازم يتملي منها.
 *
 * التشغيل: phpunit --configuration tests/phpunit.xml --filter SeoReportNormalizerTest
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../app/Services/AI/SeoReportNormalizer.php';

final class SeoReportNormalizerTest extends TestCase
{
    private function aiResponse(): array
    {
        // نفس الشكل اللي رجّعه Gemini فعلًا في تقرير حقيقي (أكتوبر 2026)
        return [
            'seo' => [
                'keywords' => [],
                'target_keywords' => [['keyword' => 'Egypt Tours', 'intent' => 'Commercial', 'priority' => 'High']],
                'meta_description_suggestions' => ['Discover Egypt tours.'],
            ],
            'aeo' => [
                'direct_answers' => [],
                'direct_answer_blocks' => [['question' => 'Best time?', 'direct_answer' => 'October to April.']],
                'trust_signals_action_plan' => ['Show verified reviews.'],
                'entity_positioning_strategy' => 'Be a TravelAgency entity.',
            ],
            'geo' => [
                'generated_faqs' => [['query' => 'How many days?', 'answer' => '7 to 10.']],
                'maps_integration_recommendations' => ['Embed itinerary maps.'],
                'local_optimization_proposals' => ['City hub pages.', 'Local distances.'],
            ],
        ];
    }

    public function testAliasesFillCanonicalFieldsAsReadableText(): void
    {
        $n = SeoReportNormalizer::normalize($this->aiResponse());
        $this->assertSame(['Egypt Tours (Commercial, High)'], $n['seo']['keywords']);
        $this->assertSame(['Discover Egypt tours.'], $n['seo']['meta_suggestions']);
        $this->assertSame(['Best time? — October to April.'], $n['aeo']['direct_answers']);
        $this->assertSame(['Show verified reviews.'], $n['aeo']['trust_signals']);
        $this->assertSame('Be a TravelAgency entity.', $n['aeo']['positioning_strategy']);
        $this->assertSame(['How many days? — 7 to 10.'], $n['geo']['questions_generated']);
        $this->assertSame(['Embed itinerary maps.'], $n['geo']['map_integration']);
        $this->assertSame("City hub pages.\nLocal distances.", $n['geo']['improvement_suggestions']);
    }

    public function testCanonicalValuesWin(): void
    {
        $n = SeoReportNormalizer::normalize(['seo' => ['keywords' => ['a'], 'target_keywords' => ['b']]]);
        $this->assertSame(['a'], $n['seo']['keywords']);
    }

    public function testOldStoredReportRowIsFilledFromFullJson(): void
    {
        $row = ['seo_keywords' => [], 'aeo_direct_answers' => [], 'full_report_json' => json_encode($this->aiResponse())];
        $filled = SeoReportNormalizer::fillReportRow($row);
        $this->assertSame(['Egypt Tours (Commercial, High)'], $filled['seo_keywords']);
        $this->assertSame(['Best time? — October to April.'], $filled['aeo_direct_answers']);
        $this->assertSame(1, $filled['keywords_found']);
    }
}
