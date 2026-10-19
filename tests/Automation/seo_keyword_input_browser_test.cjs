const fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
(async()=>{let n=0;const check=(ok,label)=>{if(!ok)throw Error(label);n++;console.log('PASS '+label);};
const root=path.resolve(__dirname,'../..'),source=fs.readFileSync(path.join(root,'app/Controllers/SeoDataController.php'),'utf8'),markup=source.match(/\$body=<<<'HTML'\n([\s\S]*?)\nHTML;/)[1],script='const seoAdmin=false;'+source.match(/<<<'JS'\n([\s\S]*?)\nJS;/)[1];
const b=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
try{const p=await b.newPage(),errors=[],writes=[],gets=[];let repaired=false,holdOther=false,releaseOther=null;const raw='Luxor tours (Transactional, High)',phrase='Luxor tours';p.on('pageerror',e=>errors.push(e.message));
await p.route('http://platform.test/**',async route=>{const req=route.request(),u=new URL(req.url()),api=u.searchParams.get('__tf_api')||u.pathname;
if(!api.startsWith('/api/'))return route.fulfill({contentType:'text/html',body:'<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="csrf-token" content="fixture">'+markup+'</html>'});
let data={};if(req.method()==='POST'){const body=req.postDataJSON();writes.push({api,body,csrf:req.headers()['x-csrf-token']});if(!api.endsWith('/13/repair'))throw Error('Unexpected paid/write operation '+api);repaired=true;data={keyword:phrase};}else{
gets.push(api+'?'+u.searchParams.toString());
if(api==='/api/websites')data={websites:[{id:44,company_name:'Other site first'},{id:33,company_name:'Selected customer site'}]};
else if(api.endsWith('/markets')){const other=u.searchParams.get('website_id')==='44';if(other&&holdOther)await new Promise(resolve=>releaseOther=resolve);data={markets:[{id:other?19:9,label:other?'Other market':'US English',is_primary:1}],limit:6};}
else if(api.endsWith('/keywords'))data={status:{available:true},keywords:[{id:13,keyword:repaired?phrase:raw,suggested_phrase:repaired?null:phrase,trend_30_days:[],search_volume:null}],gaps:[]};
}
return route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data})});});
async function open(query){await p.goto('http://platform.test/ai/keywords'+query);await p.addScriptTag({path:path.join(root,'public_html/assets/js/panel.js')});await p.addScriptTag({content:script});}
await open('?website_id=33');await p.locator('[data-repair-keyword]').waitFor();
check(await p.locator('#seoSite').inputValue()==='33'&&gets.some(g=>g.includes('website_id=33'))&&!gets.some(g=>g.includes('website_id=44')),'deep link selects the owned requested website rather than the first account site');
check(await p.locator('#seoInputReview').isVisible()&&(await p.locator('#seoWords').innerText()).includes(phrase),'legacy annotation is explained with the exact proposed phrase');
check(!writes.length,'opening keyword review never spends, starts research or repairs silently');
p.once('dialog',d=>d.dismiss());await p.locator('[data-repair-keyword]').click();check(!writes.length,'cancelled phrase review leaves the keyword unchanged');
p.once('dialog',d=>d.accept());await p.locator('[data-repair-keyword]').click();await p.waitForFunction(()=>document.querySelector('#seoInputReview').hidden);
check(writes.length===1&&writes[0].body.website_id===33&&writes[0].body.market_id===9&&writes[0].body.expected_keyword===raw&&writes[0].body.reviewed===true,'reviewed cleanup sends original phrase and exact site/market once');
check(writes[0].csrf==='fixture'&&!await p.locator('[data-repair-keyword]').count(),'actual Panel CSRF transport is retained and repaired action disappears');
check((await p.locator('#seoWords').innerText()).includes(phrase)&&!(await p.locator('#seoWords').innerText()).includes('Transactional'),'post-save data is re-read rather than displaying an assumed correction');
const count=gets.length;await open('?website_id=999');await p.waitForFunction(()=>document.querySelector('#seoStatus').textContent.includes('غير متاح'));
check(await p.locator('#seoSite').inputValue()===''&&!gets.slice(count).some(g=>g.includes('/markets')||g.includes('/keywords')),'foreign deep link does not silently fall back to another customer site');
await p.locator('#seoSite').selectOption('33');await p.waitForFunction(()=>document.querySelector('#seoWords').textContent.includes('Luxor tours'));
check(await p.locator('#seoSite').inputValue()==='33','customer can recover from a stale link by selecting an owned website');
holdOther=true;await p.locator('#seoSite').selectOption('44');await p.waitForTimeout(100);await p.locator('#seoSite').selectOption('33');await p.waitForFunction(()=>document.querySelector('#seoMarket').value==='9'&&document.querySelector('#seoWords').textContent.includes('Luxor tours'));
if(!releaseOther)throw Error('Delayed market fixture did not run');releaseOther();await p.waitForTimeout(150);
check(await p.locator('#seoMarket').inputValue()==='9'&&await p.locator('#seoSite').inputValue()==='33','late response from a previously selected site cannot replace current market or keyword view');
check(errors.length===0&&writes.length===1,'workflow has no browser errors or paid/background write requests');
console.log(n+' assertions passed');}finally{await b.close();}})().catch(e=>{console.error(e.stack);process.exit(1)});
