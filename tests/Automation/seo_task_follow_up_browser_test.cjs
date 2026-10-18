const path=require('node:path');const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
(async()=>{let n=0;const check=(ok,label)=>{if(!ok)throw Error(label);n++;console.log('PASS '+label);};const b=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});try{
const p=await b.newPage(),writes=[],errors=[];p.on('pageerror',e=>errors.push(e.message));let version=7;
const old='{"@graph":[{"@type":"BreadcrumbList","itemListElement":[{"item":"https://site.example/page/"}]}]}';
let tasks=[
{id:201,kind:'metadata',category:'geo',status:'pending',title:'Wrong collection trip',page_url:'https://site.example/tours/marsa-alam',field_name:'jsonld',expected_value:old,recommendation_review:{state:'excluded',reason:'Collection cannot receive a single-trip proposal.'}},
{id:202,kind:'metadata',category:'geo',status:'needs_client',title:'Wrong quiz',page_url:'https://site.example/quiz',field_name:'description',expected_value:'A retained value.',recommendation_review:{state:'excluded',reason:'Operational route is outside marketing edits.'}},
{id:203,kind:'metadata',category:'geo',status:'pending',title:'Safe breadcrumb',page_url:'https://site.example/tours/marsa-alam',field_name:'jsonld',expected_value:old,recommendation_review:{state:'reviewable',reason:'Review this safe collection breadcrumb.'}},
{id:204,kind:'manual',category:'seo',status:'needs_client',title:'Not indexed: old state',page_url:'https://site.example/tours',expected_value:null,connected_check_kind:'indexing',recommendation_review:{state:'reviewable',reason:'Review current coverage.'}},
{id:205,kind:'metadata',category:'seo',status:'verified',is_active:1,title:'Applied title',field_name:'title',page_url:'https://site.example/tours',expected_value:'Retained title',recommendation_review:{state:'retained'}},
{id:206,kind:'manual',category:'seo',status:'verified',title:'Sitemap',page_url:'https://site.example/',expected_value:null,connected_check_kind:'sitemap',recommendation_review:{state:'retained'}},
{id:207,kind:'metadata',category:'geo',status:'pending',title:'Missing evidence',page_url:'https://site.example/tours/unknown',field_name:'description',expected_value:'Retained description.',recommendation_review:{state:'needs_evidence',reason:'No stored page evidence.'}}
];
tasks=tasks.map(x=>x.id===204?{...x,follow_up:{state:'waiting_google',reason:'Google discovered this page but has not crawled it; indexing is not guaranteed.'}}:x);
tasks.push({id:208,kind:'manual',category:'seo',status:'needs_client',title:'Not indexed: unknown URL',page_url:'https://site.example/tours/unknown',expected_value:null,connected_check_kind:'indexing',follow_up:{state:'needs_review'}},{id:209,kind:'manual',category:'seo',status:'needs_client',title:'Not indexed: stale observation',page_url:'https://site.example/tours/stale',expected_value:null,connected_check_kind:'indexing',follow_up:{state:'needs_check',reason:'Refresh the connected Google inspection.'}});
const detail=()=>({identity_valid:true,worker_ready:true,run:{id:10,status:'completed',version,summary:'Review current proposals',snapshot:{pages:[],opportunities:{scope_version:2}}},tasks});
await p.route('http://platform.test/**',async r=>{const req=r.request(),u=new URL(req.url()),route=u.searchParams.get('__tf_api')||u.pathname;if(!route.startsWith('/api/'))return r.fulfill({contentType:'text/html',body:'<!doctype html><html lang="en"><section id="seoExpertRoot" data-user-id="1"></section></html>'});if(req.method()!=='GET')writes.push({route,body:req.postDataJSON()});let data={};
if(route.endsWith('/setup'))data={installed:true,worker_ready:true,csrf_token:'fixture',websites:[{id:32,domain:'site.example'}]};
else if(route.endsWith('/sites/32'))data={site:{id:32,url:'https://site.example'},worker_ready:true,quote:{allowed:true,source:'plan'},config:{status:'draft',version:1,brief:{business_name:'Example',business_facts:'Confirmed facts',audience:'Travelers',goal:'Bookings',language:'en'},policy:{max_cycles:3,max_cycle_price:0,max_pages:100}},runs:[{id:10,status:'completed'}],connections:{}};
else if(route.endsWith('/probe'))data={connector:{state:'publicly_observed'},metrics:{gsc:{state:'measured'},analytics:{state:'measured'}},publishing:{state:'authenticated'}};
else if(route.endsWith('/runs/10'))data=detail();
else if(route.endsWith('/tasks/209/done')){tasks=tasks.map(x=>x.id===209?{...x,follow_up:{state:'waiting_google',reason:'Fresh Google result: discovered but not crawled.'}}:x);version++;data=detail();}

await r.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data})});});
await p.goto('http://platform.test/seo-expert?website_id=32&page=plan');await p.addScriptTag({path:path.resolve(__dirname,'../../public_html/assets/js/panel.js')});await p.addScriptTag({path:path.resolve(__dirname,'../../public_html/assets/js/seo-expert.js')});await p.locator('[data-run="10"]').click();
await p.locator('[data-task="204"]').waitFor();
check(await p.locator('[data-task]').count()===9,'all applied, excluded, pending and Google tasks remain visible');
check((await p.locator('[data-task="204"] .se-badge').innerText()).includes('Awaiting Google crawl'),'discovered URL has its own Google badge');
check(await p.locator('[data-task="204"] [data-done]').count()===0,'waiting task does not ask customer to claim a site fix');
check(await p.locator('[data-task="204"] [data-connected-check]').count()===1,'waiting task retains read-only Google refresh');
check((await p.locator('[data-task="204"] [data-follow-up-note]').innerText()).includes('not guaranteed'),'waiting label does not promise indexing');
check((await p.locator('[data-task="209"] .se-badge').innerText()).includes('Refresh Google inspection'),'stale observation asks for a fresh check');
check((await p.locator('#seDetail').innerText()).includes('Unresolved tasks: 3 · Excluded proposals or missing evidence: 3 · Awaiting Google crawl: 1'),'waiting, customer work and excluded history have distinct counts');
await p.locator('[data-filter="waiting_google"]').click();check(await p.locator('[data-task]:visible').count()===1&&await p.locator('[data-task="204"]').isVisible(),'Google filter isolates waiting pages');
await p.locator('[data-filter="client"]').click();check(await p.locator('[data-task]:visible').count()===2&&await p.locator('[data-task="208"]').isVisible()&&await p.locator('[data-task="209"]').isVisible(),'unknown/stale pages remain review work, not waiting');
await p.locator('[data-connected-check="209"]').click();await p.waitForFunction(()=>document.querySelector('[data-task="209"]')?.dataset.followUp==='waiting_google');
const request=writes.find(w=>w.route.endsWith('/tasks/209/done'));
check(request.body.connected_check===true&&request.body.version===7,'refresh uses existing versioned connected endpoint');
await p.locator('[data-filter="waiting_google"]').click();check(await p.locator('[data-task]:visible').count()===2,'fresh Google observation moves the page into follow-up');
await p.locator('[data-filter="client"]').click();check(await p.locator('[data-task]:visible').count()===1,'follow-up transition removes only the waiting page from customer input');
await p.locator('[data-filter="excluded"]').click();check(await p.locator('[data-task]:visible').count()===3,'excluded proposal inspection is preserved');
await p.locator('[data-filter="verified"]').click();check(await p.locator('[data-task]:visible').count()===2,'waiting is never counted as completed or verified');
await p.locator('[data-filter="all"]').click();await p.setViewportSize({width:390,height:900});check(await p.locator('[data-filter="waiting_google"]').isVisible(),'Google filter accessible on mobile');
check(writes.every(w=>w.route.endsWith('/probe')||w.route.endsWith('/tasks/209/done')),'classification triggers no generation, approval or publication');
check(errors.length===0,'follow-up UI has no JavaScript errors');
}finally{await b.close();}console.log(n+' assertions passed');})().catch(e=>{console.error(e.message);process.exitCode=1;});
