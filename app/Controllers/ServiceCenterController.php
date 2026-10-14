<?php
class ServiceCenterController extends Controller
{
    private function actor(): int{return (int)($this->user['id']??0);}
    public function index(array $params=[]): array {
        if(!$this->actor())return $this->error('Unauthorized',401);$csrf=htmlspecialchars(Csrf::token(),ENT_QUOTES,'UTF-8');
        $body='<link rel="stylesheet" href="/assets/css/service-center.css"><div id="serviceCenter" data-csrf="'.$csrf.'"><div class="sc-toolbar"><label>Find a service<input id="scSearch" type="search" placeholder="Bookings, email, payments…"></label><button id="scRefresh" type="button">Refresh status</button></div><p id="scStatus" role="status" aria-live="polite"></p><div id="scRuntime"></div><nav id="scTabs" aria-label="Service center tools"></nav><section id="scTools"></section><div id="scServices" class="sc-grid"></div></div><script src="/assets/js/service-center.js"></script>';
        echo $this->renderPanelPage('service_center','Service center','Service readiness, delivery recovery and operation history',$body,'');exit;
    }
    public function action(array $params=[]): array {
        if(!$this->actor())return $this->error('Unauthorized',401);$a=(string)($params['action']??'overview');$reads=['overview','sessions','usage','operations','deliveries','refund-quote','refund-requests','rates','agency-domains','agency-domain','wallet-statement','payment-timeline','customer-timeline'];
        $read=in_array($a,$reads,true);if(($_SERVER['REQUEST_METHOD']??'GET')!==($read?'GET':'POST'))return $this->error('Method not allowed',405);
        if(!$read&&($e=$this->verifyCsrf()))return $e;if($e=$this->rateLimitGuard('user','service_center',30,60))return $e;
        try{$svc=new ServiceCenterService();$scope=$svc->scope($this->actor(),!$read&&!in_array($a,['revoke-session'],true),in_array($a,['usage','refund-quote','refund-requests','wallet-statement','payment-timeline'],true));$uid=$scope['owner_id'];$actor=$this->actor();
            $data=match($a){
                'overview'=>$svc->overview($actor),
                'sessions'=>['sessions'=>$svc->sessions($actor)],
                'revoke-session'=>$svc->revokeSession($actor,(int)$this->get('id')),
                'usage'=>$svc->usage($actor),
                'payment-timeline'=>$svc->paymentTimeline($actor,(int)$this->get('booking_id')),
                'customer-timeline'=>$svc->customerTimeline($actor,(int)$this->get('contact_id')),
                'operations'=>['operations'=>$svc->operations($actor)],
                'record-check'=>$svc->recordCheck($actor,(string)$this->get('service'),(string)$this->get('check'),(string)$this->get('evidence',''),filter_var($this->get('remove',false),FILTER_VALIDATE_BOOLEAN)),
                'deliveries'=>['replies'=>(new ApprovedReplyDelivery())->listing($uid)],
                'retry-reply'=>(new ApprovedReplyDelivery())->approve((int)$this->get('id'),$uid),
                'resolve-reply'=>(new ApprovedReplyDelivery())->resolve($uid,(int)$this->get('id'),(string)$this->get('outcome'),(string)$this->get('evidence')),
                'refund-quote'=>(new BookingRefundPolicy())->quote($uid,(int)$this->get('booking_id')),
                'refund-requests'=>['requests'=>(new BookingRefundPolicy())->listing($uid)],
                'request-refund'=>(new BookingRefundPolicy())->request($uid,$actor,$this->all()),
                'review-refund'=>(new BookingRefundPolicy())->review($uid,$actor,(int)$this->get('id'),$this->get('approve')===true),
                'rates'=>$svc->rates($actor),
                'save-rate'=>$svc->saveRate($actor,$this->all()),
                'delete-rate'=>$svc->deleteRate($actor,(int)$this->get('id')),
                'agency-domains'=>['agencies'=>(new AgencyDomainSetup())->listing($uid)],
                'agency-domain'=>(new AgencyDomainSetup())->status($uid,(int)$this->get('agency_id')),
                'save-agency-domain'=>(new AgencyDomainSetup())->save($uid,(int)$this->get('agency_id'),(string)$this->get('domain')),
                'verify-agency-domain'=>(new AgencyDomainSetup())->verify($uid,(int)$this->get('agency_id')),
                'check-agency-https'=>(new AgencyDomainSetup())->checkHttps($uid,(int)$this->get('agency_id')),
                'scan-ticket'=>$this->scanTicket($uid,$svc,(string)$this->get('ticket')),
                'wallet-statement'=>$this->statement($uid,$svc),
                default=>throw new InvalidArgumentException('Unknown service center action'),
            };return $this->success($data);
        }catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}catch(PDOException $e){Logger::error('Service center storage unavailable');return $this->error('Service data is unavailable. Check the new SQL installation.',503);}catch(Throwable $e){$code=in_array($e->getCode(),[401,403,404],true)?$e->getCode():409;return $this->error(CustomerError::message($e->getMessage(),$code),$code);}
    }
    private function scanTicket(int $uid,ServiceCenterService $svc,string $ticket): array {
        $ticket=trim($ticket);if(preg_match('~^https?://~i',$ticket)){$ticket=basename((string)parse_url($ticket,PHP_URL_PATH));}
        if(!preg_match('/^[a-f0-9]{32}$/D',$ticket))throw new InvalidArgumentException('Scan or paste a Tourfecto ticket code');
        $id=$svc->q('SELECT id FROM bookings WHERE ticket_token=? AND user_id=?',[$ticket,$uid])->fetchColumn();if(!$id)throw new RuntimeException('Ticket not found in this workspace',404);
        return (new TicketService())->checkIn($uid,(int)$id);
    }
    private function statement(int $uid,ServiceCenterService $svc): array {
        $rows=$svc->q('SELECT id,type,amount,currency,status,created_at FROM wallet_transactions WHERE user_id=? ORDER BY id DESC LIMIT 5000',[$uid])->fetchAll(PDO::FETCH_ASSOC);
        return ['transactions'=>$rows,'limit'=>5000,'note'=>'Most recent 5,000 wallet movements. Currency and status are preserved.'];
    }
    public function domainProof(array $params=[]): array {
        $a=AgencyDomainSetup::publicAgency((string)($_SERVER['HTTP_HOST']??''));if(!$a)return $this->error('Domain not verified',404);
        header('Cache-Control: no-store');return ['agency_id'=>(int)$a['id'],'verification'=>$a['verification_token']];
    }
    public static function agencyLanding(array $a): void {
        $e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$name=$e($a['name']);$color=preg_match('/^#[a-f0-9]{6}$/i',(string)$a['primary_color'])?$a['primary_color']:'#b9974b';$base=rtrim(defined('APP_URL')?(string)APP_URL:'','/');$login=$e($base.'/login');
        header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');
        echo '<!doctype html><html lang="en" dir="ltr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$name.'</title><style>body{margin:0;background:#07101f;color:#fff;font:18px system-ui;display:grid;place-items:center;min-height:100vh}main{max-width:620px;padding:40px;text-align:center}a{display:inline-block;background:'.$color.';color:#07101f;padding:14px 22px;border-radius:12px;text-decoration:none}p{color:#c0cad8;line-height:1.7}</style><main><h1>'.$name.'</h1><p>Tourism services and client workspace</p><a href="'.$login.'">Client sign in</a><p>Your account opens securely on the platform.</p></main></html>';exit;
    }
}
