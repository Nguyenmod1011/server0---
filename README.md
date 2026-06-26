# 🔐 License Manager Pro

Hệ thống quản lý license key hoàn chỉnh — PHP Server + iOS Swift Client.

---

## 📦 Cấu trúc thư mục

```
license_system/
├── config.php              ← ⚙️  Cấu hình DB + API secret
├── install.php             ← 🔧  Cài đặt database (xóa sau dùng)
├── api.php                 ← 🌐  REST API endpoint
├── .htaccess               ← 🛡️  Bảo mật server
│
├── admin/
│   ├── login.php           ← Đăng nhập (admin + đại lý)
│   ├── logout.php
│   ├── index.php           ← Dashboard tổng quan
│   ├── keys.php            ← ✅ Quản lý keys (tạo/xóa/reset/check)
│   ├── resellers.php       ← 👥 Quản lý đại lý
│   ├── devices.php         ← 📱 Quản lý thiết bị
│   ├── settings.php        ← ⚙️  Đổi mật khẩu + cài đặt
│   ├── logs.php            ← 📋 Activity logs
│   └── includes/
│       ├── auth.php        ← Middleware xác thực
│       ├── header.php      ← Template header
│       └── footer.php      ← Template footer
│
└── ios_client/
    └── LicenseManager.swift ← iOS client hoàn chỉnh
```

---

## 🚀 Cài đặt Server (PHP + MySQL)

### Yêu cầu
- PHP ≥ 8.0
- MySQL ≥ 5.7 hoặc MariaDB ≥ 10.3
- mod_rewrite (Apache) hoặc tương đương (Nginx)

### Bước 1 — Tạo database
```sql
CREATE DATABASE license_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'licenseuser'@'localhost' IDENTIFIED BY 'matkhau_manh';
GRANT ALL PRIVILEGES ON license_db.* TO 'licenseuser'@'localhost';
FLUSH PRIVILEGES;
```

### Bước 2 — Cấu hình `config.php`
```php
define('DB_HOST',     'localhost');
define('DB_USER',     'licenseuser');
define('DB_PASS',     'matkhau_manh');
define('DB_NAME',     'license_db');
define('API_SECRET',  'THAY_BANG_CHUOI_BI_MAT_NGAU_NHIEN');  // ← QUAN TRỌNG
define('SITE_NAME',   'Tên ứng dụng của bạn');
```

> **Tạo API_SECRET ngẫu nhiên:**
> ```bash
> openssl rand -hex 32
> ```

### Bước 3 — Upload files lên hosting
Upload toàn bộ thư mục `license_system/` lên public_html hoặc thư mục web.

### Bước 4 — Chạy installer
Truy cập: `https://yourdomain.com/install.php`

- Tạo tất cả bảng database
- Tạo admin mặc định: `admin / admin123`
- **XÓA FILE install.php ngay sau đó!**

### Bước 5 — Đăng nhập
`https://yourdomain.com/admin/login.php`

- Username: `admin`
- Password: `admin123`
- **Đổi mật khẩu ngay tại Settings!**

---

## 🌐 API Reference

**Endpoint:** `POST https://yourdomain.com/api.php`

Tất cả requests cần header hoặc body:
```
api_secret: YOUR_SECRET_KEY
```

### Action: `activate_key`
Kích hoạt key và bind thiết bị.

**Request:**
```
action=activate_key
key=XXXX-XXXX-XXXX-XXXX
device_id=unique_device_identifier
device_name=iPhone của Nam
device_model=iPhone14,2
ios_version=17.2
api_secret=YOUR_SECRET
```

**Response (thành công):**
```json
{
  "status": "activated",
  "message": "Kích hoạt thành công!",
  "expires_at": "2024-12-31 23:59:59",
  "days_remaining": 30
}
```

**Các status có thể trả về:**
| Status | Ý nghĩa |
|--------|---------|
| `activated` | Kích hoạt lần đầu thành công |
| `valid` | Đã kích hoạt trước đó, còn hạn |
| `expired` | Key hết hạn |
| `invalid` | Key không tồn tại |
| `banned` | Key bị vô hiệu hóa |
| `max_devices` | Đạt giới hạn thiết bị |
| `not_activated` | Key chưa được kích hoạt lần nào |

---

### Action: `get_saved_key`
Lấy key đã lưu theo device_id (hỗ trợ reinstall app).

**Request:**
```
action=get_saved_key
device_id=unique_device_identifier
api_secret=YOUR_SECRET
```

**Response:**
```json
{
  "status": "found",
  "key": "XXXX-XXXX-XXXX-XXXX",
  "expires_at": "2024-12-31 23:59:59",
  "days_remaining": 30,
  "is_activated": true
}
```

---

### Action: `check_key`
Kiểm tra trạng thái key mà không kích hoạt.

---

### Action: `save_key`
Lưu key vào cache server theo device_id.

---

### Action: `ping`
Heartbeat định kỳ (mỗi 5 phút).

---

## 📱 Tích hợp iOS

### 1. Import file
Kéo `LicenseManager.swift` vào Xcode project.

### 2. Cấu hình
```swift
// Trong LicenseManager.swift, sửa Config:
private enum Config {
    static let apiURL    = "https://yourdomain.com/api.php"
    static let apiSecret = "THAY_BANG_SECRET_CUA_BAN"   // phải khớp config.php
}
```

### 3. Khởi tạo khi app mở
```swift
// AppDelegate.swift
func application(_ app: UIApplication, didFinishLaunchingWithOptions ...) -> Bool {
    
    LicenseManager.shared.initialize { status in
        switch status {
        case .valid(let exp, let days):
            print("✅ Key hợp lệ, còn \(days) ngày")
            // → Vào màn hình chính
            
        default:
            // → Hiện màn nhập key
            let licVC = LicenseViewController()
            licVC.modalPresentationStyle = .fullScreen
            licVC.onSuccess = { _ in /* vào app */ }
            self.window?.rootViewController?.present(licVC, animated: false)
        }
    }
    return true
}
```

### 4. Luồng hoạt động

```
App khởi động
    │
    ├─► Tìm key trong Keychain iOS
    │       ├─ Có → Gọi activate_key API → Vào app ✅
    │       └─ Không có
    │               │
    │               └─► Gọi get_saved_key API (theo device_id)
    │                       ├─ Server có cache → Lưu Keychain + Vào app ✅
    │                       └─ Không có → Hiện màn nhập key
    │
    └─► Người dùng nhập key → activate_key → Lưu Keychain + Server cache → Vào app ✅
```

### 5. Cơ chế "không nhập lại key sau reinstall"

Hệ thống dùng **2 lớp lưu trữ**:

| Lớp | Phương pháp | Tồn tại sau reinstall |
|-----|-------------|----------------------|
| Lớp 1 | iOS Keychain (`kSecAttrAccessibleAfterFirstUnlock`) | ✅ Có |
| Lớp 2 | Server `device_key_cache` (theo device_id) | ✅ Có |

- **Lớp 1** hoạt động offline — nếu Keychain còn dữ liệu, không cần network.
- **Lớp 2** dự phòng — khi Keychain bị xóa (format máy), server vẫn nhớ device_id.

---

## 👥 Phân quyền

| Tính năng | Admin | Đại lý |
|-----------|-------|--------|
| Tạo key | ✅ Không giới hạn | ✅ Theo quota |
| Xem key của mình | ✅ | ✅ |
| Xem key của người khác | ✅ | ❌ |
| Xóa key | ✅ | ✅ (key của mình) |
| Reset key/thiết bị | ✅ | ❌ |
| Vô hiệu hóa key | ✅ | ❌ |
| Quản lý đại lý | ✅ | ❌ |
| Xem logs | ✅ | ❌ |

---

## 🛡️ Bảo mật khuyến nghị

1. **HTTPS bắt buộc** — Bật SSL/TLS trước khi dùng production
2. **Đổi API_SECRET** — Dùng chuỗi ngẫu nhiên ít nhất 32 ký tự
3. **Xóa install.php** ngay sau cài đặt
4. **Đổi mật khẩu admin** từ `admin123` ngay khi đăng nhập lần đầu
5. **Giới hạn IP** nếu cần — thêm vào .htaccess
6. **Backup DB** định kỳ

---

## 🔧 Tùy chỉnh nâng cao

### Định dạng key khác (ngoài XXXX-XXXX-XXXX-XXXX)
Sửa hàm `generateKey()` trong `config.php`:
```php
// 6 segments x 4 ký tự: XXXX-XXXX-XXXX-XXXX-XXXX-XXXX
function generateKey(int $segments = 6, int $segLen = 4): string { ... }
```

### Thêm webhook khi key được kích hoạt
Trong `api.php`, function `apiActivateKey()`, thêm sau dòng `writeLog(...)`:
```php
// Gọi webhook
$webhookUrl = 'https://your-webhook.com/notify';
// file_get_contents($webhookUrl . '?key=' . $key . '&device=' . $deviceId);
```

### Rate limiting
Thêm vào đầu `api.php`:
```php
// Giới hạn 60 requests/phút theo IP
$cacheKey = 'rl_' . md5($ip);
// Dùng Redis/Memcached hoặc file-based cache
```

---

## 📞 Hỗ trợ

- Admin mặc định: `admin / admin123`
- Đổi mật khẩu tại: `Admin → Settings`
- API docs: xem phần API Reference ở trên
