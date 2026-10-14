(() => {
 'use strict';
 const start=()=>{
  const root=document.getElementById('seoExpertRoot');
  const core=document.getElementById('seWorkspace');
  if(!root||!core||document.getElementById('seNavigation'))return;
  const ar=document.documentElement.lang.toLowerCase().startsWith('ar');
  const t=(a,e)=>ar?a:e;
  const pages=[
   ['overview',t('نظرة عامة','Overview'),t('حالة الخدمة والخطوة التالية لموقعك.','Your service state and next step.')],
   ['scan',t('فحص الموقع والمنافسين','Site & competitor research'),t('ابدأ الفحص، ثم انتقل للخطة لمراجعة المقترحات.','Start an inspection, then review the proposals in Plan.')],
   ['plan',t('خطة التحسين','Improvement plan'),t('اختر دورة، راجع المهام والحقائق، واعتمد التعديلات المناسبة.','Choose a cycle, review tasks and facts, then approve appropriate changes.')],
   ['execute',t('تنفيذ التحسينات','Apply improvements'),t('حرّر صفحة أو رحلة موجودة، وراجع المعاينة قبل الحفظ.','Edit an existing page or tour and review the preview before saving.')],
   ['results',t('النتائج والسجل','Results & history'),t('راجع نتائج الدورات، وقارن الأداء بالبيانات المتاحة.','Review cycle outcomes and compare performance using available data.')],
   ['settings',t('الربط والإعدادات','Connections & settings'),t('إدارة الربط، وبيانات الشركة، وحدود العمل وتكلفته.','Manage connections, company facts, work limits and cost.')],
   ['tours',t('إدارة الرحلات','Tour management'),t('جهّز رحلة جديدة كمسودة، ثم استكمل بيانات الحجز قبل نشرها.','Prepare a new tour draft and complete booking details before publishing.')]
  ];
  const allowed=new Set(pages.map(p=>p[0]));
  const selected=()=>Number(document.getElementById('seWebsite')?.value||0);
  const pageFromURL=()=>{const p=new URL(location.href).searchParams.get('page');return allowed.has(p)?p:'overview';};
  let active=pageFromURL(),state=null,frame=0;
  const make=(tag,cls,text)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text)n.textContent=text;return n;};
  const urlFor=page=>{const u=new URL(location.href);u.searchParams.set('page',page);const id=selected();if(id)u.searchParams.set('website_id',String(id));else u.searchParams.delete('website_id');u.hash='';return u.pathname+u.search;};
  const link=(page,text)=>{const a=make('a','p-btn outline',text);a.dataset.sePage=page;a.href=urlFor(page);return a;};
  const navigation=make('nav','se-navigation');navigation.id='seNavigation';navigation.setAttribute('aria-label',t('صفحات خدمة SEO وإدارة الرحلات','SEO service pages and tour management'));
  const navMain=make('div','se-navigation-main');
  for(const [key,label] of pages){const a=link(key,label);a.className='se-navigation-link';if(key==='tours')a.classList.add('se-navigation-tours');navMain.append(a);}
  navigation.append(navMain);
  const head=root.querySelector('.se-head');if(head)head.after(navigation);else root.prepend(navigation);
  const pageHead=make('div','se-page-heading');
  const heading=make('h2');heading.id='sePageHeading';heading.tabIndex=-1;
  const description=make('p');pageHead.append(heading,description);navigation.after(pageHead);
  const corePages=make('div');corePages.id='seCorePages';core.append(corePages);
  // Keep add-on modules outside seWorkspace: its legacy busy gate must not disable their independent controls.
  const addonPages=make('div');addonPages.id='seAddonPages';core.after(addonPages);
  const panels=new Map();
  for(const [key,label] of pages){const pane=make('section','se-workspace-page');pane.id='sePage-'+key;pane.dataset.sePanel=key;pane.setAttribute('aria-label',label);panels.set(key,pane);(key==='execute'||key==='results'||key==='tours'?addonPages:corePages).append(pane);}
  const move=(id,page)=>{const n=document.getElementById(id),dest=panels.get(page);if(n&&!dest.contains(n))dest.append(n);};
  for(const id of ['seWorker','seScope'])move(id,'overview');
  for(const id of ['seManageConnections','seConnectionGrid','seProbe','seProbeResults','seConfig'])move(id,'settings');
  move('seQuote','scan');
  const confirm=document.getElementById('seGenerateConfirm')?.closest('label');if(confirm)panels.get('scan').append(confirm);
  const scanActions=make('div','se-actions');panels.get('scan').append(scanActions);
  const settingsActions=make('div','se-actions');panels.get('settings').append(settingsActions);
  for(const id of ['seStart','sePause','seResume']){const n=document.getElementById(id);if(n)(id==='seStart'?scanActions:settingsActions).append(n);}
  for(const id of ['seRuns','seDetail'])move(id,'plan');
  // Remove only the now-empty former action wrapper; never replace a form or attached handlers.
  for(const n of [...core.children])if(n.classList.contains('se-actions')&&!n.children.length)n.remove();
  const manage=link('settings',t('إعدادات الربط','Connection settings'));manage.className='se-connection-link';
  document.getElementById('seConnectionSummary')?.after(manage);
  const overviewCard=make('article','se-card se-overview-card');
  const nextHeading=make('h3','',t('الخطوة التالية','Next step'));const nextText=make('p');nextText.id='seNextStep';
  const nextActions=make('div','se-actions');overviewCard.append(nextHeading,nextText,nextActions);panels.get('overview').prepend(overviewCard);
  const quick=make('div','se-grid se-overview-grid');
  for(const key of ['scan','plan','execute','results']){const p=pages.find(x=>x[0]===key),card=make('article','se-card');card.append(make('h3','',p[1]),make('p','',p[2]),link(key,t('افتح الصفحة','Open page')));quick.append(card);}
  panels.get('overview').append(quick);
  const research=make('article','se-card');research.append(make('h3','',t('بحث المنافسين والكلمات','Competitor & keyword research')),make('p','',t('افتح أدوات البحث الموجودة في المنصة. بدء تحليل جديد يتبع التكلفة والموافقة المعروضة في أداته.','Open the existing research tools. New analysis follows the cost and approval shown by each tool.')));
  const researchLinks=make('div','se-actions');
  for(const [href,label] of [['/competitors',t('تحليل المنافسين','Competitor analysis')],['/keywords',t('بحث الكلمات','Keyword research')]]){const a=make('a','p-btn outline',label);a.href=href;a.target='_blank';a.rel='noopener noreferrer';researchLinks.append(a);}
  research.append(researchLinks);panels.get('scan').append(research);
  const cycleChoices=make('article','se-card');cycleChoices.append(make('h3','',t('الفحوصات المحفوظة','Stored inspections')));const scanRuns=make('div','se-actions');scanRuns.id='seScanRuns';cycleChoices.append(scanRuns);panels.get('scan').append(cycleChoices);
  const planHint=make('p','se-note',t('افتح دورة من القائمة لعرض الخطة. إنشاء دورة جديدة موجود في صفحة الفحص.','Open a cycle below to view its plan. Start a new cycle on the inspection page.'));panels.get('plan').prepend(planHint);
  const reports=make('article','se-card');reports.append(make('h3','',t('سجل دورات التحسين','Improvement cycle history')));const resultRuns=make('div','se-actions');resultRuns.id='seResultRuns';reports.append(resultRuns,make('p','p-cell-muted',t('حالة الدورة وحدها لا تثبت نجاح كل مهامها. افتحها لقراءة التحقق والمهام التي تحتاج مراجعة.','A cycle state alone does not prove every task succeeded. Open it to review verification and unresolved tasks.')),link('execute',t('سجل تعديلات صفحة: افتح الصفحة ثم اضغط سجل التعديلات','Page edit history: open a page, then choose Edit history')));panels.get('results').append(reports);
  const resultHelp=make('p','se-note',t('التغييرات المحفوظة وظهورها تُراجع داخل المحرر. قياس الزيارات والنتائج يظهر أدناه حسب الربط والبيانات المتاحة.','Review saved changes and rendering in the editor. Traffic and outcome measurement appears below when connections and data are available.'));panels.get('results').append(resultHelp);
  const placeholders=new Map();
  for(const [page,label] of [['execute',t('المحرر غير متاح. راجع تحميل ملفات التحديث ثم أعد فتح الصفحة.','Editor unavailable. Check update files and reload the page.')],['results',t('لوحة قياس النتائج غير متاحة في هذه النسخة.','Measurement dashboard is unavailable in this version.')],['tours',t('أداة إنشاء مسودة الرحلة غير متاحة. راجع تحديث محرر الرحلات.','Tour draft creation is unavailable. Check the tour editor update.')]]){const n=make('p','se-note',label);placeholders.set(page,n);panels.get(page).append(n);}
  const adoptModules=()=>{
   for(const [id,page] of [['seoPageEditor','execute'],['seoImpact','results'],['tfTourDraft','tours']]){const node=document.getElementById(id);if(node){move(id,page);placeholders.get(page).hidden=true;}}
  };
  const updateLinks=()=>{for(const a of root.querySelectorAll('a[data-se-page]')){a.href=urlFor(a.dataset.sePage);if(a.closest('#seNavigation')){if(a.dataset.sePage===active)a.setAttribute('aria-current','page');else a.removeAttribute('aria-current');}}};
  const renderNext=()=>{
   nextActions.replaceChildren();
   if(!selected()){nextText.textContent=t('أضف موقعك أو اختَر موقعًا من القائمة لبدء الخدمة.','Add or choose a website to begin.');const a=make('a','p-btn',t('إدارة المواقع','Manage websites'));a.href='/websites';nextActions.append(a);return;}
   if(!state||state.websiteId!==selected()){nextText.textContent=t('جارٍ تحميل حالة الموقع. لو استمر الانتظار، اضغط تحديث الحالة.','Loading website state. If this continues, choose Refresh status.');return;}
   if(!state.configured){nextText.textContent=t('أكمل بيانات الشركة وحدود العمل، ثم ابدأ أول فحص.','Complete company facts and work limits, then start your first inspection.');nextActions.append(link('settings',t('أكمل الإعداد','Complete setup')));}
   else if(!state.workerReady){nextText.textContent=t('المتابعة في الخلفية تحتاج مراجعة مهام السيرفر. راجع التنبيه أدناه قبل بدء دورة.','Background work requires attention to the server worker. Review the notice below before starting a cycle.');nextActions.append(link('settings',t('راجع الإعدادات','Review settings')));}
   else if(state.cycleBlock){nextText.textContent=state.cycleBlock;nextActions.append(link('plan',t('تابع الدورات الحالية','Follow existing cycles')),link('settings',t('راجع الحدود','Review limits')));}
   else if(state.runs.length){nextText.textContent=t('راجع آخر دورة والمهام المطلوبة قبل بدء دورة جديدة.','Review your latest cycle and required tasks before starting a new one.');nextActions.append(runLink(state.runs[0]));}
   else {nextText.textContent=t('إعداداتك محفوظة. افتح الفحص وراجع الاستخدام المعروض قبل البدء.','Setup is saved. Open inspection and review the displayed usage before starting.');nextActions.append(link('scan',t('ابدأ الفحص','Start inspection')));}
   nextActions.append(link('tours',t('إدارة الرحلات','Tour management')));
  };
  const runLink=run=>{const source=root.querySelector('#seRuns [data-run="'+run.id+'"]');const a=link('plan',source?.textContent||'#'+run.id);a.dataset.seOpenRun=String(run.id);return a;};
  const renderRuns=()=>{
   for(const dest of [scanRuns,resultRuns]){dest.replaceChildren();if(!state||state.websiteId!==selected()||!state.runs.length){dest.append(make('p','',t('لا توجد دورة معروضة لهذا الموقع. حدّث الحالة أو ابدأ فحصًا جديدًا.','No cycle is available for this website. Refresh status or start an inspection.')));continue;}for(const run of state.runs)dest.append(runLink(run));}
  };
  const show=(page,focus=false)=>{
   active=allowed.has(page)?page:'overview';const info=pages.find(p=>p[0]===active);
   for(const [key,pane] of panels)pane.hidden=key!==active;
   heading.textContent=info[1];description.textContent=info[2];root.dataset.sePage=active;
   document.title=info[1]+' | Tourfecto';updateLinks();adoptModules();
   window.dispatchEvent(new CustomEvent('tourfecto-seo-page',{detail:{page:active,websiteId:selected()}}));
   if(focus)heading.focus({preventScroll:true});
  };
  const go=page=>{const next=allowed.has(page)?page:'overview';if(next!==active)history.pushState(null,'',urlFor(next));show(next,true);};
  root.addEventListener('click',event=>{
   const a=event.target.closest('a[data-se-page]');if(!a||event.defaultPrevented||event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
   event.preventDefault();if(a.dataset.seOpenRun){const b=root.querySelector('#seRuns [data-run="'+Number(a.dataset.seOpenRun)+'"]');if(b&&!b.disabled)b.click();}go(a.dataset.sePage);
  });
  window.addEventListener('popstate',()=>{const page=pageFromURL();history.replaceState(null,'',urlFor(page));show(page,true);});
  window.addEventListener('tourfecto-seo-overview',event=>{const d=event.detail;if(!d||d.websiteId!==selected())return;state=d;renderNext();renderRuns();updateLinks();history.replaceState(null,'',urlFor(active));});
  document.getElementById('seWebsite').addEventListener('change',()=>{state=null;renderNext();renderRuns();updateLinks();history.replaceState(null,'',urlFor(active));});
  // Existing transfers keep their content and permissions; navigation only reveals the destination.
  for(const name of ['tourfecto-editor-open','tourfecto-editor-draft','tourfecto-editor-task'])window.addEventListener(name,()=>go('execute'));
  window.addEventListener('tourfecto-seo-cycle-started',()=>go('plan'));
  const observer=new MutationObserver(()=>{if(!frame)frame=requestAnimationFrame(()=>{frame=0;adoptModules();updateLinks();});});
  observer.observe(root.parentElement,{childList:true,subtree:true});
  window.addEventListener('pagehide',event=>{if(!event.persisted){observer.disconnect();if(frame)cancelAnimationFrame(frame);}},{once:true});
  renderNext();renderRuns();show(active);
 };
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
