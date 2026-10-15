<?php
/** Client-facing services; feature flags remain authoritative. Legacy routes stay available. */
class ClientNavigation
{
    public static function groups(bool $ar): array{
        $l=static fn($a,$e)=>$ar?$a:$e;
        return [
            'home'=>[$l('الرئيسية','Home'),'home',[
                'business_hub'=>[$l('تشغيل الشركة','Company operations'),'/business-hub'],
                'overview'=>[$l('ملخص الحساب','Account overview'),'/dashboard'],
                'automation'=>[$l('إعدادات الأتمتة','Automation settings'),'/automation']]],
            'search'=>[$l('الظهور في البحث','Search visibility'),'search',[
                'seo_expert'=>[$l('الخطة والتنفيذ','Plan and execution'),'/seo-expert'],
                'seo_content'=>[$l('محتوى الموقع','Website content'),'/seo-content'],
                'gbp_content'=>[$l('ملف Google Business','Google Business profile'),'/gbp-content'],
                'auto_seo'=>['Auto SEO','/auto-seo'],
                'ai_keywords'=>[$l('الكلمات المفتاحية','Keywords'),'/ai/keywords'],
                'ai_analyze'=>[$l('التحليل التفصيلي','Detailed analysis'),'/ai/analyze'],
                'competitor_intelligence'=>[$l('تحليل المنافسين','Competitor research'),'/competitor-intelligence'],
                'outreach'=>[$l('العلاقات والروابط','Outreach and links'),'/outreach']]],
            'social'=>[$l('السوشيال ميديا','Social media'),'smartphone',[
                'social'=>[$l('خطة الشهر والنشر','Monthly plan and publishing'),'/social'],
                'whatsapp_marketing'=>['WhatsApp Marketing','/whatsapp-marketing'],
                'email_marketing'=>[$l('تسويق البريد','Email marketing'),'/email-marketing']]],
            'ads'=>[$l('الإعلانات','Advertising'),'megaphone',[
                'ads'=>[$l('الحملات والميزانية','Campaigns and budget'),'/ads']]],
            'customers'=>[$l('العملاء والرسائل','Customers and inbox'),'message',[
                'ai_chat_inbox'=>[$l('الرسائل','Inbox'),'/chat'],
                'crm'=>[$l('إدارة العملاء','CRM'),'/crm'],
                'reputation'=>[$l('التقييمات','Reviews'),'/reputation/reviews'],
                'review_requests'=>[$l('طلبات التقييم','Review requests'),'/review-requests'],
                'ai_chat_leads'=>[$l('العملاء المحتملون','Leads'),'/chat/leads'],
                'ai_chat_settings'=>[$l('إعدادات الردود','Reply settings'),'/chat/settings'],
                'ai_chat_followup'=>[$l('المتابعة','Follow-ups'),'/chat/followup-settings'],
                'ai_chat_knowledge'=>[$l('معلومات المساعد','Assistant knowledge'),'/chat/knowledge-base'],
                'ai_chat_learning'=>[$l('تحسين الردود','Improve replies'),'/chat/learning'],
                'reputation_overview'=>[$l('ملخص التقييمات','Review overview'),'/reputation/overview'],
                'reputation_intelligence'=>[$l('تحليل السمعة','Reputation analysis'),'/reputation/intelligence'],
                'reputation_stats'=>[$l('إحصائيات التقييمات','Review statistics'),'/reputation/stats']]],
            'site'=>[$l('الموقع والحجوزات','Website and bookings'),'globe',[
                '_websites'=>[$l('المواقع والربط','Websites and connection'),'/websites'],
                'bookings'=>[$l('الحجوزات والتوفر','Bookings and availability'),'/bookings'],
                'operations'=>[$l('تشغيل الرحلات','Trip operations'),'/operations'],
                'website_optimizer'=>[$l('تحسين الموقع','Website optimization'),'/website-optimizer'],
                'website_builder'=>[$l('إنشاء موقع','Build a website'),'/website-builder'],
                'business_center'=>[$l('إدارة النشاط','Business management'),'/business-center'],
                'service_center'=>[$l('الخدمات المرتبطة','Connected services'),'/service-center']]],
            'content'=>[$l('مكتبة المحتوى','Content library'),'palette',[
                'creative_studio'=>[$l('الصور والفيديو','Images and video'),'/creative-studio'],
                'ai_articles'=>[$l('المقالات','Articles'),'/ai/articles'],
                'marketing_assistant'=>[$l('أفكار المحتوى','Content ideas'),'/marketing-assistant']]],
            'reports'=>[$l('النتائج والتقارير','Results and reports'),'bar-chart-2',[
                'analytics'=>[$l('الأداء','Performance'),'/dashboard/analytics'],
                'ai_reports'=>[$l('تقارير التحليل','Analysis reports'),'/ai/reports'],
                'revenue'=>[$l('الإيراد','Revenue'),'/revenue'],
                'growth'=>[$l('النمو','Growth'),'/dashboard/growth'],
                'executive'=>[$l('الملخص التنفيذي','Executive summary'),'/dashboard/executive'],
                'revenue_intelligence'=>[$l('تحليل الإيراد','Revenue analysis'),'/revenue/intelligence'],
                'activity'=>[$l('سجل النشاط','Activity log'),'/dashboard/activity']]],
        ];
    }
    private static function enabled(string $key,array $flags): bool{return str_starts_with($key,'_')||!array_key_exists($key,$flags)||(bool)$flags[$key];}
    private static function esc(string $s): string{return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
    public static function sidebar(string $active,bool $ar,array $flags): string{
        $html='<div class="panel-nav-essentials">';
        foreach(self::groups($ar) as [$title,$icon,$items]){$visible=array_filter($items,static fn($item,$key)=>self::enabled($key,$flags),ARRAY_FILTER_USE_BOTH);if(!$visible)continue;$first=reset($visible);$on=array_key_exists($active,$items);$label=self::esc($title);$url=self::esc($first[1]);$html.='<a href="'.$url.'" class="panel-nav-link'.($on?' active':'').'"'.($on?' aria-current="page"':'').' data-search="'.$label.'"><span class="ic">'.icon_svg($icon).'</span><span class="lbl">'.$label.'</span></a>';}
        $html.='</div><div class="panel-nav-group"><div class="panel-nav-group-title">'.($ar?'الحساب والمساعدة':'Account and help').'</div><div class="panel-nav-group-body">';
        foreach([['_integrations',$ar?'الحسابات والصلاحيات':'Accounts and permissions','link','/integrations'],['business_hub',$ar?'بيانات الشركة':'Company details','briefcase','/business-hub#bhForm'],['_subscription',$ar?'الاشتراك والفواتير':'Subscription and billing','credit-card','/subscription'],['_profile',$ar?'إعدادات الحساب':'Account settings','user','/profile/settings'],['agency',$ar?'إدارة الوكالة':'Agency management','building','/agency'],['_help',$ar?'الدعم':'Support','help-circle','/help/contact']] as [$key,$label,$icon,$url]){if(!self::enabled($key,$flags))continue;$on=$key===$active&&$key!=='business_hub';$html.='<a class="panel-nav-link'.($on?' active':'').'" href="'.self::esc($url).'" data-search="'.self::esc($label).'"><span class="ic">'.icon_svg($icon).'</span><span class="lbl">'.self::esc($label).'</span></a>';}
        return $html.'</div></div><div class="panel-nav-essentials">'.(self::enabled('ai_assistant',$flags)?'<a class="panel-nav-link" href="/ai-assistant"><span class="ic">'.icon_svg('sparkles').'</span><span class="lbl">'.($ar?'اسأل المساعد':'Ask the assistant').'</span></a>':'').'</div>';
    }
    public static function workspace(string $active,bool $ar,array $flags): string{
        foreach(self::groups($ar) as [$title,$icon,$items]){if(!array_key_exists($active,$items))continue;$links=[];$more=[];$index=0;foreach($items as $key=>[$label,$url]){if(!self::enabled($key,$flags))continue;$on=$key===$active;$link='<a class="p-btn outline sm'.($on?' on':'').'" href="'.self::esc($url).'"'.($on?' aria-current="page"':'').'>'.self::esc($label).'</a>';if($index++<3||$on)$links[]=$link;else $more[]=$link;}
            // شريط خفيف من غير كارت ولا عنوان: اسم القسم ظاهر في القائمة الجانبية وعنوان الصفحة
            return '<nav aria-label="'.self::esc($title).'" class="panel-subnav" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px">'.implode('',$links).($more?'<details style="position:relative"><summary class="p-btn outline sm" style="list-style:none;cursor:pointer">'.($ar?'المزيد ▾':'More ▾').'</summary><div class="p-card" style="position:absolute;z-index:20;inset-inline-start:0;margin-top:6px;padding:8px;display:flex;flex-direction:column;gap:6px;min-width:220px">'.implode('',$more).'</div></details>':'').'</nav>';}
        return '';
    }
}
