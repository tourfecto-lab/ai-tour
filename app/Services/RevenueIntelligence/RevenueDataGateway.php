<?php

/**
 * Tourfecto - Revenue Intelligence Data Gateway
 * @version 1.0.0
 *
 * طبقة القراءة المركزية (Repository) لموديول Revenue Intelligence.
 * كل الوصول لبيانات إيرادات حقيقية بيعدّي من هنا فقط - لا يوجد أي
 * كلاس تاني في الموديول بيعمل SQL مباشر على جداول تانية. هذا يضمن:
 *   1) Tenant Isolation: كل استعلام هنا مقيّد بـ user_id إجباريًا.
 *   2) لا تكرار منطق الاستعلامات في كل Service.
 *   3) لو احتجنا نغيّر مصدر بيانات (مثلاً orders حقيقية بدل الإدخال
 *      اليدوي rev_revenue_records) نغيّره في مكان واحد فقط.
 *
 * لا يعيد هذا الكلاس بناء CRM/Analytics/Ads/Billing - فقط "يقرأ" من
 * جداولها الموجودة فعلاً (rev_revenue_records, rev_marketing_spend,
 * ad_campaigns, crm_deals, crm_contacts, crm_leads, crm_pipeline_stages,
 * subscriptions) عبر Database::query() مباشرة (بنفس أسلوب باقي
 * Controllers في المشروع)، بدون ORM إضافي غير ضروري.
 */
class RevenueDataGateway
{
    /** @var Database */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ============================================================
    // Revenue Records (rev_revenue_records) - BATCH6
    // ============================================================

    /** كل سجلات الإيراد الفعلية لمستخدم معيّن ضمن فترة زمنية. */
    public function getRevenueRecords(int $userId, string $fromDate, string $toDate): array
    {
        return $this->db->query(
            "SELECT source, reference_id, amount, currency, recorded_at, notes
             FROM rev_revenue_records
             WHERE user_id = ? AND recorded_at >= ? AND recorded_at < ?
             ORDER BY recorded_at ASC",
            [$userId, $fromDate, $toDate]
        );
    }

    /** إجمالي الإيراد + عدد السجلات ضمن فترة. */
    /**
     * Section 21 (No Fake Data - extends to "no silently wrong data" too):
     * SUM(amount) across records only makes sense if they're all the same
     * currency. Rather than silently adding e.g. $50 + €50 = "100", we
     * detect a currency mix and surface it explicitly so the UI/AI
     * assistant can warn instead of showing a misleading total. No FX
     * conversion is attempted (no exchange-rate data source exists in the
     * project), so a mixed-currency period cannot be honestly summed at all.
     */
    public function getRevenueTotals(int $userId, string $fromDate, string $toDate): array
    {
        $row = $this->db->query(
            "SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS count, COUNT(DISTINCT currency) AS currency_count,
                    MIN(currency) AS single_currency
             FROM rev_revenue_records WHERE user_id = ? AND recorded_at >= ? AND recorded_at < ?",
            [$userId, $fromDate, $toDate]
        );
        $currencyCount = (int) ($row[0]['currency_count'] ?? 0);
        $currencies=$this->db->query('SELECT currency,SUM(amount) total,COUNT(*) count FROM rev_revenue_records WHERE user_id=? AND recorded_at>=? AND recorded_at<? GROUP BY currency',[$userId,$fromDate,$toDate]);
        return [
            'total' => $currencyCount>1?null:(float)($row[0]['total']??0),
            'totals_by_currency'=>$currencies,
            'count' => (int) ($row[0]['count'] ?? 0),
            'mixed_currency' => $currencyCount > 1,
            'currency' => $currencyCount === 1 ? $row[0]['single_currency'] : null,
        ];
    }

    /** سلسلة إيراد يومية (لأغراض الرسم البياني/الـ Forecast/الـ Anomaly Detection). */
    public function getDailyRevenueSeries(int $userId, string $fromDate, string $toDate): array
    {
        if($this->getRevenueTotals($userId,$fromDate,$toDate)['mixed_currency'])return [];
        $rows = $this->db->query(
            "SELECT DATE(recorded_at) AS d, SUM(amount) AS revenue, COUNT(*) AS records
             FROM rev_revenue_records
             WHERE user_id = ? AND recorded_at >= ? AND recorded_at < ?
             GROUP BY DATE(recorded_at) ORDER BY d ASC",
            [$userId, $fromDate, $toDate]
        );
        return array_map(static function ($r) {
            return ['date' => $r['d'], 'revenue' => (float) $r['revenue'], 'records' => (int) $r['records']];
        }, $rows);
    }

    /** الإيراد مجمّع حسب المصدر (booking/order/subscription/manual...) - المصدر الحقيقي الوحيد المتاح. */
    public function getRevenueBySource(int $userId, string $fromDate, string $toDate): array
    {
        if($this->getRevenueTotals($userId,$fromDate,$toDate)['mixed_currency'])return [];
        return $this->db->query(
            "SELECT source, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS count
             FROM rev_revenue_records
             WHERE user_id = ? AND recorded_at >= ? AND recorded_at < ?
             GROUP BY source ORDER BY total DESC",
            [$userId, $fromDate, $toDate]
        );
    }

    /**
     * سجلات الإيراد مع بُعد المنتج (G2) - للمنتجات/التصنيفات. كل سجل
     * بيحمل source/product_name/category/amount عشان الـ Service يجمعهم
     * حسب المنتج أو التصنيف مع fallback آمن للمصدر.
     */
    public function getRevenueRecordsWithProduct(int $userId, string $fromDate, string $toDate): array
    {
        if($this->getRevenueTotals($userId,$fromDate,$toDate)['mixed_currency'])return [];
        return $this->db->query(
            "SELECT source, product_name, category, amount, currency, recorded_at
             FROM rev_revenue_records
             WHERE user_id = ? AND recorded_at >= ? AND recorded_at < ?
             ORDER BY recorded_at ASC",
            [$userId, $fromDate, $toDate]
        );
    }

    /**
     * مجموع الإيراد الفعلي (rev_revenue_records) لشهر معيّن (G7).
     * الشهر بصيغة YYYY-MM.
     */
    public function getRevenueSumForMonth(int $userId, string $period): ?float
    {
        $totals=$this->getRevenueTotals($userId,$period.'-01',(new DateTimeImmutable($period.'-01'))->modify('+1 month')->format('Y-m-d'));if($totals['mixed_currency'])return null;
        $row = $this->db->query(
            "SELECT COALESCE(SUM(amount), 0) AS total
             FROM rev_revenue_records
             WHERE user_id = ? AND DATE_FORMAT(recorded_at, '%Y-%m') = ?",
            [$userId, $period]
        );
        return (float) ($row[0]['total'] ?? 0);
    }

    /** مجموع قيمة الصفقات المكسوبة (won) في شهر معيّن (G7) - نفس منطق تقرير CRM. */
    public function getWonDealsForMonth(int $userId, string $period): float
    {
        $row = $this->db->query(
            "SELECT COALESCE(SUM(value), 0) AS total
             FROM crm_deals
             WHERE owner_user_id = ? AND status = 'won' AND closed_at IS NOT NULL
               AND DATE_FORMAT(closed_at, '%Y-%m') = ?",
            [$userId, $period]
        );
        return (float) ($row[0]['total'] ?? 0);
    }

    /**
     * الصفقات المفتوحة المتوقع إغلاقها في شهر معيّن (G7) - للتنبؤ
     * بتحقيق الهدف من الخط. بيحمل كل صفقة احتمالية صفقتها أو
     * احتمالية مرحلتها (fallback stage_win_probability).
     */
    public function getOpenDealsForMonth(int $userId, string $period): array
    {
        try {
            return $this->db->query(
                "SELECT d.id, d.title, d.value, d.probability, d.expected_close_date,
                        COALESCE(d.probability, s.win_probability) AS effective_probability
                 FROM crm_deals d
                 LEFT JOIN crm_pipeline_stages s ON s.id = d.stage_id
                 WHERE d.owner_user_id = ? AND d.status = 'open'
                   AND d.expected_close_date IS NOT NULL
                   AND DATE_FORMAT(d.expected_close_date, '%Y-%m') = ?",
                [$userId, $period]
            );
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * سلسلة شهرية (Y-m) لإيراد نوع معيّن (عادة source='subscription') خلال
     * آخر N شهر - لتحليل استقرار الإيراد المتكرر (Revenue Retention).
     * نرجّع صفًا لكل شهر فيه سجلات فقط (الشهر اللي مفيش فيه سجلات بيختفي
     * من الناتج = إشارة "gap" قابلة للاكتشاف في الـRetentionService).
     */
    public function getMonthlyRevenueSeries(int $userId, int $months = 6, ?string $source = null): array
    {
        if($this->getRevenueTotals($userId,date('Y-m-01',strtotime('-'.max(1,$months).' months')),date('Y-m-d H:i:s'))['mixed_currency'])return [];
        $params = [$userId];
        $sourceFilter = '';
        if ($source !== null) {
            $sourceFilter = ' AND source = ?';
            $params[] = $source;
        }
        return $this->db->query(
            "SELECT DATE_FORMAT(recorded_at, '%Y-%m') AS month, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS count
             FROM rev_revenue_records
             WHERE user_id = ? AND recorded_at >= DATE_SUB(CURDATE(), INTERVAL ? MONTH) {$sourceFilter}
             GROUP BY month ORDER BY month ASC",
            [$userId, max(1, $months), ...array_slice($params, 1)]
        );
    }

    // ============================================================
    // Marketing Spend (rev_marketing_spend + ad_campaigns) - BATCH6
    // ============================================================

    public function getMarketingSpendTotal(int $userId, string $fromDate, string $toDate): ?float
    {
        $pdo=$this->db->getConnection();
        try{
            // Existing manual spend records do not carry a currency. They cannot support a valid ROAS.
            $q=$pdo->prepare('SELECT COUNT(*) FROM rev_marketing_spend WHERE user_id=? AND spend_date>=? AND spend_date<? AND amount<>0');$q->execute([$userId,$fromDate,$toDate]);if($q->fetchColumn())return null;
            $q=$pdo->prepare('SELECT c.currency,SUM(r.spend) total FROM ad_performance_reports r JOIN ad_campaigns c ON c.id=r.campaign_id WHERE c.user_id=? AND r.date_start>=? AND r.date_start<? GROUP BY c.currency');$q->execute([$userId,substr($fromDate,0,10),substr($toDate,0,10)]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
            if(!$rows)return 0.0;if(count($rows)!==1||empty($rows[0]['currency']))return null;
            $revenue=$this->getRevenueTotals($userId,$fromDate,$toDate);if($revenue['mixed_currency']||($revenue['currency']&&$revenue['currency']!==$rows[0]['currency']))return null;
            return (float)$rows[0]['total'];
        }catch(Throwable $e){return null;}
    }

    public function getMarketingSpendByChannel(int $userId, string $fromDate, string $toDate): array
    {
        if($this->getMarketingSpendTotal($userId,$fromDate,$toDate)===null)return [];
        return $this->db->query(
            "SELECT channel, COALESCE(SUM(amount), 0) AS total
             FROM rev_marketing_spend WHERE user_id = ? AND spend_date >= ? AND spend_date < ?
             GROUP BY channel ORDER BY total DESC",
            [$userId, $fromDate, $toDate]
        );
    }

    // ============================================================
    // CRM: Contacts / Leads (crm_contacts, crm_leads) - قراءة فقط
    // ============================================================

    public function getContacts(int $userId): array
    {
        return $this->db->query("SELECT * FROM crm_contacts WHERE user_id = ? ORDER BY created_at DESC", [$userId]);
    }

    public function getLeads(int $userId): array
    {
        return $this->db->query(
            "SELECT l.* FROM crm_leads l
             INNER JOIN crm_contacts c ON c.id = l.contact_id
             WHERE c.user_id = ? ORDER BY l.updated_at DESC",
            [$userId]
        );
    }

    // ============================================================
    // CRM: Deals / Pipeline (crm_deals, crm_pipeline_stages) - قراءة فقط
    // ============================================================

    /** كل الصفقات (مفتوحة/مكسوبة/خسرانة) لمستخدم معيّن مع اسم المرحلة. */
    public function getDeals(int $userId, ?string $status = null): array
    {
        $sql = "SELECT d.*, s.name AS stage_name, s.slug AS stage_slug, s.win_probability AS stage_win_probability,
                       s.is_won AS stage_is_won, s.is_lost AS stage_is_lost, c.name AS contact_name, c.email AS contact_email
                FROM crm_deals d
                LEFT JOIN crm_pipeline_stages s ON s.id = d.stage_id
                LEFT JOIN crm_contacts c ON c.id = d.contact_id
                WHERE d.owner_user_id = ?";
        $params = [$userId];
        if ($status !== null) {
            $sql .= " AND d.status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY d.updated_at DESC";
        return $this->db->query($sql, $params);
    }

    /** صفقات مكسوبة (won) ضمن فترة - أقرب "إيراد فعلي مرتبط بعميل" متاح في المشروع حاليًا. */
    public function getWonDealsByContact(int $userId, ?string $fromDate = null, ?string $toDate = null): array
    {
        $sql = "SELECT d.id, d.contact_id, d.value, d.currency, d.closed_at, d.title,
                       c.name AS contact_name, c.email AS contact_email
                FROM crm_deals d
                INNER JOIN crm_contacts c ON c.id = d.contact_id
                WHERE d.owner_user_id = ? AND d.status = 'won'";
        $params = [$userId];
        if ($fromDate !== null && $toDate !== null) {
            $sql .= " AND d.closed_at >= ? AND d.closed_at < ?";
            $params[] = $fromDate;
            $params[] = $toDate;
        }
        $sql .= " ORDER BY d.closed_at ASC";
        return $this->db->query($sql, $params);
    }

    // ============================================================
    // Subscriptions (recurring revenue) - عندما تتوفر
    // ============================================================

    /** هل يوجد جدول اشتراكات فعلي مع مبالغ يمكن اعتبارها Recurring Revenue؟ */
    /** Customer revenue only. A Tourfecto plan is an operating cost, never the customer's MRR. */
    public function getCustomerRecurringRevenue(int $userId): ?array
    {
        try {
            $q=$this->db->getConnection()->prepare("SELECT mrr,currency FROM biz_subscriptions WHERE user_id=? AND status='active' AND (current_period_end IS NULL OR current_period_end>=CURRENT_DATE())");
            $q->execute([$userId]); return $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            if (in_array($e->getCode(),['42S02','42S22'],true)) return null;
            throw $e;
        }
    }

    public function getActiveSubscriptionsRevenue(int $userId): ?array
    {
        try {
            // نفس البنية المؤكدة فعليًا في Subscription::activeSubscriptionRow()
            // (subscriptions.plan_id + current_period_end - ده الشكل الحقيقي
            // على السيرفر المباشر، مختلف عن schema.sql/migrations القديمة في
            // الريبو التي توثّق الكود نفسه أنها غير مطابقة للواقع).
            $rows = $this->db->query(
                "SELECT s.id, s.status, p.price, p.billing_cycle
                 FROM subscriptions s
                 LEFT JOIN subscription_plans p ON p.id = s.plan_id
                 WHERE s.user_id = ? AND s.status = 'active' AND s.current_period_end > NOW()",
                [$userId]
            );
            return $rows;
        } catch (Exception $e) {
            // الجدول ممكن يكون غير موجود أو بأعمدة مختلفة في بعض النشرات - لا نكسر الموديول لأجله.
            return null;
        }
    }

    /** Section 2 (Forecast Accuracy - إضافة): توقعات سابقة انتهت فترتها فعليًا، عشان نقارنها بالإيراد الحقيقي اللي حصل. */
    public function getPastForecasts(int $userId, int $limit = 10): array
    {
        return $this->db->query(
            "SELECT id, period_type, period_start, period_end, expected_revenue, low_estimate, high_estimate, confidence, created_at
             FROM revai_forecasts
             WHERE user_id = ? AND period_end < CURDATE() AND insufficient_data = 0 AND expected_revenue IS NOT NULL
             ORDER BY period_end DESC
             LIMIT ?",
            [$userId, $limit]
        );
    }

    // ============================================================
    // v1.5.0: Biz Subscriptions (biz_subscriptions + biz_subscription_events)
    // ============================================================

    /** كل اشتراكات عملاء العميل (بأمان مع استثناء الجدول الغائب -> []). */
    public function getBizSubscriptions(int $userId): array
    {
        try {
            return $this->db->query(
                "SELECT * FROM biz_subscriptions WHERE user_id = ? ORDER BY started_at DESC",
                [$userId]
            );
        } catch (Exception $e) {
            return [];
        }
    }

    /** أحداث تغيير الاشتراكات (new/expansion/contraction/churn) خلال فترة. */
    public function getBizSubscriptionEvents(int $userId, string $fromDate = '1970-01-01', string $toDate = '9999-12-31'): array
    {
        try {
            return $this->db->query(
                "SELECT * FROM biz_subscription_events
                 WHERE user_id = ? AND occurred_at >= ? AND occurred_at <= ?
                 ORDER BY occurred_at ASC, id ASC",
                [$userId, $fromDate, $toDate]
            );
        } catch (Exception $e) {
            return [];
        }
    }

    /** هل جداول الـBiz Subscriptions موجودة فعلًا (تجنب أخطاء غير ضرورية)؟ */
    public function hasBizSubscriptionTables(): bool
    {
        try {
            $this->db->query("SELECT COUNT(*) AS c FROM biz_subscriptions WHERE 1=0");
            $this->db->query("SELECT COUNT(*) AS c FROM biz_subscription_events WHERE 1=0");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    // ============================================================
    // v1.5.0: Sales Attribution (sales_teams + sales_reps + crm_deals.assigned_rep_id)
    // ============================================================

    /** كل مندوبي البيع مع أسماء فرقهم (Tenant-scoped). */
    public function getSalesReps(int $userId): array
    {
        try {
            return $this->db->query(
                "SELECT r.*, t.name AS team_name
                 FROM sales_reps r
                 LEFT JOIN sales_teams t ON t.id = r.team_id
                 WHERE r.user_id = ? ORDER BY r.name ASC",
                [$userId]
            );
        } catch (Exception $e) {
            return [];
        }
    }

    /** فرق البيع. */
    public function getSalesTeams(int $userId): array
    {
        try {
            return $this->db->query(
                "SELECT * FROM sales_teams WHERE user_id = ? ORDER BY name ASC",
                [$userId]
            );
        } catch (Exception $e) {
            return [];
        }
    }

    /** الصفقات مع اسم المندوب المكلّف (للتوزيع على المندوب/الفريق). */
    public function getDealsWithRep(int $userId): array
    {
        try {
            return $this->db->query(
                "SELECT d.id, d.title, d.value, d.currency, d.status, d.expected_close_date, d.closed_at,
                        d.probability, d.assigned_rep_id, d.stage_id,
                        r.name AS rep_name, r.team_id, t.name AS team_name
                 FROM crm_deals d
                 LEFT JOIN sales_reps r ON r.id = d.assigned_rep_id AND r.user_id = d.owner_user_id
                 LEFT JOIN sales_teams t ON t.id = r.team_id
                 WHERE d.owner_user_id = ?",
                [$userId]
            );
        } catch (Exception $e) {
            return [];
        }
    }

    // ============================================================
    // v1.5.0: Benchmarks (revai_benchmarks - مشتقة من بيانات المنصة)
    // ============================================================

    /** هل جدول benchmarks مثبت؟ */
    public function hasBenchmarkTables(): bool
    {
        try {
            $this->db->query("SELECT 1 FROM revai_benchmarks LIMIT 1");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /** أحدث صفوف benchmarks المنصية (أحدث as_of_date) - لا تحتوي user_id (بيانات مجهولة). */
    public function getPlatformBenchmarks(): array
    {
        try {
            $rows = $this->db->query(
                "SELECT metric_key, metric_label, p25, p50, p75, basis, sample_size, as_of_date
                 FROM revai_benchmarks
                 WHERE as_of_date = (SELECT MAX(as_of_date) FROM revai_benchmarks)
                 ORDER BY metric_key ASC"
            );
            return $rows ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    /** أحدث قيم الـBenchmark المتاحة لمجموعة مقاييس، وعدد الحسابات الداخلة. */
    public function getBenchmarkRow(string $metricKey, ?string $asOfDate = null): ?array
    {
        try {
            $sql = "SELECT * FROM revai_benchmarks WHERE metric_key = ?";
            $params = [$metricKey];
            if ($asOfDate !== null) {
                $sql .= " AND as_of_date = ?";
                $params[] = $asOfDate;
            } else {
                $sql .= " ORDER BY as_of_date DESC";
            }
            $sql .= " LIMIT 1";
            $rows = $this->db->query($sql, $params);
            return $rows[0] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }

    /** كل المقياس المتاحة (للواجهة). */
    public function getBenchmarkRows(string $asOfDate): array
    {
        try {
            return $this->db->query(
                "SELECT * FROM revai_benchmarks WHERE as_of_date = ? ORDER BY metric_key ASC",
                [$asOfDate]
            );
        } catch (Exception $e) {
            return [];
        }
    }

    // ============================================================
    // v1.6.0: Dashboard Prefs (revai_dashboard_prefs)
    // ============================================================

    public function getDashboardPrefs(int $userId): ?array
    {
        try {
            $rows = $this->db->query("SELECT * FROM revai_dashboard_prefs WHERE user_id = ? LIMIT 1", [$userId]);
            return $rows[0] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }

    public function upsertDashboardPrefs(int $userId, array $layout): void
    {
        try {
            $this->db->query(
                "INSERT INTO revai_dashboard_prefs (user_id, layout)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE layout = VALUES(layout)",
                [$userId, json_encode($layout, JSON_UNESCAPED_UNICODE)]
            );
        } catch (Exception $e) {
            // إضافة فقط - لا يكسر الداشبورد لو الجدول غير مثبت
        }
    }

    public function deleteDashboardPrefs(int $userId): void
    {
        try {
            $this->db->query("DELETE FROM revai_dashboard_prefs WHERE user_id = ?", [$userId]);
        } catch (Exception $e) {
            // no-op
        }
    }

    // ============================================================
    // v1.6.0: Stripe Settings (revai_stripe_settings) - secrets encrypted
    // ============================================================

    public function getStripeSettings(int $userId): ?array
    {
        try {
            $rows = $this->db->query(
                "SELECT id, user_id, webhook_secret_enc, connected_account_id, mode, is_enabled, last_event_at, last_event_type
                 FROM revai_stripe_settings WHERE user_id = ? LIMIT 1",
                [$userId]
            );
            return $rows[0] ?? null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * حفظ إعدادات Stripe. webhook_secret يُمرَّر مشفرًا مسبقًا (Encryption::encrypt)
     * ولا يُحفظ نص صريح أبدًا.
     */
    public function upsertStripeSettings(int $userId, array $data): void
    {
        try {
            $secret = (string) ($data['webhook_secret_enc'] ?? '');
            $account = (string) ($data['connected_account_id'] ?? '');
            $mode = in_array($data['mode'] ?? 'test', ['test', 'live'], true) ? $data['mode'] : 'test';
            $enabled = !empty($data['is_enabled']) ? 1 : 0;

            // التحقق من الفرق في السر: لو قيمته فاضية نأخذ القديمة كما هي
            // (تحديث جزئي لا يمسح السر المخزّن).
            $existing = $this->getStripeSettings($userId);
            if ($secret === '' && $existing !== null) {
                $secret = (string) ($existing['webhook_secret_enc'] ?? '');
            }

            $this->db->query(
                "INSERT INTO revai_stripe_settings (user_id, webhook_secret_enc, connected_account_id, mode, is_enabled)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    webhook_secret_enc = VALUES(webhook_secret_enc),
                    connected_account_id = VALUES(connected_account_id),
                    mode = VALUES(mode),
                    is_enabled = VALUES(is_enabled)",
                [$userId, $secret, $account, $mode, $enabled]
            );
        } catch (Exception $e) {
            // no-op على النشرات القديمة
        }
    }

    // ============================================================
    // v1.6.0: Stripe Webhook ingestion (idempotent)
    // ============================================================

    /** هل حدث Stripe ده اتستلم من قبل لهذا المستخدم؟ (Idempotency) */
    public function stripeEventExists(int $userId, string $eventId): bool
    {
        try {
            $rows = $this->db->query(
                "SELECT 1 FROM revai_stripe_events WHERE user_id = ? AND stripe_event_id = ? LIMIT 1",
                [$userId, $eventId]
            );
            return !empty($rows);
        } catch (Exception $e) {
            return false;
        }
    }

    /** تسجيل حدث مستلم (للـ idempotency + audit). */
    public function touchStripeEvent(int $userId, string $eventId, string $type, string $status = 'processed'): void
    {
        try {
            $this->db->query(
                "INSERT IGNORE INTO revai_stripe_events (user_id, stripe_event_id, event_type, status, received_at)
                 VALUES (?, ?, ?, ?, NOW())",
                [$userId, $eventId, $type, $status]
            );
            $this->db->query(
                "UPDATE revai_stripe_settings
                 SET last_event_at = NOW(), last_event_type = ?
                 WHERE user_id = ?",
                [$type, $userId]
            );
        } catch (Exception $e) {
            // no-op
        }
    }

    /** إدراج اشتراك من حدث Stripe (upsert على stripe_subscription_id). */
    public function upsertBizSubscriptionFromStripe(int $userId, array $row, ?string $forceStatus = null): void
    {
        try {
            $stripeSubId = (string) ($row['stripe_subscription_id'] ?? '');
            if ($stripeSubId === '') {
                return;
            }
            $status = $forceStatus ?? (string) ($row['status'] ?? 'active');
            if (!in_array($status, ['active', 'trialing', 'past_due', 'cancelled', 'expired'], true)) {
                $status = 'active';
            }
            $started = (string) ($row['current_period_start'] ?? '');
            $periodEnd = (string) ($row['current_period_end'] ?? '');
            $this->db->query(
                "INSERT INTO biz_subscriptions
                    (user_id, stripe_subscription_id, customer_name, customer_email, plan_name, status, billing_cycle, mrr, currency, started_at, current_period_end, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    customer_name = VALUES(customer_name),
                    customer_email = VALUES(customer_email),
                    plan_name = VALUES(plan_name),
                    status = VALUES(status),
                    billing_cycle = VALUES(billing_cycle),
                    mrr = VALUES(mrr),
                    currency = VALUES(currency),
                    current_period_end = VALUES(current_period_end),
                    updated_at = NOW()",
                [
                    $userId,
                    $stripeSubId,
                    (string) ($row['customer_name'] ?? ''),
                    (string) ($row['customer_email'] ?? ''),
                    (string) ($row['plan_name'] ?? ''),
                    $status,
                    in_array($row['billing_cycle'] ?? 'monthly', ['monthly', 'quarterly', 'yearly'], true) ? $row['billing_cycle'] : 'monthly',
                    (float) ($row['mrr'] ?? 0),
                    (string) ($row['currency'] ?? 'USD'),
                    $started !== '' ? substr($started, 0, 10) : gmdate('Y-m-d'),
                    $periodEnd !== '' ? substr($periodEnd, 0, 10) : null,
                ]
            );
        } catch (Exception $e) {
            // no-op على النشرات القديمة
        }
    }

    /** إدراج حدث تغيير MRR من Stripe. */
    public function insertBizSubscriptionEvent(int $userId, array $row): void
    {
        try {
            $type = in_array($row['event_type'] ?? 'new', ['new', 'expansion', 'contraction', 'churn'], true) ? $row['event_type'] : 'new';
            $mrrDelta = (float) ($row['mrr_delta'] ?? 0);
            $occurred = (string) ($row['occurred_at'] ?? gmdate('Y-m-d H:i:s'));

            // نحدد الاشتراك المرتبط: الأولوية للـ stripe_subscription_id إن وجد
            // (يضمن إسناد الحدث للاشتراك الصحيح)، وإلا آخر اشتراك للمستخدم.
            $stripeSubId = (string) ($row['stripe_subscription_id'] ?? '');
            $subId = 0;
            if ($stripeSubId !== '') {
                $subs = $this->db->query(
                    "SELECT id FROM biz_subscriptions WHERE user_id = ? AND stripe_subscription_id = ? ORDER BY id DESC LIMIT 1",
                    [$userId, $stripeSubId]
                );
                $subId = (int) ($subs[0]['id'] ?? 0);
            }
            if ($subId <= 0) {
                $subs = $this->db->query(
                    "SELECT id FROM biz_subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 1",
                    [$userId]
                );
                $subId = (int) ($subs[0]['id'] ?? 0);
            }
            if ($subId <= 0) {
                return; // لا اشتراك -> لا حدث (لا نضيع بيانات، ننتظر إدراج الاشتراك أولًا)
            }

            $this->db->query(
                "INSERT INTO biz_subscription_events
                    (user_id, subscription_id, event_type, mrr_delta, mrr_after, occurred_at, notes, created_at)
                 VALUES (?, ?, ?, ?, 0, ?, ?, NOW())",
                [$userId, $subId, $type, $mrrDelta, $occurred, (string) ($row['description'] ?? '')]
            );
        } catch (Exception $e) {
            // no-op
        }
    }

    public function hasComparableCurrency(int $userId): bool{
        $pdo=$this->db->getConnection();
        try{$q=$pdo->prepare("SELECT COUNT(DISTINCT currency) n,SUM(currency IS NULL OR currency='') unknown FROM (SELECT currency FROM rev_revenue_records WHERE user_id=? UNION ALL SELECT currency FROM crm_deals WHERE owner_user_id=?) x");$q->execute([$userId,$userId]);$r=$q->fetch(PDO::FETCH_ASSOC);return (int)$r['n']<=1&&(int)$r['unknown']===0;}catch(Throwable $e){return false;}
    }

}
