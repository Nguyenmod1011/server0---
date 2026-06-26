<?php
// ============================================================
// admin/import_keys.php – Import key hàng loạt từ file/text
// ============================================================
define('IN_ADMIN', true);
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/auth.php';

$db = getDB();

// ── AJAX POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!verifyCsrf($_POST['_csrf'] ?? '')) {
        echo json_encode(['ok' => false, 'msg' => 'CSRF error']); exit;
    }

    $act = $_POST['act'] ?? '';

    // ── Preview: kiểm tra danh sách key trước khi import ──
    if ($act === 'preview') {
        $raw  = $_POST['keys_raw'] ?? '';
        $keys = parseKeyList($raw);
        if (empty($keys)) {
            echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy key hợp lệ']); exit;
        }

        // Kiểm tra trùng trong DB
        $results = [];
        foreach ($keys as $k) {
            $stmt = $db->prepare("SELECT id, is_used, is_active, expires_at FROM license_keys WHERE license_key=?");
            $stmt->execute([$k]);
            $row = $stmt->fetch();
            $results[] = [
                'key'    => $k,
                'exists' => $row ? true : false,
                'status' => $row ? (!$row['is_active'] ? 'Vô hiệu' : ($row['is_used'] ? 'Đã kích hoạt' : 'Chưa dùng')) : 'Mới',
            ];
        }
        $new      = count(array_filter($results, fn($r) => !$r['exists']));
        $duplicate= count($results) - $new;
        echo json_encode(['ok' => true, 'total' => count($results), 'new' => $new, 'duplicate' => $duplicate, 'preview' => array_slice($results, 0, 50)]);
        exit;
    }

    // ── Import thật sự ─────────────────────────────────────
    if ($act === 'import') {
        $raw        = $_POST['keys_raw']   ?? '';
        $days       = max(1, (int)($_POST['days']        ?? 30));
        $maxDev     = max(1, (int)($_POST['max_devices'] ?? 1));
        $note       = substr($_POST['note'] ?? '', 0, 255);
        $skipDup    = ($_POST['skip_dup'] ?? '1') === '1';

        $keys = parseKeyList($raw);
        if (empty($keys)) {
            echo json_encode(['ok' => false, 'msg' => 'Không có key hợp lệ']); exit;
        }

        $stmt = $db->prepare(
            "INSERT IGNORE INTO license_keys (license_key, duration_days, max_devices, note, created_by_admin, created_by_reseller)
             VALUES (?,?,?,?,?,?)"
        );

        $imported = $skipped = $errors = 0;
        $db->beginTransaction();
        try {
            foreach ($keys as $k) {
                // Kiểm tra trùng
                $ck = $db->prepare("SELECT id FROM license_keys WHERE license_key=?");
                $ck->execute([$k]);
                if ($ck->fetch()) {
                    if ($skipDup) { $skipped++; continue; }
                    $errors++;
                    continue;
                }
                $stmt->execute([
                    $k, $days, $maxDev, $note,
                    isAdmin() ? currentAdminId() : null,
                    isAdmin() ? null : currentResellerId(),
                ]);
                $imported++;
            }
            $db->commit();
            writeLog($db, 'import_keys', "Import $imported keys ($skipped bỏ qua)", isAdmin()?'admin':'reseller', currentUserId());
            echo json_encode(['ok' => true, 'imported' => $imported, 'skipped' => $skipped, 'errors' => $errors]);
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Action không hợp lệ']); exit;
}

// ── Upload file ────────────────────────────────────────────
$uploadedContent = '';
if (!empty($_FILES['key_file']['tmp_name'])) {
    $ext = strtolower(pathinfo($_FILES['key_file']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['txt', 'csv'])) {
        $uploadedContent = file_get_contents($_FILES['key_file']['tmp_name']);
    }
}

function parseKeyList(string $raw): array {
    $lines = preg_split('/[\r\n,;]+/', $raw);
    $keys  = [];
    foreach ($lines as $line) {
        $k = strtoupper(trim($line));
        // Validate định dạng XXXX-XXXX-XXXX-XXXX
        if (preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $k)) {
            $keys[] = $k;
        }
    }
    return array_unique($keys);
}

$pageTitle  = 'Import Keys';
$activePage = 'keys';
include __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <a href="keys.php" class="btn btn-xs btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h5 style="color:#e0e0ff;margin:0"><i class="bi bi-upload"></i> Import Keys hàng loạt</h5>
</div>

<div class="row g-4">
  <!-- Form nhập -->
  <div class="col-lg-7">
    <div class="panel">
      <div class="panel-header">
        <span class="panel-title">Dữ liệu Key</span>
      </div>
      <div class="panel-body">
        <!-- Upload file -->
        <div class="mb-3">
          <label class="form-label">Upload file TXT/CSV <small style="color:var(--text-dim)">(mỗi dòng 1 key)</small></label>
          <input type="file" id="keyFile" class="form-control" accept=".txt,.csv" onchange="loadFile(this)">
        </div>

        <div style="text-align:center;color:var(--text-dim);margin:12px 0;font-size:.82rem">— hoặc —</div>

        <!-- Paste thủ công -->
        <div class="mb-3">
          <label class="form-label">Dán danh sách key <small style="color:var(--text-dim)">(mỗi dòng 1 key, định dạng XXXX-XXXX-XXXX-XXXX)</small></label>
          <textarea id="keysRaw" class="form-control" rows="10"
            placeholder="ABCD-1234-EFGH-5678&#10;IJKL-9012-MNOP-3456&#10;..."
            style="font-family:monospace;font-size:.83rem;text-transform:uppercase"
            oninput="this.value=this.value.toUpperCase()"><?= htmlspecialchars($uploadedContent) ?></textarea>
          <div id="parseCount" style="font-size:.75rem;color:var(--text-dim);margin-top:4px"></div>
        </div>

        <button class="btn btn-xs btn-info-xs" style="padding:8px 18px" onclick="doPreview()">
          <i class="bi bi-eye"></i> Preview & Kiểm tra
        </button>
      </div>
    </div>
  </div>

  <!-- Cài đặt import -->
  <div class="col-lg-5">
    <div class="panel mb-3">
      <div class="panel-header">
        <span class="panel-title">Cài đặt Import</span>
      </div>
      <div class="panel-body">
        <div class="mb-3">
          <label class="form-label">Thời hạn (ngày)</label>
          <select id="impDays" class="form-select">
            <option value="1">1 ngày</option>
            <option value="7">7 ngày</option>
            <option value="15">15 ngày</option>
            <option value="30" selected>30 ngày</option>
            <option value="60">60 ngày</option>
            <option value="90">90 ngày</option>
            <option value="180">6 tháng</option>
            <option value="365">1 năm</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Giới hạn thiết bị/key</label>
          <input type="number" id="impMaxDev" class="form-control" value="1" min="1" max="100">
        </div>
        <div class="mb-3">
          <label class="form-label">Ghi chú</label>
          <input type="text" id="impNote" class="form-control" placeholder="Ghi chú cho batch này...">
        </div>
        <div class="mb-3">
          <label class="d-flex align-items-center gap-2" style="cursor:pointer">
            <input type="checkbox" id="skipDup" checked style="width:16px;height:16px;accent-color:#7c3aed">
            <span style="font-size:.85rem;color:#c4c4dc">Bỏ qua key đã tồn tại</span>
          </label>
        </div>
        <button class="btn btn-primary w-100" onclick="doImport()" id="importBtn" disabled>
          <i class="bi bi-cloud-upload-fill"></i> Import Keys
        </button>
      </div>
    </div>

    <!-- Preview result -->
    <div id="previewBox" class="panel" style="display:none">
      <div class="panel-header">
        <span class="panel-title"><i class="bi bi-list-check"></i> Kết quả Preview</span>
      </div>
      <div class="panel-body p-0" id="previewContent"></div>
    </div>
  </div>
</div>

<script>
const CSRF = '<?= csrfToken() ?>';
let previewOk = false;

// Load file upload
function loadFile(input){
  if(!input.files[0]) return;
  const r = new FileReader();
  r.onload = e => { document.getElementById('keysRaw').value = e.target.result.toUpperCase(); countKeys(); };
  r.readAsText(input.files[0]);
}

// Đếm key khi gõ
document.getElementById('keysRaw').addEventListener('input', countKeys);
function countKeys(){
  const raw = document.getElementById('keysRaw').value;
  const matches = raw.match(/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/g) || [];
  const unique = [...new Set(matches)];
  document.getElementById('parseCount').textContent = unique.length
    ? `✅ Tìm thấy ${unique.length} key hợp lệ (${matches.length - unique.length} trùng lặp trong danh sách)`
    : '⚠️ Chưa có key hợp lệ';
  document.getElementById('importBtn').disabled = unique.length === 0;
  previewOk = false;
}

function doPreview(){
  const raw = document.getElementById('keysRaw').value.trim();
  if(!raw){ toast('Nhập danh sách key trước','error'); return; }
  showLoading();
  $.post('import_keys.php', { act:'preview', _csrf:CSRF, keys_raw:raw }, function(r){
    hideLoading();
    if(!r.ok){ toast(r.msg,'error'); return; }
    const box = document.getElementById('previewBox');
    box.style.display = 'block';
    const pv = document.getElementById('previewContent');
    pv.innerHTML = `
      <div style="padding:14px 16px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;border-bottom:1px solid var(--border)">
        <div style="text-align:center"><div style="font-size:1.3rem;font-weight:800;color:#e0e0ff">${r.total}</div><div style="font-size:.72rem;color:var(--text-dim)">Tổng</div></div>
        <div style="text-align:center"><div style="font-size:1.3rem;font-weight:800;color:#4ade80">${r.new}</div><div style="font-size:.72rem;color:var(--text-dim)">Key mới</div></div>
        <div style="text-align:center"><div style="font-size:1.3rem;font-weight:800;color:#f87171">${r.duplicate}</div><div style="font-size:.72rem;color:var(--text-dim)">Trùng DB</div></div>
      </div>
      ${r.preview.map(p=>`
        <div style="display:flex;justify-content:space-between;padding:7px 14px;border-bottom:1px solid var(--border);font-size:.78rem">
          <span class="key-mono" style="font-size:.78rem">${p.key}</span>
          <span class="${p.exists?'badge-expired':'badge-valid'}">${p.status}</span>
        </div>`).join('')}
      ${r.total > 50 ? `<div style="padding:8px 14px;font-size:.75rem;color:var(--text-dim)">...và ${r.total-50} key khác</div>` : ''}
    `;
    previewOk = true;
    document.getElementById('importBtn').disabled = r.new === 0;
    if(r.new===0) toast('Tất cả key đã tồn tại trong DB','error');
  }, 'json').fail(()=>{ hideLoading(); toast('Lỗi kết nối','error'); });
}

function doImport(){
  const raw = document.getElementById('keysRaw').value.trim();
  if(!raw){ toast('Nhập danh sách key','error'); return; }
  confirmAction('Xác nhận import?',`Thao tác này không thể hoàn tác.`,'question',()=>{
    showLoading();
    $.post('import_keys.php',{
      act:'import', _csrf:CSRF, keys_raw:raw,
      days:document.getElementById('impDays').value,
      max_devices:document.getElementById('impMaxDev').value,
      note:document.getElementById('impNote').value,
      skip_dup:document.getElementById('skipDup').checked?'1':'0'
    },function(r){
      hideLoading();
      if(r.ok){
        Swal.fire({
          icon:'success',title:'Import hoàn tất!',
          html:`<div style="font-size:.9rem">✅ Imported: <b style="color:#4ade80">${r.imported}</b>&nbsp;&nbsp;⏭️ Bỏ qua: <b style="color:#fbbf24">${r.skipped}</b>&nbsp;&nbsp;❌ Lỗi: <b style="color:#f87171">${r.errors}</b></div>`,
          background:'#1a1a2e',color:'#cdd6f4',confirmButtonColor:'#7c3aed'
        }).then(()=>window.location.href='keys.php');
      } else {
        toast(r.msg||'Lỗi import','error');
      }
    },'json').fail(()=>{ hideLoading(); toast('Lỗi kết nối','error'); });
  });
}

// Count on load nếu có content từ upload
countKeys();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
