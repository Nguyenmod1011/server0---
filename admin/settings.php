<?php
// ============================================================
// admin/settings.php – Cài đặt & Đổi mật khẩu
// ============================================================
define('IN_ADMIN', true);
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/includes/auth.php';

$db  = getDB();
$msg = $err = '';

// ── Đổi mật khẩu (Admin hoặc Reseller) ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_pass'])) {
    if (!verifyCsrf($_POST['_csrf'] ?? '')) {
        $err = 'Token không hợp lệ.';
    } else {
        $currentPass = $_POST['current_pass'] ?? '';
        $newPass     = $_POST['new_pass']     ?? '';
        $confirmPass = $_POST['confirm_pass'] ?? '';

        if (strlen($newPass) < 6) {
            $err = 'Mật khẩu mới phải có ít nhất 6 ký tự.';
        } elseif ($newPass !== $confirmPass) {
            $err = 'Xác nhận mật khẩu không khớp.';
        } else {
            if (isAdmin()) {
                $stmt = $db->prepare("SELECT password FROM admin_users WHERE id=?");
                $stmt->execute([currentAdminId()]);
                $user = $stmt->fetch();
                if (!$user || !verifyPassword($currentPass, $user['password'])) {
                    $err = 'Mật khẩu hiện tại không đúng.';
                } else {
                    $db->prepare("UPDATE admin_users SET password=? WHERE id=?")
                       ->execute([hashPassword($newPass), currentAdminId()]);
                    writeLog($db, 'change_password', 'Admin đổi mật khẩu', 'admin', currentAdminId());
                    $msg = 'Đổi mật khẩu thành công!';
                }
            } else {
                $stmt = $db->prepare("SELECT password FROM resellers WHERE id=?");
                $stmt->execute([currentResellerId()]);
                $user = $stmt->fetch();
                if (!$user || !verifyPassword($currentPass, $user['password'])) {
                    $err = 'Mật khẩu hiện tại không đúng.';
                } else {
                    $db->prepare("UPDATE resellers SET password=? WHERE id=?")
                       ->execute([hashPassword($newPass), currentResellerId()]);
                    writeLog($db, 'change_password', 'Reseller đổi mật khẩu', 'reseller', currentResellerId());
                    $msg = 'Đổi mật khẩu thành công!';
                }
            }
        }
    }
}

// ── Cập nhật thông tin site (chỉ admin) ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings']) && isAdmin()) {
    if (!verifyCsrf($_POST['_csrf'] ?? '')) {
        $err = 'Token không hợp lệ.';
    } else {
        // Trong thực tế có thể lưu vào DB hoặc file config
        // Demo: chỉ thông báo
        $msg = 'Đã lưu cài đặt (cần cập nhật config.php thủ công).';
    }
}

// ── Xóa logs cũ (admin) ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_logs']) && isAdmin()) {
    $days = max(1, (int)($_POST['log_days'] ?? 30));
    $db->prepare("DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)")
       ->execute([$days]);
    $msg = "Đã xóa logs cũ hơn $days ngày.";
}

// ── Thống kê DB ─────────────────────────────────────────────
$dbStats = [];
if (isAdmin()) {
    $tables = ['license_keys','device_bindings','device_key_cache','resellers','activity_logs'];
    foreach ($tables as $t) {
        $dbStats[$t] = $db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    }
}

$pageTitle  = 'Cài đặt';
$activePage = 'settings';
include __DIR__ . '/includes/header.php';
?>

<div class="row g-4">

  <!-- ── Đổi mật khẩu ── -->
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-header">
        <span class="panel-title"><i class="bi bi-key-fill"></i> Đổi mật khẩu của tôi</span>
        <span style="font-size:.78rem;color:var(--text-dim)">@<?= currentUsername() ?> (<?= isAdmin()?'Admin':'Đại lý' ?>)</span>
      </div>
      <div class="panel-body">
        <?php if ($msg): ?><div class="alert-s success"><i class="bi bi-check-circle-fill"></i> <?= clean($msg) ?></div><?php endif; ?>
        <?php if ($err): ?><div class="alert-s error"><i class="bi bi-x-circle-fill"></i> <?= clean($err) ?></div><?php endif; ?>

        <form method="POST">
          <input type="hidden" name="_csrf" value="<?= csrfToken() ?>">
          <input type="hidden" name="change_pass" value="1">

          <div class="mb-3">
            <label class="form-label">Mật khẩu hiện tại</label>
            <div class="input-group">
              <input type="password" name="current_pass" id="cp1" class="form-control" placeholder="Nhập mật khẩu hiện tại" required>
              <button type="button" class="input-group-text" onclick="togglePwd('cp1','eye1')"><i class="bi bi-eye" id="eye1"></i></button>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Mật khẩu mới</label>
            <div class="input-group">
              <input type="password" name="new_pass" id="cp2" class="form-control" placeholder="Tối thiểu 6 ký tự" required oninput="checkStrength(this.value)">
              <button type="button" class="input-group-text" onclick="togglePwd('cp2','eye2')"><i class="bi bi-eye" id="eye2"></i></button>
            </div>
            <!-- Strength meter -->
            <div style="margin-top:6px">
              <div style="height:4px;background:#1f1f35;border-radius:2px">
                <div id="strengthBar" style="height:100%;width:0%;border-radius:2px;transition:.3s"></div>
              </div>
              <div id="strengthTxt" style="font-size:.72rem;color:var(--text-dim);margin-top:3px"></div>
            </div>
          </div>

          <div class="mb-4">
            <label class="form-label">Xác nhận mật khẩu mới</label>
            <input type="password" name="confirm_pass" id="cp3" class="form-control" placeholder="Nhập lại mật khẩu mới" required>
          </div>

          <button type="submit" class="btn btn-primary w-100">
            <i class="bi bi-shield-lock-fill"></i> Đổi mật khẩu
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ── API Info ── -->
  <div class="col-lg-6">
    <div class="panel mb-3">
      <div class="panel-header">
        <span class="panel-title"><i class="bi bi-code-slash"></i> Thông tin API</span>
      </div>
      <div class="panel-body">
        <div class="mb-3">
          <label class="form-label">API Endpoint</label>
          <div class="d-flex gap-2">
            <input type="text" class="form-control" id="apiUrl"
                   value="<?= ((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http').'://'.$_SERVER['HTTP_HOST'].rtrim(dirname(dirname($_SERVER['PHP_SELF'])),'/').'/api.php' ?>"
                   readonly>
            <button class="btn btn-xs btn-info-xs" style="padding:8px 12px" onclick="copyText(document.getElementById('apiUrl').value,'API URL')">
              <i class="bi bi-clipboard"></i>
            </button>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">API Secret (trong config.php)</label>
          <div class="d-flex gap-2">
            <input type="password" class="form-control" id="apiSecret" value="<?= API_SECRET ?>" readonly>
            <button class="btn btn-xs btn-warn-xs" style="padding:8px 12px" onclick="togglePwd('apiSecret','eyeSecret')">
              <i class="bi bi-eye" id="eyeSecret"></i>
            </button>
            <button class="btn btn-xs btn-info-xs" style="padding:8px 12px" onclick="copyText('<?= API_SECRET ?>','Secret')">
              <i class="bi bi-clipboard"></i>
            </button>
          </div>
          <small style="color:#f87171"><i class="bi bi-exclamation-triangle-fill"></i> Không chia sẻ secret này!</small>
        </div>

        <div style="background:#0f0f1a;border:1px solid #2a2a45;border-radius:8px;padding:12px;font-family:monospace;font-size:.75rem;color:#c4c4dc">
          <div style="color:#7070a0;margin-bottom:6px">// Các action có thể dùng:</div>
          <div style="color:#a78bfa">check_key</div>
          <div style="color:#a78bfa">activate_key</div>
          <div style="color:#a78bfa">save_key</div>
          <div style="color:#a78bfa">get_saved_key</div>
          <div style="color:#a78bfa">ping</div>
        </div>
      </div>
    </div>

    <?php if (isAdmin()): ?>
    <!-- DB Stats -->
    <div class="panel mb-3">
      <div class="panel-header">
        <span class="panel-title"><i class="bi bi-database-fill"></i> Thống kê Database</span>
      </div>
      <div class="panel-body p-0">
        <?php foreach ($dbStats as $table => $count): ?>
        <div style="display:flex;justify-content:space-between;padding:9px 16px;border-bottom:1px solid var(--border);font-size:.83rem">
          <span style="color:var(--text-dim);font-family:monospace"><?= $table ?></span>
          <span style="color:#a78bfa;font-weight:700"><?= number_format($count) ?> rows</span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Clear logs -->
    <div class="panel">
      <div class="panel-header">
        <span class="panel-title"><i class="bi bi-trash-fill" style="color:#f87171"></i> Dọn dẹp Logs</span>
      </div>
      <div class="panel-body">
        <form method="POST" class="d-flex gap-2 align-items-end">
          <input type="hidden" name="_csrf" value="<?= csrfToken() ?>">
          <input type="hidden" name="clear_logs" value="1">
          <div class="flex-grow-1">
            <label class="form-label">Xóa logs cũ hơn</label>
            <div class="input-group">
              <input type="number" name="log_days" class="form-control" value="30" min="1">
              <span class="input-group-text" style="background:#0f0f1a;border:1px solid #2a2a45;color:var(--text-dim)">ngày</span>
            </div>
          </div>
          <button type="submit" class="btn btn-danger-xs btn-xs" style="padding:10px 16px;border-radius:8px;border:none">
            <i class="bi bi-trash"></i> Xóa
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── Cài đặt site (Admin) ── -->
<?php if (isAdmin()): ?>
<div class="row g-4 mt-1">
  <div class="col-12">
    <div class="panel">
      <div class="panel-header">
        <span class="panel-title"><i class="bi bi-gear-fill"></i> Cài đặt hệ thống</span>
        <span style="font-size:.75rem;color:var(--text-dim)">Thay đổi trực tiếp trong <code style="color:#a78bfa">config.php</code></span>
      </div>
      <div class="panel-body">
        <div style="background:#0f0f1a;border:1px solid #2a2a45;border-radius:10px;padding:16px;font-family:monospace;font-size:.82rem;color:#c4c4dc;line-height:1.8">
          <div><span style="color:#6b7280">// config.php</span></div>
          <div><span style="color:#a78bfa">define</span>(<span style="color:#86efac">'DB_HOST'</span>, <span style="color:#fbbf24">'<?= DB_HOST ?>'</span>);</div>
          <div><span style="color:#a78bfa">define</span>(<span style="color:#86efac">'DB_NAME'</span>, <span style="color:#fbbf24">'<?= DB_NAME ?>'</span>);</div>
          <div><span style="color:#a78bfa">define</span>(<span style="color:#86efac">'SITE_NAME'</span>, <span style="color:#fbbf24">'<?= SITE_NAME ?>'</span>);</div>
          <div><span style="color:#a78bfa">define</span>(<span style="color:#86efac">'SITE_VERSION'</span>, <span style="color:#fbbf24">'<?= SITE_VERSION ?>'</span>);</div>
          <div><span style="color:#a78bfa">define</span>(<span style="color:#86efac">'API_SECRET'</span>, <span style="color:#fbbf24">'<span style="filter:blur(4px)">••••••••••••••</span>'</span>);</div>
        </div>
        <div style="margin-top:12px;font-size:.8rem;color:var(--text-dim)">
          <i class="bi bi-info-circle-fill" style="color:#38bdf8"></i>
          Chỉnh sửa trực tiếp file <code>config.php</code> trên server để thay đổi các thông số này.
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
function togglePwd(fieldId, iconId){
  const f=document.getElementById(fieldId);
  const e=document.getElementById(iconId);
  if(!f||!e)return;
  if(f.type==='password'||f.readOnly){
    const orig=f.type; f.type='text'; e.className='bi bi-eye-slash';
    setTimeout(()=>{f.type=orig;e.className='bi bi-eye';},3000);
  } else {
    f.type='password'; e.className='bi bi-eye';
  }
}

function checkStrength(pw){
  const bar=document.getElementById('strengthBar');
  const txt=document.getElementById('strengthTxt');
  if(!pw){bar.style.width='0';txt.textContent='';return;}
  let score=0;
  if(pw.length>=8) score++;
  if(pw.length>=12) score++;
  if(/[A-Z]/.test(pw)) score++;
  if(/[0-9]/.test(pw)) score++;
  if(/[^A-Za-z0-9]/.test(pw)) score++;
  const levels=[
    {pct:'15%',color:'#ef4444',txt:'Rất yếu'},
    {pct:'30%',color:'#f97316',txt:'Yếu'},
    {pct:'55%',color:'#f59e0b',txt:'Trung bình'},
    {pct:'75%',color:'#84cc16',txt:'Mạnh'},
    {pct:'100%',color:'#22c55e',txt:'Rất mạnh'},
  ];
  const l=levels[Math.min(score,4)];
  bar.style.width=l.pct; bar.style.background=l.color;
  txt.textContent=l.txt; txt.style.color=l.color;
}
</script>



<!-- ── Telegram Section ── -->
<?php if (isAdmin()): ?>
<div class="row g-4 mt-1">
  <div class="col-12">
    <div class="panel">
      <div class="panel-header">
        <span class="panel-title">🤖 Telegram Notifications</span>
        <span class="<?= defined('TG_ENABLED') && TG_ENABLED ? 'badge-valid' : 'badge-inactive' ?>">
          <?= defined('TG_ENABLED') && TG_ENABLED ? 'Đang bật' : 'Đang tắt' ?>
        </span>
      </div>
      <div class="panel-body">
        <div class="row g-3">
          <div class="col-md-6">
            <div style="background:#0f0f1a;border:1px solid #2a2a45;border-radius:10px;padding:16px;font-family:monospace;font-size:.82rem;color:#c4c4dc;line-height:2.1">
              <div style="color:#6b7280">// config.php</div>
              <div><span style="color:#a78bfa">define</span>(<span style="color:#86efac">'TG_ENABLED'</span>,   <span style="color:#38bdf8">true</span>);</div>
              <div><span style="color:#a78bfa">define</span>(<span style="color:#86efac">'TG_BOT_TOKEN'</span>, <span style="color:#fbbf24">'1234567890:ABC...'</span>);</div>
              <div><span style="color:#a78bfa">define</span>(<span style="color:#86efac">'TG_CHAT_ID'</span>,   <span style="color:#fbbf24">'-100123456789'</span>);</div>
            </div>
          </div>
          <div class="col-md-6">
            <div style="font-size:.83rem;color:var(--text-dim);line-height:2;margin-bottom:12px">
              <div><i class="bi bi-1-circle-fill" style="color:#a78bfa"></i> Tạo bot tại <a href="https://t.me/BotFather" target="_blank" style="color:#38bdf8">@BotFather</a> → <code>/newbot</code></div>
              <div><i class="bi bi-2-circle-fill" style="color:#a78bfa"></i> Lấy Chat ID: <a href="https://t.me/userinfobot" target="_blank" style="color:#38bdf8">@userinfobot</a></div>
              <div><i class="bi bi-3-circle-fill" style="color:#a78bfa"></i> Cập nhật <code>config.php</code></div>
            </div>
            <div style="font-size:.8rem;background:#0f0f1a;border:1px solid #2a2a45;border-radius:8px;padding:10px 12px;color:var(--text-dim);margin-bottom:12px">
              <b style="color:#c4c4dc">Thông báo tự động khi:</b>
              <div style="margin-top:6px">🔑 Key kích hoạt lần đầu</div>
              <div>👤 Đại lý mới được tạo</div>
              <div>📊 Báo cáo cron hàng ngày</div>
            </div>
            <?php if (defined('TG_ENABLED') && TG_ENABLED): ?>
            <button onclick="testTelegram()" class="btn btn-primary w-100" style="font-size:.85rem">
              <i class="bi bi-send-fill"></i> Gửi tin nhắn test
            </button>
            <?php else: ?>
            <div class="alert-s info"><i class="bi bi-info-circle-fill"></i> Bật TG_ENABLED trong config.php để dùng tính năng này</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
function testTelegram(){
  showLoading();
  $.post('ajax_telegram_test.php',{_csrf:'<?= csrfToken() ?>'},function(r){
    hideLoading();
    if(r.ok) toast('Đã gửi tin nhắn test thành công!','success');
    else toast(r.msg||'Gửi thất bại','error');
  },'json').fail(()=>{ hideLoading(); toast('Lỗi kết nối','error'); });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
