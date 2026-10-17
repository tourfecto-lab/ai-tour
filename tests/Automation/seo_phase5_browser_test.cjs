const fs=require('fs'),path=require('path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE_PATH||'playwright');
(async()=>{
const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',headless:true,args:['--no-sandbox']});const page=await browser.newPage();let n=0,fails=0;
function check(ok,label){n++;if(!ok)fails++;console.log((ok?'  ok  ':'  FAIL ')+label);}
const root=path.resolve(__dirname,'../..'),controller=fs.readFileSync(path.join(root,'app/Controllers/LocalSeoController.php'),'utf8');
const match=controller.match(/\$body='([\s\S]*?)<script defer/);if(!match)throw Error('Real controller markup unavailable');
await page.setContent(match[1]);
await page.evaluate(()=>{
 const points=Array.from({length:25},(_,i)=>({latitude:30+(2-Math.floor(i/5))*.01,longitude:31+(i%5-2)*.01}));
 const run={id:1,month_start:'2026-10-01',status:'complete',config:{keywords:['Luxor <script>bad</script>','Cairo tours'],points},points:[...Array.from({length:25},(_,i)=>({keyword_index:0,point_index:i,state:'measured',rank_position:i===24?null:i%15+1,checked_at:'2026-10-17'})),...Array.from({length:25},(_,i)=>({keyword_index:1,point_index:i,state:'measured',rank_position:2}))]};
 window.calls=[];window.Panel={fetchJSON:async(url,options={})=>{const action=url.split('/').pop().split('?')[0],body=options.body?JSON.parse(options.body):null;window.calls.push({action,body});
 if(action==='sites')return {success:true,data:{sites:[{id:1,main_url:'https://site.example'}]}};
 if(action==='data')return {success:true,data:{runs:[run],tasks:[{source_url:'https://directory.example/<unsafe>',evidence_json:JSON.stringify({checks:{name:{state:'mismatch',observed:'<img src=x onerror=alert(1)>'}}})}]}};
 if(action==='run'){if(!body.paid_confirmed)return {success:false,error:'Confirm paid measurements'};return {success:true,data:{run_id:1}};}
 if(action==='nap')return {success:true,data:{observations:[]}};
 if(action==='profile')return {success:true,data:{}};
 if(action==='links')return {success:true,data:{available:true,candidates:[{domain:'editor.example',signals:{linked_competitors:2},collaboration_idea:'Review only'}]}};
 throw Error('Unexpected request '+url);
 }};
});
await page.addScriptTag({path:path.join(root,'public_html/assets/js/local-seo.js')});
await page.waitForFunction(()=>document.querySelectorAll('[role=cell]').length===50);
check(await page.locator('[role=table]').count()===2&&await page.locator('[role=cell]').count()===50,'actual customer JS renders two 5x5 keyword heatmaps');
check((await page.locator('[role=cell]').allTextContents()).includes('لم يظهر'),'measured absence stays explicit, never rank zero');
check(await page.locator('#localGrid script,#localTasks img').count()===0,'keyword and directory evidence cannot inject markup');
check(await page.locator('#localHistory').textContent()==='2026-10-01 · #1 · complete','monthly run history is selectable');
await page.locator('#localKeywords').fill('Luxor tours\nCairo tours');await page.locator('#localRun').click();await page.waitForFunction(()=>document.getElementById('localStatus').textContent.includes('Confirm paid'));
check(await page.evaluate(()=>window.calls.filter(c=>c.action==='run').at(-1).body.paid_confirmed===false),'paid confirmation reaches server as explicit false until checked');
await page.locator('#localPaid').check();await page.locator('#localRun').click();await page.waitForFunction(()=>document.getElementById('localStatus').textContent.includes('أُضيفت الشبكة'));
check(await page.evaluate(()=>window.calls.filter(c=>c.action==='run').at(-1).body.website_id===1&&window.calls.filter(c=>c.action==='run').at(-1).body.keywords.length===2),'paid grid action is scoped to chosen website and keywords');
check(!await page.locator('#localPaid').isChecked(),'paid confirmation resets after enqueue');
await page.locator('#localLinks').click();await page.waitForFunction(()=>document.getElementById('localLinkGap').textContent.includes('editor.example'));
check((await page.locator('#localLinkGap').textContent()).includes('2 منافسين'),'real link-gap results show measured competitor count');
await browser.close();console.log(`\n${n-fails}/${n} passed`);process.exit(fails?1:0);
})().catch(e=>{console.error(e);process.exit(1);});
