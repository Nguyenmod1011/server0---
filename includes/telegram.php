<?php
// ============================================================
// includes/telegram.php – Telegram Bot Notifications
// ============================================================
// Cài đặt trong config.php:
//   define('TG_BOT_TOKEN', 'your:bot_token');
//   define('TG_CHAT_ID',   'your_chat_id');
//   define('TG_ENABLED',   true);
//
// Lấy bot token: https://t.me/BotFather
// Lấy chat ID: https://t.me/userinfobot
// ============================================================

class TelegramNotifier {

    private string $token;
    private string $chatId;

    public function __construct() {
        $this->token  = defined('TG_BOT_TOKEN') ? TG_BOT_TOKEN : '';
        $this->chatId = defined('TG_CHAT_ID')   ? TG_CHAT_ID   : '';
    }

    private function isEnabled(): bool {
        return defined('TG_ENABLED') && TG_ENABLED && $this->token && $this->chatId;
    }

    // ── Gửi tin nhắn ────────────────────────────────────────
    public function send(string $text): bool {
        if (!$this->isEnabled()) return false;
        $url  = "https://api.telegram.org/bot{$this->token}/sendMessage";
        $data = ['chat_id' => $this->chatId, 'text' => $text, 'parse_mode' => 'HTML'];
        $ch   = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result !== false;
    }

    // ── Templates thông báo ──────────────────────────────────

    public function notifyKeyActivated(string $key, string $deviceName, string $deviceModel, string $iosVersion, string $ip, string $expiresAt): void {
        $msg = "🔑 <b>Key Kích hoạt</b>\n\n"
             . "Key: <code>$key</code>\n"
             . "Thiết bị: <b>$deviceName</b> ($deviceModel)\n"
             . "iOS: $iosVersion\n"
             . "IP: <code>$ip</code>\n"
             . "Hết hạn: <b>$expiresAt</b>\n"
             . "Thời gian: " . date('d/m/Y H:i:s');
        $this->send($msg);
    }

    public function notifyKeyExpired(string $key): void {
        $msg = "⚠️ <b>Key Hết hạn</b>\n\n"
             . "Key: <code>$key</code>\n"
             . "Thời gian: " . date('d/m/Y H:i:s');
        $this->send($msg);
    }

    public function notifyNewReseller(string $username, int $quota): void {
        $msg = "👤 <b>Đại lý mới</b>\n\n"
             . "Username: @$username\n"
             . "Quota: $quota keys\n"
             . "Thời gian: " . date('d/m/Y H:i:s');
        $this->send($msg);
    }

    public function notifyAdminLogin(string $username, string $ip): void {
        $msg = "🔐 <b>Admin Đăng nhập</b>\n\n"
             . "Username: @$username\n"
             . "IP: <code>$ip</code>\n"
             . "Thời gian: " . date('d/m/Y H:i:s');
        $this->send($msg);
    }

    public function notifyDailySummary(array $stats): void {
        $msg = "📊 <b>Báo cáo hàng ngày</b> " . date('d/m/Y') . "\n\n"
             . "🔑 Tổng keys: <b>{$stats['total']}</b>\n"
             . "✅ Đang dùng: <b>{$stats['active']}</b>\n"
             . "❌ Hết hạn: <b>{$stats['expired']}</b>\n"
             . "⏳ Chưa dùng: <b>{$stats['unused']}</b>\n"
             . "📱 Thiết bị: <b>{$stats['devices']}</b>\n"
             . "👥 Đại lý: <b>{$stats['resellers']}</b>";
        $this->send($msg);
    }

    // Test kết nối
    public function test(): bool {
        return $this->send("✅ License Manager Pro – Kết nối Telegram thành công!\nThời gian: " . date('d/m/Y H:i:s'));
    }
}

// Singleton helper
function tg(): TelegramNotifier {
    static $instance = null;
    if (!$instance) $instance = new TelegramNotifier();
    return $instance;
}
