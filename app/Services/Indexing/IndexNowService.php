<?php

/**
 * Tourfecto - IndexNow Instant Indexing Service
 * @version 1.0.0
 *
 * فهرسة فورية لصفحات المواقع عند Bing/Yandex/Seznam/Naver عبر بروتوكول
 * IndexNow (indexnow.org) - بدل انتظار الزحف الطبيعي اللي بياخد أسابيع.
 * ده الجزء اللي بيحوّل التنفيذ التلقائي من "تحسين الكود" لـ "تحسين + إبلاغ
 * محركات البحث فورًا".
 *
 * الفكرة: بعد ما الـ AutoSEO يطبّق إصلاح، بنبعت الـ URLs المتأثرة لـ
 * IndexNow بكل محركات البحث المشاركة في البروتوكول بضغطة واحدة.
 */
class IndexNowService
{
    private const API_ENDPOINT = 'https://api.indexnow.org/indexnow';

    /** محركات البحث المشاركة في البروتوكول (اختياري للإرسال الفردي) */
    private const ENGINES = [
        'https://www.bing.com/indexnow',
        'https://yandex.com/indexnow',
        'https://www.seznam.cz/indexnow',
        'https://searchadvisor.naver.com/indexnow',
    ];

    private int $timeout = 15;

    /**
     * توليد مفتاح IndexNow صالح (8-128 حرف، أحرف سداسية عشرية + شرطات).
     */
    public function generateKey(): string
    {
        return strtolower(bin2hex(random_bytes(16)));
    }

    /**
     * إرسال قائمة URLs لفهرسة فورية.
     *
     * @param string $host الدومين (مثال: example.com - من غير بروتوكول)
     * @param string $key مفتاح IndexNow
     * @param string[] $urls روابط كاملة (https://...)
     * @param string|null $keyLocation رابط ملف المفتاح (اختياري - بيتحسب تلقائي)
     * @return array ['success'=>bool, 'status'=>?int, 'error'=>?string]
     */
    public function submitUrls(string $host, string $key, array $urls, ?string $keyLocation = null): array
    {
        $urls = array_values(array_filter($urls, fn ($u) => filter_var($u, FILTER_VALIDATE_URL)));
        if (empty($urls)) {
            return ['success' => false, 'error' => 'مفيش روابط صالحة للإرسال'];
        }

        $keyLocation = $keyLocation ?? "https://{$host}/{$key}.txt";

        $payload = [
            'host' => $host,
            'key' => $key,
            'keyLocation' => $keyLocation,
            'urlList' => $urls,
        ];

        $ch = curl_init(self::API_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            return ['success' => false, 'error' => 'cURL Error: ' . $curlError];
        }

        // IndexNow بيرجّع 200 (ok) / 202 (accepted) عند النجاح
        if ($status === 200 || $status === 202) {
            return ['success' => true, 'status' => $status, 'submitted' => count($urls)];
        }

        return ['success' => false, 'status' => $status, 'error' => 'IndexNow HTTP ' . $status . ': ' . substr($response, 0, 300)];
    }

    /**
     * إرسال رابط واحد.
     */
    public function submitSingle(string $host, string $key, string $url): array
    {
        return $this->submitUrls($host, $key, [$url]);
    }

    /**
     * إرسال لنفس الرابط عبر كل محركات البحث المشتركة في البروتوكول
     * (بعض المحركات بتفضل استقبال الإرسال على الـ endpoint الخاص بيها).
     */
    public function submitToAllEngines(string $host, string $key, array $urls): array
    {
        $results = [];
        foreach (self::ENGINES as $engine) {
            $results[$engine] = $this->postToEngine($engine, $host, $key, $urls);
        }
        return $results;
    }

    private function postToEngine(string $engine, string $host, string $key, array $urls): array
    {
        $payload = [
            'host' => $host,
            'key' => $key,
            'urlList' => $urls,
        ];

        $ch = curl_init($engine);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['success' => ($status === 200 || $status === 202), 'status' => $status, 'body' => substr((string) $response, 0, 200)];
    }
}
