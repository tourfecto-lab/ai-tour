<?php

/** Validated asynchronous image and video requests with durable request identity. */
class MediaGenerationService
{
    private const SUPPORTED_TYPES = [
        'social_image', 'marketing_image', 'instagram_post', 'facebook_cover',
        'youtube_thumbnail', 'story', 'reels_cover', 'short_video',
    ];

    /** نوع الوسائط -> نسبة الأبعاد الاحترافية الصحيحة لكل منصة (صور فقط) */
    private const IMAGE_ASPECT_RATIOS = [
        'social_image' => '1:1',
        'marketing_image' => '4:3',
        'instagram_post' => '1:1',
        'facebook_cover' => '16:9',
        'youtube_thumbnail' => '16:9',
        'story' => '9:16',
        'reels_cover' => '9:16',
    ];

    /** إضافات أسلوب احترافية بتتحط على وصف المستخدم قبل التوليد */
    private const STYLE_PRESETS = [
        'photo' => 'authentic editorial travel photography, candid moment, lifelike skin texture and subtle imperfections, believable hands and anatomy, physically consistent natural light and shadows, realistic fabric and water, restrained color grading, no CGI, no beauty filters or plastic skin',
        'cinematic' => 'cinematic lighting, dramatic composition, shallow depth of field, high-end commercial film look',
        'product' => 'clean studio background, professional product photography, soft shadows, advertising catalog style',
        'illustration' => 'modern flat illustration style, clean vector shapes, vibrant harmonious colors',
        'luxury' => 'premium editorial travel campaign, elegant composition, restrained color palette, refined cinematic light, realistic detail',
        'minimal' => 'minimalist design, plenty of negative space, elegant and simple composition',
    ];

    /** منصة الفيديو -> نسبة الأبعاد (فيديو بس بيدعم 16:9 أو 9:16) */
    private const VIDEO_ASPECT_RATIOS = [
        'tiktok' => '9:16',
        'instagram_reels' => '9:16',
        'youtube_shorts' => '9:16',
        'general_landscape' => '16:9',
    ];

    public function isSupportedType(string $type): bool
    {
        return in_array($type, self::SUPPORTED_TYPES, true);
    }

    public static function stylePresets(): array
    {
        return self::STYLE_PRESETS;
    }

    /**
     * توليد صورة تسويقية احترافية.
     * @param string $style مفتاح من STYLE_PRESETS (اختياري)
     */
    public function requestGeneration(int $userId, string $type, string $prompt, string $style = 'photo', ?BrandProfile $brand = null, string $headline = '', array $options = [], string $requestKey = '', array $billing = []): MediaItem
    {
        if (!in_array($type, self::SUPPORTED_TYPES, true) || $type === 'short_video') {
            throw new InvalidArgumentException("نوع وسائط غير مدعوم: {$type}");
        }

        $prompt=GenerationOptions::text($prompt,'prompt',2000,true);
        if(!isset(self::STYLE_PRESETS[$style]))throw new InvalidArgumentException('Invalid style');
        $options=GenerationOptions::normalize($options);
        if($options['reference_id']){ReferenceMedia::load($userId,$options['reference_id']);$key=defined('GEMINI_API_KEY')?GEMINI_API_KEY:'';if(class_exists('SystemSettingsService'))$key=(new SystemSettingsService())->get('gemini_api_key',$key);if(class_exists('AiServiceRouting'))$key=AiServiceRouting::apiKey('gemini');if(!is_string($key)||$key==='')throw new InvalidArgumentException('Reference editing requires Gemini');}
        $headline=GenerationOptions::text($headline,'headline',200);
        $aspectRatio = $options['aspect_ratio'] ?: self::IMAGE_ASPECT_RATIOS[$type];
        $finalPrompt = $brand
            ? BrandPromptBuilder::build($prompt, $style, $brand, $aspectRatio)
            : $this->buildImagePrompt($prompt, $style);
        $finalPrompt.=' '.GenerationOptions::direction($options).' Compose for a '.$aspectRatio.' output. Keep the essential subject inside the central safe area; edges may be cropped to the selected ratio. Preserve requested faces and hands in the frame.';

        return $this->enqueue($userId, [
            'type'=>$type,'prompt'=>$prompt,'aspect_ratio'=>$aspectRatio,
            'brand_profile_id'=>$brand?(int)$brand->getAttribute('id'):null,
            'final_prompt'=>$finalPrompt,'headline'=>$headline?:null,
            'generation_meta'=>json_encode(array_filter(['style'=>$style,'options'=>$options,'billing'=>$billing?:null]),JSON_THROW_ON_ERROR),
        ],GenerateMediaJob::class,$requestKey);
    }

    /**
     * توليد فيديو قصير احترافي (Veo) - نفس فكرة requestGeneration لكن
     * بمهلة أطول (عملية غير متزامنة) عبر GenerateVideoJob.
     * @param string $platform tiktok | instagram_reels | youtube_shorts | general_landscape
     * @param int    $durationSeconds 4 | 6 | 8
     * @param string $style مفتاح من STYLE_PRESETS
     */
    public function requestVideoGeneration(int $userId, string $prompt, string $platform = 'instagram_reels', int $durationSeconds = 8, string $style = 'cinematic', ?BrandProfile $brand = null, array $options = [], string $requestKey = '', array $billing = []): MediaItem
    {
        $prompt=GenerationOptions::text($prompt,'prompt',2000,true);
        if(!isset(self::VIDEO_ASPECT_RATIOS[$platform])||!isset(self::STYLE_PRESETS[$style])||!in_array($durationSeconds,[4,6,8],true))throw new InvalidArgumentException('Invalid video settings');
        $options=GenerationOptions::normalize($options,true);
        if($options['reference_id'])ReferenceMedia::load($userId,$options['reference_id']);
        if(($options['reference_id']||$options['resolution']!=='720p')&&$durationSeconds!==8)throw new InvalidArgumentException('Eight seconds required');
        if(!VeoClient::supportsResolution($options['resolution'])||($options['resolution']!=='720p'&&!$billing))throw new InvalidArgumentException('Approved premium quote required');
        $aspectRatio=$options['aspect_ratio']?:self::VIDEO_ASPECT_RATIOS[$platform];
        $finalPrompt=$this->buildVideoPrompt($prompt,$style).' '.GenerationOptions::direction($options,true);
        if($brand){$palette=BrandPromptBuilder::palette($brand);$colors=array_filter([$palette['primary']??'',$palette['secondary']??'',$palette['accent']??'']);$finalPrompt.=' Brand mood and color accents: '.implode(', ',$colors).'.';}
        return $this->enqueue($userId,[
            'type'=>'short_video','prompt'=>$prompt,'aspect_ratio'=>$aspectRatio,'duration_seconds'=>$durationSeconds,
            'brand_profile_id'=>$brand?(int)$brand->getAttribute('id'):null,'final_prompt'=>$finalPrompt,
            'generation_meta'=>json_encode(array_filter(['style'=>$style,'platform'=>$platform,'options'=>$options,'billing'=>$billing?:null]),JSON_THROW_ON_ERROR),
        ],GenerateVideoJob::class,$requestKey);
    }

    public function requestBatch(int $userId,string $kind,array $data,?BrandProfile $brand,string $requestKey,string $quoteHash): array
    {
        if(!preg_match('/^[a-zA-Z0-9_-]{16,70}$/D',$requestKey)||!preg_match('/^[a-f0-9]{64}$/D',$quoteHash))throw new InvalidArgumentException('Invalid confirmed request');
        $count=$data['count']??1;if(!in_array($count,[1,2],true)||($kind==='video'&&$count!==1))throw new InvalidArgumentException('Invalid count');
        return Database::getInstance()->transaction(function()use($userId,$kind,$data,$brand,$requestKey,$quoteHash,$count){
            $pdo=Database::getInstance()->getConnection();$lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$s=$pdo->prepare('SELECT id FROM users WHERE id=?'.$lock);$s->execute([$userId]);if(!$s->fetchColumn())throw new RuntimeException('User unavailable');
            $s=$pdo->prepare('SELECT * FROM media_items WHERE user_id=? AND request_key IN (?,?) ORDER BY id');$s->execute([$userId,$requestKey.'_1',$requestKey.'_2']);$existing=$s->fetchAll(PDO::FETCH_ASSOC);if($existing)return array_map(fn($row)=>new MediaItem($row),$existing);
            $options=GenerationOptions::normalize($data['options']??[],$kind==='video');$duration=$data['duration_seconds']??8;
            $quote=CreativePricing::quote($userId,$kind,$options,$count,$duration);if(!$quote['ok']||!hash_equals($quote['hash'],$quoteHash))throw new InvalidArgumentException('Quote changed or insufficient funds');
            $items=[];for($i=0;$i<$count;$i++){
                $prompt=GenerationOptions::text($data['prompt']??'','prompt',2000,true);$style=GenerationOptions::text($data['style']??($kind==='video'?'cinematic':'photo'),'style',30);
                if($kind==='video')$items[]=$this->requestVideoGeneration($userId,$prompt,GenerationOptions::text($data['platform']??'instagram_reels','platform',30),$duration,$style,$brand,$options,$requestKey.'_'.($i+1),$quote['units'][$i]);
                else $items[]=$this->requestGeneration($userId,GenerationOptions::text($data['type']??'social_image','type',40),$prompt,$style,$brand,'',$options,$requestKey.'_'.($i+1),$quote['units'][$i]);
            }return $items;
        });
    }
    private function enqueue(int $userId,array $attributes,string $job,string $requestKey): MediaItem
    {
        if($userId<1||($requestKey!==''&&!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$requestKey)))throw new InvalidArgumentException('Invalid request identity');
        $db=Database::getInstance();
        return $db->transaction(function()use($db,$userId,$attributes,$job,$requestKey){
            $pdo=$db->getConnection();$lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $stmt=$pdo->prepare('SELECT id FROM users WHERE id=?'.$lock);$stmt->execute([$userId]);if(!$stmt->fetchColumn())throw new RuntimeException('User unavailable');
            if($requestKey!==''){$stmt=$pdo->prepare('SELECT * FROM media_items WHERE user_id=? AND request_key=?');$stmt->execute([$userId,$requestKey]);$row=$stmt->fetch(PDO::FETCH_ASSOC);if($row)return new MediaItem($row);}
            $stmt=$pdo->prepare("SELECT COUNT(*) FROM media_items WHERE user_id=? AND status='generating'");$stmt->execute([$userId]);if((int)$stmt->fetchColumn()>=2)throw new InvalidArgumentException('انتظر اكتمال أحد الطلبين الجاريين قبل إضافة طلب جديد.');
            $queue=Container::getInstance()->make(QueueManager::class);if(!$queue->isReady())throw new RuntimeException('Generation queue unavailable');
            $key=defined('GEMINI_API_KEY')?GEMINI_API_KEY:'';if(class_exists('SystemSettingsService'))$key=(new SystemSettingsService())->get('gemini_api_key',$key);
            if(class_exists('AiServiceRouting'))$key=AiServiceRouting::apiKey('gemini');
            $configured=$job===GenerateVideoJob::class?is_string($key)&&$key!=='':((is_string($key)&&$key!=='')||(new OpenAIImageClient())->isConfigured());
            if(!$configured)throw new RuntimeException('Generation provider not configured');
            if(class_exists('PlanUsage')&&empty(json_decode($attributes['generation_meta'],true)['billing'])){$feature=$job===GenerateVideoJob::class?'media_video':'media_image';$check=PlanUsage::check($userId,$feature,$feature,'الرصيد أو الحصة غير كافيين للتوليد.');if(!$check['ok'])throw new InvalidArgumentException('الرصيد أو الحصة غير كافيين للتوليد.');}
            $meta=json_decode($attributes['generation_meta'],true)?:[];
            if(empty($meta['billing'])){$kind=$job===GenerateVideoJob::class?'video':'image';$quote=CreativePricing::quote($userId,$kind,$meta['options']??[],1,(int)($attributes['duration_seconds']??8));if(!$quote['ok'])throw new InvalidArgumentException('Reservation unavailable');$meta['billing']=$quote['units'][0];$attributes['generation_meta']=json_encode($meta,JSON_THROW_ON_ERROR);}
            if(class_exists('AiServiceRouting')){$task=$job===GenerateVideoJob::class?'creative_studio_video':(!empty($meta['options']['reference_id'])?'creative_studio_edit':'creative_studio_image');$meta['routing_snapshot']=AiServiceRouting::mediaSnapshot($task);}
            $meta['reservation_required']=true;$attributes['generation_meta']=json_encode($meta,JSON_THROW_ON_ERROR);
            $item=new MediaItem(array_merge($attributes,['user_id'=>$userId,'status'=>'generating','generation_stage'=>'queued','request_key'=>$requestKey?:null,'usage_charged'=>0]));GenerationLifecycle::save($item);CreativeReservations::reserve($item,$meta['billing']);
            $jobId=$queue->push($job,['media_item_id'=>(int)$item->getAttribute('id'),'final_prompt'=>$attributes['final_prompt']],'media');
            if(!$jobId)throw new RuntimeException('Could not enqueue generation');
            $item->setAttribute('job_id',(int)$jobId);GenerationLifecycle::save($item);return $item;
        });
    }

    private function buildImagePrompt(string $prompt, string $style): string
    {
        $styleDescriptor = self::STYLE_PRESETS[$style] ?? self::STYLE_PRESETS['photo'];
        return trim($prompt) . '. Style: ' . $styleDescriptor . '.';
    }

    private function buildVideoPrompt(string $prompt, string $style): string
    {
        $styleDescriptor = self::STYLE_PRESETS[$style] ?? self::STYLE_PRESETS['cinematic'];
        return trim($prompt) . '. Visual style: ' . $styleDescriptor . '. Smooth camera motion, professional social-media advertising video.';
    }
}
