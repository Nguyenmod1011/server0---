<?php
// ============================================================
// includes/rate_limiter.php – Rate Limiting cho API
// Dùng file-based cache, không cần Redis/APCu
// ============================================================

class RateLimiter {
    private string $cacheDir;
    private int    $maxRequests;
    private int    $windowSeconds;

    public function __construct(
        int    $maxRequests   = 60,   // Tối đa X requests
        int    $windowSeconds = 60,   // Trong Y giây
        string $cacheDir      = ''
    ) {
        $this->maxRequests   = $maxRequests;
        $this->windowSeconds = $windowSeconds;
        $this->cacheDir      = $cacheDir ?: sys_get_temp_dir() . '/lm_ratelimit';
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0700, true);
        }
    }

    /**
     * Kiểm tra xem IP có vượt giới hạn không.
     * @return array ['allowed'=>bool, 'remaining'=>int, 'retry_after'=>int]
     */
    public function check(string $ip, string $action = 'api'): array {
        $key  = preg_replace('/[^a-zA-Z0-9_]/', '_', $ip . '_' . $action);
        $file = $this->cacheDir . '/' . $key . '.json';
        $now  = time();

        // Đọc state
        $state = ['requests' => [], 'blocked_until' => 0];
        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            if ($raw) $state = json_decode($raw, true) ?? $state;
        }

        // Còn trong thời gian bị block
        if ($state['blocked_until'] > $now) {
            return [
                'allowed'     => false,
                'remaining'   => 0,
                'retry_after' => $state['blocked_until'] - $now,
                'blocked'     => true,
            ];
        }

        // Lọc requests trong window
        $window   = $now - $this->windowSeconds;
        $requests = array_filter($state['requests'], fn($t) => $t > $window);
        $count    = count($requests);

        if ($count >= $this->maxRequests) {
            // Vượt quá → block thêm 60 giây
            $state['blocked_until'] = $now + 60;
            $state['requests']      = array_values($requests);
            $this->save($file, $state);
            return [
                'allowed'     => false,
                'remaining'   => 0,
                'retry_after' => 60,
                'blocked'     => true,
            ];
        }

        // Cho phép → ghi nhận request
        $requests[] = $now;
        $state['requests'] = array_values($requests);
        $this->save($file, $state);

        return [
            'allowed'   => true,
            'remaining' => $this->maxRequests - count($requests),
            'reset_at'  => $now + $this->windowSeconds,
        ];
    }

    private function save(string $file, array $state): void {
        @file_put_contents($file, json_encode($state), LOCK_EX);
    }

    /**
     * Dọn dẹp file cache cũ (gọi định kỳ)
     */
    public function cleanup(): void {
        $files = glob($this->cacheDir . '/*.json') ?: [];
        $old   = time() - 3600; // Xóa file > 1 tiếng
        foreach ($files as $f) {
            if (filemtime($f) < $old) @unlink($f);
        }
    }
}
