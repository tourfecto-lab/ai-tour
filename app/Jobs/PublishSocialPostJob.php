<?php

/**
 * Tourfecto - Publish Social Post Job
 * تنفيذ فعلي لنشر هدف واحد (social_post_targets) على منصته عبر
 * platform_connections. يُنفَّذ بواسطة cron/process_queue.php.
 * @version 4.0.0
 */
require_once __DIR__.'/../Services/SocialMedia/SocialMonthlyPlanService.php';
class PublishSocialPostJob implements QueueJobInterface
{
    private const MAX_POLL_ATTEMPTS = 30;
    private const POLL_DELAY_SECONDS = 15;

    public function handle(array $payload): void
    {
        SocialMonthlyPlanService::guardTarget((int)($payload['social_post_target_id']??0),function()use($payload){$this->handleUnlocked($payload);});
    }

    private function handleUnlocked(array $payload): void
    {
        $key='social-target:'.(int)($payload['social_post_target_id']??0);
        if(!ExternalExecution::lock($key))return;
        try {
            $this->publish($payload);
        } finally {
            ExternalExecution::unlock($key);
            $tid = (int) ($payload['social_post_target_id'] ?? 0);
            $row = $tid ? Database::getInstance()->query('SELECT social_post_id FROM social_post_targets WHERE id = ?', [$tid]) : [];
            if ($row) {
                SocialPostService::refreshPostStatus((int) $row[0]['social_post_id']);
            }
        }
    }

    private function publish(array $payload): void
    {
        $targetId = (int) ($payload['social_post_target_id'] ?? 0);
        if (!$targetId) {
            throw new Exception('social_post_target_id مفقود في payload');
        }

        $target = (new SocialPostTarget())->find($targetId);
        if (!$target) {
            throw new Exception("SocialPostTarget #{$targetId} غير موجود");
        }

        if(in_array($target->getAttribute('status'),['published','uncertain'],true))return;
        $post = (new SocialPost())->find((int) $target->getAttribute('social_post_id'));
        $connection = (new PlatformConnection())->find((int) $target->getAttribute('platform_connection_id'));

        if (!$post || !$connection) {
            $target->setAttribute('status', 'failed');
            $target->setAttribute('last_error', 'المنشور أو اتصال المنصة غير موجود');
            if(!$target->save())throw new RuntimeException('Target persistence failed');
            return;
        }

        if ($connection->getAttribute('status') !== 'connected') {
            $target->setAttribute('status', 'failed');
            $target->setAttribute('last_error', 'اتصال المنصة غير فعّال (status != connected) - يحتاج إعادة ربط OAuth');
            if(!$target->save())throw new RuntimeException('Target persistence failed');
            return;
        }

        $platform = (string) $connection->getAttribute('platform');

        if (!in_array($platform, ['facebook', 'instagram', 'tiktok', 'youtube'], true)) {
            $target->setAttribute('status', 'failed');
            $target->setAttribute('last_error', "النشر الفعلي على منصة {$platform} لسه مش متاح (المنصات المدعومة: فيسبوك، انستجرام، تيك توك، يوتيوب).");
            if(!$target->save())throw new RuntimeException('Target persistence failed');
            return;
        }

        try {
            $encryption = new Encryption();
            $message = (string) $post->getAttribute('content');
            $hashtags = (string) $post->getAttribute('hashtags');
            $decoded=json_decode($hashtags,true);if(is_array($decoded))$hashtags=implode(' ',array_map(fn($h)=>'#'.ltrim(trim((string)$h),'#'),$decoded));
            if ($hashtags) {
                $message .= "\n\n" . $hashtags;
            }

            $media = $this->resolveMedia((int) $post->getAttribute('media_item_id'));
            if($media&&(int)$media->getAttribute('user_id')!==(int)$post->getAttribute('user_id'))throw new RuntimeException('Media ownership mismatch');
            if($post->getAttribute('media_item_id')&&!$media)throw new RuntimeException('Publish media unavailable');
            $mediaUrl = $media && $platform !== 'youtube' ? (PrivateMedia::valid((string)$media->getAttribute('file_path'))?PrivateMedia::publishUrl($media):$this->toPublicUrl((string)$media->getAttribute('file_path'))) : null;
            $isVideo = $media && $media->getAttribute('type') === 'short_video';

            if ((in_array($platform, ['instagram', 'tiktok'], true) && !$mediaUrl) || ($platform === 'youtube' && !$media)) {
                $this->fail($target, "{$platform} محتاج صورة أو فيديو إجباريًا - المنشور ده مفيهوش وسائط. ارفع صورة أو فيديو من فورم المنشور، أو اختار واحدة من Creative Studio.");
                return;
            }

            if ($platform === 'facebook') {
                $pageAccessToken = $encryption->decrypt((string) $connection->getAttribute('access_token'));
                $api = new MetaSocialAPI($pageAccessToken);
                $pageId = (string) $connection->getAttribute('external_location_id');

                if ($isVideo) {
                    // الفيديو القصير بيتنشر كـ Reel. لو فيسبوك رفضه (مقاس/مدة مش مناسبين للريلز) نرجع لفيديو عادي بدل ما يفشل خالص
                    $result = ExternalExecution::run('social:'.$target->getAttribute('id').':step0',(int)$post->getAttribute('user_id'),fn()=>$api->publishReelToFacebookPage($pageId, $pageAccessToken, $message, $mediaUrl));
                    if (!$result['success'] && ($result['outcome_state']??'uncertain')==='failed') {
                        Logger::warning('Facebook reel failed - falling back to regular video', ['target_id' => $targetId, 'step' => $result['step'] ?? '', 'error' => $result['error'] ?? '']);
                        $result = ExternalExecution::run('social:'.$target->getAttribute('id').':step1',(int)$post->getAttribute('user_id'),fn()=>$api->publishVideoToFacebookPage($pageId, $pageAccessToken, $message, $mediaUrl));
                    }
                } else {
                    $result = ExternalExecution::run('social:'.$target->getAttribute('id').':step2',(int)$post->getAttribute('user_id'),fn()=>$api->publishToFacebookPage($pageId, $pageAccessToken, $message, $mediaUrl));
                }

                $this->finishOrFail($target, $post, $platform, $result);
                return;
            }

            if ($platform === 'instagram') {
                $pageAccessToken = $encryption->decrypt((string) $connection->getAttribute('access_token'));
                $api = new MetaSocialAPI($pageAccessToken);
                $igUserId = (string) $connection->getAttribute('external_location_id');

                if ($isVideo) {
                    $this->handleInstagramVideo($target, $post, $api, $igUserId, $pageAccessToken, $mediaUrl, $message);
                } else {
                    $result = ExternalExecution::run('social:'.$target->getAttribute('id').':step3',(int)$post->getAttribute('user_id'),fn()=>$api->publishToInstagram($igUserId, $pageAccessToken, $mediaUrl, $message));
                    $this->finishOrFail($target, $post, $platform, $result);
                }
                return;
            }

            if ($platform === 'tiktok') {
                // توكن تيك توك بيخلص كل 24 ساعة: بيتجدد هنا قبل النشر
                $api = TikTokAPI::fromConnection($connection, $encryption);

                if ($isVideo) {
                    $this->handleTikTokVideo($target, $post, $api, $mediaUrl, $message);
                } else {
                    $this->fail($target, 'TikTok يدعم نشر الفيديوهات فقط حاليًا.');
                }
                return;
            }

            if ($platform === 'youtube') {
                $accessToken = $encryption->decrypt((string) $connection->getAttribute('access_token'));
                $api = new YouTubeAPI($accessToken);

                if ($isVideo) {
                    $this->handleYouTubeVideo($target, $post, $api, $media, $message);
                } else {
                    $this->fail($target, 'YouTube Shorts يدعم نشر الفيديوهات فقط.');
                }
                return;
            }

        } catch (Throwable $e) {
            if($target->getAttribute('status')==='published')throw $e;
            $target->setAttribute('status', 'uncertain');
            $target->setAttribute('last_error', self::friendlyError($e->getMessage()));
            if(!$target->save())throw new RuntimeException('Target persistence failed');

            Logger::error('Social Post Publish Exception', [
                'target_id' => $targetId,
                'message'   => $e->getMessage(),
            ]);
        }
    }

    // --- Instagram ---
    private function handleInstagramVideo(SocialPostTarget $target, SocialPost $post, MetaSocialAPI $api, string $igUserId, string $pageAccessToken, string $videoUrl, string $caption): void
    {
        $containerId = (string) ($target->getAttribute('provider_ref') ?: '');

        if ($containerId === '') {
            $result = ExternalExecution::run('social:'.$target->getAttribute('id').':step4',(int)$post->getAttribute('user_id'),fn()=>$api->createInstagramVideoContainer($igUserId, $pageAccessToken, $videoUrl, $caption));
            if (!$result['success']) {
                $this->fail($target, $result['error'] ?? 'تعذر إنشاء container فيديو انستجرام',($result['outcome_state']??'uncertain')==='uncertain');
                return;
            }

            $target->setAttribute('provider_ref', $result['container_id']);
            $target->setAttribute('status', 'publishing');
            if(!$target->save())throw new RuntimeException('Target persistence failed');

            $this->requeue((int) $target->getAttribute('id'));
            return;
        }

        $attempts = (int) $target->getAttribute('poll_attempts');
        if ($attempts >= self::MAX_POLL_ATTEMPTS) {
            $this->fail($target, 'انتهت مهلة معالجة فيديو انستجرام - حاول مرة أخرى');
            return;
        }

        $status = $api->checkInstagramContainerStatus($containerId, $pageAccessToken);
        if (!$status['success']) {
            $this->fail($target, $status['error'] ?? 'تعذر فحص حالة معالجة الفيديو');
            return;
        }

        if ($status['status'] === 'ERROR' || $status['status'] === 'EXPIRED') {
            $this->fail($target, 'فشلت معالجة الفيديو في انستجرام (status: ' . $status['status'] . ')');
            return;
        }

        if ($status['status'] !== 'FINISHED') {
            $target->setAttribute('poll_attempts', $attempts + 1);
            if(!$target->save())throw new RuntimeException('Target persistence failed');
            $this->requeue((int) $target->getAttribute('id'));
            return;
        }

        $publishResult = ExternalExecution::run('social:'.$target->getAttribute('id').':step5',(int)$post->getAttribute('user_id'),fn()=>$api->publishInstagramContainer($igUserId, $pageAccessToken, $containerId));
        $this->finishOrFail($target, $post, 'instagram', ['success' => $publishResult['success'], 'post_id' => $publishResult['post_id'] ?? null, 'error' => $publishResult['error'] ?? null,'outcome_state'=>$publishResult['outcome_state']??'uncertain']);
    }

    // --- TikTok ---
    private function handleTikTokVideo(SocialPostTarget $target, SocialPost $post, TikTokAPI $api, string $videoUrl, string $title): void
    {
        $publishId = (string) ($target->getAttribute('provider_ref') ?: '');

        if ($publishId === '') {
            $result = ExternalExecution::run('social:'.$target->getAttribute('id').':step6',(int)$post->getAttribute('user_id'),fn()=>$api->publishVideo($videoUrl, $title, 'auto', TikTokAPI::optionsFor(Database::getInstance()->getConnection(), (int) $target->getAttribute('id'))));
            if (!$result['success']) {
                $this->fail($target, $result['error'] ?? 'تعذر بدء نشر الفيديو على TikTok',($result['outcome_state']??'uncertain')==='uncertain');
                return;
            }

            $target->setAttribute('provider_ref', $result['publish_id'] ?? 'unknown');
            $target->setAttribute('status', 'publishing');
            if(!$target->save())throw new RuntimeException('Target persistence failed');

            $this->requeue((int) $target->getAttribute('id'));
            return;
        }

        $attempts = (int) $target->getAttribute('poll_attempts');
        if ($attempts >= self::MAX_POLL_ATTEMPTS) {
            $this->fail($target, 'انتهت مهلة نشر الفيديو على TikTok - حاول مرة أخرى');
            return;
        }

        $status = $api->checkPublishStatus($publishId);
        if (!$status['success']) {
            $this->fail($target, $status['error'] ?? 'تعذر فحص حالة النشر على TikTok');
            return;
        }

        if ($status['status'] === 'FAILED') {
            $this->fail($target, 'فشل نشر الفيديو على TikTok: ' . ($status['error'] ?? 'سبب غير معروف'));
            return;
        }

        if ($status['status'] !== 'PUBLISHED') {
            $target->setAttribute('poll_attempts', $attempts + 1);
            if(!$target->save())throw new RuntimeException('Target persistence failed');
            $this->requeue((int) $target->getAttribute('id'));
            return;
        }

        $this->finishOrFail($target, $post, 'tiktok', ['success' => true, 'post_id' => $publishId]);
    }

    // --- YouTube ---
    private function handleYouTubeVideo(SocialPostTarget $target, SocialPost $post, YouTubeAPI $api, MediaItem $media, string $title): void
    {
        $videoId = (string) ($target->getAttribute('provider_ref') ?: '');

        if ($videoId === '') {
            $localPath = (string) $media->getAttribute('file_path');
            $fullPath = PrivateMedia::valid($localPath)?PrivateMedia::resolve($localPath):ROOT_PATH . '/public_html/' . ltrim($localPath, '/');

            if (!file_exists($fullPath)) {
                $this->fail($target, 'ملف الفيديو غير موجود على السيرفر: ' . $localPath);
                return;
            }

            $result = ExternalExecution::run('social:'.$target->getAttribute('id').':step7',(int)$post->getAttribute('user_id'),fn()=>$api->uploadShort($fullPath, $title, $title, [], 'public'));
            if (!$result['success']) {
                $this->fail($target, $result['error'] ?? 'تعذر رفع الفيديو على YouTube',($result['outcome_state']??'uncertain')==='uncertain');
                return;
            }

            $target->setAttribute('provider_ref', $result['video_id']);
            $target->setAttribute('status', 'publishing');
            if(!$target->save())throw new RuntimeException('Target persistence failed');

            $this->requeue((int) $target->getAttribute('id'));
            return;
        }

        $attempts = (int) $target->getAttribute('poll_attempts');
        if ($attempts >= self::MAX_POLL_ATTEMPTS) {
            $this->fail($target, 'انتهت مهلة معالجة الفيديو على YouTube - حاول مرة أخرى');
            return;
        }

        $status = $api->checkVideoStatus($videoId);
        if (!$status['success']) {
            $this->fail($target, $status['error'] ?? 'تعذر فحص حالة الفيديو على YouTube');
            return;
        }

        if ($status['status'] === 'ERROR') {
            $this->fail($target, 'فشلت معالجة الفيديو على YouTube: ' . ($status['error'] ?? ''));
            return;
        }

        if ($status['status'] !== 'FINISHED') {
            $target->setAttribute('poll_attempts', $attempts + 1);
            if(!$target->save())throw new RuntimeException('Target persistence failed');
            $this->requeue((int) $target->getAttribute('id'));
            return;
        }

        $this->finishOrFail($target, $post, 'youtube', ['success' => true, 'post_id' => $videoId]);
    }

    private function finishOrFail(SocialPostTarget $target, SocialPost $post, string $platform, array $result): void
    {
        $targetId = (int) $target->getAttribute('id');

        if (!$result['success']) {
            $this->fail($target, $result['error'] ?? 'خطأ غير معروف من المنصة',($result['outcome_state']??'uncertain')==='uncertain');
            Logger::error('Social Post Publish Failed', ['target_id' => $targetId, 'platform' => $platform, 'error' => $result['error'] ?? null]);
            return;
        }

        $target->setAttribute('status', 'published');
        $target->setAttribute('external_post_id', $result['post_id'] ?? ($result['video_id'] ?? null));
        $target->setAttribute('published_at', date('Y-m-d H:i:s'));
        $target->setAttribute('last_error', null);
        $target->setAttribute('provider_ref', null);
        if(!$target->save())throw new RuntimeException('Target persistence failed');

        $platformName = match ($platform) {
            'facebook'  => 'فيسبوك',
            'instagram' => 'انستجرام',
            'tiktok'    => 'تيك توك',
            'youtube'   => 'يوتيوب',
            default     => $platform,
        };

        try {
        if (class_exists('Notification')) {
            Notification::notify(
                (int) $post->getAttribute('user_id'),
                'social_post_published',
                'تم نشر منشورك',
                "اتنشر منشورك على {$platformName} بنجاح.",
                '/social'
            );
        }

        }catch(Throwable $e){Logger::warning('Social publish notification failed',['target_id'=>$targetId]);}

        Logger::info('Social Post Published', ['target_id' => $targetId, 'platform' => $platform, 'post_id' => $result['post_id'] ?? ($result['video_id'] ?? null)]);
    }

    /** رسالة Meta الخام + شرح بالعربي للأخطاء المشهورة عشان العميل يعرف يعمل إيه */
    public static function friendlyError(string $msg): string
    {
        $m = strtolower($msg);
        $hint = '';
        if (str_contains($m, 'aspect ratio')) {
            $hint = 'مقاس الصورة مش مقبول في إنستجرام (لازم بين 4:5 و 1.91:1). ارفعها تاني من المنصة وهي هتظبط المقاس.';
        } elseif (str_contains($m, 'only photo or video') || str_contains($m, 'image format') || str_contains($m, 'jpeg')) {
            $hint = 'إنستجرام بيقبل صور JPG بس. ارفع الصورة من فورم المنشور والمنصة هتحولها.';
        } elseif (str_contains($m, 'media download') || str_contains($m, 'could not be fetched') || str_contains($m, 'failed to download')) {
            $hint = 'Meta ماقدرتش تحمّل الصورة/الفيديو من موقعك. اتأكد إن الموقع شغال على https ومفيش حماية (WAF/Cloudflare) بتمنع فولدر /uploads.';
        } elseif (str_contains($m, '(#200)') || str_contains($m, '(#10)') || str_contains($m, 'permission') || str_contains($m, 'not authorized')) {
            $hint = 'صلاحية ناقصة من Meta. اربط فيسبوك وإنستجرام تاني من صفحة السوشيال ووافق على كل الصلاحيات.';
        } elseif (str_contains($m, 'session has expired') || str_contains($m, 'error validating access token') || str_contains($m, '(#190)') || str_contains($m, 'code 190')) {
            $hint = 'انتهت صلاحية الربط. اربط فيسبوك وإنستجرام تاني من صفحة السوشيال.';
        } elseif (str_contains($m, 'application does not have the capability') || str_contains($m, 'development mode')) {
            $hint = 'تطبيق Meta لسه في وضع التطوير. لازم الحساب اللي بتنشر بيه يكون Admin/Tester في التطبيق، أو التطبيق يتنشر (Live) بعد المراجعة.';
        } elseif (str_contains($m, 'rate limit') || str_contains($m, '(#4)') || str_contains($m, '(#17)') || str_contains($m, '(#32)') || str_contains($m, '(#613)')) {
            $hint = 'Meta موقفة الطلبات مؤقتًا (حد الاستخدام). جرّب تاني بعد شوية.';
        }
        return $hint ? $hint . ' — ' . $msg : $msg;
    }

    private function fail(SocialPostTarget $target, string $message,bool $uncertain=false): void
    {
        $target->setAttribute('status', $uncertain?'uncertain':'failed');
        $target->setAttribute('last_error', self::friendlyError($message));
        if(!$target->save())throw new RuntimeException('Target persistence failed');
    }

    private function requeue(int $targetId): void
    {
        $queue = Container::getInstance()->make(QueueManager::class);
        $queue->push(PublishSocialPostJob::class, ['social_post_target_id' => $targetId], 'social', self::POLL_DELAY_SECONDS);
    }

    private function resolveMedia(int $mediaItemId): ?MediaItem
    {
        if (!$mediaItemId) {
            return null;
        }
        $media = (new MediaItem())->find($mediaItemId);
        if (!$media || $media->getAttribute('deleted_at') || $media->getAttribute('status') !== 'completed' || !$media->getAttribute('file_path')) {
            return null;
        }
        return $media;
    }

    private function toPublicUrl(string $filePath): ?string
    {
        if (!$filePath) {
            return null;
        }
        if (strpos($filePath, 'uploads/') === 0 || strpos($filePath, '/uploads/') === 0) {
            $appUrl = rtrim(defined('APP_URL') ? APP_URL : '', '/');
            return $appUrl . '/' . ltrim($filePath, '/');
        }
        return null;
    }
}
