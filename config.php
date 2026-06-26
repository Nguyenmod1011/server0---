<?php
// ============================================================
// CONFIG.PHP - Cấu hình hệ thống
// ============================================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'your_password');
define('DB_NAME',  'license_db');

// API secret - THAY ĐỔI GIÁ TRỊ NÀY!
define('API_SECRET', 'CHANGE_THIS_SECRET_KEY_2024');

// Thông tin site
define('SITE_NAME', 'License Manager Pro');
define('SITE_VERSION', '1.0.0');

// ── Telegram Bot (tuỳ chọn) ────────────────────────────────
// Lấy token: https://t.me/BotFather | Chat ID: https://t.me/userinfobot
define('TG_ENABLED',   false);              // Đặt true để bật
define('TG_BOT_TOKEN', 'YOUR:BOT_TOKEN');
define('TG_CHAT_ID',   'YOUR_CHAT_ID');

// Session config
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Strict');
    session_start();
}

// ============================================================
// DATABASE CONNECTION (Singleton PDO)
// ============================================================
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $opts = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
        } catch (PDOException $e) {
            if (defined('API_MODE')) {
                jsonResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
            }
            die('<div style="color:red;padding:20px;font-family:monospace;">Database Error: ' . htmlspecialchars($e->getMessage()) . '</div>');
        }
    }
    return $pdo;
}

// ============================================================
// HELPER FUNCTIONS
// ============================================================

/**
 * Tạo license key dạng XXXX-XXXX-XXXX-XXXX
 */
function generateKey(int $segments = 4, int $segLen = 4): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $len   = strlen($chars);
    $parts = [];
    for ($i = 0; $i < $segments; $i++) {
        $part = '';
        for ($j = 0; $j < $segLen; $j++) {
            $part .= $chars[random_int(0, $len - 1)];
        }
        $parts[] = $part;
    }
    return implode('-', $parts);
}

/**
 * Tạo unique key không trùng lặp trong DB
 */
function generateUniqueKey(PDO $db, int $segments = 4, int $segLen = 4): string {
    do {
        $key  = generateKey($segments, $segLen);
        $stmt = $db->prepare("SELECT id FROM license_keys WHERE license_key = ?");
        $stmt->execute([$key]);
    } while ($stmt->fetchColumn());
    return $key;
}

/**
 * Hash password
 */
function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verify password
 */
function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

/**
 * JSON Response (cho API)
 */
function jsonResponse(array $data, int $code = 200): void {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Lấy IP thực của client
 */
function getClientIP(): string {
    $keys = ['HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

/**
 * Ghi log hoạt động
 */
function writeLog(PDO $db, string $action, string $desc, string $userType = '', int $userId = 0): void {
    $stmt = $db->prepare(
        "INSERT INTO activity_logs (action, description, user_type, user_id, ip_address)
         VALUES (?,?,?,?,?)"
    );
    $stmt->execute([$action, $desc, $userType, $userId, getClientIP()]);
}

/**
 * CSRF token
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Redirect
 */
function redirect(string $url): void {
    header("Location: $url");
    exit;
}

/**
 * Format ngày giờ
 */
function formatDate(?string $date, string $fmt = 'd/m/Y H:i'): string {
    if (!$date) return 'N/A';
    return date($fmt, strtotime($date));
}

/**
 * Tính số ngày còn lại
 */
function daysRemaining(?string $expiresAt): int {
    if (!$expiresAt) return 0;
    $diff = strtotime($expiresAt) - time();
    return max(0, (int) ceil($diff / 86400));
}

/**
 * Sanitize input
 */
function clean(string $val): string {
    return htmlspecialchars(trim($val), ENT_QUOTES, 'UTF-8');
}
