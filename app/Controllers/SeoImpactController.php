<?php
require_once __DIR__.'/../Services/SeoImpact/SeoImpactService.php';
final class SeoImpactController extends Controller {
    private function action(string $op,array $p): array {
        header('Cache-Control: no-store, private');if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        if($op!=='overview'){if($e=$this->verifyCsrf())return $e;if($e=$this->rateLimitGuard('user','seo_impact_write',10,60))return $e;}
        $uid=(int)$this->user['id'];$wid=(int)($p['website_id']??0);$s=new SeoImpactService();
        try {
            if($op==='overview')return $this->success($s->overview($uid,$wid));
            if($op==='create')return $this->success($s->create($uid,$wid,$this->data));
            if($op==='refresh')return $this->success($s->refresh($uid,(int)($p['id']??0)));
            if($op==='ai')return $this->success($s->importAi($uid,$wid,$this->data));
            if($op==='bookings')return $this->success($s->configureBookings($uid,$wid,($this->data['enabled']??false)===true));
            return $this->error('Unsupported operation',422);
        }catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}
        catch(Throwable $e){Logger::warning('SEO measurement needs review',['user_id'=>$uid,'website_id'=>$wid,'operation'=>$op]);return $this->error('Measurement is temporarily unavailable. Your saved work is retained; retry or contact support. / القياس غير متاح مؤقتًا. عملك محفوظ؛ أعد المحاولة أو تواصل مع الدعم.',503);}
    }
    public function overview(array $p=[]): array{return $this->action('overview',$p);}
    public function create(array $p=[]): array{return $this->action('create',$p);}
    public function refresh(array $p=[]): array{return $this->action('refresh',$p);}
    public function ai(array $p=[]): array{return $this->action('ai',$p);}
    public function bookings(array $p=[]): array{return $this->action('bookings',$p);}
    public function ingest(array $p=[]): array {
        header('Cache-Control: no-store');if($e=$this->rateLimitGuard('ip','seo_paid_webhook',60,60))return $e;
        try{(new SeoImpactService())->ingest((int)($p['website_id']??0),(string)($_SERVER['HTTP_X_TOURFECTO_SECRET']??''),$this->data);return $this->success(['received'=>true]);}
        catch(InvalidArgumentException $e){return $this->error('Booking source or payload rejected.',403);}
        catch(Throwable $e){return $this->error('Receipt could not be confirmed. Retry the same booking key later.',503);}
    }
}
