<?php

/**
 * Tourfecto - Brand Prompt Builder
 * بيبني برومبت الصورة النهائي من: فكرة العميل + هوية العلامة + دروس التعلّم من تقييمات سابقة.
 * @version 1.0.0
 */
class BrandPromptBuilder
{
    public static function profileData(BrandProfile $bp): array
    {
        $d = json_decode((string) $bp->getAttribute('profile_json'), true);
        return is_array($d) ? $d : [];
    }

    public static function palette(BrandProfile $bp): array
    {
        $p = json_decode((string) $bp->getAttribute('palette_json'), true);
        return is_array($p) ? $p : [];
    }

    /** أحدث/أثقل الدروس: ['do'=>[], 'avoid'=>[]] */
    public static function learnings(int $brandId, int $limit = 5): array
    {
        $out = ['do' => [], 'avoid' => []];
        try {
            $rows = Database::getInstance()->query(
                'SELECT kind, lesson FROM brand_learnings WHERE brand_profile_id = :b ORDER BY weight DESC, id DESC LIMIT 40',
                [':b' => $brandId]
            );
            foreach ((array) $rows as $r) {
                if (count($out[$r['kind']]) < $limit) {
                    $out[$r['kind']][] = $r['lesson'];
                }
            }
        } catch (Throwable $e) {
            // التعلّم مش لازم يكسر التوليد
        }
        return $out;
    }

    public static function build(string $idea, string $style, ?BrandProfile $bp, string $aspectRatio): string
    {
        $base = trim($idea);
        $styleLine = MediaGenerationService::stylePresets()[$style] ?? MediaGenerationService::stylePresets()['photo'];
        if (!$bp) {
            return $base . '. Style: ' . $styleLine . '.';
        }
        $d = self::profileData($bp);
        $pal = self::palette($bp);
        $learn = self::learnings((int) $bp->getAttribute('id'));

        $parts = [$base . '.'];
        $parts[] = 'Brand: ' . ($bp->getAttribute('brand_name') ?: '') . ' (' . ($d['business_type'] ?? 'business') . ').';
        if (!in_array($style, ['photo', 'product'], true) && !empty($d['visual_style'])) {
            $parts[] = 'Art direction: ' . $d['visual_style'] . '.';
        }
        if (!empty($d['mood_keywords']) && is_array($d['mood_keywords'])) {
            $parts[] = 'Mood: ' . implode(', ', array_slice($d['mood_keywords'], 0, 6)) . '.';
        }
        $colors = array_filter([$pal['primary'] ?? '', $pal['secondary'] ?? '', $pal['accent'] ?? '']);
        if ($colors) {
            $parts[] = 'Color grading should harmonize with the brand palette ' . implode(', ', $colors) . ' (use as subtle accents in props, wardrobe, light or environment; keep natural realism).';
        }
        $parts[] = 'Photographic style: ' . $styleLine . '.';
        if(in_array($style,['photo','cinematic','product','luxury'],true))$parts[] = 'Technical look: photorealistic editorial travel/lifestyle photograph, shot on a full-frame camera with a prime lens, natural light, rich but natural color, crisp detail, authentic textures, believable proportions and anatomy; people look candid and natural; preserve the requested view and pose. Brand accents must not change the natural colors of skin, water or landmarks. A real destination must not be represented with invented landmark details; use a supplied reference for precise location fidelity.';
        $parts[] = 'Composition for ' . $aspectRatio . ': one clear focal subject, strong depth, uncluttered, with calm negative space near the bottom edge for a logo overlay.';
        foreach ((array) ($d['do'] ?? []) as $x) {
            $parts[] = 'Do: ' . $x . '.';
        }
        foreach ($learn['do'] as $x) {
            $parts[] = 'Client liked: ' . $x . '.';
        }
        $avoid = array_merge(['any text, letters, watermarks, logos or captions inside the image', 'distorted hands or faces', 'generic stock-photo look', 'unintentional oversaturation'], (array) ($d['avoid'] ?? []), $learn['avoid']);
        $parts[] = 'Avoid: ' . implode('; ', array_slice($avoid, 0, 10)) . '.';
        return implode(' ', $parts);
    }
}
