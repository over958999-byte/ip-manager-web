<?php
/**
 * 安全模块
 * 密码哈希、CSRF防护、Session安全
 */

class Security {
    private static $instance = null;
    
    // CSRF Token 有效期（秒）
    const CSRF_TOKEN_LIFETIME = 3600;
    
    // 密码哈希算法
    const PASSWORD_ALGO = PASSWORD_BCRYPT;
    const PASSWORD_OPTIONS = ['cost' => 12];
    
    private function __construct() {
        // 配置安全的 session
        $this->configureSession();
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
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
            
            // 使用更安全的 session 配置
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.cookie_httponly', '1');
            
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
}
