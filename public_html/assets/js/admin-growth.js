/* Tourfecto - لوحة تسويق المنصة بالإيميل (أدمن) */
(() => {
    'use strict';
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const STATUS = {
        new: ['جديدة - في الدور', 'blue'], no_email: ['مالهاش إيميل', 'gray'], contacted: ['اتبعتلها', 'blue'], finished: ['خلصت الرسايل الـ3', 'gray'],
        replied: ['ردّت ✉️', 'green'], interested: ['مهتمة (ضغطت اللينك) 🔥', 'green'], signed_up: ['سجّلت في المنصة 🎉', 'green'],
        unsubscribed: ['ألغت الاشتراك', 'orange'], bounced: ['الإيميل راجع', 'red'], invalid: ['إيميل غلط', 'red'], paused: ['متوقفة', 'gray'], queued: ['في الدور', 'blue'],
    };
    const DAYS = ['الأحد', 'الاتنين', 'التلات', 'الأربع', 'الخميس', 'الجمعة', 'السبت'];
    let P, root, data, tplLang = 'ar', tplStep = 1, filters = { status: '', country: '', q: '', page: 1 };

    const api = async (url, body) => {
        const r = await P.fetchJSON(url, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...body, csrf_token: data?.csrf_token }) } : undefined);
        if (!r?.success) throw new Error(r?.error || 'حصلت مشكلة');
        return r.data;
    };
    const toast = (msg, bad) => { const el = root.querySelector('#grMsg'); el.textContent = msg; el.className = 'gr-msg' + (bad ? ' bad' : ''); el.hidden = !msg; if (msg) el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); };
    const run = async (btn, fn) => { if (btn) btn.disabled = true; try { await fn(); } catch (e) { toast(e.message, true); } finally { if (btn) btn.disabled = false; } };
    const v = id => root.querySelector('#' + id);
    const tested = () => { try { return !!localStorage.getItem('gr-tested'); } catch (e) { return false; } };

    const render = () => {
        const s = data.stats, st = data.settings, by = s.by_status || {};
        const withEmail = s.total - (by.no_email || 0) - (by.invalid || 0);
        const pct = (a, b) => b ? Math.round(a * 100 / b) + '%' : '—';
        const on = st.enabled === '1';
        const checks = [
            [!!st.from_email, 'إيميل المرسل متظبط'],
            [!!st.smtp_host, 'SMTP مخصص للتسويق (مش إيميل المنصة الأساسي)'],
            [s.total > 0, 'استوردت شركات'],
            [tested(), 'بعتّ رسالة تجربة لنفسك وشفت شكلها'],
            [on, 'شغّلت الإرسال التلقائي'],
        ];
        root.innerHTML = `
<header class="gr-hero"><div><span class="gr-eye">TOURFECTO / GROWTH</span><h1>تسويق المنصة لشركات السياحة</h1><p>استورد الشركات، والمنصة تبعت لكل شركة 3 رسايل شخصية على أيام مختلفة، وتقف لوحدها لو الشركة ردّت أو سجّلت أو ألغت.</p></div>
<div class="gr-switch ${on ? 'on' : ''}"><b>${on ? '🟢 الإرسال التلقائي شغال' : '⏸ الإرسال متوقف'}</b><span>${s.paused_reason === 'bounces' ? '⚠️ وقفناه لوحده لأن إيميلات كتير رجعت. نضّف القائمة وبعدين شغّله.' : on ? (s.in_window ? 'بيبعت دلوقتي في مواعيد العمل' : 'هيبعت في مواعيد العمل الجاية') : 'شغّله بعد ما تجرب رسالة على نفسك'}</span><button class="p-btn ${on ? 'outline' : ''}" data-toggle>${on ? 'وقّف الإرسال' : 'شغّل الإرسال'}</button></div></header>
<div id="grMsg" class="gr-msg" hidden></div>
<section class="gr-cards">
 ${[['الشركات', s.total], ['ليها إيميل', withEmail], ['اتبعتلها', (by.contacted || 0) + (by.finished || 0) + (by.replied || 0) + (by.interested || 0) + (by.signed_up || 0)], ['ضغطوا اللينك', s.messages.clicked + ' <small>' + pct(s.messages.clicked, s.messages.sent) + '</small>'], ['مهتمين / ردّوا', (by.interested || 0) + (by.replied || 0)], ['سجّلوا 🎉', by.signed_up || 0], ['ألغوا الاشتراك', by.unsubscribed || 0], ['إيميلات راجعة', (by.bounced || 0) + (by.invalid || 0)]].map(([l, n]) => `<div class="gr-card"><span>${l}</span><b>${n}</b></div>`).join('')}
</section>
<section class="gr-today"><div><b>النهارده:</b> اتبعت ${s.today.sent} من ${s.today.limit} <span class="gr-muted">(الحد بيزيد كل يوم لحد ${esc(st.daily_limit)} عشان الإيميل مايدخلش سبام)</span></div><div><b>مستني الإرسال:</b> ${s.pending} رسالة · <span class="gr-muted">آخر تشغيل: ${esc(s.last_run || 'لسه')}</span></div>
<div class="gr-actions"><button class="p-btn outline sm" data-run="send">ابعت دفعة دلوقتي</button><button class="p-btn outline sm" data-run="enrich">دوّر على إيميلات الشركات الناقصة (${by.no_email || 0})</button></div></section>
<section class="gr-box"><h2>✅ قبل ما تشغّل</h2><ul class="gr-checks">${checks.map(([ok, t]) => `<li class="${ok ? 'ok' : ''}">${ok ? '✔' : '○'} ${t}</li>`).join('')}</ul>
<p class="gr-muted">نصيحة مهمة: ابعت من دومين أو إيميل منفصل (زي hello@try-tourfecto.com) مش من tourfecto.pro نفسه، عشان لو حد عمل Spam مايأثرش على إيميلات المنصة (التسجيل والفواتير). وفعّل SPF وDKIM وDMARC للدومين ده.</p></section>

<section class="gr-box"><h2>1) استيراد الشركات</h2>
<div class="gr-row"><input id="grUrl" class="p-select" dir="ltr" placeholder="https://api.apify.com/v2/datasets/.../items?..." value="${esc(st.source_url)}"><button class="p-btn" data-import>استورد / حدّث</button></div>
<p class="gr-muted">بيقبل رابط Dataset من Apify (Google Maps أو Contact scraper) أو أي رابط JSON. ممكن تستورد نفس الرابط تاني بعد ما تجيب بيانات جديدة: مفيش تكرار، وعملاء المنصة الحاليين بيتشالوا لوحدهم.</p>
<details><summary>أو الصق البيانات (JSON أو CSV فيه عمود email)</summary><textarea id="grText" class="p-select" rows="5" dir="ltr" placeholder="company,email,website,city,country"></textarea><button class="p-btn outline sm" data-import-text>استورد اللي اتلزق</button></details></section>

<section class="gr-box"><h2>2) الرسايل</h2>
<div class="gr-tabs">${['ar', 'en'].map(l => `<button class="p-btn xs ${tplLang === l ? '' : 'outline'}" data-lang="${l}">${l === 'ar' ? 'عربي (الدول العربية)' : 'English (باقي الدول)'}</button>`).join('')}<span class="gr-sep"></span>${[1, 2, 3].map(n => `<button class="p-btn xs ${tplStep === n ? '' : 'outline'}" data-step="${n}">${['', 'الرسالة 1: التعارف (يوم 0)', 'الرسالة 2: تحليل مجاني (بعد ' + (st.step_gaps.split(',')[0] || 3) + ' أيام)', 'الرسالة 3: الأخيرة (بعد ' + (st.step_gaps.split(',')[1] || 4) + ' أيام كمان)'][n]}</button>`).join('')}</div>
<div class="gr-split"><div><label class="gr-f">العنوان<input id="grSubj" class="p-select" value="${esc(st.templates[tplLang][tplStep].subject)}"></label><label class="gr-f">الرسالة<textarea id="grBody" class="p-select" rows="16">${esc(st.templates[tplLang][tplStep].body)}</textarea></label>
<p class="gr-muted">متغيرات: {{company}} {{city}} {{rating}} {{reviews}} {{opener}} (أول سطر شخصي) {{signup_link}} (زرار التسجيل) {{website_or_company}} {{sender_name}}</p>
<div class="gr-actions"><button class="p-btn" data-save-tpl>حفظ الرسالة</button><button class="p-btn outline" data-preview>معاينة على شركة حقيقية</button><button class="p-btn outline" data-reset-tpl>رجّع الرسالة الأصلية</button></div></div>
<div><div class="gr-preview-head" id="grPrevHead">المعاينة</div><iframe id="grPreview" class="gr-preview" sandbox title="preview"></iframe>
<div class="gr-row"><input id="grTestTo" class="p-select" dir="ltr" value="${esc(data.admin_email)}"><button class="p-btn outline" data-test>ابعتها لإيميلي</button></div></div></div></section>

<section class="gr-box"><h2>3) الإعدادات</h2><div class="gr-grid">
${f('sender_name', 'اسم المرسل (اسمك)')}${f('sender_title', 'المسمى (مثلًا: مؤسس Tourfecto)')}${f('from_email', 'إيميل المرسل', 'email')}${f('reply_to', 'الردود تروح على', 'email')}
${f('smtp_host', 'SMTP Host (للتسويق)')}${f('smtp_port', 'Port', 'number')}${f('smtp_username', 'SMTP Username')}<label class="gr-f">SMTP Password<input id="s_smtp_password" type="password" class="p-select" placeholder="${st.smtp_password_set ? '•••••• (محفوظ)' : ''}"></label>
<label class="gr-f">التشفير<select id="s_smtp_encryption" class="p-select">${['tls', 'ssl', ''].map(x => `<option value="${x}" ${st.smtp_encryption === x ? 'selected' : ''}>${x || 'بدون'}</option>`).join('')}</select></label>
${f('daily_limit', 'أقصى عدد في اليوم (بعد التسخين)', 'number')}${f('hour_from', 'يبعت من الساعة', 'number')}${f('hour_to', 'لحد الساعة', 'number')}${f('timezone', 'المنطقة الزمنية')}
<label class="gr-f">أيام مايبعتش فيها<div class="gr-days">${DAYS.map((d, i) => `<label><input type="checkbox" data-day="${i}" ${st.skip_days.split(',').includes(String(i)) ? 'checked' : ''}>${d}</label>`).join('')}</div></label>
${f('step_gaps', 'الأيام بين الرسايل (مثلًا 3,4)')}${f('signup_url', 'لينك التسجيل')}${f('company_address', 'عنوان الشركة (لازم في آخر الإيميل)')}
<label class="gr-f gr-chk"><input type="checkbox" id="s_ai_opener" ${st.ai_opener !== '0' ? 'checked' : ''}> الذكاء الصناعي يكتب أول سطر مخصوص لكل شركة</label>
<label class="gr-f gr-chk"><input type="checkbox" id="s_track_opens" ${st.track_opens === '1' ? 'checked' : ''}> تتبع فتح الرسالة (بيقلل الوصول للـ Inbox شوية - الأفضل مقفول)</label>
</div><button class="p-btn" data-save-settings>حفظ الإعدادات</button></section>

<section class="gr-box"><h2>4) الشركات</h2><div class="gr-row">
<select id="grFStatus" class="p-select"><option value="">كل الحالات</option>${Object.entries(STATUS).filter(([k]) => k !== 'queued').map(([k, [l]]) => `<option value="${k}" ${filters.status === k ? 'selected' : ''}>${l} (${by[k] || 0})</option>`).join('')}</select>
<select id="grFCountry" class="p-select"><option value="">كل الدول</option>${Object.entries(s.countries || {}).map(([c, n]) => `<option value="${esc(c)}" ${filters.country === c ? 'selected' : ''}>${esc(c)} (${n})</option>`).join('')}</select>
<input id="grFQ" class="p-select" placeholder="دوّر باسم الشركة أو الإيميل أو المدينة" value="${esc(filters.q)}"></div><div id="grLeads" class="gr-leads"></div></section>`;
        preview();
        loadLeads();
    };
    const f = (k, label, type = 'text') => `<label class="gr-f">${label}<input id="s_${k}" type="${type}" class="p-select" value="${esc(data.settings[k])}" ${['from_email', 'reply_to', 'smtp_host', 'smtp_username', 'signup_url', 'timezone'].includes(k) ? 'dir="ltr"' : ''}></label>`;

    const loadLeads = async () => {
        const box = v('grLeads'); if (!box) return;
        box.innerHTML = '<p class="gr-muted">بيحمّل…</p>';
        try {
            const q = new URLSearchParams(filters).toString();
            const r = await api('/api/admin/growth/leads?' + q);
            box.innerHTML = r.rows.length ? `<table class="gr-table"><thead><tr><th>الشركة</th><th>الإيميل</th><th>المكان</th><th>جوجل</th><th>الحالة</th><th>آخر رسالة</th><th></th></tr></thead><tbody>${r.rows.map(l => `<tr>
<td><b>${esc(l.company || '—')}</b>${l.category ? `<div class="gr-muted">${esc(l.category)}</div>` : ''}${l.website ? `<a href="${esc(l.website)}" target="_blank" rel="noopener noreferrer" dir="ltr">${esc(l.website.replace(/^https?:\/\/(www\.)?/, '').slice(0, 40))}</a>` : ''}</td>
<td dir="ltr">${esc(l.email || '—')}${l.phone ? `<div class="gr-muted">${esc(l.phone)}</div>` : ''}</td>
<td>${esc([l.city, l.country].filter(Boolean).join(' · '))}<div class="gr-muted">${l.language === 'ar' ? 'عربي' : 'English'}</div></td>
<td>${l.rating ? '⭐ ' + Number(l.rating) + (l.reviews ? ` <span class="gr-muted">(${l.reviews})</span>` : '') : '—'}${l.maps_url ? ` <a href="${esc(l.maps_url)}" target="_blank" rel="noopener noreferrer">خريطة</a>` : ''}</td>
<td><span class="gr-badge ${STATUS[l.status]?.[1] || ''}">${esc(STATUS[l.status]?.[0] || l.status)}</span>${Number(l.step) ? `<div class="gr-muted">رسالة ${l.step} من 3${l.clicked_at ? ' · ضغط اللينك' : ''}</div>` : ''}</td>
<td class="gr-muted">${esc(l.last_sent_at || '—')}${l.next_send_at && ['contacted'].includes(l.status) ? `<div>الجاية: ${esc(l.next_send_at.slice(0, 10))}</div>` : ''}</td>
<td><select class="p-select gr-act" data-lead="${l.id}"><option value="">إجراء…</option><option value="replied">ردّت (وقّف الرسايل)</option><option value="interested">مهتمة</option><option value="signed_up">سجّلت</option><option value="paused">وقّف</option><option value="contacted">كمّل الرسايل</option><option value="unsubscribed">ألغت الاشتراك</option><option value="bounced">الإيميل راجع</option><option value="delete">امسح</option></select></td></tr>`).join('')}</tbody></table>
<div class="gr-row">${r.page > 1 ? '<button class="p-btn outline xs" data-page="-1">السابق</button>' : ''}<span class="gr-muted">صفحة ${r.page} من ${Math.max(1, r.pages)} · ${r.total} شركة</span>${r.page < r.pages ? '<button class="p-btn outline xs" data-page="1">التالي</button>' : ''}</div>`
                : '<p class="gr-muted">مفيش شركات هنا. استورد من فوق.</p>';
        } catch (e) { box.innerHTML = `<p class="gr-msg bad">${esc(e.message)}</p>`; }
    };

    const currentTemplates = () => { const t = JSON.parse(JSON.stringify(data.settings.templates)); t[tplLang][tplStep] = { subject: v('grSubj').value, body: v('grBody').value }; return t; };
    const preview = async () => {
        try {
            const r = await api('/api/admin/growth/preview', { step: tplStep, lang: tplLang, templates: currentTemplates() });
            v('grPrevHead').innerHTML = `<b>${esc(r.subject)}</b><div class="gr-muted">معاينة على: ${esc(r.lead.company)}${r.lead.city ? ' · ' + esc(r.lead.city) : ''}${r.lead.rating ? ' · ⭐ ' + r.lead.rating : ''}</div>`;
            v('grPreview').srcdoc = '<!doctype html><meta charset="utf-8"><body style="margin:12px;background:#fff">' + r.html + '</body>';
        } catch (e) { v('grPrevHead').textContent = e.message; }
    };
    const settingsFromForm = () => {
        const out = {};
        ['sender_name', 'sender_title', 'from_email', 'reply_to', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'daily_limit', 'hour_from', 'hour_to', 'timezone', 'step_gaps', 'signup_url', 'company_address'].forEach(k => { const el = v('s_' + k); if (el) out[k] = el.value.trim(); });
        out.skip_days = [...root.querySelectorAll('[data-day]:checked')].map(x => x.dataset.day).join(',');
        out.ai_opener = v('s_ai_opener').checked ? '1' : '0';
        out.track_opens = v('s_track_opens').checked ? '1' : '0';
        return out;
    };
    const reload = async () => { data = { ...(await api('/api/admin/growth')), }; render(); };

    const bind = () => {
        root.addEventListener('click', e => {
            const t = e.target.closest('button'); if (!t) return;
            if (t.dataset.toggle !== undefined) return run(t, async () => {
                const on = data.settings.enabled === '1';
                if (!on && !data.settings.from_email) throw new Error('حط إيميل المرسل في الإعدادات الأول');
                if (!on && !confirm('هيبدأ يبعت للشركات تلقائيًا في مواعيد العمل وبالحد اليومي. متأكد إنك راجعت الرسايل وجربتها على نفسك؟')) return;
                await api('/api/admin/growth/settings', { settings: { enabled: on ? '0' : '1' } }); await reload(); toast(on ? 'وقفنا الإرسال.' : 'الإرسال التلقائي اشتغل ✅');
            });
            if (t.dataset.run) return run(t, async () => {
                toast(t.dataset.run === 'enrich' ? 'بندوّر في مواقع الشركات… (حوالي دقيقة)' : 'بنبعت دفعة…');
                const r = await api('/api/admin/growth/run', { what: t.dataset.run });
                await reload();
                toast(t.dataset.run === 'enrich' ? `لقينا ${r.found} إيميل جديد.` : r.reason === 'daily_limit' ? 'وصلت للحد اليومي النهارده.' : r.reason === 'paused_bounces' ? 'متوقف بسبب الإيميلات الراجعة.' : `اتبعت ${r.sent}${r.failed ? ` · فشل ${r.failed}` : ''}.`);
            });
            if (t.dataset.import !== undefined || t.dataset.importText !== undefined) return run(t, async () => {
                toast('بنستورد… لو الملف كبير ممكن ياخد دقيقة');
                const r = await api('/api/admin/growth/import', t.dataset.import !== undefined ? { url: v('grUrl').value.trim() } : { text: v('grText').value });
                await reload();
                toast(`تم: ${r.added} شركة جديدة · ${r.updated} اتحدّثت · ${r.no_email} مالهاش إيميل (هندوّر عليه في موقعها) · ${r.skipped} اتشالت (مكررة أو عملاء عندك أو ناقصة)`);
            });
            if (t.dataset.lang) { tplLang = t.dataset.lang; render(); return; }
            if (t.dataset.step) { tplStep = Number(t.dataset.step); render(); return; }
            if (t.dataset.preview !== undefined) return run(t, preview);
            if (t.dataset.saveTpl !== undefined) return run(t, async () => { await api('/api/admin/growth/settings', { settings: { templates: currentTemplates() } }); data.settings.templates = currentTemplates(); toast('الرسالة اتحفظت ✅'); preview(); });
            if (t.dataset.resetTpl !== undefined) return run(t, async () => { if (!confirm('ترجع الرسالة دي للنص الأصلي؟')) return; const tpl = currentTemplates(); delete tpl[tplLang][tplStep]; tpl[tplLang][tplStep] = { subject: '', body: '' }; await api('/api/admin/growth/settings', { settings: { templates: tpl } }); await reload(); });
            if (t.dataset.test !== undefined) return run(t, async () => { await api('/api/admin/growth/settings', { settings: { templates: currentTemplates() } }); data.settings.templates = currentTemplates(); await api('/api/admin/growth/test', { email: v('grTestTo').value.trim(), step: tplStep }); try { localStorage.setItem('gr-tested', '1'); } catch (x) {} toast('اتبعتت ✅ شوف الـ Inbox (ولو راحت Spam يبقى لازم تظبط SPF/DKIM للدومين).'); });
            if (t.dataset.saveSettings !== undefined) return run(t, async () => { await api('/api/admin/growth/settings', { settings: settingsFromForm() }); await reload(); toast('الإعدادات اتحفظت ✅'); });
            if (t.dataset.page) { filters.page += Number(t.dataset.page); loadLeads(); }
        });
        root.addEventListener('change', e => {
            const s = e.target;
            if (s.matches('.gr-act') && s.value) {
                const st = s.value;
                if (st === 'delete' && !confirm('تمسح الشركة دي نهائيًا؟')) { s.value = ''; return; }
                run(s, async () => { await api('/api/admin/growth/leads/' + s.dataset.lead + '/status', { status: st }); toast('اتحدّث ✅'); const r = await api('/api/admin/growth'); data = r; loadLeads(); });
            }
            if (s.id === 'grFStatus' || s.id === 'grFCountry') { filters.status = v('grFStatus').value; filters.country = v('grFCountry').value; filters.page = 1; loadLeads(); }
        });
        let timer; root.addEventListener('input', e => { if (e.target.id === 'grFQ') { clearTimeout(timer); timer = setTimeout(() => { filters.q = e.target.value.trim(); filters.page = 1; loadLeads(); }, 350); } });
    };

    window.AdminGrowth = {
        init: async panel => {
            P = panel; root = document.getElementById('growthRoot'); if (!root || !P) return;
            try { data = await api('/api/admin/growth'); bind(); render(); }
            catch (e) { root.innerHTML = `<p class="gr-msg bad">${esc(e.message)}</p>`; }
        },
    };
})();
