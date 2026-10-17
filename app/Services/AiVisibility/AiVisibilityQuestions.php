<?php
/** Local generation from confirmed offers + provider keywords; generation incurs no extra paid AI call. */
final class AiVisibilityQuestions
{
    public static function validate(array $questions,array $aliases): array
    {
        if (count($questions)<10 || count($questions)>30) throw new InvalidArgumentException('راجع من 10 لـ30 سؤال لكل سوق');
        $out=[];$seen=[];
        foreach ($questions as $q) {
            if (!is_string($q) || mb_strlen(trim($q))<10 || mb_strlen(trim($q))>300 || preg_match('/[\x00-\x1f<>]/u',$q)) throw new InvalidArgumentException('راجع نص السؤال (10–300 حرف)');
            $q=trim($q);$key=AiVisibilityDetector::normalize($q);
            if (AiVisibilityDetector::firstMention($q,$aliases)!==null || preg_match('~https?://|\bwww\.|@~iu',$q)) throw new InvalidArgumentException('السؤال لازم يكون من غير اسم الشركة أو دومينها أو رابط');
            if (isset($seen[$key])) throw new InvalidArgumentException('فيه أسئلة مكررة');
            $seen[$key]=true;$out[]=$q;
        }
        return $out;
    }
    public static function generate(array $facts,array $keywords,string $language,array $aliases,int $count=20): array
    {
        $templates=[
            'en'=>['What should I compare before booking %s?','What is included in %s?','What published prices can I find for %s?','Which operators offer %s?','Where can I find genuine traveler reviews of %s?','What cancellation terms apply to %s?','How do I choose a reliable provider for %s?','What should a first-time traveler know about %s?','What pickup options are published for %s?','What questions should I ask before paying for %s?'],
            'ar'=>['أقارن إيه قبل حجز %s؟','إيه اللي شاملُه %s؟','إيه الأسعار المنشورة لـ %s؟','مين الشركات اللي بتقدم %s؟','فين ألاقي تجارب مسافرين حقيقية عن %s؟','إيه شروط إلغاء %s؟','إزاي أختار مقدم خدمة موثوق لـ %s؟','إيه اللي لازم أعرفه قبل تجربة %s لأول مرة؟','إيه اختيارات الانتقال المنشورة لـ %s؟','أسأل عن إيه قبل دفع حجز %s؟'],
            'de'=>['Was sollte ich vor der Buchung von %s vergleichen?','Was ist bei %s enthalten?','Welche veröffentlichten Preise gibt es für %s?','Welche Anbieter bieten %s an?','Wo finde ich echte Bewertungen zu %s?','Welche Stornobedingungen gelten für %s?','Wie finde ich einen zuverlässigen Anbieter für %s?','Was muss ich vor meiner ersten Buchung von %s wissen?','Welche Abholoptionen gibt es bei %s?','Was sollte ich vor der Bezahlung für %s fragen?'],
            'fr'=>['Que comparer avant de réserver %s ?','Que comprend %s ?','Quels prix publiés existe-t-il pour %s ?','Quels opérateurs proposent %s ?','Où lire des avis authentiques sur %s ?','Quelles conditions d’annulation pour %s ?','Comment choisir un prestataire fiable pour %s ?','Que savoir avant de réserver %s pour la première fois ?','Quelles options de prise en charge pour %s ?','Quelles questions poser avant de payer pour %s ?']];
        if (!isset($templates[$language])) throw new InvalidArgumentException('اكتب وراجع الأسئلة يدويًا للغة السوق دي');
        $confirmed=AiVisibilityDetector::normalize(($facts['business_facts']??'').' '.($facts['offers']??''));
        if ($confirmed==='') throw new InvalidArgumentException('أكد تفاصيل الخدمة والعروض في بيانات الشركة الأول');
        $seeds=[];
        foreach ($keywords as $row) {
            if (($row['source']??'')!=='dataforseo' || !isset($row['volume'])) continue;
            $keyword=trim((string)($row['keyword']??''));$fresh=strtotime(($row['fetched_at']??'').' UTC');
            if (!$fresh || $fresh<time()-30*86400 || $fresh>time()+300 || mb_strlen($keyword)>130 || AiVisibilityDetector::firstMention($keyword,$aliases)!==null) continue;
            $overlap=false; foreach (explode(' ',AiVisibilityDetector::normalize($keyword)) as $word) if (mb_strlen($word)>=4 && str_contains($confirmed,$word)) { $overlap=true; break; }
            if ($overlap) $seeds[]=$keyword;
        }
        if (!$seeds) throw new InvalidArgumentException('محتاج كلمات DataForSEO حديثة مرتبطة بالخدمات المؤكدة؛ أو اكتب الأسئلة يدويًا');
        $out=[];
        foreach ($templates[$language] as $template) foreach ($seeds as $seed) {
            $q=sprintf($template,$seed);if (mb_strlen($q)<=300 && !in_array($q,$out,true)) $out[]=$q;
            if (count($out)>=max(10,min(30,$count))) break 2;
        }
        return self::validate($out,$aliases);
    }
}
