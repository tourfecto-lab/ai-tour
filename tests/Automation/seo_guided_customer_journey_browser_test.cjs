const fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
(async()=>{
 let n=0;const check=(ok,label)=>{if(!ok)throw Error(label);n++;console.log('PASS '+label);};
 const root=path.resolve(__dirname,'../..'),b=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 try{
  const p=await b.newPage({viewport:{width:1280,height:960}}),errors=[],writes=[],gets=[];let phase='queued',version=1;
  p.on('pageerror',e=>errors.push(e.message));await p.clock.install();
  const cfg={status:'active',version:1,brief:{business_name:'Confirmed Travel',business_facts:'Confirmed tours',audience:'Confirmed English audience',goal:'Bookings',language:'en'},policy:{max_cycles:4,max_cycle_price:0,max_pages:100,auto_missing_metadata:true},cycles_used:1,budget_month:new Date().toISOString().slice(0,7)};
  const tasks=()=>phase==='queued'?[]:[{id:201,kind:'manual',status:'needs_client',category:'seo',title:'Confirm actual booking details',instructions:'Provide actual booking facts.',page_url:'https://customer.example/',expected_value:null,follow_up:{state:'needs_review'}},{id:202,kind:'metadata',status:phase==='completed'?'pending':'active',category:'seo',title:'Factual title',instructions:'Review actual facts.',page_url:'https://customer.example/tours',expected_value:'Confirmed tours',field_name:'title',is_active:phase==='completed'?0:1}];
  const detail=()=>({identity_valid:true,worker_ready:true,run:{id:11,status:phase,version,snapshot:{pages:[],opportunities:{scope_version:2}}},tasks:tasks()});
  await p.route('http://platform.test/**',async route=>{const req=route.request(),u=new URL(req.url()),api=u.searchParams.get('__tf_api')||u.pathname;let data={};
   if(!api.startsWith('/api/'))return route.fulfill({contentType:'text/html',body:'<!doctype html><html lang="en"><meta charset="utf-8"><meta name="csrf-token" content="fixture"><div class="panel-shell"><main class="panel-content" style="width:100%;min-width:0"><div id="seoExpertRoot" data-user-id="1"></div></main></div></html>'});
   if(req.method()==='POST')writes.push(api);else gets.push(api);
   if(api.endsWith('/setup'))data={installed:true,worker_ready:true,csrf_token:'fixture',websites:[{id:33,domain:'customer.example'},{id:44,domain:'other.example'}]};
   else if(api.includes('/sites/')){const other=api.includes('/44');if(api.endsWith('/probe'))data={connector:{state:other?'not_observed':'publicly_observed'},metrics:{gsc:{state:other?'not_connected':'measured'},analytics:{state:other?'not_connected':'measured'}},publishing:{state:'not_connected'}};else data={worker_ready:true,site:{id:other?44:33,url:other?'https://other.example':'https://customer.example'},config:cfg,quote:{ok:true,source:'plan',hash:'fixture'},runs:other?[]:[{id:11,status:phase,version}],connections:{google_search_console:{saved:!other},google_analytics:{saved:!other},wordpress:{saved:false}}};}
   else if(api.endsWith('/runs/11'))data=detail();
   return route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data})});
  });
  await p.goto('http://platform.test/seo-expert?website_id=33&page=plan');
  for(const name of ['panel','seo-expert','seo-workspace'])await p.addScriptTag({path:path.join(root,'public_html/assets/js/'+name+'.js')});
  await p.addStyleTag({content:fs.readFileSync(path.join(root,'public_html/assets/css/panel.css'),'utf8').replace(/^@import.*$/gm,'')});await p.addStyleTag({path:path.join(root,'public_html/assets/css/seo-expert.css')});await p.addStyleTag({path:path.join(root,'public_html/assets/css/seo-workspace.css')});
  await p.waitForFunction(()=>document.querySelector('#seDetail h3')?.textContent==='Queued');
  check(await p.locator('#seJourneySteps li').count()===4,'customer has four explicit steps without losing existing tools');
  await p.locator('#seNavigation [data-se-page=overview]').click();
  check((await p.locator('#seNextStep').innerText()).includes('processing your existing cycle'),'background processing asks the customer to follow the existing cycle: '+await p.locator('#seNextStep').innerText());
  check((await p.locator('#seConnectionSummary').innerText()).includes('verified')&&!await p.locator('#sePublishingCard').isVisible(),'missing optional WordPress login is not presented as a failed PHP setup');
  await p.locator('#seNavigation [data-se-page=settings]').click();await p.locator('#seManageConnections').click();
  check(await p.locator('#sePublishingCard').isVisible(),'optional WordPress permissions remain accessible on request');
  await p.locator('#seNavigation [data-se-page=plan]').click();phase='monitoring';version++;
  await p.clock.fastForward(20001);await p.locator('[data-task="201"] [data-evidence]').waitFor();
  check((await p.locator('#seDetail h3').first().innerText()).includes('Verifying'),'cycle progress updates automatically without another customer click');
  const before=gets.length;await p.locator('[data-task="201"] [data-evidence]').fill('Unsubmitted facts must be retained.');await p.locator('#sePageHeading').click();phase='completed';version++;
  await p.clock.fastForward(20001);
  check(await p.locator('[data-task="201"] [data-evidence]').inputValue()==='Unsubmitted facts must be retained.'&&gets.length===before,'automatic polling pauses for unsaved customer input even after focus leaves the field');
  await p.locator('#seRefresh').click();await p.locator('[data-task="202"] [data-value]').waitFor();
  await p.locator('[data-approve]').click();await p.waitForFunction(()=>document.querySelector('#seMessage').textContent.includes('confirm approval'));
  check(!writes.some(u=>u.endsWith('/approve')||u.endsWith('/start')),'guided progress never approves changes or consumes a new cycle automatically');
  await p.locator('#seNavigation [data-se-page=overview]').click();await p.waitForFunction(()=>document.querySelector('#seNextStep').textContent.includes('Proposals to review: 1'));
  check((await p.locator('#seJourney').innerText()).includes('approve reviewed changes'),'the customer sees what requires their review and what the platform does');
  await p.locator('#seWebsite').selectOption('44');await p.waitForFunction(()=>document.querySelector('#seNextStep').textContent.includes('Setup is saved'));
  check(await p.locator('#seDetail').innerText()===''&&!await p.locator('#seNextStep').innerText().then(t=>t.includes('Proposals to review: 1')),'switching sites clears the prior plan and its required actions');
  await p.setViewportSize({width:390,height:900});check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'guided SEO overview fits a mobile viewport');
  check(errors.length===0,'SEO guidance and progress have no JavaScript errors');await p.close();

  const local=await b.newPage({viewport:{width:1280,height:960}}),localWrites=[],localErrors=[];let failNap=false,holdNap=null,releaseNap=null;
  local.on('pageerror',e=>localErrors.push(e.message));
  const source=fs.readFileSync(path.join(root,'app/Controllers/LocalSeoController.php'),'utf8'),markup=source.match(/\$body='([\s\S]*?)<script defer/)[1];
  const profile=id=>({name:id===33?'Real business <img src=x onerror=alert(1)>':'Other business',phone:id===33?'+201001234567':'+201002222222',address:{locality:'Cairo',regionCode:'EG'},website:id===33?'https://customer.example':'https://other.example',directory_profiles:[id===33?'https://directory.example/real':'https://directory.example/other'],confirmed_at:'2026-10-18T00:00:00Z'});
  await local.route('http://platform.test/**',async route=>{const req=route.request(),u=new URL(req.url()),api=u.searchParams.get('__tf_api')||u.pathname;
   if(!api.startsWith('/api/'))return route.fulfill({contentType:'text/html',body:'<!doctype html><meta charset="utf-8"><meta name="csrf-token" content="fixture"><div class="panel-shell"><main class="panel-content" style="width:100%;min-width:0">'+markup+'</main></div>'});
   const body=req.method()==='POST'?req.postDataJSON():null,id=Number(body?.website_id||u.searchParams.get('website_id')||33),op=api.split('/').pop();if(body)localWrites.push({op,body});let data={};
   if(op==='sites')data={sites:[{id:33,main_url:'https://customer.example'},{id:44,main_url:'https://other.example'}]};
   else if(op==='data')data={profile:profile(id),maps_grid_ready:false,runs:[],tasks:[]};
   else if(op==='profile')data=api.startsWith('/api/gbp/')?{profile:profile(id)}:{profile:profile(id),maps_grid_ready:false};
   else if(op==='nap'){
    if(holdNap){const signal=holdNap;holdNap=null;await signal;}
    if(failNap){failNap=false;return route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({success:false,error:'راجع ترحيل Phase 5 والربط والمزوّد وسقف الإنفاق'})});}
    data={observations:[{source_url:id===33?'https://customer.example':'https://other.example',fetch_state:'observed',checks:{name:{state:'mismatch',expected:profile(id).name,observed:'Old name'},phone:{state:'match',expected:profile(id).phone,observed:profile(id).phone},address:{state:'unknown',expected:profile(id).address,observed:null}}}]};
   }
   return route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data})});
  });
  await local.goto('http://platform.test/local-seo?website_id=33');for(const name of ['panel','local-seo'])await local.addScriptTag({path:path.join(root,'public_html/assets/js/'+name+'.js')});await local.addStyleTag({content:fs.readFileSync(path.join(root,'public_html/assets/css/panel.css'),'utf8').replace(/^@import.*$/gm,'')});await local.addStyleTag({path:path.join(root,'public_html/assets/css/local-seo.css')});
  await local.waitForFunction(()=>!document.querySelector('#localSeo').hasAttribute('aria-busy'));
  check((await local.locator('#localSeo h3').first().innerText()).includes('راجع بيانات نشاطك'),'Arabic step instructions render correctly as UTF-8');
  check((await local.locator('#localProfilePreview').innerText()).includes('Real business')&&await local.locator('#localDirectories').inputValue()==='https://directory.example/real','actual connected facts are previewed and previous directory profiles are restored');
  check(await local.locator('#localProfilePreview img').count()===0,'Google facts render as escaped text');
  check(!localWrites.length&&!await local.locator('#localConfirm').isChecked()&&!await local.locator('#localPaid').isChecked(),'loading guidance never confirms facts or authorizes paid work');
  await local.locator('#localProfile').click();check(!localWrites.length,'an unchecked factual review cannot save or start comparison');
  await local.locator('#localConfirm').check();await local.locator('#localProfile').click();await local.waitForFunction(()=>!document.querySelector('#localSeo').hasAttribute('aria-busy'));
  check(localWrites.length===2&&localWrites[0].op==='profile'&&localWrites[1].op==='nap','one reviewed confirmation saves facts and automatically compares them exactly once');
  check((await local.locator('#localComparison').innerText()).includes('يحتاج مراجعة')&&(await local.locator('#localComparison').innerText()).includes('غير معروف'),'comparison explains real mismatches and unknown values rather than showing a false all-clear');
  check((await local.locator('#localStatus').innerText()).includes('تلقائيًا')&&(await local.locator('#localMapsReady').innerText()).includes('إحداثيات'),'NAP succeeds independently and the paid Maps prerequisite stays visible');
  check(!localWrites.some(w=>['run','links'].includes(w.op)),'a successful free comparison does not trigger paid grids or backlink research');
  failNap=true;await local.locator('#localProfile').click();await local.waitForFunction(()=>!document.querySelector('#localSeo').hasAttribute('aria-busy'));
  check((await local.locator('#localStatus').innerText()).includes('حُفظت بياناتك')&&!(await local.locator('#localStatus').innerText()).includes('ترحيل Phase'),'partial failure preserves saved facts and gives a customer action without server jargon');
  const saved=localWrites.filter(w=>w.op==='profile').length;await local.locator('#localNap').click();await local.waitForFunction(()=>!document.querySelector('#localSeo').hasAttribute('aria-busy'));
  check(localWrites.filter(w=>w.op==='profile').length===saved,'retrying the comparison does not replay profile saving');
  holdNap=new Promise(r=>{releaseNap=r;});await local.locator('#localNap').click();await local.waitForFunction(()=>document.querySelector('#localSeo').hasAttribute('aria-busy'));
  await local.evaluate(()=>{const s=document.querySelector('#localSite');s.value='44';s.dispatchEvent(new Event('change'));});releaseNap();
  await local.waitForFunction(()=>!document.querySelector('#localSeo').hasAttribute('aria-busy')&&document.querySelector('#localProfilePreview').textContent.includes('Other business'));
  check(!(await local.locator('#localSeo').innerText()).includes('Real business')&&await local.locator('#localDirectories').inputValue()==='https://directory.example/other','late responses from a previous site cannot populate the new site');
  check(!await local.locator('#localConfirm').isChecked()&&!await local.locator('#localPaid').isChecked()&&await local.locator('#localComparison').innerText()==='','site changes clear prior facts approval, paid consent and comparison results');
  await local.setViewportSize({width:390,height:900});check(await local.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'local customer steps fit a mobile viewport');
  check(localErrors.length===0,'guided local comparison has no JavaScript errors');await local.close();
 }finally{await b.close();}console.log(n+' assertions passed');
})().catch(e=>{console.error(e.stack);process.exitCode=1;});
