<?php

/**
 * Tourfecto - Send Email Campaign Batch Job
 * @version 1.0.0
 *
 * يرسل دفعة (حتى 100 مستلم) من حملة تسويق بريدي عبر EmailCampaignService::sendBatch().
 * لو فاضل مستلمين pending بعد الدفعة دي، يعيد جدولة نفسه في الطابور عشان
 * الـ cron اللي بيتشغل كل دقيقة يكمّل. لو فشل كله، يُحتسب فشل ويُعاد
 * المحاولة تلقائيًا (QueueManager backoff).
 *
 * الـ cron:  cron/process_queue.php  (يستدعي QueueManager::processDue)
 */
class SendEmailCampaignBatchJob implements QueueJobInterface
{
    public function handle(array $payload): void
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        $campaignId = (int) ($payload['campaign_id'] ?? 0);

        if ($userId <= 0 || $campaignId <= 0) {
            throw new Exception('SendEmailCampaignBatchJob: payload ناقص (user_id/campaign_id)');
        }

        $service = new EmailCampaignService();
        $result = $service->sendBatch($userId, $campaignId);

        // لو لسه فيه مستلمين - نعيد الجدولة لدفعة تانية (المهمة الحالية اكتملت)
        if (!empty($result['remaining']) && class_exists('QueueManager')) {
            $queue = new QueueManager();
            $queue->push(SendEmailCampaignBatchJob::class, [
                'user_id' => $userId,
                'campaign_id' => $campaignId,
            ], 'email', 1);
        }
    }
}
