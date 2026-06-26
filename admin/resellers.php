<?php
// ============================================================
// admin/resellers.php – Quản lý Đại lý
// ============================================================
define('IN_ADMIN', true);
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/auth.php';
requireAdmin(); // Chỉ admin mới vào được

$db = getDB();

// ── AJAX / POST handler ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $act = $_POST['act'] ?? '';

    // ── Thêm đại lý ──────────────────────────────────────
    if ($act === 'add') {
        if (!verifyCsrf($_POST['_csrf'] ?? '')) { echo json_encode(['ok'=>false,'msg'=>'CSRF error']); exit; }

        $username = strtolower(trim($_POST['username'] ?? ''));
        $password = $_POST['password'] ?? '';
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $quota    = max(1, (int)($_POST['key_quota'] ?? 100));
        $note     = substr($_POST['note'] ?? '', 0, 255);

        if (strlen($username) < 3) { echo json_encode(['ok'=>false,'msg'=>'Username tối thiểu 3 ký tự']); exit; }
        if (strlen($password) < 6) { echo json_encode(['ok'=>false,'msg'=>'Mật khẩu tối thiểu 6 ký tự']); exit; }
        if (!preg_match('/^[a-z0-9_]+$/', $username)) { echo json_encode(['ok'=>false,'msg'=>'Username chỉ gồm chữ thường, số và _']); exit; }

        // Kiểm tra trùng
        $ck = $db->prepare("SELECT id FROM resellers WHERE username=?");
        $ck->execute([$username]);
        if ($ck->fetch()) { echo json_encode(['ok'=>false,'msg'=>'Username đã tồn tại']); exit; }

        $stmt = $db->prepare("INSERT INTO resellers (username, password, email, phone, key_quota, note, created_by) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$username, hashPassword($password), $email, $phone, $quota, $note, currentAdminId()]);
        $newId = $db->lastInsertId();
        writeLog($db, 'add_reseller', "Thêm đại lý: $username (quota: $quota)", 'admin', currentAdminId());
        echo json_encode(['ok'=>true,'id'=>$newId,'username'=>$username]);
        exit;
    }

    // ── Đổi mật khẩu đại lý ──────────────────────────────
    if ($act === 'change_pass') {
        $id       = (int)($_POST['id'] ?? 0);
        $password = $_POST['password'] ?? '';
        if ($id < 1 || strlen($password) < 6) { echo json_encode(['ok'=>false,'msg'=>'Mật khẩu tối thiểu 6 ký tự']); exit; }
        $db->prepare("UPDATE resellers SET password=? WHERE id=?")->execute([hashPassword($password), $id]);
        writeLog($db, 'change_reseller_pass', "Đổi MK đại lý ID: $id", 'admin', currentAdminId());
        echo json_encode(['ok'=>true]);
        exit;
    }

    // ── Sửa thông tin đại lý ─────────────────────────────
    if ($act === 'edit') {
        $id    = (int)($_POST['id'] ?? 0);
        $quota = max(1, (int)($_POST['key_quota'] ?? 100));
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $note  = substr($_POST['note'] ?? '', 0, 255);
        if ($id < 1) { echo json_encode(['ok'=>false,'msg'=>'ID không hợp lệ']); exit; }
        $db->prepare("UPDATE resellers SET key_quota=?, email=?, phone=?, note=? WHERE id=?")
           ->execute([$quota, $email, $phone, $note, $id]);
        writeLog($db, 'edit_reseller', "Sửa đại lý ID: $id quota→$quota", 'admin', currentAdminId());
        echo json_encode(['ok'=>true]);
        exit;
    }

    // ── Toggle active ─────────────────────────────────────
    if ($act === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $cur = (int)($_POST['current'] ?? 1);
        $new = $cur ? 0 : 1;
        $db->prepare("UPDATE resellers SET is_active=? WHERE id=?")->execute([$new, $id]);
        writeLog($db, 'toggle_reseller', "Đại lý ID $id → ".($new?'bật':'tắt'), 'admin', currentAdminId());
        echo json_encode(['ok'=>true,'active'=>$new]);
        exit;
    }

    // ── Xóa đại lý ───────────────────────────────────────
    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id < 1) { echo json_encode(['ok'=>false,'msg'=>'ID không hợp lệ']); exit; }
        // Giữ lại keys của họ (không xóa) – chỉ xóa account
        $db->prepare("DELETE FROM resellers WHERE id=?")->execute([$id]);
        writeLog($db, 'delete_reseller', "Xóa đại lý ID: $id", 'admin', currentAdminId());
        echo json_encode(['ok'=>true]);
        exit;
    }

    // ── Lấy thông tin đại lý (cho edit modal) ────────────
    if ($act === 'get') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT id, username, email, phone, key_quota, keys_created, note, is_active FROM resellers WHERE id=?");
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        echo json_encode($r ? ['ok'=>true,'data'=>$r] : ['ok'=>false,'msg'=>'Không tìm thấy']);
        exit;
    }

    echo json_encode(['ok'=>false,'msg'=>'Action không hợp lệ']); exit;
}

// ── Lấy danh sách đại lý ──────────────────────────────────
$resellers = $db->query("
    SELECT r.*, a.username as created_by_name,
           (SELECT COUNT(*) FROM license_keys lk WHERE lk.created_by_reseller = r.id) as total_keys,
           (SELECT COUNT(*) FROM license_keys lk WHERE lk.created_by_reseller = r.id AND lk.is_used=1 AND lk.expires_at > NOW()) as active_keys
    FROM resellers r
    LEFT JOIN admin_users a ON r.created_by = a.id
    ORDER BY r.created_at DESC
")->fetchAll();

$pageTitle  = 'Quản lý Đại lý';
$activePage = 'resellers';
include __DIR__ . '/includes/header.php';
?>

<!-- Toolbar -->
<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h5 style="color:#e0e0ff;margin:0"><i class="bi bi-people-fill"></i> Đại lý</h5>
    <small style="color:var(--text-dim)"><?= count($resellers) ?> đại lý</small>
  </div>
  <button class="btn btn-primary btn-xs" onclick="openAddModal()">
    <i class="bi bi-person-plus-fill"></i> Thêm đại lý
  </button>
</div>

<!-- Resellers Grid -->
<div class="row g-3 mb-4">
<?php foreach ($resellers as $r):
  $usedPct = $r['key_quota'] > 0 ? round($r['keys_created']/$r['key_quota']*100) : 0;
?>
<div class="col-md-6 col-xl-4">
  <div class="panel">
    <div class="panel-header">
      <div class="d-flex align-items-center gap-2">
        <div style="width:36px;height:36px;background:rgba(124,58,237,.2);border-radius:50%;display:flex;align-items:center;justify-content:center">
          <i class="bi bi-person-fill" style="color:#a78bfa"></i>
        </div>
        <div>
          <div style="color:#e0e0ff;font-weight:700"><?= clean($r['username']) ?></div>
          <div style="font-size:.72rem;color:var(--text-dim)"><?= $r['is_active'] ? '<span style="color:#4ade80">● Hoạt động</span>' : '<span style="color:#6b7280">● Bị khóa</span>' ?></div>
        </div>
      </div>
      <div class="d-flex gap-1">
        <button class="btn btn-xs btn-info-xs" onclick="editReseller(<?= $r['id'] ?>)" title="Sửa"><i class="bi bi-pencil-fill"></i></button>
        <button class="btn btn-xs btn-warn-xs" onclick="changePass(<?= $r['id'] ?>, '<?= clean($r['username']) ?>')" title="Đổi MK"><i class="bi bi-key-fill"></i></button>
        <button class="btn btn-xs <?= $r['is_active'] ? 'btn-danger-xs' : 'btn-success-xs' ?>"
                onclick="toggleReseller(<?= $r['id'] ?>, <?= $r['is_active'] ?>)"
                title="<?= $r['is_active'] ? 'Khóa' : 'Mở khóa' ?>">
          <i class="bi bi-<?= $r['is_active'] ? 'lock' : 'unlock' ?>-fill"></i>
        </button>
        <button class="btn btn-xs btn-danger-xs" onclick="deleteReseller(<?= $r['id'] ?>, '<?= clean($r['username']) ?>')" title="Xóa"><i class="bi bi-trash-fill"></i></button>
      </div>
    </div>
    <div class="panel-body">
      <!-- Quota bar -->
      <div class="d-flex justify-content-between mb-1" style="font-size:.78rem">
        <span style="color:var(--text-dim)">Quota keys</span>
        <span style="color:#a78bfa"><?= $r['keys_created'] ?>/<?= $r['key_quota'] ?></span>
      </div>
      <div style="height:6px;background:#1f1f35;border-radius:3px;margin-bottom:12px">
        <div style="height:100%;width:<?= min(100,$usedPct) ?>%;background:<?= $usedPct>=90?'#ef4444':($usedPct>=70?'#f59e0b':'#7c3aed') ?>;border-radius:3px;transition:.3s"></div>
      </div>
      <div class="row g-2" style="font-size:.8rem">
        <div class="col-6">
          <div style="background:#0f0f1a;border-radius:8px;padding:8px;text-align:center">
            <div style="color:#38bdf8;font-size:1.1rem;font-weight:700"><?= $r['total_keys'] ?></div>
            <div style="color:var(--text-dim)">Tổng key</div>
          </div>
        </div>
        <div class="col-6">
          <div style="background:#0f0f1a;border-radius:8px;padding:8px;text-align:center">
            <div style="color:#4ade80;font-size:1.1rem;font-weight:700"><?= $r['active_keys'] ?></div>
            <div style="color:var(--text-dim)">Đang dùng</div>
          </div>
        </div>
      </div>
      <?php if ($r['email'] || $r['phone']): ?>
      <div style="margin-top:10px;font-size:.78rem;color:var(--text-dim)">
        <?php if ($r['email']): ?><div><i class="bi bi-envelope-fill"></i> <?= clean($r['email']) ?></div><?php endif; ?>
        <?php if ($r['phone']): ?><div><i class="bi bi-telephone-fill"></i> <?= clean($r['phone']) ?></div><?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($r['note']): ?>
      <div style="margin-top:8px;font-size:.75rem;color:var(--text-dim);background:#0f0f1a;border-radius:6px;padding:6px 10px">
        <i class="bi bi-sticky-fill"></i> <?= clean($r['note']) ?>
      </div>
      <?php endif; ?>
      <div style="margin-top:8px;font-size:.72rem;color:var(--text-dim)">
        Tạo lúc: <?= formatDate($r['created_at']) ?> | Bởi: <?= clean($r['created_by_name'] ?? 'System') ?><br>
        Đăng nhập gần nhất: <?= $r['last_login'] ? formatDate($r['last_login']) : 'Chưa đăng nhập' ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php if (empty($resellers)): ?>
<div class="col-12">
  <div style="text-align:center;padding:60px;color:var(--text-dim)">
    <i class="bi bi-people" style="font-size:3rem;opacity:.4"></i>
    <div style="margin-top:12px">Chưa có đại lý nào. <a href="#" onclick="openAddModal()" style="color:#7c3aed">Thêm ngay</a></div>
  </div>
</div>
<?php endif; ?>
</div>

<!-- ══ MODAL: Thêm đại lý ══ -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:#1a1a2e;border:1px solid #2a2a45;border-radius:14px">
      <div class="modal-header" style="border-color:#2a2a45">
        <h5 class="modal-title" style="color:#e0e0ff"><i class="bi bi-person-plus-fill"></i> Thêm đại lý</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Username <span style="color:#f87171">*</span></label>
          <input type="text" id="addUsername" class="form-control" placeholder="vd: daily01" oninput="this.value=this.value.toLowerCase().replace(/[^a-z0-9_]/g,'')">
        </div>
        <div class="mb-3">
          <label class="form-label">Mật khẩu <span style="color:#f87171">*</span></label>
          <input type="text" id="addPass" class="form-control" placeholder="Tối thiểu 6 ký tự">
        </div>
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="form-label">Email</label>
            <input type="email" id="addEmail" class="form-control" placeholder="email@...">
          </div>
          <div class="col-6">
            <label class="form-label">Số điện thoại</label>
            <input type="text" id="addPhone" class="form-control" placeholder="0xxx...">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Quota key tối đa</label>
          <input type="number" id="addQuota" class="form-control" value="100" min="1">
          <small style="color:var(--text-dim)">Số key tối đa đại lý có thể tạo</small>
        </div>
        <div class="mb-3">
          <label class="form-label">Ghi chú</label>
          <textarea id="addNote" class="form-control" rows="2" placeholder="Ghi chú..."></textarea>
        </div>
      </div>
      <div class="modal-footer" style="border-color:#2a2a45">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Hủy</button>
        <button type="button" class="btn btn-primary" onclick="doAddReseller()"><i class="bi bi-plus-circle"></i> Thêm</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ MODAL: Sửa đại lý ══ -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:#1a1a2e;border:1px solid #2a2a45;border-radius:14px">
      <div class="modal-header" style="border-color:#2a2a45">
        <h5 class="modal-title" style="color:#e0e0ff"><i class="bi bi-pencil-fill"></i> Sửa đại lý</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="editId">
        <div class="mb-3">
          <label class="form-label">Username</label>
          <input type="text" id="editUsername" class="form-control" disabled style="opacity:.5">
        </div>
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="form-label">Email</label>
            <input type="email" id="editEmail" class="form-control">
          </div>
          <div class="col-6">
            <label class="form-label">Số điện thoại</label>
            <input type="text" id="editPhone" class="form-control">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Quota key tối đa</label>
          <input type="number" id="editQuota" class="form-control" min="1">
        </div>
        <div class="mb-3">
          <label class="form-label">Ghi chú</label>
          <textarea id="editNote" class="form-control" rows="2"></textarea>
        </div>
      </div>
      <div class="modal-footer" style="border-color:#2a2a45">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Hủy</button>
        <button type="button" class="btn btn-primary" onclick="doEditReseller()"><i class="bi bi-check-lg"></i> Lưu</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ MODAL: Đổi mật khẩu ══ -->
<div class="modal fade" id="passModal" tabindex="-1">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content" style="background:#1a1a2e;border:1px solid #2a2a45;border-radius:14px">
      <div class="modal-header" style="border-color:#2a2a45">
        <h5 class="modal-title" style="color:#e0e0ff"><i class="bi bi-key-fill"></i> Đổi mật khẩu</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="passId">
        <div id="passUsername" style="color:#a78bfa;font-weight:700;margin-bottom:12px"></div>
        <label class="form-label">Mật khẩu mới</label>
        <input type="text" id="passNew" class="form-control" placeholder="Tối thiểu 6 ký tự">
        <small style="color:var(--text-dim)">Sẽ được áp dụng ngay lập tức</small>
      </div>
      <div class="modal-footer" style="border-color:#2a2a45">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Hủy</button>
        <button type="button" class="btn btn-primary" onclick="doChangePass()"><i class="bi bi-check-lg"></i> Lưu</button>
      </div>
    </div>
  </div>
</div>

<script>
const CSRF='<?= csrfToken() ?>';

function openAddModal(){ new bootstrap.Modal(document.getElementById('addModal')).show(); }

function doAddReseller(){
  const u=document.getElementById('addUsername').value.trim();
  const p=document.getElementById('addPass').value;
  if(u.length<3||p.length<6){ toast('Username ≥3, mật khẩu ≥6 ký tự','error'); return; }
  ajax('resellers.php',{
    act:'add',_csrf:CSRF,
    username:u, password:p,
    email:document.getElementById('addEmail').value,
    phone:document.getElementById('addPhone').value,
    key_quota:document.getElementById('addQuota').value,
    note:document.getElementById('addNote').value
  },r=>{
    if(r.ok){ toast('Đã thêm đại lý: '+r.username); setTimeout(()=>location.reload(),900); }
    else toast(r.msg,'error');
  });
}

function editReseller(id){
  showLoading();
  $.post('resellers.php',{act:'get',_csrf:CSRF,id},function(r){
    hideLoading();
    if(!r.ok){ toast(r.msg,'error'); return; }
    const d=r.data;
    document.getElementById('editId').value       = d.id;
    document.getElementById('editUsername').value  = d.username;
    document.getElementById('editEmail').value     = d.email||'';
    document.getElementById('editPhone').value     = d.phone||'';
    document.getElementById('editQuota').value     = d.key_quota;
    document.getElementById('editNote').value      = d.note||'';
    new bootstrap.Modal(document.getElementById('editModal')).show();
  },'json');
}

function doEditReseller(){
  ajax('resellers.php',{
    act:'edit', _csrf:CSRF,
    id:document.getElementById('editId').value,
    email:document.getElementById('editEmail').value,
    phone:document.getElementById('editPhone').value,
    key_quota:document.getElementById('editQuota').value,
    note:document.getElementById('editNote').value
  },r=>{
    if(r.ok){ toast('Đã lưu thay đổi'); setTimeout(()=>location.reload(),900); }
    else toast(r.msg,'error');
  });
}

function changePass(id, username){
  document.getElementById('passId').value=id;
  document.getElementById('passUsername').textContent='@'+username;
  document.getElementById('passNew').value='';
  new bootstrap.Modal(document.getElementById('passModal')).show();
}

function doChangePass(){
  const p=document.getElementById('passNew').value;
  if(p.length<6){ toast('Mật khẩu tối thiểu 6 ký tự','error'); return; }
  ajax('resellers.php',{act:'change_pass',_csrf:CSRF,id:document.getElementById('passId').value,password:p},r=>{
    if(r.ok){ toast('Đã đổi mật khẩu thành công'); bootstrap.Modal.getInstance(document.getElementById('passModal')).hide(); }
    else toast(r.msg,'error');
  });
}

function toggleReseller(id, cur){
  confirmAction(cur?'Khóa đại lý này?':'Mở khóa đại lý?','','question',()=>{
    ajax('resellers.php',{act:'toggle',_csrf:CSRF,id,current:cur},r=>{
      if(r.ok){ setTimeout(()=>location.reload(),800); }
    });
  });
}

function deleteReseller(id, username){
  confirmAction('Xóa đại lý @'+username+'?','Keys của đại lý sẽ được giữ lại.','warning',()=>{
    ajax('resellers.php',{act:'delete',_csrf:CSRF,id},r=>{
      if(r.ok){ toast('Đã xóa đại lý'); setTimeout(()=>location.reload(),800); }
    });
  });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
