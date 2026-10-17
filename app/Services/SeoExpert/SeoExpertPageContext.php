<?php
require_once __DIR__.'/../AutoFix/PageContext.php';
/** Bounded HTML-only fetches, using the existing per-hop SSRF/DNS-pinning transport. */
class SeoExpertPageContext extends PageContext
{
    private string $robotsHeader='';
    private bool $nativeOwner=false;
    private bool $wordpress=false;
    protected function isImage(string $url): bool { return false; } // No image URL is presented as verified.
    private bool $publicationRead=false;
    protected function textLimit(): int { return $this->publicationRead?60000:2500; }
    /** Full public text for verification only; planning keeps its existing bounded excerpt. */
    public function freshPublication(string $url): array {
        $previous=$this->publicationRead;$this->publicationRead=true;
        try{return $this->fresh($url);}finally{$this->publicationRead=$previous;}
    }
    protected function get(string $url,bool $withBody,string $method='GET'): array {
        $body='';$headers=[];
        $r=SafeHttp::exec($url,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_ENCODING=>'',CURLOPT_NOBODY=>$method==='HEAD',CURLOPT_USERAGENT=>'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml','Accept-Language: en-US,en;q=0.9'],
            CURLOPT_HEADERFUNCTION=>static function($c,$line)use(&$body,&$headers){if(preg_match('#^HTTP/#i',$line)){$headers=[];$body='';}elseif(strpos($line,':')!==false){[$k,$v]=explode(':',$line,2);$headers[strtolower(trim($k))]=trim($v);}return strlen($line);},
            CURLOPT_WRITEFUNCTION=>static function($c,$chunk)use(&$body,$withBody){if($withBody)$body.=$chunk;return strlen($body)>1500000?0:strlen($chunk);}],4);
        if($withBody&&class_exists('DOMDocument')){$doc=new DOMDocument();$old=libxml_use_internal_errors(true);try{if($doc->loadHTML($body,LIBXML_NONET)){$xp=new DOMXPath($doc);$this->nativeOwner=$xp->query('//head/meta[@name="tourfecto-seo-owner" and @content="native"]')->length>0;}}finally{libxml_clear_errors();libxml_use_internal_errors($old);}}
        $this->wordpress=$withBody && preg_match('~wp-content/|wp-includes/|wp-json/|name=["\']generator["\'][^>]*WordPress~i',$body)===1;
        $this->robotsHeader=(string)($headers['x-robots-tag']??'');
        return ['http'=>$r['code'],'body'=>$body,'headers'=>$headers,'error'=>$r['error']!==''?'fetch_failed':null,'final_url'=>$r['url']?:$url];
    }
    public function fresh(string $url): array {
        $this->nativeOwner=false;$this->robotsHeader='';$started=microtime(true);$r=$this->fetch($url);$r['fetch_seconds']=round(microtime(true)-$started,3);$r['url']=$url;$r['wordpress']=$this->wordpress;$r['robots_header']=$this->robotsHeader;$r['native_seo_owner']=!empty($r['ok'])&&$this->nativeOwner;if($this->robotsHeader!=='' && !empty($r['ok']))$r['robots']=trim((string)($r['robots']??'').' '.strtolower($this->robotsHeader));
        if(isset($r['final_url'])){$r['final_url']=preg_replace('/([?&])_tourfecto_audit=[a-f0-9]+(?:&|$)/','$1',$r['final_url']);$r['final_url']=rtrim(str_replace('?&','?',$r['final_url']),'?&');}
        return $r;
    }
}
