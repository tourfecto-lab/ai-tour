/**
 * Tourfecto - كارت ربط واتساب الرسمي (Cloud API) في إعدادات الشات - عربي + إنجليزي
 * - "اربط بضغطة" (Embedded Signup من Meta) لو الأدمن ظبط Config ID
 * - ربط يدوي (Phone Number ID + WABA ID + توكن دائم) لأي حد عنده تطبيق Meta بتاعه
 */
(function () {
    'use strict';
    var P = window.Panel || {};
    var AR = ((document.documentElement.lang || 'ar').slice(0, 2) === 'ar');
    var L = function (ar, en) { return AR ? ar : en; };
    var esc = P.esc || function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var toast = P.toast || function (m) { alert(m); };
    var fetchJSON = P.fetchJSON || function (u, o) { return fetch(u, o).then(function (r) { return r.json(); }); };
    var card, site = '', embedCfg = null, esData = {};

    var ST = {
        APPROVED: [L('اتوافق عليه', 'Approved'), '#16a34a'], PENDING: [L('مستني موافقة Meta', 'Pending review'), '#d97706'], IN_APPEAL: [L('في الاستئناف', 'In appeal'), '#d97706'],
        REJECTED: [L('اترفض', 'Rejected'), '#dc2626'], PAUSED: [L('متوقف', 'Paused'), '#dc2626'], DISABLED: [L('متوقف', 'Disabled'), '#dc2626']
    };
    var TPL_EN = { tf_trip_reminder: 'Trip reminder', tf_review_request: 'Post-trip review request', tf_follow_up: 'Follow-up for guests who didn’t book' };
    function badge(s) {
        if (!s) return '<span style="color:var(--panel-text-muted,#888)">' + L('مش موجود', 'Not created') + '</span>';
        var x = ST[s] || [s, '#64748b'];
        return '<span style="display:inline-block;padding:2px 9px;border-radius:99px;font-size:12px;font-weight:600;background:' + x[1] + '1a;color:' + x[1] + '">' + esc(x[0]) + '</span>';
    }
    function copyBtn(id) { return '<button type="button" class="p-btn outline xs" data-copy="' + id + '">' + L('نسخ', 'Copy') + '</button>'; }
    function code(id, v) { return '<code id="' + id + '" style="display:block;background:#1e1e2e;color:#a6e3a1;padding:9px 11px;border-radius:8px;overflow-x:auto;direction:ltr;text-align:left;font-size:12px;margin:4px 0 8px;word-break:break-all">' + esc(v) + '</code>'; }
    function head(sub) { return '<div class="p-card-head"><h3>✅ ' + L('واتساب الرسمي', 'Official WhatsApp') + ' (WhatsApp Business API)</h3><span class="p-card-sub">' + sub + '</span></div>'; }

    function guide() {
        return '<details style="margin:8px 0 14px"><summary style="cursor:pointer;font-weight:600">' + L('إزاي أجيب البيانات دي؟ (مرة واحدة - حوالي 15 دقيقة)', 'Where do I get these? (one time - about 15 minutes)') + '</summary>'
            + '<ol style="line-height:2;margin:10px 0 0;padding-inline-start:20px;font-size:13.5px">'
            + '<li>' + L('ادخل <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">developers.facebook.com/apps</a> واعمل تطبيق نوع <b>Business</b>، وضيفله منتج <b>WhatsApp</b>.', 'Go to <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">developers.facebook.com/apps</a>, create a <b>Business</b> app and add the <b>WhatsApp</b> product.') + '</li>'
            + '<li>' + L('من <b>WhatsApp ← API Setup</b>: ضيف رقمك ووثّقه، وانسخ <b>Phone Number ID</b> و <b>WhatsApp Business Account ID</b>.', 'In <b>WhatsApp → API Setup</b>: add and verify your number, then copy the <b>Phone Number ID</b> and <b>WhatsApp Business Account ID</b>.') + '</li>'
            + '<li>' + L('من <a href="https://business.facebook.com/settings/system-users" target="_blank" rel="noopener">Business Settings ← System Users</a>: اعمل System User (Admin)، اديله التطبيق وحساب الواتساب، واعمل <b>توكن دائم</b> بالصلاحيات:', 'In <a href="https://business.facebook.com/settings/system-users" target="_blank" rel="noopener">Business Settings → System Users</a>: create an Admin system user, assign it the app and WhatsApp account, and generate a <b>permanent token</b> with:') + ' <code>whatsapp_business_messaging</code> ' + L('و', 'and') + ' <code>whatsapp_business_management</code>.</li>'
            + '<li>' + L('ضيف وسيلة دفع في حساب WhatsApp Business (Meta بتحاسب على الرسايل اللي انت بتبدأها).', 'Add a payment method to the WhatsApp Business account (Meta charges for messages you start).') + '</li>'
            + '<li>' + L('بعد الربط هنا: حط <b>Callback URL</b> و <b>Verify token</b> اللي هتظهرلك في التطبيق ← WhatsApp ← Configuration، واشترك في <b>messages</b>.', 'After connecting here: paste the <b>Callback URL</b> and <b>Verify token</b> shown below into your app → WhatsApp → Configuration, and subscribe to <b>messages</b>.') + '</li>'
            + '</ol></details>';
    }

    function manualForm(d) {
        return guide()
            + '<div class="form-group"><label class="form-label">Phone Number ID</label><input id="waPn" class="form-control" dir="ltr" inputmode="numeric" placeholder="1234567890123456"></div>'
            + '<div class="form-group"><label class="form-label">WhatsApp Business Account ID</label><input id="waWaba" class="form-control" dir="ltr" inputmode="numeric" placeholder="1029384756"></div>'
            + '<div class="form-group"><label class="form-label">' + L('التوكن الدائم (System User)', 'Permanent token (System User)') + '</label><input id="waTok" class="form-control" dir="ltr" type="password" autocomplete="off" placeholder="EAAG…"></div>'
            + '<div class="form-group"><label class="form-label">App Secret <span class="p-cell-muted">' + L('(مطلوب لحماية الرسائل الواردة)', '(required to verify incoming messages)') + '</span></label><input id="waSec" class="form-control" dir="ltr" type="password" autocomplete="off" required minlength="32" maxlength="32"></div>'
            + '<div id="waAlert" class="alert alert-danger" style="display:none;margin-top:10px"></div>'
            + '<button class="p-btn ' + (embedCfg ? 'outline' : 'primary') + '" id="waConnect" style="margin-top:10px">' + L('ربط الرقم', 'Connect number') + '</button>';
    }

    function renderDisconnected(d) {
        var html = head(L('رقم شركتك الرسمي من Meta - مافيش خطر حظر، والرسايل باسم شركتك', 'Your company’s official number from Meta - no ban risk, messages in your name'));
        if (embedCfg) {
            html += '<p style="margin:6px 0 10px;font-size:14px">' + L('أسهل طريقة: سجّل دخول بفيسبوك واختار رقمك (أو اعمل رقم جديد) - وكل حاجة بتتظبط لوحدها.', 'Easiest: log in with Facebook and pick (or create) your number - everything else is set up for you.') + '</p>'
                + '<button class="p-btn primary" id="waEmbed" style="display:inline-flex;gap:8px;align-items:center"><span style="font-weight:700">f</span> ' + L('اربط واتساب بضغطة', 'Connect WhatsApp in one click') + '</button>'
                + '<div id="waEmbedAlert" class="alert alert-danger" style="display:none;margin-top:10px"></div>'
                + '<details style="margin-top:16px"><summary style="cursor:pointer;color:var(--panel-text-muted)">' + L('عندي تطبيق Meta بتاعي - ربط يدوي', 'I have my own Meta app - connect manually') + '</summary><div style="margin-top:10px">' + manualForm(d) + '</div></details>';
        } else {
            html += manualForm(d);
        }
        if (d.ultramsg_connected) html += '<p class="p-cell-muted" style="margin-top:10px;font-size:12.5px">' + L('UltraMsg مربوط حاليًا - بعد ربط الرسمي هيبقى هو الأساسي، وUltraMsg احتياطي لو الرسمي فشل.', 'UltraMsg is connected - once official WhatsApp is linked it becomes primary, with UltraMsg as a fallback.') + '</p>';
        card.innerHTML = html;
    }

    function renderConnected(d) {
        var rows = (d.templates || []).map(function (t) {
            var rej = t.rejected ? Object.keys(t.rejected).map(function (l) { return l + ': ' + t.rejected[l]; }).join(' · ') : '';
            return '<tr><td><b>' + esc(AR ? t.label : (TPL_EN[t.name] || t.label)) + '</b><br><span class="p-cell-muted" dir="ltr">' + esc(t.name) + ' · ' + esc(t.category) + '</span>' + (rej ? '<br><span style="color:#dc2626;font-size:12px">' + esc(rej) + '</span>' : '') + '</td><td>' + badge(t.ar) + '</td><td>' + badge(t.en) + '</td></tr>';
        }).join('');
        var anyMissing = (d.templates || []).some(function (t) { return !t.ar || !t.en || t.ar === 'REJECTED' || t.en === 'REJECTED'; });
        var allOk = (d.templates || []).every(function (t) { return t.ar === 'APPROVED' || t.en === 'APPROVED'; });
        var webhook = d.embedded
            ? '<p class="p-cell-muted" style="font-size:13px;margin:0">' + L('الرسايل الواردة متظبطة تلقائيًا (اتربطت من خلال فيسبوك).', 'Incoming messages are set up automatically (connected through Facebook).') + (d.last_webhook_at ? ' <span style="color:#16a34a">✓ ' + L('آخر رسالة وصلت', 'Last message received') + ': ' + esc(d.last_webhook_at) + ' UTC</span>' : '') + '</p>'
                + (d.register_error ? '<div class="alert alert-warning" style="margin-top:8px">' + L('تسجيل الرقم على Meta: ', 'Number registration: ') + esc(d.register_error) + '</div>' : '')
            : '<p class="p-cell-muted" style="font-size:13px;margin:0">' + L('في تطبيقك على Meta ← WhatsApp ← Configuration ← Webhook: حط القيم دي، واشترك في <b>messages</b>.', 'In your Meta app → WhatsApp → Configuration → Webhook: paste these values and subscribe to <b>messages</b>.') + (d.last_webhook_at ? ' <span style="color:#16a34a">✓ ' + L('آخر رسالة وصلت', 'Last message received') + ': ' + esc(d.last_webhook_at) + ' UTC</span>' : '') + '</p>'
                + '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px"><span class="p-cell-muted" style="font-size:12.5px">Callback URL</span>' + copyBtn('waUrl') + '</div>' + code('waUrl', d.webhook_url)
                + '<div style="display:flex;justify-content:space-between;align-items:center"><span class="p-cell-muted" style="font-size:12.5px">Verify token</span>' + copyBtn('waVt') + '</div>' + code('waVt', d.verify_token);
        card.innerHTML = head(L('رقم شركتك الرسمي - الرسايل بتطلع باسمك', 'Your official company number - messages go out in your name'))
            + '<div class="alert alert-success">✔ ' + L('مربوط', 'Connected') + ': <b dir="ltr">' + esc(d.display_phone || d.phone_number_id) + '</b> ' + (d.verified_name ? '· ' + esc(d.verified_name) : '') + (d.quality ? ' · ' + L('الجودة', 'Quality') + ': ' + esc(d.quality) : '') + '</div>'
            + (!d.webhook_secure ? '<div class="alert alert-danger">' + L('استقبال الرسائل متوقف لحد إضافة App Secret: افصل الربط وأعد ربطه بالسر الصحيح.', 'Incoming messages are blocked until App Secret is configured. Reconnect with the correct secret.') + '</div>' : '')
            + '<div id="waDeliveryStatuses" class="p-cell-muted"></div>'
            + '<p style="margin:12px 0 2px;font-weight:600">1) ' + L('الرسايل الواردة', 'Incoming messages') + '</p>' + webhook
            + '<p style="margin:16px 0 6px;font-weight:600">2) ' + L('القوالب', 'Templates') + ' <span class="p-cell-muted" style="font-weight:400;font-size:12.5px">- ' + L('Meta بتشترط قالب متوافق عليه لأي رسالة انت بتبدأها (تذكير، طلب تقييم، متابعة)', 'Meta requires an approved template for any message you start (reminders, review requests, follow-ups)') + '</span></p>'
            + '<div style="overflow-x:auto"><table class="p-table" style="width:100%"><thead><tr><th>' + L('القالب', 'Template') + '</th><th>' + L('عربي', 'Arabic') + '</th><th>English</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
            + '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">' + (anyMissing ? '<button class="p-btn primary sm" id="waCreate">' + L('اعمل القوالب في حسابي', 'Create templates in my account') + '</button>' : '') + '<button class="p-btn outline sm" id="waSync">' + L('تحديث الحالة', 'Refresh status') + '</button></div>'
            + (allOk ? '' : '<p class="p-cell-muted" style="font-size:12.5px;margin-top:6px">' + L('لحد ما القوالب تتوافق، التذكير وطلب التقييم بيروحوا بالإيميل', 'Until templates are approved, reminders and review requests go by email') + (d.ultramsg_connected ? L(' أو UltraMsg', ' or UltraMsg') : '') + L('. الرد على الشات شغال من دلوقتي.', '. Chat replies work right away.') + '</p>')
            + '<p style="margin:16px 0 6px;font-weight:600">3) ' + L('جرّب', 'Test') + '</p>'
            + '<div style="display:flex;gap:8px;flex-wrap:wrap"><input id="waTestPhone" class="form-control" dir="ltr" style="max-width:220px" placeholder="2010xxxxxxxx"><button class="p-btn outline sm" id="waTest">' + L('ابعت رسالة تجربة', 'Send a test message') + '</button></div>'
            + '<div style="margin-top:16px;border-top:1px solid var(--panel-border,#2a3247);padding-top:12px"><button class="p-btn outline xs" id="waDisc">' + L('فصل الربط', 'Disconnect') + '</button></div>';
    }

    async function load() {
        site = (document.getElementById('websiteSelect') || {}).value || '';
        if (!card) return;
        if (!site) { card.style.display = 'none'; return; }
        card.style.display = 'block';
        card.innerHTML = '<div class="p-cell-muted">…</div>';
        if (embedCfg === null) {
            var c = await fetchJSON('/api/whatsapp-cloud/embedded-config');
            embedCfg = c.success && c.data.enabled ? c.data : false;
        }
        var r = await fetchJSON('/api/whatsapp-cloud/status?website_id=' + encodeURIComponent(site));
        if (!r.success) { card.innerHTML = '<div class="alert alert-danger">' + esc(r.error || L('تعذر التحميل', 'Could not load')) + '</div>'; return; }
        (r.data.connected ? renderConnected : renderDisconnected)(r.data);
        if (r.data.connected) {
            var ds = await fetchJSON('/api/whatsapp-cloud/delivery-statuses?website_id=' + encodeURIComponent(site));
            var el = document.getElementById('waDeliveryStatuses');
            if (el && ds.success) {
                var labels = {sent:L('تم الإرسال','Sent'), delivered:L('تم التسليم','Delivered'), read:L('تمت القراءة','Read'), failed:L('فشل الإرسال','Failed')};
                el.innerHTML = '<details><summary>' + L('آخر حالات تسليم الرسائل','Recent message delivery statuses') + '</summary>' + (ds.data.statuses || []).map(function(s){return '<p dir="ltr">' + esc(s.wamid) + ' · ' + esc(labels[s.status] || s.status) + (s.error_message ? ' · ' + esc(s.error_message) : '') + '</p>';}).join('') + '</details>';
            }
        }
    }

    async function post(url, body) {
        return fetchJSON(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) });
    }
    function busy(btn, on, label) { if (!btn) return; btn.disabled = on; if (on) { btn.dataset.l = btn.innerHTML; btn.textContent = label || '…'; } else if (btn.dataset.l) btn.innerHTML = btn.dataset.l; }

    // ------------------------------------------------------------ الربط بضغطة (Embedded Signup)
    function loadFb(cfg) {
        return new Promise(function (resolve, reject) {
            if (window.FB) { resolve(); return; }
            window.fbAsyncInit = function () { window.FB.init({ appId: cfg.app_id, autoLogAppEvents: true, xfbml: false, version: cfg.version }); resolve(); };
            var s = document.createElement('script');
            s.async = true; s.defer = true; s.crossOrigin = 'anonymous';
            s.src = 'https://connect.facebook.net/' + (AR ? 'ar_AR' : 'en_US') + '/sdk.js';
            s.onerror = function () { reject(new Error(L('تعذر تحميل فيسبوك - جرّب تاني أو اقفل مانع الإعلانات', 'Could not load Facebook - try again or disable your ad blocker'))); };
            document.body.appendChild(s);
        });
    }
    window.addEventListener('message', function (ev) {
        var host = '';
        try { host = new URL(ev.origin).hostname; } catch (x) { return; }
        if (!/(^|\.)facebook\.com$/.test(host)) return;
        try {
            var d = typeof ev.data === 'string' ? JSON.parse(ev.data) : ev.data;
            if (d && d.type === 'WA_EMBEDDED_SIGNUP') {
                if (d.event === 'FINISH' || d.event === 'FINISH_ONLY_WABA' || d.event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING') esData = d.data || {};
                else if (d.event === 'CANCEL') esData = { cancelled: true, step: (d.data || {}).current_step };
            }
        } catch (x) { /* رسايل تانية من فيسبوك */ }
    });
    async function embeddedSignup(btn) {
        var al = document.getElementById('waEmbedAlert'); al.style.display = 'none';
        busy(btn, true, L('بنفتح فيسبوك…', 'Opening Facebook…'));
        try { await loadFb(embedCfg); } catch (e) { busy(btn, false); al.textContent = e.message; al.style.display = 'block'; return; }
        esData = {};
        window.FB.login(function (resp) {
            (async function () {
                if (!resp || !resp.authResponse || !resp.authResponse.code) {
                    busy(btn, false);
                    if (esData.cancelled) { al.textContent = L('اتقفلت النافذة قبل ما الربط يخلص', 'The window was closed before setup finished') + (esData.step ? ' (' + esData.step + ')' : ''); al.style.display = 'block'; }
                    return;
                }
                busy(btn, true, L('بنكمّل الربط…', 'Finishing setup…'));
                // بيانات الرقم بتيجي في رسالة منفصلة من نافذة Meta - نستناها ثواني لو اتأخرت
                for (var i = 0; i < 20 && !esData.phone_number_id; i++) await new Promise(function (r) { setTimeout(r, 150); });
                var r = await post('/api/whatsapp-cloud/embedded', { website_id: site, code: resp.authResponse.code, waba_id: esData.waba_id || '', phone_number_id: esData.phone_number_id || '' });
                busy(btn, false);
                if (!r.success) { al.textContent = r.error || L('تعذر الربط', 'Could not connect'); al.style.display = 'block'; return; }
                toast(r.message || L('اتربط', 'Connected'), 'success');
                (r.data && r.data.warnings || []).forEach(function (w) { toast(w, 'error'); });
                load();
            })();
        }, { config_id: embedCfg.config_id, response_type: 'code', override_default_response_type: true, extras: { setup: {}, featureType: '', sessionInfoVersion: '3' } });
    }

    document.addEventListener('click', async function (e) {
        if (!card || !card.contains(e.target)) return;
        var t = e.target.closest('button') || e.target;
        if (t.dataset.copy) {
            var el = document.getElementById(t.dataset.copy);
            try { await navigator.clipboard.writeText(el.textContent); toast(L('اتنسخ', 'Copied'), 'success'); } catch (x) { }
            return;
        }
        if (t.id === 'waEmbed') {
            embeddedSignup(t);
        } else if (t.id === 'waConnect') {
            var al = document.getElementById('waAlert'); al.style.display = 'none';
            busy(t, true, L('بنتأكد من البيانات مع Meta…', 'Checking with Meta…'));
            var r = await post('/api/whatsapp-cloud/connect', { website_id: site, phone_number_id: document.getElementById('waPn').value, waba_id: document.getElementById('waWaba').value, access_token: document.getElementById('waTok').value, app_secret: document.getElementById('waSec').value });
            busy(t, false);
            if (!r.success) { al.textContent = r.error || L('تعذر الربط', 'Could not connect'); al.style.display = 'block'; return; }
            toast(r.message || L('اتربط', 'Connected'), 'success');
            if (r.data && r.data.subscribe_error) toast(L('اتربط، بس الاشتراك في الرسايل الواردة فشل: ', 'Connected, but subscribing to incoming messages failed: ') + r.data.subscribe_error, 'error');
            load();
        } else if (t.id === 'waCreate') {
            busy(t, true, L('بنبعت القوالب لـ Meta…', 'Submitting templates to Meta…'));
            var c = await post('/api/whatsapp-cloud/templates', { website_id: site });
            busy(t, false);
            toast(c.message || c.error, c.success ? 'success' : 'error');
            load();
        } else if (t.id === 'waSync') {
            busy(t, true);
            var s = await fetchJSON('/api/whatsapp-cloud/templates?website_id=' + encodeURIComponent(site));
            busy(t, false);
            if (!s.success) toast(s.error, 'error');
            load();
        } else if (t.id === 'waTest') {
            busy(t, true, L('بنبعت…', 'Sending…'));
            var x = await post('/api/whatsapp-cloud/test', { website_id: site, phone: document.getElementById('waTestPhone').value });
            busy(t, false);
            toast(x.message || x.error, x.success ? 'success' : 'error');
        } else if (t.id === 'waDisc') {
            if (!confirm(L('فصل واتساب الرسمي؟ التذكير والشات هيرجعوا لـ UltraMsg (لو مربوط) أو الإيميل.', 'Disconnect official WhatsApp? Reminders and chat will fall back to UltraMsg (if connected) or email.'))) return;
            var d = await post('/api/whatsapp-cloud/disconnect/' + encodeURIComponent(site));
            toast(d.message || d.error, d.success ? 'success' : 'error');
            load();
        }
    });

    function init() {
        card = document.getElementById('waCloudCard');
        var sel = document.getElementById('websiteSelect');
        if (!card || !sel) return;
        sel.addEventListener('change', load);
        var tries = 0, iv = setInterval(function () { tries++; if (sel.value || tries > 40) { clearInterval(iv); if (sel.value) load(); } }, 250);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
