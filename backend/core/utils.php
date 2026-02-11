<?php
/**
 * 公共工具类
 * 抽取项目中重复使用的工具函数
 */

class Utils {
    
    // ==================== IP 相关 ====================
    
    /**
     * 获取客户端真实IP
     * 支持代理、CDN、负载均衡等场景
     */
    public static function getClientIp(): string {
        // 优先检查 X-Forwarded-For（代理/CDN场景）
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && $_SERVER['HTTP_X_FORWARDED_FOR'] !== '') {
            // 取第一个IP（最原始的客户端IP）
            $ip = trim(strtok($_SERVER['HTTP_X_FORWARDED_FOR'], ','));
            if (self::isValidIp($ip)) {
                return $ip;
            }
        }
        
        // 检查 X-Real-IP（Nginx常用）
        if (isset($_SERVER['HTTP_X_REAL_IP']) && $_SERVER['HTTP_X_REAL_IP'] !== '') {
            $ip = trim($_SERVER['HTTP_X_REAL_IP']);
            if (self::isValidIp($ip)) {
                return $ip;
            }
        }
        
        // 检查 CF-Connecting-IP（Cloudflare）
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) && $_SERVER['HTTP_CF_CONNECTING_IP'] !== '') {
            $ip = trim($_SERVER['HTTP_CF_CONNECTING_IP']);
            if (self::isValidIp($ip)) {
                return $ip;
            }
        }
        
        // 最后使用 REMOTE_ADDR
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * 验证IP地址是否有效
     */
    public static function isValidIp(string $ip): bool {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            || filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
    
    /**
     * 检查是否为本地IP
     */
    public static function isLocalIp(string $ip): bool {
        $localPatterns = ['127.0.0.1', '::1', 'localhost'];
        $privateRanges = ['192.168.', '10.', '172.16.', '172.17.', '172.18.', '172.19.', 
                         '172.20.', '172.21.', '172.22.', '172.23.', '172.24.', '172.25.',
                         '172.26.', '172.27.', '172.28.', '172.29.', '172.30.', '172.31.'];
        
        if (in_array($ip, $localPatterns)) {
            return true;
        }
        
        foreach ($privateRanges as $range) {
            if (strpos($ip, $range) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * IP脱敏处理（用于日志）
     */
    public static function maskIp(string $ip): string {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // IPv4: 192.168.1.100 -> 192.168.1.***
            $parts = explode('.', $ip);
            $parts[3] = '***';
            return implode('.', $parts);
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // IPv6: 简化处理
            return substr($ip, 0, strrpos($ip, ':') + 1) . '****';
        }
        return '***.***.***';
    }
    
    // ==================== 设备检测 ====================
    
    /**
     * 获取设备类型
     */
    public static function getDeviceType(): string {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ua_lower = strtolower($ua);
        
        if (strpos($ua_lower, 'iphone') !== false || strpos($ua_lower, 'ipad') !== false) {
            return 'ios';
        }
        if (strpos($ua_lower, 'android') !== false) {
            return 'android';
        }
        if ((strpos($ua_lower, 'windows') !== false || strpos($ua_lower, 'macintosh') !== false)
            && strpos($ua_lower, 'mobile') === false) {
            return 'desktop';
        }
        return 'mobile';
    }
    
    /**
     * 获取浏览器类型
     */
    public static function getBrowserType(): string {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        if (preg_match('/MSIE|Trident/i', $ua)) return 'ie';
        if (preg_match('/Firefox/i', $ua)) return 'firefox';
        if (preg_match('/Chrome/i', $ua) && !preg_match('/Edge|Edg/i', $ua)) return 'chrome';
        if (preg_match('/Safari/i', $ua) && !preg_match('/Chrome/i', $ua)) return 'safari';
        if (preg_match('/Edge|Edg/i', $ua)) return 'edge';
        if (preg_match('/Opera|OPR/i', $ua)) return 'opera';
        
        return 'other';
    }
    
    // ==================== URL 处理 ====================
    
    /**
     * URL自动补全协议
     */
    public static function autoCompleteUrl(string $url): string {
        $url = trim($url);
        if (empty($url)) {
            return $url;
        }
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }
        return $url;
    }
    
    /**
     * 验证URL格式
     */
    public static function isValidUrl(string $url): bool {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
    
    /**
     * 从URL提取域名
     */
    public static function extractDomain(string $url): ?string {
        $parsed = parse_url($url);
        return $parsed['host'] ?? null;
    }
    
    // ==================== 字符串处理 ====================
    
    /**
     * 生成随机字符串
     */
    public static function randomString(int $length = 16, string $chars = ''): string {
        if (empty($chars)) {
            $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        }
        $result = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, $max)];
        }
        return $result;
    }
    
    /**
     * 生成UUID v4
     */
    public static function uuid(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
    
    /**
     * 敏感信息脱敏
     */
    public static function maskSensitive(string $value, int $keepStart = 3, int $keepEnd = 3): string {
        $length = mb_strlen($value);
        if ($length <= $keepStart + $keepEnd) {
            return str_repeat('*', $length);
        }
        $start = mb_substr($value, 0, $keepStart);
        $end = mb_substr($value, -$keepEnd);
        $maskLength = $length - $keepStart - $keepEnd;
        return $start . str_repeat('*', min($maskLength, 6)) . $end;
    }
    
    // ==================== 安全相关 ====================
    
    /**
     * XSS 输出编码
     */
    public static function escapeHtml(?string $value): string {
        if ($value === null) {
            return '';
        }
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * 批量XSS编码数组
     */
    public static function escapeArray(array $data): array {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $result[$key] = self::escapeHtml($value);
            } elseif (is_array($value)) {
                $result[$key] = self::escapeArray($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
    
    /**
     * HMAC签名生成
     */
    public static function generateHmac(string $data, string $secret, string $algo = 'sha256'): string {
        return hash_hmac($algo, $data, $secret);
    }
    
    /**
     * HMAC签名验证
     */
    public static function verifyHmac(string $data, string $signature, string $secret, string $algo = 'sha256'): bool {
        $expected = self::generateHmac($data, $secret, $algo);
        return hash_equals($expected, $signature);
    }
    
    /**
     * 生成API请求签名
     */
    public static function signRequest(array $params, string $secret, int $timestamp = 0): string {
        if ($timestamp === 0) {
            $timestamp = time();
        }
        
        // 按键名排序
        ksort($params);
        
        // 构建签名字符串
        $signStr = '';
        foreach ($params as $key => $value) {
            if ($key !== 'sign' && $value !== '') {
                $signStr .= $key . '=' . $value . '&';
            }
        }
        $signStr .= 'timestamp=' . $timestamp . '&secret=' . $secret;
        
        return self::generateHmac($signStr, $secret);
    }
    
    // ==================== 时间处理 ====================
    
    /**
     * 获取当前时间戳（毫秒）
     */
    public static function nowMs(): int {
        return (int)(microtime(true) * 1000);
    }
    
    /**
     * 格式化时间差
     */
    public static function formatTimeDiff(int $seconds): string {
        if ($seconds < 60) {
            return $seconds . '秒';
        } elseif ($seconds < 3600) {
            return floor($seconds / 60) . '分钟';
        } elseif ($seconds < 86400) {
            return floor($seconds / 3600) . '小时';
        } else {
            return floor($seconds / 86400) . '天';
        }
    }
    
    // ==================== 数组处理 ====================
    
    /**
     * 安全获取数组值
     */
    public static function arrayGet(array $array, string $key, $default = null) {
        return $array[$key] ?? $default;
    }
    
    /**
     * 数组转CSV行
     */
    public static function arrayToCsvRow(array $data): string {
        $output = fopen('php://temp', 'r+');
        fputcsv($output, $data);
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return rtrim($csv, "\n");
    }
    
    // ==================== JSON 处理 ====================
    
    /**
     * 安全JSON编码
     */
    public static function jsonEncode($data, int $flags = 0): string {
        $flags |= JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $result = json_encode($data, $flags);
        return $result === false ? '{}' : $result;
    }
    
    /**
     * 安全JSON解码
     */
    public static function jsonDecode(string $json, $default = null) {
        $data = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $data : $default;
    }
    
    // ==================== HTTP 响应 ====================
    
    /**
     * 设置安全响应头
     */
    public static function setSecurityHeaders(): void {
        // 防止XSS
        header('X-XSS-Protection: 1; mode=block');
        // 防止点击劫持
        header('X-Frame-Options: SAMEORIGIN');
        // 禁止内容嗅探
        header('X-Content-Type-Options: nosniff');
        // CSP策略
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:;");
        // 强制HTTPS（生产环境）
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        // Referrer策略
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
    
    /**
     * 检查是否HTTPS
     */
    public static function isHttps(): bool {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? 80) == 443
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    }
    
    /**
     * JSON响应
     */
    public static function jsonResponse(array $data, int $statusCode = 200): void {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo self::jsonEncode($data);
    }
    
    /**
     * 成功响应
     */
    public static function success($data = null, string $message = '操作成功'): void {
        self::jsonResponse([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
    }
    
    /**
     * 错误响应
     */
    public static function error(string $message, int $code = 400, $data = null): void {
        self::jsonResponse([
            'success' => false,
            'message' => $message,
            'code' => $code,
            'data' => $data
        ], 200); // 保持200状态码，错误信息放在body中
    }
}
