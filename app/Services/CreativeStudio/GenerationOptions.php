<?php
/** Validated creative direction; no unsupported provider quality promises. */
class GenerationOptions
{
    public const IMAGE_RATIOS=['1:1','4:5','4:3','3:4','16:9','9:16'];
    public const STAGES=['queued','starting','generating','processing','download_failed','billing','completed','failed'];
    public static function text($value,string $name,int $max,bool $required=false): string
    {
        if(!is_string($value))throw new InvalidArgumentException('Invalid '.$name);
        $value=trim($value);
        if(mb_strlen($value)>$max||($required&&mb_strlen($value)<8))throw new InvalidArgumentException('Invalid '.$name);
        return $value;
    }
    public static function normalize(array $input,bool $video=false): array
    {
        $out=[];
        foreach(['subject'=>300,'location'=>200,'audience'=>200,'avoid'=>400] as $key=>$max)$out[$key]=self::text($input[$key]??'',$key,$max);
        $lists=['composition'=>['balanced','negative_space','closeup','wide'],'lighting'=>['natural','golden_hour','studio','night'],'camera'=>['steady','dolly','orbit','aerial'],'audio'=>['ambient','music','silent']];
        foreach($lists as $key=>$values){$v=$input[$key]??$values[0];if(!is_string($v)||!in_array($v,$values,true))throw new InvalidArgumentException('Invalid '.$key);$out[$key]=$v;}
        $ratio=$input['aspect_ratio']??'';
        if(!is_string($ratio)||($ratio!==''&&!in_array($ratio,$video?['16:9','9:16']:self::IMAGE_RATIOS,true)))throw new InvalidArgumentException('Invalid aspect ratio');
        $out['aspect_ratio']=$ratio;
        $ref=$input['reference_id']??0;if(!is_scalar($ref)||!ctype_digit((string)$ref)||strlen((string)$ref)>10)throw new InvalidArgumentException('Invalid reference');$out['reference_id']=(int)$ref;
        $resolution=$input['resolution']??'720p';if(!is_string($resolution)||!in_array($resolution,['720p','1080p','4k'],true)||(!$video&&$resolution!=='720p'))throw new InvalidArgumentException('Invalid resolution');$out['resolution']=$resolution;
        $scene=$input['scene']??'custom';if(!is_string($scene)||!in_array($scene,['custom','arrival','room','experience','destination'],true))throw new InvalidArgumentException('Invalid scene');$out['scene']=$scene;
        return $out;
    }
    public static function direction(array $o,bool $video=false): string
    {
        $parts=[];
        foreach(['subject'=>'Main subject','location'=>'Setting','audience'=>'Intended audience'] as $k=>$label)if($o[$k]!=='')$parts[]=$label.': '.$o[$k].'.';
        $composition=['balanced'=>'Balanced composition with one clear focal point','negative_space'=>'Keep clean negative space for text to be added later','closeup'=>'Detailed close-up of the main subject','wide'=>'Wide establishing composition with environmental context'];
        $light=['natural'=>'Soft natural light','golden_hour'=>'Warm golden-hour light','studio'=>'Controlled soft studio light','night'=>'Atmospheric night lighting with readable subject detail'];
        $parts[]=$composition[$o['composition']].'. '.$light[$o['lighting']].'.';
        if($video&&($o['scene']??'custom')!=='custom'){$scenes=['arrival'=>'A single welcoming arrival shot with a slow reveal of the entrance','room'=>'A single smooth interior reveal highlighting space and natural materials','experience'=>'A single coherent activity shot with restrained subject motion','destination'=>'A single establishing destination reveal with gentle forward camera movement'];$parts[]=$scenes[$o['scene']].'.';}
        if($video){$camera=['steady'=>'Steady locked-off camera','dolly'=>'Slow smooth dolly movement','orbit'=>'Gentle orbit around the subject','aerial'=>'Smooth aerial establishing movement'];$audio=['ambient'=>'Natural ambient sound without dialogue','music'=>'Subtle instrumental background music without dialogue','silent'=>'No music, speech or deliberate sound effects'];$parts[]=$camera[$o['camera']].'. '.$audio[$o['audio']].'. Keep one coherent scene, stable anatomy and temporal continuity.';}
        if($o['avoid']!=='')$parts[]='Avoid these elements: '.$o['avoid'].'.';
        $parts[]='Do not invent prices, travel guarantees, awards or factual claims. Do not render logos, watermarks or embedded text. Final branding and text can be added separately.';
        return implode(' ',$parts);
    }
    public static function publicItem(array $row): array
    {
        $keys=['id','type','prompt','aspect_ratio','duration_seconds','file_path','thumbnail_path','width','height','status','rating','created_at','brand_profile_id','generation_stage','folder_id','is_favorite','deleted_at'];
        $out=array_intersect_key($row,array_flip($keys));
        $out['id']=(int)($out['id']??0);$out['rating']=(int)($out['rating']??0);
        $meta=json_decode((string)($row['generation_meta']??'{}'),true);$out['settings']=is_array($meta)?array_intersect_key($meta,array_flip(['style','platform','options','output'])):[];
        $billing=is_array($meta)?($meta['billing']??null):null;
        $out['usage']=is_array($billing)?array_intersect_key($billing,array_flip(['source','amount','currency','key','settled'])):null;
        foreach(['file_path','thumbnail_path'] as $key){$p=$out[$key]??null;$out[$key]=is_string($p)&&(PrivateMedia::valid($p)||PrivateMedia::socialFile($p))?PrivateMedia::preview($out['id'],$key==='thumbnail_path'):null;}
        if(($out['status']??'')!=='completed'||!empty($out['deleted_at'])){$out['file_path']=null;$out['thumbnail_path']=null;}
        $out['is_favorite']=(bool)($out['is_favorite']??false);
        $out['error_message']=($out['status']??'')==='failed'?'لم يكتمل التوليد. جرّب وصفًا أوضح أو تواصل مع الدعم برقم الطلب.':null;
        if(!in_array($out['generation_stage']??'',self::STAGES,true))$out['generation_stage']=($out['status']??'')==='generating'?'generating':($out['status']??'failed');
        return $out;
    }
}
