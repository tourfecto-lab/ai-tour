const path=require('node:path'),fs=require('node:fs');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
(async()=>{let n=0;const check=(ok,label)=>{if(!ok)throw Error(label);n++;console.log('PASS '+label)};
const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
try{
 const writes=[],errors=[];let version=1;
 const claim={state:'needs_confirmation',reason:'Confirm the exact policy.',findings:[{code:'cancellation_policy',field:'description',quote:'Book with free cancellation. <img src=x onerror=alert(1)>'},{code:'guide_credentials',field:'description',quote:'Book with free cancellation. <img src=x onerror=alert(1)>'}]};
 let tasks=[
  {id:901,kind:'metadata',category:'seo',status:'pending',title:'Review tour summary',field_name:'description',page_url:'https://site.example/tours',expected_value:'Book with free cancellation.',recommendation_review:{state:'reviewable',reason:'Review this proposal.',claim_review:claim}},
  {id:902,kind:'metadata',category:'seo',status:'pending',title:'Reviewed title',field_name:'title',page_url:'https://site.example/tours',expected_value:'Egypt Tours | Example Tours',recommendation_review:{state:'reviewable',reason:'Review the title.',claim_review:{state:'no_flagged_claims',findings:[]}}},
  {id:903,kind:'content',category:'aeo',status:'verified',is_active:1,title:'Existing destination guide',field_name:'content',page_url:'https://site.example/',published_url:'https://site.example/?tf_article=existing-guide',checked_at:'2026-10-17 12:00:00',expected_value:'Children under 6 travel free.',recommendation_review:{state:'retained',claim_review:{...claim,findings:[{code:'child_pricing',field:'content',quote:'Children under 6 travel free.'}]}}}
 ];
 const detail=()=>({identity_valid:true,worker_ready:true,run:{id:10,status:'completed',version,snapshot:{pages:[],opportunities:{scope_version:2}}},tasks});
 const context=await browser.newContext();await context.route('http://platform.test/**',async route=>{
  const req=route.request(),u=new URL(req.url()),api=u.searchParams.get('__tf_api')||u.pathname;
  if(!api.startsWith('/api/'))return route.fulfill({contentType:'text/html',body:`<!doctype html><html lang="${u.searchParams.get('lang')==='ar'?'ar':'en'}" dir="${u.searchParams.get('lang')==='ar'?'rtl':'ltr'}"><meta charset="utf-8"><meta name="csrf-token" content="fixture"><div class="panel-shell"><main class="panel-content"><section id="seoExpertRoot" data-user-id="1"></section></main></div></html>`});
  const body=req.method()==='GET'?null:req.postDataJSON();if(body)writes.push({api,body});let data={};
  if(api.endsWith('/setup'))data={installed:true,worker_ready:true,csrf_token:'fixture',websites:[{id:33,domain:'site.example'}]};
  else if(api.endsWith('/sites/33'))data={site:{id:33,url:'https://site.example'},worker_ready:true,quote:{ok:true,source:'plan'},config:{status:'draft',brief:{business_name:'Example',business_facts:'Local guides and private tours.',audience:'Travelers',goal:'Bookings',language:'en'},policy:{max_cycles:3,max_cycle_price:0,max_pages:100}},runs:[{id:10,status:'completed'}],connections:{}};
  else if(api.endsWith('/probe'))data={connector:{state:'publicly_observed'},metrics:{gsc:{state:'measured'},analytics:{state:'measured'}},publishing:{state:'authenticated'}};
  else if(api.endsWith('/tasks/901/edit')){tasks[0]={...tasks[0],expected_value:body.value,recommendation_review:{...tasks[0].recommendation_review,claim_review:{state:'no_flagged_claims',findings:[]}}};version++;data=detail();}
  else if(api.endsWith('/runs/10/approve'))data=detail();
  else if(api.endsWith('/runs/10'))data=detail();
  await route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data})});
 });
 const open=async(lang='en')=>{const p=await context.newPage();p.on('pageerror',e=>errors.push(e.message));await p.goto('http://platform.test/seo-expert?website_id=33&lang='+lang);for(const file of ['panel','seo-expert'])await p.addScriptTag({path:path.resolve(__dirname,'../../public_html/assets/js/'+file+'.js')});for(const file of ['style','panel','tf-theme','seo-expert'])await p.addStyleTag({content:fs.readFileSync(path.resolve(__dirname,'../../public_html/assets/css/'+file+'.css'),'utf8').replace(/^@import.*$/gm,'')});await p.locator('[data-run="10"]').click();await p.locator('[data-task="901"]').waitFor();return p;};
 const p=await open();
 check(await p.locator('[data-claim-summary]').innerText().then(s=>s.includes('Facts needing review: 2')),'summary counts factual reviews separately from technical results');
 check(await p.locator('[data-claim-review]').count()===2,'pending and already published claims both show warnings');
 check(await p.locator('[data-task="901"] [data-claim-review] li').count()===1,'a sentence matching two claim patterns is shown only once');
 check(!await p.locator('[data-task="901"] [name=seTask]').isChecked(),'unsupported metadata is not automatically selected');
 check(await p.locator('[data-task="902"] [name=seTask]').isChecked(),'ordinary reviewed metadata retains normal default selection');
 check(await p.locator('[data-task="901"] [data-value]').isEditable(),'customer can correct the pending proposal in place');
 check(await p.locator('[data-claim-review] img').count()===0&&(await p.locator('[data-task="901"] [data-claim-review]').innerText()).includes('<img'),'untrusted claim snippets render as escaped text');
 check((await p.locator('[data-task="903"] .se-badge').innerText()).toLowerCase().includes('verified')&&(await p.locator('[data-task="903"]').innerText()).includes('2026-10-17 12:00:00'),'published technical status and dated history remain visible');
 await p.locator('[data-filter="client"]').click();check(await p.locator('[data-task]:visible').count()===2&&await p.locator('[data-task="903"]').isVisible(),'needs-input filter includes published factual review without altering its status');
 await p.locator('[data-filter="all"]').click();await p.locator('[data-task="901"] [name=seTask]').check();await p.locator('#seReview').check();await p.locator('[data-approve]').click();
 await p.waitForFunction(()=>document.querySelector('#seMessage')?.textContent.includes('flagged business claims'));
 check(!writes.some(x=>x.api.endsWith('/approve')),'manual selection gives clear correction guidance before an approval request');
 await p.locator('[data-task="901"] [data-value]').fill('Compare confirmed Egypt tour routes and ask about your chosen departure.');await p.locator('[data-task="901"] [data-save]').click();
 await p.waitForFunction(()=>!document.querySelector('[data-task="901"] [data-claim-review]'));
 check((await p.locator('[data-task="901"] [data-value]').inputValue()).includes('Compare confirmed')&&await p.locator('[data-task="901"] [name=seTask]').isChecked(),'saving corrected facts removes warning and restores normal approval flow');
 check(writes.find(x=>x.api.endsWith('/edit')).body.csrf_token==='fixture'&&writes.find(x=>x.api.endsWith('/edit')).body.version===1,'correction uses actual Panel CSRF transport and versioned task edit');
 const ar=await open('ar');await ar.setViewportSize({width:390,height:900});
 check((await ar.locator('[data-task="903"] [data-claim-review]').innerText()).includes('معلومة تجارية محتاجة تأكيد'),'Arabic warning tells the customer exactly what needs review');
 check(await ar.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'claim review fits mobile Arabic layout');
 check(writes.every(x=>x.api.endsWith('/probe')||x.api.endsWith('/edit'))&&errors.length===0,'review creates no paid cycle, publication, booking, email or browser error');
}finally{await browser.close();}console.log(n+' assertions passed');})().catch(e=>{console.error(e.message);process.exitCode=1});
