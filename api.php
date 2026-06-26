<?php
// ============================================================
// API.PHP - REST API cho iOS Client
// Endpoint: POST yoursite.com/api.php
// ============================================================
define('API_MODE', true);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Secret, X-Device-Id');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/rate_limiter.php';
require_once __DIR__ . '/includes/telegram.php';

// ── Rate Limiting ──────────────────────────────────────────
$_rl     = new RateLimiter(60, 60);  // 60 req / 60 giây
$_rlIP   = getClientIP();
$_rlChk  = $_rl->check($_rlIP, 'api');
if (!$_rlChk['allowed']) {
    header('Retry-After: ' . ($_rlChk['retry_after'] ?? 60));
    jsonResponse(['status' => 'error', 'message' => 'Too many requests. Thử lại sau ' . ($_rlChk['retry_after'] ?? 60) . 's'], 429);
}
// Cleanup định kỳ (1/100 requests)
if (random_int(1, 100) === 1) $_rl->cleanup();

// ---- Parse input (JSON body hoặc form-data) ----
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$P     = array_merge($_POST, $input);   // merge cả hai

$action    = trim($P['action']     ?? '');
$rawKey    = strtoupper(trim($P['key'] ?? ''));
$deviceId  = trim($P['device_id']  ?? $_SERVER['HTTP_X_DEVICE_ID'] ?? '');
$apiSecret = trim($P['api_secret'] ?? $_SERVER['HTTP_X_API_SECRET'] ?? '');

// ---- Validate API secret ----
if (!hash_equals(API_SECRET, $apiSecret)) {
    jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

// ---- Sanitize device_id ----
$deviceId = preg_replace('/[^a-zA-Z0-9\-_]/', '', $deviceId);
$ip       = getClientIP();
$db       = getDB();

// ---- Router ----
switch ($action) {

    // ----------------------------------------------------------
    // 1. CHECK_KEY – kiểm tra key có hợp lệ không (không kích hoạt)
    // ----------------------------------------------------------
    case 'check_key':
        apiCheckKey($db, $rawKey, $deviceId, $ip);
        break;

    // ----------------------------------------------------------
    // 2. ACTIVATE_KEY – kích hoạt key, bind device
    // ----------------------------------------------------------
    case 'activate_key':
        apiActivateKey($db, $rawKey, $deviceId, $ip, $P);
        break;

    // ----------------------------------------------------------
    // 3. SAVE_KEY – lưu key vào cache theo device (ghi đè khi reinstall)
    // ----------------------------------------------------------
    case 'save_key':
        apiSaveKey($db, $rawKey, $deviceId);
        break;

    // ----------------------------------------------------------
    // 4. GET_SAVED_KEY – lấy key đã lưu theo device_id
    // ----------------------------------------------------------
    case 'get_saved_key':
        apiGetSavedKey($db, $deviceId);
        break;

    // ----------------------------------------------------------
    // 5. PING – heartbeat, cập nhật last_seen
    // ----------------------------------------------------------
    case 'ping':
        apiPing($db, $rawKey, $deviceId, $ip);
        break;

    // ----------------------------------------------------------
    // 6. RESET_DEVICE – xoá binding device (admin yêu cầu)
    // ----------------------------------------------------------
    case 'reset_device':
        apiResetDevice($db, $rawKey, $deviceId);
        break;

    default:
        jsonResponse(['status' => 'error', 'message' => 'Unknown action'], 400);
}

// ============================================================
// FUNCTION: apiCheckKey
// ============================================================
function apiCheckKey(PDO $db, string $key, string $deviceId, string $ip): void
{
    if ($key === '') jsonResponse(['status' => 'error', 'message' => 'Key is required']);

    $row = fetchKey($db, $key);

    if (!$row)               jsonResponse(['status' => 'invalid',  'message' => 'Key không tồn tại']);
    if (!$row['is_active'])  jsonResponse(['status' => 'banned',   'message' => 'Key đã bị vô hiệu hoá']);

    if ($row['is_used']) {
        // Đã kích hoạt → kiểm tra hết hạn
        if (strtotime($row['expires_at']) < time()) {
            jsonResponse(['status' => 'expired', 'message' => 'Key đã hết hạn',
                'expired_at' => $row['expires_at']]);
        }

        // Kiểm tra thiết bị nếu có device_id
        $deviceStatus = 'unknown';
        if ($deviceId) {
            $bound = isBound($db, $row['id'], $deviceId);
            $deviceStatus = $bound ? 'bound' : 'unbound';
        }

        jsonResponse([
            'status'         => 'valid',
            'message'        => 'Key hợp lệ',
            'expires_at'     => $row['expires_at'],
            'days_remaining' => daysRemaining($row['expires_at']),
            'max_devices'    => $row['max_devices'],
            'device_count'   => deviceCount($db, $row['id']),
            'device_status'  => $deviceStatus,
        ]);
    }

    // Chưa kích hoạt
    jsonResponse([
        'status'        => 'not_activated',
        'message'       => 'Key chưa kích hoạt',
        'duration_days' => $row['duration_days'],
    ]);
}

// ============================================================
// FUNCTION: apiActivateKey
// ============================================================
function apiActivateKey(PDO $db, string $key, string $deviceId, string $ip, array $P): void
{
    if ($key === '')      jsonResponse(['status' => 'error', 'message' => 'Key là bắt buộc']);
    if ($deviceId === '') jsonResponse(['status' => 'error', 'message' => 'Device ID là bắt buộc']);

    $row = fetchKey($db, $key);

    if (!$row)               jsonResponse(['status' => 'invalid', 'message' => 'Key không tồn tại']);
    if (!$row['is_active'])  jsonResponse(['status' => 'banned',  'message' => 'Key đã bị vô hiệu hoá']);

    $now     = date('Y-m-d H:i:s');
    $expires = date('Y-m-d H:i:s', strtotime("+{$row['duration_days']} days"));

    if ($row['is_used']) {
        // Key đã được kích hoạt trước đó
        if (strtotime($row['expires_at']) < time()) {
            jsonResponse(['status' => 'expired', 'message' => 'Key đã hết hạn']);
        }

        // Thiết bị này đã bind chưa?
        if (isBound($db, $row['id'], $deviceId)) {
            // Đã bind → cập nhật last_seen và trả về hợp lệ
            $db->prepare("UPDATE device_bindings SET last_seen=NOW(), ip_address=? WHERE key_id=? AND device_id=?")
               ->execute([$ip, $row['id'], $deviceId]);
            updateCache($db, $deviceId, $row['id'], $key);
            jsonResponse([
                'status'         => 'valid',
                'message'        => 'Thiết bị đã đăng ký, key hợp lệ',
                'expires_at'     => $row['expires_at'],
                'days_remaining' => daysRemaining($row['expires_at']),
            ]);
        }

        // Chưa bind → kiểm tra giới hạn thiết bị
        $cnt = deviceCount($db, $row['id']);
        if ($cnt >= $row['max_devices']) {
            jsonResponse([
                'status'      => 'max_devices',
                'message'     => "Key đã đạt giới hạn {$row['max_devices']} thiết bị",
                'max_devices' => $row['max_devices'],
                'used'        => $cnt,
            ]);
        }

        // Bind thiết bị mới
        bindDevice($db, $row['id'], $deviceId, $ip, $P);
        updateCache($db, $deviceId, $row['id'], $key);
        jsonResponse([
            'status'         => 'valid',
            'message'        => 'Thiết bị mới đã được thêm',
            'expires_at'     => $row['expires_at'],
            'days_remaining' => daysRemaining($row['expires_at']),
        ]);
    }

    // ---- Kích hoạt lần đầu ----
    $db->prepare("UPDATE license_keys SET is_used=1, activated_at=?, expires_at=? WHERE id=?")
       ->execute([$now, $expires, $row['id']]);

    bindDevice($db, $row['id'], $deviceId, $ip, $P);
    updateCache($db, $deviceId, $row['id'], $key);

    writeLog($db, 'activate_key', "Key: $key | Device: $deviceId | IP: $ip", 'api', 0);

    // Telegram notification (async style - không block response)
    if (defined('TG_ENABLED') && TG_ENABLED) {
        tg()->notifyKeyActivated(
            $key,
            $P['device_name']  ?? 'Unknown',
            $P['device_model'] ?? 'Unknown',
            $P['ios_version']  ?? '?',
            $ip,
            $expires
        );
    }

    jsonResponse([
        'status'         => 'activated',
        'message'        => 'Kích hoạt thành công!',
        'expires_at'     => $expires,
        'days_remaining' => $row['duration_days'],
    ]);
}

// ============================================================
// FUNCTION: apiSaveKey (lưu key theo device – không cần nhập lại sau reinstall)
// ============================================================
function apiSaveKey(PDO $db, string $key, string $deviceId): void
{
    if ($key === '' || $deviceId === '') {
        jsonResponse(['status' => 'error', 'message' => 'Thiếu key hoặc device_id']);
    }

    $row = $db->prepare("SELECT id FROM license_keys WHERE license_key=? AND is_active=1");
    $row->execute([$key]);
    $kRow = $row->fetch();

    if (!$kRow) jsonResponse(['status' => 'error', 'message' => 'Key không hợp lệ']);

    updateCache($db, $deviceId, $kRow['id'], $key);
    jsonResponse(['status' => 'success', 'message' => 'Key đã được lưu cho thiết bị này']);
}

// ============================================================
// FUNCTION: apiGetSavedKey
// ============================================================
function apiGetSavedKey(PDO $db, string $deviceId): void
{
    if ($deviceId === '') jsonResponse(['status' => 'error', 'message' => 'Thiếu device_id']);

    $stmt = $db->prepare("
        SELECT dkc.license_key, lk.expires_at, lk.is_active, lk.is_used, lk.duration_days
        FROM   device_key_cache dkc
        JOIN   license_keys lk ON dkc.key_id = lk.id
        WHERE  dkc.device_id = ?
        LIMIT  1
    ");
    $stmt->execute([$deviceId]);
    $c = $stmt->fetch();

    if (!$c) {
        jsonResponse(['status' => 'not_found', 'message' => 'Không có key đã lưu cho thiết bị này']);
    }

    if (!$c['is_active']) {
        jsonResponse(['status' => 'banned', 'key' => $c['license_key'], 'message' => 'Key đã bị vô hiệu hoá']);
    }

    if ($c['is_used'] && strtotime($c['expires_at']) < time()) {
        jsonResponse(['status' => 'expired', 'key' => $c['license_key'],
            'message' => 'Key đã hết hạn', 'expired_at' => $c['expires_at']]);
    }

    jsonResponse([
        'status'         => 'found',
        'key'            => $c['license_key'],
        'expires_at'     => $c['expires_at'],
        'days_remaining' => $c['is_used'] ? daysRemaining($c['expires_at']) : $c['duration_days'],
        'is_activated'   => (bool)$c['is_used'],
    ]);
}

// ============================================================
// FUNCTION: apiPing (heartbeat mỗi X phút)
// ============================================================
function apiPing(PDO $db, string $key, string $deviceId, string $ip): void
{
    if ($key === '' || $deviceId === '') {
        jsonResponse(['status' => 'error', 'message' => 'Thiếu tham số']);
    }

    $row = fetchKey($db, $key);

    if (!$row || !$row['is_active']) {
        jsonResponse(['status' => 'invalid', 'message' => 'Key không hợp lệ']);
    }

    if ($row['is_used'] && strtotime($row['expires_at']) < time()) {
        jsonResponse(['status' => 'expired', 'message' => 'Key đã hết hạn']);
    }

    // Cập nhật last_seen
    $db->prepare("UPDATE device_bindings SET last_seen=NOW(), ip_address=? WHERE key_id=? AND device_id=?")
       ->execute([$ip, $row['id'], $deviceId]);

    jsonResponse([
        'status'         => 'valid',
        'expires_at'     => $row['expires_at'],
        'days_remaining' => daysRemaining($row['expires_at']),
        'server_time'    => date('Y-m-d H:i:s'),
    ]);
}

// ============================================================
// FUNCTION: apiResetDevice
// ============================================================
function apiResetDevice(PDO $db, string $key, string $deviceId): void
{
    // Endpoint này chỉ để test – production nên yêu cầu admin token riêng
    jsonResponse(['status' => 'error', 'message' => 'Use admin panel to reset devices'], 403);
}

// ============================================================
// HELPER: fetchKey
// ============================================================
function fetchKey(PDO $db, string $key): array|false
{
    $s = $db->prepare("SELECT * FROM license_keys WHERE license_key = ? LIMIT 1");
    $s->execute([$key]);
    return $s->fetch();
}

// ============================================================
// HELPER: isBound
// ============================================================
function isBound(PDO $db, int $keyId, string $deviceId): bool
{
    $s = $db->prepare("SELECT id FROM device_bindings WHERE key_id=? AND device_id=? LIMIT 1");
    $s->execute([$keyId, $deviceId]);
    return (bool)$s->fetch();
}

// ============================================================
// HELPER: deviceCount
// ============================================================
function deviceCount(PDO $db, int $keyId): int
{
    $s = $db->prepare("SELECT COUNT(*) FROM device_bindings WHERE key_id=?");
    $s->execute([$keyId]);
    return (int)$s->fetchColumn();
}

// ============================================================
// HELPER: bindDevice
// ============================================================
function bindDevice(PDO $db, int $keyId, string $deviceId, string $ip, array $P): void
{
    $s = $db->prepare("
        INSERT IGNORE INTO device_bindings (key_id, device_id, device_name, device_model, ios_version, ip_address)
        VALUES (?,?,?,?,?,?)
    ");
    $s->execute([
        $keyId, $deviceId,
        substr($P['device_name']  ?? '', 0, 255),
        substr($P['device_model'] ?? '', 0, 100),
        substr($P['ios_version']  ?? '', 0, 20),
        $ip,
    ]);
}

// ============================================================
// HELPER: updateCache
// ============================================================
function updateCache(PDO $db, string $deviceId, int $keyId, string $key): void
{
    $db->prepare("
        INSERT INTO device_key_cache (device_id, key_id, license_key)
        VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE key_id=VALUES(key_id), license_key=VALUES(license_key), updated_at=NOW()
    ")->execute([$deviceId, $keyId, $key]);
}
