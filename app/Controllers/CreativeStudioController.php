<?php

/**
 * Tourfecto - Creative Studio Controller
 * لوحة Creative Studio: مكتبة وسائط (صور + فيديوهات قصيرة بالذكاء
 * الاصطناعي) + سكربتات فيديو + نشر/جدولة مباشرة على السوشيال ميديا
 * (بيعيد استخدام SocialPostService/PublishSocialPostJob الموجودين
 * فعلاً - نفس تدفق صفحة /social، بس بادئ من هنا وبالوسائط مربوطة
 * تلقائيًا).
 * @version 2.0.0
 */
class CreativeStudioController extends Controller
{
    /** @var MediaGenerationService */
    private $mediaService;
    /** @var VideoScriptService */
    private $videoService;

    public function __construct()
    {
        parent::__construct();
        $this->mediaService = new MediaGenerationService();
        $this->videoService = new VideoScriptService();
    }

    /** GET /creative-studio */
    public function index(array $params = []): array
    {
        $studioCss=asset_v('/assets/css/creative-studio-pro.css');
        $studioJs=asset_v('/assets/js/creative-studio-pro.js');
        $editorJs=asset_v('/assets/js/creative-brand-editor.js');
        $videoEditorJs=asset_v('/assets/js/creative-video-editor.js');
        $studioUserId=(int)$this->user['id'];
        $body = <<<HTML
        <link rel="stylesheet" href="{$studioCss}">
        <script src="{$videoEditorJs}"></script>
        <script src="{$editorJs}"></script>
        <script src="{$studioJs}"></script>
        <section class="cs-hero" id="csHero" data-account="{$studioUserId}">
            <div><span class="cs-eyebrow">TOURFECTO CREATIVE STUDIO</span><h2 data-cs-title>من الفكرة إلى حملة بصرية</h2><p data-cs-intro>صمّم صورًا وفيديوهات تعبر عن وجهتك وهويتك. وجّه الإضاءة والتكوين، تابع مراحل التوليد، وحمّل النتيجة الأصلية أو انشرها.</p></div><div class="cs-emblem" aria-hidden="true">✦</div>
            </section>
            <section class="p-card" style="padding:14px 18px;margin:12px 0;display:flex;gap:12px;align-items:center;flex-wrap:wrap;justify-content:space-between">
                <div><b>📷 عندك صور حقيقية لرحلاتك؟ دي أحلى من أي صورة متولدة</b><div class="p-cell-muted" style="font-size:13px">ارفع صورك في "خطة الشهر" والذكاء الصناعي بيتعلم منها ويعمل بوستات بلوجو شركتك وألوانها - مجانًا من غير توليد صور.</div></div>
                <a class="p-btn" href="/social">صمّم بوستات من صوري</a>
        </section>
        <div class="p-toolbar">
            <button class="p-btn" onclick="document.getElementById('mediaModal').classList.add('open')">🎨 توليد صورة</button>
            <button class="p-btn" onclick="document.getElementById('genVideoModal').classList.add('open')">🎥 توليد فيديو قصير</button>
            <button class="p-btn outline" onclick="document.getElementById('videoModal').classList.add('open')">📝 سكربت فيديو</button>
        </div>

        <div class="p-tabs" id="csTabs" style="margin-bottom:16px;">
            <button class="p-tab" data-tab="brand">🎯 هوية العلامة والخطة</button>
            <button class="p-tab active" data-tab="media">🖼️ الصور والفيديوهات</button>
            <button class="p-tab" data-tab="scripts">📝 سكربتات الفيديو</button>
            <button class="p-tab" data-tab="stats">📊 الإحصائيات</button>
        </div>

        <div id="statsPanel" style="display:none;">
            <div id="statsBox"><div class="p-empty">جارِ التحميل...</div></div>
        </div>

        <div id="brandPanel" style="display:none;">
            <div class="p-card" style="margin-bottom:16px;">
                <div class="p-card-head"><h3>1) حلّل موقعك</h3><span class="p-card-sub">الذكاء الاصطناعي بيسحب اللوجو والألوان والأسلوب ويبني هوية علامتك</span></div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <input type="text" id="brandUrl" class="p-select" style="flex:1;min-width:240px;" placeholder="https://yourwebsite.com" dir="ltr">
                    <button class="p-btn" id="brandAnalyzeBtn" onclick="analyzeBrand()">تحليل الموقع</button>
                </div>
            </div>
            <div id="brandCard"></div>
            <div id="planBox"></div>
        </div>

        <div class="p-grid cols-4" id="mediaGrid"><div class="p-empty">جارِ التحميل...</div></div>
        <div id="scriptsGrid" style="display:none;"><div class="p-empty">جارِ التحميل...</div></div>

        <!-- توليد صورة -->
        <div class="p-modal-overlay" id="mediaModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>توليد صورة تسويقية احترافية</h3><button class="p-modal-close" onclick="document.getElementById('mediaModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label>النوع</label>
                    <select id="mediaType" class="p-select" style="width:100%;margin-bottom:10px;">
                        <option value="social_image">صورة سوشيال ميديا (1:1)</option>
                        <option value="marketing_image">صورة تسويقية (4:3)</option>
                        <option value="instagram_post">منشور انستجرام (1:1)</option>
                        <option value="facebook_cover">غلاف فيسبوك (16:9)</option>
                        <option value="youtube_thumbnail">صورة يوتيوب مصغرة (16:9)</option>
                        <option value="story">ستوري (9:16)</option>
                        <option value="reels_cover">غلاف ريلز (9:16)</option>
                    </select>
                    <label>الأسلوب البصري</label>
                    <select id="mediaStyle" class="p-select" style="width:100%;margin-bottom:10px;">
                        <option value="photo">تصوير احترافي واقعي</option>
                        <option value="cinematic">سينمائي</option>
                        <option value="product">تصوير منتج (استوديو)</option>
                        <option value="illustration">رسم/إليستريشن</option>
                        <option value="minimal">مينيمال بسيط</option>
                    </select>
                    <label>الوصف</label>
                    <textarea id="mediaPrompt" rows="3" style="width:100%;" class="p-select" placeholder="مثال: منظر شاطئ الغردقة عند الغروب بألوان دافئة"></textarea>
                    <button class="p-btn outline xs" style="margin-top:8px;" onclick="enhancePrompt('mediaPrompt', 'image', this)">✨ تحسين الوصف بالذكاء الاصطناعي</button>
                </div>
                <div class="p-modal-foot"><button class="p-btn" id="genImageBtn" onclick="requestMedia()">توليد الصورة</button></div>
            </div>
        </div>

        <!-- توليد فيديو قصير حقيقي -->
        <div class="p-modal-overlay" id="genVideoModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>توليد فيديو قصير بالذكاء الاصطناعي</h3><button class="p-modal-close" onclick="document.getElementById('genVideoModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label>الوصف (المشهد اللي عايز الفيديو يوريه)</label>
                    <textarea id="genVideoPrompt" rows="3" style="width:100%;" class="p-select" placeholder="مثال: لقطة سينمائية لطائر بحري يحلّق فوق شاطئ الغردقة وقت الغروب"></textarea>
                    <button class="p-btn outline xs" style="margin-top:8px;margin-bottom:10px;" onclick="enhancePrompt('genVideoPrompt', 'video', this)">✨ تحسين الوصف بالذكاء الاصطناعي</button>
                    <label>المنصة (بيحدد شكل الفيديو)</label>
                    <select id="genVideoPlatform" class="p-select" style="width:100%;margin-bottom:10px;">
                        <option value="instagram_reels">Instagram Reels (عمودي)</option>
                        <option value="tiktok">TikTok (عمودي)</option>
                        <option value="youtube_shorts">YouTube Shorts (عمودي)</option>
                        <option value="general_landscape">عرضي عام (16:9)</option>
                    </select>
                    <label>الأسلوب البصري</label>
                    <select id="genVideoStyle" class="p-select" style="width:100%;margin-bottom:10px;">
                        <option value="cinematic">سينمائي</option>
                        <option value="photo">واقعي احترافي</option>
                        <option value="product">عرض منتج</option>
                        <option value="minimal">مينيمال بسيط</option>
                    </select>
                    <label>المدة</label>
                    <select id="genVideoDuration" class="p-select" style="width:100%;">
                        <option value="4">4 ثواني</option>
                        <option value="6">6 ثواني</option>
                        <option value="8" selected>8 ثواني</option>
                    </select>
                    <p class="p-cell-muted" style="font-size:12px;margin-top:10px;">وقت التوليد متغير حسب الضغط على الخدمة. تابع المراحل من المكتبة ويمكنك العودة لاحقًا.</p>
                </div>
                <div class="p-modal-foot"><button class="p-btn" id="genVideoBtn" onclick="requestVideo()">توليد الفيديو</button></div>
            </div>
        </div>

        <!-- سكربت فيديو نصي -->
        <div class="p-modal-overlay" id="videoModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>سكربت فيديو قصير</h3><button class="p-modal-close" onclick="document.getElementById('videoModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label>الموضوع</label>
                    <input type="text" id="videoTopic" class="p-select" style="width:100%;margin-bottom:10px;">
                    <label>المنصة</label>
                    <select id="videoPlatform" class="p-select" style="width:100%;margin-bottom:10px;">
                        <option value="tiktok">TikTok</option>
                        <option value="instagram_reels">Instagram Reels</option>
                        <option value="youtube_shorts">YouTube Shorts</option>
                    </select>
                    <label>المدة (ثانية)</label>
                    <select id="videoDuration" class="p-select" style="width:100%;">
                        <option value="15">15 ثانية</option>
                        <option value="30" selected>30 ثانية</option>
                        <option value="60">60 ثانية</option>
                    </select>
                </div>
                <div class="p-modal-foot"><button class="p-btn" id="genScriptBtn" onclick="requestVideoScript()">توليد السكربت</button></div>
            </div>
        </div>

        <!-- نشر / جدولة عنصر وسائط -->
        <div class="p-modal-overlay" id="publishModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>نشر / جدولة</h3><button class="p-modal-close" onclick="document.getElementById('publishModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <input type="hidden" id="publishMediaId">
                    <label>نص المنشور</label>
                    <textarea id="publishContent" rows="4" style="width:100%;" class="p-select"></textarea>
                    <button class="p-btn outline xs" id="publishCaptionBtn" style="margin-top:8px;" onclick="generatePublishCaption()">✨ توليد نص بالذكاء الاصطناعي</button>
                    <div id="publishCaptionErr" style="display:none;margin-top:8px;color:#f87171;font-size:12.5px;"></div>

                    <label style="margin-top:14px;display:block;">انشر على</label>
                    <div id="publishTargetsList" class="p-cell-muted">جارِ تحميل الصفحات المتصلة...</div>

                    <label style="margin-top:14px;display:block;">التوقيت</label>
                    <div style="display:flex;gap:14px;align-items:center;margin-bottom:8px;">
                        <label style="display:flex;align-items:center;gap:6px;"><input type="radio" name="publishWhen" value="now" checked onchange="togglePublishSchedule()"> انشر الآن</label>
                        <label style="display:flex;align-items:center;gap:6px;"><input type="radio" name="publishWhen" value="later" onchange="togglePublishSchedule()"> جدولة لموعد لاحق</label>
                    </div>
                    <input type="datetime-local" id="publishScheduleAt" class="p-select" style="width:100%;display:none;">
                </div>
                <div class="p-modal-foot">
                    <button class="p-btn" id="publishSubmitBtn" onclick="submitPublish()">نشر</button>
                </div>
            </div>
        </div>
HTML;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON;
    const jsonHeaders = { 'Content-Type': 'application/json' };

    document.querySelectorAll('#csTabs .p-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('#csTabs .p-tab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const tab = btn.dataset.tab;
            document.getElementById('mediaGrid').style.display = tab === 'media' ? 'grid' : 'none';
            document.getElementById('brandPanel').style.display = tab === 'brand' ? 'block' : 'none';
            document.getElementById('statsPanel').style.display = tab === 'stats' ? 'block' : 'none';
            if (tab === 'stats') { loadStats(true); }
            document.getElementById('scriptsGrid').style.display = tab === 'scripts' ? 'block' : 'none';
        });
    });

    const studio=window.CreativeStudioPro.init(P,Number(document.getElementById('csHero').dataset.account));
    const load=studio.load;

    async function loadScripts() {
        const res = await fetchJSON('/api/creative-studio/video-scripts');
        const box = document.getElementById('scriptsGrid');
        if (res.success && res.data.scripts && res.data.scripts.length) {
            box.innerHTML = res.data.scripts.map(s => {
                let scenes = [];
                try { scenes = JSON.parse(s.scenes || '[]'); } catch (e) { scenes = []; }
                const scenesHtml = scenes.length ? `
                    <div class="p-table-scroll" style="margin-top:10px;"><table class="p-table">
                        <thead><tr><th>التوقيت</th><th>المشهد المرئي</th><th>الصوت (Voiceover)</th></tr></thead>
                        <tbody>${scenes.map(sc => `<tr><td style="white-space:nowrap;">${esc(sc.time || '-')}</td><td>${esc(sc.visual || '-')}</td><td>${esc(sc.voiceover || '-')}</td></tr>`).join('')}</tbody>
                    </table></div>` : '';

                return `
                <div class="p-card" style="margin-bottom:14px;">
                    <div class="p-card-head">
                        <h3>${esc(s.topic)}</h3>
                        <span class="p-card-sub">${esc(s.platform)} · ${esc(s.duration_seconds)} ثانية</span>
                    </div>
                    ${s.status === 'completed'
                        ? `<p style="white-space:pre-wrap;line-height:1.8;background:var(--panel-bg,#f7f8fa);padding:12px 14px;border-radius:8px;">${esc(s.script_text || '')}</p>${scenesHtml}
                           <button class="p-btn outline xs" style="margin-top:10px;" onclick="copyScript(this)" data-text="${esc(s.script_text || '')}">📋 نسخ النص</button>`
                        : (s.status === 'failed'
                            ? `<div class="alert alert-danger">فشل توليد السكربت</div>`
                            : `<div class="p-cell-muted">⏳ جارِ التوليد...</div>`)
                    }
                </div>`;
            }).join('');
        } else {
            box.innerHTML = '<div class="p-empty"><div class="p-empty-icon">🎬</div>لا يوجد سكربتات فيديو بعد</div>';
        }
    }

    window.copyScript = function (btn) {
        navigator.clipboard.writeText(btn.dataset.text).then(() => P.toast('اتنسخ النص ✔', 'success'));
    };

    function statusLabel(status) {
        return { completed: 'جاهزة', failed: 'فشل التوليد', generating: 'جارِ التوليد...' }[status] || status;
    }

    window.enhancePrompt = async function (textareaId,kind,btn) {
        const el=document.getElementById(textareaId),original=btn.textContent,before=el.value;
        if(!before.trim())return;btn.disabled=true;btn.textContent='…';
        try{const res=await fetchJSON('/api/creative-studio/enhance-prompt',{method:'POST',headers:jsonHeaders,body:JSON.stringify({prompt:before.trim(),kind})});if(res.success&&typeof res.data?.prompt==='string'){if(el.value===before){el.value=res.data.prompt;el.dispatchEvent(new Event('input'));}else P.toast('The draft changed; your current text was retained.','info');}else P.toast('تعذر تحسين الوصف / Could not enhance prompt','error');}catch(e){P.toast('تعذر الاتصال / Connection unavailable','error');}finally{btn.disabled=false;btn.textContent=original;}
    };

    window.requestMedia=()=>studio.request('image',currentBrand?Number(currentBrand.id):0);
    window.requestVideo=()=>studio.request('video',currentBrand?Number(currentBrand.id):0);

    window.requestVideoScript = async function () {
        const topic = document.getElementById('videoTopic').value.trim();
        const platform = document.getElementById('videoPlatform').value;
        const duration_seconds = document.getElementById('videoDuration').value;
        if (!topic) return;

        const btn = document.getElementById('genScriptBtn');
        btn.disabled = true;
        const original = btn.textContent;
        btn.textContent = 'جارِ التوليد... قد يستغرق شوية';

        const res = await fetchJSON('/api/creative-studio/video-scripts', { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ topic, platform, duration_seconds, website_id: P.getCurrentWebsiteId ? P.getCurrentWebsiteId() : null }) });

        btn.disabled = false;
        btn.textContent = original;
        document.getElementById('videoModal').classList.remove('open');

        if (res.success) {
            P.toast('تم توليد السكربت ✔', 'success');
            document.querySelector('#csTabs [data-tab="scripts"]').click();
            loadScripts();
        } else {
            P.toast(res.error || 'فشل التوليد', 'error');
        }
    };

    // ---------- نشر / جدولة ----------
    let publishTargetsLoaded = false;

    window.togglePublishSchedule = function () {
        const later = document.querySelector('input[name="publishWhen"]:checked').value === 'later';
        document.getElementById('publishScheduleAt').style.display = later ? 'block' : 'none';
    };

    window.openPublish = async function (mediaId, prompt) {
        document.getElementById('publishMediaId').value = mediaId;
        document.getElementById('publishContent').value = '';
        document.getElementById('publishContent').dataset.topic = prompt || '';
        document.getElementById('publishModal').classList.add('open');

        if (!publishTargetsLoaded) {
            const res = await fetchJSON('/api/social/connections');
            const box = document.getElementById('publishTargetsList');
            if (res.success && res.data.connections && res.data.connections.length) {
                box.innerHTML = res.data.connections.map(c => `
                    <label style="display:flex;align-items:center;gap:8px;margin:6px 0;">
                        <input type="checkbox" class="publishTargetCheckbox" value="${c.id}">
                        ${c.platform === 'facebook' ? '📘' : '📸'} ${esc(c.name)}
                    </label>`).join('');
                publishTargetsLoaded = true;
            } else {
                box.innerHTML = 'مفيش صفحات فيسبوك/انستجرام متصلة لسه. اربط حساب Meta الأول من <a href="/ads" target="_blank">صفحة الإعلانات</a>.';
            }
        }
    };

    window.generatePublishCaption = async function () {
        const btn = document.getElementById('publishCaptionBtn');
        const errBox = document.getElementById('publishCaptionErr');
        errBox.style.display = 'none';
        const topic = document.getElementById('publishContent').dataset.topic || 'محتوى تسويقي';
        const original = btn.textContent;
        btn.disabled = true; btn.textContent = '⏳ جارِ التوليد...';
        try {
            const res = await fetchJSON('/api/social/generate-caption', { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ topic: topic.slice(0, 300), platform: 'instagram' }) });
            if (res.success && res.data && res.data.content) {
                const tags = Array.isArray(res.data.hashtags) && res.data.hashtags.length ? '\n\n' + res.data.hashtags.map(t => '#' + String(t).replace(/^#/, '')).join(' ') : '';
                document.getElementById('publishContent').value = res.data.content + tags;
            } else {
                errBox.textContent = res.error || res.message || 'فشل التوليد. جرّب تاني.';
                errBox.style.display = 'block';
            }
        } catch (e) {
            errBox.textContent = 'تعذر الاتصال بالسيرفر. جرّب تاني.';
            errBox.style.display = 'block';
        }
        btn.disabled = false; btn.textContent = original;
    };

    window.submitPublish = async function () {
        const mediaId = parseInt(document.getElementById('publishMediaId').value, 10);
        const content = document.getElementById('publishContent').value.trim();
        if (!content) { P.toast('اكتب نص المنشور الأول', 'error'); return; }

        const targetIds = Array.from(document.querySelectorAll('.publishTargetCheckbox:checked')).map(cb => parseInt(cb.value, 10));
        if (!targetIds.length) { P.toast('اختار صفحة واحدة على الأقل للنشر عليها', 'error'); return; }

        const isLater = document.querySelector('input[name="publishWhen"]:checked').value === 'later';
        const scheduledAt = isLater ? document.getElementById('publishScheduleAt').value : null;
        if (isLater && !scheduledAt) { P.toast('اختار موعد الجدولة', 'error'); return; }

        // الموعد بيتبعت UTC (السيرفر بيخزّن UTC) - كان بيتبعت بتوقيت الجهاز من غير منطقة زمنية
        const targets = targetIds.map(id => ({ platform_connection_id: id, scheduled_at: scheduledAt ? new Date(scheduledAt).toISOString() : null }));

        const btn = document.getElementById('publishSubmitBtn');
        btn.disabled = true;
        const res = await fetchJSON('/api/social/posts', { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ content, media_item_id: mediaId, targets }) });
        btn.disabled = false;

        if (res.success) {
            document.getElementById('publishModal').classList.remove('open');
            P.toast(isLater ? 'تمت الجدولة ✔' : 'جارِ النشر الآن ✔', 'success');
        } else {
            P.toast(res.error || 'فشل النشر', 'error');
        }
    };


    // ---------- هوية العلامة + الخطة + التعلّم ----------
    let currentBrand = null;
    let currentPlan = null;
    const TYPE_LABEL = { social_image: 'سوشيال 1:1', marketing_image: 'تسويقية 4:3', instagram_post: 'انستجرام', facebook_cover: 'غلاف فيسبوك', youtube_thumbnail: 'يوتيوب', story: 'ستوري', reels_cover: 'ريلز' };

    function renderBrand() {
        const box = document.getElementById('brandCard');
        if (!currentBrand) { box.innerHTML = ''; document.getElementById('planBox').innerHTML = ''; return; }
        const b = currentBrand, pr = b.profile || {}, pal = b.palette || {};
        const sw = ['primary', 'secondary', 'accent'].map(k => `
            <label style="display:flex;flex-direction:column;align-items:center;gap:4px;font-size:11px;">
                <input type="color" value="${esc(pal[k] || '#000000')}" onchange="savePalette('${k}', this.value)" style="width:48px;height:48px;border:none;background:none;cursor:pointer;">
                ${k === 'primary' ? 'أساسي' : (k === 'secondary' ? 'ثانوي' : 'مميز')}
            </label>`).join('');
        const list = a => (Array.isArray(a) ? a : []).map(x => `<span class="pill orange" style="margin:2px;">${esc(x)}</span>`).join('');
        const learn = b.learnings || { do: [], avoid: [] };
        box.innerHTML = `
        <div class="p-card" style="margin-bottom:16px;">
            <div class="p-card-head"><h3>🎯 هوية ${esc(b.brand_name || '')}</h3><span class="p-card-sub" dir="ltr">${esc(b.source_url)}</span></div>
            <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-start;">
                <div style="text-align:center;min-width:110px;">
                    ${b.logo_path ? `<img src="${esc(b.logo_path)}" alt="logo" style="max-width:110px;max-height:90px;background:#fff;padding:8px;border-radius:10px;">` : '<div class="p-cell-muted" style="font-size:12px;">مفيش لوجو اتلقى<br>هنولّد الصور من غير لوجو</div>'}
                </div>
                <div style="display:flex;gap:10px;">${sw}</div>
                <div style="flex:1;min-width:260px;line-height:1.9;font-size:13.5px;">
                    <div><b>النشاط:</b> ${esc(pr.business_type || '-')}</div>
                    <div><b>الجمهور:</b> ${esc(pr.audience || '-')}</div>
                    <div><b>النبرة:</b> ${esc(pr.tone || '-')}</div>
                    <div><b>الأسلوب البصري:</b> <span dir="ltr">${esc(pr.visual_style || '-')}</span></div>
                    <div style="margin-top:6px;">${list(pr.content_pillars)}</div>
                </div>
            </div>
            ${(learn.do.length || learn.avoid.length) ? `
            <div style="margin-top:12px;padding:10px 12px;border-radius:8px;background:var(--panel-bg,#f7f8fa);font-size:12.5px;line-height:1.8;">
                <b>🧠 اللي النظام اتعلّمه من تقييماتك:</b>
                ${learn.do.map(x => `<div>✅ ${esc(x)}</div>`).join('')}
                ${learn.avoid.map(x => `<div>🚫 ${esc(x)}</div>`).join('')}
            </div>` : ''}
            <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap;">
                <input type="text" id="planGoal" class="p-select" style="flex:1;min-width:220px;" placeholder="هدف الخطة (اختياري) مثال: حجوزات رحلات الشتاء">
                <button class="p-btn" id="planBtn" onclick="createPlan()">✨ اعمل خطة محتوى (7 أفكار)</button>
            </div>
        </div>`;
        renderPlan();
    }

    function renderPlan() {
        const box = document.getElementById('planBox');
        if (!currentPlan || !currentPlan.items || !currentPlan.items.length) { box.innerHTML = ''; return; }
        box.innerHTML = `<div class="p-grid cols-3">` + currentPlan.items.map((it, i) => `
            <div class="p-card">
                <div style="display:flex;justify-content:space-between;gap:6px;margin-bottom:6px;">
                    <span class="pill orange">${esc(it.pillar || '')}</span>
                    <span class="p-cell-muted" style="font-size:12px;">${esc(it.platform)} · ${esc(TYPE_LABEL[it.type] || it.type)}</span>
                </div>
                <h4 style="margin:6px 0;">${esc(it.title)}</h4>
                <p class="p-cell-muted" style="font-size:12.5px;white-space:pre-wrap;line-height:1.7;">${esc(it.caption)}</p>
                <button class="p-btn xs" style="margin-top:10px;width:100%;" onclick="generateFromPlan(${i}, this)">🎨 ولّد الصورة بهوية العلامة</button>
            </div>`).join('') + `</div>`;
    }

    async function loadBrand() {
        const res = await fetchJSON('/api/creative-studio/brands');
        if (res.success && res.data.brands && res.data.brands.length) {
            currentBrand = res.data.brands[0];
            currentPlan = res.data.plan;
            document.getElementById('brandUrl').value = currentBrand.source_url || '';
        }
        renderBrand();
    }

    window.analyzeBrand = async function () {
        const url = document.getElementById('brandUrl').value.trim();
        if (!url) return;
        const btn = document.getElementById('brandAnalyzeBtn');
        btn.disabled = true; const o = btn.textContent; btn.textContent = 'جارِ التحليل... (ممكن ياخد نص دقيقة)';
        const res = await fetchJSON('/api/creative-studio/brand/analyze', { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ url }) });
        btn.disabled = false; btn.textContent = o;
        if (res.success) { currentBrand = res.data.brand; currentPlan = null; renderBrand(); P.toast('تم تحليل هوية علامتك ✔', 'success'); }
        else { P.toast(res.error || 'فشل التحليل', 'error'); }
    };

    window.savePalette = async function (key, value) {
        if (!currentBrand) return;
        const res = await fetchJSON('/api/creative-studio/brand/' + currentBrand.id + '/palette', { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ [key]: value }) });
        if (res.success) { currentBrand = res.data.brand; P.toast('اتحفظ اللون ✔', 'success'); }
    };

    window.createPlan = async function () {
        if (!currentBrand) return;
        const btn = document.getElementById('planBtn');
        btn.disabled = true; const o = btn.textContent; btn.textContent = 'جارِ بناء الخطة...';
        const goal = (document.getElementById('planGoal') || {}).value || '';
        const res = await fetchJSON('/api/creative-studio/plan', { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ brand_id: currentBrand.id, count: 7, goal }) });
        btn.disabled = false; btn.textContent = o;
        if (res.success) { currentPlan = res.data.plan; renderPlan(); }
        else { P.toast(res.error || 'فشل بناء الخطة', 'error'); }
    };

    window.generateFromPlan = async function (i, btn) {
        const it = currentPlan.items[i];
        btn.disabled = true; const o = btn.textContent; btn.textContent = 'تم الإرسال ✔';
        const res = await fetchJSON('/api/creative-studio/media', { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ type: it.type, style: it.style, prompt: it.image_prompt, headline: it.title, brand_id: currentBrand.id }) });
        if (res.success) { P.toast('بدأ توليد الصورة - هتلاقيها في تبويب الصور', 'success'); load(); }
        else { P.toast(res.error || 'فشل الطلب', 'error'); btn.disabled = false; btn.textContent = o; }
    };

    window.rateMedia = async function (id, rating) {
        let note = '';
        if (rating === -1) { note = prompt('إيه اللي مش عاجبك في الصورة؟ (اختياري - بيساعد النظام يتحسّن)') || ''; }
        else { note = ''; }
        const res = await fetchJSON('/api/creative-studio/media/' + id + '/rate', { method: 'POST', headers: jsonHeaders, body: JSON.stringify({ rating, note }) });
        if (res.success) { P.toast(res.message || 'تم ✔', 'success'); load(); loadBrand(); }
        else { P.toast(res.error || 'فشل', 'error'); }
    };

    // ---------- الإحصائيات ----------
    const ST_LABEL = { published: 'اتنشر', scheduled: 'مجدول', pending: 'في الانتظار', publishing: 'جارِ النشر', failed: 'فشل' };
    const ST_PILL = { published: 'green', scheduled: 'orange', pending: 'orange', publishing: 'orange', failed: 'red' };
    const PLAT = { facebook: '📘 فيسبوك', instagram: '📸 انستجرام', tiktok: '🎵 تيك توك', youtube: '▶️ يوتيوب' };
    const nf = n => Number(n || 0).toLocaleString((document.documentElement.lang || 'ar').slice(0, 2) === 'ar' ? 'ar-EG-u-nu-latn' : 'en-GB');

    function kpi(label, value, sub) {
        return `<div class="p-card" style="text-align:center;"><div class="p-cell-muted" style="font-size:12px;">${label}</div><div style="font-size:28px;font-weight:700;margin:4px 0;">${value}</div><div class="p-cell-muted" style="font-size:11.5px;">${sub || '&nbsp;'}</div></div>`;
    }

    async function loadStats(refresh) {
        const res = await fetchJSON('/api/creative-studio/stats');
        const box = document.getElementById('statsBox');
        if (!res.success) { box.innerHTML = `<div class="p-empty">${esc(res.error || 'تعذر تحميل الإحصائيات')}</div>`; return; }
        const d = res.data, t = d.targets || {}, im = d.images || {}, en = d.engagement || {};
        const published = t.published || 0, failed = t.failed || 0, sched = (t.scheduled || 0) + (t.pending || 0);
        const rate = (published + failed) ? Math.round(published * 100 / (published + failed)) : 0;
        const likedPct = (im.liked + im.disliked) ? Math.round(im.liked * 100 / (im.liked + im.disliked)) : null;

        const days = [];
        for (let i = 13; i >= 0; i--) { const x = new Date(Date.now() - i * 86400000); days.push(x.toISOString().slice(0, 10)); }
        const map = {}; (d.daily || []).forEach(r => { map[String(r.d).slice(0, 10)] = Number(r.n); });
        const max = Math.max(1, ...days.map(k => map[k] || 0));
        const bars = days.map(k => `<div title="${k}: ${map[k] || 0}" style="flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:4px;">
            <span style="font-size:10px;">${map[k] || ''}</span>
            <div style="width:100%;max-width:26px;height:${Math.max(3, Math.round(((map[k] || 0) / max) * 90))}px;background:${map[k] ? '#6c8cff' : 'rgba(128,128,128,.25)'};border-radius:4px 4px 0 0;"></div>
            <span style="font-size:9px;opacity:.6;">${k.slice(8)}</span></div>`).join('');

        const plat = (d.platforms || []).map(p => `<span class="pill green" style="margin:2px;">${esc(PLAT[p.platform] || p.platform)}: ${nf(p.n)}</span>`).join('') || '<span class="p-cell-muted">لسه مفيش منشورات منشورة</span>';

        const rows = (d.recent || []).map(r => {
            const when = r.published_at || r.scheduled_at || '';
            const hasM = r.metrics_state === 'ok';
            const metric = r.status !== 'published' ? '-' : (hasM
                ? `👍 ${nf(r.likes)} · 💬 ${nf(r.comments)}${Number(r.shares) ? ' · 🔁 ' + nf(r.shares) : ''}${r.reach !== null && r.reach !== undefined ? ' · 👁 ' + nf(r.reach) : ''}`
                : (r.metrics_state === 'unavailable' ? '<span class="p-cell-muted" title="' + esc(r.metrics_note || '') + '">غير متاح (صلاحيات Meta)</span>' : '<span class="p-cell-muted">جارِ الجلب...</span>'));
            return `<tr>
                <td>${r.file_path ? `<img src="${esc(r.file_path)}" style="width:44px;height:44px;object-fit:cover;border-radius:6px;">` : ''}</td>
                <td style="max-width:260px;font-size:12.5px;">${esc(r.content)}${r.status === 'failed' && r.last_error ? `<div class="p-cell-muted" style="font-size:11px;color:#f87171;">${esc(String(r.last_error).slice(0, 120))}</div>` : ''}</td>
                <td style="white-space:nowrap;">${esc(PLAT[r.platform] || r.platform || '')}<div class="p-cell-muted" style="font-size:11px;">${esc(r.page_name || '')}</div></td>
                <td><span class="pill ${ST_PILL[r.status] || 'orange'}">${esc(ST_LABEL[r.status] || r.status)}</span></td>
                <td style="white-space:nowrap;font-size:12px;">${esc(String(when).slice(0, 16))}</td>
                <td style="font-size:12px;white-space:nowrap;">${metric}</td>
                <td>${r.permalink ? `<a href="${esc(r.permalink)}" target="_blank" rel="noopener">فتح ↗</a>` : ''}</td></tr>`;
        }).join('');

        box.innerHTML = `
        <div class="p-grid cols-4" style="margin-bottom:14px;">
            ${kpi('منشورات اتنشرت', nf(published), 'نسبة النجاح ' + rate + '%')}
            ${kpi('مجدولة / منتظرة', nf(sched), failed ? 'فشل: ' + nf(failed) : 'مفيش فشل')}
            ${kpi('صور اتولّدت', nf(im.completed), 'فشل: ' + nf(im.failed))}
            ${kpi('رضا العميل عن الصور', likedPct === null ? '-' : likedPct + '%', '👍 ' + nf(im.liked) + ' · 👎 ' + nf(im.disliked))}
        </div>
        <div class="p-grid cols-4" style="margin-bottom:14px;">
            ${kpi('إجمالي الإعجابات', nf(en.likes), '')}
            ${kpi('إجمالي التعليقات', nf(en.comments), '')}
            ${kpi('إجمالي المشاركات', nf(en.shares), '')}
            ${kpi('الوصول (Reach)', nf(en.reach), 'للمنشورات اللي Meta رجّعت لها بيانات')}
        </div>
        <div class="p-card" style="margin-bottom:14px;">
            <div class="p-card-head"><h3>النشر آخر 14 يوم</h3><span class="p-card-sub">${plat}</span></div>
            <div style="display:flex;gap:4px;align-items:flex-end;height:130px;">${bars}</div>
        </div>
        <div class="p-card">
            <div class="p-card-head"><h3>آخر المنشورات</h3><button class="p-btn outline xs" onclick="loadStats(true)">🔄 تحديث التفاعل</button></div>
            ${rows ? `<div class="p-table-scroll"><table class="p-table"><thead><tr><th></th><th>المنشور</th><th>الصفحة</th><th>الحالة</th><th>الوقت</th><th>التفاعل</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>` : '<div class="p-empty">لسه مفيش منشورات</div>'}
        </div>`;

        if (refresh) {
            // نجيب التفاعل من Meta في الخلفية ونعيد الرسم لو فيه تحديث
            fetchJSON('/api/creative-studio/stats/refresh', { method: 'POST', headers: jsonHeaders, body: '{}' }).then(r => {
                if (r.success && r.data && r.data.refreshed > 0) { loadStats(false); }
            });
        }
    }
    window.loadStats = loadStats;

    load();
    loadScripts();
    loadBrand();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('creative_studio', 'Creative Studio', 'توليد صور وفيديوهات احترافية بالذكاء الاصطناعي، وانشرها أو جدولها مباشرة', $body, $script);
        exit;
    }

    /** GET /api/creative-studio/media */
    public function listMedia(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        try{
            $cursor=$this->get('cursor',0);if(!is_scalar($cursor)||!ctype_digit((string)$cursor))return $this->error('Invalid cursor',422);
            $filter=$this->get('filter','all');if(!in_array($filter,['all','images','videos','completed','generating','failed','favorites','trash'],true))return $this->error('Invalid filter',422);
            $ids=$this->get('ids','');
            if($ids!==''){
                if(!is_string($ids)||!preg_match('/^\d+(,\d+){0,23}$/D',$ids))return $this->error('Invalid media ids',422);
                $values=array_map('intval',explode(',',$ids));$stmt=$this->db->getConnection()->prepare('SELECT * FROM media_items WHERE user_id=? AND deleted_at IS NULL AND id IN ('.implode(',',array_fill(0,count($values),'?')).')');$stmt->execute(array_merge([(int)$this->user['id']],$values));return $this->success(['items'=>array_map([GenerationOptions::class,'publicItem'],$stmt->fetchAll(PDO::FETCH_ASSOC))]);
            }
            $sql='SELECT * FROM media_items WHERE user_id=?';$args=[(int)$this->user['id']];
            $sql.=$filter==='trash'?' AND deleted_at IS NOT NULL':' AND deleted_at IS NULL';
            $q=GenerationOptions::text($this->get('q',''),'search',120);if($q!==''){$sql.=" AND prompt LIKE ? ESCAPE '!'";$args[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%';}
            $folder=$this->get('folder','');if($folder!==''){if(!is_scalar($folder)||!ctype_digit((string)$folder))throw new InvalidArgumentException('Invalid folder');if((int)$folder){$sql.=' AND folder_id=?';$args[]=(int)$folder;}else $sql.=' AND folder_id IS NULL';}
            if($filter==='favorites')$sql.=' AND is_favorite=1';
            if((int)$cursor>0){$sql.=' AND id<?';$args[]=(int)$cursor;}
            if($filter==='images')$sql.=" AND type<>'short_video'";
            elseif($filter==='videos')$sql.=" AND type='short_video'";
            elseif(!in_array($filter,['all','favorites','trash'],true)){$sql.=' AND status=?';$args[]=$filter;}
            $stmt=$this->db->getConnection()->prepare($sql.' ORDER BY id DESC LIMIT 25');$stmt->execute($args);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>24;$rows=array_slice($rows,0,24);
            return $this->success(['items'=>array_map([GenerationOptions::class,'publicItem'],$rows),'next_cursor'=>$more?(int)end($rows)['id']:null]);
        }catch(Throwable $e){Logger::error('Creative media list failed',['message'=>$e->getMessage()]);return $this->error('تعذر تحميل المكتبة. حاول مرة أخرى.',503);}
    }

    public function libraryFolders(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        try{return $this->success(['folders'=>CreativeLibrary::folders((int)$this->user['id'])]);}catch(Throwable $e){return $this->error('تعذر تحميل المجلدات.',503);}
    }
    public function createLibraryFolder(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);if($e=$this->rateLimitGuard('user','creative_library',30,60))return $e;
        try{return $this->success(['id'=>CreativeLibrary::createFolder((int)$this->user['id'],GenerationOptions::text($this->get('name',''),'folder name',100))],'تم إنشاء المجلد',201);}catch(Throwable $e){return $this->error('اكتب اسم مجلد صالح؛ الحد الأقصى 30 مجلدًا.',422);}
    }
    public function updateLibraryItem(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);if($e=$this->rateLimitGuard('user','creative_library',60,60))return $e;
        try{$input=['action'=>$this->get('action','organize')];foreach(['folder_id','is_favorite'] as $k)if($this->get($k,null)!==null)$input[$k]=$this->get($k);return $this->success(['item'=>CreativeLibrary::update((int)$this->user['id'],(int)($params['id']??0),$input)]);}catch(InvalidArgumentException $e){return $this->error('تعذر تنفيذ التعديل. انتظر الطلبات أو النشر الجاري، وتأكد من ملكية المجلد.',422);}catch(Throwable $e){Logger::error('Creative library update failed',['message'=>$e->getMessage()]);return $this->error('تعذر تحديث المكتبة الآن.',503);}
    }
    /** POST /api/creative-studio/media - توليد صورة */
    public function requestMedia(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($rateError = $this->rateLimitGuard('user', 'ai', 20, 60)) {
            return $rateError;
        }
        if (!$this->validate(['type' => 'required', 'prompt' => 'required'])) {
            return $this->error('بيانات ناقصة', 422);
        }
        try {
            $brand = null;
            $brandInput=$this->get('brand_id',0);if(!is_scalar($brandInput)||!ctype_digit((string)$brandInput))throw new InvalidArgumentException('Invalid brand');
            $brandId=(int)$brandInput;
            if ($brandId > 0) {
                $brand = $this->ownedBrand($brandId);
                if (!$brand) {
                    return $this->error('الهوية دي مش موجودة', 404);
                }
            }
            if($this->get('quote_hash','')!=='')return $this->confirmedGeneration('image',$brand);
            $item = $this->mediaService->requestGeneration(
                (int) $this->user['id'],
                GenerationOptions::text($this->get('type'),'type',40),
                GenerationOptions::text($this->get('prompt'),'prompt',2000,true),
                GenerationOptions::text($this->get('style', 'photo'),'style',30),
                $brand,
                GenerationOptions::text($this->get('headline', ''),'headline',200),
                $this->generationInputOptions(),
                GenerationOptions::text($this->get('request_key', ''),'request_key',80)
            );
            return $this->success(['item' => GenerationOptions::publicItem($item->toArray())], 'تم استلام طلب التوليد', 201);
        } catch (InvalidArgumentException $e) {
            return $this->error('راجع الوصف وإعدادات الصورة والرصيد، أو انتظر اكتمال الطلبات الجارية.',422);
        } catch (Throwable $e) {
            Logger::error('requestMedia Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إنشاء طلب التوليد', 500);
        }
    }

    /** POST /api/creative-studio/video - توليد فيديو قصير حقيقي (Veo) */
    public function requestVideo(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($rateError = $this->rateLimitGuard('user', 'ai', 20, 60)) {
            return $rateError;
        }
        if (!$this->validate(['prompt' => 'required'])) {
            return $this->error('الوصف مطلوب', 422);
        }
        try {
            if($this->get('quote_hash','')!=='')return $this->confirmedGeneration('video',$this->generationBrand());
            $item = $this->mediaService->requestVideoGeneration(
                (int) $this->user['id'],
                GenerationOptions::text($this->get('prompt'),'prompt',2000,true),
                GenerationOptions::text($this->get('platform', 'instagram_reels'),'platform',30),
                $this->generationDuration(),
                GenerationOptions::text($this->get('style', 'cinematic'),'style',30),
                $this->generationBrand(),
                $this->generationInputOptions(),
                GenerationOptions::text($this->get('request_key', ''),'request_key',80)
            );
            return $this->success(['item' => GenerationOptions::publicItem($item->toArray())], 'بدأ توليد الفيديو', 201);
        } catch (InvalidArgumentException $e) {
            return $this->error('راجع الوصف والإعدادات والرصيد، أو انتظر اكتمال الطلبات الجارية.',422);
        } catch (Throwable $e) {
            Logger::error('requestVideo Error', ['message' => $e->getMessage()]);
            return $this->error('تعذر إنشاء طلب توليد الفيديو', 500);
        }
    }

    /** Configuration and credit estimate only; does not call paid provider APIs. */
    public function generationAvailability(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        try{
            $key=defined('GEMINI_API_KEY')?GEMINI_API_KEY:'';
            if(class_exists('SystemSettingsService'))$key=(new SystemSettingsService())->get('gemini_api_key',$key);
            if(class_exists('AiServiceRouting'))$key=AiServiceRouting::apiKey('gemini');
            $gemini=is_string($key)&&$key!=='';$openai=(new OpenAIImageClient())->isConfigured();
            $queue=Container::getInstance()->make(QueueManager::class);$ready=$queue->isReady();
            $data=['queue_ready'=>$ready,'image_configured'=>$gemini||$openai,'video_configured'=>$gemini];
            if(class_exists('AiServiceRouting')){foreach(['image'=>'creative_studio_image','video'=>'creative_studio_video'] as $kind=>$task){$policy=AiServiceRouting::policy($task);if($policy!==null)$data[$kind.'_configured']=$policy['enabled']&&count(array_filter($policy['chain'],fn($p)=>AiServiceRouting::apiKey($p)!==''))>0;}}
            foreach(['image'=>'media_image','video'=>'media_video'] as $kind=>$feature){
                $check=class_exists('PlanUsage')?PlanUsage::check((int)$this->user['id'],$feature,$feature,'Insufficient credits'):['ok'=>false];
                $data[$kind]=array_intersect_key($check,array_flip(['ok','source','remaining','price','shortfall']));
            }
            return $this->success($data);
        }catch(Throwable $e){Logger::error('Creative availability failed',['message'=>$e->getMessage()]);return $this->error('تعذر قراءة حالة التوليد حاليًا.',503);}
    }

    private function confirmedGeneration(string $kind,?BrandProfile $brand): array
    {
        $count=$this->get('count',1);if(!in_array($count,[1,2,'1','2'],true))throw new InvalidArgumentException('Invalid count');
        $data=['prompt'=>$this->get('prompt'),'style'=>$this->get('style',$kind==='video'?'cinematic':'photo'),'type'=>$this->get('type','social_image'),'platform'=>$this->get('platform','instagram_reels'),'options'=>$this->generationInputOptions(),'duration_seconds'=>$this->generationDuration(),'count'=>(int)$count];
        $items=$this->mediaService->requestBatch((int)$this->user['id'],$kind,$data,$brand,GenerationOptions::text($this->get('request_key',''),'request key',70),GenerationOptions::text($this->get('quote_hash',''),'quote',64));$public=array_map(fn($m)=>GenerationOptions::publicItem($m->toArray()),$items);return $this->success(['items'=>$public,'item'=>$public[0]],'تم استلام طلبك',201);
    }
    public function generationQuote(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        if($e=$this->rateLimitGuard('user','creative_quote',60,60))return $e;
        try{$count=$this->get('count',1);if(!in_array($count,[1,2,'1','2'],true))throw new InvalidArgumentException('Invalid count');return $this->success(CreativePricing::quote((int)$this->user['id'],GenerationOptions::text($this->get('kind','image'),'kind',10),$this->generationInputOptions(),(int)$count,$this->generationDuration()));}catch(InvalidArgumentException $e){return $this->error('راجع الرصيد والإعدادات. الجودة الأعلى وتحويل الصورة يتطلبان 8 ثوانٍ وسعرًا مفعّلًا.',422);}catch(Throwable $e){Logger::error('Creative quote unavailable',['message'=>$e->getMessage()]);return $this->error('تعذر حساب التكلفة الآن.',503);}
    }
    public function referenceUpload(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        if($e=$this->rateLimitGuard('user','creative_upload',15,60))return $e;
        try{$f=$_FILES['image']??null;$parent=$this->get('parent_id',0);if(!is_array($f)||!is_scalar($parent)||!ctype_digit((string)$parent)||($f['error']??-1)!==UPLOAD_ERR_OK||!is_string($f['tmp_name']??null)||!is_uploaded_file($f['tmp_name'])||filesize($f['tmp_name'])>ReferenceMedia::MAX_BYTES)throw new InvalidArgumentException('Invalid upload');$bytes=file_get_contents($f['tmp_name']);if($bytes===false)throw new RuntimeException('Upload read failure');$item=ReferenceMedia::store((int)$this->user['id'],$bytes,(int)$parent);return $this->success(['item'=>GenerationOptions::publicItem($item->toArray())],'تم حفظ الصورة',201);}catch(InvalidArgumentException $e){return $this->error('اختر صورة PNG أو JPEG أو WebP حتى 10 ميجابايت من حسابك.',422);}catch(Throwable $e){Logger::error('Creative upload failed',['message'=>$e->getMessage()]);return $this->error('تعذر حفظ الصورة الآن.',500);}
    }
    public function editingAvailability(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        return $this->success(['available'=>VideoEditing::available()&&Container::getInstance()->make(QueueManager::class)->isReady(),'max_seconds'=>30,'max_dimension'=>1280]);
    }
    public function editVideo(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);if($e=$this->rateLimitGuard('user','creative_edit_video',10,60))return $e;
        try{$f=$_FILES['overlay']??null;if(!is_array($f)||($f['error']??-1)!==UPLOAD_ERR_OK||!is_string($f['tmp_name']??null)||!is_uploaded_file($f['tmp_name'])||filesize($f['tmp_name'])>ReferenceMedia::MAX_BYTES)throw new InvalidArgumentException('Invalid overlay');$bytes=file_get_contents($f['tmp_name']);if($bytes===false)throw new RuntimeException('Overlay read failed');$item=VideoEditing::enqueue((int)$this->user['id'],(int)($params['id']??0),$bytes,['start'=>$this->get('start',0),'end'=>$this->get('end',8),'mute'=>$this->get('mute','0')],GenerationOptions::text($this->get('request_key',''),'request key',80));return $this->success(['item'=>GenerationOptions::publicItem($item->toArray())],'تم استلام المونتاج دون تكلفة توليد',201);}catch(InvalidArgumentException $e){return $this->error('راجع حدود القص والصورة، وتأكد من جاهزية المونتاج على الاستضافة.',422);}catch(Throwable $e){Logger::error('Creative edit enqueue failed',['message'=>$e->getMessage()]);return $this->error('تعذر بدء المونتاج الآن.',503);}
    }
    private function generationInputOptions(): array
    {
        $options=$this->get('options',[]);if(!is_array($options))throw new InvalidArgumentException('Invalid creative settings');return $options;
    }
    private function generationDuration(): int
    {
        $v=$this->get('duration_seconds',8);if(!in_array($v,[4,6,8,'4','6','8'],true))throw new InvalidArgumentException('Invalid duration');return (int)$v;
    }
    private function generationBrand(): ?BrandProfile
    {
        $id=$this->get('brand_id',0);if(!is_scalar($id)||!ctype_digit((string)$id))throw new InvalidArgumentException('Invalid brand');
        if(!(int)$id)return null;$brand=$this->ownedBrand((int)$id);if(!$brand)throw new InvalidArgumentException('Invalid brand');return $brand;
    }
    public function mediaContent(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        try{$row=PrivateMedia::owned((int)$this->user['id'],(int)($params['id']??0));if($row['status']!=='completed')throw new InvalidArgumentException('Unavailable');$thumb=$this->get('thumbnail',0);if(!in_array($thumb,[0,1,'0','1'],true))throw new InvalidArgumentException('Invalid thumbnail');$path=(string)($thumb?($row['thumbnail_path']?:$row['file_path']):$row['file_path']);PrivateMedia::stream($path,(int)$row['id']);exit;}catch(Throwable $e){return $this->error('الملف غير متاح',404);}
    }
    public function publishContent(array $params=[]): array
    {
        try{$s=$this->db->getConnection()->prepare('SELECT * FROM media_items WHERE id=?');$s->execute([(int)($params['id']??0)]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!$row||!PrivateMedia::verify($row,$this->get('expires',''),$this->get('signature','')))throw new InvalidArgumentException('Unavailable');PrivateMedia::stream((string)$row['file_path'],(int)$row['id']);exit;}catch(Throwable $e){return $this->error('الملف غير متاح',404);}
    }
    public function downloadMedia(array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        try{$row=PrivateMedia::owned((int)$this->user['id'],(int)($params['id']??0));if($row['status']!=='completed')throw new InvalidArgumentException('Unavailable');PrivateMedia::stream((string)$row['file_path'],(int)$row['id'],true);exit;}catch(Throwable $e){return $this->error('الملف غير متاح',404);}
    }

    /** POST /api/creative-studio/enhance-prompt - تحسين وصف بالذكاء الاصطناعي قبل التوليد */
    public function enhancePrompt(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($rateError = $this->rateLimitGuard('user', 'ai', 20, 60)) {
            return $rateError;
        }
        if (!$this->validate(['prompt' => 'required'])) {
            return $this->error('الوصف مطلوب', 422);
        }

        if($rateError=$this->rateLimitGuard('user','creative_enhance',10,60))return $rateError;
        try{$prompt=GenerationOptions::text($this->get('prompt'),'prompt',2000,true);$kind=$this->get('kind','image');if(!in_array($kind,['image','video'],true))throw new InvalidArgumentException('Invalid kind');}catch(InvalidArgumentException $e){return $this->error('اكتب وصفًا بين 8 و2000 حرف واختر نوعًا صحيحًا.',422);}
        $mediaLabel = $kind === 'video' ? 'مشهد فيديو قصير' : 'صورة تسويقية';

        $metaPrompt = <<<PROMPT
مهمتك: حوّل الوصف القصير التالي لـ{$mediaLabel} إلى وصف احترافي مفصّل بالإنجليزية
يصلح كـ prompt لنموذج توليد وسائط بالذكاء الاصطناعي - أضف تفاصيل عن الإضاءة،
زاوية التصوير، التكوين، والأجواء، من غير ما تغيّر الفكرة الأساسية.
للصور السياحية الواقعية: فضّل لحظة طبيعية وإضاءة منطقية وملمس جلد وخامات حقيقية وألوان هادئة؛ تجنب CGI والتنعيم التجميلي.
حافظ على الوجهة والأشخاص والأعداد والأفعال والتعليمات المحددة. لا تضف أسعارًا أو شعارات أو نصوصًا أو معالم مخترعة، ولا تدّعي دقة 4K أو إعدادات كاميرا فعلية.
اجعل النص أقل من 1800 حرف.
الوصف الأصلي: "{$prompt}"
رجّع النص المحسّن فقط، من غير أي شرح أو علامات اقتباس.
PROMPT;

        try{$response = AiText::generate($metaPrompt, ['task'=>'creative_prompt', 'maxOutputTokens' => 512, 'temperature' => 0.6]);}catch(Throwable $e){Logger::error('Creative prompt enhancement failed',['message'=>$e->getMessage()]);return $this->error('تعذر تحسين الوصف. يمكنك التوليد بالوصف الأصلي.',502);}

        if (!($response['success'] ?? false)) {
            return $this->error('تعذر تحسين الوصف. يمكنك التوليد بالوصف الأصلي.',502);
        }

        $enhanced = trim((string) ($response['data'] ?? ''));
        $enhanced = trim($enhanced, "\"'`\n ");

        if ($enhanced === '') {
            return $this->error('تعذّر تحسين الوصف', 502);
        }

        return $this->success(['prompt' => mb_substr($enhanced,0,2000)]);
    }

    /** GET /api/creative-studio/video-scripts */
    public function listVideoScripts(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $items = (new VideoScript())->where(['user_id' => $this->user['id']], ['created_at' => 'DESC'], 40);
        return $this->success(['scripts' => array_map(fn ($i) => $i->toArray(), $items)]);
    }

    /** POST /api/creative-studio/video-scripts */
    public function requestVideoScript(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($rateError = $this->rateLimitGuard('user', 'ai', 20, 60)) {
            return $rateError;
        }
        if (!$this->validate(['topic' => 'required'])) {
            return $this->error('الموضوع مطلوب', 422);
        }

        try {
            $script = $this->videoService->generate(
                (int) $this->user['id'],
                (string) $this->get('topic'),
                (string) $this->get('platform', 'general'),
                (int) $this->get('duration_seconds', 30),
                ContentLanguageService::forUser((int)$this->user['id'], (int)$this->get('website_id',0) ?: null)
            );
            return $this->success(['script' => $script->toArray()], 'تم توليد السكربت', 201);
        } catch (InvalidArgumentException $e) {
            return $this->error('راجع الوصف والإعدادات والرصيد، أو انتظر اكتمال الطلبات الجارية.',422);
        } catch (Throwable $e) {
            Logger::error('requestVideoScript Error', ['message' => $e->getMessage()]);
            return $this->error(CustomerError::message($e->getMessage(), 500), 502);
        }
    }

    // ======================================================================
    //  Brand Studio: هوية العلامة + خطة المحتوى + التعلّم من التقييم
    // ======================================================================

    private function ownedBrand(int $id): ?BrandProfile
    {
        $b = (new BrandProfile())->find($id);
        return ($b && (int) $b->getAttribute('user_id') === (int) $this->user['id']) ? $b : null;
    }

    private function brandPayload(BrandProfile $b): array
    {
        $a = $b->toArray();
        $a['palette'] = BrandPromptBuilder::palette($b);
        $a['profile'] = BrandPromptBuilder::profileData($b);
        unset($a['palette_json'], $a['profile_json']);
        $a['learnings'] = BrandPromptBuilder::learnings((int) $b->getAttribute('id'), 6);
        return $a;
    }

    /** GET /api/creative-studio/brands */
    public function listBrands(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $rows = (new BrandProfile())->where(['user_id' => $this->user['id']], ['updated_at' => 'DESC'], 10);
        $brands = array_map(fn ($b) => $this->brandPayload($b), $rows);
        $plan = null;
        if ($rows) {
            $plans = (new CreativePlan())->where(['brand_profile_id' => (int) $rows[0]->getAttribute('id')], ['created_at' => 'DESC'], 1);
            if ($plans) {
                $plan = ['id' => (int) $plans[0]->getAttribute('id'), 'items' => json_decode((string) $plans[0]->getAttribute('plan_json'), true) ?: []];
            }
        }
        return $this->success(['brands' => $brands, 'plan' => $plan]);
    }

    /** POST /api/creative-studio/brand/analyze { url, website_id? } */
    public function analyzeBrand(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($rateError = $this->rateLimitGuard('user', 'brand_analyze', 6, 60)) {
            return $rateError;
        }
        if (!$this->validate(['url' => 'required'])) {
            return $this->error('اكتب رابط الموقع', 422);
        }
        @set_time_limit(120);
        $res = (new BrandKitService())->analyze((int) $this->user['id'], (string) $this->get('url'), $this->get('website_id') ? (int) $this->get('website_id') : null);
        if (!$res['ok']) {
            return $this->error($res['error'], 422);
        }
        return $this->success(['brand' => $this->brandPayload($res['profile'])], 'تم تحليل هوية العلامة', 201);
    }

    /** POST /api/creative-studio/brand/{id}/palette { primary, secondary, accent } - تعديل يدوي للألوان */
    public function updateBrandPalette(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $b = $this->ownedBrand((int) ($params['id'] ?? 0));
        if (!$b) {
            return $this->error('الهوية دي مش موجودة', 404);
        }
        $pal = BrandPromptBuilder::palette($b);
        foreach (['primary', 'secondary', 'accent'] as $k) {
            $v = strtoupper(trim((string) $this->get($k, '')));
            if (preg_match('/^#[0-9A-F]{6}$/', $v)) {
                $pal[$k] = $v;
            }
        }
        $b->setAttribute('palette_json', json_encode($pal));
        $b->save();
        return $this->success(['brand' => $this->brandPayload($b)]);
    }

    /** POST /api/creative-studio/plan { brand_id, count?, goal? } */
    public function createPlan(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($rateError = $this->rateLimitGuard('user', 'ai', 20, 60)) {
            return $rateError;
        }
        $b = $this->ownedBrand((int) $this->get('brand_id', 0));
        if (!$b) {
            return $this->error('حلّل موقعك الأول عشان تتكوّن الهوية', 422);
        }
        @set_time_limit(120);
        $res = (new CreativePlanService())->create((int) $this->user['id'], $b, (int) $this->get('count', 7), mb_substr((string) $this->get('goal', ''), 0, 300));
        if (!$res['ok']) {
            return $this->error($res['error'], 502);
        }
        return $this->success(['plan' => ['id' => (int) $res['plan']->getAttribute('id'), 'items' => $res['items']]], 'تم إنشاء الخطة', 201);
    }

    /** POST /api/creative-studio/media/{id}/rate { rating: 1|-1, note? } */
    public function rateMedia(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $item = (new MediaItem())->find((int) ($params['id'] ?? 0));
        if (!$item || (int) $item->getAttribute('user_id') !== (int) $this->user['id']) {
            return $this->error('العنصر مش موجود', 404);
        }
        (new BrandLearningService())->rate($item, (int) $this->get('rating', 1), (string) $this->get('note', ''));
        return $this->success(['rated' => true], 'شكرًا! هنستفيد من تقييمك في الصور الجاية');
    }

    // ======================================================================
    //  الإحصائيات: الصور + النشر + التفاعل
    // ======================================================================

    /** GET /api/creative-studio/stats */
    public function stats(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $uid = (int) $this->user['id'];
        $db = Database::getInstance();
        try {
            $img = $db->query(
                "SELECT COUNT(*) total, SUM(status='completed') completed, SUM(status='failed') failed,
                        SUM(rating = 1) liked, SUM(rating = -1) disliked
                   FROM media_items WHERE user_id = :u AND type <> 'short_video'", [':u' => $uid]
            )[0] ?? [];
            $byStatus = $db->query(
                "SELECT t.status, COUNT(*) n FROM social_post_targets t JOIN social_posts p ON p.id = t.social_post_id
                  WHERE p.user_id = :u GROUP BY t.status", [':u' => $uid]
            );
            $byPlatform = $db->query(
                "SELECT c.platform, COUNT(*) n FROM social_post_targets t JOIN social_posts p ON p.id = t.social_post_id
                   LEFT JOIN platform_connections c ON c.id = t.platform_connection_id
                  WHERE p.user_id = :u AND t.status = 'published' GROUP BY c.platform", [':u' => $uid]
            );
            $daily = $db->query(
                "SELECT DATE(t.published_at) d, COUNT(*) n FROM social_post_targets t JOIN social_posts p ON p.id = t.social_post_id
                  WHERE p.user_id = :u AND t.status = 'published' AND t.published_at >= (NOW() - INTERVAL 14 DAY)
                  GROUP BY DATE(t.published_at) ORDER BY d", [':u' => $uid]
            );
            $recent = $db->query(
                "SELECT t.id target_id, t.status, t.published_at, t.scheduled_at, t.last_error, t.external_post_id,
                        c.platform, c.external_location_name page_name, p.content, m.file_path, m.id media_asset_id, m.deleted_at media_deleted_at,
                        mm.likes, mm.comments, mm.shares, mm.reach, mm.state metrics_state, mm.note metrics_note
                   FROM social_post_targets t
                   JOIN social_posts p ON p.id = t.social_post_id
              LEFT JOIN platform_connections c ON c.id = t.platform_connection_id
              LEFT JOIN media_items m ON m.id = p.media_item_id
              LEFT JOIN social_post_metrics mm ON mm.target_id = t.id
                  WHERE p.user_id = :u ORDER BY COALESCE(t.published_at, t.scheduled_at, t.created_at) DESC LIMIT 30", [':u' => $uid]
            );
        } catch (Throwable $e) {
            Logger::error('Creative stats error', ['message' => $e->getMessage()]);
            return $this->error('تعذر تحميل الإحصائيات (شغّل ملف الـ migration الجديد؟)', 500);
        }
        $tot = ['likes' => 0, 'comments' => 0, 'shares' => 0, 'reach' => 0];
        foreach ($recent as &$r) {
            $r['file_path']=empty($r['media_deleted_at'])&&!empty($r['file_path'])&&PrivateMedia::valid((string)$r['file_path'])?PrivateMedia::preview((int)$r['media_asset_id']):null;unset($r['media_asset_id'],$r['media_deleted_at']);
            $r['content'] = mb_substr((string) $r['content'], 0, 110);
            $r['permalink'] = ($r['platform'] === 'facebook' && !empty($r['external_post_id'])) ? 'https://www.facebook.com/' . rawurlencode((string) $r['external_post_id']) : null;
            unset($r['external_post_id']);
            foreach (array_keys($tot) as $k) {
                $tot[$k] += (int) ($r[$k] ?? 0);
            }
        }
        unset($r);
        $statusMap = [];
        foreach ($byStatus as $x) {
            $statusMap[$x['status']] = (int) $x['n'];
        }
        return $this->success([
            'images' => array_map('intval', $img),
            'targets' => $statusMap,
            'platforms' => $byPlatform,
            'daily' => $daily,
            'engagement' => $tot,
            'recent' => $recent,
        ]);
    }

    /** POST /api/creative-studio/stats/refresh - تحديث التفاعل من Meta (بحد أقصى 8 منشورات في المرة) */
    public function refreshStats(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($rateError = $this->rateLimitGuard('user', 'stats_refresh', 10, 60)) {
            return $rateError;
        }
        @set_time_limit(90);
        try {
            $n = (new SocialInsightsService())->refreshForUser((int) $this->user['id'], 8);
        } catch (Throwable $e) {
            Logger::warning('refreshStats failed', ['message' => $e->getMessage()]);
            $n = 0;
        }
        return $this->success(['refreshed' => $n]);
    }

    public function retryVideoDownload(array $params=[]): array{
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);$id=(int)($params['id']??0);
        if(!GenerationLifecycle::lock($id))return $this->error('الطلب قيد التنفيذ',409);
        try{
            $item=(new MediaItem())->find($id);if(!$item||(int)$item->getAttribute('user_id')!==(int)$this->user['id'])return $this->error('غير موجود',404);
            if($item->getAttribute('status')!=='generating'||!in_array($item->getAttribute('generation_stage'),['download_failed','billing'],true)||!$item->getAttribute('provider_ref'))return $this->error('هذه العملية لا تحتاج استعادة تنزيل',422);
            $meta=json_decode((string)$item->getAttribute('generation_meta'),true)?:[];$meta['download_retries']=0;$item->setAttribute('generation_meta',json_encode($meta));
            $job=Container::getInstance()->make(QueueManager::class)->push(GenerateVideoJob::class,['media_item_id'=>$id,'poll_attempt'=>(int)$item->getAttribute('poll_attempts')],'media');if(!$job)throw new RuntimeException('Queue unavailable');$item->setAttribute('job_id',$job);GenerationLifecycle::save($item);
            return $this->success(['id'=>$id],'استعادة نفس الفيديو بدون طلب توليد جديد');
        }catch(Throwable $e){return $this->error('تعذر جدولة الاستعادة؛ حاول بعد مراجعة الطلب',503);}finally{GenerationLifecycle::unlock($id);}
    }

}
