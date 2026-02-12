<?php
/**
 * 安全模块
 * 密码哈希、CSRF防护、Session安全、登录锁定、TOTP双因素认证
 */

class Security {
    private static $instance = null;
    private $db = null;
    
    // CSRF Token 有效期（秒）
    const CSRF_TOKEN_LIFETIME = 3600;
    
    // 密码哈希算法
    const PASSWORD_ALGO = PASSWORD_BCRYPT;
    const PASSWORD_OPTIONS = ['cost' => 12];
    
    // 登录锁定配置
    const MAX_LOGIN_ATTEMPTS = 5;      // 最大失败次数
    const LOCKOUT_DURATION = 900;       // 锁定时长（秒）= 15分钟
    const ATTEMPT_WINDOW = 300;         // 计算失败次数的时间窗口（秒）= 5分钟
    
    // TOTP 配置
    const TOTP_ISSUER = 'IP管理器';
    const TOTP_DIGITS = 6;
    const TOTP_PERIOD = 30;
    const TOTP_ALGORITHM = 'sha1';
    
    private function __construct() {
        // 配置安全的 session
        $this->configureSession();
        
        // 获取数据库实例
        if (class_exists('Database')) {
            $this->db = Database::getInstance();
        }
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 配置安全的 Session
     */
    private function configureSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
                       || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
            
            session_set_cookie_params([
                'lifetime' => 86400,  // 24小时
                'path' => '/',
                'domain' => '',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax'  // 改为Lax以支持跨页面导航
            ]);
            
            // 使用更安全的 session 配置
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.gc_maxlifetime', '86400');  // Session 数据保存24小时
            
            if ($isHttps) {
                ini_set('session.cookie_secure', '1');
            }
        }
    }
    
    /**
     * 安全启动 Session
     */
    public function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
            
            // 定期重新生成 session ID 防止会话固定攻击
            if (!isset($_SESSION['_created'])) {
                $_SESSION['_created'] = time();
            } else if (time() - $_SESSION['_created'] > 1800) {
                // 每30分钟重新生成 session ID
                session_regenerate_id(true);
                $_SESSION['_created'] = time();
            }
        }
    }
    
    // ==================== 密码安全 ====================
    
    /**
     * 生成密码哈希
     */
    public function hashPassword(string $password): string {
        return password_hash($password, self::PASSWORD_ALGO, self::PASSWORD_OPTIONS);
    }
    
    /**
     * 验证密码
     */
    public function verifyPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }
    
    /**
     * 检查密码是否需要重新哈希（算法升级时）
     */
    public function needsRehash(string $hash): bool {
        return password_needs_rehash($hash, self::PASSWORD_ALGO, self::PASSWORD_OPTIONS);
    }
    
    /**
     * 检测是否为旧格式明文密码
     */
    public function isLegacyPassword(string $stored): bool {
        // 如果不是以 $2y$ 开头，说明是旧的明文密码
        return strpos($stored, '$2y$') !== 0 && strpos($stored, '$2a$') !== 0;
    }
    
    // ==================== CSRF 防护 ====================
    
    /**
     * 生成 CSRF Token
     */
    public function generateCsrfToken(): string {
        $this->startSession();
        
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_time'] = time();
        
        return $token;
    }
    
    /**
     * 获取当前 CSRF Token（如不存在则生成）
     */
    public function getCsrfToken(): string {
        $this->startSession();
        
        if (empty($_SESSION['csrf_token']) || $this->isCsrfTokenExpired()) {
            return $this->generateCsrfToken();
        }
        
        return $_SESSION['csrf_token'];
    }
    
    /**
     * 验证 CSRF Token
     */
    public function validateCsrfToken(?string $token): bool {
        $this->startSession();
        
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        
        if ($this->isCsrfTokenExpired()) {
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * 检查 CSRF Token 是否过期
     */
    private function isCsrfTokenExpired(): bool {
        if (empty($_SESSION['csrf_token_time'])) {
            return true;
        }
        return (time() - $_SESSION['csrf_token_time']) > self::CSRF_TOKEN_LIFETIME;
    }
    
    /**
     * 从请求中获取 CSRF Token
     */
    public function getCsrfTokenFromRequest(): ?string {
        // 优先从 Header 获取
        $headers = getallheaders();
        if (!empty($headers['X-CSRF-Token'])) {
            return $headers['X-CSRF-Token'];
        }
        if (!empty($headers['X-Csrf-Token'])) {
            return $headers['X-Csrf-Token'];
        }
        
        // 从 POST 数据获取
        if (!empty($_POST['_csrf_token'])) {
            return $_POST['_csrf_token'];
        }
        
        // 从 JSON body 获取
        $input = json_decode(file_get_contents('php://input'), true);
        if (!empty($input['_csrf_token'])) {
            return $input['_csrf_token'];
        }
        
        return null;
    }
    
    // ==================== IP 安全 ====================
    
    /**
     * 获取客户端真实 IP（防伪造）
     */
    public function getClientIp(): string {
        // 信任的代理 IP 列表（Cloudflare, 本地等）
        $trustedProxies = [
            '127.0.0.1',
            '::1',
            // Cloudflare IP ranges can be added here
        ];
        
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        
        // 如果是通过信任的代理访问
        if (in_array($remoteAddr, $trustedProxies) || $this->isCloudflareIp($remoteAddr)) {
            // Cloudflare
            if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                return $this->sanitizeIp($_SERVER['HTTP_CF_CONNECTING_IP']);
            }
            // X-Real-IP
            if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                return $this->sanitizeIp($_SERVER['HTTP_X_REAL_IP']);
            }
            // X-Forwarded-For（取第一个）
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                return $this->sanitizeIp(trim($ips[0]));
            }
        }
        
        return $this->sanitizeIp($remoteAddr);
    }
    
    /**
     * 清理和验证 IP 地址
     */
    private function sanitizeIp(string $ip): string {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        return '0.0.0.0';
    }
    
    /**
     * 检查是否为 Cloudflare IP
     */
    private function isCloudflareIp(string $ip): bool {
        // Cloudflare IPv4 ranges
        $cfRanges = [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22',
            '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
            '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22',
            '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        ];
        
        foreach ($cfRanges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 检查 IP 是否在指定范围内
     */
    private function ipInRange(string $ip, string $range): bool {
        list($subnet, $bits) = explode('/', $range);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $bits);
        $subnet &= $mask;
        return ($ip & $mask) == $subnet;
    }
    
    // ==================== 输入清理 ====================
    
    /**
     * 清理 XSS
     */
    public function cleanXss(string $input): string {
        return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * 清理 SQL 关键字（额外层，PDO 预处理是主要防护）
     */
    public function cleanSqlKeywords(string $input): string {
        $keywords = ['DROP', 'DELETE', 'UPDATE', 'INSERT', 'TRUNCATE', 'ALTER', '--', '/*'];
        foreach ($keywords as $kw) {
            $input = str_ireplace($kw, '', $input);
        }
        return $input;
    }
    
    // ==================== 登录失败锁定 ====================
    
    /**
     * 检查 IP 是否被锁定
     */
    public function isIpLocked(string $ip): bool {
        if (!$this->db) return false;
        
        try {
            $pdo = $this->db->getPdo();
            $stmt = $pdo->prepare(
                "SELECT locked_until FROM login_attempts 
                 WHERE ip = ? AND locked_until > NOW() 
                 LIMIT 1"
            );
            $stmt->execute([$ip]);
            $row = $stmt->fetch();
            
            return $row !== false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * 获取 IP 锁定剩余时间（秒）
     */
    public function getLockoutRemaining(string $ip): int {
        if (!$this->db) return 0;
        
        try {
            $pdo = $this->db->getPdo();
            $stmt = $pdo->prepare(
                "SELECT TIMESTAMPDIFF(SECOND, NOW(), locked_until) as remaining 
                 FROM login_attempts 
                 WHERE ip = ? AND locked_until > NOW() 
                 LIMIT 1"
            );
            $stmt->execute([$ip]);
            $row = $stmt->fetch();
            
            return $row ? max(0, (int)$row['remaining']) : 0;
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * 记录登录失败
     */
    public function recordLoginFailure(string $ip): array {
        if (!$this->db) {
            return ['locked' => false, 'attempts' => 0, 'remaining' => 0];
        }
        
        try {
            $pdo = $this->db->getPdo();
            
            // 清理过期的记录
            $pdo->exec("DELETE FROM login_attempts WHERE locked_until IS NOT NULL AND locked_until < NOW()");
            
            // 获取当前失败次数
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) as cnt FROM login_attempts 
                 WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
                 AND locked_until IS NULL"
            );
            $stmt->execute([$ip, self::ATTEMPT_WINDOW]);
            $count = (int)$stmt->fetchColumn();
            
            // 记录本次失败
            $stmt = $pdo->prepare(
                "INSERT INTO login_attempts (ip, created_at) VALUES (?, NOW())"
            );
            $stmt->execute([$ip]);
            
            $attempts = $count + 1;
            
            // 检查是否需要锁定
            if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
                $stmt = $pdo->prepare(
                    "UPDATE login_attempts SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND) 
                     WHERE ip = ? AND locked_until IS NULL"
                );
                $stmt->execute([self::LOCKOUT_DURATION, $ip]);
                
                // 记录安全日志
                if (class_exists('Logger')) {
                    Logger::logSecurityEvent('IP被锁定', [
                        'ip' => $ip,
                        'attempts' => $attempts,
                        'duration' => self::LOCKOUT_DURATION
                    ]);
                }
                
                return [
                    'locked' => true,
                    'attempts' => $attempts,
                    'remaining' => self::LOCKOUT_DURATION
                ];
            }
            
            return [
                'locked' => false,
                'attempts' => $attempts,
                'remaining' => 0,
                'max_attempts' => self::MAX_LOGIN_ATTEMPTS
            ];
        } catch (Exception $e) {
            return ['locked' => false, 'attempts' => 0, 'remaining' => 0];
        }
    }
    
    /**
     * 登录成功后清除失败记录
     */
    public function clearLoginFailures(string $ip): void {
        if (!$this->db) return;
        
        try {
            $pdo = $this->db->getPdo();
            $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip = ?");
            $stmt->execute([$ip]);
        } catch (Exception $e) {
            // 忽略错误
        }
    }
    
    // ==================== TOTP 双因素认证 ====================
    
    /**
     * 生成 TOTP 密钥
     */
    public function generateTotpSecret(): string {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 16; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }
    
    /**
     * 生成 TOTP 配置 URI（用于二维码）
     */
    public function getTotpUri(string $secret, string $account = 'admin'): string {
        $issuer = urlencode(self::TOTP_ISSUER);
        $account = urlencode($account);
        return "otpauth://totp/{$issuer}:{$account}?secret={$secret}&issuer={$issuer}&algorithm=" . 
               strtoupper(self::TOTP_ALGORITHM) . "&digits=" . self::TOTP_DIGITS . "&period=" . self::TOTP_PERIOD;
    }
    
    /**
     * 验证 TOTP 代码
     */
    public function verifyTotp(string $secret, string $code, int $window = 1): bool {
        $code = preg_replace('/\s+/', '', $code);
        if (strlen($code) !== self::TOTP_DIGITS) {
            return false;
        }
        
        $timestamp = time();
        
        // 检查当前时间窗口和前后窗口
        for ($i = -$window; $i <= $window; $i++) {
            $checkTime = $timestamp + ($i * self::TOTP_PERIOD);
            $expectedCode = $this->generateTotpCode($secret, $checkTime);
            if (hash_equals($expectedCode, $code)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 生成当前 TOTP 代码
     */
    public function generateTotpCode(string $secret, ?int $timestamp = null): string {
        $timestamp = $timestamp ?? time();
        $counter = floor($timestamp / self::TOTP_PERIOD);
        
        // Base32 解码
        $secretKey = $this->base32Decode($secret);
        
        // 计算 HMAC
        $counterBytes = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac(self::TOTP_ALGORITHM, $counterBytes, $secretKey, true);
        
        // 动态截断
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24) |
                  ((ord($hash[$offset + 1]) & 0xFF) << 16) |
                  ((ord($hash[$offset + 2]) & 0xFF) << 8) |
                  (ord($hash[$offset + 3]) & 0xFF);
        
        $otp = $binary % pow(10, self::TOTP_DIGITS);
        
        return str_pad((string)$otp, self::TOTP_DIGITS, '0', STR_PAD_LEFT);
    }
    
    /**
     * Base32 解码
     */
    private function base32Decode(string $input): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output = '';
        $v = 0;
        $vbits = 0;
        
        for ($i = 0, $j = strlen($input); $i < $j; $i++) {
            $v <<= 5;
            if ($input[$i] === '=') continue;
            $v += strpos($alphabet, strtoupper($input[$i]));
            $vbits += 5;
            
            if ($vbits >= 8) {
                $vbits -= 8;
                $output .= chr(($v >> $vbits) & 0xFF);
            }
        }
        
        return $output;
    }
    
    /**
     * 检查是否启用了 TOTP
     */
    public function isTotpEnabled(): bool {
        if (!$this->db) return false;
        
        $secret = $this->db->getConfig('totp_secret', '');
        $enabled = $this->db->getConfig('totp_enabled', false);
        
        return !empty($secret) && $enabled;
    }
    
    /**
     * 启用 TOTP
     */
    public function enableTotp(string $secret, string $code): bool {
        // 验证代码
        if (!$this->verifyTotp($secret, $code)) {
            return false;
        }
        
        if ($this->db) {
            $this->db->setConfig('totp_secret', $secret);
            $this->db->setConfig('totp_enabled', true);
            
            if (class_exists('Logger')) {
                Logger::logSecurityEvent('TOTP双因素认证已启用');
            }
        }
        
        return true;
    }
    
    /**
     * 禁用 TOTP
     */
    public function disableTotp(): void {
        if ($this->db) {
            $this->db->setConfig('totp_enabled', false);
            
            if (class_exists('Logger')) {
                Logger::logSecurityEvent('TOTP双因素认证已禁用');
            }
        }
    }
    
    /**
     * 获取 TOTP 密钥
     */
    public function getTotpSecret(): string {
        if (!$this->db) return '';
        return $this->db->getConfig('totp_secret', '');
    }
}
