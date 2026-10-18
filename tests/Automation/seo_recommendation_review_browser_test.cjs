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
const detail=()=>({identity_valid:true,worker_ready:true,run:{id:10,status:'completed',version,summary:'Review current proposals',snapshot:{pages:[],opportunities:{scope_version:2}}},tasks});
await p.route('http://platform.test/**',async r=>{const req=r.request(),u=new URL(req.url()),route=u.searchParams.get('__tf_api')||u.pathname;if(!route.startsWith('/api/'))return r.fulfill({contentType:'text/html',body:'<!doctype html><html lang="en"><section id="seoExpertRoot" data-user-id="1"></section></html>'});if(req.method()!=='GET')writes.push({route,body:req.postDataJSON()});let data={};
if(route.endsWith('/setup'))data={installed:true,worker_ready:true,csrf_token:'fixture',websites:[{id:32,domain:'site.example'}]};
else if(route.endsWith('/sites/32'))data={site:{id:32,url:'https://site.example'},worker_ready:true,quote:{allowed:true,source:'plan'},config:{status:'draft',version:1,brief:{business_name:'Example',business_facts:'Confirmed facts',audience:'Travelers',goal:'Bookings',language:'en'},policy:{max_cycles:3,max_cycle_price:0,max_pages:100}},runs:[{id:10,status:'completed'}],connections:{}};
else if(route.endsWith('/probe'))data={connector:{state:'publicly_observed'},metrics:{gsc:{state:'measured'},analytics:{state:'measured'}},publishing:{state:'authenticated'}};
else if(route.endsWith('/runs/10'))data=detail();

await r.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data})});});
await p.goto('http://platform.test/seo-expert?website_id=32&page=plan');await p.addScriptTag({path:path.resolve(__dirname,'../../public_html/assets/js/panel.js')});await p.addScriptTag({path:path.resolve(__dirname,'../../public_html/assets/js/seo-expert.js')});await p.locator('[data-run="10"]').click();
await p.locator('[data-task="201"]').waitFor();
check(await p.locator('[data-task]').count()===7,'all historical tasks remain visible in the plan');
check(await p.locator('[data-task="201"] [data-recommendation-review]').innerText()==='Collection cannot receive a single-trip proposal. Assessment uses the stored page snapshot; it is not a fresh page verification.','exclusion explains both reason and evidence age');
check(await p.locator('[data-task="201"] [name=seTask]').count()===0,'excluded trip schema cannot be selected for approval');
check(await p.locator('[data-task="202"] [data-done]').count()===0&&await p.locator('[data-task="202"] [data-editor-task]').count()===0,'excluded quiz has no completion or editor action');
check(await p.locator('[data-task="203"] [name=seTask]').count()===1,'safe collection breadcrumb retains approval checkbox');
check(await p.locator('[data-task="204"] [data-connected-check]').count()===1,'Google indexing action remains available');
check(await p.locator('[data-task="205"] [data-rollback]').count()===1,'applied metadata retains rollback feature');
check((await p.locator('[data-task="206"] .se-badge').innerText()).includes('Verified with Google'),'connected verification label names Google rather than public page');
check(await p.locator('[data-task="207"] [name=seTask]').count()===0,'missing page evidence blocks browser approval');
check((await p.locator('#seDetail').innerText()).includes('Unresolved tasks: 2 · Excluded proposals or missing evidence: 3'),'excluded proposals are not counted as customer work or completed successes');
await p.locator('[data-filter="client"]').click();check(await p.locator('[data-task]:visible').count()===1&&await p.locator('[data-task="204"]').isVisible(),'needs-input filter excludes unsuitable historical proposals');
await p.locator('[data-filter="excluded"]').click();check(await p.locator('[data-task]:visible').count()===3,'excluded filter retains proposals and reasons for inspection');
await p.locator('[data-filter="verified"]').click();check(await p.locator('[data-task]:visible').count()===2,'verified filter retains actual verified results only');
await p.locator('[data-filter="all"]').click();check(await p.locator('[data-task]:visible').count()===7,'all-task view restores the complete history');
await p.setViewportSize({width:390,height:900});check(await p.locator('[data-filter="excluded"]').isVisible(),'review filters remain accessible on mobile');
check(writes.every(w=>w.route.endsWith('/probe')),'viewing assessment starts no generation or publication request');
check(errors.length===0,'recommendation review UI has no JavaScript errors');
}finally{await b.close();}console.log(n+' assertions passed');})().catch(e=>{console.error(e.message);process.exitCode=1;});
