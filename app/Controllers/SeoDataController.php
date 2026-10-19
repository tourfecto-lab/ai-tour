<?php
/** SEO data APIs, with ownership checks before data access and no secrets in responses. */
class SeoDataController extends Controller
{
    private function context(bool $admin=false): array
    {
        if (!$this->isAuthenticated()) throw new InvalidArgumentException('يجب تسجيل الدخول');
        $wid=(int)$this->get('website_id'); $uid=(int)$this->user['id'];
        if ($admin) {
            if (!in_array($this->user['role']??'',['admin','super_admin'],true) || !AdminAccess::can($this->user,'settings.manage')) throw new InvalidArgumentException('غير مسموح');
            $uid=(int)($this->db->query('SELECT user_id FROM websites WHERE id=?',[$wid])[0]['user_id']??0);
        }
        $service=new SeoMarketService($this->db); $service->site($wid,$uid);
        return [$wid,$uid,$service];
    }
    public function markets(array $params=[]): array { return $this->marketAction(false,false); }
    public function addMarket(array $params=[]): array { return $this->marketAction(true,false); }
    public function adminMarkets(array $params=[]): array { return $this->marketAction(false,true); }
    public function adminAddMarket(array $params=[]): array { return $this->marketAction(true,true); }
    private function marketAction(bool $write,bool $admin): array
    {
        try {
            [$wid,$uid,$service]=$this->context($admin);
            if ($write) {
                if ($this->get('action')==='primary') $service->primary($wid,$uid,(int)$this->get('market_id'));
                else $service->add($wid,$uid,['location_code'=>$this->get('location_code'),'language_code'=>$this->get('language_code'),'label'=>$this->get('label'),'is_primary'=>$this->get('is_primary',false)]);
            }
            return $this->success(['markets'=>$service->markets($wid,$uid),'limit'=>$service->limit($uid)]);
        } catch (InvalidArgumentException $e) { return $this->error($e->getMessage(),422); }
    }
    public function discover(array $params=[]): array
    {
        try {
            [$wid,$uid]=$this->context();
            $seeds=$this->get('seeds',[]); if (!is_array($seeds)) throw new InvalidArgumentException('الكلمات لازم تكون قائمة');
            $r=(new DataForSeoResearchService($this->db))->research($wid,$uid,(int)$this->get('market_id',0),$seeds);
            if (empty($r['available'])) return $this->success($r,'not measured');
            $words=array_column($r['data'],'keyword');
            return $this->success($r+['discovered_keywords'=>$words,'new_discoveries'=>$words]);
        } catch (InvalidArgumentException $e) { return $this->error($e->getMessage(),422); }
    }
    public function enrich(array $params=[]): array
    {
        try {
            [$wid,$uid]=$this->context();
            return $this->success((new KeywordResearchService())->enrichTrackedKeywords($this->db,$wid,$uid));
        } catch (InvalidArgumentException $e) { return $this->error($e->getMessage(),422); }
    }
    public function keywords(array $params=[]): array
    {
        try {
            [$wid,$uid]=$this->context();
            $r=(new RankTrackingService())->trackingOverview($this->db,$wid,$uid,(int)$this->get('market_id',0));
            foreach ($r['keywords'] as &$row) {
                $phrase=DataForSeoRankingSource::keywordPhrase($row['keyword']);
                $row['suggested_phrase']=$phrase!==trim($row['keyword'])?$phrase:null;
            } unset($row);
            $r['gaps']=!empty($r['status']['available'])?SeoKeywordGapService::rows($this->db,$wid,$uid):[];
            return $this->success($r);
        } catch (InvalidArgumentException $e) { return $this->error($e->getMessage(),422); }
    }
    public function deleteKeyword(array $params=[]): array
    {
        if (!$this->isAuthenticated()) return $this->error('يجب تسجيل الدخول',401);
        $s=$this->db->getConnection()->prepare('DELETE FROM tracked_keywords WHERE id=? AND user_id=?');
        $s->execute([(int)($params['id']??0),(int)$this->user['id']]);
        return $s->rowCount()?$this->success([]):$this->error('الكلمة مش موجودة',404);
    }
    public function addKeyword(array $params=[]): array { return $this->saveKeywords(false); }
    public function bulkAddKeywords(array $params=[]): array { return $this->saveKeywords(true); }
    /** Explicit review repairs only unmeasured legacy input; measured history is never renamed. */
    public function repairKeyword(array $params=[]): array
    {
        if (!$this->isAuthenticated()) return $this->error('يجب تسجيل الدخول',401);
        if ($error=$this->verifyCsrf()) return $error;
        try {
            [$wid,$uid,$markets]=$this->context();
            $market=$markets->resolve($wid,$uid,(int)$this->get('market_id',0));
            if ($this->get('reviewed',false)!==true) throw new InvalidArgumentException('راجع عبارة البحث وأكد تنقيتها أولًا');
            $result=$this->db->transaction(function () use ($wid,$uid,$market,$params) {
                $read=function (string $sql,array $values): array {
                    // Database::query intentionally maps failed SELECTs to []. A history
                    // safety check must distinguish that failure from an empty result.
                    try {
                        $statement=$this->db->getConnection()->prepare($sql);
                        if (!$statement->execute($values)) throw new RuntimeException('Read failed');
                        return $statement->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Throwable $e) {
                        throw new InvalidArgumentException('تعذر مراجعة السجل؛ لم يتم تعديل الكلمة. حاول لاحقًا');
                    }
                };
                if (!$read('SELECT id FROM websites WHERE id=? AND user_id=? FOR UPDATE',[$wid,$uid]))
                    throw new InvalidArgumentException('الموقع غير متاح في حسابك');
                $row=$read('SELECT * FROM tracked_keywords WHERE id=? AND website_id=? AND user_id=? AND market_id=? FOR UPDATE',[(int)($params['id']??0),$wid,$uid,$market['id']])[0]??null;
                if (!$row) throw new InvalidArgumentException('الكلمة غير موجودة في الموقع والسوق المختارين');
                if ($this->get('expected_keyword')!==$row['keyword']) throw new InvalidArgumentException('تغيرت الكلمة؛ حدّث القائمة وراجعها مجددًا');
                $phrase=DataForSeoRankingSource::keywordPhrase($row['keyword']);
                if ($phrase==='' || $phrase===trim($row['keyword'])) throw new InvalidArgumentException('لا توجد تعليقات تحليل معروفة لتنقيتها');
                foreach (['last_checked_at','enriched_at','gsc_checked_at','current_position','search_volume','difficulty','impressions','gsc_position'] as $field)
                    if (isset($row[$field])) throw new InvalidArgumentException('لهذه الكلمة قياسات محفوظة؛ احتفظ بسجلها وأضف العبارة الصحيحة منفصلة');
                if ($read('SELECT id FROM seo_rank_tracking_history WHERE website_id=? AND user_id=? AND market_id=? AND keyword=? LIMIT 1',[$wid,$uid,$market['id'],$row['keyword']]))
                    throw new InvalidArgumentException('لهذه الكلمة سجل ترتيب محفوظ؛ أضف العبارة الصحيحة منفصلة');
                if ($read('SELECT id FROM seo_keyword_research WHERE website_id=? AND market_id=? AND keyword=? LIMIT 1',[$wid,$market['id'],$row['keyword']]))
                    throw new InvalidArgumentException('لهذه الكلمة سجل بحث محفوظ؛ أضف العبارة الصحيحة منفصلة');
                if ($read('SELECT id FROM tracked_keywords WHERE website_id=? AND user_id=? AND market_id=? AND keyword=? LIMIT 1',[$wid,$uid,$market['id'],$phrase]))
                    throw new InvalidArgumentException('العبارة الصحيحة موجودة بالفعل؛ احتفظ بالسجل القديم دون دمجه');
                if (!$this->db->exec('UPDATE tracked_keywords SET keyword=? WHERE id=? AND website_id=? AND user_id=? AND market_id=?',[$phrase,$row['id'],$wid,$uid,$market['id']]))
                    throw new InvalidArgumentException('تعذر حفظ العبارة؛ لم يتم تعديل الكلمة. حاول لاحقًا');
                return ['id'=>(int)$row['id'],'previous_keyword'=>$row['keyword'],'keyword'=>$phrase];
            });
            return $this->success($result);
        } catch (InvalidArgumentException $e) { return $this->error($e->getMessage(),422); }
    }
    private function saveKeywords(bool $bulk): array
    {
        try {
            [$wid,$uid,$service]=$this->context();
            $market=$service->resolve($wid,$uid,(int)$this->get('market_id',0));
            $words=$bulk?$this->get('keywords',[]):[$this->get('keyword','')];
            if (!is_array($words) || count($words)>100) throw new InvalidArgumentException('ضيف لحد 100 كلمة في المرة');
            $added=0;
            foreach ($words as $raw) {
                if (!is_string($raw) || trim($raw)==='' || mb_strlen(trim($raw))>255) throw new InvalidArgumentException('كلمة غير صالحة');
                if (DataForSeoRankingSource::keywordPhrase($raw)!==trim($raw)) throw new InvalidArgumentException('أضف عبارة البحث فقط، بدون نية البحث أو الأولوية بين الأقواس');
            }
            foreach (array_unique($words) as $raw) {
                $s=$this->db->getConnection()->prepare('INSERT IGNORE INTO tracked_keywords (website_id,user_id,market_id,keyword) VALUES (?,?,?,?)');
                $s->execute([$wid,$uid,$market['id'],trim($raw)]); $added+=$s->rowCount();
            }
            return $this->success(['added'=>$added]);
        } catch (InvalidArgumentException $e) { return $this->error($e->getMessage(),422); }
    }
    public function gapRefresh(array $params=[]): array
    {
        try { [$wid,$uid]=$this->context(); return $this->success(SeoKeywordGapService::refresh($this->db,$wid,$uid)); }
        catch (InvalidArgumentException $e) { return $this->error($e->getMessage(),422); }
    }
    public function adminSites(array $params=[]): array
    {
        if (!$this->isAuthenticated() || !AdminAccess::can($this->user,'settings.manage')) return $this->error('غير مسموح',403);
        return $this->success(['websites'=>$this->db->query('SELECT id,main_url,company_name FROM websites ORDER BY id DESC LIMIT 500')]);
    }
    public function index(array $params=[]): array
    {
        if (!$this->isAuthenticated()) return $this->error('يجب تسجيل الدخول',401);
        $admin=str_starts_with((string)($_SERVER['REQUEST_URI']??''),'/admin/');
        $body=<<<'HTML'
<div class="p-card" dir="rtl">
<h2>الكلمات المفتاحية والأسواق</h2>
<p>الأرقام من DataForSEO حسب البلد واللغة. — معناها مش مقاس (not measured). بيانات البحث بتتخزن 30 يوم، وفحص الترتيب مرة يوميًا. البحث والفحص المدفوع بيتحسبوا من حصة الباقة وبعدها المحفظة.</p>
<label>الموقع <select id="seoSite" class="form-control"></select></label>
<label>السوق <select id="seoMarket" class="form-control"></select></label>
<p id="seoStatus" role="status"></p>
<p id="seoInputReview" role="status" hidden>بعض الكلمات فيها تعليقات تحليل وليست عبارة بحث صافية. راجع العبارة المقترحة ثم نقِّ الكلمات غير المقاسة. التنقية مجانية ولا تبدأ بحثًا أو قياس ترتيب، والسجلات المقاسة لا تُعدَّل.</p>
<details><summary>إضافة سوق / تغيير السوق الرئيسي</summary>
<form id="marketForm"><label>كود موقع DataForSEO <input id="seoLocation" type="number" min="1" required placeholder="2826 = UK"></label>
<label>اللغة <input id="seoLanguage" required maxlength="12" placeholder="en"></label>
<label>اسم السوق <input id="seoLabel" required maxlength="100" placeholder="UK – English"></label>
<button class="p-btn" type="submit">إضافة السوق</button></form>
<button id="seoPrimary" class="p-btn">خلّي السوق المختار رئيسي</button><p id="seoMarketLimit"></p></details>
<div id="seoCustomer">
<label>كلمة تتابعها <input id="seoWord" maxlength="255"></label><button id="seoAdd" class="p-btn">إضافة للمتابعة</button>
<button id="seoResearch" class="p-btn primary">بحث كلمات حقيقية للموقع</button>
<button id="seoCheck" class="p-btn">فحص ترتيب الكلمات</button>
<button id="seoGap" class="p-btn">تحديث فجوة الكلمات مع المنافسين (حتى 50 كلمة)</button>
<h3>حجم البحث الشهري والترتيب حسب السوق</h3>
<div class="p-table-scroll"><table class="p-table"><thead><tr><th>الكلمة</th><th>حجم البحث</th><th>الصعوبة</th><th>CPC بالدولار</th><th>نية البحث</th><th>الترتيب</th><th>اتجاه 30 يوم</th><th>آخر قياس</th></tr></thead><tbody id="seoWords"></tbody></table></div>
<h3>نتائج البحث من DataForSEO</h3><div id="seoResearchRows"></div>
<h3>فجوة الكلمات مع المنافسين</h3><p>بنقارن السوق الرئيسي بس. الفجوة محتاجة ترتيب منافس من 1 لـ10، وحجم بحث حقيقي، وقياس حديث لموقعك خارج أول 20.</p>
<div class="p-table-scroll"><table class="p-table"><thead><tr><th>الكلمة</th><th>المنافس</th><th>حجم البحث</th><th>ترتيب المنافس</th><th>ترتيبك</th><th>المصدر والتاريخ</th></tr></thead><tbody id="seoGaps"></tbody></table></div>
</div></div>
HTML;
        $script=($admin?'const seoAdmin=true;':'const seoAdmin=false;').<<<'JS'
(async function(){
const {fetchJSON,esc,toast}=window.Panel;
const el=id=>document.getElementById(id);
const base=seoAdmin?'/api/admin/seo-data':'/api/seo/data';
let wid=0,mid=0,marketRequest=0,wordRequest=0;
const metric=v=>v===null||v===undefined?'—':esc(String(v));
async function post(url,data={}){return fetchJSON(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({website_id:wid,market_id:mid,...data})});}
function fail(r){if(!r.success){toast(r.error||'تعذر تنفيذ الطلب','error');return true;}return false;}
async function markets(){
 const siteId=wid,request=++marketRequest;mid=0;++wordRequest;el('seoWords').replaceChildren();el('seoGaps').replaceChildren();el('seoInputReview').hidden=true;
 const r=await fetchJSON(base+'/markets?website_id='+siteId);if(siteId!==wid||request!==marketRequest)return;if(fail(r))return;
 el('seoMarket').innerHTML=r.data.markets.map(m=>`<option value="${Number(m.id)}">${esc(m.label)}${Number(m.is_primary)?' ★':''}</option>`).join('');mid=Number(el('seoMarket').value);
 el('seoMarketLimit').textContent='الحد المتاح: '+r.data.limit+' سوق. السوق الافتراضي من لغة الموقع؛ راجعه حسب بلد الجمهور.';
 if(!seoAdmin)await words();
}
async function words(){
 const siteId=wid,marketId=mid,request=++wordRequest;
 const r=await fetchJSON('/api/seo/data/keywords?website_id='+siteId+'&market_id='+marketId);if(siteId!==wid||marketId!==mid||request!==wordRequest)return;if(fail(r))return;
 el('seoStatus').textContent=r.data.status.message||'المصدر مهيأ. الأرقام الناقصة مش مقاسة؛ حفظ الكلمات وحده لا يعني قياسها.';
 el('seoInputReview').hidden=!r.data.keywords.some(k=>k.suggested_phrase);
 el('seoWords').innerHTML=r.data.keywords.map(k=>`<tr><td>${esc(k.keyword)}${k.suggested_phrase?`<p>عبارة البحث المقترحة: <strong>${esc(k.suggested_phrase)}</strong></p><button class="p-btn" data-repair-keyword="${Number(k.id)}">راجعت العبارة — تنقية بدون قياس</button>`:''}</td><td>${metric(k.search_volume)}</td><td>${metric(k.difficulty)}</td><td>${metric(k.cpc)}</td><td>${metric(k.intent)}</td><td>${metric(k.current_position)}</td><td title="${esc(JSON.stringify(k.trend_30_days))}">${metric(k.trend)}</td><td>${metric(k.last_checked_at)}</td></tr>`).join('')||'<tr><td colspan="8">مفيش كلمات متابَعة — not measured</td></tr>';
 for(const button of el('seoWords').querySelectorAll('[data-repair-keyword]'))button.onclick=async()=>{
  const keyword=r.data.keywords.find(k=>Number(k.id)===Number(button.dataset.repairKeyword));
  if(!keyword||!confirm('تنقية عبارة البحث من:\n'+keyword.keyword+'\nإلى:\n'+keyword.suggested_phrase+'\nلن يبدأ بحث أو خصم رصيد.'))return;
  button.disabled=true;try{const result=await post('/api/seo/data/keywords/'+Number(keyword.id)+'/repair',{expected_keyword:keyword.keyword,reviewed:true});if(!fail(result))await words();}finally{if(button.isConnected)button.disabled=false;}
 };
 el('seoGaps').innerHTML=r.data.gaps.filter(g=>Number(g.market_id)===mid).map(g=>`<tr><td>${esc(g.keyword)}</td><td>${esc(g.competitor_domain)}</td><td>${metric(g.search_volume)}</td><td>${metric(g.position)}</td><td>${g.customer_position===null?'خارج أول 100':metric(g.customer_position)}</td><td>DataForSEO · ${esc(g.fetched_at)}</td></tr>`).join('')||'<tr><td colspan="6">لسه مفيش فجوة متحققة للسوق ده — not measured</td></tr>';
}
el('marketForm').onsubmit=async e=>{e.preventDefault();const r=await post(base+'/markets',{location_code:Number(el('seoLocation').value),language_code:el('seoLanguage').value.trim(),label:el('seoLabel').value.trim()});if(!fail(r))await markets();};
el('seoPrimary').onclick=async()=>{const r=await post(base+'/markets',{action:'primary'});if(!fail(r))await markets();};
el('seoMarket').onchange=async()=>{mid=Number(el('seoMarket').value);el('seoResearchRows').replaceChildren();if(!seoAdmin)await words();};
el('seoSite').onchange=async()=>{wid=Number(el('seoSite').value);el('seoResearchRows').replaceChildren();await markets();};
el('seoAdd').onclick=async()=>{const r=await post('/api/ai/keywords/add',{keyword:el('seoWord').value.trim()});if(!fail(r))await words();};
el('seoResearch').onclick=async function(){this.disabled=true;try{const r=await post('/api/seo/data/research');if(fail(r))return;if(!r.data.available){el('seoStatus').textContent=r.data.message||r.data.reason+' — not measured';return;}const box=el('seoResearchRows');box.replaceChildren();for(const k of r.data.data){const row=document.createElement('p');row.textContent=k.keyword+' | حجم البحث: '+(k.volume??'—')+' | الصعوبة: '+(k.difficulty??'—')+' | CPC: '+(k.cpc??'—')+' | النية: '+(k.intent??'—');const b=document.createElement('button');b.className='p-btn';b.textContent='تابع الكلمة';b.onclick=async()=>{const a=await post('/api/ai/keywords/add',{keyword:k.keyword});if(!fail(a))await words();};row.append(b);box.append(row);}await words();}finally{this.disabled=false;}};
el('seoCheck').onclick=async function(){this.disabled=true;try{const r=await post('/api/seo/rank-tracking/check');if(!fail(r))await words();}finally{this.disabled=false;}};
el('seoGap').onclick=async function(){this.disabled=true;try{const r=await post('/api/seo/data/gaps/refresh');if(!fail(r)){if(!r.data.available)toast(r.data.reason||'not measured','error');await words();}}finally{this.disabled=false;}};
if(seoAdmin)el('seoCustomer').hidden=true;
const r=await fetchJSON(seoAdmin?base+'/sites':'/api/websites');if(fail(r))return;
const sites=r.data.websites||[],requested=new URLSearchParams(location.search).get('website_id');
el('seoSite').innerHTML=sites.map(w=>`<option value="${Number(w.id)}">${esc(w.company_name||w.main_url||String(w.id))}</option>`).join('');
if(requested!==null){if(!/^[1-9]\d*$/.test(requested)||!sites.some(w=>Number(w.id)===Number(requested))){el('seoSite').selectedIndex=-1;el('seoStatus').textContent='الموقع المطلوب غير متاح في حسابك. اختر موقعًا من القائمة للمتابعة.';return;}el('seoSite').value=String(Number(requested));}
wid=Number(el('seoSite').value);if(wid)await markets();
})();
JS;
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_analyze','الكلمات والأسواق','بيانات بحث وترتيب حقيقية لكل سوق',$body,$script);
        return [];
    }
}
