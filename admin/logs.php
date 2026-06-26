<?php
// ============================================================
// admin/logs.php – Activity Logs
// ============================================================
define('IN_ADMIN', true);
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/auth.php';

$db = getDB();
$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['q'] ?? '');

$where  = '1=1';
$params = [];
if ($filter !== 'all') { $where .= ' AND user_type=?'; $params[] = $filter; }
if ($search) { $where .= ' AND (action LIKE ? OR description LIKE ? OR ip_address LIKE ?)'; $s="%$search%"; $params=array_merge($params,[$s,$s,$s]); }

$stmt = $db->prepare("SELECT * FROM activity_logs WHERE $where ORDER BY created_at DESC LIMIT 1000");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$pageTitle = 'Activity Logs'; $activePage = 'logs';
include __DIR__ . '/includes/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h5 style="color:#e0e0ff;margin:0"><i class="bi bi-journal-text"></i> Activity Logs</h5>
  <small style="color:var(--text-dim)"><?= count($logs) ?> mục</small>
</div>

<div class="panel mb-3"><div class="panel-body">
<form method="GET" class="row g-2 align-items-end">
  <div class="col-md-4">
    <label class="form-label">Tìm kiếm</label>
    <input type="text" name="q" class="form-control" placeholder="Action, mô tả, IP..." value="<?= clean($search) ?>">
  </div>
  <div class="col-md-3">
    <label class="form-label">Loại</label>
    <select name="filter" class="form-select">
      <option value="all" <?= $filter==='all'?'selected':'' ?>>Tất cả</option>
      <option value="admin" <?= $filter==='admin'?'selected':'' ?>>Admin</option>
      <option value="reseller" <?= $filter==='reseller'?'selected':'' ?>>Reseller</option>
      <option value="api" <?= $filter==='api'?'selected':'' ?>>API</option>
      <option value="system" <?= $filter==='system'?'selected':'' ?>>System</option>
    </select>
  </div>
  <div class="col-md-2"><button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Lọc</button></div>
  <div class="col-md-2"><a href="logs.php" class="btn btn-outline-secondary w-100"><i class="bi bi-x"></i> Reset</a></div>
</form>
</div></div>

<div class="panel"><div class="panel-body p-0">
<table id="logTable" class="tbl display nowrap" style="min-width:700px">
<thead><tr><th>#</th><th>Thời gian</th><th>Action</th><th>Mô tả</th><th>Loại</th><th>IP</th></tr></thead>
<tbody>
<?php foreach ($logs as $i => $l):
  $clr = match($l['user_type']) { 'admin'=>'#a78bfa','reseller'=>'#38bdf8','api'=>'#4ade80', default=>'#6b7280' };
?>
<tr>
  <td style="color:var(--text-dim);font-size:.75rem"><?= $i+1 ?></td>
  <td style="font-size:.78rem;color:var(--text-dim);white-space:nowrap"><?= formatDate($l['created_at'],'d/m/Y H:i:s') ?></td>
  <td><span style="font-family:monospace;font-size:.8rem;color:#fbbf24"><?= clean($l['action']) ?></span></td>
  <td style="font-size:.82rem;color:#c4c4dc;max-width:320px;white-space:normal"><?= clean($l['description'] ?? '') ?></td>
  <td><span style="color:<?= $clr ?>;font-size:.75rem;font-weight:700;text-transform:uppercase"><?= $l['user_type'] ?></span></td>
  <td style="font-family:monospace;font-size:.75rem;color:var(--text-dim)"><?= clean($l['ip_address']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div></div>

<script>
$(document).ready(function(){
  $('#logTable').DataTable({order:[[0,'desc']],pageLength:50,columnDefs:[{orderable:false,targets:[]}]});
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
