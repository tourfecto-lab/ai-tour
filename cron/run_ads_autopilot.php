<?php

/**
 * Tourfecto - AI Ads Autopilot: Periodic Scheduler
 * @version 1.0.0
 *
 * بيشغّل AdAutopilotEngine::runForAllUsers() اللي بيقيّم كل حملات العملاء
 * المفعّلين auto_optimize=1 والحالة active، وبيمررها عبر Guardrails
 * (settings) وبينفذ/يسجّل/يحوّل للموافقة حسب وضع كل عميل.
 *
 * Common Settings: مرة كل ساعة كافية لمراجعة دورية معقولة من غير استهلاك
 * مبالغ فيه لـ API calls.
 * Command (غيّر المسار حسب اسم الدومين الحقيقي عندك):
 *   php /home/USERNAME/domains/YOURSITE.com/cron/run_ads_autopilot.php >> /home/USERNAME/domains/YOURSITE.com/storage/logs/ads_autopilot.log 2>&1
 */

require_once __DIR__ . '/bootstrap.php';

$startedAt = microtime(true);

try {
    $engine = new AdAutopilotEngine();
    $summary = $engine->runForAllUsers();

    $durationMs = round((microtime(true) - $startedAt) * 1000);
    fwrite(STDOUT, sprintf(
        "[%s] Ads Autopilot: processed=%d no_action=%d logged=%d pending=%d executed=%d insufficient_data=%d errors=%d (%dms)\n",
        date('Y-m-d H:i:s'),
        $summary['processed'] ?? 0,
        $summary['no_action'] ?? 0,
        $summary['logged'] ?? 0,
        $summary['pending'] ?? 0,
        $summary['executed'] ?? 0,
        $summary['insufficient_data'] ?? 0,
        $summary['errors'] ?? 0,
        $durationMs
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Ads Autopilot error: ' . $e->getMessage() . "\n");
    if (class_exists('Logger')) {
        Logger::error('Ads Autopilot scheduler failed', ['error' => $e->getMessage()]);
    }
    exit(1);
}
