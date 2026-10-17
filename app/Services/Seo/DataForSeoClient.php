<?php
/** The only authenticated DataForSEO transport. Never logs credentials or response bodies. */
class DataForSeoClient
{
    private array $settings;
    private $transport;
    private $ledger;
    public function __construct(?array $settings=null, ?callable $transport=null, $ledger=null)
    {
        if ($settings===null) {
            $s=new SystemSettingsService(); $settings=[];
            foreach (['enabled','login','password','monthly_budget_usd','local_enabled','backlinks_enabled'] as $key) $settings[$key]=$s->get('serp_'.$key);
        }
        $this->settings=$settings; $this->transport=$transport; $this->ledger=$ledger;
    }
    public function isConfigured(): bool
    {
        return (string)($this->settings['enabled']??'')==='1' && trim((string)($this->settings['login']??''))!=='' && trim((string)($this->settings['password']??''))!=='';
    }
    public function endpointEnabled(string $endpoint): bool {
        if (!$this->isConfigured()) return false;
        return match($endpoint) {
            'serp/google/maps/live/advanced'=>($this->settings['local_enabled']??'0')==='1',
            'backlinks/referring_domains/live'=>($this->settings['backlinks_enabled']??'0')==='1',
            default=>true,
        };
    }
    public function call(string $endpoint, array $tasks, int $websiteId, int $units, float $ceiling): array
    {
        if (!$this->isConfigured()) return ['available'=>false,'reason'=>'not_measured','message'=>'اربط DataForSEO من الأدمن'];
        $allowed=['serp/google/maps/live/advanced','backlinks/referring_domains/live','serp/google/organic/live/advanced','dataforseo_labs/google/keyword_ideas/live','dataforseo_labs/google/keyword_overview/live','dataforseo_labs/google/keywords_for_site/live','dataforseo_labs/google/bulk_keyword_difficulty/live','dataforseo_labs/google/ranked_keywords/live'];
        if (!in_array($endpoint,$allowed,true) || !$tasks || $units<1 || $units>100 || !is_finite($ceiling) || $ceiling<=0) throw new InvalidArgumentException('Invalid SEO request');
        if ($endpoint==='serp/google/maps/live/advanced' && ($this->settings['local_enabled']??'0')!=='1') return ['available'=>false,'reason'=>'local_maps_disabled'];
        if (str_starts_with($endpoint,'backlinks/') && ($this->settings['backlinks_enabled']??'0')!=='1') return ['available'=>false,'reason'=>'backlinks_provider_disabled'];
        $ledger=$this->ledger ?? new SeoDataLedger();
        $id=null;
        try {
            $id=$ledger->reserve($endpoint,$websiteId,$units,$ceiling,(float)($this->settings['monthly_budget_usd']??0));
            $response=$this->transport ? ($this->transport)($endpoint,$tasks) : $this->send($endpoint,$tasks);
            // Cost is the response's total, not total + per-task cost (which double counts).
            $cost=isset($response['cost']) && is_numeric($response['cost']) ? (float)$response['cost'] : null;
            if ($cost===null && isset($response['tasks']) && is_array($response['tasks'])) {
                $cost=0.0;
                foreach ($response['tasks'] as $task) {
                    if (!isset($task['cost']) || !is_numeric($task['cost'])) { $cost=null; break; }
                    $cost+=(float)$task['cost'];
                }
            }
            if ($cost!==null && (!is_finite($cost)||$cost<0)) $cost=null;
            $valid=($response['status_code']??0)===20000 && count($response['tasks']??[])===count($tasks);
            foreach ($response['tasks']??[] as $task) $valid=$valid && ($task['status_code']??0)===20000 && is_array($task['result']??null);
            $ledger->settle($id,$cost,$valid?'complete':'provider_error');
            return $valid ? ['available'=>true,'response'=>$response,'cost'=>$cost] : ['available'=>false,'reason'=>'dataforseo_invalid_response'];
        } catch (Throwable $e) {
            if ($id!==null) {
                try { $ledger->settle($id,null,'unknown'); }
                catch (Throwable $ignored) { /* The durable reservation remains in place. */ }
            }
            $known=['seo_budget_busy','seo_monthly_budget_reached','seo_quota_and_wallet_exhausted','website_not_found'];
            return ['available'=>false,'reason'=>in_array($e->getMessage(),$known,true)?$e->getMessage():'dataforseo_request_failed'];
        }
    }
    private function send(string $endpoint,array $tasks): array
    {
        $ch=curl_init('https://api.dataforseo.com/v3/'.$endpoint);
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($tasks,JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_USERPWD=>$this->settings['login'].':'.$this->settings['password'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>120,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        $body=curl_exec($ch); $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        if ($body===false || $http!==200) throw new RuntimeException('DataForSEO transport failed');
        $r=json_decode($body,true,512,JSON_THROW_ON_ERROR);
        if (!is_array($r)) throw new RuntimeException('Invalid provider JSON');
        return $r;
    }
}
