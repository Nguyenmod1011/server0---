<?php
// ============================================================
// INSTALL.PHP - Cài đặt database lần đầu
// Truy cập: yoursite.com/install.php
// XÓA FILE NÀY SAU KHI CÀI ĐẶT XONG!
// ============================================================
require_once 'config.php';

$db  = getDB();
$msg = [];
$err = [];

$tables = [
    "admin_users" => "CREATE TABLE IF NOT EXISTS admin_users (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        username     VARCHAR(50)  UNIQUE NOT NULL,
        password     VARCHAR(255) NOT NULL,
        email        VARCHAR(100) DEFAULT '',
        last_login   DATETIME     NULL,
        created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "resellers" => "CREATE TABLE IF NOT EXISTS resellers (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        username     VARCHAR(50)  UNIQUE NOT NULL,
        password     VARCHAR(255) NOT NULL,
        email        VARCHAR(100) DEFAULT '',
        phone        VARCHAR(20)  DEFAULT '',
        note         TEXT,
        key_quota    INT          DEFAULT 100,
        keys_created INT          DEFAULT 0,
        is_active    TINYINT(1)   DEFAULT 1,
        created_by   INT          NULL,
        last_login   DATETIME     NULL,
        created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_username  (username),
        INDEX idx_is_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "license_keys" => "CREATE TABLE IF NOT EXISTS license_keys (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        license_key         VARCHAR(25)  UNIQUE NOT NULL,
        duration_days       INT          NOT NULL DEFAULT 30,
        max_devices         INT          DEFAULT 1,
        is_active           TINYINT(1)   DEFAULT 1,
        is_used             TINYINT(1)   DEFAULT 0,
        activated_at        DATETIME     NULL,
        expires_at          DATETIME     NULL,
        created_by_admin    INT          NULL,
        created_by_reseller INT          NULL,
        note                VARCHAR(255) DEFAULT '',
        created_at          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_license_key (license_key),
        INDEX idx_is_active   (is_active),
        INDEX idx_expires_at  (expires_at),
        INDEX idx_is_used     (is_used)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "device_bindings" => "CREATE TABLE IF NOT EXISTS device_bindings (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        key_id       INT          NOT NULL,
        device_id    VARCHAR(255) NOT NULL,
        device_name  VARCHAR(255) DEFAULT '',
        device_model VARCHAR(100) DEFAULT '',
        ios_version  VARCHAR(20)  DEFAULT '',
        ip_address   VARCHAR(45)  DEFAULT '',
        first_seen   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        last_seen    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_key_device (key_id, device_id),
        INDEX idx_key_id    (key_id),
        INDEX idx_device_id (device_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "device_key_cache" => "CREATE TABLE IF NOT EXISTS device_key_cache (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        device_id   VARCHAR(255) UNIQUE NOT NULL,
        key_id      INT          NOT NULL,
        license_key VARCHAR(25)  NOT NULL,
        cached_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_device_id  (device_id),
        INDEX idx_key_id     (key_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "activity_logs" => "CREATE TABLE IF NOT EXISTS activity_logs (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        action      VARCHAR(100)                              NOT NULL,
        description TEXT,
        user_type   ENUM('admin','reseller','api','system')   DEFAULT 'system',
        user_id     INT     DEFAULT 0,
        ip_address  VARCHAR(45)  DEFAULT '',
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created_at (created_at),
        INDEX idx_action     (action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
];

foreach ($tables as $name => $sql) {
    try {
        $db->exec($sql);
        $msg[] = "✅ Table <b>$name</b> created.";
    } catch (PDOException $e) {
        $err[] = "❌ Table <b>$name</b>: " . $e->getMessage();
    }
}

// Tạo admin mặc định
$count = (int)$db->query("SELECT COUNT(*) FROM admin_users")->fetchColumn();
if ($count === 0) {
    try {
        $s = $db->prepare("INSERT INTO admin_users (username, password, email) VALUES (?,?,?)");
        $s->execute(['admin', hashPassword('admin123'), 'admin@localhost']);
        $msg[] = "✅ Default admin created: <b>admin / admin123</b>";
    } catch (PDOException $e) {
        $err[] = "❌ Admin: " . $e->getMessage();
    }
} else {
    $msg[] = "ℹ️ Admin already exists, skipped.";
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<title>Install – License Manager Pro</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:#0f0f1a;color:#cdd6f4;font-family:'Segoe UI',sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;}
  .card{background:#1e1e2e;border:1px solid #313244;border-radius:12px;padding:32px;max-width:560px;width:100%;}
  h2{color:#cba6f7;margin-bottom:24px;font-size:1.4rem;}
  .item{padding:8px 0;border-bottom:1px solid #313244;font-size:.9rem;}
  .item:last-child{border:none;}
  .btn{display:inline-block;margin-top:20px;padding:10px 24px;background:#89b4fa;color:#1e1e2e;border-radius:8px;text-decoration:none;font-weight:700;}
  .warn{margin-top:16px;padding:12px;background:#f38ba820;border:1px solid #f38ba8;border-radius:8px;color:#f38ba8;font-size:.85rem;}
</style>
</head>
<body>
<div class="card">
  <h2>⚙️ License Manager Pro – Installation</h2>
  <?php foreach ($msg as $m): ?>
  <div class="item"><?= $m ?></div>
  <?php endforeach; ?>
  <?php foreach ($err as $e): ?>
  <div class="item" style="color:#f38ba8;"><?= $e ?></div>
  <?php endforeach; ?>

  <?php if (empty($err)): ?>
  <div class="warn">⚠️ <b>XÓA file install.php ngay bây giờ!</b><br>Đây là rủi ro bảo mật nghiêm trọng nếu để lại.</div>
  <a class="btn" href="admin/login.php">→ Vào Admin Panel</a>
  <?php endif; ?>
</div>
</body>
</html>
