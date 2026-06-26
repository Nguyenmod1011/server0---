<?php
// ============================================================
// admin/login.php
// ============================================================
define('IN_ADMIN', true);
define('PUBLIC_PAGE', true);
require_once dirname(__DIR__) . '/config.php';

$error = '';
$info  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['_csrf'] ?? '')) {
        $error = 'Token không hợp lệ, vui lòng thử lại.';
    } else {
        $username = strtolower(trim($_POST['username'] ?? ''));
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'admin';

        if ($username === '' || $password === '') {
            $error = 'Vui lòng nhập đầy đủ thông tin.';
        } else {
            $db = getDB();

            if ($role === 'admin') {
                $stmt = $db->prepare("SELECT * FROM admin_users WHERE username=? LIMIT 1");
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if ($user && verifyPassword($password, $user['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['admin_id']  = $user['id'];
                    $_SESSION['username']  = $user['username'];
                    $_SESSION['role']      = 'admin';
                    $db->prepare("UPDATE admin_users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);
                    writeLog($db, 'admin_login', "Admin '{$user['username']}' đăng nhập", 'admin', $user['id']);
                    $redirect = $_GET['r'] ?? 'index.php';
                    redirect(filter_var($redirect, FILTER_VALIDATE_URL) ? 'index.php' : $redirect);
                } else {
                    $error = 'Sai tên đăng nhập hoặc mật khẩu.';
                    writeLog($db, 'login_failed', "Thất bại: $username (admin)", 'system', 0);
                }
            } else {
                // Reseller login
                $stmt = $db->prepare("SELECT * FROM resellers WHERE username=? AND is_active=1 LIMIT 1");
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if ($user && verifyPassword($password, $user['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['reseller_id'] = $user['id'];
                    $_SESSION['username']    = $user['username'];
                    $_SESSION['role']        = 'reseller';
                    $db->prepare("UPDATE resellers SET last_login=NOW() WHERE id=?")->execute([$user['id']]);
                    writeLog($db, 'reseller_login', "Reseller '{$user['username']}' đăng nhập", 'reseller', $user['id']);
                    redirect('keys.php');
                } else {
                    $error = 'Sai thông tin hoặc tài khoản bị khóa.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Đăng nhập – <?= SITE_NAME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{
  background:#0f0f1a;min-height:100vh;display:flex;align-items:center;
  justify-content:center;font-family:'Segoe UI',system-ui,sans-serif;
  background-image:radial-gradient(ellipse at 50% 0,rgba(124,58,237,.15) 0,transparent 70%);
}
.card{
  background:#1a1a2e;border:1px solid #2a2a45;border-radius:16px;
  padding:40px 36px;width:100%;max-width:420px;
}
.brand{text-align:center;margin-bottom:30px}
.logo-box{
  width:64px;height:64px;background:linear-gradient(135deg,#7c3aed,#4f46e5);
  border-radius:16px;display:flex;align-items:center;justify-content:center;
  font-size:30px;color:#fff;margin:0 auto 14px;
}
h2{color:#e0e0ff;font-size:1.3rem;font-weight:800}
p.sub{color:#6060a0;font-size:.83rem}
.form-label{font-size:.82rem;color:#7070a0;font-weight:600;margin-bottom:5px}
.form-control,.form-select{
  background:#0f0f1a;border:1px solid #2a2a45;color:#c4c4dc;
  border-radius:10px;padding:.6rem .9rem;font-size:.88rem;
}
.form-control:focus,.form-select:focus{
  background:#131325;border-color:#7c3aed;
  box-shadow:0 0 0 3px rgba(124,58,237,.2);color:#e0e0ff;outline:none;
}
.form-control::placeholder{color:#40407a}
.input-group-text{background:#0f0f1a;border:1px solid #2a2a45;border-left:none;color:#7070a0;border-radius:0 10px 10px 0;cursor:pointer}
.input-group .form-control{border-radius:10px 0 0 10px;border-right:none}
.input-group:focus-within .form-control,
.input-group:focus-within .input-group-text{border-color:#7c3aed}
.btn-login{
  width:100%;padding:.7rem;background:linear-gradient(135deg,#7c3aed,#4f46e5);
  border:none;border-radius:10px;color:#fff;font-size:.95rem;font-weight:700;
  cursor:pointer;transition:.2s;margin-top:6px;
}
.btn-login:hover{transform:translateY(-1px);box-shadow:0 8px 24px rgba(124,58,237,.35)}
.alert-err{
  background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);
  color:#f87171;border-radius:10px;padding:10px 14px;font-size:.83rem;margin-bottom:16px;
}
.role-tabs{display:flex;gap:6px;margin-bottom:20px}
.role-tab{
  flex:1;padding:9px;border:1px solid #2a2a45;border-radius:10px;
  background:transparent;color:#6060a0;cursor:pointer;font-size:.82rem;
  font-weight:600;text-align:center;transition:.15s;
}
.role-tab.active{background:rgba(124,58,237,.2);border-color:#7c3aed;color:#a78bfa}
</style>
</head>
<body>
<div class="card">
  <div class="brand">
    <div class="logo-box"><i class="bi bi-shield-lock-fill"></i></div>
    <h2><?= SITE_NAME ?></h2>
    <p class="sub">Đăng nhập vào hệ thống quản lý</p>
  </div>

  <!-- Role selector -->
  <div class="role-tabs">
    <button type="button" class="role-tab active" id="tabAdmin" onclick="switchRole('admin')">
      <i class="bi bi-person-fill-gear"></i> Admin
    </button>
    <button type="button" class="role-tab" id="tabReseller" onclick="switchRole('reseller')">
      <i class="bi bi-people-fill"></i> Đại lý
    </button>
  </div>

  <?php if ($error): ?>
  <div class="alert-err"><i class="bi bi-exclamation-circle-fill"></i> <?= clean($error) ?></div>
  <?php endif; ?>

  <form method="POST" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= csrfToken() ?>">
    <input type="hidden" name="role" id="roleField" value="admin">

    <div class="mb-3">
      <label class="form-label">Tên đăng nhập</label>
      <input type="text" name="username" class="form-control" placeholder="Nhập username..."
             value="<?= clean($_POST['username'] ?? '') ?>" autofocus required>
    </div>

    <div class="mb-4">
      <label class="form-label">Mật khẩu</label>
      <div class="input-group">
        <input type="password" name="password" id="pwdField" class="form-control" placeholder="••••••••" required>
        <span class="input-group-text" onclick="togglePwd()"><i class="bi bi-eye" id="eyeIcon"></i></span>
      </div>
    </div>

    <button type="submit" class="btn-login">
      <i class="bi bi-box-arrow-in-right"></i> Đăng nhập
    </button>
  </form>

  <div style="text-align:center;margin-top:18px;font-size:.75rem;color:#40407a">
    <?= SITE_NAME ?> v<?= SITE_VERSION ?>
  </div>
</div>

<script>
function switchRole(r){
  document.getElementById('roleField').value=r;
  document.getElementById('tabAdmin').classList.toggle('active',r==='admin');
  document.getElementById('tabReseller').classList.toggle('active',r==='reseller');
}
function togglePwd(){
  const f=document.getElementById('pwdField');
  const e=document.getElementById('eyeIcon');
  if(f.type==='password'){f.type='text';e.className='bi bi-eye-slash';}
  else{f.type='password';e.className='bi bi-eye';}
}
</script>
</body>
</html>
