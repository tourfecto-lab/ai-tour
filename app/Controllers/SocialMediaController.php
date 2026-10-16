<?php
require_once __DIR__ . '/../Services/Security/OAuthState.php';
require_once __DIR__ . '/../Services/SocialMedia/SocialMonthlyPlanService.php';
require_once __DIR__ . '/../Jobs/GenerateSocialMonthlyPlanJob.php';
require_once __DIR__ . '/../Jobs/RefreshSocialPlanMetricsJob.php';

/**
 * Tourfecto - Social Media Controller
 * لوحة إدارة السوشيال ميديا (من ai-marketing-automation-hub، مُعاد بناؤها
 * فوق platform_connections/social_posts/social_post_targets الموحّدة)
 * @version 1.0.0
 */
class SocialMediaController extends Controller
{
    /** @var SocialPostService */
    private $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = new SocialPostService();
    }

    /** GET /social */
    public function index(array $params = []): array
    {
        $body = <<<HTML
        <style>
            .sp-media{border:1px dashed var(--panel-border);border-radius:12px;padding:12px;display:flex;gap:12px;align-items:center;flex-wrap:wrap}
            .sp-media .sp-prev{width:96px;height:96px;border-radius:10px;background:var(--panel-card-bg-2,rgba(127,127,127,.12));display:flex;align-items:center;justify-content:center;overflow:hidden;font-size:28px;flex:none}
            .sp-media .sp-prev img,.sp-media .sp-prev video{width:100%;height:100%;object-fit:cover}
            .sp-lib{display:grid;grid-template-columns:repeat(auto-fill,minmax(84px,1fr));gap:8px;margin-top:10px;max-height:220px;overflow:auto}
            .sp-lib button{border:2px solid transparent;border-radius:10px;padding:0;background:none;cursor:pointer;aspect-ratio:1;overflow:hidden}
            .sp-lib button img,.sp-lib button video{width:100%;height:100%;object-fit:cover;display:block}
            .sp-lib button.on{border-color:var(--panel-accent)}
            .sp-tgt{display:inline-flex;align-items:center;gap:4px;margin:2px 0 2px 6px;font-size:12px;white-space:nowrap}
            .sp-err{font-size:11.5px;color:var(--panel-danger);margin-top:3px;max-width:420px;white-space:normal}
            .sp-bars{display:flex;align-items:flex-end;gap:2px;height:110px;padding-top:6px}
            .sp-bars i{flex:1;background:var(--panel-accent);border-radius:3px 3px 0 0;min-height:2px;opacity:.85;cursor:default}
            .sp-bars i.z{opacity:.2}
            .sp-axis{display:flex;justify-content:space-between;font-size:11px;margin-top:4px}
            .sp-prow{display:flex;align-items:center;gap:10px;margin:9px 0;font-size:13px}
            .sp-prow .nm{width:92px;flex:none}
            .sp-meter{flex:1;height:8px;border-radius:6px;background:rgba(127,127,127,.18);overflow:hidden;display:flex}
            .sp-meter b{display:block;height:100%}
            .sp-eng{font-size:12px;white-space:nowrap;line-height:1.9}
            .sp-chip{display:inline-flex;align-items:center;gap:5px;border:1px solid var(--panel-border);border-radius:999px;padding:4px 11px;font-size:12.5px;margin:3px}
            .sp-chip.off{border-color:var(--panel-danger);color:var(--panel-danger)}
            .sp-cal{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px;padding:12px 16px 16px}
            .sp-cal .h{font-size:11.5px;text-align:center;padding:4px 0;color:var(--panel-muted,#888)}
            .sp-cal .d{min-height:74px;border:1px solid var(--panel-border);border-radius:8px;padding:4px;font-size:11px;overflow:hidden}
            .sp-cal .d.today{border-color:var(--panel-accent)}
            .sp-cal .d.out{opacity:.45}
            .sp-cal .d .n{font-weight:700;margin-bottom:2px}
            .sp-cal .e{display:block;border-radius:5px;padding:1px 4px;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;background:rgba(127,127,127,.18)}
            .sp-cal .e.published{background:rgba(34,197,94,.22)}
            .sp-cal .e.failed{background:rgba(239,68,68,.22)}
            .sp-cal .e.scheduled,.sp-cal .e.pending{background:rgba(99,102,241,.25)}
            @media (max-width:700px){.sp-cal .d{min-height:54px}.sp-cal .e{font-size:0;padding:3px}}
        </style>
        <div class="p-toolbar" style="justify-content:space-between;flex-wrap:wrap;gap:10px">
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <button class="p-btn primary" onclick="openNewPost()">+ New post</button>
                <select id="spDays" class="p-select" style="max-width:150px"><option value="7">Last 7 days</option><option value="30" selected>Last 30 days</option><option value="90">Last 90 days</option></select>
                <button class="p-btn outline" id="spMetricsBtn" onclick="refreshMetrics(true)">↻ Update Engagement of Meta</button>
            </div>
            <span class="p-cell-muted" id="spMetricsNote" style="font-size:12px">إنستجرام لازم صورة أو فيديو. الفيسبوك ينفع نص بس.</span>
        </div>

        <div class="p-grid cols-4" id="spStats">
            <div class="p-card stat-tile"><div class="stat-icon blue">📝</div><div class="stat-info"><div class="stat-value" id="stTotal">-</div><div class="stat-label">إجمالي المنشورات</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon green">✅</div><div class="stat-info"><div class="stat-value" id="stPub">-</div><div class="stat-label">Published <span id="stRate" class="p-cell-muted"></span></div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon purple">🗓️</div><div class="stat-info"><div class="stat-value" id="stSched">-</div><div class="stat-label">مجدول / مسودات</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon orange">⚠️</div><div class="stat-info"><div class="stat-value" id="stFail">-</div><div class="stat-label">Failed</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon blue">👁</div><div class="stat-info"><div class="stat-value" id="stReach">-</div><div class="stat-label">الوصول</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon green">👍</div><div class="stat-info"><div class="stat-value" id="stLikes">-</div><div class="stat-label">لايكات</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon purple">💬</div><div class="stat-info"><div class="stat-value" id="stComments">-</div><div class="stat-label">تعليقات</div></div></div>
            <div class="p-card stat-tile"><div class="stat-icon orange">🔁</div><div class="stat-info"><div class="stat-value" id="stShares">-</div><div class="stat-label">مشاركات</div></div></div>
        </div>

        <div class="p-grid cols-2" style="margin-top:14px">
            <div class="p-card"><div class="p-card-head"><h3>📈 النشر اليومي</h3><span class="p-card-sub" id="spDailySub"></span></div><div class="sp-bars" id="spDaily"></div><div class="sp-axis p-cell-muted" id="spDailyAxis"></div></div>
            <div class="p-card"><div class="p-card-head"><h3>📊 حسب المنصة</h3></div><div id="spPlat" class="p-cell-muted">Loading...</div></div>
        </div>
        <div class="p-grid cols-2" style="margin-top:14px">
            <div class="p-card"><div class="p-card-head"><h3>⏰ أفضل أوقات النشر</h3><span class="p-card-sub" id="spBestSub"></span></div><div id="spBest" class="p-cell-muted">Loading...</div></div>
            <div class="p-card"><div class="p-card-head"><h3>🏆 أفضل المنشورات تفاعلًا</h3></div><div id="spTop" class="p-cell-muted">Loading...</div></div>
        </div>
        <div class="p-card" style="margin:14px 0 18px"><div class="p-card-head"><h3>🔗 الحسابات المربوطة</h3><a class="p-btn outline sm" href="/ads/connect/meta?for=social">+ Connect account</a></div><div id="spAccounts" class="p-cell-muted">Loading...</div></div>
        <div class="p-card no-pad">
            <div class="p-toolbar" style="padding:14px 16px 0;gap:8px;flex-wrap:wrap">
                <input type="search" id="spQ" class="p-select" style="max-width:240px" placeholder="🔎 ابحث في النصوص...">
                <select id="spFStatus" class="p-select" style="max-width:150px"><option value="">All statuses</option><option value="published">Published</option><option value="scheduled">Scheduled</option><option value="draft">Draft</option><option value="failed">Failed</option><option value="partial">Partially completed</option><option value="uncertain">Needs review</option></select>
                <select id="spFPlat" class="p-select" style="max-width:150px"><option value="">All platforms</option><option value="facebook">Facebook</option><option value="instagram">Instagram</option><option value="tiktok">TikTok</option><option value="youtube">YouTube</option></select>
            </div>
            <div class="p-table-scroll"><table class="p-table" id="postsTable">
                <thead><tr><th>Content</th><th>Published on</th><th>Engagement</th><th>Status</th><th>Date</th><th></th></tr></thead>
                <tbody><tr class="p-loading-row"><td colspan="6">Loading...</td></tr></tbody>
            </table></div>
        </div>

        <div class="p-card no-pad" style="margin-top:18px;">
            <div class="p-card-head" style="padding:18px 20px 0;"><h3>📅 Content calendar</h3><span class="p-card-sub">Last week + next month</span></div>
            <div class="sp-cal" id="calendarGrid"><span class="p-cell-muted">Loading...</span></div>
        </div>

        <div class="p-modal-overlay" id="newPostModal">
            <div class="p-modal wide">
                <div class="p-modal-head"><h3>New post</h3><button class="p-modal-close" onclick="document.getElementById('newPostModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label>Topic (to generate text with AI)</label>
                    <input type="text" id="topicInput" class="p-select" style="width:100%;margin-bottom:10px;" placeholder="Example: special offer for Hurghada trips">
                    <button class="p-btn outline" id="aiCapBtn" onclick="generateCaption()">✨ Generate text with AI</button>
                    <label style="margin-top:14px;display:block;">Post text</label>
                    <textarea id="contentInput" rows="5" style="width:100%;" class="p-select"></textarea>

                    <label style="margin-top:14px;display:block;">Image or video</label>
                    <div class="sp-media">
                        <div class="sp-prev" id="spPrev">&#128444;&#65039;</div>
                        <div style="flex:1;min-width:200px">
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <label class="p-btn outline" style="margin:0;cursor:pointer">⬆️ ارفع من جهازك<input type="file" id="spFile" accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime" style="display:none"></label>
                                <button type="button" class="p-btn outline" onclick="toggleLib()">🎨 من المكتبة</button>
                                <a class="p-btn outline" href="/creative-studio" target="_blank" rel="noopener">✨ Generate image or video AI ↗</a>
                                <button type="button" class="p-btn ghost" id="spClear" style="display:none" onclick="clearMedia()">Remove</button>
                            </div>
                            <div class="p-cell-muted" id="spMediaNote" style="font-size:12px;margin-top:6px">صورة JPG/PNG/WEBP لحد 10 ميجا، أو فيديو MP4 لحد 100 ميجا. مقاس الصورة بيتظبط لإنستجرام تلقائي.</div>
                        </div>
                    </div>
                    <div class="sp-lib" id="spLib" style="display:none"></div>

                    <label style="margin-top:14px;display:block;">Publish on</label>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0"><button type="button" class="p-btn outline sm" onclick="refreshSocialTargets()">↻ تحديث الحسابات والصفحات</button><a class="p-btn outline sm" href="/ads/connect/meta?for=social">+ ربط صفحات Meta</a></div>
                    <div id="targetsList" class="p-cell-muted">Loading connected pages...</div>
                    <label style="margin-top:14px;display:block;">When?</label>
                    <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center">
                        <label style="display:flex;gap:6px;align-items:center"><input type="radio" name="spWhen" value="now" checked> Now</label>
                        <label style="display:flex;gap:6px;align-items:center"><input type="radio" name="spWhen" value="later"> Schedule</label>
                        <input type="datetime-local" id="spAt" class="p-select" style="display:none;max-width:240px">
                    </div>
                    <div class="p-cell-muted" style="font-size:12px;margin-top:6px">If you don't choose a page, the post will be saved as a draft.</div>
                </div>
                <div class="p-modal-foot">
                    <button class="p-btn outline" onclick="document.getElementById('newPostModal').classList.remove('open')">Cancel</button>
                    <button class="p-btn primary" id="spSubmit" onclick="createPost()">Publish</button>
                </div>
            </div>
        </div>

        <div class="p-modal-overlay" id="editPostModal">
            <div class="p-modal">
                <div class="p-modal-head"><h3>تعديل المنشور</h3><button class="p-modal-close" onclick="document.getElementById('editPostModal').classList.remove('open')">×</button></div>
                <div class="p-modal-body">
                    <label>Post text</label>
                    <textarea id="editContent" rows="6" style="width:100%;" class="p-select"></textarea>
                    <div id="editWhenBox" style="display:none;margin-top:14px">
                        <label style="display:block">موعد جديد (اختياري)</label>
                        <input type="datetime-local" id="editAt" class="p-select" style="max-width:260px">
                    </div>
                    <div class="p-cell-muted" style="font-size:12px;margin-top:8px">التعديل بيغيّر النص جوه المنصة بس، ومينفعش بعد ما المنشور يتنشر.</div>
                </div>
                <div class="p-modal-foot">
                    <button class="p-btn outline" onclick="document.getElementById('editPostModal').classList.remove('open')">Cancel</button>
                    <button class="p-btn primary" id="editSubmit" onclick="saveEdit()">Save</button>
                </div>
            </div>
        </div>
HTML;

        $monthlyCss = htmlspecialchars(asset_v('/assets/css/social-monthly-plans.css'), ENT_QUOTES, 'UTF-8');
        $monthlyJs = htmlspecialchars(asset_v('/assets/js/social-monthly-plans.js'), ENT_QUOTES, 'UTF-8');
        $monthlyUid = (int)($this->user['id']??0);
        $body = '<link rel="stylesheet" href="'.$monthlyCss.'"><section id="monthlySocialRoot" class="smp-root p-card" data-user-id="'.$monthlyUid.'" aria-label="Monthly social media plan"><p>Loading monthly plans… / جارِ تحميل الخطط الشهرية…</p></section><script defer src="'.htmlspecialchars(asset_v('/assets/js/site-photo-picker.js'), ENT_QUOTES, 'UTF-8').'"></script><script defer src="'.$monthlyJs.'"></script>'.$body;

        $script = <<<'JS'
(function () {
    const P = window.Panel;
    const esc = P.esc, fetchJSON = P.fetchJSON;
    let media = null;      // {id, file_path, is_video}
    let libLoaded = false;

    const SP_ST = { draft: '<span class="pill">Draft</span>', scheduled: '<span class="pill">Scheduled</span>', pending: '<span class="pill">Scheduled</span>', publishing: '<span class="pill orange">Publishing</span>', partial:'<span class="pill orange">Partially completed</span>',uncertain:'<span class="pill orange">Outcome needs review</span>',published: '<span class="pill green">✔ Published</span>', failed: '<span class="pill red">Failed</span>' };
    const PF = { facebook: '📘', instagram: '📸', tiktok: '🎵', youtube: '▶️' };
    // التواريخ في الداتابيز UTC - بنعرضها بتوقيت جهاز العميل
    const spLocal = s => window.Panel.formatDate ? window.Panel.formatDate(s) : (s || '-');
    const isVid = p => /\.(mp4|mov)$/i.test(p || '');

    function submitLabel() {
        const later = (document.querySelector('input[name=spWhen]:checked') || {}).value === 'later';
        const any = document.querySelectorAll('.targetCheckbox:checked').length > 0;
        document.getElementById('spSubmit').textContent = !any ? 'Save as draft' : (later ? 'Schedule' : 'Publish Now');
    }
    document.addEventListener('change', e => {
        if (!e.target) return;
        if (e.target.name === 'spWhen') { const at = document.getElementById('spAt'); at.style.display = e.target.value === 'later' ? 'block' : 'none'; if (!at.value) { const d = new Date(Date.now() + 3600e3); d.setMinutes(0, 0, 0); at.value = new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16); } }
        if (e.target.name === 'spWhen' || e.target.classList.contains('targetCheckbox')) submitLabel();
    });

    window.openNewPost = function () { document.getElementById('newPostModal').classList.add('open'); submitLabel(); loadTargets(); };
    window.refreshSocialTargets = async function () { await loadTargets(); P.toast('تم تحديث قائمة الحسابات', 'success'); };

    function setMedia(m) {
        media = m;
        const prev = document.getElementById('spPrev');
        prev.innerHTML = !m ? '&#128444;&#65039;' : (m.is_video ? '<video src="' + esc(m.file_path) + '" muted playsinline></video>' : '<img src="' + esc(m.file_path) + '" alt="">');
        document.getElementById('spClear').style.display = m ? '' : 'none';
        document.querySelectorAll('#spLib button').forEach(b => b.classList.toggle('on', !!m && +b.dataset.id === m.id));
    }
    window.clearMedia = function () { setMedia(null); document.getElementById('spFile').value = ''; };

    document.getElementById('spFile').addEventListener('change', async e => {
        const f = e.target.files && e.target.files[0];
        if (!f) return;
        const note = document.getElementById('spMediaNote');
        const old = note.textContent;
        note.textContent = 'جارِ الرفع...';
        const fd = new FormData(); fd.append('file', f);
        const res = await fetchJSON('/api/social/media', { method: 'POST', body: fd });
        note.textContent = old;
        if (res.success) { setMedia(res.data.item); P.toast('اترفعت ✓', 'success'); libLoaded = false; }
        else P.toast(res.error || 'Upload failed', 'error');
    });

    window.toggleLib = async function () {
        const box = document.getElementById('spLib');
        if (box.style.display !== 'none') { box.style.display = 'none'; return; }
        box.style.display = 'grid';
        if (libLoaded) return;
        box.innerHTML = '<span class="p-cell-muted">Loading...</span>';
        const res = await fetchJSON('/api/creative-studio/media');
        const items = ((res.success && res.data.items) || []).filter(i => i.status === 'completed' && i.file_path);
        libLoaded = true;
        box.innerHTML = items.length ? items.map(i => '<button type="button" data-id="' + i.id + '" data-path="' + esc(i.file_path) + '" title="' + esc(i.prompt || '') + '">' + (isVid(i.file_path) || i.type === 'short_video' ? '<video src="' + esc(i.file_path) + '" muted></video>' : '<img loading="lazy" src="' + esc(i.thumbnail_path || i.file_path) + '" alt="">') + '</button>').join('')
            : '<span class="p-cell-muted">مفيش صور في المكتبة لسه. <a href="/creative-studio">ولّد صورة من Creative Studio</a> أو ارفع من جهازك.</span>';
        box.querySelectorAll('button').forEach(b => b.addEventListener('click', () => setMedia({ id: +b.dataset.id, file_path: b.dataset.path, is_video: isVid(b.dataset.path) || !!b.querySelector('video') })));
    };

    async function loadTargets() {
        const box = document.getElementById('targetsList');
        box.textContent = 'جارِ تحديث الصفحات المرتبطة...';
        const res = await fetchJSON('/api/social/connections?_=' + Date.now(), { cache: 'no-store' });
        if (!res.success) { box.innerHTML = '<div class="alert alert-danger">تعذر تحميل الصفحات: ' + esc(res.error || '') + ' <a href="#" onclick="location.reload();return false">حدّث الصفحة</a></div>'; return; }
        const off = (res.data.disconnected || []);
        const offHtml = off.length ? '<div class="alert alert-warning" style="margin-top:8px;font-size:12.5px">⚠️ صفحات محتاجة إعادة ربط: ' + off.map(c => (PF[c.platform] || '') + ' <b>' + esc(c.name || c.platform) + '</b>' + (c.last_error ? ' <span class="p-cell-muted">(' + esc(c.last_error) + ')</span>' : '')).join(', ') + ' — <a href="/ads/connect/meta?for=social">اربط تاني</a></div>' : '';
        if (res.data.connections && res.data.connections.length) {
            const miss = res.data.missing_features || [];
            box.innerHTML = (miss.length ? '<div class="alert alert-warning" style="margin-bottom:10px;">⚠️ النشر مش هيشتغل على: <b>' + miss.map(esc).join(', ') + '</b> - صلاحيات ناقصة من Meta. <a href="/ads/connect/meta?for=social&rerequest=1">اربط تاني ووافق على كل الصلاحيات</a></div>' : '')
                + res.data.connections.map(c => `
                <label style="display:flex;align-items:center;gap:8px;margin:6px 0;color:var(--panel-text)">
                    <input type="checkbox" class="targetCheckbox" value="${c.id}" data-platform="${esc(c.platform)}">
                    ${PF[c.platform] || '•'} ${esc(c.name)} <span class="p-cell-muted" style="font-size:11.5px">(${c.platform === 'instagram' ? 'Instagram' : c.platform === 'facebook' ? 'Page Facebook' : esc(c.platform)})</span>${(c.missing || []).length ? ' <span class="p-cell-muted" title="' + esc(c.missing.join(', ')) + '">⚠️ صلاحية ناقصة</span>' : ''}
                </label>`).join('')
                + '<div style="margin-top:6px"><a class="p-cell-muted" style="font-size:12px" href="/ads/connect/meta?for=social">صفحة ناقصة؟ اربط تاني واختار كل الصفحات</a></div>' + offHtml;
        } else {
            const lc = res.data.last_connect;
            const diag = lc ? '<div class="p-cell-muted" style="font-size:12px;margin-top:8px">آخر ربط (' + esc(lc.at || '') + '): Meta رجّعت ' + (lc.pages || 0) + ' Page' + (lc.names && lc.names.length ? ' (' + lc.names.map(esc).join(', ') + ')' : '') + (lc.error ? ' — Error: ' + esc(lc.error) : '') + (lc.skipped ? ' — صفحات من غير صلاحية: ' + lc.skipped : '') + '</div>' : '';
            box.innerHTML = (off.length ? '' : '<div class="alert alert-warning">لم يتم العثور على صفحات قابلة للنشر. ربط حساب Meta لا يعني بالضرورة منح صلاحية إدارة الصفحات. تأكد من امتلاك صلاحية الصفحة، ثم أعد الربط مع اختيار الصفحات والموافقة على الأذونات.</div>') + '<a class="p-btn primary sm" href="/ads/connect/meta?for=social">اربط فيسبوك وإنستجرام</a>' + offHtml + diag;
        }
        if (new URLSearchParams(location.search).get('meta') === 'connected' && res.data.connections && res.data.connections.length) P.toast('تم العثور على صفحات النشر المرتبطة ✓', 'success');
    }

    const nf = n => Number(n || 0).toLocaleString((document.documentElement.lang || 'ar').slice(0, 2) === 'ar' ? 'ar-EG' : 'en-GB');
    const PN = { facebook: 'Facebook', instagram: 'Instagram', tiktok: 'TikTok', youtube: 'YouTube' };
    const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    const utc = s => new Date(String(s).replace(' ', 'T') + 'Z');
    let postsById = {};
    let autoRefreshed = false;

    function engCell(p) {
        const t = (p.targets || []).filter(x => x.status === 'published');
        if (!t.length) return '<span class="p-cell-muted">-</span>';
        return '<div class="sp-eng">' + t.map(x => {
            if (x.metrics_state === 'ok') return (PF[x.platform] || '•') + ' 👍 ' + nf(x.likes) + ' · 💬 ' + nf(x.comments) + ' · 🔁 ' + nf(x.shares) + (x.reach !== null && x.reach !== undefined ? ' · 👁 ' + nf(x.reach) : '');
            if (x.metrics_state === 'unavailable') return (PF[x.platform] || '•') + ' <span class="p-cell-muted" title="' + esc(x.metrics_note || '') + '">Unavailable</span>';
            return (PF[x.platform] || '•') + ' <span class="p-cell-muted">' + (x.platform === 'facebook' || x.platform === 'instagram' ? 'بيتحدّث...' : '-') + '</span>';
        }).join('<br>') + '</div>';
    }

    function targetsCell(p) {
        const t = p.targets || [];
        if (!t.length) return '<span class="p-cell-muted">-</span>';
        return t.map(x => '<div><span class="sp-tgt">' + (PF[x.platform] || '•') + ' ' + esc(x.name || x.platform) + ' ' + (SP_ST[x.status] || esc(x.status))
            + (x.status === 'published' && x.permalink ? ' <a href="' + esc(x.permalink) + '" target="_blank" rel="noopener">Open ↗</a>' : '')
            + (x.scheduled_at && x.status !== 'published' ? ' <span class="p-cell-muted">' + esc(spLocal(x.scheduled_at)) + '</span>' : '')
            + '</span>' + (['failed','uncertain'].includes(x.status) && x.last_error ? '<div class="sp-err">' + esc(x.last_error) + '</div>' : '') + '</div>').join('');
    }

    function filterQS() {
        const q = new URLSearchParams();
        const v = id => (document.getElementById(id) || {}).value || '';
        if (v('spQ').trim()) q.set('q', v('spQ').trim());
        if (v('spFStatus')) q.set('status', v('spFStatus'));
        if (v('spFPlat')) q.set('platform', v('spFPlat'));
        return q.toString();
    }

    let loadTimer = null;
    async function load() {
        clearTimeout(loadTimer);
        const res = await fetchJSON('/api/social/posts?' + filterQS());
        const tbody = document.querySelector('#postsTable tbody');
        if (res.success && res.data.posts && res.data.posts.length) {
            postsById = {};
            tbody.innerHTML = res.data.posts.map(p => {
                postsById[p.id] = p;
                const failed = (p.targets || []).some(x => x.status === 'failed');
                const busy = (p.targets || []).some(x => x.status === 'publishing');
                const editable = ['draft', 'scheduled', 'failed'].includes(p.status) && !busy;
                return `<tr>
                    <td>${p.media_path ? ((p.media_type === 'short_video' || isVid(p.media_path)) ? '🎬 ' : '<img src="' + esc(p.media_path) + '" alt="" style="width:38px;height:38px;object-fit:cover;border-radius:6px;vertical-align:middle;margin-inline-end:6px">') : ''}${esc((p.content || '').slice(0, 80))}</td>
                    <td>${targetsCell(p)}</td>
                    <td>${engCell(p)}</td>
                    <td>${SP_ST[p.status] || esc(p.status)}</td>
                    <td class="p-cell-muted">${esc(spLocal(p.created_at))}</td>
                    <td style="white-space:nowrap">${failed ? '<button class="p-btn outline xs" onclick="retryPost(' + p.id + ', this)">↻ Retry</button> ' : ''}${editable ? '<button class="p-btn ghost xs" onclick="editPost(' + p.id + ')" title="Edit">✏️</button>' : ''}<button class="p-btn ghost xs" onclick="duplicatePost(' + p.id + ')" title="نسخة كمسودة">📄</button>${!busy ? '<button class="p-btn ghost xs" onclick="deletePost(' + p.id + ')" title="مسح من المنصة بس (مش من فيسبوك)">🗑</button>' : ''}</td>
                </tr>`;
            }).join('');
            if (res.data.posts.some(p => p.status === 'publishing')) loadTimer = setTimeout(load, 8000);
            // أول مرة: لو فيه منشورات منشورة لسه مالهاش إحصائيات نسحبها تلقائي (بدون إزعاج)
            const needs = res.data.posts.some(p => (p.targets || []).some(x => x.status === 'published' && (x.platform === 'facebook' || x.platform === 'instagram') && !x.metrics_at));
            if (needs && !autoRefreshed) { autoRefreshed = true; refreshMetrics(false); }
        } else {
            tbody.innerHTML = '<tr><td colspan="6" class="p-empty">' + (filterQS() ? 'مفيش نتايج بالفلتر ده' : 'No posts yet') + '</td></tr>';
        }
    }

    // ---------- الإحصائيات ----------
    function bestTimes(samples) {
        if (!samples || samples.length < 3) return null;
        const hasScore = samples.some(s => +s.score > 0);
        const m = {};
        samples.forEach(s => {
            const d = utc(s.at); if (isNaN(d)) return;
            const k = d.getDay() + '-' + d.getHours();
            m[k] = m[k] || { dow: d.getDay(), h: d.getHours(), n: 0, sc: 0 };
            m[k].n++; m[k].sc += +s.score || 0;
        });
        return Object.values(m).sort((a, b) => hasScore ? (b.sc / b.n) - (a.sc / a.n) || b.n - a.n : b.n - a.n).slice(0, 3).map(x => ({ ...x, hasScore }));
    }
    const hLabel = h => (h % 12 === 0 ? 12 : h % 12) + (h < 12 ? ' ص' : ' م');

    async function loadStats() {
        const days = +document.getElementById('spDays').value || 30;
        const res = await fetchJSON('/api/social/stats?days=' + days);
        const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
        if (!res.success) {
            ['stTotal', 'stPub', 'stSched', 'stFail', 'stReach', 'stLikes', 'stComments', 'stShares'].forEach(i => set(i, '-'));
            document.getElementById('spPlat').innerHTML = '<span class="p-cell-muted">' + esc(res.error || 'تعذر تحميل الإحصائيات') + '</span>';
            document.getElementById('spBest').innerHTML = document.getElementById('spTop').innerHTML = '';
            return;
        }
        const d = res.data, st = d.by_status || {}, e = d.engagement || {};
        set('stTotal', nf(d.total_posts)); set('stPub', nf(st.published)); set('stRate', d.success_rate !== null ? '(' + nf(d.success_rate) + '% Success)' : '');
        set('stSched', nf((st.scheduled || 0) + (st.publishing || 0)) + ' / ' + nf(st.draft)); set('stFail', nf(st.failed));
        const hasM = (e.measured || 0) > 0;
        set('stReach', hasM ? nf(e.reach) : '-'); set('stLikes', hasM ? nf(e.likes) : '-'); set('stComments', hasM ? nf(e.comments) : '-'); set('stShares', hasM ? nf(e.shares) : '-');
        if (e.last_fetch) document.getElementById('spMetricsNote').textContent = 'Last updated للتفاعل: ' + spLocal(e.last_fetch);

        // النشر اليومي
        const ser = d.daily || [], max = Math.max(1, ...ser.map(x => x.count));
        document.getElementById('spDaily').innerHTML = ser.map(x => '<i class="' + (x.count ? '' : 'z') + '" style="height:' + Math.max(2, Math.round(x.count / max * 100)) + '%" title="' + esc(x.date) + ': ' + x.count + '"></i>').join('');
        document.getElementById('spDailyAxis').innerHTML = ser.length ? '<span>' + esc(ser[0].date.slice(5)) + '</span><span>' + esc(ser[ser.length - 1].date.slice(5)) + '</span>' : '';
        document.getElementById('spDailySub').textContent = nf(ser.reduce((a, x) => a + x.count, 0)) + ' Publish';

        // حسب المنصة
        const pl = Object.entries(d.by_platform || {});
        document.getElementById('spPlat').innerHTML = pl.length ? pl.map(([k, v]) => {
            const tot = Math.max(1, v.total);
            return '<div class="sp-prow"><span class="nm">' + (PF[k] || '•') + ' ' + esc(PN[k] || k) + '</span><div class="sp-meter" title="Published ' + v.published + ' · Failed ' + v.failed + ' · Scheduled ' + v.scheduled + '"><b style="width:' + v.published / tot * 100 + '%;background:#22c55e"></b><b style="width:' + v.scheduled / tot * 100 + '%;background:#6366f1"></b><b style="width:' + v.failed / tot * 100 + '%;background:#ef4444"></b></div><span style="width:34px;text-align:end">' + nf(v.total) + '</span></div>';
        }).join('') + '<div class="p-cell-muted" style="font-size:11.5px">🟢 Published &nbsp;🟣 Scheduled &nbsp;🔴 Failed</div>' : '<span class="p-cell-muted">مفيش بيانات في الفترة دي</span>';

        // أفضل الأوقات (بتوقيت جهازك)
        const bt = bestTimes(d.samples);
        document.getElementById('spBestSub').textContent = bt ? (bt[0].hasScore ? 'حسب متوسط التفاعل' : 'حسب عدد مرات النشر') : '';
        document.getElementById('spBest').innerHTML = bt ? bt.map((x, i) => '<div class="sp-prow"><span style="font-size:18px">' + ['🥇', '🥈', '🥉'][i] + '</span><b>' + DAYS[x.dow] + '</b><span>' + hLabel(x.h) + '</span><span class="p-cell-muted" style="margin-inline-start:auto">' + nf(x.n) + ' Post</span></div>').join('') : '<span class="p-cell-muted">محتاج 3 منشورات منشورة على الأقل عشان نقترح أفضل ميعاد.</span>';

        // أفضل المنشورات
        const top = d.top_posts || [];
        document.getElementById('spTop').innerHTML = top.length ? top.map(x => '<div class="sp-prow"><span>' + (PF[x.platform] || '•') + '</span><span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(x.content) + '</span><span class="sp-eng">👍 ' + nf(x.likes) + ' · 💬 ' + nf(x.comments) + ' · 🔁 ' + nf(x.shares) + '</span></div>').join('') : '<span class="p-cell-muted">لسه مفيش بيانات تفاعل. اضغط «تحديث التفاعل من Meta».</span>';
    }

    window.refreshMetrics = async function (manual) {
        const btn = document.getElementById('spMetricsBtn');
        if (manual) { btn.disabled = true; btn.textContent = 'جارِ السحب من Meta...'; }
        const res = await fetchJSON('/api/social/metrics/refresh', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ force: manual ? 1 : 0 }) });
        btn.disabled = false; btn.textContent = '↻ Update Engagement of Meta';
        if (res.success) {
            if (manual) P.toast(res.data.checked ? 'Updated Engagement for ' + res.data.updated + ' Post' + (res.data.unavailable ? ' (' + res.data.unavailable + ' غير متاح - غالبًا صلاحية read_insights ناقصة)' : '') : 'مفيش منشورات منشورة على فيسبوك/إنستجرام للتحديث', res.data.unavailable ? 'error' : 'success');
            loadStats(); load();
        } else if (manual) P.toast(res.error || 'Could not update', 'error');
    };

    // ---------- الحسابات ----------
    async function loadAccounts() {
        const box = document.getElementById('spAccounts');
        const res = await fetchJSON('/api/social/connections?_=' + Date.now(), { cache: 'no-store' });
        if (!res.success) { box.textContent = res.error || 'Could not load'; return; }
        const on = res.data.connections || [], off = res.data.disconnected || [];
        box.innerHTML = (on.length ? on.map(c => '<span class="sp-chip">' + (PF[c.platform] || '•') + ' ' + esc(c.name || c.platform) + ' <span style="color:#22c55e">●</span></span>').join('') : '<span class="p-cell-muted">مفيش حسابات متصلة. اربط فيسبوك/إنستجرام من الزرار فوق.</span>')
            + off.map(c => '<span class="sp-chip off" title="' + esc(c.last_error || '') + '">' + (PF[c.platform] || '•') + ' ' + esc(c.name || c.platform) + ' — محتاج إعادة ربط</span>').join('');
    }

    // ---------- التقويم (شبكة شهرية) ----------
    async function loadCalendar() {
        const res = await fetchJSON('/api/social/calendar');
        const box = document.getElementById('calendarGrid');
        const rows = (res.success && res.data.items) || [];
        const today = new Date(); today.setHours(0, 0, 0, 0);
        const start = new Date(today); start.setDate(start.getDate() - 7);
        start.setDate(start.getDate() - ((start.getDay() + 1) % 7)); // الأسبوع يبدأ السبت
        const key = d => d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate();
        const byDay = {};
        rows.forEach(r => { const d = utc(r.scheduled_at); if (!isNaN(d)) (byDay[key(d)] = byDay[key(d)] || []).push({ d, r }); });
        let html = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'].map(n => '<div class="h">' + n + '</div>').join('');
        for (let i = 0; i < 42; i++) {
            const d = new Date(start); d.setDate(start.getDate() + i);
            const ev = (byDay[key(d)] || []).sort((a, b) => a.d - b.d);
            html += '<div class="d' + (+d === +today ? ' today' : '') + (d < today ? ' out' : '') + '"><div class="n">' + d.getDate() + (d.getDate() === 1 ? ' / ' + (d.getMonth() + 1) : '') + '</div>'
                + ev.slice(0, 3).map(x => '<span class="e ' + esc(x.r.status) + '" title="' + esc((x.r.content || '').slice(0, 120)) + '">' + (PF[x.r.platform] || '•') + ' ' + x.d.toLocaleTimeString((document.documentElement.lang || 'ar').slice(0, 2) === 'ar' ? 'ar-EG-u-nu-latn' : 'en-GB', { hour: 'numeric', minute: '2-digit' }) + ' ' + esc((x.r.content || '').slice(0, 14)) + '</span>').join('')
                + (ev.length > 3 ? '<span class="p-cell-muted">+' + (ev.length - 3) + '</span>' : '') + '</div>';
        }
        box.innerHTML = html;
    }

    // ---------- Edit / Copy ----------
    let editId = null;
    window.editPost = function (id) {
        const p = postsById[id]; if (!p) return;
        editId = id;
        document.getElementById('editContent').value = p.content || '';
        document.getElementById('editAt').value = '';
        document.getElementById('editWhenBox').style.display = (p.targets || []).some(x => x.status === 'scheduled') ? 'block' : 'none';
        document.getElementById('editPostModal').classList.add('open');
    };
    window.saveEdit = async function () {
        const content = document.getElementById('editContent').value.trim();
        if (!content) { P.toast('Write Post text', 'error'); return; }
        const atVal = document.getElementById('editAt').value;
        const btn = document.getElementById('editSubmit'); btn.disabled = true;
        const res = await fetchJSON('/api/social/posts/' + editId + '/update', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ content, scheduled_at: atVal || '' }) });
        btn.disabled = false;
        if (res.success) { document.getElementById('editPostModal').classList.remove('open'); P.toast('اتحفظ التعديل ✔', 'success'); load(); loadCalendar(); }
        else P.toast(res.error || 'Could not save', 'error');
    };
    window.duplicatePost = async function (id) {
        const res = await fetchJSON('/api/social/posts/' + id + '/duplicate', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast('اتعملت نسخة كمسودة - عدّلها وانشرها', 'success'); load(); loadStats(); } else P.toast(res.error || 'Could not', 'error');
    };

    window.generateCaption = async function () {
        const topic = document.getElementById('topicInput').value.trim();
        if (!topic) { P.toast('اكتب الموضوع الأول', 'error'); return; }
        const btn = document.getElementById('aiCapBtn'); btn.disabled = true;
        const res = await fetchJSON('/api/social/generate-caption', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ topic, platform: 'instagram', website_id: P.getCurrentWebsiteId ? P.getCurrentWebsiteId() : null }) });
        btn.disabled = false;
        if (res.success) document.getElementById('contentInput').value = res.data.content;
        else P.toast(res.error || 'Generation failed', 'error');
    };

    window.createPost = async function () {
        const content = document.getElementById('contentInput').value.trim();
        if (!content) { P.toast('Write Post text', 'error'); return; }
        const later = (document.querySelector('input[name=spWhen]:checked') || {}).value === 'later';
        const atVal = document.getElementById('spAt').value;
        if (later && !atVal) { P.toast('Pick a publish time', 'error'); return; }
        const checked = Array.from(document.querySelectorAll('.targetCheckbox:checked'));
        if (!media && checked.some(cb => cb.dataset.platform === 'instagram')) { P.toast('إنستجرام محتاج صورة أو فيديو - ارفع صورة الأول', 'error'); return; }
        const scheduledAt = later ? atVal : null; // UTC
        const targets = checked.map(cb => ({ platform_connection_id: parseInt(cb.value, 10), scheduled_at: scheduledAt }));
        const btn = document.getElementById('spSubmit'); btn.disabled = true; const lbl = btn.textContent; btn.textContent = targets.length && !later ? 'Publishing...' : 'Saving...';
        const res = await fetchJSON('/api/social/posts', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ content, targets, media_item_id: media ? media.id : null }) });
        btn.disabled = false; btn.textContent = lbl;
        if (res.success) {
            document.getElementById('newPostModal').classList.remove('open');
            const st = res.data.post && res.data.post.status;
            P.toast(!targets.length ? 'Saved as draft' : (later ? 'Scheduled ✔' : st === 'published' ? 'Published ✔' : st === 'failed' ? 'النشر فشل - شوف السبب في الجدول' : 'Publishing...'), st === 'failed' ? 'error' : 'success');
            document.getElementById('contentInput').value = ''; document.getElementById('topicInput').value = ''; clearMedia();
            load(); loadCalendar(); loadStats();
        } else {
            P.toast(res.error || 'Save failed', 'error');
        }
    };

    window.retryPost = async function (id, btn) {
        if (btn) { btn.disabled = true; btn.textContent = 'Publishing...'; }
        const res = await fetchJSON('/api/social/posts/' + id + '/retry', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) P.toast(res.data.status === 'published' ? 'Published ✔' : res.data.status === 'failed' ? 'لسه فاشل - شوف السبب' : 'Publishing...', res.data.status === 'failed' ? 'error' : 'success');
        else P.toast(res.error || 'Could not', 'error');
        load();
    };

    window.deletePost = async function (id) {
        if (!confirm('مسح المنشور من المنصة؟ (مش هيتمسح من فيسبوك/إنستجرام لو كان اتنشر)')) return;
        const res = await fetchJSON('/api/social/posts/' + id + '/delete', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        if (res.success) { P.toast('اتمسح', 'success'); load(); loadCalendar(); loadStats(); } else P.toast(res.error || 'Could not', 'error');
    };

    let qTimer = null;
    ['spFStatus', 'spFPlat'].forEach(id => document.getElementById(id).addEventListener('change', load));
    document.getElementById('spQ').addEventListener('input', () => { clearTimeout(qTimer); qTimer = setTimeout(load, 350); });
    document.getElementById('spDays').addEventListener('change', loadStats);

    load();
    loadStats();
    loadCalendar();
    loadAccounts();
    loadTargets();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('social', 'Social media', 'Manage and publish social media content across all connected platforms', $body, $script);
        exit;
    }

    /** Monthly-plan endpoints share the same owner scope and delivery service. */
    private function monthlyRequest(string $action,array $params=[]): array
    {
        if(!$this->isAuthenticated())return $this->error('Unauthorized',401);
        $write=in_array($action,['create','edit','approve','pause','resume','cancel'],true);
        if($write&&($error=$this->verifyCsrf()))return $error;
        if($write&&($error=$this->rateLimitGuard('user',$action==='create'?'social_monthly_ai':'social_monthly_write',$action==='create'?4:40,60)))return $error;
        $uid=(int)$this->user['id'];$id=(int)($params['id']??0);$service=new SocialMonthlyPlanService();
        try {
            if($action==='setup'){$result=$service->setup($uid);$result['csrf_token']=Csrf::token();return $this->success($result);}
            if($action==='list')return $this->success(['plans'=>$service->listPlans($uid)]);
            if($action==='detail')return $this->success($service->detail($uid,$id));
            if($action==='create')return $this->success($service->create($uid,$this->data),'Monthly generation queued',201);
            if($action==='edit')return $this->success($service->edit($uid,$id,(int)($params['item_id']??0),$this->data));
            if($action==='approve'&&$this->get('reviewed')!==true)return $this->error('Confirm review before automatic publishing.',422);
            if($action==='approve')return $this->success($service->approve($uid,$id,(int)$this->get('version',0)));
            return $this->success($service->changeState($uid,$id,$action));
        }catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}
        catch(ProviderRequestFailure $e){return $this->error('تعذر إعداد الخطة. حدّث قائمة الخطط قبل المحاولة مجددًا / Could not prepare the plan. Refresh the list before retrying.',502);}
        catch(Throwable $e){Logger::error('Monthly social plan action failed',['action'=>$action,'plan_id'=>$id,'user_id'=>$uid,'error'=>$e->getMessage()]);return $this->error('تعذر تنفيذ الطلب. تحقق من تركيب SQL وتشغيل مهام النشر ثم حدّث قائمة الخطط / Could not complete the request. Check migration and publishing worker, then refresh the plan list.',503);}
    }
    public function monthlySetup(array $params=[]): array{return $this->monthlyRequest('setup',$params);}
    public function monthlyList(array $params=[]): array{return $this->monthlyRequest('list',$params);}
    public function monthlyDetail(array $params=[]): array{return $this->monthlyRequest('detail',$params);}
    public function monthlyCreate(array $params=[]): array{return $this->monthlyRequest('create',$params);}
    public function monthlyEdit(array $params=[]): array{return $this->monthlyRequest('edit',$params);}
    public function monthlyApprove(array $params=[]): array{return $this->monthlyRequest('approve',$params);}
    public function monthlyPause(array $params=[]): array{return $this->monthlyRequest('pause',$params);}
    public function monthlyResume(array $params=[]): array{return $this->monthlyRequest('resume',$params);}
    public function monthlyCancel(array $params=[]): array{return $this->monthlyRequest('cancel',$params);}

    /** POST /api/social/media - رفع صورة/فيديو من الجهاز للمنشور */
    public function uploadMedia(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($rateError = $this->rateLimitGuard('user', 'social_upload', 30, 60)) {
            return $rateError;
        }
        $r = SocialMediaUpload::handle((int) $this->user['id'], $_FILES['file'] ?? null);
        if (!$r['ok']) {
            return $this->error($r['error'], 422);
        }
        return $this->success(['item' => $r['item'], 'duplicate' => !empty($r['duplicate'])], !empty($r['duplicate']) ? 'الصورة دي موجودة في مكتبتك بالفعل' : 'اترفع', 201);
    }

    /** POST /api/social/brand-kit - لوجو وألوان الشركة اللي بتتحط على التصميمات */
    public function saveBrandKit(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_brand_kit', 20, 60))) {
            return $error;
        }
        try {
            $kit = (new SocialBrandStudio())->saveKit((int) $this->user['id'], (int) ($_POST['website_id'] ?? $this->get('website_id', 0)), $_FILES['logo'] ?? null, (string) ($_POST['primary'] ?? $this->get('primary', '')), (string) ($_POST['accent'] ?? $this->get('accent', '')));
            return $this->success(['brand' => $kit], 'اتحفظت هوية الشركة');
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Logger::error('Brand kit save failed', ['error' => $e->getMessage()]);
            return $this->error('مقدرناش نحفظ الهوية دلوقتي، جرّب تاني', 500);
        }
    }

    /** POST /api/social/media/import-site - سحب صور الرحلات والفنادق من موقع العميل لمكتبته */
    public function importSiteImages(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_site_import', 4, 300))) {
            return $error;
        }
        require_once __DIR__ . '/../Services/SocialMedia/SiteImageImporter.php';
        @set_time_limit(90);
        try {
            $uid = (int) $this->user['id'];
            $wid = (int) $this->get('website_id', 0);
            $importer = new SiteImageImporter();
            if ($wid > 0) {
                $r = $importer->import($uid, $wid, 20);
                return $this->success($r, $r['message']);
            }
            // من غير موقع محدد (مكتبة الاستوديو): كل مواقع العميل المربوطة
            $sites = $this->db->query('SELECT id FROM websites WHERE user_id = ? ORDER BY id DESC LIMIT 3', [$uid]) ?: [];
            if (!$sites) {
                return $this->error('اربط موقعك الأول من "مواقعي" عشان نسحب صوره', 422);
            }
            $importer->budgetSeconds = (int) floor(38 / count($sites));
            $added = [];
            $found = 0;
            foreach ($sites as $site) {
                $r = $importer->import($uid, (int) $site['id'], max(5, (int) floor(20 / count($sites))));
                $added = array_merge($added, $r['added']);
                $found += $r['found'];
            }
            $msg = $added ? 'سحبنا ' . count($added) . ' صورة من موقعك.' : ($found ? 'كل صور موقعك المناسبة موجودة في مكتبتك بالفعل.' : 'مالقيناش صور كبيرة كفاية على موقعك. ارفع صورك من جهازك.');
            return $this->success(['added' => $added, 'found' => $found, 'message' => $msg], $msg);
        } catch (InvalidArgumentException $e) {
            return $this->error('اختار الموقع', 422);
        } catch (Throwable $e) {
            Logger::error('Site image import failed', ['error' => $e->getMessage()]);
            return $this->error('مقدرناش نسحب الصور من موقعك دلوقتي، جرّب تاني أو ارفع صورك من جهازك', 500);
        }
    }

    /** POST /api/social/media/site-scan - يدوّر على صور الموقع (أو روابط رحلات) ويعرضها للاختيار */
    public function scanSiteImages(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_site_scan', 10, 300))) {
            return $error;
        }
        require_once __DIR__ . '/../Services/SocialMedia/SiteImageImporter.php';
        @set_time_limit(90);
        $uid = (int) $this->user['id'];
        $wid = (int) $this->get('website_id', 0);
        if ($wid < 1) {
            $row = $this->db->query('SELECT id FROM websites WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$uid]);
            $wid = (int) ($row[0]['id'] ?? 0);
        }
        $urls = $this->get('urls', []);
        $urls = is_array($urls) ? array_values(array_filter($urls, 'is_string')) : [];
        if (!$urls && $wid < 1) {
            return $this->error('اربط موقعك الأول، أو حط روابط الرحلات اللي عاوز صورها', 422);
        }
        try {
            $r = (new SiteImageImporter())->scan($uid, $wid, $urls);
            if (!$r['total']) {
                return $this->error($urls ? 'مالقيناش صور في الروابط دي. اتأكد إن الروابط بتفتح.' : 'مالقيناش صور على موقعك. حط روابط صفحات الرحلات بنفسك.', 422);
            }
            return $this->success($r);
        } catch (InvalidArgumentException $e) {
            return $this->error('اختار الموقع', 422);
        } catch (Throwable $e) {
            Logger::error('Site image scan failed', ['error' => $e->getMessage()]);
            return $this->error('مقدرناش نفتح الموقع دلوقتي، جرّب تاني', 500);
        }
    }

    /** POST /api/social/media/site-import-selected - يسحب الصور اللي العميل اختارها بس */
    public function importSelectedImages(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_site_pick', 60, 300))) {
            return $error;
        }
        require_once __DIR__ . '/../Services/SocialMedia/SiteImageImporter.php';
        @set_time_limit(90);
        $items = $this->get('items', []);
        if (!is_array($items) || !$items || count($items) > 25) {
            return $this->error('اختار من 1 لـ 25 صورة في المرة', 422);
        }
        $clean = [];
        foreach ($items as $it) {
            if (is_array($it) && is_string($it['src'] ?? null)) {
                $clean[] = ['src' => $it['src'], 'alt' => is_string($it['alt'] ?? null) ? $it['alt'] : '', 'page' => is_string($it['page'] ?? null) ? $it['page'] : '', 'title' => is_string($it['title'] ?? null) ? $it['title'] : ''];
            }
        }
        try {
            return $this->success((new SiteImageImporter())->importItems((int) $this->user['id'], $clean, $this->get('folders') === true));
        } catch (Throwable $e) {
            Logger::error('Selected image import failed', ['error' => $e->getMessage()]);
            return $this->error('مقدرناش نسحب الصور دلوقتي، جرّب تاني', 500);
        }
    }

    /** POST /api/social/media/{id}/learn - الذكاء الصناعي يشوف الصورة ويفهمها */
    public function learnMedia(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_learn', 40, 60))) {
            return $error;
        }
        try {
            $v = (new SocialBrandStudio())->learn((int) $this->user['id'], (int) ($params['id'] ?? 0));
            return $this->success(['vision' => $v]);
        } catch (InvalidArgumentException $e) {
            return $this->error('الصورة مش موجودة', 404);
        } catch (Throwable $e) {
            return $this->success(['vision' => null]);
        }
    }

    /** POST /api/social/design - تصميم بوست واحد بهوية الشركة من صورة العميل (مجانًا - مفيش توليد صور) */
    public function designPost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (($error = $this->verifyCsrf()) || ($error = $this->rateLimitGuard('user', 'social_design', 20, 60))) {
            return $error;
        }
        $headline = trim((string) $this->get('headline', ''));
        if (mb_strlen($headline) < 3 || mb_strlen($headline) > 90) {
            return $this->error('اكتب عنوان قصير للتصميم (من 3 لـ 90 حرف)', 422);
        }
        $uid = (int) $this->user['id'];
        $mediaId = (int) $this->get('media_id', 0);
        try {
            $studio = new SocialBrandStudio();
            $id = $studio->design($uid, (int) $this->get('website_id', 0), (string) $this->get('format', 'design'), $headline, (string) $this->get('subline', ''), $mediaId > 0 ? $mediaId : null, (int) $this->get('variant', 0));
            $row = PrivateMedia::owned($uid, $id);
            return $this->success(['id' => $id, 'preview_url' => $row['file_path']], 'التصميم جاهز', 201);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage() === 'Website unavailable' ? 'اختار الموقع' : 'الصورة مش موجودة', 422);
        } catch (Throwable $e) {
            Logger::error('Design post failed', ['error' => $e->getMessage()]);
            return $this->error('مقدرناش نعمل التصميم دلوقتي، جرّب تاني', 500);
        }
    }

    /** POST /api/social/posts/{id}/retry - إعادة نشر الأهداف اللي فشلت */
    public function retryPost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $uid = (int) $this->user['id'];
        $postId = (int) ($params['id'] ?? 0);
        if (!$this->db->query('SELECT id FROM social_posts WHERE id = ? AND user_id = ? LIMIT 1', [$postId, $uid])) {
            return $this->error('المنشور مش موجود', 404);
        }
        $failed = $this->db->query("SELECT id FROM social_post_targets WHERE social_post_id = ? AND status = 'failed'", [$postId]);
        if (!$failed) {
            return $this->error('مفيش حاجة فاشلة في المنشور ده', 422);
        }
        $queue = Container::getInstance()->make(QueueManager::class);
        $jobs = [];
        foreach ($failed as $t) {
            $this->db->query("UPDATE social_post_targets SET status = 'scheduled', last_error = NULL, provider_ref = NULL, poll_attempts = 0, scheduled_at = NULL WHERE id = ?", [(int) $t['id']]);
            $id = $queue->push(PublishSocialPostJob::class, ['social_post_target_id' => (int) $t['id']], 'social', 0);
            if ($id) {
                $jobs[] = (int) $id;
            }
        }
        SocialPostService::runNow($jobs);
        return $this->success(['status' => SocialPostService::refreshPostStatus($postId)]);
    }

    /** POST /api/social/posts/{id}/delete - مسح من المنصة بس */
    public function deletePost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $postId = (int) ($params['id'] ?? 0);
        if (!$this->db->query('SELECT id FROM social_posts WHERE id = ? AND user_id = ? LIMIT 1', [$postId, (int) $this->user['id']])) {
            return $this->error('المنشور مش موجود', 404);
        }
        if ($this->db->query("SELECT id FROM social_post_targets WHERE social_post_id = ? AND status = 'publishing' LIMIT 1", [$postId])) {
            return $this->error('المنشور بيتنشر دلوقتي، استنى لما يخلص', 422);
        }
        $this->db->query('DELETE FROM social_post_targets WHERE social_post_id = ?', [$postId]);
        $this->db->query('DELETE FROM social_posts WHERE id = ?', [$postId]);
        return $this->success([], 'اتمسح');
    }

    /** GET /api/social/connections - الحسابات المتصلة للنشر عليها */
    public function listConnections(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            // كل الاتصالات (حتى اللي فاصلة) عشان العميل يشوف السبب بدل "مفيش صفحات"
            $all = $this->db->query(
                "SELECT id, platform, external_location_id, external_location_name AS name, status, last_error
                 FROM platform_connections
                 WHERE (user_id = ? OR website_id IN (SELECT id FROM websites WHERE user_id = ?)) AND platform IN ('facebook', 'instagram', 'tiktok', 'youtube')
                 ORDER BY (status = 'connected') DESC, id DESC",
                [$this->user['id'], $this->user['id']]
            );
            $rows = [];
            $broken = [];
            $seen = [];
            foreach ($all as $r) {
                $k = $r['platform'] . ':' . ($r['external_location_id'] ?: 'connection-' . $r['id']);
                if (isset($seen[$k])) {
                    continue; // نفس الصفحة متسجلة أكتر من مرة (موقعين مثلًا) - نعرضها مرة واحدة
                }
                $seen[$k] = true;
                if ($r['status'] === 'connected') {
                    $rows[] = $r;
                } else {
                    $broken[] = ['platform' => $r['platform'], 'name' => $r['name'], 'status' => $r['status'], 'last_error' => $r['last_error'] ? mb_substr((string) $r['last_error'], 0, 300) : null];
                }
            }
            foreach ($rows as &$r0) {
                unset($r0['external_location_id'], $r0['status'], $r0['last_error']);
            }
            unset($r0);
            // Schema-safe: platform_connections in the supplied SQL has no meta_json column.
            // Permission diagnostics belong to the OAuth callback; never assume extra DB fields.
            $missing = [];
            $out = ['connections' => $rows, 'disconnected' => $broken, 'missing_features' => array_values($missing), 'connected_count' => count($rows)];
            // تشخيص: آخر محاولة ربط لاقت كام صفحة (بيتسجل في الكول باك) - بنرجّعه دايمًا
            // دلوقتي (مش بس لما مفيش اتصالات) عشان نعرف Meta رجّعت كام صفحة فعلًا.
            $out['last_connect'] = $_SESSION['meta_last_connect'] ?? null;
            return $this->success($out);
        } catch (Throwable $e) {
            Logger::error('listConnections Error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return $this->error('تعذر جلب الصفحات المتصلة. حاول تحديث الصفحة، وإذا استمرت المشكلة تواصل مع الدعم.', 500);
        }
    }

    /** GET /api/social/posts - كل منشور ومعاه نتيجة كل صفحة (اتنشر/فشل + السبب + الإحصائيات) - فلاتر: status, platform, q */
    public function listPosts(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        $this->catchUpOverdue((int) $this->user['id']);

        $where = ['sp.user_id = ?'];
        $args = [(int) $this->user['id']];
        $status = (string) $this->get('status', '');
        if (in_array($status, ['draft', 'scheduled', 'publishing', 'published', 'failed', 'partial', 'uncertain'], true)) {
            $where[] = 'sp.status = ?';
            $args[] = $status;
        }
        $platform = (string) $this->get('platform', '');
        if (in_array($platform, ['facebook', 'instagram', 'tiktok', 'youtube'], true)) {
            $where[] = 'EXISTS (SELECT 1 FROM social_post_targets tt JOIN platform_connections cc ON cc.id = tt.platform_connection_id WHERE tt.social_post_id = sp.id AND cc.platform = ?)';
            $args[] = $platform;
        }
        $q = trim((string) $this->get('q', ''));
        if ($q !== '') {
            $where[] = 'sp.content LIKE ?';
            $args[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
        }
        $limit = max(10, min(100, (int) $this->get('limit', 50)));

        $posts = $this->db->query(
            'SELECT sp.id, sp.content, sp.status, sp.media_item_id, sp.created_at, m.file_path AS media_path, m.type AS media_type, m.user_id AS media_owner, m.deleted_at AS media_deleted, m.status AS media_status
               FROM social_posts sp LEFT JOIN media_items m ON m.id = sp.media_item_id
              WHERE ' . implode(' AND ', $where) . ' ORDER BY sp.created_at DESC, sp.id DESC LIMIT ' . $limit,
            $args
        );
        foreach ($posts as &$mediaPost) {
            if ((int)($mediaPost['media_owner'] ?? 0) !== (int)$this->user['id'] || !empty($mediaPost['media_deleted']) || ($mediaPost['media_status'] ?? '') !== 'completed') {
                $mediaPost['media_path'] = null;
            } elseif (PrivateMedia::valid((string)($mediaPost['media_path'] ?? ''))) {
                $mediaPost['media_path'] = PrivateMedia::preview((int)$mediaPost['media_item_id']);
            }
            unset($mediaPost['media_owner'], $mediaPost['media_deleted'], $mediaPost['media_status']);
        }
        unset($mediaPost);
        $ids = array_map(fn ($p) => (int) $p['id'], $posts);
        $byPost = [];
        if ($ids) {
            $rows = $this->db->query(
                'SELECT t.id AS target_id, t.social_post_id, t.status, t.last_error, t.scheduled_at, t.published_at, t.external_post_id,
                        c.platform, c.external_location_name AS name,
                        pm.likes, pm.comments, pm.shares, pm.reach, pm.state AS metrics_state, pm.note AS metrics_note, pm.permalink AS metrics_permalink, pm.fetched_at AS metrics_at
                   FROM social_post_targets t
                   LEFT JOIN platform_connections c ON c.id = t.platform_connection_id
                   LEFT JOIN social_post_metrics pm ON pm.target_id = t.id
                  WHERE t.social_post_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY t.id',
                $ids
            );
            foreach ($rows as $r) {
                $r['permalink'] = !empty($r['metrics_permalink']) ? $r['metrics_permalink'] : self::permalink((string) $r['platform'], (string) ($r['external_post_id'] ?? ''));
                unset($r['external_post_id'], $r['metrics_permalink']);
                $byPost[(int) $r['social_post_id']][] = $r;
            }
        }
        foreach ($posts as &$p) {
            $p['targets'] = $byPost[(int) $p['id']] ?? [];
            if ($p['targets'] && $p['status'] === 'scheduled') {
                // منشورات قديمة: الحالة ماكانتش بتتحدث - نحسبها من الأهداف
                $p['status'] = SocialPostService::refreshPostStatus((int) $p['id']);
            }
        }
        unset($p);
        return $this->success(['posts' => $posts]);
    }

    /**
     * GET /api/social/stats?days=30
     * أرقام الفترة: عدد المنشورات حسب الحالة والمنصة، النشر اليومي، إجمالي التفاعل (من الكاش)،
     * أفضل المنشورات، وعينات أوقات النشر (الواجهة بتحسب منها أفضل ميعاد بتوقيت العميل).
     */
    public function stats(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $uid = (int) $this->user['id'];
        $days = max(7, min(90, (int) $this->get('days', 30)));

        try {
            $byStatus = ['draft' => 0, 'scheduled' => 0, 'publishing' => 0, 'published' => 0, 'failed' => 0];
            foreach ($this->db->query('SELECT status, COUNT(*) AS c FROM social_posts WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY status', [$uid, $days]) as $r) {
                $byStatus[(string) $r['status']] = (int) $r['c'];
            }

            $byPlatform = [];
            foreach ($this->db->query(
                "SELECT c.platform, t.status, COUNT(*) AS c
                   FROM social_post_targets t JOIN social_posts sp ON sp.id = t.social_post_id
                   LEFT JOIN platform_connections c ON c.id = t.platform_connection_id
                  WHERE sp.user_id = ? AND sp.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY c.platform, t.status",
                [$uid, $days]
            ) as $r) {
                $k = (string) ($r['platform'] ?: 'other');
                $byPlatform[$k] = $byPlatform[$k] ?? ['published' => 0, 'failed' => 0, 'scheduled' => 0, 'total' => 0];
                $bucket = in_array($r['status'], ['published', 'failed'], true) ? $r['status'] : 'scheduled';
                $byPlatform[$k][$bucket] += (int) $r['c'];
                $byPlatform[$k]['total'] += (int) $r['c'];
            }

            $daily = [];
            foreach ($this->db->query(
                "SELECT DATE(t.published_at) AS d, COUNT(*) AS c
                   FROM social_post_targets t JOIN social_posts sp ON sp.id = t.social_post_id
                  WHERE sp.user_id = ? AND t.status = 'published' AND t.published_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  GROUP BY DATE(t.published_at)",
                [$uid, $days]
            ) as $r) {
                $daily[(string) $r['d']] = (int) $r['c'];
            }
            $series = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-{$i} day"));
                $series[] = ['date' => $d, 'count' => $daily[$d] ?? 0];
            }

            $eng = $this->db->query(
                "SELECT COALESCE(SUM(pm.likes),0) AS likes, COALESCE(SUM(pm.comments),0) AS comments, COALESCE(SUM(pm.shares),0) AS shares,
                        COALESCE(SUM(pm.reach),0) AS reach, COUNT(pm.target_id) AS measured, MAX(pm.fetched_at) AS last_fetch
                   FROM social_post_metrics pm
                   JOIN social_post_targets t ON t.id = pm.target_id JOIN social_posts sp ON sp.id = t.social_post_id
                  WHERE sp.user_id = ? AND pm.state = 'ok' AND t.published_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$uid, $days]
            )[0] ?? [];

            $top = $this->db->query(
                "SELECT sp.id, sp.content, c.platform, c.external_location_name AS name, pm.likes, pm.comments, pm.shares, pm.reach,
                        (pm.likes + pm.comments * 2 + pm.shares * 3) AS score
                   FROM social_post_metrics pm
                   JOIN social_post_targets t ON t.id = pm.target_id JOIN social_posts sp ON sp.id = t.social_post_id
                   LEFT JOIN platform_connections c ON c.id = t.platform_connection_id
                  WHERE sp.user_id = ? AND pm.state = 'ok' AND t.published_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  ORDER BY score DESC, sp.id DESC LIMIT 5",
                [$uid, $days]
            );
            foreach ($top as &$t0) {
                $t0['content'] = mb_substr((string) $t0['content'], 0, 90);
            }
            unset($t0);

            // عينات للأوقات (UTC) + وزن التفاعل لو متاح - آخر 300 نشر
            $samples = $this->db->query(
                "SELECT t.published_at AS at, COALESCE(pm.likes + pm.comments * 2 + pm.shares * 3, 0) AS score
                   FROM social_post_targets t JOIN social_posts sp ON sp.id = t.social_post_id
                   LEFT JOIN social_post_metrics pm ON pm.target_id = t.id AND pm.state = 'ok'
                  WHERE sp.user_id = ? AND t.status = 'published' AND t.published_at IS NOT NULL
                  ORDER BY t.published_at DESC LIMIT 300",
                [$uid]
            );

            $published = (int) ($byStatus['published'] ?? 0);
            $failed = (int) ($byStatus['failed'] ?? 0);
            $tPub = 0;
            $tFail = 0;
            foreach ($byPlatform as $v) {
                $tPub += $v['published'];
                $tFail += $v['failed'];
            }
            return $this->success([
                'days' => $days,
                'by_status' => $byStatus,
                'total_posts' => array_sum($byStatus),
                'success_rate' => ($tPub + $tFail) > 0 ? round($tPub / ($tPub + $tFail) * 100) : null,
                'by_platform' => $byPlatform,
                'daily' => $series,
                'engagement' => [
                    'likes' => (int) ($eng['likes'] ?? 0),
                    'comments' => (int) ($eng['comments'] ?? 0),
                    'shares' => (int) ($eng['shares'] ?? 0),
                    'reach' => (int) ($eng['reach'] ?? 0),
                    'measured' => (int) ($eng['measured'] ?? 0),
                    'last_fetch' => $eng['last_fetch'] ?? null,
                ],
                'top_posts' => $top,
                'samples' => $samples,
            ]);
        } catch (Throwable $e) {
            Logger::error('Social stats error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return $this->error('تعذر تحميل الإحصائيات. تأكد إن آخر migration اتشغّلت (social_post_metrics).', 500);
        }
    }

    /**
     * POST /api/social/metrics/refresh - يسحب إحصائيات المنشورات المنشورة على فيسبوك/إنستجرام من Meta ويخزّنها.
     * بحد أقصى 15 هدف في المرة، ومبيعيدش سحب اللي اتحدّث من أقل من 30 دقيقة (إلا لو force=1).
     */
    public function refreshMetrics(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($rateError = $this->rateLimitGuard('user', 'social_metrics', 6, 60)) {
            return $rateError;
        }
        $uid = (int) $this->user['id'];
        $force = (bool) $this->get('force', false);
        @set_time_limit(90);

        try {
            $rows = $this->db->query(
                "SELECT t.id, t.external_post_id, c.platform, c.access_token
                   FROM social_post_targets t
                   JOIN social_posts sp ON sp.id = t.social_post_id
                   JOIN platform_connections c ON c.id = t.platform_connection_id
                   LEFT JOIN social_post_metrics pm ON pm.target_id = t.id
                  WHERE sp.user_id = ? AND t.status = 'published' AND t.external_post_id IS NOT NULL AND t.external_post_id <> ''
                    AND c.platform IN ('facebook','instagram') AND c.status = 'connected'
                    " . ($force ? '' : "AND (pm.fetched_at IS NULL OR pm.fetched_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))") . "
                  ORDER BY t.published_at DESC LIMIT 15",
                [$uid]
            );
            $encryption = new Encryption();
            $ok = 0;
            $unavailable = 0;
            foreach ($rows as $r) {
                $res = ['success' => false, 'error' => 'تعذر فك التوكن'];
                try {
                    $token = $encryption->decrypt((string) $r['access_token']);
                    $res = (new MetaSocialAPI($token))->getPostMetrics((string) $r['platform'], (string) $r['external_post_id'], $token);
                } catch (Throwable $e) {
                    $res['error'] = CustomerError::message($e->getMessage(), 500);
                }
                if ($res['success']) {
                    $ok++;
                    $this->db->query(
                        "INSERT INTO social_post_metrics (target_id, likes, comments, shares, reach, permalink, state, note, fetched_at)
                         VALUES (?, ?, ?, ?, ?, ?, 'ok', NULL, NOW())
                         ON DUPLICATE KEY UPDATE likes = VALUES(likes), comments = VALUES(comments), shares = VALUES(shares),
                             reach = VALUES(reach), permalink = VALUES(permalink), state = 'ok', note = NULL, fetched_at = NOW()",
                        [(int) $r['id'], $res['likes'], $res['comments'], $res['shares'], $res['reach'], $res['permalink']]
                    );
                } else {
                    $unavailable++;
                    $this->db->query(
                        "INSERT INTO social_post_metrics (target_id, state, note, fetched_at) VALUES (?, 'unavailable', ?, NOW())
                         ON DUPLICATE KEY UPDATE state = 'unavailable', note = VALUES(note), fetched_at = NOW()",
                        [(int) $r['id'], mb_substr((string) ($res['error'] ?? ''), 0, 290)]
                    );
                }
            }
            return $this->success(['updated' => $ok, 'unavailable' => $unavailable, 'checked' => count($rows)]);
        } catch (Throwable $e) {
            Logger::error('Social metrics refresh error', ['message' => $e->getMessage(), 'line' => $e->getLine()]);
            return $this->error('تعذر تحديث الإحصائيات. تأكد إن migration الإحصائيات اتشغّلت.', 500);
        }
    }

    /** POST /api/social/posts/{id}/duplicate - نسخة مسودة من منشور (نفس النص والوسائط) */
    public function duplicatePost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $uid = (int) $this->user['id'];
        $postId = (int) ($params['id'] ?? 0);
        $rows = $this->db->query('SELECT content, media_item_id, website_id, hashtags FROM social_posts WHERE id = ? AND user_id = ? LIMIT 1', [$postId, $uid]);
        if (!$rows) {
            return $this->error('المنشور مش موجود', 404);
        }
        $src = $rows[0];
        $hashtags = $src['hashtags'] ? json_decode((string) $src['hashtags'], true) : null;
        try {
            $post = $this->service->createPost($uid, [
                'content' => $src['content'],
                'website_id' => $src['website_id'],
                'media_item_id' => $src['media_item_id'] ?: null,
                'hashtags' => is_array($hashtags) ? $hashtags : null,
            ], []);
            return $this->success(['post' => $post->toArray()], 'اتعمل نسخة كمسودة', 201);
        } catch (Throwable $e) {
            Logger::error('duplicatePost error', ['message' => $e->getMessage()]);
            return $this->error('تعذر عمل نسخة من المنشور', 500);
        }
    }

    /**
     * POST /api/social/posts/{id}/update - تعديل نص منشور لسه ما اتنشرش (مسودة/مجدول)، وتغيير موعد الأهداف المجدولة.
     * body: {content?, scheduled_at?}  (scheduled_at بـ UTC ISO، أو "" لعدم التغيير)
     */
    public function updatePost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $uid = (int) $this->user['id'];
        $postId = (int) ($params['id'] ?? 0);
        $rows = $this->db->query('SELECT id, status FROM social_posts WHERE id = ? AND user_id = ? LIMIT 1', [$postId, $uid]);
        if (!$rows) {
            return $this->error('المنشور مش موجود', 404);
        }
        if (!in_array($rows[0]['status'], ['draft', 'scheduled', 'failed'], true)) {
            return $this->error('مينفعش تعدّل منشور اتنشر أو بيتنشر دلوقتي', 422);
        }
        $content = trim((string) $this->get('content', ''));
        if ($content === '') {
            return $this->error('Post text is required', 422);
        }
        $at = null;
        $atRaw = trim((string) $this->get('scheduled_at', ''));
        if ($atRaw !== '') {
            try{$ts=InternationalSettingsService::scheduledTimestamp((int)$this->user['id'],$atRaw);}catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}
            if ($ts === false || $ts < time() - 60 || $ts > time() + 366 * 86400) {
                return $this->error('موعد النشر لازم يكون في المستقبل (وخلال سنة)', 422);
            }
            $at = gmdate('Y-m-d H:i:s', $ts);
        }
        try {
            $this->db->query('UPDATE social_posts SET content = ? WHERE id = ?', [$content, $postId]);
            if ($at !== null) {
                $targets = $this->db->query("SELECT id FROM social_post_targets WHERE social_post_id = ? AND status = 'scheduled'", [$postId]);
                $queue = Container::getInstance()->make(QueueManager::class);
                foreach ($targets as $t) {
                    $this->db->query('UPDATE social_post_targets SET scheduled_at = ? WHERE id = ?', [$at, (int) $t['id']]);
                    // نمسح مهمة النشر القديمة (المعلّقة) الأول، وإلا هتنشر بالموعد القديم ويتنشر المنشور مرتين
                    $this->db->query(
                        "DELETE FROM jobs WHERE job_class = 'PublishSocialPostJob' AND status = 'pending' AND payload LIKE ?",
                        ['%"social_post_target_id":' . (int) $t['id'] . '}%']
                    );
                    $queue->push(PublishSocialPostJob::class, ['social_post_target_id' => (int) $t['id']], 'social', max(0, strtotime($at . ' UTC') - time()));
                }
            }
            return $this->success([], 'اتحفظ التعديل');
        } catch (Throwable $e) {
            Logger::error('updatePost error', ['message' => $e->getMessage()]);
            return $this->error('Could not Save edit', 500);
        }
    }

    /**
     * لو كرون الطابور مش شغال على السيرفر، المنشورات المجدولة كانت بتفضل "مجدول" للأبد.
     * لما العميل يفتح الصفحة بننفّذ اللي ميعاده عدّى (بحد أقصى 3 في المرة).
     */
    private function catchUpOverdue(int $uid): void
    {
        try {
            $due = $this->db->query(
                "SELECT t.id FROM social_post_targets t JOIN social_posts p ON p.id = t.social_post_id
                  WHERE p.user_id = ? AND t.status = 'scheduled' AND (t.scheduled_at IS NULL OR t.scheduled_at <= UTC_TIMESTAMP() - INTERVAL 2 MINUTE)
                    AND t.created_at <= NOW() - INTERVAL 2 MINUTE
                  ORDER BY t.id LIMIT 3",
                [$uid]
            );
            $jobs = [];
            foreach ($due as $t) {
                $j = $this->db->query(
                    "SELECT id FROM jobs WHERE job_class = 'PublishSocialPostJob' AND status = 'pending' AND payload LIKE ? ORDER BY id LIMIT 1",
                    ['%"social_post_target_id":' . (int) $t['id'] . '}%']
                );
                if ($j) {
                    $jobs[] = (int) $j[0]['id'];
                }
            }
            SocialPostService::runNow($jobs);
        } catch (Throwable $e) {
            Logger::warning('social catch-up skipped', ['error' => CustomerError::message($e->getMessage(), 500)]);
        }
    }

    private static function permalink(string $platform, string $id): ?string
    {
        if ($id === '') {
            return null;
        }
        if ($platform === 'facebook') {
            return 'https://www.facebook.com/' . rawurlencode($id);
        }
        return null;
    }

    /** POST /api/social/posts */
    public function createPost(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if (!$this->validate(['content' => 'required'])) {
            return $this->error('Missing data', 422, $this->getErrors());
        }

        $uid = (int) $this->user['id'];
        // 🔒 الصفحات لازم تكون بتاعة العميل نفسه، والموعد بيتحفظ UTC
        $targets = [];
        foreach ((array) $this->get('targets', []) as $t) {
            $cid = (int) ($t['platform_connection_id'] ?? 0);
            $ok = $cid > 0 && $this->db->query(
                "SELECT id FROM platform_connections WHERE id = ? AND (user_id = ? OR website_id IN (SELECT id FROM websites WHERE user_id = ?)) AND platform IN ('facebook','instagram','tiktok','youtube') AND status = 'connected' LIMIT 1",
                [$cid, $uid, $uid]
            );
            if (!$ok) {
                return $this->error('This publishing page doesn\'t exist or isn\'t connected', 422);
            }
            $at = null;
            if (!empty($t['scheduled_at'])) {
                try{$ts=InternationalSettingsService::scheduledTimestamp($uid,(string)$t['scheduled_at']);}catch(InvalidArgumentException $e){return $this->error($e->getMessage(),422);}
                if ($ts === false) {
                    return $this->error('Invalid publish time', 422);
                }
                if ($ts < time() - 60) {
                    return $this->error('Publish time must be in the future', 422);
                }
                if ($ts > time() + 366 * 86400) {
                    return $this->error('Publish time is too far ahead (max 1 year)', 422);
                }
                $at = gmdate('Y-m-d H:i:s', $ts);
            }
            $targets[] = ['platform_connection_id' => $cid, 'scheduled_at' => $at];
        }
        $rawMediaId = $this->get('media_item_id', 0);
        if ($rawMediaId !== null && (!is_scalar($rawMediaId) || !preg_match('/^[0-9]+$/', (string)$rawMediaId))) {
            return $this->error('Image/video isn\'t ready or doesn\'t exist', 422);
        }
        $mediaId = (int) $rawMediaId;
        if ($mediaId > 0 && !$this->db->query("SELECT id FROM media_items WHERE id = ? AND user_id = ? AND status = 'completed' AND deleted_at IS NULL LIMIT 1", [$mediaId, $uid])) {
            return $this->error('Image/video isn\'t ready or doesn\'t exist', 422);
        }

        try {
            $post = $this->service->createPost(
                $uid,
                // كان media_item_id بيتشال هنا، فاللي جاي من Creative Studio كان بيتنشر نص بس
                ['content' => $this->get('content'), 'website_id' => $this->get('website_id'), 'media_item_id' => $mediaId ?: null],
                $targets
            );
            // النشر الفوري بيتنفذ في نفس الطلب (مش مستني الكرون)
            SocialPostService::runNow($this->service->immediateJobIds);
            $postId = (int) $post->getAttribute('id');
            $out = $post->toArray();
            if ($targets) {
                $out['status'] = SocialPostService::refreshPostStatus($postId);
            }
            return $this->success(['post' => $out], 'Post saved', 201);
        } catch (Exception $e) {
            Logger::error('createPost Error', ['message' => $e->getMessage()]);
            return $this->error('Could not create the post', 500);
        }
    }

    /**
     * GET /api/social/calendar
     * تقويم محتوى حقيقي مبني على social_posts + social_post_targets
     * الموجودين بالفعل (عمود scheduled_at). ده بديل عن جدول
     * content_calendar المنفصل اللي كان في موديول content-studio -
     * مفيش داعي لتكراره لأن البيانات موجودة أصلًا هنا.
     */
    public function getCalendar(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $rows = $this->db->query(
                "SELECT sp.id, sp.content, COALESCE(spt.scheduled_at, spt.published_at) AS scheduled_at, spt.status, spt.published_at, pc.platform
                 FROM social_posts sp
                 JOIN social_post_targets spt ON spt.social_post_id = sp.id
                 LEFT JOIN platform_connections pc ON pc.id = spt.platform_connection_id
                 WHERE sp.user_id = ? AND COALESCE(spt.scheduled_at, spt.published_at) IS NOT NULL
                 AND COALESCE(spt.scheduled_at, spt.published_at) BETWEEN DATE_SUB(NOW(), INTERVAL 14 DAY) AND DATE_ADD(NOW(), INTERVAL 35 DAY)
                 ORDER BY COALESCE(spt.scheduled_at, spt.published_at) ASC",
                [$this->user['id']]
            );
            return $this->success(['items' => $rows]);
        } catch (Exception $e) {
            Logger::error('Social Calendar Error', ['message' => $e->getMessage()]);
            return $this->error('Could not load the calendar', 500);
        }
    }

    /** POST /api/social/generate-caption */
    public function generateCaption(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        if ($rateError = $this->rateLimitGuard('user', 'ai', 20, 60)) {
            return $rateError;
        }
        if (!$this->validate(['topic' => 'required'])) {
            return $this->error('Topic is required', 422);
        }

        $result = $this->service->generateCaption(
            (string) $this->get('topic'),
            (string) $this->get('platform', 'instagram'),
            ContentLanguageService::forUser((int)$this->user['id'], (int)$this->get('website_id',0) ?: null)
        );

        if (!$result['success']) {
            return $this->error($result['error'], 502);
        }

        return $this->success($result);
    }

    /** GET /social/connect/tiktok */
    public function connectTikTok(array $params = []): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/social'));
            exit;
        }

        $clientKey = env('TIKTOK_CLIENT_KEY');
        $clientSecret = env('TIKTOK_CLIENT_SECRET');
        if (!$clientKey || !$clientSecret) {
            $this->renderOAuthError('TikTok connection is not enabled yet (TIKTOK_CLIENT_KEY/SECRET details are missing).');
            exit;
        }

        $nonce = bin2hex(random_bytes(16));
        $_SESSION['tiktok_oauth_nonce'] = $nonce;

        $redirectUri = env('TIKTOK_OAUTH_REDIRECT_URI') ?: (rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/social/connect/tiktok/callback');
        $state = OAuthState::issue(['aud' => 'tiktok', 'nonce' => $nonce], (int) ($this->user['id'] ?? 0));

        $url = 'https://www.tiktok.com/v2/auth/authorize/?'
            . http_build_query([
                'client_key'    => $clientKey,
                'response_type' => 'code',
                'scope'         => 'video.upload',
                'redirect_uri'  => $redirectUri,
                'state'         => $state,
            ]);

        header('Location: ' . $url);
        exit;
    }

    /** GET /social/connect/tiktok/callback */
    public function tikTokOAuthCallback(array $params = []): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $error = $this->get('error');
        if ($error) {
            $this->renderOAuthError('The customer declined consent or an error came from TikTok: ' . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $code = $this->get('code');
        $state = $this->get('state');
        if (!$code || !$state) {
            $this->renderOAuthError('Incomplete response from TikTok');
            exit;
        }

        $decodedState = OAuthState::verify((string) $state, (int) ($this->user['id'] ?? 0), $_SESSION['tiktok_oauth_nonce'] ?? null, 'tiktok');
        // تصحيح: التحقق بقى من state موقّع مربوط بالمستخدم - مش معتمد على جلسة PHP بس
        if (!$decodedState) {
            $this->renderOAuthError('The session expired or the attempt is not trusted');
            exit;
        }

        $clientKey    = env('TIKTOK_CLIENT_KEY');
        $clientSecret = env('TIKTOK_CLIENT_SECRET');
        $redirectUri  = env('TIKTOK_OAUTH_REDIRECT_URI') ?: (rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/social/connect/tiktok/callback');

        $ch = curl_init('https://open-api.tiktok.com/oauth/access_token/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'client_key'    => $clientKey,
                'client_secret' => $clientSecret,
                'code'          => $code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $redirectUri,
            ]),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);

        $decoded = json_decode((string) $resp, true);
        if (($decoded['error_code'] ?? 0) !== 0 || empty($decoded['data']['access_token'])) {
            $this->renderOAuthError('Token exchange failed with TikTok: ' . htmlspecialchars($decoded['description'] ?? 'unknown error', ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $data = $decoded['data'];
        $accessToken  = $data['access_token'];
        $refreshToken = $data['refresh_token'] ?? '';
        $openId       = $data['open_id'];
        $expiresIn    = $data['expires_in'] ?? 86400;

        $encryption = new Encryption();

        $existing = (new PlatformConnection())->where([
            'user_id'  => (int) $this->user['id'],
            'platform' => 'tiktok',
        ], [], 1);

        $connData = [
            'user_id'               => (int) $this->user['id'],
            'platform'              => 'tiktok',
            'status'                => 'connected',
            'access_token'          => $encryption->encrypt($accessToken),
            'refresh_token'         => $refreshToken ? $encryption->encrypt($refreshToken) : null,
            'external_location_id'  => $openId,
            'external_location_name' => 'TikTok Account',
            'token_expires_at'      => date('Y-m-d H:i:s', time() + (int) $expiresIn),
            'last_error'            => null,
        ];

        if (!empty($existing)) {
            foreach ($connData as $key => $value) {
                $existing[0]->setAttribute($key, $value);
            }
            $existing[0]->save();
        } else {
            (new PlatformConnection($connData))->save();
        }

        unset($_SESSION['tiktok_oauth_nonce']);
        header('Location: /social?connected=tiktok');
        exit;
    }

    /** GET /social/connect/youtube */
    public function connectYouTube(array $params = []): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login?redirect=' . urlencode('/social'));
            exit;
        }

        $oauth = new GoogleOAuthClient(
            'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube',
            env('YOUTUBE_OAUTH_REDIRECT_URI') ?: null
        );
        if (!$oauth->isConfigured()) {
            $this->renderOAuthError('YouTube connection is not enabled yet (GOOGLE_CLIENT_ID/SECRET or YOUTUBE_OAUTH_REDIRECT_URI is missing).');
            exit;
        }

        $nonce = bin2hex(random_bytes(16));
        $_SESSION['youtube_oauth_nonce'] = $nonce;

        $state = OAuthState::issue(['aud' => 'youtube', 'nonce' => $nonce], (int) ($this->user['id'] ?? 0));
        header('Location: ' . $oauth->buildAuthUrl($state));
        exit;
    }

    /** GET /social/connect/youtube/callback */
    public function youTubeOAuthCallback(array $params = []): void
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }

        $error = $this->get('error');
        if ($error) {
            $this->renderOAuthError('The customer declined consent or an error came from Google: ' . htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'));
            exit;
        }

        $code = $this->get('code');
        $state = $this->get('state');
        if (!$code || !$state) {
            $this->renderOAuthError('Incomplete response from Google');
            exit;
        }

        $decodedState = OAuthState::verify((string) $state, (int) ($this->user['id'] ?? 0), $_SESSION['youtube_oauth_nonce'] ?? null, 'youtube');
        // تصحيح: التحقق بقى من state موقّع مربوط بالمستخدم - مش معتمد على جلسة PHP بس
        if (!$decodedState) {
            $this->renderOAuthError('The session expired or the attempt is not trusted');
            exit;
        }

        $oauth = new GoogleOAuthClient(
            'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube',
            env('YOUTUBE_OAUTH_REDIRECT_URI') ?: null
        );
        $tokenResult = $oauth->exchangeCodeForTokens((string) $code);

        if (!$tokenResult['success']) {
            $this->renderOAuthError('Token exchange failed with Google: ' . htmlspecialchars($tokenResult['error'] ?? '', ENT_QUOTES, 'UTF-8'));
            exit;
        }
        if (empty($tokenResult['refresh_token'])) {
            $this->renderOAuthError('Google did not return a refresh_token. Disconnect any previous connection from your Google settings and try again.');
            exit;
        }

        $accessToken  = $tokenResult['access_token'];
        $refreshToken = $tokenResult['refresh_token'];

        $ch = curl_init('https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $channelResp = curl_exec($ch);
        curl_close($ch);
        $channelData = json_decode((string) $channelResp, true);
        $channelId   = $channelData['items'][0]['id'] ?? null;
        $channelTitle = $channelData['items'][0]['snippet']['title'] ?? 'YouTube Channel';

        if (!$channelId) {
            $this->renderOAuthError('We could not get the channel ID of YouTube.');
            exit;
        }

        $encryption = new Encryption();

        $existing = (new PlatformConnection())->where([
            'user_id'  => (int) $this->user['id'],
            'platform' => 'youtube',
        ], [], 1);

        $connData = [
            'user_id'                => (int) $this->user['id'],
            'platform'               => 'youtube',
            'status'                 => 'connected',
            'access_token'           => $encryption->encrypt($accessToken),
            'refresh_token'          => $encryption->encrypt($refreshToken),
            'external_location_id'   => $channelId,
            'external_location_name' => $channelTitle,
            'token_expires_at'       => date('Y-m-d H:i:s', time() + (int) ($tokenResult['expires_in'] ?? 3600)),
            'last_error'             => null,
        ];

        if (!empty($existing)) {
            foreach ($connData as $key => $value) {
                $existing[0]->setAttribute($key, $value);
            }
            $existing[0]->save();
        } else {
            (new PlatformConnection($connData))->save();
        }

        unset($_SESSION['youtube_oauth_nonce']);
        header('Location: /social?connected=youtube');
        exit;
    }

    private function renderOAuthError(string $message): void
    {
        $message = CustomerError::oauthMessage($message);
        echo '<div class="p-card" style="max-width:600px;margin:40px auto;text-align:center;">'
            . '<h2>⚠️ Error connecting the account</h2>'
            . '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<a href="/social" class="p-btn primary">Back to social media management</a>'
            . '</div>';
        exit;
    }
}
