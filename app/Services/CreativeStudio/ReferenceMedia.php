<?php
/** Owner-scoped local references; never accepts remote URLs or arbitrary paths. */
class ReferenceMedia
{
    public const MAX_BYTES=10485760;
    public static function validateInline(array $reference): void
    {
        if(!in_array($reference['mimeType']??null,['image/png','image/jpeg','image/webp'],true)||!is_string($reference['data']??null)||strlen($reference['data'])>self::MAX_BYTES*1.4)throw new InvalidArgumentException('Invalid reference payload');
        $image=GeneratedMediaFiles::image($reference['data']);$mime=$image['extension']==='jpg'?'image/jpeg':'image/'.$image['extension'];if($mime!==$reference['mimeType'])throw new InvalidArgumentException('Reference MIME mismatch');
    }
    public static function load(int $userId,int $id): array
    {
        $pdo=Database::getInstance()->getConnection();$s=$pdo->prepare("SELECT * FROM media_items WHERE id=? AND user_id=? AND status='completed' AND type<>'short_video' AND deleted_at IS NULL");$s->execute([$id,$userId]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new InvalidArgumentException('Reference unavailable');
        $full=PrivateMedia::resolve((string)$row['file_path']);if(filesize($full)>self::MAX_BYTES)throw new InvalidArgumentException('Reference unavailable');
        $bytes=file_get_contents($full);if($bytes===false)throw new RuntimeException('Reference read failed');$image=GeneratedMediaFiles::image(base64_encode($bytes));
        return ['mimeType'=>$image['extension']==='jpg'?'image/jpeg':'image/'.$image['extension'],'data'=>base64_encode($image['data'])];
    }
    public static function store(int $userId,string $bytes,int $parentId=0): MediaItem
    {
        if($userId<1||strlen($bytes)>self::MAX_BYTES)throw new InvalidArgumentException('Upload too large');
        if($parentId)self::load($userId,$parentId);
        $image=GeneratedMediaFiles::image(base64_encode($bytes));if($image['width']*$image['height']>8000000)throw new InvalidArgumentException('Upload dimensions too large');
        // Decode/re-encode to remove metadata, appended payloads and untrusted source file names.
        $gd=@imagecreatefromstring($image['data']);if(!$gd)throw new InvalidArgumentException('Invalid upload');imagealphablending($gd,false);imagesavealpha($gd,true);ob_start();$encoded=@imagepng($gd);$clean=ob_get_clean();imagedestroy($gd);if(!$encoded||!is_string($clean)||strlen($clean)>self::MAX_BYTES)throw new RuntimeException('Image normalization failed');
        $path=null;$thumb=null;
        try{return Database::getInstance()->transaction(function()use($userId,$parentId,$image,$clean,&$path,&$thumb){
            $item=new MediaItem(['user_id'=>$userId,'type'=>'marketing_image','prompt'=>$parentId?'Brand layout — image #'.$parentId:'Uploaded reference image','aspect_ratio'=>'1:1','status'=>'completed','generation_stage'=>'completed','usage_charged'=>1,'generation_meta'=>json_encode(['source'=>$parentId?'brand_editor':'upload','parent_id'=>$parentId,'billing'=>['source'=>'upload','amount'=>0,'currency'=>'USD','settled'=>true]],JSON_THROW_ON_ERROR)]);GenerationLifecycle::save($item);
            $path=GeneratedMediaFiles::write((int)$item->getAttribute('id'),$clean,'png');$thumb=GeneratedMediaFiles::thumbnail($path,(int)$item->getAttribute('id'));
            $item->setAttribute('file_path',$path);$item->setAttribute('thumbnail_path',$thumb?:$path);$item->setAttribute('width',$image['width']);$item->setAttribute('height',$image['height']);GenerationLifecycle::save($item);return $item;
        });}catch(Throwable $e){foreach([$path,$thumb] as $p)if($p&&preg_match('#^/uploads/media/[a-zA-Z0-9_-]+\.(png|jpg)$#D',$p))@unlink(PrivateMedia::resolve($p));throw $e;}
    }
}
