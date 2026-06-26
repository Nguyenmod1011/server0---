// ============================================================
// LicenseManager.swift
// iOS License System – Server & Client
// Tích hợp: import file này vào project, gọi LicenseManager.shared
// ============================================================

import Foundation
import UIKit
import Security

// MARK: - ⚙️ Cấu hình (THAY ĐỔI TRƯỚC KHI DÙNG)
private enum Config {
    static let apiURL       = "https://yourdomain.com/api.php"
    static let apiSecret    = "CHANGE_THIS_SECRET_KEY_2024"   // khớp config.php
    static let pingInterval = 300.0    // giây (5 phút)
    static let timeout      = 15.0     // giây
}

// MARK: - Kết quả trả về
enum LicenseStatus {
    case valid(expiresAt: String, daysRemaining: Int)
    case expired(expiredAt: String)
    case invalid(message: String)
    case notActivated(durationDays: Int)
    case maxDevices(max: Int, used: Int)
    case banned
    case networkError(message: String)
}

// MARK: - LicenseManager (Singleton)
final class LicenseManager {

    static let shared = LicenseManager()
    private init() {}

    // ── State ──────────────────────────────────────────────
    private(set) var isValid     = false
    private(set) var expiresAt   : String?
    private(set) var daysLeft    : Int = 0
    private(set) var currentKey  : String?

    private var pingTimer: Timer?
    private let session: URLSession = {
        let cfg = URLSessionConfiguration.default
        cfg.timeoutIntervalForRequest  = Config.timeout
        cfg.timeoutIntervalForResource = Config.timeout
        return URLSession(configuration: cfg)
    }()

    // ── Callbacks ──────────────────────────────────────────
    var onKeyExpired    : (() -> Void)?
    var onKeyBanned     : (() -> Void)?
    var onNetworkError  : ((String) -> Void)?

    // MARK: - 🚀 Khởi động (gọi trong AppDelegate / @main)
    /// Gọi hàm này khi app khởi động. Tự động tìm key đã lưu.
    func initialize(completion: @escaping (LicenseStatus) -> Void) {
        // 1. Thử lấy key từ Keychain trước
        if let savedKey = KeychainHelper.shared.loadKey() {
            let deviceId = getOrCreateDeviceId()
            activateKey(savedKey, deviceId: deviceId, completion: completion)
            return
        }

        // 2. Thử lấy key từ server (cache theo device_id)
        let deviceId = getOrCreateDeviceId()
        getSavedKeyFromServer(deviceId: deviceId) { [weak self] result in
            switch result {
            case .success(let serverKey):
                // Tìm thấy key trên server → lưu vào Keychain và kích hoạt
                KeychainHelper.shared.saveKey(serverKey)
                self?.activateKey(serverKey, deviceId: deviceId, completion: completion)
            case .failure:
                // Không có key nào → yêu cầu người dùng nhập
                completion(.invalid(message: "Vui lòng nhập license key"))
            }
        }
    }

    // MARK: - 🔑 Kích hoạt key
    func activateKey(_ key: String,
                     deviceId: String? = nil,
                     completion: @escaping (LicenseStatus) -> Void) {
        let did = deviceId ?? getOrCreateDeviceId()
        var params = baseParams()
        params["action"]       = "activate_key"
        params["key"]          = key.uppercased().trimmingCharacters(in: .whitespaces)
        params["device_id"]    = did
        params["device_name"]  = UIDevice.current.name
        params["device_model"] = deviceModel()
        params["ios_version"]  = UIDevice.current.systemVersion

        request(params: params) { [weak self] result in
            guard let self = self else { return }
            switch result {
            case .success(let json):
                let status = json["status"] as? String ?? ""
                switch status {
                case "activated", "valid":
                    let exp  = json["expires_at"]     as? String ?? ""
                    let days = json["days_remaining"] as? Int    ?? 0
                    self.isValid   = true
                    self.expiresAt = exp
                    self.daysLeft  = days
                    self.currentKey = key
                    // Lưu vào Keychain + server cache
                    KeychainHelper.shared.saveKey(key)
                    self.saveKeyToServer(key: key, deviceId: did)
                    self.startPing(key: key, deviceId: did)
                    completion(.valid(expiresAt: exp, daysRemaining: days))

                case "expired":
                    self.isValid = false
                    let exp = json["expired_at"] as? String ?? json["expires_at"] as? String ?? ""
                    completion(.expired(expiredAt: exp))

                case "max_devices":
                    let mx   = json["max_devices"] as? Int ?? 1
                    let used = json["used"]        as? Int ?? mx
                    completion(.maxDevices(max: mx, used: used))

                case "banned":
                    self.isValid = false
                    completion(.banned)

                case "not_activated":
                    let days = json["duration_days"] as? Int ?? 0
                    completion(.notActivated(durationDays: days))

                default:
                    let msg = json["message"] as? String ?? "Key không hợp lệ"
                    completion(.invalid(message: msg))
                }

            case .failure(let err):
                completion(.networkError(message: err.localizedDescription))
            }
        }
    }

    // MARK: - 🔍 Kiểm tra key (không kích hoạt)
    func checkKey(_ key: String, completion: @escaping (LicenseStatus) -> Void) {
        var params = baseParams()
        params["action"]    = "check_key"
        params["key"]       = key.uppercased()
        params["device_id"] = getOrCreateDeviceId()

        request(params: params) { result in
            switch result {
            case .success(let json):
                let status = json["status"] as? String ?? ""
                switch status {
                case "valid":
                    let exp  = json["expires_at"]     as? String ?? ""
                    let days = json["days_remaining"] as? Int    ?? 0
                    completion(.valid(expiresAt: exp, daysRemaining: days))
                case "expired":
                    let exp = json["expired_at"] as? String ?? ""
                    completion(.expired(expiredAt: exp))
                case "not_activated":
                    let days = json["duration_days"] as? Int ?? 0
                    completion(.notActivated(durationDays: days))
                case "banned":
                    completion(.banned)
                default:
                    let msg = json["message"] as? String ?? "Không hợp lệ"
                    completion(.invalid(message: msg))
                }
            case .failure(let err):
                completion(.networkError(message: err.localizedDescription))
            }
        }
    }

    // MARK: - 💾 Lấy key đã lưu từ server (theo device_id)
    private func getSavedKeyFromServer(deviceId: String,
                                       completion: @escaping (Result<String, Error>) -> Void) {
        var params = baseParams()
        params["action"]    = "get_saved_key"
        params["device_id"] = deviceId

        request(params: params) { result in
            switch result {
            case .success(let json):
                let status = json["status"] as? String ?? ""
                if (status == "found"), let k = json["key"] as? String {
                    completion(.success(k))
                } else {
                    completion(.failure(NSError(domain: "LM", code: 404,
                        userInfo: [NSLocalizedDescriptionKey: "No saved key"])))
                }
            case .failure(let err):
                completion(.failure(err))
            }
        }
    }

    // MARK: - 💾 Lưu key lên server cache
    func saveKeyToServer(key: String, deviceId: String? = nil) {
        var params = baseParams()
        params["action"]    = "save_key"
        params["key"]       = key.uppercased()
        params["device_id"] = deviceId ?? getOrCreateDeviceId()
        request(params: params) { _ in }  // fire & forget
    }

    // MARK: - 🗑️ Xóa key (đăng xuất)
    func clearKey() {
        isValid    = false
        expiresAt  = nil
        daysLeft   = 0
        currentKey = nil
        KeychainHelper.shared.deleteKey()
        stopPing()
    }

    // MARK: - ❤️ Ping heartbeat
    private func startPing(key: String, deviceId: String) {
        stopPing()
        pingTimer = Timer.scheduledTimer(withTimeInterval: Config.pingInterval, repeats: true) { [weak self] _ in
            self?.sendPing(key: key, deviceId: deviceId)
        }
    }

    private func stopPing() {
        pingTimer?.invalidate()
        pingTimer = nil
    }

    private func sendPing(key: String, deviceId: String) {
        var params = baseParams()
        params["action"]    = "ping"
        params["key"]       = key
        params["device_id"] = deviceId

        request(params: params) { [weak self] result in
            if case .success(let json) = result {
                let status = json["status"] as? String ?? ""
                switch status {
                case "expired": self?.isValid = false; self?.onKeyExpired?()
                case "invalid": self?.isValid = false; self?.onKeyBanned?()
                case "valid":
                    self?.isValid   = true
                    self?.expiresAt = json["expires_at"] as? String
                    self?.daysLeft  = json["days_remaining"] as? Int ?? 0
                default: break
                }
            }
        }
    }

    // MARK: - 🆔 Device ID (Keychain-persistent qua reinstall)
    func getOrCreateDeviceId() -> String {
        let kc = KeychainHelper.shared
        if let existing = kc.loadDeviceId() { return existing }
        // Tạo mới: kết hợp UUID + model hash để tăng tính nhất quán
        let newId = UUID().uuidString.replacingOccurrences(of: "-", with: "")
        kc.saveDeviceId(newId)
        return newId
    }

    // MARK: - 🌐 Network Request
    private func request(params: [String: String],
                         completion: @escaping (Result<[String: Any], Error>) -> Void) {
        guard let url = URL(string: Config.apiURL) else {
            completion(.failure(NSError(domain: "LM", code: -1,
                userInfo: [NSLocalizedDescriptionKey: "URL không hợp lệ"])))
            return
        }

        var req        = URLRequest(url: url)
        req.httpMethod = "POST"
        req.setValue("application/x-www-form-urlencoded", forHTTPHeaderField: "Content-Type")
        req.setValue(Config.apiSecret, forHTTPHeaderField: "X-Api-Secret")
        req.httpBody   = params
            .map { "\($0.key)=\($0.value.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? "")" }
            .joined(separator: "&")
            .data(using: .utf8)

        session.dataTask(with: req) { data, _, error in
            DispatchQueue.main.async {
                if let err = error {
                    completion(.failure(err)); return
                }
                guard let data = data,
                      let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any]
                else {
                    completion(.failure(NSError(domain: "LM", code: -2,
                        userInfo: [NSLocalizedDescriptionKey: "Phản hồi không hợp lệ"])))
                    return
                }
                completion(.success(json))
            }
        }.resume()
    }

    private func baseParams() -> [String: String] {
        return ["api_secret": Config.apiSecret]
    }

    private func deviceModel() -> String {
        var sysinfo = utsname()
        uname(&sysinfo)
        return withUnsafePointer(to: &sysinfo.machine) {
            $0.withMemoryRebound(to: CChar.self, capacity: 1) { String(cString: $0) }
        }
    }
}

// MARK: - 🔐 KeychainHelper
final class KeychainHelper {
    static let shared = KeychainHelper()
    private init() {}

    private let keyTag      = "com.yourapp.licensekey"
    private let deviceIdTag = "com.yourapp.deviceid"

    // ── Save / Load / Delete License Key ──────────────────
    func saveKey(_ key: String) {
        save(key, tag: keyTag)
    }

    func loadKey() -> String? {
        return load(tag: keyTag)
    }

    func deleteKey() {
        delete(tag: keyTag)
    }

    // ── Save / Load Device ID ──────────────────────────────
    func saveDeviceId(_ id: String) {
        save(id, tag: deviceIdTag)
    }

    func loadDeviceId() -> String? {
        return load(tag: deviceIdTag)
    }

    // ── Low-level Keychain ops ─────────────────────────────
    //
    // kSecAttrAccessibleAfterFirstUnlock:
    //  → Data tồn tại sau khi xóa app và cài lại
    //  → Đây là cơ chế "không cần nhập lại key sau reinstall"
    //
    private func save(_ value: String, tag: String) {
        guard let data = value.data(using: .utf8) else { return }
        delete(tag: tag)  // xóa cũ trước
        let query: [CFString: Any] = [
            kSecClass              : kSecClassGenericPassword,
            kSecAttrAccount        : tag,
            kSecAttrService        : Bundle.main.bundleIdentifier ?? "com.app",
            kSecValueData          : data,
            kSecAttrAccessible     : kSecAttrAccessibleAfterFirstUnlock,
            kSecAttrSynchronizable : kCFBooleanFalse as Any  // local only
        ]
        SecItemAdd(query as CFDictionary, nil)
    }

    private func load(tag: String) -> String? {
        let query: [CFString: Any] = [
            kSecClass              : kSecClassGenericPassword,
            kSecAttrAccount        : tag,
            kSecAttrService        : Bundle.main.bundleIdentifier ?? "com.app",
            kSecReturnData         : kCFBooleanTrue as Any,
            kSecMatchLimit         : kSecMatchLimitOne,
            kSecAttrAccessible     : kSecAttrAccessibleAfterFirstUnlock
        ]
        var result: AnyObject?
        let status = SecItemCopyMatching(query as CFDictionary, &result)
        guard status == errSecSuccess,
              let data = result as? Data,
              let str  = String(data: data, encoding: .utf8)
        else { return nil }
        return str
    }

    private func delete(tag: String) {
        let query: [CFString: Any] = [
            kSecClass       : kSecClassGenericPassword,
            kSecAttrAccount : tag,
            kSecAttrService : Bundle.main.bundleIdentifier ?? "com.app"
        ]
        SecItemDelete(query as CFDictionary)
    }
}

// MARK: - 🖼️ LicenseViewController (UI mẫu)
// Kéo thả hoặc present ViewController này khi cần nhập key
final class LicenseViewController: UIViewController {

    var onSuccess: ((LicenseStatus) -> Void)?

    private let cardView    = UIView()
    private let titleLabel  = UILabel()
    private let subtitleLbl = UILabel()
    private let keyField    = UITextField()
    private let activateBtn = UIButton(type: .system)
    private let statusLabel = UILabel()
    private let spinner     = UIActivityIndicatorView(style: .medium)

    override func viewDidLoad() {
        super.viewDidLoad()
        setupUI()
    }

    private func setupUI() {
        view.backgroundColor = UIColor(red: 0.06, green: 0.06, blue: 0.10, alpha: 1)

        // Card
        cardView.backgroundColor    = UIColor(red: 0.10, green: 0.10, blue: 0.18, alpha: 1)
        cardView.layer.cornerRadius = 18
        cardView.layer.borderWidth  = 1
        cardView.layer.borderColor  = UIColor(red: 0.16, green: 0.16, blue: 0.27, alpha: 1).cgColor
        cardView.translatesAutoresizingMaskIntoConstraints = false
        view.addSubview(cardView)

        // Icon
        let icon = UILabel()
        icon.text      = "🔐"
        icon.font      = .systemFont(ofSize: 48)
        icon.textAlignment = .center
        icon.translatesAutoresizingMaskIntoConstraints = false
        cardView.addSubview(icon)

        // Title
        titleLabel.text      = "License Key"
        titleLabel.font      = .systemFont(ofSize: 22, weight: .bold)
        titleLabel.textColor = UIColor(red: 0.88, green: 0.88, blue: 1.0, alpha: 1)
        titleLabel.textAlignment = .center
        titleLabel.translatesAutoresizingMaskIntoConstraints = false
        cardView.addSubview(titleLabel)

        // Subtitle
        subtitleLbl.text          = "Nhập license key để tiếp tục"
        subtitleLbl.font          = .systemFont(ofSize: 13)
        subtitleLbl.textColor     = .systemGray
        subtitleLbl.textAlignment = .center
        subtitleLbl.translatesAutoresizingMaskIntoConstraints = false
        cardView.addSubview(subtitleLbl)

        // Key field
        keyField.placeholder   = "XXXX-XXXX-XXXX-XXXX"
        keyField.font          = UIFont.monospacedSystemFont(ofSize: 17, weight: .semibold)
        keyField.textColor     = UIColor(red: 0.67, green: 0.55, blue: 1.0, alpha: 1)
        keyField.textAlignment = .center
        keyField.autocorrectionType       = .no
        keyField.autocapitalizationType   = .allCharacters
        keyField.keyboardType             = .asciiCapable
        keyField.backgroundColor          = UIColor(red: 0.06, green: 0.06, blue: 0.10, alpha: 1)
        keyField.layer.cornerRadius       = 10
        keyField.layer.borderWidth        = 1
        keyField.layer.borderColor        = UIColor(red: 0.16, green: 0.16, blue: 0.27, alpha: 1).cgColor
        keyField.translatesAutoresizingMaskIntoConstraints = false
        // Padding
        let padView = UIView(frame: CGRect(x: 0, y: 0, width: 14, height: 1))
        keyField.leftView = padView; keyField.leftViewMode = .always
        keyField.rightView = padView; keyField.rightViewMode = .always
        // Auto-format XXXX-XXXX-XXXX-XXXX
        keyField.addTarget(self, action: #selector(keyFieldChanged), for: .editingChanged)
        cardView.addSubview(keyField)

        // Activate button
        activateBtn.setTitle("Kích hoạt", for: .normal)
        activateBtn.titleLabel?.font        = .systemFont(ofSize: 16, weight: .bold)
        activateBtn.setTitleColor(.white, for: .normal)
        activateBtn.backgroundColor         = UIColor(red: 0.49, green: 0.23, blue: 0.93, alpha: 1)
        activateBtn.layer.cornerRadius      = 12
        activateBtn.translatesAutoresizingMaskIntoConstraints = false
        activateBtn.addTarget(self, action: #selector(doActivate), for: .touchUpInside)
        cardView.addSubview(activateBtn)

        // Spinner
        spinner.color = .white
        spinner.translatesAutoresizingMaskIntoConstraints = false
        spinner.hidesWhenStopped = true
        cardView.addSubview(spinner)

        // Status label
        statusLabel.text          = ""
        statusLabel.font          = .systemFont(ofSize: 12)
        statusLabel.textColor     = .systemRed
        statusLabel.textAlignment = .center
        statusLabel.numberOfLines = 0
        statusLabel.translatesAutoresizingMaskIntoConstraints = false
        cardView.addSubview(statusLabel)

        // Constraints
        NSLayoutConstraint.activate([
            cardView.centerXAnchor.constraint(equalTo: view.centerXAnchor),
            cardView.centerYAnchor.constraint(equalTo: view.centerYAnchor),
            cardView.leadingAnchor.constraint(equalTo: view.leadingAnchor, constant: 24),
            cardView.trailingAnchor.constraint(equalTo: view.trailingAnchor, constant: -24),

            icon.topAnchor.constraint(equalTo: cardView.topAnchor, constant: 28),
            icon.centerXAnchor.constraint(equalTo: cardView.centerXAnchor),

            titleLabel.topAnchor.constraint(equalTo: icon.bottomAnchor, constant: 10),
            titleLabel.leadingAnchor.constraint(equalTo: cardView.leadingAnchor, constant: 16),
            titleLabel.trailingAnchor.constraint(equalTo: cardView.trailingAnchor, constant: -16),

            subtitleLbl.topAnchor.constraint(equalTo: titleLabel.bottomAnchor, constant: 6),
            subtitleLbl.leadingAnchor.constraint(equalTo: cardView.leadingAnchor, constant: 16),
            subtitleLbl.trailingAnchor.constraint(equalTo: cardView.trailingAnchor, constant: -16),

            keyField.topAnchor.constraint(equalTo: subtitleLbl.bottomAnchor, constant: 24),
            keyField.leadingAnchor.constraint(equalTo: cardView.leadingAnchor, constant: 20),
            keyField.trailingAnchor.constraint(equalTo: cardView.trailingAnchor, constant: -20),
            keyField.heightAnchor.constraint(equalToConstant: 50),

            activateBtn.topAnchor.constraint(equalTo: keyField.bottomAnchor, constant: 14),
            activateBtn.leadingAnchor.constraint(equalTo: cardView.leadingAnchor, constant: 20),
            activateBtn.trailingAnchor.constraint(equalTo: cardView.trailingAnchor, constant: -20),
            activateBtn.heightAnchor.constraint(equalToConstant: 50),

            spinner.centerXAnchor.constraint(equalTo: cardView.centerXAnchor),
            spinner.topAnchor.constraint(equalTo: activateBtn.bottomAnchor, constant: 12),

            statusLabel.topAnchor.constraint(equalTo: spinner.bottomAnchor, constant: 4),
            statusLabel.leadingAnchor.constraint(equalTo: cardView.leadingAnchor, constant: 16),
            statusLabel.trailingAnchor.constraint(equalTo: cardView.trailingAnchor, constant: -16),
            statusLabel.bottomAnchor.constraint(equalTo: cardView.bottomAnchor, constant: -24),
        ])
    }

    @objc private func keyFieldChanged() {
        guard var text = keyField.text else { return }
        text = text.uppercased().replacingOccurrences(of: "[^A-Z0-9]", with: "", options: .regularExpression)
        if text.count > 16 { text = String(text.prefix(16)) }
        var result = ""
        for (i, ch) in text.enumerated() {
            if i > 0 && i % 4 == 0 { result += "-" }
            result.append(ch)
        }
        keyField.text = result
    }

    @objc private func doActivate() {
        guard let key = keyField.text, key.count == 19 else {
            setStatus("Key phải có dạng XXXX-XXXX-XXXX-XXXX", color: .systemRed); return
        }
        setLoading(true)
        LicenseManager.shared.activateKey(key) { [weak self] status in
            self?.setLoading(false)
            switch status {
            case .valid(let exp, let days):
                self?.setStatus("✅ Hợp lệ – Còn \(days) ngày (hết \(exp))", color: .systemGreen)
                DispatchQueue.main.asyncAfter(deadline: .now() + 1.2) {
                    self?.onSuccess?(status)
                    self?.dismiss(animated: true)
                }
            case .expired(let exp):
                self?.setStatus("❌ Key đã hết hạn lúc \(exp)", color: .systemRed)
            case .invalid(let msg):
                self?.setStatus("❌ \(msg)", color: .systemRed)
            case .notActivated(let days):
                self?.setStatus("⚡ Key hợp lệ (\(days) ngày) – đang kích hoạt...", color: .systemYellow)
            case .maxDevices(let mx, let used):
                self?.setStatus("❌ Đã đạt giới hạn \(used)/\(mx) thiết bị", color: .systemOrange)
            case .banned:
                self?.setStatus("🚫 Key bị vô hiệu hóa", color: .systemRed)
            case .networkError(let msg):
                self?.setStatus("⚠️ Lỗi mạng: \(msg)", color: .systemOrange)
            }
        }
    }

    private func setStatus(_ text: String, color: UIColor) {
        statusLabel.text      = text
        statusLabel.textColor = color
    }

    private func setLoading(_ loading: Bool) {
        activateBtn.isEnabled = !loading
        loading ? spinner.startAnimating() : spinner.stopAnimating()
        activateBtn.alpha = loading ? 0.6 : 1.0
    }
}

// MARK: - 📋 Cách dùng trong AppDelegate / SceneDelegate
/*
// AppDelegate.swift hoặc @main struct

func application(_ application: UIApplication,
                 didFinishLaunchingWithOptions ...) -> Bool {

    // Thiết lập callbacks
    LicenseManager.shared.onKeyExpired = {
        // Key hết hạn giữa chừng → về màn nhập key
    }
    LicenseManager.shared.onKeyBanned = {
        // Key bị ban
    }

    // Khởi tạo: tự tìm key đã lưu
    LicenseManager.shared.initialize { status in
        switch status {
        case .valid(let exp, let days):
            // ✅ Có key hợp lệ → vào app thẳng
            print("Key hợp lệ, còn \(days) ngày")
            // present MainViewController

        default:
            // ❌ Không có key → hiện màn nhập key
            let licVC = LicenseViewController()
            licVC.modalPresentationStyle = .fullScreen
            licVC.onSuccess = { _ in
                // present MainViewController
            }
            window?.rootViewController?.present(licVC, animated: false)
        }
    }
    return true
}
*/

// MARK: - 🔧 Tích hợp nhanh vào SwiftUI
/*
// ContentView.swift (SwiftUI)

struct ContentView: View {
    @State private var isLicensed = false
    @State private var showLicense = false

    var body: some View {
        Group {
            if isLicensed {
                MainView()
            } else {
                ProgressView("Đang kiểm tra license...")
                    .onAppear { checkLicense() }
            }
        }
        .sheet(isPresented: $showLicense) {
            LicenseViewRepresentable(onSuccess: { isLicensed = true })
        }
    }

    func checkLicense() {
        LicenseManager.shared.initialize { status in
            if case .valid = status {
                isLicensed = true
            } else {
                showLicense = true
            }
        }
    }
}

struct LicenseViewRepresentable: UIViewControllerRepresentable {
    var onSuccess: () -> Void
    func makeUIViewController(context: Context) -> LicenseViewController {
        let vc = LicenseViewController()
        vc.onSuccess = { _ in onSuccess() }
        return vc
    }
    func updateUIViewController(_ vc: LicenseViewController, context: Context) {}
}
*/
