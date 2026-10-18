const fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
(async()=>{let n=0;const check=(ok,label)=>{if(!ok)throw Error(label);n++;console.log('PASS '+label)};
const b=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
try{
 const writes=[],errors=[];let products=[{id:10,name:'Previously saved tour'}],failNext=false,hold=false,release;
 const overview=id=>({installed:true,platform_bookings:{available:true,state:products.length?'configured':'not_configured',mapped_products:products.length,periods:{current:{start:'2026-09-20',end:'2026-10-17'},previous:{start:'2026-08-23',end:'2026-09-19'}},current:{paid_bookings:0,cancelled_bookings_with_payments:0,currencies:[]},previous:{paid_bookings:0,cancelled_bookings_with_payments:0,currencies:[]}},measurement_sources:{search:'saved_connection',analytics:'saved_connection',wordpress_orders_available:false},monitors:[],changes:[],ai:[]});
 const context=await b.newContext();await context.route('http://platform.test/**',async route=>{
  const req=route.request(),u=new URL(req.url()),api=u.searchParams.get('__tf_api')||u.pathname;
  if(!api.startsWith('/api/'))return route.fulfill({contentType:'text/html',body:`<!doctype html><html lang="${u.searchParams.get('language')==='ar'?'ar':'en'}" dir="${u.searchParams.get('language')==='ar'?'rtl':'ltr'}"><meta charset="utf-8"><meta name="csrf-token" content="fixture"><div class="panel-shell"><main class="panel-content" style="min-width:0"><div id="seoExpertRoot" data-user-id="1" data-se-page="results"><select id="seWebsite"><option value="33">customer.example</option><option value="44">other.example</option></select></div></main></div></html>`});
  const body=req.method()==='POST'?req.postDataJSON():null;if(body)writes.push({api,body});let data={};
  const wid=Number(api.split('/')[4]);
  if(api.endsWith('/setup'))data={csrf_token:'fixture'};
  else if(api.endsWith('/products/preview')){
   if(hold){hold=false;await new Promise(r=>release=r);}
   data={source_url:body.source_url,draft:{name:'Imported tour <img src=x onerror=alert(1)>',price:'',currency:'',description:'Observed description'},candidates:[],requires_review:true};
  }else if(api.endsWith('/products')&&body){
   if(failNext){failNext=false;return route.fulfill({contentType:'application/json',status:503,body:JSON.stringify({success:false,error:'Temporary save failure'})});}
   const product={id:20,name:body.name,price:body.price,currency:body.currency};products=[...products.filter(x=>x.id!==20),product];data={created:true,product,overview:overview(wid)};
  }else if(api.endsWith('/products'))data={products,selected:products.map(x=>({product_id:x.id,name:x.name})),next_cursor:null};
  else if(api.startsWith('/api/seo-impact/sites/'))data=overview(wid);
  return route.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data})});
 });
 const p=await context.newPage();p.on('pageerror',e=>errors.push(e.message));
 const open=async(page,lang='en')=>{await page.goto('http://platform.test/seo-expert?language='+lang);for(const name of ['panel','seo-impact'])await page.addScriptTag({path:path.resolve(__dirname,'../../public_html/assets/js/'+name+'.js')});await page.addStyleTag({content:fs.readFileSync(path.resolve(__dirname,'../../public_html/assets/css/panel.css'),'utf8').replace(/^@import.*$/gm,'')});await page.addStyleTag({path:path.resolve(__dirname,'../../public_html/assets/css/seo-expert.css')});await page.waitForFunction(()=>document.querySelector('#imMessage')?.textContent&&!document.querySelector('#seoImpact').hasAttribute('aria-busy'));};
 const idle=()=>p.waitForFunction(()=>!document.querySelector('#seoImpact').hasAttribute('aria-busy'));
 await open(p);check(writes.length===0,'opening Results performs no import, creation, mapping or financial write');
 await p.locator('#imNativeSetup summary').click();await p.locator('[data-native-product="10"]').waitFor();await p.locator('#imNativeAdd summary').click();await p.locator('#imCatalogManual').click();
 check(await p.locator('#imCatalogForm').isVisible()&&await p.locator('#imCatalogPrice').inputValue()===''&&await p.locator('#imCatalogCurrency').inputValue()==='','manual addition is available beside the selector without inventing price or currency');
 await p.locator('#imCatalogName').fill('Confirmed manual tour');await p.locator('#imCatalogPrice').fill('255.50');await p.locator('#imCatalogCurrency').fill('eur');await p.locator('#imCatalogSave').click();
 check(writes.length===0,'native required confirmation prevents saving unreviewed facts');
 await p.locator('#imCatalogConfirm').check();await p.locator('#imCatalogDescription').fill('Customer-confirmed description');
 check(!await p.locator('#imCatalogConfirm').isChecked(),'editing a confirmed product clears prior factual consent');
 await p.locator('#imCatalogConfirm').check();failNext=true;await p.locator('#imCatalogSave').click();await idle();const key=writes.at(-1).body.request_key;
 check((await p.locator('#imMessage').innerText()).includes('failure')&&await p.locator('#imCatalogName').inputValue()==='Confirmed manual tour','a failed save retains the review form instead of losing customer facts');
 await p.locator('#imCatalogSave').click();await p.waitForFunction(()=>document.querySelector('#imMessage').textContent.includes('Product saved'));
 const saved=writes.filter(x=>x.api.endsWith('/products'));
 check(saved.length===2&&saved[0].body.request_key===saved[1].body.request_key&&/^[a-f0-9]{32}$/.test(key),'retry uses the same product identity so a lost response cannot cause duplicate creation');
 check(saved[1].api==='/api/seo-impact/sites/33/platform-bookings/products'&&saved[1].body.price==='255.50'&&saved[1].body.currency==='EUR'&&saved[1].body.confirmed===true&&saved[1].body.csrf_token==='fixture','save uses the actual Panel CSRF JSON transport and exact reviewed decimal/currency');
 check(await p.locator('[data-native-product="10"]').isChecked()&&await p.locator('[data-native-product="20"]').isChecked(),'adding a product retains the previously mapped selection and selects the newly mapped product');
 check(!await p.locator('#imCatalogConfirm').isChecked()&&await p.locator('#imCatalogForm').isHidden(),'successful creation clears the form and its consent');
 await p.locator('#imNativeAdd summary').click();await p.locator('#imCatalogUrl').fill('https://customer.example/tour/luxor');await p.locator('#imCatalogRead').click();await p.waitForFunction(()=>!document.querySelector('#imCatalogForm').hidden);await idle();
 check(writes.at(-1).api.endsWith('/products/preview')&&writes.filter(x=>x.api.endsWith('/products')).length===2,'reading an import page only requests a preview and never saves automatically');
 check((await p.locator('#imCatalogName').inputValue()).includes('<img')&&await p.locator('#imNativeAdd img').count()===0,'untrusted imported product names render as text without executing HTML');
 check(await p.locator('#imCatalogPrice').inputValue()===''&&await p.locator('#imCatalogCurrency').inputValue()===''&&!await p.locator('#imCatalogConfirm').isChecked(),'missing imported prices/currencies remain blank and require fresh review');
 await p.locator('#imCatalogUrl').fill('https://customer.example/tour/another');check(await p.locator('#imCatalogForm').isHidden(),'changing an imported source invalidates the old review before it can be saved');
 hold=true;await p.locator('#imCatalogRead').click();while(!release)await p.waitForTimeout(20);await p.locator('#seWebsite').selectOption('44');await p.locator('#seWebsite').selectOption('33');release();await idle();
 check(await p.locator('#imCatalogForm').isHidden()&&await p.locator('#imCatalogName').inputValue()===''&&await p.locator('#imCatalogUrl').inputValue()==='','a late import cannot repopulate facts after switching sites away and back');
 const ar=await context.newPage();ar.on('pageerror',e=>errors.push(e.message));await ar.setViewportSize({width:390,height:900});await open(ar,'ar');await ar.locator('#imNativeAdd summary').click();await ar.locator('#imCatalogManual').click();
 check((await ar.locator('#imNativeAdd summary').innerText()).includes('استيراد')&&await ar.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Arabic product addition and review fit a mobile viewport');
 if(process.env.TF_CATALOG_UI_ARTIFACT_DIR){await ar.locator('#imNative').screenshot({path:path.join(process.env.TF_CATALOG_UI_ARTIFACT_DIR,'catalog-add-mobile-ar.png')});}
 check(errors.length===0&&writes.every(x=>/\/products(?:\/preview)?$/.test(x.api)),'the guided journey creates no booking, payment, approval, paid provider request or JavaScript error');
}finally{await b.close();}console.log(n+' assertions passed');})().catch(e=>{console.error(e.stack);process.exitCode=1});
