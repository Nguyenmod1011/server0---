<?php
// ============================================================
// admin/export_keys.php – Xuất keys ra CSV hoặc TXT
// ============================================================
define('IN_ADMIN', true);
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/auth.php';

$db     = getDB();
$format = $_GET['format'] ?? 'csv';  // csv | txt | json
$filter = $_GET['filter'] ?? 'all';
$resId  = currentResellerId();

// Điều kiện lọc
$where  = isAdmin() ? '1=1' : 'lk.created_by_reseller = ' . (int)$resId;
$filterWhere = match($filter) {
    'active'   => " AND lk.is_active=1 AND lk.is_used=1 AND lk.expires_at > NOW()",
    'expired'  => " AND lk.is_used=1 AND lk.expires_at < NOW()",
    'unused'   => " AND lk.is_used=0",
    'disabled' => " AND lk.is_active=0",
    default    => '',
};

$keys = $db->query("
    SELECT lk.license_key, lk.duration_days, lk.max_devices,
           lk.is_active, lk.is_used, lk.activated_at, lk.expires_at,
           lk.note, lk.created_at,
           COUNT(db.id) as device_count,
           COALESCE(a.username, r.username, 'system') as creator
    FROM license_keys lk
    LEFT JOIN device_bindings db ON lk.id = db.key_id
    LEFT JOIN admin_users a ON lk.created_by_admin = a.id
    LEFT JOIN resellers   r ON lk.created_by_reseller = r.id
    WHERE $where $filterWhere
    GROUP BY lk.id
    ORDER BY lk.created_at DESC
")->fetchAll();

$filename = 'keys_' . $filter . '_' . date('Ymd_His');

// ── CSV ────────────────────────────────────────────────────
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}.csv\"");
    $out = fopen('php://output', 'w');
    // BOM cho Excel đọc được tiếng Việt
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['License Key','Thời hạn (ngày)','Max TB','Kích hoạt','Hết hạn','Trạng thái','Thiết bị','Tạo bởi','Ghi chú','Ngày tạo']);
    foreach ($keys as $k) {
        $status = !$k['is_active'] ? 'Vô hiệu' : (!$k['is_used'] ? 'Chưa kích hoạt' : (strtotime($k['expires_at']) < time() ? 'Hết hạn' : 'Hoạt động'));
        fputcsv($out, [
            $k['license_key'],
            $k['duration_days'],
            $k['max_devices'],
            $k['activated_at'] ?? '',
            $k['expires_at'] ?? '',
            $status,
            $k['device_count'] . '/' . $k['max_devices'],
            $k['creator'],
            $k['note'] ?? '',
            $k['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

// ── TXT (chỉ key, mỗi dòng 1 key – dễ copy) ───────────────
if ($format === 'txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}.txt\"");
    foreach ($keys as $k) {
        echo $k['license_key'] . "\n";
    }
    exit;
}

// ── JSON ───────────────────────────────────────────────────
if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}.json\"");
    $out = array_map(fn($k) => [
        'key'          => $k['license_key'],
        'duration_days'=> $k['duration_days'],
        'max_devices'  => $k['max_devices'],
        'is_active'    => (bool)$k['is_active'],
        'is_used'      => (bool)$k['is_used'],
        'activated_at' => $k['activated_at'],
        'expires_at'   => $k['expires_at'],
        'device_count' => $k['device_count'],
        'creator'      => $k['creator'],
        'note'         => $k['note'],
        'created_at'   => $k['created_at'],
    ], $keys);
    echo json_encode(['total' => count($out), 'filter' => $filter, 'keys' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Fallback
header('Location: keys.php');
exit;
