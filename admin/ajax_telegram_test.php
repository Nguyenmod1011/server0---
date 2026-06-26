<?php
define('IN_ADMIN', true);
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/telegram.php';
require_once __DIR__ . '/includes/auth.php';
requireAdmin();
header('Content-Type: application/json');
if (!verifyCsrf($_POST['_csrf'] ?? '')) { echo json_encode(['ok'=>false,'msg'=>'CSRF error']); exit; }
$ok = tg()->test();
echo json_encode(['ok' => $ok, 'msg' => $ok ? 'Đã gửi thành công' : 'Gửi thất bại – kiểm tra token/chat_id']);
