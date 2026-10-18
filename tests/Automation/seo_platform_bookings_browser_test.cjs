const fs=require('node:fs'),path=require('node:path');const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
(async()=>{let n=0;const check=(ok,label)=>{if(!ok)throw Error(label);n++;console.log('PASS '+label)};const b=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});try{
const p=await b.newPage({viewport:{width:1280,height:960}}),writes=[],errors=[];let selected=[],hold=false,release;
p.on('pageerror',e=>errors.push(e.message));
const native=id=>({available:true,state:selected.length&&id===33?'configured':'not_configured',mapped_products:selected.length,cohort_basis:'booking_created_at_utc',periods:{current:{start:'2026-09-20',end:'2026-10-17'},previous:{start:'2026-08-23',end:'2026-09-19'}},current:{paid_bookings:1,cancelled_bookings_with_payments:0,currencies:[{currency:'USD',gross_captured:'150.00',succeeded_refunds:'15.00',net_captured:'135.00',pending_refunds:'4.00'},{currency:'EUR',gross_captured:'80.00',succeeded_refunds:'80.00',net_captured:'0.00',pending_refunds:'0.00'}],excluded_inconsistent_payments:0},previous:{paid_bookings:0,cancelled_bookings_with_payments:0,currencies:[],excluded_inconsistent_payments:0}});
const overview=id=>({installed:true,platform_bookings:native(id),measurement_sources:{search:'saved_connection',analytics:'saved_connection',wordpress_orders_available:false},changes:[],monitors:[],ai:[],booking_source:null});
await p.route('http://platform.test/**',async route=>{const req=route.request(),u=new URL(req.url()),api=u.searchParams.get('__tf_api')||u.pathname;if(!api.startsWith('/api/'))return route.fulfill({contentType:'text/html',body:'<!doctype html><html lang="en"><meta charset="utf-8"><meta name="csrf-token" content="fixture"><div class="panel-shell"><main class="panel-content" style="min-width:0"><div id="seoExpertRoot" data-user-id="1" data-se-page="results"><select id="seWebsite"><option value="33">customer.example</option><option value="44">other.example</option></select></div></main></div></html>'});let data={};const id=Number(api.split('/')[4]);
if(req.method()==='POST')writes.push({api,body:req.postDataJSON()});
if(api.endsWith('/setup'))data={csrf_token:'fixture'};
else if(api.endsWith('/platform-bookings/products')){
 if(hold&&id===33){hold=false;await new Promise(r=>release=r)}
 const more=u.searchParams.get('cursor')==='100';data={products:id===33?(more?[{id:101,name:'Other available tour',bound_elsewhere:false}]:[{id:10,name:'Nile tour <img src=x onerror=alert(1)>',bound_elsewhere:false},{id:11,name:'Sea tour',bound_elsewhere:false},{id:12,name:'Tour at another site',bound_elsewhere:true}]):[{id:44,name:'New site product',bound_elsewhere:false}],selected:id===33?selected.map(product_id=>({product_id,name:'Nile tour'})):[],next_cursor:more?null:100};
}else if(api.endsWith('/platform-bookings')){selected=req.postDataJSON().product_ids;data=overview(id)}
else if(api.startsWith('/api/seo-impact/sites/'))data=overview(id);
await route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data})});});
await p.goto('http://platform.test/seo-expert');for(const name of ['panel','seo-impact'])await p.addScriptTag({path:path.resolve(__dirname,'../../public_html/assets/js/'+name+'.js')});
await p.addStyleTag({content:fs.readFileSync(path.resolve(__dirname,'../../public_html/assets/css/panel.css'),'utf8').replace(/^@import.*$/gm,'')});await p.addStyleTag({path:path.resolve(__dirname,'../../public_html/assets/css/seo-expert.css')});
await p.waitForFunction(()=>!document.querySelector('#seoImpact').hasAttribute('aria-busy'));
check((await p.locator('#imNativeReport').innerText()).includes('not zero bookings')&&!writes.length,'an unmapped site is explicitly unconfigured and loading never enables a financial source');
await p.locator('#imNativeSetup summary').click();await p.locator('[data-native-product="10"]').waitFor();
check(await p.locator('[data-native-product="12"]').isDisabled()&&await p.locator('#imNativeProducts img').count()===0,'other-site assignments are locked and product names render as escaped text');
await p.locator('[data-native-product="10"]').check();await p.locator('#imNativeSave').click();await p.waitForFunction(()=>!document.querySelector('#seoImpact').hasAttribute('aria-busy'));
check(!writes.length&&(await p.locator('#imMessage').innerText()).includes('confirm'),'selection cannot be saved without the explicit site-fact confirmation');
await p.locator('#imNativeConfirm').check();await p.locator('#imNativeMore').click();await p.locator('[data-native-product="101"]').waitFor();
check(await p.locator('[data-native-product="10"]').isChecked(),'pagination retains an unsaved selection');
await p.locator('[data-native-product="11"]').check();check(!await p.locator('#imNativeConfirm').isChecked(),'changing products clears the prior factual confirmation');await p.locator('[data-native-product="11"]').uncheck();
await p.locator('#imNativeSearch').fill('Nile');await p.locator('#imNativeFind').click();await p.waitForFunction(()=>!document.querySelector('#seoImpact').hasAttribute('aria-busy'));
check(await p.locator('[data-native-product="10"]').isChecked(),'search never resets the customer’s unsaved selected product');
await p.locator('#imNativeConfirm').check();await p.locator('#imNativeSave').click();await p.waitForFunction(()=>document.querySelector('#imNativeReport').textContent.includes('135.00'));
check(writes.length===1&&writes[0].api==='/api/seo-impact/sites/33/platform-bookings'&&JSON.stringify(writes[0].body.product_ids)==='[10]'&&writes[0].body.confirmed===true&&writes[0].body.csrf_token==='fixture','reviewed save sends only selected owned product ids with the real Panel CSRF/JSON transport');
const report=await p.locator('#imNativeReport').innerText();
check(report.includes('USD')&&report.includes('EUR')&&report.includes('0.00')&&report.includes('15.00')&&report.includes('4.00'),'captured amounts, confirmed/pending refunds and actual zero remain visible by currency');
check(report.includes('booking creation dates')&&report.includes('not assigned to a landing page')&&report.includes('Cancellation alone'),'measurement scope is explicit without converting product sales into SEO revenue or assumed refunds');
check(!await p.locator('#imNativeConfirm').isChecked()&&await p.locator('#imConnect').isDisabled(),'successful native setup clears consent and does not enable unrelated WooCommerce receipts');
if(process.env.TF_NATIVE_UI_ARTIFACT_DIR){await p.screenshot({path:path.join(process.env.TF_NATIVE_UI_ARTIFACT_DIR,'native-bookings-desktop.png'),fullPage:true});}
hold=true;await p.locator('#imNativeFind').click();await p.waitForFunction(()=>document.querySelector('#seoImpact').hasAttribute('aria-busy'));await p.locator('#seWebsite').selectOption('44');while(!release)await p.waitForTimeout(20);release();await p.waitForFunction(()=>!document.querySelector('#seoImpact').hasAttribute('aria-busy'));
check(!(await p.locator('#imNativeReport').innerText()).includes('135.00')&&await p.locator('[data-native-product]').count()===0&&!await p.locator('#imNativeConfirm').isChecked(),'a site change clears prior money, product selection and consent and rejects late product responses');
await p.setViewportSize({width:390,height:900});check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'native booking guidance fits a mobile viewport');
check(errors.length===0&&writes.every(w=>w.api.endsWith('/platform-bookings')),'customer mapping creates no payment, booking, provider request or page error');
}finally{await b.close()}console.log(n+' assertions passed')})().catch(e=>{console.error(e.stack);process.exitCode=1});
