<?php

/**
 * Tourfecto - Content Formatter
 * تحويل مبسّط من Markdown (صيغة توليد المقالات) لـ HTML صالح للنشر.
 * @version 1.0.0
 */
class ContentFormatter
{
    public static function markdownToHtml(string $markdown): string
    {
        $escaped = htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8');

        $escaped = preg_replace('/^### (.*)$/m', '<h4>$1</h4>', $escaped);
        $escaped = preg_replace('/^## (.*)$/m', '<h3>$1</h3>', $escaped);
        $escaped = preg_replace('/^# (.*)$/m', '<h2>$1</h2>', $escaped);
        $escaped = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $escaped);

        $blocks = preg_split('/\n{2,}/', $escaped);
        $html = array_map(function ($block) {
            $block = trim($block);
            if ($block === '') {
                return '';
            }
            if (strpos($block, '<h') === 0) {
                return $block;
            }
            return '<p>' . nl2br($block) . '</p>';
        }, $blocks);

        return implode("\n", array_filter($html));
    }
}
