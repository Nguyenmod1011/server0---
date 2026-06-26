<?php
// ============================================================
// admin/keys.php – Quản lý License Keys
// ============================================================
define('IN_ADMIN', true);
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/auth.php';

$db  = getDB();
$msg = $err = '';

// ── AJAX / POST handler ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $act = $_POST['act'] ?? '';

    // Chỉ admin hoặc reseller còn quota
    $isAdm = isAdmin();
    $resId  = currentResellerId();

    // ── Tạo keys ──────────────────────────────────────────
    if ($act === 'generate') {
        if (!verifyCsrf($_POST['_csrf'] ?? '')) die(json_encode(['ok'=>false,'msg'=>'CSRF error']));

        $qty     = min((int)($_POST['qty'] ?? 1), 500);
        $days    = max(1, (int)($_POST['days'] ?? 30));
        $maxDev  = max(1, (int)($_POST['max_devices'] ?? 1));
        $note    = substr($_POST['note'] ?? '', 0, 255);
        $prefix  = strtoupper(preg_replace('/[^A-Z0-9]/', '', $_POST['prefix'] ?? ''));

        // Reseller: kiểm tra quota
        if (!$isAdm) {
            $res = $db->prepare("SELECT key_quota, keys_created FROM resellers WHERE id=?");
            $res->execute([$resId]);
            $r = $res->fetch();
            if (!$r || ($r['keys_created'] + $qty) > $r['key_quota']) {
                echo json_encode(['ok'=>false,'msg'=>'Vượt quota cho phép']);exit;
            }
        }

        $keys = [];
        $stmt = $db->prepare(
            "INSERT INTO license_keys (license_key, duration_days, max_devices, note, created_by_admin, created_by_reseller)
             VALUES (?,?,?,?,?,?)"
        );

        $db->beginTransaction();
        try {
            for ($i = 0; $i < $qty; $i++) {
                $rawKey = generateUniqueKey($db);
                // Thêm prefix nếu có
                $finalKey = $prefix ? $prefix . '-' . $rawKey : $rawKey;
                // Kiểm tra lại nếu có prefix
                if ($prefix) {
                    $ck = $db->prepare("SELECT id FROM license_keys WHERE license_key=?");
                    $ck->execute([$finalKey]);
                    if ($ck->fetch()) { $finalKey = $rawKey; } // fallback
                }
                $stmt->execute([
                    $finalKey, $days, $maxDev, $note,
                    $isAdm ? currentAdminId() : null,
                    $isAdm ? null : $resId
                ]);
                $keys[] = $finalKey;
            }
            if (!$isAdm) {
                $db->prepare("UPDATE resellers SET keys_created = keys_created + ? WHERE id=?")
                   ->execute([$qty, $resId]);
            }
            $db->commit();
            writeLog($db, 'generate_keys', "Tạo $qty key, $days ngày, max $maxDev thiết bị", isAdmin()?'admin':'reseller', currentUserId());
            echo json_encode(['ok'=>true,'keys'=>$keys,'count'=>count($keys)]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
        }
        exit;
    }

    // ── Xóa key ────────────────────────────────────────────
    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // Xóa cache + bindings trước
            $db->prepare("DELETE FROM device_key_cache WHERE key_id=?")->execute([$id]);
            $db->prepare("DELETE FROM device_bindings WHERE key_id=?")->execute([$id]);
            $db->prepare("DELETE FROM license_keys WHERE id=?")->execute([$id]);
            writeLog($db, 'delete_key', "Xóa key ID: $id", isAdmin()?'admin':'reseller', currentUserId());
            echo json_encode(['ok'=>true]);
        } else {
            echo json_encode(['ok'=>false,'msg'=>'ID không hợp lệ']);
        }
        exit;
    }

    // ── Xóa hàng loạt ──────────────────────────────────────
    if ($act === 'bulk_delete') {
        $ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')));
        if (!empty($ids)) {
            $pl = implode(',', $ids);
            $db->exec("DELETE FROM device_key_cache WHERE key_id IN ($pl)");
            $db->exec("DELETE FROM device_bindings WHERE key_id IN ($pl)");
            $db->exec("DELETE FROM license_keys WHERE id IN ($pl)");
            writeLog($db, 'bulk_delete_keys', "Xóa ".count($ids)." keys", isAdmin()?'admin':'reseller', currentUserId());
        }
        echo json_encode(['ok'=>true,'count'=>count($ids)]);
        exit;
    }

    // ── Toggle kích hoạt/vô hiệu ───────────────────────────
    if ($act === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $cur = (int)($_POST['current'] ?? 1);
        $new = $cur ? 0 : 1;
        $db->prepare("UPDATE license_keys SET is_active=? WHERE id=?")->execute([$new, $id]);
        writeLog($db, 'toggle_key', "Key ID $id → ".($new?'bật':'tắt'), 'admin', currentAdminId());
        echo json_encode(['ok'=>true,'active'=>$new]);
        exit;
    }

    // ── Reset thiết bị (xóa bindings) ──────────────────────
    if ($act === 'reset_devices') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            // Lấy key để log
            $kRow = $db->prepare("SELECT license_key FROM license_keys WHERE id=?");
            $kRow->execute([$id]);
            $kData = $kRow->fetch();

            $db->prepare("DELETE FROM device_bindings WHERE key_id=?")->execute([$id]);
            $db->prepare("DELETE FROM device_key_cache WHERE key_id=?")->execute([$id]);
            writeLog($db, 'reset_devices', "Reset thiết bị key: ".($kData['license_key']??$id), 'admin', currentAdminId());
            echo json_encode(['ok'=>true]);
        } else {
            echo json_encode(['ok'=>false,'msg'=>'ID không hợp lệ']);
        }
        exit;
    }

    // ── Reset key (xóa binding + đặt lại trạng thái chưa dùng) ──
    if ($act === 'reset_key') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0 && isAdmin()) {
            $db->prepare("DELETE FROM device_bindings WHERE key_id=?")->execute([$id]);
            $db->prepare("DELETE FROM device_key_cache WHERE key_id=?")->execute([$id]);
            $db->prepare("UPDATE license_keys SET is_used=0, activated_at=NULL, expires_at=NULL WHERE id=?")->execute([$id]);
            writeLog($db, 'reset_key', "Reset hoàn toàn key ID: $id", 'admin', currentAdminId());
            echo json_encode(['ok'=>true]);
        } else {
            echo json_encode(['ok'=>false,'msg'=>'Không có quyền hoặc ID không hợp lệ']);
        }
        exit;
    }

    // ── Kiểm tra 1 key cụ thể ──────────────────────────────
    if ($act === 'check_key') {
        $k    = strtoupper(trim($_POST['key'] ?? ''));
        $stmt = $db->prepare("
            SELECT lk.*,
                   COUNT(db.id) as device_count,
                   a.username as admin_name, r.username as reseller_name
            FROM license_keys lk
            LEFT JOIN device_bindings db ON lk.id = db.key_id
            LEFT JOIN admin_users a ON lk.created_by_admin = a.id
            LEFT JOIN resellers   r ON lk.created_by_reseller = r.id
            WHERE lk.license_key = ?
            GROUP BY lk.id
        ");
        $stmt->execute([$k]);
        $row = $stmt->fetch();
        if (!$row) { echo json_encode(['ok'=>false,'msg'=>'Key không tồn tại']); exit; }

        // Devices
        $devs = $db->prepare("SELECT * FROM device_bindings WHERE key_id=? ORDER BY first_seen DESC");
        $devs->execute([$row['id']]);
        $devices = $devs->fetchAll();

        $status = 'Chưa kích hoạt';
        $statusCls = 'new';
        if (!$row['is_active']) { $status='Bị vô hiệu'; $statusCls='inactive'; }
        elseif ($row['is_used'] && strtotime($row['expires_at']) < time()) { $status='Hết hạn'; $statusCls='expired'; }
        elseif ($row['is_used']) { $status='Đang hoạt động'; $statusCls='valid'; }

        echo json_encode([
            'ok' => true,
            'key' => $row['license_key'],
            'status' => $status,
            'status_cls' => $statusCls,
            'duration_days' => $row['duration_days'],
            'max_devices' => $row['max_devices'],
            'device_count' => $row['device_count'],
            'activated_at' => $row['activated_at'] ? formatDate($row['activated_at']) : null,
            'expires_at' => $row['expires_at'] ? formatDate($row['expires_at']) : null,
            'days_remaining' => daysRemaining($row['expires_at']),
            'created_at' => formatDate($row['created_at']),
            'note' => $row['note'],
            'creator' => $row['admin_name'] ?? $row['reseller_name'] ?? 'N/A',
            'devices' => array_map(fn($d) => [
                'device_name'  => $d['device_name'],
                'device_model' => $d['device_model'],
                'ios_version'  => $d['ios_version'],
                'ip_address'   => $d['ip_address'],
                'first_seen'   => formatDate($d['first_seen']),
                'last_seen'    => formatDate($d['last_seen']),
            ], $devices),
        ]);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Action không hợp lệ']);
    exit;
}

// ── Lấy danh sách keys ─────────────────────────────────────
$where = isAdmin() ? '' : 'WHERE lk.created_by_reseller = ' . currentResellerId();
$filter = $_GET['filter'] ?? 'all';
$filterWhere = match($filter) {
    'active'   => ($where ? ' AND' : ' WHERE') . " lk.is_active=1 AND lk.is_used=1 AND lk.expires_at > NOW()",
    'expired'  => ($where ? ' AND' : ' WHERE') . " lk.is_used=1 AND lk.expires_at < NOW()",
    'unused'   => ($where ? ' AND' : ' WHERE') . " lk.is_used=0",
    'disabled' => ($where ? ' AND' : ' WHERE') . " lk.is_active=0",
    default    => '',
};

$keys = $db->query("
    SELECT lk.*, COUNT(db.id) as device_count,
           a.username as admin_name, r.username as reseller_name
    FROM license_keys lk
    LEFT JOIN device_bindings db ON lk.id = db.key_id
    LEFT JOIN admin_users a ON lk.created_by_admin = a.id
    LEFT JOIN resellers   r ON lk.created_by_reseller = r.id
    $where $filterWhere
    GROUP BY lk.id
    ORDER BY lk.created_at DESC
")->fetchAll();

// ── Mở modal generate nếu có query param ──────────────────
$openGenModal = isset($_GET['modal']) && $_GET['modal'] === 'generate';

$pageTitle  = 'Quản lý License Keys';
$activePage = 'keys';
include __DIR__ . '/includes/header.php';
?>

<!-- ── Toolbar ── -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <div>
    <h5 style="color:#e0e0ff;margin:0"><i class="bi bi-key-fill"></i> License Keys</h5>
    <small style="color:var(--text-dim)"><?= count($keys) ?> key hiển thị</small>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <!-- Export dropdown -->
    <div class="dropdown">
      <button class="btn btn-xs btn-success-xs dropdown-toggle" data-bs-toggle="dropdown">
        <i class="bi bi-download"></i> Xuất file
      </button>
      <ul class="dropdown-menu dropdown-menu-dark" style="background:#1a1a2e;border:1px solid #2a2a45;border-radius:10px;min-width:160px">
        <li><a class="dropdown-item" href="export_keys.php?format=csv&filter=<?= $filter ?>" style="color:#c4c4dc;font-size:.83rem"><i class="bi bi-file-earmark-spreadsheet-fill" style="color:#4ade80"></i> Xuất CSV (Excel)</a></li>
        <li><a class="dropdown-item" href="export_keys.php?format=txt&filter=<?= $filter ?>" style="color:#c4c4dc;font-size:.83rem"><i class="bi bi-file-earmark-text-fill" style="color:#38bdf8"></i> Xuất TXT (chỉ key)</a></li>
        <li><a class="dropdown-item" href="export_keys.php?format=json&filter=<?= $filter ?>" style="color:#c4c4dc;font-size:.83rem"><i class="bi bi-braces" style="color:#a78bfa"></i> Xuất JSON</a></li>
        <li><hr class="dropdown-divider" style="border-color:#2a2a45"></li>
        <li><a class="dropdown-item" href="#" onclick="exportSelected()" style="color:#c4c4dc;font-size:.83rem"><i class="bi bi-check2-square" style="color:#fbbf24"></i> Xuất đã chọn (TXT)</a></li>
      </ul>
    </div>
    <button class="btn btn-xs btn-info-xs" onclick="openCheckModal()">
      <i class="bi bi-search"></i> Check Key
    </button>
    <button class="btn btn-xs btn-warn-xs" id="bulkDeleteBtn" style="display:none" onclick="bulkDelete()">
      <i class="bi bi-trash-fill"></i> Xóa đã chọn
    </button>
    <button class="btn btn-primary btn-xs" onclick="openGenModal()">
      <i class="bi bi-plus-circle-fill"></i> Tạo Key
    </button>
  </div>
</div>

<!-- Filter tabs -->
<div class="d-flex gap-2 flex-wrap mb-3">
  <?php
  $tabs = ['all'=>'Tất cả','active'=>'Đang dùng','expired'=>'Hết hạn','unused'=>'Chưa dùng','disabled'=>'Vô hiệu'];
  foreach($tabs as $k=>$v):
  ?>
  <a href="?filter=<?=$k?>" class="btn btn-xs <?=$filter===$k?'btn-primary':'btn-outline-secondary'?>"><?=$v?></a>
  <?php endforeach; ?>
</div>

<!-- Keys Table -->
<div class="panel">
  <div class="panel-body p-0">
    <div class="table-responsive">
    <table id="keysTable" class="tbl display nowrap" style="min-width:900px">
      <thead>
        <tr>
          <th><input type="checkbox" id="checkAll" onchange="toggleAll(this,'keyIds')"></th>
          <th>License Key</th>
          <th>Trạng thái</th>
          <th>Thời hạn</th>
          <th>Thiết bị</th>
          <th>Hết hạn</th>
          <th>Tạo bởi</th>
          <th>Ngày tạo</th>
          <th>Thao tác</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($keys as $k):
        $now = time();
        if (!$k['is_active']) { $st='disabled'; $stTxt='Vô hiệu'; }
        elseif (!$k['is_used']) { $st='new'; $stTxt='Chưa kích hoạt'; }
        elseif (strtotime($k['expires_at']) < $now) { $st='expired'; $stTxt='Hết hạn'; }
        else { $st='valid'; $stTxt='Hoạt động'; }
        $daysLeft = daysRemaining($k['expires_at']);
      ?>
      <tr>
        <td><input type="checkbox" name="keyIds" value="<?=$k['id']?>" onchange="updateBulk()"></td>
        <td>
          <span class="key-mono" onclick="copyText('<?=clean($k['license_key'])?>','Key')" title="Click để copy">
            <?=clean($k['license_key'])?>
          </span>
          <?php if($k['note']): ?><br><small style="color:var(--text-dim)"><?=clean($k['note'])?></small><?php endif; ?>
        </td>
        <td><span class="badge-<?=$st?>"><?=$stTxt?></span></td>
        <td><span style="color:#a78bfa"><?=$k['duration_days']?> ngày</span></td>
        <td>
          <span class="badge-used"><?=$k['device_count']?>/<?=$k['max_devices']?></span>
        </td>
        <td>
          <?php if ($k['is_used'] && $k['expires_at']): ?>
            <span style="color:<?=$daysLeft<=3?'#f87171':($daysLeft<=7?'#fbbf24':'#c4c4dc');?>;font-size:.8rem">
              <?=formatDate($k['expires_at'],'d/m/Y')?>
              <?php if($daysLeft > 0): ?><br><small style="color:var(--text-dim)"><?=$daysLeft?> ngày còn lại</small><?php endif; ?>
            </span>
          <?php else: ?><span style="color:var(--text-dim);font-size:.8rem">—</span><?php endif; ?>
        </td>
        <td style="font-size:.78rem;color:var(--text-dim)"><?=clean($k['admin_name']??$k['reseller_name']??'—')?></td>
        <td style="font-size:.78rem;color:var(--text-dim)"><?=formatDate($k['created_at'],'d/m/Y')?></td>
        <td>
          <div class="d-flex gap-1 flex-wrap">
            <!-- Copy -->
            <button class="btn btn-xs btn-info-xs" onclick="copyText('<?=clean($k['license_key'])?>','Key')" title="Copy key"><i class="bi bi-clipboard"></i></button>
            <!-- Check -->
            <button class="btn btn-xs btn-success-xs" onclick="checkKey('<?=clean($k['license_key'])?>')" title="Chi tiết"><i class="bi bi-eye"></i></button>
            <?php if(isAdmin()): ?>
            <!-- Toggle -->
            <button class="btn btn-xs <?=$k['is_active']?'btn-warn-xs':'btn-success-xs'?>"
              onclick="toggleKey(<?=$k['id']?>,<?=$k['is_active']?>)"
              title="<?=$k['is_active']?'Vô hiệu hóa':'Kích hoạt'?>">
              <i class="bi bi-<?=$k['is_active']?'toggle-on':'toggle-off'?>"></i>
            </button>
            <!-- Reset devices -->
            <button class="btn btn-xs btn-warn-xs" onclick="resetDevices(<?=$k['id']?>)" title="Reset thiết bị"><i class="bi bi-phone-fill"></i> Reset</button>
            <!-- Reset full -->
            <button class="btn btn-xs" style="background:rgba(168,85,247,.15);color:#c084fc"
              onclick="resetKey(<?=$k['id']?>)" title="Reset key hoàn toàn"><i class="bi bi-arrow-counterclockwise"></i></button>
            <!-- Delete -->
            <button class="btn btn-xs btn-danger-xs" onclick="deleteKey(<?=$k['id']?>)" title="Xóa"><i class="bi bi-trash"></i></button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ══════════ MODAL: Tạo Key ══════════ -->
<div class="modal fade" id="genModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content" style="background:#1a1a2e;border:1px solid #2a2a45;border-radius:14px">
      <div class="modal-header" style="border-color:#2a2a45">
        <h5 class="modal-title" style="color:#e0e0ff"><i class="bi bi-plus-circle-fill"></i> Tạo License Key</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Số lượng key</label>
            <input type="number" id="genQty" class="form-control" value="1" min="1" max="500">
            <small style="color:var(--text-dim)">Tối đa 500 key/lần</small>
          </div>
          <div class="col-md-4">
            <label class="form-label">Thời hạn (ngày)</label>
            <select id="genDays" class="form-select">
              <option value="1">1 ngày</option>
              <option value="3">3 ngày</option>
              <option value="7">7 ngày</option>
              <option value="15">15 ngày</option>
              <option value="30" selected>30 ngày</option>
              <option value="60">60 ngày</option>
              <option value="90">90 ngày</option>
              <option value="180">6 tháng</option>
              <option value="365">1 năm</option>
              <option value="custom">Tùy chỉnh...</option>
            </select>
          </div>
          <div class="col-md-4" id="customDaysWrap" style="display:none">
            <label class="form-label">Số ngày tùy chỉnh</label>
            <input type="number" id="customDays" class="form-control" value="30" min="1" max="9999">
          </div>
          <div class="col-md-4">
            <label class="form-label">Giới hạn thiết bị / key</label>
            <input type="number" id="genMaxDev" class="form-control" value="1" min="1" max="100">
          </div>
          <div class="col-md-4">
            <label class="form-label">Tiền tố (prefix) <small style="color:var(--text-dim)">(tuỳ chọn)</small></label>
            <input type="text" id="genPrefix" class="form-control" placeholder="VD: VIP, ADMIN..." maxlength="10">
          </div>
          <div class="col-md-4">
            <label class="form-label">Ghi chú</label>
            <input type="text" id="genNote" class="form-control" placeholder="Ghi chú..." maxlength="255">
          </div>
        </div>
      </div>
      <div class="modal-footer" style="border-color:#2a2a45">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Hủy</button>
        <button type="button" class="btn btn-primary" onclick="doGenerate()">
          <i class="bi bi-key-fill"></i> Tạo Key
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════ MODAL: Kết quả key vừa tạo ══════════ -->
<div class="modal fade" id="resultModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content" style="background:#1a1a2e;border:1px solid #2a2a45;border-radius:14px">
      <div class="modal-header" style="border-color:#2a2a45">
        <h5 class="modal-title" style="color:#4ade80"><i class="bi bi-check-circle-fill"></i> Keys đã tạo thành công!</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="location.reload()"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex gap-2 mb-3">
          <button class="btn btn-xs btn-primary" onclick="copyAllKeys()"><i class="bi bi-clipboard-check"></i> Copy tất cả</button>
          <span id="resultCount" style="color:var(--text-dim);font-size:.85rem;align-self:center"></span>
        </div>
        <div id="resultGrid" class="row g-2"></div>
      </div>
      <div class="modal-footer" style="border-color:#2a2a45">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal" onclick="location.reload()">Đóng & Tải lại</button>
      </div>
    </div>
  </div>
</div>

<!-- ══════════ MODAL: Check Key ══════════ -->
<div class="modal fade" id="checkModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content" style="background:#1a1a2e;border:1px solid #2a2a45;border-radius:14px">
      <div class="modal-header" style="border-color:#2a2a45">
        <h5 class="modal-title" style="color:#e0e0ff"><i class="bi bi-search"></i> Kiểm tra Key</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex gap-2 mb-3">
          <input type="text" id="checkKeyInput" class="form-control" placeholder="Nhập license key..." style="font-family:monospace;text-transform:uppercase" oninput="this.value=this.value.toUpperCase()">
          <button class="btn btn-primary btn-xs" style="padding:8px 16px" onclick="checkKeyBtn()"><i class="bi bi-search"></i></button>
        </div>
        <div id="checkResult"></div>
      </div>
    </div>
  </div>
</div>

<script>
const CSRF = '<?= csrfToken() ?>';

// ── DataTable ──────────────────────────────────────────────
$(document).ready(function(){
  $('#keysTable').DataTable({
    order:[[7,'desc']],
    pageLength:25,
    columnDefs:[{orderable:false,targets:[0,8]}],
    language:{search:'<i class="bi bi-search"></i>'},
  });
  <?php if($openGenModal): ?>openGenModal();<?php endif; ?>
});

// ── Generate modal ──────────────────────────────────────────
function openGenModal(){ new bootstrap.Modal(document.getElementById('genModal')).show(); }

document.getElementById('genDays').addEventListener('change', function(){
  document.getElementById('customDaysWrap').style.display = this.value==='custom'?'block':'none';
});

let generatedKeys = [];
function doGenerate(){
  const qty  = parseInt(document.getElementById('genQty').value)||1;
  const daysSel = document.getElementById('genDays').value;
  const days = daysSel==='custom' ? parseInt(document.getElementById('customDays').value)||30 : parseInt(daysSel);
  const maxDev  = parseInt(document.getElementById('genMaxDev').value)||1;
  const prefix  = document.getElementById('genPrefix').value.trim().toUpperCase();
  const note    = document.getElementById('genNote').value.trim();

  if(qty<1||qty>500){ toast('Số lượng 1–500','error'); return; }
  if(days<1){ toast('Thời hạn tối thiểu 1 ngày','error'); return; }

  showLoading();
  $.post('keys.php',{
    act:'generate',_csrf:CSRF,qty,days,max_devices:maxDev,prefix,note
  },function(r){
    hideLoading();
    if(r.ok){
      generatedKeys = r.keys;
      bootstrap.Modal.getInstance(document.getElementById('genModal')).hide();
      showResultModal(r.keys);
    } else {
      toast(r.msg||'Lỗi tạo key','error');
    }
  },'json').fail(()=>{ hideLoading(); toast('Kết nối thất bại','error'); });
}

function showResultModal(keys){
  const grid = document.getElementById('resultGrid');
  document.getElementById('resultCount').textContent = keys.length + ' key';
  grid.innerHTML = keys.map((k,i)=>`
    <div class="col-12 col-md-6 col-xl-4">
      <div style="background:#0f0f1a;border:1px solid #2a2a45;border-radius:8px;padding:8px 12px;display:flex;align-items:center;justify-content:space-between;gap:8px">
        <span class="key-mono" style="font-size:.82rem">${k}</span>
        <button class="btn btn-xs btn-info-xs" onclick="copyText('${k}','Key')" style="flex-shrink:0"><i class="bi bi-clipboard"></i></button>
      </div>
    </div>
  `).join('');
  new bootstrap.Modal(document.getElementById('resultModal')).show();
}

function copyAllKeys(){
  copyText(generatedKeys.join('\n'), generatedKeys.length + ' keys');
}

// ── Check Key ─────────────────────────────────────────────
function openCheckModal(){ new bootstrap.Modal(document.getElementById('checkModal')).show(); }
function checkKey(k){ document.getElementById('checkKeyInput').value=k; openCheckModal(); checkKeyBtn(); }

function checkKeyBtn(){
  const k = document.getElementById('checkKeyInput').value.trim();
  if(!k){ toast('Nhập key trước','error'); return; }
  showLoading();
  $.post('keys.php',{act:'check_key',_csrf:CSRF,key:k},function(r){
    hideLoading();
    if(!r.ok){ document.getElementById('checkResult').innerHTML=`<div class="alert-s error">${r.msg}</div>`; return; }
    const statusClsMap = {valid:'badge-valid',expired:'badge-expired',inactive:'badge-inactive',new:'badge-new',disabled:'badge-inactive'};
    let devHtml = '';
    if(r.devices && r.devices.length>0){
      devHtml = '<div style="margin-top:12px"><div style="font-size:.8rem;color:var(--text-dim);margin-bottom:6px;font-weight:600">THIẾT BỊ ĐÃ ĐĂNG KÝ</div>';
      devHtml += r.devices.map(d=>`
        <div style="background:#0f0f1a;border:1px solid #2a2a45;border-radius:8px;padding:10px 12px;margin-bottom:6px;font-size:.8rem">
          <div><b style="color:#c4c4dc">${d.device_name||'Unknown'}</b> <span style="color:var(--text-dim)">${d.device_model||''}</span></div>
          <div style="color:var(--text-dim)">iOS ${d.ios_version||'?'} | IP: ${d.ip_address||'?'}</div>
          <div style="color:var(--text-dim)">Lần cuối: ${d.last_seen}</div>
        </div>`).join('');
      devHtml += '</div>';
    }
    document.getElementById('checkResult').innerHTML = `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
        <div style="background:#0f0f1a;border:1px solid #2a2a45;border-radius:8px;padding:12px">
          <div style="font-size:.72rem;color:var(--text-dim);margin-bottom:4px">KEY</div>
          <div class="key-mono">${r.key}</div>
        </div>
        <div style="background:#0f0f1a;border:1px solid #2a2a45;border-radius:8px;padding:12px">
          <div style="font-size:.72rem;color:var(--text-dim);margin-bottom:4px">TRẠNG THÁI</div>
          <span class="${statusClsMap[r.status_cls]||'badge-new'}">${r.status}</span>
        </div>
        <div style="background:#0f0f1a;border:1px solid #2a2a45;border-radius:8px;padding:12px">
          <div style="font-size:.72rem;color:var(--text-dim);margin-bottom:4px">THỜI HẠN</div>
          <div style="color:#a78bfa;font-weight:600">${r.duration_days} ngày</div>
        </div>
        <div style="background:#0f0f1a;border:1px solid #2a2a45;border-radius:8px;padding:12px">
          <div style="font-size:.72rem;color:var(--text-dim);margin-bottom:4px">THIẾT BỊ</div>
          <div style="color:#38bdf8;font-weight:600">${r.device_count}/${r.max_devices}</div>
        </div>
        ${r.expires_at ? `<div style="background:#0f0f1a;border:1px solid #2a2a45;border-radius:8px;padding:12px">
          <div style="font-size:.72rem;color:var(--text-dim);margin-bottom:4px">HẾT HẠN</div>
          <div style="color:#fbbf24">${r.expires_at}</div>
          <div style="color:var(--text-dim);font-size:.75rem">${r.days_remaining} ngày còn lại</div>
        </div>` : ''}
        <div style="background:#0f0f1a;border:1px solid #2a2a45;border-radius:8px;padding:12px">
          <div style="font-size:.72rem;color:var(--text-dim);margin-bottom:4px">TẠO BỞI</div>
          <div style="color:#c4c4dc">${r.creator}</div>
        </div>
      </div>
      ${r.note ? `<div style="background:#0f0f1a;border:1px solid #2a2a45;border-radius:8px;padding:10px 12px;font-size:.82rem;color:var(--text-dim)"><i class="bi bi-sticky-fill"></i> ${r.note}</div>` : ''}
      ${devHtml}
    `;
  },'json').fail(()=>{ hideLoading(); toast('Lỗi kết nối','error'); });
}

// ── Actions ───────────────────────────────────────────────
function deleteKey(id){
  confirmAction('Xóa key này?','Hành động không thể hoàn tác!','warning',()=>{
    ajax('keys.php',{act:'delete',_csrf:CSRF,id},r=>{
      if(r.ok){ toast('Đã xóa key'); setTimeout(()=>location.reload(),800); }
      else toast(r.msg,'error');
    });
  });
}

function toggleKey(id,cur){
  const label = cur ? 'Vô hiệu hóa' : 'Kích hoạt';
  confirmAction(label+' key này?','','question',()=>{
    ajax('keys.php',{act:'toggle',_csrf:CSRF,id,current:cur},r=>{
      if(r.ok){ toast(label+' thành công'); setTimeout(()=>location.reload(),800); }
    });
  });
}

function resetDevices(id){
  confirmAction('Reset thiết bị?','Tất cả thiết bị sẽ bị ngắt khỏi key này.','warning',()=>{
    ajax('keys.php',{act:'reset_devices',_csrf:CSRF,id},r=>{
      if(r.ok){ toast('Đã reset thiết bị'); setTimeout(()=>location.reload(),800); }
    });
  });
}

function resetKey(id){
  confirmAction('Reset hoàn toàn key?','Key sẽ trở về trạng thái CHƯA kích hoạt, tất cả thiết bị bị xóa.','warning',()=>{
    ajax('keys.php',{act:'reset_key',_csrf:CSRF,id},r=>{
      if(r.ok){ toast('Đã reset key hoàn toàn'); setTimeout(()=>location.reload(),800); }
      else toast(r.msg,'error');
    });
  });
}

// ── Bulk Delete ────────────────────────────────────────────
function updateBulk(){
  const checked = document.querySelectorAll('input[name="keyIds"]:checked').length;
  document.getElementById('bulkDeleteBtn').style.display = checked>0 ? 'inline-flex' : 'none';
}

function exportSelected(){
  const keys = [...document.querySelectorAll('input[name="keyIds"]:checked')]
    .map(cb => cb.closest('tr').querySelector('.key-mono')?.textContent?.trim())
    .filter(Boolean);
  if(!keys.length){ toast('Chưa chọn key nào','error'); return; }
  const blob = new Blob([keys.join('\n')], {type:'text/plain'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'keys_selected_' + keys.length + '.txt';
  a.click();
  toast('Đã xuất ' + keys.length + ' key');
}

function bulkDelete(){
  const ids = [...document.querySelectorAll('input[name="keyIds"]:checked')].map(cb=>cb.value);
  if(!ids.length){ toast('Chưa chọn key nào','error'); return; }
  confirmAction(`Xóa ${ids.length} key?`,'Không thể hoàn tác!','warning',()=>{
    ajax('keys.php',{act:'bulk_delete',_csrf:CSRF,ids:ids.join(',')},r=>{
      if(r.ok){ toast('Đã xóa '+r.count+' key'); setTimeout(()=>location.reload(),800); }
    });
  });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
