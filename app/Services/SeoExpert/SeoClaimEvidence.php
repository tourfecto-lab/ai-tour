<?php
/** Conservative evidence checks for observed risky claims, not a general fact checker.
 * Keep complete sentences: a different tour's offer or a negated policy cannot
 * prove an affirmative, site-wide promise. Callers enforce profile ownership/revision.
 */
final class SeoClaimEvidence
{
    public static function snapshotFacts(array $snapshot): array
    {
        $profile=$snapshot['brief']['shared_profile']??[];
        return !empty($profile['version'])&&empty($profile['needs_reconfirmation'])&&is_array($profile['facts']??null)?$profile['facts']:[];
    }

    private static function sentences(string $text): array
    {
        $text=html_entity_decode(preg_replace('~<[^>]+>~u',"\n",$text),ENT_QUOTES|ENT_HTML5,'UTF-8');
        return preg_split('/(?<=[.!?؟])\s+|\R/u',$text)?:[];
    }

    private static function normalize(string $text): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}%]+/u',' ',mb_strtolower($text)));
    }

    public static function review(array $parts,array $facts): array
    {
        // Audience, goals, competitors, imported pages and product-specific offers
        // do not confirm general booking policies. No semantic inference is made.
        $confirmed=[];
        foreach(self::sentences(is_string($facts['business_facts']??null)?$facts['business_facts']:'') as $fact){
            $fact=trim($fact);
            if(preg_match('/[?؟][*_\s]*$/u',$fact)||preg_match('/^[#*\s-]*(?:confirm|check|ask|verify|تأكد|اسأل|راجع)(?:\s|:)/iu',$fact))continue;
            $confirmed[]=self::normalize($fact);
        }
        $patterns=[
            'cancellation_policy'=>'/\b(?:free\s+cancell?ation|cancell?ation\s+(?:is\s+)?free|cancel\s+(?:for\s+free|free\s+of\s+charge))\b|إلغاء\s+مجاني|الإلغاء\s+مجاني/iu',
            'guide_credentials'=>'/\b(?:licensed|certified|accredited)\s+(?:(?:local|professional|expert|egyptologist|tour)\s+){0,3}(?:guides?|egyptologists?)\b|مرشد[\p{L}\s]{0,35}(?:مرخص|معتمد)/iu',
            'child_pricing'=>'/\b(?:child(?:ren)?|kids?|infants?)\b.{0,180}\b(?:free|discount|reduction|reduced|percent|per\s+cent|half[\s-]price)\b|\b(?:child(?:ren)?|kids?|infants?)\b.{0,180}[0-9]+\s*%|(?:الأطفال|الاطفال|أطفال|اطفال).{0,180}(?:مجان|خصم|تخفيض|بالمئة|بالمائة|%)/iu',
        ];
        $findings=[];$seen=[];
        foreach($parts as $field=>$text){
            if(!is_string($text))continue;
            foreach(self::sentences($text) as $sentence){
                $sentence=trim($sentence);if($sentence==='')continue;
                // Questions and requests for confirmation remain editorial guidance.
                $plain=ltrim($sentence,"#*- \t");
                if(preg_match('/^(?:confirm|check|ask|verify|تأكد|اسأل|راجع)(?:\s|:)/iu',$plain))continue;
                if(preg_match('/^(?:is|are|can|do|does|what|when|where|how|هل|ما|متى|كيف)\b/iu',$plain)&&preg_match('/[?؟][*_\s]*$/u',$plain))continue;
                foreach($patterns as $code=>$pattern){
                    if(!preg_match($pattern,$sentence)||in_array(self::normalize($sentence),$confirmed,true))continue;
                    $key=$field.'|'.$code.'|'.$sentence;if(isset($seen[$key]))continue;$seen[$key]=true;
                    $findings[]=['code'=>$code,'field'=>(string)$field,'quote'=>mb_substr($sentence,0,400)];
                    if(count($findings)>=20)break 3;
                }
            }
        }
        return ['state'=>$findings?'needs_confirmation':'no_flagged_claims','basis'=>'confirmed_business_facts_exact_sentence','findings'=>$findings,
            'reason'=>$findings?'Confirm the flagged policy or credential in business facts using the same complete sentence and scope, or remove it from the proposal before approval. Existing publication history is retained. / أكد السياسة أو المؤهل في معلومات النشاط بنفس الجملة الكاملة ونطاقها، أو احذف الادعاء من الاقتراح قبل الاعتماد. سجل النشر السابق محفوظ.':'No claim was flagged by these limited checks; editorial review is still required. / لم يرصد الفحص المحدود ادعاءً يحتاج تأكيدًا؛ المراجعة التحريرية ما زالت مطلوبة.'];
    }

    public static function task(array $task,array $facts): array
    {
        $parts=[];
        if(($task['kind']??'')==='metadata'&&in_array($task['field_name']??'',['title','description'],true))$parts[$task['field_name']]=(string)($task['expected_value']??'');
        if(($task['kind']??'')==='content'){
            $parts=['title'=>(string)($task['title']??''),'content'=>(string)($task['expected_value']??''),'meta_description'=>(string)($task['content_review']['meta_description']??'')];
            foreach($task['content_review']['faqs']??[] as $i=>$faq)if(is_array($faq))$parts['faq_answer_'.$i]=(string)($faq['answer']??'');
        }
        return self::review($parts,$facts);
    }
}
