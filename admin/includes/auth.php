<?php
// ============================================================
// admin/includes/auth.php
// ============================================================
if (!defined('IN_ADMIN')) {
    header('HTTP/1.1 403 Forbidden'); exit('Direct access forbidden.');
}
require_once dirname(__DIR__, 2) . '/config.php';

// Tự động xác định đường dẫn admin
if (!defined('ADMIN_URL')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Lấy thư mục gốc (trước /admin/)
    $self   = $_SERVER['PHP_SELF'] ?? '/admin/index.php';
    $pos    = strpos($self, '/admin/');
    $base   = ($pos !== false) ? substr($self, 0, $pos) : '';
    define('ADMIN_URL', "$scheme://$host{$base}/admin");
}

function requireAdmin(): void {
    if (empty($_SESSION['admin_id'])) {
        header('Location: ' . ADMIN_URL . '/login.php?r=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
        exit;
    }
}

function requireAny(): void {
    if (empty($_SESSION['admin_id']) && empty($_SESSION['reseller_id'])) {
        header('Location: ' . ADMIN_URL . '/login.php');
        exit;
    }
}

function isAdmin(): bool   { return !empty($_SESSION['admin_id']); }
function isReseller(): bool { return !empty($_SESSION['reseller_id']); }
function currentAdminId(): int    { return (int)($_SESSION['admin_id'] ?? 0); }
function currentResellerId(): int { return (int)($_SESSION['reseller_id'] ?? 0); }
function currentUserId(): int     { return isAdmin() ? currentAdminId() : currentResellerId(); }
function currentUsername(): string { return clean($_SESSION['username'] ?? 'Unknown'); }
function currentRole(): string    { return $_SESSION['role'] ?? 'admin'; }

// Bảo vệ nếu không phải trang public
if (!defined('PUBLIC_PAGE')) {
    requireAdmin();
}
