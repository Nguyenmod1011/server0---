<?php
// ============================================================
// admin/index.php – Dashboard (Admin + Reseller)
// ============================================================
define('IN_ADMIN', true);
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/auth.php';

// Cho phép cả admin lẫn reseller vào dashboard
if (!isAdmin() && !isReseller()) {
    header('Location: login.php'); exit;
}

$db         = getDB();
$pageTitle  = 'Tổng quan';
$activePage = 'dashboard';

// ──────────────────────────────────────────────────────────
// RESELLER DASHBOARD
// ──────────────────────────────────────────────────────────
if (isReseller() && !isAdmin()) {
    $rId = currentResellerId();

    $resInfo = $db->prepare("SELECT * FROM resellers WHERE id=?");
    $resInfo->execute([$rId]);
    $rData   = $resInfo->fetch();

    $myKeys   = (int)$db->prepare("SELECT COUNT(*) FROM license_keys WHERE created_by_reseller=?")->execute([$rId]) ? $db->prepare("SELECT COUNT(*) FROM license_keys WHERE created_by_reseller=?")->execute([$rId]) : 0;
    // requery
    $s = $db->prepare("SELECT COUNT(*) FROM license_keys WHERE created_by_reseller=?"); $s->execute([$rId]); $total = (int)$s->fetchColumn();
    $s = $db->prepare("SELECT COUNT(*) FROM license_keys WHERE created_by_reseller=? AND is_active=1 AND is_used=1 AND expires_at > NOW()"); $s->execute([$rId]); $active = (int)$s->fetchColumn();
    $s = $db->prepare("SELECT COUNT(*) FROM license_keys WHERE created_by_reseller=? AND is_used=1 AND expires_at < NOW()"); $s->execute([$rId]); $expired = (int)$s->fetchColumn();
    $s = $db->prepare("SELECT COUNT(*) FROM license_keys WHERE created_by_reseller=? AND is_used=0"); $s->execute([$rId]); $unused = (int)$s->fetchColumn();

    $quota    = (int)$rData['key_quota'];
    $created  = (int)$rData['keys_created'];
    $remain   = max(0, $quota - $created);
    $pct      = $quota > 0 ? round($created / $quota * 100) : 0;

    // Keys sắp hết hạn
    $expiring = $db->prepare("
        SELECT lk.license_key, lk.expires_at, COUNT(db.id) as devices
        FROM license_keys lk LEFT JOIN device_bindings db ON lk.id=db.key_id
        WHERE lk.created_by_reseller=? AND lk.is_active=1 AND lk.is_used=1
          AND lk.expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
        GROUP BY lk.id ORDER BY lk.expires_at ASC LIMIT 6
    "); $expiring->execute([$rId]); $expiring = $expiring->fetchAll();

    // Keys mới nhất
    $recentKeys = $db->prepare("
        SELECT license_key, duration_days, is_used, is_active, expires_at, created_at
        FROM license_keys WHERE created_by_reseller=? ORDER BY created_at DESC LIMIT 10
    "); $recentKeys->execute([$rId]); $recentKeys = $recentKeys->fetchAll();

    include __DIR__ . '/includes/header.php';
?>

<!-- Reseller Info Banner -->
<div style="background:linear-gradient(135deg,rgba(124,58,237,.2),rgba(79,70,229,.15));border:1px solid rgba(124,58,237,.3);border-radius:12px;padding:18px 22px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
  <div>
    <div style="font-size:1.1rem;font-weight:700;color:#e0e0ff">Xin chào, @<?= clean($rData['username']) ?> 👋</div>
    <div style="font-size:.82rem;color:var(--text-dim)">Tài khoản đại lý – Hôm nay: <?= date('d/m/Y') ?></div>
  </div>
  <a href="keys.php?modal=generate" class="btn btn-primary btn-xs" style="padding:10px 20px">
    <i class="bi bi-plus-circle-fill"></i> Tạo Key mới
  </a>
</div>

<!-- Quota Bar -->
<div class="panel mb-4">
  <div class="panel-body">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
      <div>
        <div style="font-size:.85rem;color:#e0e0ff;font-weight:700">Quota Keys</div>
        <div style="font-size:.75rem;color:var(--text-dim)">Số key bạn có thể tạo</div>
      </div>
      <div style="text-align:right">
        <div style="font-size:1.5rem;font-weight:800;color:#a78bfa"><?= number_format($remain) ?></div>
        <div style="font-size:.72rem;color:var(--text-dim)">còn lại / <?= number_format($quota) ?></div>
      </div>
    </div>
    <div style="height:10px;background:#1f1f35;border-radius:5px;overflow:hidden">
      <div style="height:100%;width:<?= min(100,$pct) ?>%;background:<?= $pct>=90?'#ef4444':($pct>=70?'#f59e0b':'linear-gradient(90deg,#7c3aed,#4f46e5)') ?>;border-radius:5px;transition:.4s"></div>
    </div>
    <div style="display:flex;justify-content:space-between;margin-top:6px;font-size:.72rem;color:var(--text-dim)">
      <span>Đã dùng: <?= number_format($created) ?> (<?= $pct ?>%)</span>
      <span>Tổng quota: <?= number_format($quota) ?></span>
    </div>
  </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <?php $rcards = [
    ['icon'=>'bi-key-fill','cls'=>'purple','val'=>$total, 'lbl'=>'Tổng key của tôi'],
    ['icon'=>'bi-check-circle-fill','cls'=>'green', 'val'=>$active, 'lbl'=>'Đang hoạt động'],
    ['icon'=>'bi-clock-history','cls'=>'orange','val'=>$unused, 'lbl'=>'Chưa kích hoạt'],
    ['icon'=>'bi-x-circle-fill','cls'=>'red',  'val'=>$expired,'lbl'=>'Đã hết hạn'],
  ]; foreach($rcards as $c): ?>
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="stat-icon <?= $c['cls'] ?>"><i class="bi <?= $c['icon'] ?>"></i></div>
      <div><div class="stat-num"><?= number_format($c['val']) ?></div><div class="stat-lbl"><?= $c['lbl'] ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <!-- Sắp hết hạn -->
  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-header">
        <span class="panel-title"><i class="bi bi-alarm-fill" style="color:#fbbf24"></i> Sắp hết hạn (7 ngày)</span>
      </div>
      <div class="panel-body p-0">
        <?php if (empty($expiring)): ?>
        <div style="padding:24px;text-align:center;color:var(--text-dim);font-size:.85rem">
          <i class="bi bi-check2-circle" style="font-size:2rem;color:#4ade80;display:block;margin-bottom:8px"></i>
          Không có key nào sắp hết hạn
        </div>
        <?php else: foreach($expiring as $e): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid var(--border)">
          <span class="key-mono" onclick="copyText('<?= clean($e['license_key']) ?>','Key')" style="font-size:.8rem"><?= clean($e['license_key']) ?></span>
          <div style="text-align:right">
            <div style="font-size:.75rem;color:#fbbf24"><?= formatDate($e['expires_at'],'d/m/Y') ?></div>
            <div style="font-size:.7rem;color:var(--text-dim)"><?= $e['devices'] ?> thiết bị</div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- Keys mới nhất -->
  <div class="col-lg-7">
    <div class="panel">
      <div class="panel-header">
        <span class="panel-title"><i class="bi bi-key-fill"></i> Keys mới tạo</span>
        <a href="keys.php" style="font-size:.78rem;color:#7c3aed;text-decoration:none">Xem tất cả →</a>
      </div>
      <div class="panel-body p-0">
        <table class="tbl">
          <thead><tr><th>Key</th><th>Hạn</th><th>Trạng thái</th><th>Ngày tạo</th></tr></thead>
          <tbody>
          <?php foreach ($recentKeys as $k):
            $st = !$k['is_active'] ? ['Vô hiệu','inactive'] : (!$k['is_used'] ? ['Chưa dùng','new'] : (strtotime($k['expires_at']) < time() ? ['Hết hạn','expired'] : ['Hoạt động','valid']));
          ?>
          <tr>
            <td><span class="key-mono" onclick="copyText('<?= clean($k['license_key']) ?>','Key')" style="font-size:.78rem"><?= clean($k['license_key']) ?></span></td>
            <td style="font-size:.78rem;color:#a78bfa"><?= $k['duration_days'] ?> ngày</td>
            <td><span class="badge-<?= $st[1] ?>"><?= $st[0] ?></span></td>
            <td style="font-size:.75rem;color:var(--text-dim)"><?= formatDate($k['created_at'],'d/m/Y') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ──────────────────────────────────────────────────────────
// ADMIN DASHBOARD
// ──────────────────────────────────────────────────────────
requireAdmin();

$stats = [];
$stats['total_keys']    = (int)$db->query("SELECT COUNT(*) FROM license_keys")->fetchColumn();
$stats['active_keys']   = (int)$db->query("SELECT COUNT(*) FROM license_keys WHERE is_active=1 AND is_used=1 AND expires_at > NOW()")->fetchColumn();
$stats['expired_keys']  = (int)$db->query("SELECT COUNT(*) FROM license_keys WHERE is_used=1 AND expires_at < NOW()")->fetchColumn();
$stats['unused_keys']   = (int)$db->query("SELECT COUNT(*) FROM license_keys WHERE is_used=0 AND is_active=1")->fetchColumn();
$stats['disabled_keys'] = (int)$db->query("SELECT COUNT(*) FROM license_keys WHERE is_active=0")->fetchColumn();
$stats['total_devices'] = (int)$db->query("SELECT COUNT(*) FROM device_bindings")->fetchColumn();
$stats['active_devices']= (int)$db->query("SELECT COUNT(DISTINCT db.device_id) FROM device_bindings db JOIN license_keys lk ON db.key_id=lk.id WHERE lk.expires_at > NOW() AND lk.is_active=1")->fetchColumn();
$stats['resellers']     = (int)$db->query("SELECT COUNT(*) FROM resellers WHERE is_active=1")->fetchColumn();
$stats['expiring_soon'] = (int)$db->query("SELECT COUNT(*) FROM license_keys WHERE is_active=1 AND is_used=1 AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$stats['keys_today']    = (int)$db->query("SELECT COUNT(*) FROM license_keys WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();

$chartData = $db->query("
    SELECT DATE(created_at) as d, COUNT(*) as cnt
    FROM license_keys WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at) ORDER BY d
")->fetchAll();
$chartLabels = json_encode(array_column($chartData, 'd'));
$chartValues = json_encode(array_column($chartData, 'cnt'));

$expiringSoon = $db->query("
    SELECT lk.license_key, lk.expires_at, COUNT(db.id) as devices
    FROM license_keys lk LEFT JOIN device_bindings db ON lk.id=db.key_id
    WHERE lk.is_active=1 AND lk.is_used=1
      AND lk.expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
    GROUP BY lk.id ORDER BY lk.expires_at ASC LIMIT 8
")->fetchAll();

$recentLogs = $db->query("
    SELECT action, description, user_type, ip_address, created_at
    FROM activity_logs ORDER BY created_at DESC LIMIT 10
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="row g-3 mb-4">
  <?php $cards = [
    ['icon'=>'bi-key-fill','cls'=>'purple','val'=>$stats['total_keys'],   'lbl'=>'Tổng số Key'],
    ['icon'=>'bi-check-circle-fill','cls'=>'green','val'=>$stats['active_keys'],'lbl'=>'Key đang hoạt động'],
    ['icon'=>'bi-clock-history','cls'=>'orange','val'=>$stats['unused_keys'],'lbl'=>'Chưa kích hoạt'],
    ['icon'=>'bi-x-circle-fill','cls'=>'red','val'=>$stats['expired_keys'],'lbl'=>'Đã hết hạn'],
    ['icon'=>'bi-phone-fill','cls'=>'blue','val'=>$stats['total_devices'],'lbl'=>'Thiết bị đăng ký'],
    ['icon'=>'bi-people-fill','cls'=>'green','val'=>$stats['resellers'], 'lbl'=>'Đại lý hoạt động'],
    ['icon'=>'bi-calendar-day','cls'=>'purple','val'=>$stats['keys_today'],'lbl'=>'Key tạo hôm nay'],
    ['icon'=>'bi-exclamation-triangle-fill','cls'=>'orange','val'=>$stats['expiring_soon'],'lbl'=>'Hết hạn trong 7 ngày'],
  ]; foreach($cards as $c): ?>
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="stat-icon <?= $c['cls'] ?>"><i class="bi <?= $c['icon'] ?>"></i></div>
      <div><div class="stat-num"><?= number_format($c['val']) ?></div><div class="stat-lbl"><?= $c['lbl'] ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="panel">
      <div class="panel-header"><span class="panel-title"><i class="bi bi-bar-chart-fill"></i> Keys tạo 30 ngày qua</span></div>
      <div class="panel-body"><canvas id="keysChart" height="100"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="panel h-100">
      <div class="panel-header"><span class="panel-title"><i class="bi bi-lightning-fill"></i> Thao tác nhanh</span></div>
      <div class="panel-body d-flex flex-column gap-2">
        <a href="keys.php?modal=generate" class="btn btn-primary w-100 text-start"><i class="bi bi-plus-circle-fill"></i> Tạo key mới</a>
        <a href="import_keys.php" class="btn btn-outline-secondary w-100 text-start"><i class="bi bi-upload"></i> Import keys</a>
        <a href="keys.php" class="btn btn-outline-secondary w-100 text-start"><i class="bi bi-list-ul"></i> Quản lý keys</a>
        <a href="devices.php" class="btn btn-outline-secondary w-100 text-start"><i class="bi bi-phone"></i> Quản lý thiết bị</a>
        <a href="resellers.php" class="btn btn-outline-secondary w-100 text-start"><i class="bi bi-person-plus-fill"></i> Thêm đại lý</a>
        <a href="export_keys.php?format=csv&filter=all" class="btn btn-outline-secondary w-100 text-start"><i class="bi bi-download"></i> Xuất tất cả keys (CSV)</a>
      </div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-header">
        <span class="panel-title"><i class="bi bi-alarm-fill" style="color:#fbbf24"></i> Sắp hết hạn (7 ngày)</span>
        <span class="badge-expired"><?= count($expiringSoon) ?> key</span>
      </div>
      <div class="panel-body p-0">
        <?php if (empty($expiringSoon)): ?>
        <div style="padding:20px;text-align:center;color:var(--text-dim);font-size:.85rem"><i class="bi bi-check2-circle" style="font-size:2rem;color:#4ade80"></i><br>Không có key nào sắp hết hạn</div>
        <?php else: foreach($expiringSoon as $r): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:9px 16px;border-bottom:1px solid var(--border)">
          <span class="key-mono" onclick="copyText('<?= $r['license_key'] ?>')"><?= $r['license_key'] ?></span>
          <span style="color:#fbbf24;font-size:.78rem"><?= formatDate($r['expires_at'],'d/m/Y') ?></span>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-header">
        <span class="panel-title"><i class="bi bi-journal-text"></i> Hoạt động gần đây</span>
        <a href="logs.php" style="font-size:.78rem;color:#7c3aed;text-decoration:none">Xem tất cả →</a>
      </div>
      <div class="panel-body p-0">
        <?php foreach ($recentLogs as $l): ?>
        <div style="padding:9px 16px;border-bottom:1px solid var(--border);display:flex;gap:10px;align-items:flex-start">
          <div style="width:7px;height:7px;background:#7c3aed;border-radius:50%;margin-top:5px;flex-shrink:0"></div>
          <div style="flex:1;min-width:0">
            <div style="font-size:.8rem;color:#c4c4dc"><?= clean($l['action']) ?></div>
            <div style="font-size:.72rem;color:var(--text-dim);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= clean($l['description']??'') ?></div>
          </div>
          <div style="font-size:.7rem;color:var(--text-dim);flex-shrink:0"><?= formatDate($l['created_at'],'H:i d/m') ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('keysChart'),{
  type:'bar',
  data:{labels:<?= $chartLabels ?>,datasets:[{label:'Keys tạo mới',data:<?= $chartValues ?>,backgroundColor:'rgba(124,58,237,.6)',borderColor:'#7c3aed',borderWidth:1,borderRadius:4}]},
  options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{ticks:{color:'#7070a0',font:{size:10}},grid:{color:'#1f1f35'}},y:{ticks:{color:'#7070a0',font:{size:10}},grid:{color:'#1f1f35'},beginAtZero:true}}}
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
