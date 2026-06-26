<?php
// admin/includes/header.php
// Usage: include sau khi set $pageTitle và $activePage
if (!defined('IN_ADMIN')) exit('Forbidden');

$pageTitle  = $pageTitle  ?? SITE_NAME;
$activePage = $activePage ?? '';
$userName   = currentUsername();
$userRole   = currentRole();
$db2        = getDB(); // for nav stats

// Lấy số liệu nhanh cho sidebar
$totalKeys    = (int)$db2->query("SELECT COUNT(*) FROM license_keys")->fetchColumn();
$activeKeys   = (int)$db2->query("SELECT COUNT(*) FROM license_keys WHERE is_active=1 AND (expires_at IS NULL OR expires_at > NOW())")->fetchColumn();
$totalDevices = (int)$db2->query("SELECT COUNT(*) FROM device_bindings")->fetchColumn();
$resellers    = (int)$db2->query("SELECT COUNT(*) FROM resellers WHERE is_active=1")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= clean($pageTitle) ?> – <?= SITE_NAME ?></title>
<!-- Bootstrap 5 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Bootstrap Icons -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<!-- DataTables -->
<link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<!-- SweetAlert2 -->
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
<style>
:root{
  --bg-dark:#0f0f1a;--bg-card:#1a1a2e;--bg-sidebar:#12121f;
  --border:#2a2a45;--accent:#7c3aed;--accent2:#4f46e5;
  --text:#c4c4dc;--text-dim:#7070a0;--success:#22c55e;
  --danger:#ef4444;--warn:#f59e0b;--info:#38bdf8;
}
*{box-sizing:border-box}
body{background:var(--bg-dark);color:var(--text);font-family:'Segoe UI',system-ui,sans-serif;margin:0}

/* Sidebar */
.sidebar{
  position:fixed;top:0;left:0;height:100vh;width:240px;
  background:var(--bg-sidebar);border-right:1px solid var(--border);
  display:flex;flex-direction:column;z-index:1000;overflow-y:auto;
}
.sidebar-brand{
  padding:20px 18px;border-bottom:1px solid var(--border);
  display:flex;align-items:center;gap:10px;
}
.sidebar-brand .logo{
  width:36px;height:36px;background:linear-gradient(135deg,var(--accent),var(--accent2));
  border-radius:10px;display:flex;align-items:center;justify-content:center;
  font-size:18px;color:#fff;flex-shrink:0;
}
.sidebar-brand h5{margin:0;font-size:.95rem;font-weight:700;color:#e0e0ff}
.sidebar-brand small{color:var(--text-dim);font-size:.72rem}
.nav-section{padding:14px 10px 4px;font-size:.7rem;color:var(--text-dim);text-transform:uppercase;letter-spacing:1.2px;font-weight:600}
.nav-link{
  display:flex;align-items:center;gap:10px;padding:9px 14px;margin:2px 8px;
  border-radius:8px;color:var(--text-dim);font-size:.88rem;text-decoration:none;transition:.15s;
}
.nav-link:hover{background:rgba(124,58,237,.12);color:#c4c4ff}
.nav-link.active{background:rgba(124,58,237,.2);color:#a78bfa;font-weight:600}
.nav-link .bi{font-size:1rem}
.nav-badge{margin-left:auto;background:var(--accent);color:#fff;padding:1px 7px;border-radius:10px;font-size:.7rem}

/* Main */
.main-wrap{margin-left:240px;min-height:100vh;display:flex;flex-direction:column}
.topbar{
  height:58px;background:var(--bg-card);border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;padding:0 24px;position:sticky;top:0;z-index:900;
}
.topbar-title{font-size:1.05rem;font-weight:700;color:#e0e0ff}
.user-pill{
  display:flex;align-items:center;gap:8px;padding:6px 14px;
  background:rgba(124,58,237,.12);border:1px solid var(--border);border-radius:20px;
  font-size:.83rem;cursor:pointer;
}
.user-pill .bi-person-circle{font-size:1.2rem;color:var(--accent)}
.content{flex:1;padding:24px}

/* Cards */
.stat-card{
  background:var(--bg-card);border:1px solid var(--border);border-radius:12px;
  padding:20px;display:flex;align-items:center;gap:16px;
}
.stat-icon{
  width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;
}
.stat-icon.purple{background:rgba(124,58,237,.18);color:#a78bfa}
.stat-icon.green {background:rgba(34,197,94,.15); color:#4ade80}
.stat-icon.red   {background:rgba(239,68,68,.15);  color:#f87171}
.stat-icon.blue  {background:rgba(56,189,248,.15); color:#38bdf8}
.stat-icon.orange{background:rgba(245,158,11,.15); color:#fbbf24}
.stat-num{font-size:1.7rem;font-weight:800;color:#e0e0ff;line-height:1}
.stat-lbl{font-size:.78rem;color:var(--text-dim);margin-top:3px}

/* Panel */
.panel{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;overflow:hidden}
.panel-header{
  padding:15px 20px;border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
}
.panel-title{font-size:.92rem;font-weight:700;color:#e0e0ff;margin:0}
.panel-body{padding:20px}

/* Table */
.tbl{border-collapse:collapse;width:100%}
.tbl th{background:#131325;color:var(--text-dim);font-size:.75rem;text-transform:uppercase;letter-spacing:.8px;padding:10px 14px;border-bottom:2px solid var(--border);white-space:nowrap}
.tbl td{padding:10px 14px;border-bottom:1px solid var(--border);font-size:.85rem;vertical-align:middle}
.tbl tr:hover td{background:rgba(124,58,237,.05)}
.tbl tr:last-child td{border-bottom:none}

/* Badges */
.badge-valid   {background:rgba(34,197,94,.15); color:#4ade80;padding:3px 8px;border-radius:6px;font-size:.75rem;font-weight:600}
.badge-expired {background:rgba(239,68,68,.15);  color:#f87171;padding:3px 8px;border-radius:6px;font-size:.75rem;font-weight:600}
.badge-inactive{background:rgba(107,114,128,.15);color:#9ca3af;padding:3px 8px;border-radius:6px;font-size:.75rem;font-weight:600}
.badge-used    {background:rgba(56,189,248,.15); color:#38bdf8;padding:3px 8px;border-radius:6px;font-size:.75rem;font-weight:600}
.badge-new     {background:rgba(245,158,11,.15); color:#fbbf24;padding:3px 8px;border-radius:6px;font-size:.75rem;font-weight:600}

/* Buttons */
.btn-xs{padding:3px 10px;font-size:.75rem;border-radius:6px;border:none;cursor:pointer}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:#6d28d9;color:#fff}
.btn-outline-secondary{background:transparent;border:1px solid var(--border);color:var(--text);padding:.4rem 1rem}
.btn-outline-secondary:hover{background:var(--border);color:#e0e0ff}
.btn-danger-xs{background:rgba(239,68,68,.15);color:#f87171}
.btn-danger-xs:hover{background:#ef4444;color:#fff}
.btn-success-xs{background:rgba(34,197,94,.15);color:#4ade80}
.btn-success-xs:hover{background:#22c55e;color:#fff}
.btn-warn-xs{background:rgba(245,158,11,.15);color:#fbbf24}
.btn-warn-xs:hover{background:#f59e0b;color:#fff}
.btn-info-xs{background:rgba(56,189,248,.15);color:#38bdf8}
.btn-info-xs:hover{background:#38bdf8;color:#fff}

/* Form controls */
.form-control,.form-select{
  background:#0f0f1a;border:1px solid var(--border);color:var(--text);border-radius:8px;
  padding:.5rem .85rem;font-size:.88rem;
}
.form-control:focus,.form-select:focus{
  background:#131325;border-color:var(--accent);color:#e0e0ff;box-shadow:0 0 0 3px rgba(124,58,237,.2);outline:none;
}
.form-label{font-size:.82rem;color:var(--text-dim);margin-bottom:4px;font-weight:600}

/* Key display */
.key-mono{font-family:'Courier New',monospace;letter-spacing:1px;font-size:.88rem;color:#a78bfa;cursor:pointer}
.key-mono:hover{color:#c4b5fd}

/* Alert */
.alert-s{padding:10px 14px;border-radius:8px;font-size:.85rem;margin-bottom:16px}
.alert-s.success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#4ade80}
.alert-s.error  {background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.3); color:#f87171}
.alert-s.info   {background:rgba(56,189,248,.1);border:1px solid rgba(56,189,248,.3);color:#38bdf8}

/* DataTables override */
.dataTables_wrapper .dataTables_filter input,
.dataTables_wrapper .dataTables_length select{
  background:#0f0f1a;border:1px solid var(--border);color:var(--text);border-radius:6px;padding:4px 8px;
}
.dataTables_wrapper .dataTables_info,.dataTables_wrapper .dataTables_paginate .paginate_button{color:var(--text-dim)!important;font-size:.8rem}
.dataTables_wrapper .dataTables_paginate .paginate_button.current,.dataTables_wrapper .dataTables_paginate .paginate_button:hover{
  background:var(--accent)!important;color:#fff!important;border-radius:6px!important;border:none!important;
}
table.dataTable thead th{border-bottom:2px solid var(--border)!important}

/* Scrollbar */
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:#0f0f1a}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px}

/* Spinner overlay */
#loadingOverlay{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:none;align-items:center;justify-content:center;flex-direction:column;gap:12px;color:#c4c4dc}

@media(max-width:768px){
  .sidebar{transform:translateX(-100%);transition:.3s}
  .sidebar.show{transform:translateX(0)}
  .main-wrap{margin-left:0}
}
</style>
</head>
<body>

<!-- Sidebar -->
<nav class="sidebar">
  <div class="sidebar-brand">
    <div class="logo"><i class="bi bi-shield-lock-fill"></i></div>
    <div>
      <h5><?= SITE_NAME ?></h5>
      <small><?= SITE_VERSION ?></small>
    </div>
  </div>

  <div class="nav-section">Dashboard</div>
  <a href="index.php" class="nav-link <?= $activePage==='dashboard'?'active':'' ?>">
    <i class="bi bi-grid-1x2-fill"></i> Tổng quan
  </a>

  <div class="nav-section">Quản lý Key</div>
  <a href="keys.php" class="nav-link <?= $activePage==='keys'?'active':'' ?>">
    <i class="bi bi-key-fill"></i> License Keys
    <span class="nav-badge"><?= $totalKeys ?></span>
  </a>
  <a href="import_keys.php" class="nav-link <?= $activePage==='import'?'active':'' ?>">
    <i class="bi bi-upload"></i> Import Keys
  </a>
  <a href="devices.php" class="nav-link <?= $activePage==='devices'?'active':'' ?>">
    <i class="bi bi-phone-fill"></i> Thiết bị
    <span class="nav-badge"><?= $totalDevices ?></span>
  </a>

  <?php if (isAdmin()): ?>
  <div class="nav-section">Đại lý</div>
  <a href="resellers.php" class="nav-link <?= $activePage==='resellers'?'active':'' ?>">
    <i class="bi bi-people-fill"></i> Đại lý
    <span class="nav-badge"><?= $resellers ?></span>
  </a>

  <div class="nav-section">Hệ thống</div>
  <a href="logs.php" class="nav-link <?= $activePage==='logs'?'active':'' ?>">
    <i class="bi bi-journal-text"></i> Activity Logs
  </a>
  <a href="settings.php" class="nav-link <?= $activePage==='settings'?'active':'' ?>">
    <i class="bi bi-gear-fill"></i> Cài đặt
  </a>
  <?php endif; ?>

  <div style="margin-top:auto;padding:16px;border-top:1px solid var(--border)">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
      <div style="width:32px;height:32px;background:rgba(124,58,237,.2);border-radius:50%;display:flex;align-items:center;justify-content:center">
        <i class="bi bi-person-fill" style="color:#a78bfa"></i>
      </div>
      <div>
        <div style="font-size:.82rem;color:#e0e0ff;font-weight:600"><?= currentUsername() ?></div>
        <div style="font-size:.7rem;color:var(--text-dim)"><?= isAdmin() ? 'Administrator' : 'Reseller' ?></div>
      </div>
    </div>
    <a href="logout.php" class="nav-link" style="margin:0;color:#f87171">
      <i class="bi bi-box-arrow-left"></i> Đăng xuất
    </a>
  </div>
</nav>

<!-- Main Wrapper -->
<div class="main-wrap">
  <!-- Topbar -->
  <div class="topbar">
    <div class="topbar-title">
      <button class="btn btn-link text-white d-md-none me-2 p-0" onclick="toggleSidebar()" style="text-decoration:none;font-size:1.2rem"><i class="bi bi-list"></i></button>
      <?= clean($pageTitle) ?>
    </div>
    <div class="d-flex align-items:center gap-3">
      <div style="font-size:.78rem;color:var(--text-dim)"><i class="bi bi-circle-fill" style="color:#22c55e;font-size:.5rem"></i> Online</div>
    </div>
  </div>

  <!-- Content -->
  <div class="content">
