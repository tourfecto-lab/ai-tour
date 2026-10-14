<?php

/**
 * Tourfecto - Site Dashboard Controller (v2.0.0)
 * لوحة تحكم مستقلة لكل موقع منشأ: نظرة عامة، التصميم، SEO، التقييمات،
 * طلبات التواصل/الحجز. منفصلة عن شات الإنشاء - العميل بيدير موقعه منها
 * مباشرة بعد ما يتولّد، زي أي website builder احترافي.
 */
class SiteDashboardController extends Controller
{
    /** GET /dashboard/sites/{id} - صفحة لوحة تحكم الموقع */
    public function index(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            header('Location: /login');
            exit;
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            header('HTTP/1.1 404 Not Found');
            echo 'الموقع ده مش موجود';
            exit;
        }

        $id = (int) $website->getAttribute('id');
        $c = $website->getContent();
        $businessName = htmlspecialchars((string) ($c['business_name'] ?? $website->getAttribute('slug')), ENT_QUOTES, 'UTF-8');
        $niche = $website->resolveNicheKey();
        $nicheCfg = WebsiteNiches::get($niche);

        $tTitle = $this->tr('sd.title');

        $body = <<<HTML
        <div class="sd-header p-card" style="margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <div>
                <h2 style="margin:0 0 4px;">{$nicheCfg['icon']} {$businessName}</h2>
                <span class="p-badge" id="sdStatusBadge">...</span>
            </div>
            <div style="display:flex;gap:8px;">
                <a href="/sites/{$website->getAttribute('slug')}" target="_blank" class="p-btn outline">👁 معاينة الموقع</a>
                <button class="p-btn primary" onclick="sdPublish()" id="sdPublishBtn">🚀 نشر الموقع</button>
            </div>
        </div>

        <div class="p-tabs" style="margin-bottom:16px;">
            <button class="p-tab active" onclick="sdSwitchTab('overview')" id="sdTabOverviewBtn">📊 نظرة عامة</button>
            <button class="p-tab" onclick="sdSwitchTab('design')" id="sdTabDesignBtn">🎨 التصميم</button>
            <button class="p-tab" onclick="sdSwitchTab('seo')" id="sdTabSeoBtn">🔍 SEO والدومين</button>
            <button class="p-tab" onclick="sdSwitchTab('reviews')" id="sdTabReviewsBtn">⭐ التقييمات</button>
            <button class="p-tab" onclick="sdSwitchTab('leads')" id="sdTabLeadsBtn">📩 طلبات التواصل <span id="sdLeadsCount" class="p-badge-dot" style="display:none;"></span></button>
        </div>

        <div id="sdTabOverview">
            <div class="p-grid cols-3" id="sdOverviewCards">
                <div class="p-card"><span class="p-stat-label">الحالة</span><h3 id="sdOverStatus">-</h3></div>
                <div class="p-card"><span class="p-stat-label">عدد الزيارات</span><h3 id="sdOverViews">-</h3></div>
                <div class="p-card"><span class="p-stat-label">متوسط التقييم</span><h3 id="sdOverRating">-</h3></div>
            </div>
        </div>

        <div id="sdTabDesign" style="display:none;">
            <div class="p-card" style="margin-bottom:14px;">
                <h4 style="margin-bottom:10px;">اختر تصميم الموقع (حسب مجالك: {$nicheCfg['name_ar']})</h4>
                <div id="sdTemplatesGrid" class="p-grid cols-3">جاري التحميل...</div>
            </div>
            <div class="p-card">
                <h4 style="margin-bottom:10px;">اللون والهوية</h4>
                <label class="form-label">لون الموقع</label>
                <select id="sdThemeColor" class="form-control" style="margin-bottom:10px;max-width:260px;">
                    <option value="gold">🟡 ذهبي</option>
                    <option value="blue">🔵 أزرق</option>
                    <option value="green">🟢 أخضر</option>
                    <option value="red">🔴 أحمر</option>
                    <option value="purple">🟣 بنفسجي</option>
                </select>
                <label class="form-label">رابط اللوجو</label>
                <input type="text" id="sdLogoUrl" class="form-control" style="margin-bottom:10px;" dir="ltr" placeholder="https://...">
                <button class="p-btn primary" onclick="sdSaveDesign()">حفظ التصميم</button>
            </div>
        </div>

        <div id="sdTabSeo" style="display:none;">
            <div class="p-card">
                <label class="form-label">عنوان الصفحة (SEO Title)</label>
                <input type="text" id="sdSeoTitle" class="form-control" style="margin-bottom:10px;">
                <label class="form-label">وصف الصفحة (SEO Description)</label>
                <textarea id="sdSeoDesc" class="form-control" rows="3" style="margin-bottom:10px;"></textarea>
                <label class="form-label">دومين مخصص (اختياري)</label>
                <input type="text" id="sdCustomDomain" class="form-control" style="margin-bottom:10px;" dir="ltr" placeholder="www.example.com">
                <button class="p-btn primary" onclick="sdSaveSeo()">حفظ</button><button class="p-btn outline" onclick="sdDomainInstructions()">تحقق من النطاق</button><pre id="sdDomainInstructions" style="white-space:pre-wrap;direction:ltr"></pre>
            </div>
        </div>

        <div id="sdTabReviews" style="display:none;">
            <div id="sdReviewsList" class="p-list">جاري التحميل...</div>
        </div>

        <div id="sdTabLeads" style="display:none;">
            <div id="sdLeadsList" class="p-list">جاري التحميل...</div>
        </div>
HTML;

        $script = <<<JS
(function () {
    const P = window.Panel;
    const fetchJSON = P.fetchJSON, esc = P.esc, toast = P.toast;
    const siteId = {$id};

    window.sdSwitchTab = function (tab) {
        ['overview', 'design', 'seo', 'reviews', 'leads'].forEach(t => {
            document.getElementById('sdTab' + t.charAt(0).toUpperCase() + t.slice(1)).style.display = (t === tab) ? '' : 'none';
            document.getElementById('sdTab' + t.charAt(0).toUpperCase() + t.slice(1) + 'Btn').classList.toggle('active', t === tab);
        });
        if (tab === 'design') sdLoadTemplates();
        if (tab === 'reviews') sdLoadReviews();
        if (tab === 'leads') sdLoadLeads();
    };

    async function sdLoadOverview() {
        const res = await fetchJSON(`/api/site-dashboard/\${siteId}/overview`);
        if (!res.success) return;
        const d = res.data;
        document.getElementById('sdStatusBadge').textContent = d.status === 'published' ? '🟢 منشور' : '⚪ مسوّدة';
        document.getElementById('sdOverStatus').textContent = d.status === 'published' ? 'منشور' : 'مسوّدة';
        document.getElementById('sdOverViews').textContent = d.views_count ?? 0;
        document.getElementById('sdOverRating').textContent = d.average_rating > 0 ? ('⭐ ' + d.average_rating) : 'لا يوجد بعد';
        document.getElementById('sdThemeColor').value = d.theme_color || 'gold';
        document.getElementById('sdLogoUrl').value = d.logo_url || '';
        document.getElementById('sdSeoTitle').value = d.seo_title || '';
        document.getElementById('sdSeoDesc').value = d.seo_description || '';
        document.getElementById('sdCustomDomain').value = d.custom_domain || '';
        const badge = document.getElementById('sdLeadsCount');
        if (d.new_leads_count > 0) { badge.style.display = ''; badge.textContent = d.new_leads_count; } else { badge.style.display = 'none'; }
    }

    window.sdPublish = async function () {
        const res = await fetchJSON(`/api/website-builder/\${siteId}/publish`, { method: 'POST' });
        if (res.success) { toast('تم النشر بنجاح'); sdLoadOverview(); } else { toast(res.error || 'حصل خطأ', 'error'); }
    };

    async function sdLoadTemplates() {
        const res = await fetchJSON(`/api/site-dashboard/\${siteId}/templates`);
        const grid = document.getElementById('sdTemplatesGrid');
        if (!res.success || !res.data.templates.length) { grid.innerHTML = '<p>لا توجد تصميمات متاحة لمجالك حاليًا</p>'; return; }
        grid.innerHTML = res.data.templates.map(t => `
            <div class="p-card sd-template-card \${t.id === res.data.current_template_id ? 'active' : ''}" onclick="sdPickTemplate(\${t.id})" style="cursor:pointer;">
                <div class="sd-template-preview" style="background-image:url('\${esc(t.preview_image || '')}');height:110px;border-radius:8px;background-size:cover;background-position:center;margin-bottom:8px;background-color:#222;"></div>
                <strong>\${esc(t.name_ar)}</strong>
                \${t.is_premium ? '<span class="p-badge gold">مميز</span>' : ''}
            </div>
        `).join('');
    }

    window.sdPickTemplate = async function (templateId) {
        const res = await fetchJSON(`/api/site-dashboard/\${siteId}/design`, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ template_id: templateId }),
        });
        if (res.success) { toast('تم اختيار التصميم'); sdLoadTemplates(); } else { toast(res.error || 'حصل خطأ', 'error'); }
    };

    window.sdSaveDesign = async function () {
        const res = await fetchJSON(`/api/site-dashboard/\${siteId}/design`, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                theme_color: document.getElementById('sdThemeColor').value,
                logo_url: document.getElementById('sdLogoUrl').value,
            }),
        });
        if (res.success) toast('تم الحفظ'); else toast(res.error || 'حصل خطأ', 'error');
    };

    window.sdSaveSeo = async function () {
        const res = await fetchJSON(`/api/site-dashboard/\${siteId}/seo`, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                seo_title: document.getElementById('sdSeoTitle').value,
                seo_description: document.getElementById('sdSeoDesc').value,
                custom_domain: document.getElementById('sdCustomDomain').value,
            }),
        });
        if (res.success) toast('تم الحفظ'); else toast(res.error || 'حصل خطأ', 'error');
    };

    window.sdDomainInstructions=async function(){
        const r=await fetchJSON(`/api/site-dashboard/\${siteId}/domain`);if(!r.success){toast(r.error||'Error','error');return;}
        const d=r.data;document.getElementById('sdDomainInstructions').textContent=d.txt_name?d.state+'\\nTXT '+d.txt_name+'\\n'+d.txt_value+'\\nCNAME/A → '+d.routing_target+'\\nBind this domain and enable HTTPS in your hosting panel.':'Save a domain first.';
        if(d.txt_name){const v=await fetchJSON(`/api/site-dashboard/\${siteId}/domain/verify`,{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});toast(v.success?'DNS verified; configure HTTPS in hosting.':v.error,v.success?'success':'error');}
    };
    async function sdLoadReviews() {
        const res = await fetchJSON(`/api/site-dashboard/\${siteId}/reviews`);
        const list = document.getElementById('sdReviewsList');
        if (!res.success || !res.data.reviews.length) { list.innerHTML = '<p>لا توجد تقييمات بعد</p>'; return; }
        list.innerHTML = res.data.reviews.map(r => `
            <div class="p-card" style="margin-bottom:10px;">
                <div style="display:flex;justify-content:space-between;">
                    <strong>\${esc(r.visitor_name)}</strong>
                    <span>\${'⭐'.repeat(r.rating)}</span>
                </div>
                <p>\${esc(r.comment || '')}</p>
                <div style="display:flex;gap:8px;">
                    \${r.status !== 'approved' ? `<button class="p-btn xs primary" onclick="sdReviewAction(\${r.id},'approved')">✔ اعتماد</button>` : '<span class="p-badge green">معتمد</span>'}
                    \${r.status !== 'rejected' ? `<button class="p-btn xs outline" onclick="sdReviewAction(\${r.id},'rejected')">✖ رفض</button>` : ''}
                </div>
            </div>
        `).join('');
    }

    window.sdReviewAction = async function (reviewId, status) {
        const res = await fetchJSON(`/api/site-dashboard/\${siteId}/reviews/\${reviewId}`, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status }),
        });
        if (res.success) sdLoadReviews(); else toast(res.error || 'حصل خطأ', 'error');
    };

    async function sdLoadLeads() {
        const res = await fetchJSON(`/api/site-dashboard/\${siteId}/leads`);
        const list = document.getElementById('sdLeadsList');
        if (!res.success || !res.data.leads.length) { list.innerHTML = '<p>لا توجد طلبات بعد</p>'; return; }
        list.innerHTML = res.data.leads.map(l => `
            <div class="p-card" style="margin-bottom:10px;">
                <div style="display:flex;justify-content:space-between;">
                    <strong>\${esc(l.visitor_name)}</strong>
                    <span class="p-badge \${l.status === 'new' ? 'gold' : ''}">\${l.status}</span>
                </div>
                <p dir="ltr" style="text-align:right;">\${esc(l.phone || '')} \${l.email ? ' - ' + esc(l.email) : ''}</p>
                <p>\${esc(l.message || '')}</p>
                <select onchange="sdLeadStatus(\${l.id}, this.value)">
                    <option value="new" \${l.status === 'new' ? 'selected' : ''}>جديد</option>
                    <option value="contacted" \${l.status === 'contacted' ? 'selected' : ''}>تم التواصل</option>
                    <option value="closed" \${l.status === 'closed' ? 'selected' : ''}>مغلق</option>
                </select>
            </div>
        `).join('');
    }

    window.sdLeadStatus = async function (leadId, status) {
        const res = await fetchJSON(`/api/site-dashboard/\${siteId}/leads/\${leadId}`, {
            method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ status }),
        });
        if (!res.success) toast(res.error || 'حصل خطأ', 'error');
    };

    sdLoadOverview();
})();
JS;

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderPanelPage('website_builder', $tTitle, $businessName, $body, $script);
        exit;
    }

    /** GET /api/site-dashboard/{id}/overview */
    public function overview(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        $averageRating = (new WebsiteReview())->averageRating((int) $website->getAttribute('id'));
        $newLeads = (new WebsiteLead())->newCountFor((int) $website->getAttribute('id'));

        return $this->success([
            'status' => $website->getAttribute('status'),
            'views_count' => (int) $website->getAttribute('views_count'),
            'average_rating' => $averageRating,
            'theme_color' => $website->getAttribute('theme_color'),
            'logo_url' => $website->getAttribute('logo_url'),
            'seo_title' => $website->getAttribute('seo_title'),
            'seo_description' => $website->getAttribute('seo_description'),
            'custom_domain' => $website->getAttribute('custom_domain'),
            'new_leads_count' => $newLeads,
        ]);
    }

    /** GET /api/site-dashboard/{id}/templates */
    public function templates(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        $niche = $website->resolveNicheKey();
        $rows = (new WebsiteTemplate())->forNiche($niche);
        $templates = array_map(fn ($t) => [
            'id' => (int) $t->getAttribute('id'),
            'name_ar' => $t->getAttribute('name_ar'),
            'preview_image' => $t->getAttribute('preview_image'),
            'is_premium' => (bool) $t->getAttribute('is_premium'),
        ], $rows);

        return $this->success([
            'niche_key' => $niche,
            'templates' => $templates,
            'current_template_id' => (int) $website->getAttribute('template_id'),
        ]);
    }

    /** PUT /api/site-dashboard/{id}/design */
    public function updateDesign(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        $themeColors = ['gold' => 1, 'blue' => 1, 'green' => 1, 'red' => 1, 'purple' => 1];
        if ($this->get('theme_color') !== null && isset($themeColors[(string) $this->get('theme_color')])) {
            $website->setAttribute('theme_color', (string) $this->get('theme_color'));
        }
        if ($this->get('logo_url') !== null) {
            $website->setAttribute('logo_url', (string) $this->get('logo_url'));
        }
        if ($this->get('template_id') !== null) {
            $templateId = (int) $this->get('template_id');
            $template = (new WebsiteTemplate())->find($templateId);
            if ($template && $template->getAttribute('niche_key') === $website->resolveNicheKey()) {
                $website->setAttribute('template_id', $templateId);
            }
        }
        $website->save();
        return $this->success([], 'تم الحفظ');
    }

    /** PUT /api/site-dashboard/{id}/seo */
    public function updateSeo(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        foreach (['seo_title', 'seo_description'] as $field) {
            if ($this->get($field) !== null) {
                $website->setAttribute($field, (string) $this->get($field));
            }
        }
        try{
            if($this->get('custom_domain')!==null){$domain=CustomDomainService::normalize((string)$this->get('custom_domain'));$status=CustomDomainService::configure((int)$website->getAttribute('id'),(int)$this->user['id'],$domain);$website->setAttribute('custom_domain',$domain);}
            if(!$website->save())throw new RuntimeException('تعذر الحفظ');
            return $this->success(['domain'=>CustomDomainService::status((int)$website->getAttribute('id'),(int)$this->user['id'])],'تم الحفظ');
        }catch(Throwable $e){return $this->error(CustomerError::message($e->getMessage()),422);}
    }

    /** GET /api/site-dashboard/{id}/reviews */
    public function reviews(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        $rows = (new WebsiteReview())->allFor((int) $website->getAttribute('id'));
        $reviews = array_map(fn ($r) => [
            'id' => (int) $r->getAttribute('id'),
            'visitor_name' => $r->getAttribute('visitor_name'),
            'rating' => (int) $r->getAttribute('rating'),
            'comment' => $r->getAttribute('comment'),
            'status' => $r->getAttribute('status'),
        ], $rows);

        return $this->success(['reviews' => $reviews]);
    }

    /** PUT /api/site-dashboard/{id}/reviews/{reviewId} - اعتماد أو رفض تقييم */
    public function updateReview(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        $review = (new WebsiteReview())->find((int) ($params['reviewId'] ?? 0));
        if (!$review || (int) $review->getAttribute('website_id') !== (int) $website->getAttribute('id')) {
            return $this->error('غير موجود', 404);
        }
        $status = (string) $this->get('status');
        if (!in_array($status, ['approved', 'rejected', 'pending'], true)) {
            return $this->error('حالة غير صحيحة', 422);
        }
        $review->setAttribute('status', $status);
        $review->save();
        return $this->success([], 'تم التحديث');
    }

    /** GET /api/site-dashboard/{id}/leads */
    public function leads(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        $rows = (new WebsiteLead())->allFor((int) $website->getAttribute('id'));
        $leads = array_map(fn ($l) => [
            'id' => (int) $l->getAttribute('id'),
            'visitor_name' => $l->getAttribute('visitor_name'),
            'phone' => $l->getAttribute('phone'),
            'email' => $l->getAttribute('email'),
            'message' => $l->getAttribute('message'),
            'status' => $l->getAttribute('status'),
        ], $rows);

        return $this->success(['leads' => $leads]);
    }

    /** PUT /api/site-dashboard/{id}/leads/{leadId} */
    public function updateLead(array $params = []): array
    {
        if (!$this->isAuthenticated()) {
            return $this->error('Unauthorized', 401);
        }
        $website = $this->ownedWebsite((int) ($params['id'] ?? 0));
        if (!$website) {
            return $this->error('غير موجود', 404);
        }

        $lead = (new WebsiteLead())->find((int) ($params['leadId'] ?? 0));
        if (!$lead || (int) $lead->getAttribute('website_id') !== (int) $website->getAttribute('id')) {
            return $this->error('غير موجود', 404);
        }
        $status = (string) $this->get('status');
        if (!in_array($status, ['new', 'contacted', 'closed'], true)) {
            return $this->error('حالة غير صحيحة', 422);
        }
        $lead->setAttribute('status', $status);
        $lead->save();
        return $this->success([], 'تم التحديث');
    }

    private function ownedWebsite(int $id): ?GeneratedWebsite
    {
        if (!$id) {
            return null;
        }
        $website = (new GeneratedWebsite())->find($id);
        if (!$website || (int) $website->getAttribute('user_id') !== (int) $this->user['id']) {
            return null;
        }
        return $website;
    }

    public function domainStatus(array $params=[]): array{
        $site=$this->ownedWebsite((int)($params['id']??0));if(!$site)return $this->error('غير موجود',404);
        return $this->success(CustomDomainService::status((int)$site->getAttribute('id'),(int)$this->user['id']));
    }
    public function verifyDomain(array $params=[]): array{
        $site=$this->ownedWebsite((int)($params['id']??0));if(!$site)return $this->error('غير موجود',404);
        try{return $this->success(CustomDomainService::verify((int)$site->getAttribute('id'),(int)$this->user['id']));}catch(Throwable $e){return $this->error(CustomerError::message($e->getMessage()),422);}
    }

}
