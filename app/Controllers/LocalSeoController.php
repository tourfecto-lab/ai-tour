<?php
class LocalSeoController extends Controller
{
    public function index(array $p=[]): void {
        if(!$this->isAuthenticated()){header('Location: /login');exit;}
        $body='<section id="localSeo"><h2>البحث المحلي والروابط</h2><p>شبكة 5×5 حول موقع Google Business المؤكد. كل شبكة تستخدم 50–75 طلب قياس مدفوع من حصة SEO ثم المحفظة، مع سقف الإنفاق الحالي. حد الشبكات الشهري مشترك بين مواقع الحساب.</p><label>الموقع <select id="localSite"></select></label><button id="localLoad">تحديث النتائج</button><p id="localStatus" role="status"></p><label>روابط أدلة شركتك فقط، رابط HTTPS في كل سطر<textarea id="localDirectories" rows="3"></textarea></label><label><input id="localConfirm" type="checkbox"> أؤكد أن ملف Google Business وروابط الأدلة تخص شركتي</label><button id="localProfile">تأكيد موقع GBP</button><button id="localNap">مقارنة بيانات الشركة</button><label>كلمتان أو ثلاث، كلمة في كل سطر<textarea id="localKeywords" rows="3"></textarea></label><label>المسافة بين النقاط بالكيلومتر <input id="localSpacing" type="number" min="0.25" max="5" step="0.25" value="1"></label><label><input id="localPaid" type="checkbox"> أوافق على استهلاك طلبات القياس المدفوعة حتى 75 طلبًا؛ الغياب عن النتائج ليس ترتيبًا صفرًا</label><button id="localRun">بدء شبكة قياس</button><label>السجل الشهري <select id="localHistory"></select></label><div id="localGrid"></div><div id="localTasks"></div><button id="localLinks">بحث فجوة الروابط الحقيقي (مدفوع؛ يستخدم الكاش عند توفره)</button><div id="localLinkGap"></div><h3>التقرير الشهري</h3><p>اختر تردد monthly من جدولة تقارير SEO الحالية لإرسال التقرير العربي إلى بريدك.</p><button id="localReport">فتح نسخة قابلة للطباعة / PDF</button></section><script defer src="'.htmlspecialchars(asset_v('/assets/js/local-seo.js'),ENT_QUOTES,'UTF-8').'"></script>';
        echo $this->renderPanelPage('seo_expert','Local SEO','قياسات محلية وروابط موثقة وتقارير شهرية',$body);exit;
    }
    private function action(string $op): array {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);header('Cache-Control: no-store, private');
        if(!in_array($op,['sites','data'],true)){if($e=$this->verifyCsrf())return $e;if($e=$this->rateLimitGuard('user','local_seo_write',10,60))return $e;}
        $uid=(int)$this->user['id'];$wid=(int)$this->get('website_id',0);
        try{
            if($op==='sites')return $this->success(['sites'=>$this->db->query('SELECT id,main_url FROM websites WHERE user_id=? ORDER BY id DESC',[$uid])]);
            $site=(new SeoMarketService($this->db))->site($wid,$uid);
            if($op==='data')return $this->success(['runs'=>(new LocalRankGridService($this->db))->history($uid,$wid),'tasks'=>$this->db->query('SELECT source_url,evidence_json,status FROM seo_citation_tasks WHERE website_id=? AND user_id=? ORDER BY id DESC LIMIT 50',[$wid,$uid])]);
            if($op==='profile'){
                if($this->get('confirmed',false)!==true)throw new InvalidArgumentException('Confirm the business location and profiles');
                $connection=(new GbpSyncService())->findConnection($wid,$uid);if(!$connection||$connection->getAttribute('status')!=='connected')throw new InvalidArgumentException('Connect Google Business first');
                $token=(new GoogleReviewSyncService())->getValidAccessToken($connection);
                $r=(new GoogleBusinessAPI($token,$connection->getAttribute('external_account_id'),$connection->getAttribute('external_location_id')))->getLocation();
                if(empty($r['success']))throw new RuntimeException('GBP profile unavailable');$profile=$r['location'];
                // Name/phone/address checks do not require a customer-facing Maps pin.
                // A rank grid still validates coordinates separately before any paid work.
                try{LocalRankGridService::gridForProfile($profile);$mapsReady=true;}catch(InvalidArgumentException $e){$mapsReady=false;}
                $query=[];parse_str((string)parse_url($profile['maps_uri']??'',PHP_URL_QUERY),$query);$profile['cid']=isset($query['cid'])&&preg_match('/^[0-9]+$/D',(string)$query['cid'])?(string)$query['cid']:null;
                $dirs=$this->get('directory_profiles',[]);if(!is_array($dirs)||count($dirs)>15)throw new InvalidArgumentException('Maximum fifteen directory profiles');
                foreach($dirs as $url)if(!is_string($url)||strlen($url)>1800||!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https'||parse_url($url,PHP_URL_USER)||parse_url($url,PHP_URL_PASS))throw new InvalidArgumentException('Use public HTTPS directory profile URLs without credentials');
                $profile['directory_profiles']=array_values(array_unique($dirs));$profile['confirmed_at']=gmdate('c');
                $this->db->exec('REPLACE INTO seo_local_profiles(website_id,user_id,profile_json,updated_at) VALUES(?,?,?,UTC_TIMESTAMP())',[$wid,$uid,json_encode($profile,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
                return $this->success(['profile'=>$profile,'maps_grid_ready'=>$mapsReady]);
            }
            if($op==='run'){
                if($this->get('paid_confirmed',false)!==true)throw new InvalidArgumentException('Confirm paid Maps measurement first');
                $keywords=$this->get('keywords',[]);if(!is_array($keywords))throw new InvalidArgumentException('Provide keywords');
                return $this->success((new LocalRankGridService($this->db))->start($uid,$wid,$keywords,(float)$this->get('spacing_km',1)));
            }
            if($op==='nap')return $this->success((new NapConsistencyService($this->db))->check($uid,$wid));
            if($op==='links')return $this->success((new DataForSeoLinkGapService($this->db))->discover($uid,$wid));
            return $this->error('Unsupported operation',422);
        }catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}catch(Throwable $e){Logger::warning('Local SEO unavailable',['operation'=>$op,'website_id'=>$wid]);return $this->error('راجع ترحيل Phase 5 والربط والمزوّد وسقف الإنفاق',503);}
    }
    public function sites(array $p=[]): array{return $this->action('sites');} public function data(array $p=[]): array{return $this->action('data');}
    public function profile(array $p=[]): array{return $this->action('profile');} public function run(array $p=[]): array{return $this->action('run');}
    public function nap(array $p=[]): array{return $this->action('nap');} public function links(array $p=[]): array{return $this->action('links');}
    public function report(array $p=[]): void {
        if(!$this->isAuthenticated()){http_response_code(401);exit;}$uid=(int)$this->user['id'];$wid=(int)$this->get('website_id');
        try{$site=(new SeoMarketService($this->db))->site($wid,$uid);$data=(new SeoMonthlyReportService($this->db))->data($uid,$wid);header('Cache-Control: no-store, private');header('Content-Type: text/html; charset=utf-8');echo SeoMonthlyReportService::html($data,$site['main_url']);}catch(Throwable $e){http_response_code(503);echo 'التقرير غير متاح؛ راجع الربط والترحيل.';}
        // This is a complete HTML response; the API dispatcher must not replace its Content-Type or status.
        exit;
    }
}
