<?php
/** Google organic SERP adapter. Explicit paid-service opt-in; no generated/mocked ranks. */
class DataForSeoRankingSource implements KeywordRankingSourceInterface
{
    private array $settings; private $transport;
    public function __construct(?array $settings=null,?callable $transport=null){
        if($settings===null){$s=new SystemSettingsService();$settings=[];foreach(['enabled','login','password','location_code','language_code','device'] as $k)$settings[$k]=$s->get('serp_'.$k);}
        $this->settings=$settings;$this->transport=$transport;
    }
    public function isConfigured(): bool{return ($this->settings['enabled']??'')==='1'&&trim($this->settings['login']??'')!==''&&trim($this->settings['password']??'')!==''&&(int)($this->settings['location_code']??0)>0&&preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D',$this->settings['language_code']??'')===1;}
    public function sourceName(): string{return 'dataforseo_google_organic';}
    public function check(string $domain,array $keywords): array{
        $bad=fn($reason)=>['available'=>false,'reason'=>$reason,'rankings'=>[]];
        if(!$this->isConfigured())return $bad('no_keyword_ranking_source_configured');
        $domain=strtolower(rtrim($domain,'.'));if(!filter_var($domain,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME))return $bad('invalid_domain');
        $keywords=array_values(array_unique(array_filter(array_map('trim',$keywords))));if(count($keywords)>20)return $bad('rank_batch_limit_20');
        if(!$keywords)return ['available'=>true,'reason'=>null,'rankings'=>[]];
        $rankings=[];
        foreach($keywords as $keyword){
            if(mb_strlen($keyword)>255)return $bad('invalid_keyword');
            $task=['keyword'=>$keyword,'location_code'=>(int)$this->settings['location_code'],'language_code'=>$this->settings['language_code'],'device'=>($this->settings['device']??'desktop')==='mobile'?'mobile':'desktop','depth'=>100];
            if($this->transport)$response=($this->transport)([$task]);else{
                if(!function_exists('curl_init'))return $bad('curl_required');
                $ch=curl_init('https://api.dataforseo.com/v3/serp/google/organic/live/advanced');
                curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode([$task],JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_USERPWD=>$this->settings['login'].':'.$this->settings['password'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>120,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
                $body=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
                if($body===false||$http!==200)return $bad('rank_source_http_failure');$response=json_decode($body,true);
            }
            $t=$response['tasks'][0]??null;$r=$t['result'][0]??null;
            if(($response['status_code']??0)!==20000||($t['status_code']??0)!==20000||!is_array($r)||!array_key_exists('items',$r))return $bad('rank_source_invalid_response');
            $position=null;$url=null;
            foreach(($r['items']??[]) as $item){if(($item['type']??'')!=='organic')continue;$host=strtolower(rtrim((string)($item['domain']??parse_url((string)($item['url']??''),PHP_URL_HOST)),'.'));
                if($host!==$domain&&!str_ends_with($host,'.'.$domain))continue;$p=(int)($item['rank_group']??0);if($p>=1&&$p<=100&&($position===null||$p<$position)){$position=$p;$url=(string)($item['url']??'');}}
            $rankings[]=['keyword'=>$keyword,'position'=>$position,'url'=>$url];
        }
        return ['available'=>true,'reason'=>null,'rankings'=>$rankings];
    }
}
