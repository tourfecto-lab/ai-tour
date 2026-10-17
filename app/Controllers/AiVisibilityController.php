<?php
/** Customer-only, tenant-scoped GEO page and actions. No provider credentials enter this controller. */
class AiVisibilityController extends Controller
{
    public function data(array $params=[]): array{return $this->action('overview');}
    public function generate(array $params=[]): array{return $this->action('generate');}
    public function save(array $params=[]): array{return $this->action('save');}
    public function entities(array $params=[]): array{return $this->action('entities');}
    public function enqueue(array $params=[]): array{return $this->action('enqueue');}
    public function tasks(array $params=[]): array{return $this->action('tasks');}
    public function approveTask(array $params=[]): array{return $this->action('approveTask');}
    public function llms(array $params=[]): array{return $this->action('llms');}
    private function lines($raw): array
    {
        if(!is_string($raw)||strlen($raw)>18000)throw new InvalidArgumentException('قائمة غير صالحة');
        return array_values(array_filter(array_map('trim',preg_split('/\R/u',$raw)),fn($v)=>$v!==''));
    }
    private function action(string $action): array
    {
        if(!$this->isAuthenticated())return $this->error('سجل الدخول الأول',401);
        try{
            $uid=(int)$this->user['id'];$wid=(int)$this->get('website_id');$mid=(int)$this->get('market_id',0);$s=new AiVisibilityService($this->db);$s->site($uid,$wid);
            switch($action){
                case 'generate':$out=$s->generate($uid,$wid,$mid);break;
                case 'save':$out=$s->save($uid,$wid,$mid,$this->lines($this->get('questions','')),(int)$this->get('version',0),$this->get('approved',false)===true);break;
                case 'entities':$out=$s->entities($uid,$wid,$this->lines($this->get('aliases','')),$this->lines($this->get('profiles','')),(string)$this->get('address',''),(int)$this->get('version',0),$this->get('confirmed',false)===true);break;
                case 'enqueue':$out=$s->enqueue($uid,$wid,$mid);break;
                case 'tasks':$out=['tasks'=>$s->generateTasks($uid,$wid,$mid)];break;
                case 'approveTask':$s->approveTask($uid,$wid,(int)$this->get('task_id'));$out=['reviewed'=>true];break;
                case 'llms':$out=$s->llmsDraft($uid,$wid);break;
                default:$out=$s->overview($uid,$wid,$mid);$out['quota']=PlanUsage::quota($uid,'ai_visibility');$price=(new WalletService())->getUsagePricing()['ai_visibility']??null;$out['overage_price']=$price&&(float)$price['price']>0&&$price['currency']==='USD'?(float)$price['price']:null;
            }
            return $this->success($out);
        }catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}
        catch(Throwable $e){return $this->error('تعذر إتمام الطلب؛ راجع قاعدة البيانات وإعدادات الطابور أو جرّب تاني. لم يتم اختلاق قياس.',503);}
    }
    public static function pageBody(): string
    {
        return <<<'HTML'
<div class="p-card" dir="rtl" id="geoPage">
<h2>ظهورك في الذكاء الصناعي</h2>
<p>عينة أسبوعية من إجابات API بالبحث على الويب. مش ترتيب ثابت في تطبيقات ChatGPT أو Gemini، ومش ضمان لذكر شركتك. كل سؤال × مزوّد × سوق = وحدة من الباقة وبعدها المحفظة.</p>
<label>الموقع <select id="geoSite" class="form-control"></select></label>
<label>السوق <select id="geoMarket" class="form-control"></select></label>
<p><a href="/ai/keywords">إدارة الأسواق والكلمات الحقيقية</a> · <a href="/business-hub">تأكيد بيانات الشركة</a></p>
<p id="geoStatus" role="status" aria-live="polite">جاري التحميل…</p><div id="geoSetup"></div><p id="geoQuota"></p>
<div id="geoCards" style="display:flex;flex-wrap:wrap;gap:16px"></div>
<details><summary>الأسئلة والموافقة</summary><p>10–30 سؤال شراء واقعي، سؤال في كل سطر، من غير اسم شركتك أو دومينها. التوليد يستخدم حقائق الشركة المؤكدة وكلمات DataForSEO الحديثة. للغات غير المدعومة اكتب الأسئلة وراجعها بنفسك.</p>
<button id="geoGenerate" class="p-btn">توليد مسودة أسئلة</button><label>الأسئلة <textarea id="geoQuestions" class="form-control" rows="15" maxlength="18000"></textarea></label>
<label><input type="checkbox" id="geoApprove"> راجعت الأسئلة وبوافق على قياسها أسبوعيًا</label><button id="geoSave" class="p-btn">حفظ الأسئلة</button><p id="geoApproval"></p></details>
<button id="geoRun" class="p-btn">إضافة قياس الأسبوع للطابور (مدفوع)</button><button id="geoReload" class="p-btn">تحديث النتائج</button>
<h3>اتجاه الظهور خلال 8 أسابيع</h3><p>الفراغ يعني مش مقاس. تغيّر الأسئلة أو الأسواق يؤثر على المقارنة.</p><div id="geoTrend"></div>
<h3>إجابات الأسئلة ومصادرها</h3><div style="overflow-x:auto"><table class="p-table"><thead><tr><th>المزوّد / التاريخ</th><th>السؤال</th><th>ذكر الشركة</th><th>استشهاد بدومينك</th><th>مين اتذكر</th><th>المصادر والإجابة</th></tr></thead><tbody id="geoAnswers"></tbody></table></div>
<h3>مهام تحسين من الأدلة</h3><p>مهام للمراجعة والتنفيذ اليدوي. الموافقة هنا ما بتنشرش أي تعديل. تغييرات الموقع تمر بموافقة وتحقق مباشر وإمكانية التراجع من تدقيق الموقع.</p><button id="geoTasksRefresh" class="p-btn">تحديث المقارنة بالصفحات العامة</button><div id="geoTasks"></div>
<details><summary>الأسماء العربية والإنجليزية وروابط حسابات الشركة</summary><p>أدخل الأسماء البديلة والعنوان وروابط حسابات شركتك الحقيقية فقط. تغييرها يلغي موافقات الأسئلة لتراجعها تاني. Organization sameAs يستخدم الروابط اللي بتدخلها وتأكدها هنا فقط.</p><label>أسماء بديلة، اسم في كل سطر<textarea id="geoAliases" class="form-control" rows="3"></textarea></label><label>روابط HTTPS، رابط في كل سطر<textarea id="geoProfiles" class="form-control" rows="4"></textarea></label><label>العنوان المؤكد<input id="geoAddress" class="form-control" maxlength="500"></label><label><input type="checkbox" id="geoEntityConfirm"> دي أسماء وروابط وعنوان شركتي وأكدتها</label><button id="geoEntitySave" class="p-btn">حفظ البيانات المؤكدة</button></details>
<details><summary>مسودة llms.txt من الموقع المنشور</summary><p>رحلات وروابط وملخصات وأسعار كما تظهر على الصفحات. الملف لا يضمن تحسن ترتيب أو استشهاد. انشر بعد المراجعة من إصلاح llms.txt في تدقيق الموقع.</p><button id="geoLlms" class="p-btn">قراءة الصفحات وبناء المسودة</button><pre id="geoLlmsDraft" style="white-space:pre-wrap" dir="auto"></pre></details>
</div>
HTML;
    }
    public function index(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('سجل الدخول الأول',401);
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('ai_visibility','ظهورك في الذكاء الصناعي','قياس فعلي وأسئلة ومهام لكل سوق',self::pageBody(),file_get_contents(dirname(__DIR__,2).'/public_html/assets/js/ai-visibility.js'));return [];
    }
}
