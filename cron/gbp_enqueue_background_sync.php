<?php

/**
 * Tourfecto - GBP Background Sync (Queue-based)
 * @version 1.0.0
 * @since 2026-08-10 (GBP Module Upgrade - Round 5)
 *
 * ده Cron إضافي اختياري، مش تعديل على cron/sync_google_reviews.php
 * الموجود (اللي فضل زي ما هو تمامًا، شغال بنفس الطريقة القديمة). الفرق
 * هنا: بدل ما المزامنة تتنفذ Synchronous جوه عملية الـ Cron نفسها لكل
 * الاتصالات ورا بعض (ممكن تاخد وقت طويل مع عدد كبير من الحسابات)،
 * بيبعت (enqueue) مهمة GbpBackgroundSyncJob منفصلة لكل اتصال في نظام
 * الطابور (Queue) الموجود فعلاً في المشروع - وworker الطابور
 * (cron/process_queue.php) هو اللي بينفذهم فعليًا، عادةً كل دقيقة.
 * ده اللي مقصود بيه "Background Sync" في السبيك (بند 16)، منفصل عن
 * "Manual Sync" اللي بيحصل فورًا لما المستخدم يضغط زرار "مزامنة الآن".
 *
 * إعداد Cron Job (اختياري - لو عايز Background Sync حقيقي عبر الطابور):
 * Common Settings: Every 6 hours (0 star-slash-6 * * *)
 * Command:
 *   php /home/USERNAME/domains/YOURSITE.com/cron/gbp_enqueue_background_sync.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/gbp_background_sync.log 2>&1
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

try {
    $connections = (new PlatformConnection())->where([
        'platform' => 'google_business',
        'status' => 'connected',
    ]);

    $enqueued = 0;
    $skipped = 0;

    foreach ($connections as $connection) {
        $websiteId = (int) $connection->getAttribute('website_id');
        $userId = (int) $connection->getAttribute('user_id');

        if (!$websiteId || !$userId) {
            $skipped++;
            continue;
        }

        $result = enqueue('GbpBackgroundSyncJob', ['website_id' => $websiteId, 'user_id' => $userId], 'gbp_sync');
        if ($result) {
            $enqueued++;
        } else {
            $skipped++;
        }
    }

    $durationMs = round((microtime(true) - $startedAt) * 1000);

    fwrite(STDOUT, sprintf(
        "[%s] GBP Background Sync Enqueue: %d اتصال اتبعت للطابور، %d اتخطى (%dms)\n",
        date('Y-m-d H:i:s'),
        $enqueued,
        $skipped,
        $durationMs
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] GBP background sync enqueue error: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('Cron GBP background sync enqueue failed', ['error' => $e->getMessage()]);
    }
    exit(1);
}
