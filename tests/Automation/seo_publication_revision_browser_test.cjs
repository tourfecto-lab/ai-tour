const path=require('node:path'),fs=require('node:fs'),{chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
(async()=>{let n=0;const check=(ok,label)=>{if(!ok)throw Error(label);n++;console.log('PASS '+label)};const b=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
try{
 const context=await b.newContext(),writes=[],errors=[];let version=1,status='completed',hold=false,release;
 const old={id:9901,kind:'content',category:'aeo',status:'verified',is_active:1,publication_revision_available:true,title:'Existing Egypt guide',field_name:'content',expected_value:'Previous published content with an unsupported policy.',page_url:'https://site.example/',published_url:'https://site.example/?tf_article=egypt-guide',checked_at:'2026-10-17 12:00:00',recommendation_review:{state:'retained',claim_review:{state:'needs_confirmation',findings:[{quote:'Children travel free.'}]}},content_review:{meta_description:'Review confirmed routes.',faqs:[]}};
 let tasks=[old,{id:9900,kind:'metadata',category:'seo',status:'pending',title:'Pending title',field_name:'title',page_url:'https://site.example/tours',expected_value:'Egypt Tours | Example',recommendation_review:{state:'reviewable'}}];
 const detail=()=>({identity_valid:true,worker_ready:true,run:{id:10,status,version,snapshot:{pages:[],opportunities:{scope_version:2}}},tasks});
 await context.route('http://platform.test/**',async route=>{const q=route.request(),u=new URL(q.url()),api=u.searchParams.get('__tf_api')||u.pathname;
  if(!api.startsWith('/api/'))return route.fulfill({contentType:'text/html',body:'<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="csrf-token" content="fixture"><div class="panel-shell"><main class="panel-content"><section id="seoExpertRoot" data-user-id="1"></section></main></div></html>'});
  const input=q.method()==='GET'?null:q.postDataJSON();if(input)writes.push({api,input});let data={};
  if(api.endsWith('/setup'))data={installed:true,worker_ready:true,csrf_token:'fixture',websites:[{id:33,domain:'site.example'}]};
  else if(api.endsWith('/sites/33'))data={site:{id:33,url:'https://site.example'},worker_ready:true,quote:{ok:true,source:'plan'},config:{status:'draft',brief:{business_name:'Example',business_facts:'Confirmed routes',audience:'Travelers',goal:'Bookings',language:'en'},policy:{max_cycles:3,max_cycle_price:0,max_pages:100}},runs:[{id:10,status}],connections:{}};
  else if(api.endsWith('/probe'))data={connector:{state:'publicly_observed'},metrics:{gsc:{state:'measured'},analytics:{state:'measured'}},publishing:{state:'authenticated'}};
  else if(api.endsWith('/runs/10'))data=detail();
  else if(api.endsWith('/tasks/9901/edit')){
   if(input.publication_revision==='preview'){
    if(hold){hold=false;await new Promise(r=>release=r);}
    data={state:'preview',preview_token:'signed-fixture',published_url:old.published_url,content:input.value+' <img src=x onerror=alert(1)>',meta_description:input.meta_description,removed_faqs:1};
   }else{
    tasks=[{...old,is_active:0,publication_revision_available:false,evidence:{superseded_by_task_id:9902},recommendation_review:{state:'retained',claim_review:{state:'historical'}}},{...old,id:9902,status:'active',expected_value:input.value,checked_at:null,evidence:{reason:'Revision saved; verification pending.'},recommendation_review:{state:'retained',claim_review:{state:'no_flagged_claims'}}},tasks[1]];status='monitoring';version++;data={...detail(),revised_task_id:9902,publication_revision_saved:true};
   }
  }
  await route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data})});
 });
 const p=await context.newPage();p.on('pageerror',e=>errors.push(e.message));await p.goto('http://platform.test/seo-expert?website_id=33&page=plan');
 for(const file of ['panel','seo-expert','seo-workspace'])await p.addScriptTag({path:path.resolve(__dirname,'../../public_html/assets/js/'+file+'.js')});
 for(const file of ['style','panel','tf-theme','seo-expert','seo-workspace'])await p.addStyleTag({content:fs.readFileSync(path.resolve(__dirname,'../../public_html/assets/css/'+file+'.css'),'utf8').replace(/^@import.*$/gm,'')});
 await p.locator('[data-run="10"]').click();await p.locator('[data-task="9901"] [data-publication-editor]').waitFor();await p.waitForFunction(()=>!document.querySelector('#seoExpertRoot').hasAttribute('aria-busy'));
 const card=p.locator('[data-task="9901"]');await card.locator('[data-publication-editor] > summary').click();
 check(await card.locator('[data-publication-value]').inputValue()===old.expected_value,'published revision starts from the existing visible source');
 check(await card.locator('[data-publication-apply]').isDisabled(),'saving is unavailable until a preview exists');
 const body='Compare the published Egypt routes, review the itinerary and confirm the final price and conditions before booking. No unconfirmed cancellation, child pricing or guide credential is promised.';
 await card.locator('[data-publication-value]').fill(body);hold=true;await card.locator('[data-publication-preview]').click();while(!release)await p.waitForTimeout(10);
 check(await p.locator('[data-task="9900"] [data-value]').isDisabled()&&await card.locator('[data-publication-value]').isDisabled(),'relocated workspace inputs stay disabled during the request, preventing lost edits');
 release();await card.locator('[data-publication-confirm]').waitFor();await p.waitForFunction(()=>!document.querySelector('#seoExpertRoot').hasAttribute('aria-busy'));
 check(writes.filter(x=>x.api.endsWith('/edit')).length===1&&writes.at(-1).input.publication_revision==='preview','first action requests a preview without applying publication');
 check(await card.locator('[data-publication-preview-note] img').count()===0&&(await card.locator('[data-publication-preview-note]').innerText()).includes('<img'),'preview text is escaped even if a server response contains markup');
 check((await card.locator('[data-publication-preview-note]').innerText()).includes('1'),'preview explains FAQ removals');
 await card.locator('[data-publication-apply]').click();await p.waitForFunction(()=>document.querySelector('#seMessage').textContent.includes('أكد الحفظ'));
 check(writes.filter(x=>x.api.endsWith('/edit')).length===1,'preview alone cannot authorize saving without the review checkbox');
 await card.locator('[data-publication-value]').fill(body+' Confirm departure details.');
 check(await card.locator('[data-publication-apply]').isDisabled()&&await card.locator('[data-publication-preview-note]').count()===0,'editing after preview invalidates its token and confirmation');
 await card.locator('[data-publication-preview]').click();await card.locator('[data-publication-confirm]').waitFor();await p.waitForFunction(()=>!document.querySelector('#seoExpertRoot').hasAttribute('aria-busy'));
 await p.setViewportSize({width:390,height:900});check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Arabic publication review fits the mobile viewport');
 await card.locator('[data-publication-confirm]').check();await card.locator('[data-publication-apply]').click();await p.locator('[data-task="9902"]').waitFor();await p.waitForFunction(()=>!document.querySelector('#seoExpertRoot').hasAttribute('aria-busy'));
 const applied=writes.find(x=>x.input.publication_revision==='apply');
 check(applied.input.reviewed===true&&applied.input.preview_token==='signed-fixture'&&applied.input.csrf_token==='fixture'&&applied.input.version===1,'apply uses explicit review, signed preview, CSRF and the displayed plan version');
 check(applied.input.value===body+' Confirm departure details.'&&applied.input.meta_description===old.content_review.meta_description,'save uses the exact last-previewed text and description');
 check(await p.locator('[data-task="9901"] [data-publication-history]').count()===1&&await p.locator('[data-task="9901"] [data-publication-editor]').count()===0,'previous publication remains visible as historical and cannot start a competing edit');
 check(await p.locator('[data-task="9902"] a[href="'+old.published_url+'"]').count()===1,'current revision keeps the same public URL');
 check((await p.locator('#seMessage').innerText()).includes('قيد الانتظار'),'saving explicitly leaves public verification pending');
 check(errors.length===0&&writes.every(x=>x.api.endsWith('/probe')||x.api.endsWith('/tasks/9901/edit')),'workflow starts no paid cycle, provider post, email or booking and has no JavaScript errors');
}finally{await b.close();}console.log(n+' assertions passed');})().catch(e=>{console.error(e.message);process.exitCode=1});
