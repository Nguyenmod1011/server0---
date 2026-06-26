  </div><!-- /.content -->
</div><!-- /.main-wrap -->

<!-- Loading Overlay -->
<div id="loadingOverlay" style="position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9999;display:none;align-items:center;justify-content:center;flex-direction:column;gap:12px;color:#c4c4dc">
  <div class="spinner-border text-light" style="width:48px;height:48px"></div>
  <div>Đang xử lý...</div>
</div>

<!-- JS Libraries -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// ---- Global helpers ----
function toggleSidebar(){
  document.querySelector('.sidebar').classList.toggle('show');
}

function showLoading(){ document.getElementById('loadingOverlay').style.display='flex'; }
function hideLoading(){ document.getElementById('loadingOverlay').style.display='none'; }

// ---- Copy to clipboard ----
function copyText(text, label=''){
  navigator.clipboard.writeText(text).then(()=>{
    Swal.mixin({
      toast:true,position:'top-end',showConfirmButton:false,timer:1800,
      background:'#1a1a2e',color:'#4ade80',
      iconColor:'#4ade80',icon:'success'
    }).fire({title: label ? label+' đã copy!' : 'Đã copy!'});
  });
}

// ---- AJAX helper ----
function ajax(url, data, cb){
  showLoading();
  $.post(url, data, function(r){
    hideLoading();
    if(typeof cb==='function') cb(r);
  }, 'json').fail(function(){
    hideLoading();
    Swal.fire({icon:'error',title:'Lỗi kết nối',text:'Không thể kết nối server',background:'#1a1a2e',color:'#cdd6f4',confirmButtonColor:'#7c3aed'});
  });
}

// ---- Confirm dialog ----
function confirmAction(title, text, icon, cb){
  Swal.fire({
    title:title,text:text,icon:icon,
    showCancelButton:true,confirmButtonText:'Xác nhận',cancelButtonText:'Hủy',
    background:'#1a1a2e',color:'#cdd6f4',
    confirmButtonColor:'#7c3aed',cancelButtonColor:'#374151'
  }).then(r=>{ if(r.isConfirmed && typeof cb==='function') cb(); });
}

// ---- Toast ----
function toast(msg, type='success'){
  Swal.mixin({
    toast:true,position:'top-end',showConfirmButton:false,timer:2500,
    background:'#1a1a2e',color:type==='success'?'#4ade80':'#f87171',
    icon:type
  }).fire({title:msg});
}

// ---- Select all checkboxes ----
function toggleAll(src, name){
  document.querySelectorAll('input[name="'+name+'"]').forEach(cb=>cb.checked=src.checked);
}

// Init DataTables dark theme
$.fn.dataTable.defaults.language = {
  "search":"<i class='bi bi-search'></i>",
  "lengthMenu":"Hiển thị _MENU_ dòng",
  "info":"_START_–_END_ / _TOTAL_ mục",
  "infoEmpty":"0 mục","zeroRecords":"Không có dữ liệu",
  "paginate":{"first":"«","last":"»","next":"›","previous":"‹"}
};
</script>

<?php if (!empty($extraJS)): ?>
<script><?= $extraJS ?></script>
<?php endif; ?>
</body>
</html>
