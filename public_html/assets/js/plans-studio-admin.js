/* Tourfecto - أدمن: الباقات المقترحة، الإضافات، ابني باقتك، أسعار الصرف */
(() => {
    'use strict';
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let P, root, D;
    const api = async (url, body) => { const r = await P.fetchJSON(url, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...body, csrf_token: D?.csrf_token }) } : undefined); if (!r?.success) throw new Error(r?.error || 'حصلت مشكلة'); return r; };
    const msg = (m, bad) => { const el = root.querySelector('#paMsg'); el.textContent = m; el.className = 'ps-msg' + (bad ? ' bad' : ''); el.hidden = !m; el.scrollIntoView({ block: 'nearest' }); };
    const run = async (b, fn) => { b.disabled = true; try { await fn(); } catch (e) { msg(e.message, true); } finally { b.disabled = false; } };
    const v = id => root.querySelector('#' + id);
    const render = () => {
        const c = D.config, R = D.rates, plans = D.plans;
        root.innerHTML = `<div id="paMsg" class="ps-msg" hidden></div>
<section class="ps-box"><h3>1) الباقات الأساسية</h3><p class="p-cell-muted">الأسعار الحالية: ${Object.entries(plans).map(([k, p]) => `<b>${esc(p.name)}</b> $${p.price_monthly}/شهر`).join(' · ')}. تقدر تطبّق التشكيلة المقترحة (معمولة لشركات السياحة) وبعدين تعدّل أي رقم من صفحة <a href="/admin/plans">الباقات</a>.</p>
<div class="ps-preset">${Object.entries(D.preset).map(([k, p]) => `<div><b>${esc(p.name)}</b> — $${p.price_monthly}/شهر · $${p.price_yearly}/سنة<br>${p.multiple_websites} موقع · ${p.chat_credits.toLocaleString()} رد شات · ${p.review_credits} رد تقييم · ${p.ai_analysis} تحليل · ${p.extra.articles} مقال · ${p.extra.media_images} صورة · ${p.extra.media_videos} فيديو${p.auto_pilot ? ' · الطيار الآلي' : ''}${p.advanced_analytics ? ' · تحليلات متقدمة' : ''}</div>`).join('')}</div>
<button class="p-btn" data-preset>طبّق الباقات المقترحة</button> <span class="p-cell-muted">المشتركين الحاليين بيكملوا بسعرهم لحد التجديد.</span></section>

<section class="ps-box"><h3>2) التجربة المجانية</h3><p class="p-cell-muted">التجربة ليها حدود للخدمات الغالية (3 مقالات، 15 صورة، فيديو واحد، 300 رد شات، 10 أدوات تسويق...) عشان أقصى تكلفة للحساب الواحد تبقى حوالي $4.</p><div class="ps-cur-plan"><label><input type="checkbox" id="paTrial" ${c.trial_enabled ? 'checked' : ''}> مفعّلة للحسابات الجديدة</label>
<label>المدة <input id="paTrialDays" type="number" class="p-select" min="1" max="60" value="${c.trial_days}" style="width:70px"> يوم</label>
<label>على باقة <select id="paTrialPlan" class="p-select">${Object.entries(plans).map(([k, p]) => `<option value="${k}" ${k === c.trial_plan ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></label></div></section>

<section class="ps-box ps-adm"><h3>3) إضافات حسب الطلب (مرة واحدة - 30 يوم)</h3><table><thead><tr><th>الإضافة</th><th>الكمية</th><th>السعر $</th><th>متاحة</th></tr></thead><tbody>${Object.entries(c.packs).map(([k, p]) => `<tr><td>${p.icon} ${esc(c.services[p.service].ar)}</td><td><input class="p-select" type="number" min="1" data-pq="${k}" value="${p.qty}"></td><td><input class="p-select" type="number" min="0" step="0.5" data-pp="${k}" value="${p.price}"></td><td><input type="checkbox" data-pe="${k}" ${p.enabled ? 'checked' : ''}></td></tr>`).join('')}</tbody></table></section>

<section class="ps-box ps-adm"><h3>4) ابني باقتك - سعر الوحدة شهريًا</h3><label><input type="checkbox" id="paBuilder" ${c.builder_enabled ? 'checked' : ''}> مفعّل</label><table><thead><tr><th>الخدمة</th><th>سعر الوحدة $</th><th>مثال</th><th>متاحة</th></tr></thead><tbody>${Object.entries(c.services).map(([k, s]) => `<tr><td>${esc(s.ar)}</td><td><input class="p-select" type="number" min="0" step="0.001" data-su="${k}" value="${s.unit}"></td><td class="p-cell-muted">+${s.step} = $${(s.step * s.unit).toFixed(2)}/شهر</td><td><input type="checkbox" data-se="${k}" ${s.enabled ? 'checked' : ''}></td></tr>`).join('')}</tbody></table>
<button class="p-btn" data-save-config>حفظ (التجربة + الإضافات + ابني باقتك)</button></section>

<section class="ps-box ps-adm"><h3>5) العملات وأسعار الصرف</h3><p class="p-cell-muted">المنصة بالدولار للكل (العملاء أجانب). الجنيه بيظهر صغير جنب السعر (شرط البنك)، وأي عميل يقدر يختار عملته بنفسه = السعر بالدولار × سعر الصرف (+ الهامش). لو البنك (XPay) محدد سعر، اكتبه واقفله.</p>
<label style="display:block;margin:8px 0"><input type="checkbox" id="paAutoLocal" ${D.auto_local ? 'checked' : ''}> اعرض للزائر عملة بلده تلقائي (مقفولة = الدولار للكل)</label>
<table><thead><tr><th>العملة</th><th>سعر 1$</th><th>هامش %</th><th>السعر النهائي</th><th>مقفول (يدوي)</th><th>تظهر للعملاء</th><th>آخر تحديث</th></tr></thead><tbody>${Object.entries(R).filter(([k]) => k !== 'USD').map(([k, r]) => `<tr><td><b>${k}</b> ${esc(D.currencies[k].ar)}</td><td><input class="p-select" type="number" step="0.0001" min="0" data-rb="${k}" value="${r.base}"></td><td><input class="p-select" type="number" step="0.1" min="0" max="20" data-rm="${k}" value="${r.margin}" style="max-width:70px"></td><td>${r.rate}</td><td><input type="checkbox" data-rl="${k}" ${r.locked ? 'checked' : ''}></td><td><input type="checkbox" data-re="${k}" ${r.enabled ? 'checked' : ''}></td><td class="p-cell-muted">${esc(r.updated_at || (r.source === 'default' ? 'افتراضي - حدّثه' : ''))}</td></tr>`).join('')}</tbody></table>
<button class="p-btn" data-save-rates>حفظ أسعار الصرف</button> <button class="p-btn outline" data-refresh>حدّث من السوق (غير المقفولة)</button></section>`;
    };
    const bind = () => root.addEventListener('click', e => {
        const b = e.target.closest('button'); if (!b) return;
        if (b.dataset.preset !== undefined) return run(b, async () => { if (!confirm('هيتغيّر اسم وسعر وحدود الباقات التلاتة للتشكيلة المقترحة. تكمّل؟')) return; const r = await api('/api/admin/plans-studio/preset', {}); D.plans = r.data.plans; render(); msg('✅ اتطبقت: ' + r.data.applied.join('، ')); });
        if (b.dataset.saveConfig !== undefined) return run(b, async () => {
            const packs = {}, services = {};
            root.querySelectorAll('[data-pq]').forEach(i => { const k = i.dataset.pq; packs[k] = { qty: +i.value, price: +root.querySelector(`[data-pp="${k}"]`).value, enabled: root.querySelector(`[data-pe="${k}"]`).checked }; });
            root.querySelectorAll('[data-su]').forEach(i => { const k = i.dataset.su; services[k] = { unit: +i.value, enabled: root.querySelector(`[data-se="${k}"]`).checked }; });
            const r = await api('/api/admin/plans-studio/config', { config: { trial_enabled: v('paTrial').checked, trial_days: +v('paTrialDays').value, trial_plan: v('paTrialPlan').value, builder_enabled: v('paBuilder').checked, packs, services } });
            D.config = r.data.config; render(); msg('✅ اتحفظ');
        });
        if (b.dataset.saveRates !== undefined) return run(b, async () => {
            const rates = {}; root.querySelectorAll('[data-rb]').forEach(i => { const k = i.dataset.rb; rates[k] = { base: +i.value, margin: +root.querySelector(`[data-rm="${k}"]`).value, locked: root.querySelector(`[data-rl="${k}"]`).checked, enabled: root.querySelector(`[data-re="${k}"]`).checked }; });
            const r = await api('/api/admin/currency/rates', { rates, auto_local: v('paAutoLocal').checked });D.auto_local = v('paAutoLocal').checked; D.rates = r.data.rates; render(); msg('✅ اتحفظت أسعار الصرف');
        });
        if (b.dataset.refresh !== undefined) return run(b, async () => { const r = await api('/api/admin/currency/refresh', {}); D.rates = r.data.rates; render(); msg('✅ ' + (r.message || 'اتحدّثت')); });
    });
    window.PlansAdmin = { init: async panel => { P = panel; root = document.getElementById('plansAdmin'); if (!root) return; try { const r = await api('/api/admin/plans-studio'); D = r.data; bind(); render(); } catch (e) { root.innerHTML = '<p class="ps-msg bad">' + esc(e.message) + '</p>'; } } };
})();
