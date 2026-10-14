<?php

/**
 * Tourfecto - Video Script Service (Creative Studio)
 * توليد سكربتات فيديو قصيرة بالذكاء الاصطناعي - يعيد استخدام GeminiClient
 * الموحّد (نفس محرك مقالات SEO والشات) بدل عميل AI منفصل.
 * @version 1.0.0
 */
class VideoScriptService
{
    /** @var GeminiClient */
    private $ai;

    public function __construct(?GeminiClient $ai = null)
    {
        $this->ai = $ai ?? new GeminiClient();
    }

    public function generate(int $userId, string $topic, string $platform, int $durationSeconds = 30, string $language = 'en'): VideoScript
    {
        $script = new VideoScript([
            'user_id' => $userId,
            'topic' => $topic,
            'platform' => $platform,
            'duration_seconds' => $durationSeconds,
            'status' => 'generating',
        ]);
        $script->save();

        $languageName = $language === 'ar' ? 'العربية' : 'English';
        $prompt = <<<PROMPT
اكتب سكربت فيديو قصير مدته {$durationSeconds} ثانية لمنصة {$platform} عن: "{$topic}".
اللغة: {$languageName}.
رجّع الرد بصيغة JSON فقط بالشكل التالي:
{"script_text": "النص الكامل المروي", "scenes": [{"time":"0-3s","visual":"وصف المشهد","voiceover":"الجملة المنطوقة"}]}
PROMPT;

        $response = $this->ai->generateContent($prompt, ['task'=>'video_script', 'user_id'=>$userId, 'output_language'=>$language, 'maxOutputTokens' => 8192, 'responseMimeType' => 'application/json']);

        if (!($response['success'] ?? false)) {
            $script->setAttribute('status', 'failed');
            $script->save();
            throw new Exception($response['error'] ?? 'فشل الاتصال بمحرك الذكاء الاصطناعي');
        }

        $raw = preg_replace('/^```(json)?|```$/m', '', trim((string) ($response['data'] ?? '')));
        $parsed = json_decode(trim($raw), true);

        if (!is_array($parsed) || empty($parsed['script_text'])) {
            $script->setAttribute('status', 'failed');
            $script->save();
            throw new Exception('تعذّر تحليل رد الذكاء الاصطناعي إلى سكربت منظّم');
        }

        $script->setAttribute('script_text', $parsed['script_text']);
        $script->setAttribute('scenes', json_encode($parsed['scenes'] ?? [], JSON_UNESCAPED_UNICODE));
        $script->setAttribute('status', 'completed');
        $script->save();

        ActivityLog::record('creative_studio', 'video_script.generated', [
            'user_id' => $userId, 'subject_type' => 'video_scripts', 'subject_id' => (int) $script->getAttribute('id'),
        ]);

        return $script;
    }
}
