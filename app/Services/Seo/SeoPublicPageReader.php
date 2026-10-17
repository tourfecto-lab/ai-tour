<?php
/** Bounded public GET, no credentials and per-hop SafeHttp verification. */
class SeoPublicPageReader {
    public function __construct(private $transport=null) {}
    public function fetch(string $url): array {
        if($this->transport)return ($this->transport)($url);
        $html='';$r=SafeHttp::exec($url,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_ENCODING=>'',
            CURLOPT_HEADERFUNCTION=>static function($c,$line)use(&$html){if(str_starts_with($line,'HTTP/'))$html='';return strlen($line);},
            CURLOPT_WRITEFUNCTION=>static function($c,$chunk)use(&$html){if(strlen($html)+strlen($chunk)>1000000)return 0;$html.=$chunk;return strlen($chunk);}],3);
        return ['success'=>$r['error']===''&&$r['code']===200,'http_status'=>$r['code'],'html'=>$html,'url'=>$r['url']??$url,'error'=>$r['error']!==''?'public_fetch_unavailable':null];
    }
}
