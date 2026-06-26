<?php
// ============================================================
// admin/devices.php – Quản lý Thiết bị
// ============================================================
define('IN_ADMIN', true);
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/auth.php';

$db = getDB();

// ── AJAX POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $act = $_POST['act'] ?? '';

    // Xóa 1 device binding
    if ($act === 'remove') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // Lấy device_id để xóa cache
            $row = $db->prepare("SELECT device_id, key_id FROM device_bindings WHERE id=?");
            $row->execute([$id]);
            $r = $row->fetch();
            if ($r) {
                $db->prepare("DELETE FROM device_bindings WHERE id=?")->execute([$id]);
                $db->prepare("DELETE FROM device_key_cache WHERE device_id=? AND key_id=?")
                   ->execute([$r['device_id'], $r['key_id']]);
                writeLog($db, 'remove_device', "Xóa device binding ID: $id", isAdmin()?'admin':'reseller', currentUserId());
            }
            echo json_encode(['ok'=>true]);
        } else {
            echo json_encode(['ok'=>false,'msg'=>'ID không hợp lệ']);
        }
        exit;
    }

    // Xóa tất cả devices của 1 key
    if ($act === 'remove_all_key') {
        if (!isAdmin()) { echo json_encode(['ok'=>false,'msg'=>'Không có quyền']); exit; }
        $keyId = (int)($_POST['key_id'] ?? 0);
        if ($keyId > 0) {
            $db->prepare("DELETE FROM device_bindings WHERE key_id=?")->execute([$keyId]);
            $db->prepare("DELETE FROM device_key_cache WHERE key_id=?")->execute([$keyId]);
            writeLog($db, 'remove_all_devices', "Xóa tất cả devices key_id: $keyId", 'admin', currentAdminId());
        }
        echo json_encode(['ok'=>true]);
        exit;
    }

    // Xóa cache key của device (force re-enter key)
    if ($act === 'clear_cache') {
        $deviceId = trim($_POST['device_id'] ?? '');
        if ($deviceId) {
            $db->prepare("DELETE FROM device_key_cache WHERE device_id=?")->execute([$deviceId]);
            echo json_encode(['ok'=>true]);
        } else {
            echo json_encode(['ok'=>false,'msg'=>'Device ID trống']);
        }
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Action không hợp lệ']); exit;
}

// ── Tìm kiếm & filter ─────────────────────────────────────
$search   = trim($_GET['q'] ?? '');
$keyFilter = trim($_GET['key'] ?? '');

$where    = isAdmin() ? '1=1' : 'lk.created_by_reseller = ' . currentResellerId();
$params   = [];

if ($search) {
    $where  .= " AND (db.device_name LIKE ? OR db.device_model LIKE ? OR db.device_id LIKE ? OR db.ip_address LIKE ?)";
    $s = "%$search%";
    $params = array_merge($params, [$s,$s,$s,$s]);
}
if ($keyFilter) {
    $where .= " AND lk.license_key = ?";
    $params[] = $keyFilter;
}

$devices = $db->prepare("
    SELECT db.*, lk.license_key, lk.expires_at, lk.is_active as key_active,
           (SELECT COUNT(*) FROM device_key_cache dkc WHERE dkc.device_id = db.device_id) as has_cache
    FROM device_bindings db
    JOIN license_keys lk ON db.key_id = lk.id
    WHERE $where
    ORDER BY db.last_seen DESC
    LIMIT 500
");
$params ? $devices->execute($params) : $devices->execute();
$devices = $devices->fetchAll();

// Tổng quan
$totalDevices = (int)$db->query("SELECT COUNT(*) FROM device_bindings")->fetchColumn();
$activeToday  = (int)$db->query("SELECT COUNT(*) FROM device_bindings WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
$uniqueDevices= (int)$db->query("SELECT COUNT(DISTINCT device_id) FROM device_bindings")->fetchColumn();
$cacheCount   = (int)$db->query("SELECT COUNT(*) FROM device_key_cache")->fetchColumn();

$pageTitle  = 'Quản lý Thiết bị';
$activePage = 'devices';
include __DIR__ . '/includes/header.php';
?>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="bi bi-phone-fill"></i></div>
      <div><div class="stat-num"><?= $totalDevices ?></div><div class="stat-lbl">Tổng thiết bị</div></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="stat-icon green"><i class="bi bi-phone-vibrate-fill"></i></div>
      <div><div class="stat-num"><?= $activeToday ?></div><div class="stat-lbl">Hoạt động 24h</div></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="stat-icon purple"><i class="bi bi-fingerprint"></i></div>
      <div><div class="stat-num"><?= $uniqueDevices ?></div><div class="stat-lbl">Device ID duy nhất</div></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="stat-icon orange"><i class="bi bi-save-fill"></i></div>
      <div><div class="stat-num"><?= $cacheCount ?></div><div class="stat-lbl">Key đã lưu cache</div></div>
    </div>
  </div>
</div>

<!-- Search bar -->
<div class="panel mb-3">
  <div class="panel-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-5">
        <label class="form-label">Tìm thiết bị</label>
        <input type="text" name="q" class="form-control" placeholder="Tên máy, model, device ID, IP..." value="<?= clean($search) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Lọc theo Key</label>
        <input type="text" name="key" class="form-control" placeholder="XXXX-XXXX-XXXX-XXXX" style="font-family:monospace;text-transform:uppercase" oninput="this.value=this.value.toUpperCase()" value="<?= clean($keyFilter) ?>">
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary flex-grow-1"><i class="bi bi-search"></i> Tìm</button>
        <a href="devices.php" class="btn btn-outline-secondary"><i class="bi bi-x"></i></a>
      </div>
    </form>
  </div>
</div>

<!-- Devices Table -->
<div class="panel">
  <div class="panel-header">
    <span class="panel-title"><i class="bi bi-phone-fill"></i> Thiết bị đã đăng ký</span>
    <span style="color:var(--text-dim);font-size:.8rem"><?= count($devices) ?> kết quả</span>
  </div>
  <div class="panel-body p-0">
    <div class="table-responsive">
    <table id="devTable" class="tbl display nowrap" style="min-width:960px">
      <thead>
        <tr>
          <th>#</th>
          <th>Thiết bị</th>
          <th>Device ID</th>
          <th>iOS</th>
          <th>IP Address</th>
          <th>License Key</th>
          <th>Hết hạn Key</th>
          <th>Cache</th>
          <th>Lần cuối</th>
          <th>Thao tác</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($devices as $i => $d):
        $keyExp = $d['expires_at'] ? strtotime($d['expires_at']) : 0;
        $keyOk  = $d['key_active'] && ($keyExp === 0 || $keyExp > time());
        $lastSeenTs = strtotime($d['last_seen']);
        $isOnline = (time() - $lastSeenTs) < 300; // 5 phút
      ?>
      <tr>
        <td style="color:var(--text-dim);font-size:.78rem"><?= $i+1 ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:8px">
            <div style="width:32px;height:32px;background:#1f1f35;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <i class="bi bi-<?= str_contains(strtolower($d['device_model']??''), 'ipad') ? 'tablet' : 'phone' ?>-fill" style="color:#38bdf8"></i>
            </div>
            <div>
              <div style="font-size:.85rem;color:#e0e0ff"><?= clean($d['device_name'] ?: 'Unknown Device') ?></div>
              <div style="font-size:.72rem;color:var(--text-dim)"><?= clean($d['device_model'] ?: '—') ?></div>
            </div>
          </div>
        </td>
        <td>
          <span style="font-family:monospace;font-size:.75rem;color:#a78bfa;cursor:pointer"
                onclick="copyText('<?= clean($d['device_id']) ?>','Device ID')"
                title="Click để copy">
            <?= strlen($d['device_id']) > 18 ? substr(clean($d['device_id']),0,18).'...' : clean($d['device_id']) ?>
          </span>
        </td>
        <td><span style="font-size:.8rem;color:#c4c4dc">iOS <?= clean($d['ios_version'] ?: '?') ?></span></td>
        <td>
          <span style="font-family:monospace;font-size:.78rem;color:var(--text-dim)"><?= clean($d['ip_address'] ?: '—') ?></span>
        </td>
        <td>
          <span class="key-mono" style="font-size:.78rem" onclick="window.location.href='keys.php?filter=all'">
            <?= clean($d['license_key']) ?>
          </span>
          <br>
          <span class="<?= $keyOk ? 'badge-valid' : 'badge-expired' ?>"><?= $keyOk ? 'Còn hạn' : 'Hết hạn' ?></span>
        </td>
        <td style="font-size:.78rem;color:<?= $keyOk?'#4ade80':'#f87171' ?>">
          <?= $d['expires_at'] ? formatDate($d['expires_at'],'d/m/Y') : '—' ?>
        </td>
        <td>
          <?php if ($d['has_cache']): ?>
          <span class="badge-used" title="Key đã lưu cache – không cần nhập lại sau reinstall">
            <i class="bi bi-save-fill"></i> Có
          </span>
          <?php else: ?>
          <span style="color:var(--text-dim);font-size:.75rem">Không</span>
          <?php endif; ?>
        </td>
        <td>
          <div style="font-size:.78rem">
            <?php if ($isOnline): ?>
            <span style="color:#4ade80"><i class="bi bi-circle-fill" style="font-size:.5rem"></i> Online</span><br>
            <?php endif; ?>
            <span style="color:var(--text-dim)"><?= formatDate($d['last_seen'],'d/m H:i') ?></span>
          </div>
        </td>
        <td>
          <div class="d-flex gap-1">
            <?php if ($d['has_cache']): ?>
            <button class="btn btn-xs btn-warn-xs"
              onclick="clearCache('<?= clean($d['device_id']) ?>')"
              title="Xóa cache (buộc nhập lại key)">
              <i class="bi bi-x-circle"></i> Cache
            </button>
            <?php endif; ?>
            <button class="btn btn-xs btn-danger-xs"
              onclick="removeDevice(<?= $d['id'] ?>)"
              title="Xóa thiết bị này khỏi key">
              <i class="bi bi-trash-fill"></i>
            </button>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<script>
const CSRF='<?= csrfToken() ?>';

$(document).ready(function(){
  $('#devTable').DataTable({
    order:[[8,'desc']],
    pageLength:25,
    columnDefs:[{orderable:false,targets:[9]}]
  });
});

function removeDevice(id){
  confirmAction('Xóa thiết bị này?','Thiết bị sẽ bị ngắt khỏi key. Key cache cũng bị xóa.','warning',()=>{
    ajax('devices.php',{act:'remove',_csrf:CSRF,id},r=>{
      if(r.ok){ toast('Đã xóa thiết bị'); setTimeout(()=>location.reload(),800); }
      else toast(r.msg,'error');
    });
  });
}

function clearCache(deviceId){
  confirmAction('Xóa cache key?','Người dùng sẽ phải nhập lại key sau khi cài lại app.','question',()=>{
    ajax('devices.php',{act:'clear_cache',_csrf:CSRF,device_id:deviceId},r=>{
      if(r.ok){ toast('Đã xóa cache'); setTimeout(()=>location.reload(),800); }
      else toast(r.msg,'error');
    });
  });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
