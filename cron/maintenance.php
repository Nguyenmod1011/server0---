#!/usr/bin/env php
<?php
// ============================================================
// cron/maintenance.php – Chạy định kỳ qua Crontab
// ============================================================
// Crontab setup (chạy mỗi ngày lúc 2:00 sáng):
//   0 2 * * * /usr/bin/php /var/www/html/cron/maintenance.php >> /var/log/license_cron.log 2>&1
//
// Test thủ công:
//   php cron/maintenance.php
// ============================================================

define('CRON_MODE', true);
require_once dirname(__DIR__) . '/config.php';

$db    = getDB();
$start = microtime(true);
$log   = [];

function cronLog(string $msg): void {
    global $log;
    $line  = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    $log[] = $line;
    echo $line . PHP_EOL;
}

cronLog('=== License Maintenance Start ===');

// ── 1. Dọn logs cũ hơn 90 ngày ────────────────────────────
$deleted = $db->exec("DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
cronLog("Đã xóa $deleted log cũ (>90 ngày)");

// ── 2. Dọn device_key_cache của key đã hết hạn hoặc bị xóa ─
$deleted = $db->exec("
    DELETE dkc FROM device_key_cache dkc
    LEFT JOIN license_keys lk ON dkc.key_id = lk.id
    WHERE lk.id IS NULL
       OR (lk.is_used = 1 AND lk.expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY))
       OR lk.is_active = 0
");
cronLog("Đã xóa $deleted device cache hết hạn");

// ── 3. Dọn device_bindings của key không còn tồn tại ────────
$deleted = $db->exec("
    DELETE db FROM device_bindings db
    LEFT JOIN license_keys lk ON db.key_id = lk.id
    WHERE lk.id IS NULL
");
cronLog("Đã xóa $deleted orphan device bindings");

// ── 4. Thống kê key sắp hết hạn trong 3 ngày ──────────────
$expiringSoon = $db->query("
    SELECT license_key, expires_at, COUNT(db.id) as devices
    FROM license_keys lk
    LEFT JOIN device_bindings db ON lk.id = db.key_id
    WHERE lk.is_active = 1 AND lk.is_used = 1
      AND lk.expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY)
    GROUP BY lk.id
")->fetchAll();

if (!empty($expiringSoon)) {
    cronLog("⚠️  " . count($expiringSoon) . " key sắp hết hạn (3 ngày):");
    foreach ($expiringSoon as $k) {
        cronLog("   → {$k['license_key']} | hết: {$k['expires_at']} | {$k['devices']} thiết bị");
    }
}

// ── 5. Thống kê tổng ───────────────────────────────────────
$stats = [
    'total'    => $db->query("SELECT COUNT(*) FROM license_keys")->fetchColumn(),
    'active'   => $db->query("SELECT COUNT(*) FROM license_keys WHERE is_active=1 AND is_used=1 AND expires_at > NOW()")->fetchColumn(),
    'expired'  => $db->query("SELECT COUNT(*) FROM license_keys WHERE is_used=1 AND expires_at < NOW()")->fetchColumn(),
    'unused'   => $db->query("SELECT COUNT(*) FROM license_keys WHERE is_used=0")->fetchColumn(),
    'devices'  => $db->query("SELECT COUNT(*) FROM device_bindings")->fetchColumn(),
    'resellers'=> $db->query("SELECT COUNT(*) FROM resellers WHERE is_active=1")->fetchColumn(),
];

cronLog("📊 Stats: Total={$stats['total']} Active={$stats['active']} Expired={$stats['expired']} Unused={$stats['unused']} Devices={$stats['devices']} Resellers={$stats['resellers']}");

// ── 6. Ghi log hệ thống ────────────────────────────────────
writeLog($db, 'cron_maintenance', 'Maintenance hoàn tất. Xóa: logs=' . $deleted . ' caches/bindings', 'system', 0);

$elapsed = round((microtime(true) - $start) * 1000);
cronLog("=== Done in {$elapsed}ms ===");
