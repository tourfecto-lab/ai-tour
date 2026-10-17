const fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
(async()=>{let n=0;const check=(ok,label)=>{if(!ok)throw Error(label);n++;console.log('  ok  '+label);};const b=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});try{
 const p=await b.newPage(),errors=[],writes=[];p.on('pageerror',e=>errors.push(e.message));
 const article=fs.readFileSync(process.env.TF_GUIDE_UI_ARTIFACT||'/tmp/content-polish-guide.html','utf8');
 await p.route('http://guide.test/**',r=>r.fulfill({contentType:'text/html',body:article}));
 await p.goto('http://guide.test/?tf_article=discover-egypt');
 check(await p.locator('header').innerText()==='Customer brand navigation'&&await p.locator('footer').innerText()==='Customer booking footer','real connector output retains customer theme in browser');
 check(await p.locator('h1').count()===1&&await p.locator('h2').innerText()==='Luxor temples','one article title with semantic section heading');
 check(await p.locator('[data-tf-related] a').getAttribute('href')!==null,'contextual trip link is clickable');
 check((await p.locator('link[rel=canonical]').getAttribute('href')).includes('?tf_article=discover-egypt'),'query canonical survives browser parsing');
 check(await p.locator('script[type="application/ld+json"]').count()===1,'one article/FAQ graph rendered');
 await p.setViewportSize({width:390,height:844});check(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'article fits mobile viewport');
 const q=await b.newPage();q.on('pageerror',e=>errors.push(e.message));
 const checks={canonical:'passed',indexable:'passed',article_schema:'passed',headings:'passed',faq_schema:'not_required',internal_links:'missing',sitemap:'not_measured'};
 await q.route('http://platform.test/**',async r=>{
  const request=r.request(),u=new URL(request.url()),route=u.searchParams.get('__tf_api')||u.pathname;
  if(request.method()!=='GET')writes.push(route);
  if(!route.startsWith('/api/')){await r.fulfill({contentType:'text/html',body:'<!doctype html><html lang="en"><meta charset="utf-8"><section id="seoExpertRoot" data-user-id="1"></section></html>'});return;}
  let data={};
  if(route.endsWith('/setup'))data={installed:true,worker_ready:true,csrf_token:'fixture',websites:[{id:32,domain:'site.example'}]};
  else if(route.endsWith('/sites/32'))data={site:{id:32,url:'https://site.example'},worker_ready:true,quote:{allowed:true,source:'plan'},config:{status:'active',version:1,cycles_used:1,brief:{business_name:'Fixture Travel',business_facts:'Confirmed tours',audience:'Travelers',goal:'Bookings',language:'en'},policy:{max_cycles:3,max_cycle_price:0,max_pages:100}},runs:[{id:7,status:'completed'}],connections:{}};
  else if(route.endsWith('/probe'))data={connector:{state:'publicly_observed'},metrics:{gsc:{state:'measured'},analytics:{state:'measured'}},publishing:{state:'authenticated'}};
  else if(route.endsWith('/runs/7'))data={identity_valid:true,worker_ready:true,run:{id:7,status:'completed',version:1,summary:'Approved content is live'},tasks:[{id:99,kind:'content',category:'aeo',status:'verified',title:'Discover Egypt',page_url:'https://site.example/',published_url:'https://site.example/?tf_article=discover-egypt',expected_value:'Approved text',is_active:1,evidence:{reason:'Approved text was observed',publication_quality:{state:'needs_review',checks}},verification:{state:'verified_recently'}}]};
  await r.fulfill({contentType:'application/json',body:JSON.stringify({success:true,data})});
 });
 await q.goto('http://platform.test/seo-expert?website_id=32&page=plan');
 await q.addScriptTag({path:path.resolve(__dirname,'../../public_html/assets/js/panel.js')});
 await q.addScriptTag({path:path.resolve(__dirname,'../../public_html/assets/js/seo-expert.js')});
 await q.locator('[data-run="7"]').click();await q.locator('#seDetail').filter({hasText:'Publication quality needs review'}).waitFor();
 const text=await q.locator('#seDetail').innerText();
 check(text.includes('Canonical: Passed')&&text.includes('Sitemap entry: Not measured'),'real SEO UI distinguishes passed and unavailable evidence');
 check(text.includes('Related trip links: Missing or different')&&text.includes('Not required for this draft'),'real SEO UI explains missing links and optional FAQ separately');
 check(text.includes('does not prove Google indexing'),'UI distinguishes sitemap discovery from Google indexing');
 check(writes.every(route=>route.endsWith('/probe')),'viewing quality checks only probes connections; no publication or paid cycle request');
 check(errors.length===0,'no browser JavaScript errors');
}finally{await b.close();}console.log(`\n${n}/${n} passed`);})().catch(e=>{console.error(e.message);process.exit(1)});
