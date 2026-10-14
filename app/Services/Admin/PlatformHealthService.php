<?php

/**
 * Tourfecto - صحة المنصة (مكان واحد لكل حاجة ممكن توقف المنصة من غير ما حد ياخد باله)
 *
 * الأقسام: الكرونات (المنظّم + كل مهمة)، التكاملات، البريد، طابور المهام،
 * المدفوعات، والسيرفر. كل فحص: ok / warn / fail + رسالة + الخطوة المطلوبة.
 * بيستخدمها: صفحة الأدمن ← صحة المنصة، و AdminAlertService، وصفحة الحالة العامة.
 *
 * @version 1.0.0
 */
class PlatformHealthService
{
    /** @var Database */
    private $db;
    /** @var string */
    private $root;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?: Database::getInstance();
        $this->root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3);
    }

    public function report(): array
    {
        $sections = [
            'cron' => $this->cron(),
            'operations_delivery' => $this->operationsDelivery(),
            'integrations' => $this->integrations(),
            'mail' => $this->mail(),
            'queue' => $this->queue(),
            'payments' => $this->payments(),
            'errors' => $this->errors(),
            'server' => $this->server(),
            'database' => $this->schema(),
        ];
        $counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($sections as $s) {
            foreach ($s['checks'] as $c) {
                $counts[$c['status']] = ($counts[$c['status']] ?? 0) + 1;
            }
        }
        $overall = $counts['fail'] > 0 ? 'fail' : ($counts['warn'] > 0 ? 'warn' : 'ok');
        return ['overall' => $overall, 'counts' => $counts, 'sections' => $sections, 'generated_at' => date('Y-m-d H:i:s')];
    }

    public function operationsDelivery(): array
    {
        $out=RuntimeHealthService::operationalChecks($this->db);$checks=[];
        $scheduler=$out['scheduler']['status'];
        $checks[]=$this->c('scheduler_runtime','تشغيل المنظّم',$scheduler==='ok'?'ok':'fail',$scheduler==='ok'?'آخر نبضة تشغيل حديثة':'المنظّم يحتاج مراجعة تشغيل خارجية','راجع إعداد Cron؛ إذا توقف المنظّم وحارس الأدمن معًا يلزم مراقب خارجي لاكتشاف ذلك.');
        foreach ($out['scheduled_tasks'] as $key=>$task) {
            $checks[]=$this->c($key==='cleanup_exports'?'export_retention':'trip_reminder_worker',$key==='cleanup_exports'?'تنظيف التصديرات':'عامل تذكير الرحلات',$task['status']==='ok'?'ok':($task['status']==='failed'?'fail':'warn'),'الحالة: '.$task['status'],'راجع تشغيل المهمة وسجلها الخاص؛ لا تعِد إرسال تذكير غامض قبل التأكد.');
        }
        $delivery=$out['reminder_delivery'];
        $status=$delivery['status']==='unavailable'?'warn':(((int)$delivery['needs_review']+(int)$delivery['exhausted'])>0?'warn':'ok');
        $message=$delivery['status']==='unavailable'?'تعذر قراءة حالات التسليم':('تسليم غير مؤكد: '.$delivery['needs_review'].'؛ محاولات انتهت: '.$delivery['exhausted']);
        $checks[]=$this->c('reminder_delivery','تسليم تذكيرات الرحلات',$status,$message,'راجع مركز العمليات وحالة التسليم لدى المزوّد.');
        return ['title'=>'تشغيل العمليات والتسليم','checks'=>$checks];
    }

    // ------------------------------------------------------------------ الكرونات

    public function cron(): array
    {
        $dir = $this->root . '/storage/cron';
        $checks = [];
        $hb = @file_get_contents($dir . '/heartbeat');
        $hbTs = $hb ? strtotime(trim($hb)) : false;
        $age = $hbTs ? time() - $hbTs : null;
        if ($age === null) {
            $checks[] = $this->c('scheduler', 'المنظّم (سطر الـ Cron)', 'fail', 'ولا مرة اشتغل',
                'ضيف Task في aaPanel ← Cron كل دقيقة تشغّل cron/scheduler.php (الخطوات في دليل الكرون).');
        } elseif ($age > 300) {
            $checks[] = $this->c('scheduler', 'المنظّم (سطر الـ Cron)', 'fail', 'آخر تشغيل من ' . self::ago($age),
                'الـ Task في aaPanel ← Cron وقفت. افتحها ودوس Execute وشوف الـ Log.');
        } else {
            $checks[] = $this->c('scheduler', 'المنظّم (سطر الـ Cron)', 'ok', 'شغّال - آخر دورة من ' . self::ago($age));
        }

        $jobs = [];
        $schedule = is_file($this->root . '/cron/schedule.php') ? (array) (require $this->root . '/cron/schedule.php') : [];
        $state = json_decode((string) @file_get_contents($dir . '/state.json'), true) ?: [];
        $overrides = json_decode((string) @file_get_contents($dir . '/overrides.json'), true) ?: [];
        foreach ($schedule as $key => $job) {
            $enabled = array_key_exists($key, $overrides) ? (bool) $overrides[$key] : (bool) ($job['enabled'] ?? true);
            $s = $state[$key] ?? [];
            $interval = self::intervalMinutes((string) $job['when']);
            $lastStart = $this->cronTs($s['last_start'] ?? null);
            $status = 'ok';
            $msg = '';
            if (!$enabled) {
                $status = 'off';
                $msg = 'موقوف';
            } elseif (!isset($s['last_exit'])) {
                $status = 'pending';
                $msg = 'لسه ماجاش ميعاده';
            } elseif ((int) $s['last_exit'] !== 0) {
                $fails = (int) ($s['fails_in_row'] ?? 1);
                $status = $fails >= 3 ? 'fail' : 'warn';
                $msg = ($s['last_result'] ?? 'فشل') . ($fails > 1 ? " ({$fails} مرات ورا بعض)" : '');
            } elseif ($lastStart && (time() - $lastStart) > ($interval * 60 * 3 + 900)) {
                $status = 'warn';
                $msg = 'ماشتغلش من ' . self::ago(time() - $lastStart) . ' (المفروض كل ' . self::every($interval) . ')';
            } else {
                $msg = 'تمام - ' . ($s['last_seconds'] ?? '?') . ' ثانية';
            }
            $jobs[] = [
                'key' => $key,
                'title' => $job['title'] ?? $key,
                'group' => $job['group'] ?? '',
                'when' => $job['when'],
                'every' => self::every($interval),
                'enabled' => $enabled,
                'status' => $status,
                'message' => $msg,
                // المنظّم بيكتب بتوقيت CRON_TIMEZONE؛ الواجهة بتتوقع UTC زي باقي التواريخ
                'last_start' => $this->cronTs($s['last_start'] ?? null) ? gmdate('Y-m-d H:i:s', $this->cronTs($s['last_start'])) : null,
                'last_ok' => $this->cronTs($s['last_ok'] ?? null) ? gmdate('Y-m-d H:i:s', $this->cronTs($s['last_ok'])) : null,
                'runs' => (int) ($s['runs'] ?? 0),
                'fails_in_row' => (int) ($s['fails_in_row'] ?? 0),
            ];
        }
        $failing = array_values(array_filter($jobs, fn ($j) => $j['status'] === 'fail'));
        $warn = array_values(array_filter($jobs, fn ($j) => $j['status'] === 'warn'));
        if ($schedule) {
            if ($failing) {
                $checks[] = $this->c('cron_jobs', 'المهام المجدولة', 'fail', count($failing) . ' مهمة بتفشل: ' . implode('، ', array_column($failing, 'title')),
                    'افتح storage/logs/cron/<اسم المهمة>.log وشوف آخر الغلط.');
            } elseif ($warn) {
                $checks[] = $this->c('cron_jobs', 'المهام المجدولة', 'warn', count($warn) . ' مهمة محتاجة نظرة: ' . implode('، ', array_column($warn, 'title')));
            } else {
                $checks[] = $this->c('cron_jobs', 'المهام المجدولة', 'ok', count(array_filter($jobs, fn ($j) => $j['enabled'])) . ' مهمة شغالة');
            }
        }
        return ['title' => 'الكرونات', 'checks' => $checks, 'jobs' => $jobs];
    }

    // ------------------------------------------------------------------ التكاملات

    public function integrations(): array
    {
        $checks = [];
        $items = [];
        $registryFile = (defined('APP_PATH') ? APP_PATH : $this->root . '/app') . '/Config/integrations.php';
        $registry = is_file($registryFile) ? (array) (require $registryFile) : [];
        $last = [];
        try {
            foreach ($this->db->query('SELECT * FROM integration_checks') as $r) {
                $last[$r['integration_key']] = $r;
            }
        } catch (Throwable $e) {
        }
        foreach ($registry as $key => $meta) {
            $configured = false;
            try {
                $configured = class_exists('IntegrationManager') && IntegrationManager::isConfigured($key);
            } catch (Throwable $e) {
            }
            $chk = $last[$key] ?? null;
            $items[] = [
                'key' => $key,
                'label' => $meta['label'] ?? $key,
                'importance' => $meta['importance'] ?? 'optional',
                'configured' => $configured,
                'last_check_ok' => $chk ? (bool) $chk['ok'] : null,
                'last_check_message' => $chk['message'] ?? null,
                'last_checked_at' => $chk['checked_at'] ?? null,
                'fails_in_row' => (int) ($chk['fails_in_row'] ?? 0),
            ];
        }
        $missingRequired = array_values(array_filter($items, fn ($i) => $i['importance'] === 'required' && !$i['configured']));
        $broken = array_values(array_filter($items, fn ($i) => $i['configured'] && $i['last_check_ok'] === false));
        if ($missingRequired) {
            $checks[] = $this->c('integrations_required', 'التكاملات الأساسية', 'fail', 'ناقص: ' . implode('، ', array_column($missingRequired, 'label')),
                'الأدمن ← التكاملات: حط مفاتيحهم ودوس اختبار.');
        } else {
            $checks[] = $this->c('integrations_required', 'التكاملات الأساسية', 'ok', 'كلها متظبطة');
        }
        if ($broken) {
            $checks[] = $this->c('integrations_broken', 'اختبار التكاملات', 'fail', 'بيفشل: ' . implode('، ', array_column($broken, 'label')),
                'افتح التكامل ودوس "اختبار الاتصال" عشان تشوف السبب.');
        } elseif ($last) {
            $checks[] = $this->c('integrations_broken', 'اختبار التكاملات', 'ok', 'آخر اختبار تلقائي نجح لكل التكاملات المتظبطة');
        } else {
            $checks[] = $this->c('integrations_broken', 'اختبار التكاملات', 'warn', 'لسه مفيش اختبار تلقائي اتعمل',
                'بيتعمل كل 6 ساعات من مهمة admin_watchdog (لازم المنظّم يكون شغّال).');
        }
        return ['title' => 'التكاملات', 'checks' => $checks, 'items' => $items];
    }

    // ------------------------------------------------------------------ البريد

    public function mail(): array
    {
        $checks = [];
        $configured = false;
        try {
            $configured = class_exists('Mailer') && (new Mailer())->isConfigured();
        } catch (Throwable $e) {
        }
        if (!$configured) {
            $checks[] = $this->c('mail_config', 'إعداد البريد', 'fail', 'البريد مش متظبط - مفيش أي إيميل بيطلع من المنصة',
                'الأدمن ← التكاملات ← البريد (SMTP).');
        } else {
            $checks[] = $this->c('mail_config', 'إعداد البريد', 'ok', 'متظبط');
        }
        $s = MailLog::stats(24);
        if (!empty($s['unavailable'])) {
            $checks[] = $this->c('mail_delivery', 'الإرسال (آخر 24 ساعة)', 'warn', 'سجل البريد مش متاح', 'شغّل ملف قاعدة البيانات 2026_10_08_000003_admin_insights.sql');
        } elseif ($s['total'] === 0) {
            $checks[] = $this->c('mail_delivery', 'الإرسال (آخر 24 ساعة)', 'ok', 'مفيش رسايل اتبعتت');
        } else {
            $rate = $s['failed'] / max(1, $s['total']);
            $status = $s['failed'] === 0 ? 'ok' : ($rate >= 0.2 || $s['failed'] >= 5 ? 'fail' : 'warn');
            $checks[] = $this->c('mail_delivery', 'الإرسال (آخر 24 ساعة)', $status,
                "{$s['sent']} اتبعتت · {$s['failed']} فشلت" . ($s['failed'] && $s['last_error'] ? ' - آخر غلط: ' . mb_substr($s['last_error'], 0, 140) : ''),
                $s['failed'] ? 'افتح سجل البريد وشوف الرسايل اللي فشلت، ودوس اختبار في البريد (SMTP).' : null);
        }
        return ['title' => 'البريد', 'checks' => $checks, 'stats' => $s];
    }

    // ------------------------------------------------------------------ الطابور

    public function queue(): array
    {
        $checks = [];
        try {
            $q = $this->db->query("SELECT COUNT(*) pending, TIMESTAMPDIFF(MINUTE, MIN(available_at), NOW()) AS queue_delay_minutes FROM jobs WHERE status = 'pending' AND available_at <= NOW()")[0] ?? [];
            $pending = (int) ($q['pending'] ?? 0);
            $lag = (int) ($q['queue_delay_minutes'] ?? 0);
            $checks[] = $this->c('queue_lag', 'طابور المهام', $pending === 0 || $lag < 10 ? 'ok' : ($lag > 30 ? 'fail' : 'warn'),
                $pending === 0 ? 'فاضي' : "{$pending} مهمة مستنية - أقدمها من {$lag} دقيقة",
                $lag > 10 ? 'اتأكد إن مهمة queue شغالة في الكرونات.' : null);
            $f = $this->db->query("SELECT SUM(status = 'failed') failed, SUM(status = 'completed') done FROM jobs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")[0] ?? [];
            $failed = (int) ($f['failed'] ?? 0);
            $done = (int) ($f['done'] ?? 0);
            $rate = ($failed + $done) > 0 ? $failed / ($failed + $done) : 0;
            $checks[] = $this->c('queue_failures', 'مهام فشلت (24 ساعة)', $failed === 0 ? 'ok' : ($rate >= 0.2 ? 'fail' : 'warn'),
                $failed === 0 ? 'مفيش' : "{$failed} من " . ($failed + $done), $failed ? 'الأدمن ← المهام الخلفية.' : null);
        } catch (Throwable $e) {
            $checks[] = $this->c('queue_lag', 'طابور المهام', 'warn', 'تعذر القراءة');
        }
        return ['title' => 'طابور المهام', 'checks' => $checks];
    }

    // ------------------------------------------------------------------ المدفوعات

    public function payments(): array
    {
        $checks = [];
        try {
            $failed = (int) ($this->db->query("SELECT COUNT(*) v FROM payment_transactions WHERE status IN ('failed','declined','error') AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")[0]['v'] ?? 0);
            $checks[] = $this->c('payments_failed', 'مدفوعات فشلت (24 ساعة)', $failed === 0 ? 'ok' : ($failed >= 5 ? 'fail' : 'warn'),
                $failed === 0 ? 'مفيش' : "{$failed} عملية", $failed ? 'الأدمن ← الدفع والكوبونات.' : null);
            $pastDue = (int) ($this->db->query("SELECT COUNT(*) v FROM subscriptions WHERE status = 'past_due'")[0]['v'] ?? 0);
            $checks[] = $this->c('past_due', 'اشتراكات متأخرة الدفع', $pastDue === 0 ? 'ok' : 'warn',
                $pastDue === 0 ? 'مفيش' : "{$pastDue} اشتراك في فترة السماح", $pastDue ? 'الأدمن ← الدفع والكوبونات: كلّمهم قبل ما الاشتراك يتلغي.' : null);
        } catch (Throwable $e) {
        }
        return ['title' => 'المدفوعات', 'checks' => $checks];
    }

    // ------------------------------------------------------------------ الأخطاء ورسايل العملاء

    /**
     * 1) أخطاء ملف اللوج آخر ساعة (عشان الأعطال اللي بتحصل بصمت - زي حفظ بيفشل من غير ما حد يعرف).
     * 2) رسايل العملاء اللي فشلت (تذكير الرحلة + ملخص الصبح): السكربت بيخلص "بنجاح" حتى لو
     *    كل الرسايل فشلت، فلازم نعدّ الفشل نفسه.
     */
    public function errors(): array
    {
        $checks = [];
        try {
            $n = (new ErrorLogService($this->root . '/storage/logs'))->errorCount(60);
            $status = $n >= 100 ? 'fail' : ($n >= 20 ? 'warn' : 'ok');
            $checks[] = $this->c('app_errors', 'أخطاء النظام (آخر ساعة)', $status,
                $n === 0 ? 'مفيش' : "{$n} خطأ", $status !== 'ok' ? 'الأدمن ← أخطاء النظام: شوف إيه اللي بيتكرر.' : null);
        } catch (Throwable $e) {
        }

        $failed = 0;
        $sent = 0;
        try {
            $r = $this->db->query(
                "SELECT SUM(reminder_status = 'failed') f, SUM(reminder_status LIKE 'sent%') s
                   FROM bookings WHERE reminder_sent_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
            )[0] ?? [];
            $failed += (int) ($r['f'] ?? 0);
            $sent += (int) ($r['s'] ?? 0);
        } catch (Throwable $e) {
        }
        try {
            $r = $this->db->query(
                "SELECT SUM(last_status LIKE 'failed%') f, SUM(last_status LIKE 'sent%') s
                   FROM morning_brief_settings WHERE last_sent_on >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)"
            )[0] ?? [];
            $failed += (int) ($r['f'] ?? 0);
            $sent += (int) ($r['s'] ?? 0);
        } catch (Throwable $e) {
        }
        if ($failed === 0) {
            $checks[] = $this->c('customer_messages', 'رسايل العملاء (تذكير/ملخص الصبح)', 'ok', $sent ? "{$sent} رسالة اتبعتت آخر 24 ساعة" : 'مفيش رسايل آخر 24 ساعة');
        } elseif ($sent === 0 && $failed >= 3) {
            $checks[] = $this->c('customer_messages', 'رسايل العملاء (تذكير/ملخص الصبح)', 'fail', "كل الرسايل فشلت ({$failed})",
                'غالبًا الواتساب (UltraMsg) أو الإيميل واقع - شوف التكاملات وصفحة الإيميلات.');
        } else {
            $checks[] = $this->c('customer_messages', 'رسايل العملاء (تذكير/ملخص الصبح)', $failed >= 5 ? 'warn' : 'ok',
                "{$failed} فشلت من " . ($failed + $sent) . ' آخر 24 ساعة', $failed >= 5 ? 'غالبًا أرقام غلط أو الواتساب مش متظبط عند بعض العملاء.' : null);
        }
        return ['title' => 'الأخطاء ورسايل العملاء', 'checks' => $checks];
    }

    // ------------------------------------------------------------------ السيرفر

    public function server(): array
    {
        $checks = [];
        $t = microtime(true);
        try {
            $this->db->query('SELECT 1');
            $ms = (int) round((microtime(true) - $t) * 1000);
            $checks[] = $this->c('database', 'قاعدة البيانات', $ms < 150 ? 'ok' : 'warn', $ms . ' ms');
        } catch (Throwable $e) {
            $checks[] = $this->c('database', 'قاعدة البيانات', 'fail', 'مش بترد');
        }
        $storage = $this->root . '/storage';
        $free = @disk_free_space($storage);
        $total = @disk_total_space($storage);
        if ($free !== false && $total) {
            $pct = (int) round(100 * $free / $total);
            $checks[] = $this->c('disk', 'المساحة', $pct > 15 ? 'ok' : ($pct > 7 ? 'warn' : 'fail'), $pct . '% فاضي (' . round($free / 1073741824, 1) . ' GB)',
                $pct <= 15 ? 'امسح اللوجات القديمة والنسخ الاحتياطية من storage.' : null);
        }
        $writable = is_writable($storage);
        $checks[] = $this->c('storage', 'فولدر storage', $writable ? 'ok' : 'fail', $writable ? 'قابل للكتابة' : 'مش قابل للكتابة',
            $writable ? null : 'من Terminal: chown -R www:www storage');
        $checks[] = $this->c('php', 'PHP', version_compare(PHP_VERSION, '8.1', '>=') ? 'ok' : 'warn', PHP_VERSION);
        $envExposed = is_file($this->root . '/public_html/.env');
        if ($envExposed) {
            $checks[] = $this->c('env_exposed', 'ملف .env', 'fail', 'فيه نسخة من .env جوه public_html', 'امسحها فورًا - أي حد يقدر يقراها.');
        }
        return ['title' => 'السيرفر', 'checks' => $checks];
    }

    // ------------------------------------------------------------------ تحديثات قاعدة البيانات

    public function schema(): array
    {
        $checks = [];
        $detail = null;
        try {
            $detail = (new SchemaUpdater())->status();
            $pending = $detail['pending_files'];
            $checks[] = $this->c('migrations', 'ملفات التحديث', $pending ? 'warn' : 'ok',
                $pending ? $pending . ' ملف لسه ماتطبقش' : 'كله متطبق',
                $pending ? 'دوس "طبّق تحديثات قاعدة البيانات" تحت.' : null);
            $en = count($detail['enums']);
            $checks[] = $this->c('enums', 'قيم الأعمدة (ENUM)', $en ? 'warn' : 'ok',
                $en ? $en . ' عمود ناقصه قيم الكود محتاجها (الحفظ ممكن يفشل بـ Data truncated)' : 'متوافقة مع الكود',
                $en ? 'دوس "طبّق تحديثات قاعدة البيانات" تحت.' : null);
        } catch (Throwable $e) {
            $checks[] = $this->c('migrations', 'ملفات التحديث', 'warn', 'مقدرناش نفحص: ' . mb_substr($e->getMessage(), 0, 120));
        }
        return ['title' => 'تحديثات قاعدة البيانات', 'checks' => $checks, 'detail' => $detail];
    }

    // ------------------------------------------------------------------ أدوات

    /** وقت من state.json (مكتوب بتوقيت المنظّم) → timestamp */
    private function cronTs(?string $v): ?int
    {
        if (!$v) {
            return null;
        }
        static $tz = null;
        if ($tz === null) {
            $name = getenv('CRON_TIMEZONE') ?: ($_ENV['CRON_TIMEZONE'] ?? '');
            if ($name === '' && is_file($this->root . '/.env') && preg_match('/^\s*CRON_TIMEZONE\s*=\s*"?([^"\s#]+)/m', (string) @file_get_contents($this->root . '/.env'), $m)) {
                $name = $m[1];
            }
            try {
                $tz = new DateTimeZone($name ?: 'Africa/Cairo');
            } catch (Throwable $e) {
                $tz = new DateTimeZone('Africa/Cairo');
            }
        }
        try {
            return (new DateTimeImmutable($v, $tz))->getTimestamp();
        } catch (Throwable $e) {
            return null;
        }
    }

    private function c(string $key, string $label, string $status, string $message, ?string $action = null): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'message' => $message, 'action' => $action];
    }

    /** تقدير كل قد إيه بتشتغل المهمة بالدقايق من الـ cron expression */
    public static function intervalMinutes(string $expr): int
    {
        $f = preg_split('/\s+/', trim($expr));
        if (count($f) !== 5) {
            return 60;
        }
        [$m, $h, $dom, , $dow] = $f;
        if ($dow !== '*') {
            return 10080;
        }
        if ($dom !== '*') {
            return 43200;
        }
        if ($h !== '*' && strpos($h, '*/') !== 0) {
            return 1440 / max(1, count(explode(',', $h)));
        }
        if (strpos($h, '*/') === 0) {
            return 60 * max(1, (int) substr($h, 2));
        }
        if ($m === '*') {
            return 1;
        }
        if (strpos($m, '*/') === 0) {
            return max(1, (int) substr($m, 2));
        }
        return (int) max(1, 60 / max(1, count(explode(',', $m))));
    }

    public static function every(int $min): string
    {
        if ($min <= 1) {
            return 'دقيقة';
        }
        if ($min < 60) {
            return $min . ' دقيقة';
        }
        if ($min < 1440) {
            return ($min / 60) . ' ساعة';
        }
        if ($min < 10080) {
            return 'يوم';
        }
        return $min >= 43200 ? 'شهر' : 'أسبوع';
    }

    public static function ago(int $sec): string
    {
        if ($sec < 90) {
            return $sec . ' ثانية';
        }
        if ($sec < 5400) {
            return round($sec / 60) . ' دقيقة';
        }
        if ($sec < 172800) {
            return round($sec / 3600) . ' ساعة';
        }
        return round($sec / 86400) . ' يوم';
    }
}
