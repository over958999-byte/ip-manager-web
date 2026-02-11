<?php
/**
 * 安全增强模块
 * 包含：XSS防护、HMAC签名验证、敏感数据加密、配置加密
 */

require_once __DIR__ . '/utils.php';

class SecurityEnhanced {
    private static ?SecurityEnhanced $instance = null;
    
    // 加密配置
    private string $encryptionKey;
    private string $cipher = 'aes-256-gcm';
    
    // HMAC配置
    private string $hmacSecret;
    private int $timestampTolerance = 300; // 时间戳容差（秒）
    
    private function __construct() {
        $this->loadKeys();
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 加载加密密钥
     */
    private function loadKeys(): void {
        // 从环境变量或配置文件加载
        $this->encryptionKey = getenv('ENCRYPTION_KEY') ?: 'default-encryption-key-change-in-production';
        $this->hmacSecret = getenv('HMAC_SECRET') ?: getenv('JWT_SECRET') ?: 'default-hmac-secret';
    }
    
    // ==================== XSS 防护 ====================
    
    /**
     * HTML编码防XSS
     */
    public function escapeHtml(?string $input): string {
        if ($input === null) {
            return '';
        }
        return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    
    /**
     * 批量HTML编码
     */
    public function escapeArray(array $data): array {
        return array_map(function($value) {
            if (is_string($value)) {
                return $this->escapeHtml($value);
            } elseif (is_array($value)) {
                return $this->escapeArray($value);
            }
            return $value;
        }, $data);
    }
    
    /**
     * JavaScript编码
     */
    public function escapeJs(?string $input): string {
        if ($input === null) {
            return '';
        }
        return json_encode($input, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    }
    
    /**
     * URL编码
     */
    public function escapeUrl(?string $input): string {
        if ($input === null) {
            return '';
        }
        return rawurlencode($input);
    }
    
    /**
     * 清理HTML（允许部分标签）
     */
    public function sanitizeHtml(string $input, array $allowedTags = []): string {
        if (empty($allowedTags)) {
            $allowedTags = ['b', 'i', 'u', 'strong', 'em', 'a', 'br', 'p'];
        }
        
        $tagString = '<' . implode('><', $allowedTags) . '>';
        return strip_tags($input, $tagString);
    }
    
    // ==================== HMAC 签名 ====================
    
    /**
     * 生成HMAC签名
     */
    public function generateSignature(array $params, ?int $timestamp = null): string {
        if ($timestamp === null) {
            $timestamp = time();
        }
        
        // 移除已有签名
        unset($params['sign'], $params['signature'], $params['timestamp']);
        
        // 按键排序
        ksort($params);
        
        // 构建签名字符串
        $signString = '';
        foreach ($params as $key => $value) {
            if ($value !== '' && $value !== null) {
                $signString .= $key . '=' . (is_array($value) ? json_encode($value) : $value) . '&';
            }
        }
        $signString .= 'timestamp=' . $timestamp;
        
        return hash_hmac('sha256', $signString, $this->hmacSecret);
    }
    
    /**
     * 验证HMAC签名
     */
    public function verifySignature(array $params, string $signature, ?int $timestamp = null): bool {
        // 检查时间戳
        if ($timestamp !== null) {
            if (abs(time() - $timestamp) > $this->timestampTolerance) {
                return false;
            }
        }
        
        $expectedSignature = $this->generateSignature($params, $timestamp);
        return hash_equals($expectedSignature, $signature);
    }
    
    /**
     * 签名请求数据
     */
    public function signRequest(array $data): array {
        $timestamp = time();
        $signature = $this->generateSignature($data, $timestamp);
        
        return array_merge($data, [
            'timestamp' => $timestamp,
            'signature' => $signature
        ]);
    }
    
    // ==================== 敏感数据加密 ====================
    
    /**
     * 加密数据
     */
    public function encrypt(string $plaintext): ?string {
        $key = hash('sha256', $this->encryptionKey, true);
        $iv = random_bytes(openssl_cipher_iv_length($this->cipher));
        
        $ciphertext = openssl_encrypt($plaintext, $this->cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);
        
        if ($ciphertext === false) {
            return null;
        }
        
        // 格式：base64(iv + tag + ciphertext)
        return base64_encode($iv . $tag . $ciphertext);
    }
    
    /**
     * 解密数据
     */
    public function decrypt(string $encrypted): ?string {
        $key = hash('sha256', $this->encryptionKey, true);
        $data = base64_decode($encrypted);
        
        if ($data === false) {
            return null;
        }
        
        $ivLength = openssl_cipher_iv_length($this->cipher);
        $tagLength = 16; // GCM tag length
        
        if (strlen($data) < $ivLength + $tagLength) {
            return null;
        }
        
        $iv = substr($data, 0, $ivLength);
        $tag = substr($data, $ivLength, $tagLength);
        $ciphertext = substr($data, $ivLength + $tagLength);
        
        $plaintext = openssl_decrypt($ciphertext, $this->cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);
        
        return $plaintext !== false ? $plaintext : null;
    }
    
    /**
     * 加密配置值
     */
    public function encryptConfig(array $config, array $sensitiveKeys = []): array {
        if (empty($sensitiveKeys)) {
            $sensitiveKeys = ['password', 'secret', 'api_key', 'token', 'private_key'];
        }
        
        $result = [];
        foreach ($config as $key => $value) {
            $shouldEncrypt = false;
            foreach ($sensitiveKeys as $sensitive) {
                if (stripos($key, $sensitive) !== false) {
                    $shouldEncrypt = true;
                    break;
                }
            }
            
            if ($shouldEncrypt && is_string($value)) {
                $result[$key] = 'ENC:' . $this->encrypt($value);
            } elseif (is_array($value)) {
                $result[$key] = $this->encryptConfig($value, $sensitiveKeys);
            } else {
                $result[$key] = $value;
            }
        }
        
        return $result;
    }
    
    /**
     * 解密配置值
     */
    public function decryptConfig(array $config): array {
        $result = [];
        foreach ($config as $key => $value) {
            if (is_string($value) && strpos($value, 'ENC:') === 0) {
                $result[$key] = $this->decrypt(substr($value, 4));
            } elseif (is_array($value)) {
                $result[$key] = $this->decryptConfig($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
    
    // ==================== 日志脱敏 ====================
    
    /**
     * 脱敏IP地址
     */
    public function maskIp(string $ip): string {
        return Utils::maskIp($ip);
    }
    
    /**
     * 脱敏邮箱
     */
    public function maskEmail(string $email): string {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return '***@***';
        }
        
        $name = $parts[0];
        $domain = $parts[1];
        
        $maskedName = strlen($name) > 2 
            ? substr($name, 0, 1) . str_repeat('*', strlen($name) - 2) . substr($name, -1)
            : '**';
            
        return $maskedName . '@' . $domain;
    }
    
    /**
     * 脱敏手机号
     */
    public function maskPhone(string $phone): string {
        if (strlen($phone) < 7) {
            return str_repeat('*', strlen($phone));
        }
        return substr($phone, 0, 3) . '****' . substr($phone, -4);
    }
    
    /**
     * 脱敏通用敏感数据
     */
    public function maskSensitive(string $value, int $keepStart = 3, int $keepEnd = 3): string {
        return Utils::maskSensitive($value, $keepStart, $keepEnd);
    }
    
    /**
     * 脱敏日志数据
     */
    public function maskLogData(array $data, array $sensitiveFields = []): array {
        if (empty($sensitiveFields)) {
            $sensitiveFields = [
                'password', 'passwd', 'pwd',
                'token', 'api_key', 'apikey', 'secret',
                'credit_card', 'card_number',
                'ssn', 'id_card',
                'phone', 'mobile', 'tel',
                'email',
                'ip', 'client_ip', 'user_ip'
            ];
        }
        
        $result = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            
            if (is_array($value)) {
                $result[$key] = $this->maskLogData($value, $sensitiveFields);
            } elseif (is_string($value)) {
                $isSensitive = false;
                foreach ($sensitiveFields as $field) {
                    if (strpos($lowerKey, $field) !== false) {
                        $isSensitive = true;
                        break;
                    }
                }
                
                if ($isSensitive) {
                    // 根据字段类型选择脱敏方式
                    if (strpos($lowerKey, 'ip') !== false) {
                        $result[$key] = $this->maskIp($value);
                    } elseif (strpos($lowerKey, 'email') !== false) {
                        $result[$key] = $this->maskEmail($value);
                    } elseif (strpos($lowerKey, 'phone') !== false || strpos($lowerKey, 'mobile') !== false) {
                        $result[$key] = $this->maskPhone($value);
                    } else {
                        $result[$key] = $this->maskSensitive($value);
                    }
                } else {
                    $result[$key] = $value;
                }
            } else {
                $result[$key] = $value;
            }
        }
        
        return $result;
    }
    
    // ==================== CSP 策略 ====================
    
    /**
     * 获取CSP策略
     */
    public function getCspPolicy(array $options = []): string {
        $defaultPolicy = [
            "default-src" => "'self'",
            "script-src" => "'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src" => "'self' 'unsafe-inline'",
            "img-src" => "'self' data: https:",
            "font-src" => "'self' data:",
            "connect-src" => "'self'",
            "frame-ancestors" => "'self'",
            "base-uri" => "'self'",
            "form-action" => "'self'"
        ];
        
        $policy = array_merge($defaultPolicy, $options);
        
        $cspString = '';
        foreach ($policy as $directive => $value) {
            $cspString .= $directive . ' ' . $value . '; ';
        }
        
        return trim($cspString);
    }
    
    /**
     * 设置安全响应头
     */
    public function setSecurityHeaders(array $cspOptions = []): void {
        // XSS防护
        header('X-XSS-Protection: 1; mode=block');
        
        // 点击劫持防护
        header('X-Frame-Options: SAMEORIGIN');
        
        // 内容类型嗅探防护
        header('X-Content-Type-Options: nosniff');
        
        // CSP策略
        header('Content-Security-Policy: ' . $this->getCspPolicy($cspOptions));
        
        // HTTPS强制
        if (Utils::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
        
        // Referrer策略
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // 权限策略
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    }
    
    // ==================== SQL注入检测 ====================
    
    /**
     * 检测SQL注入特征
     */
    public function detectSqlInjection(string $input): bool {
        $patterns = [
            '/(\%27)|(\')|(\-\-)|(\%23)|(#)/i',                    // 常见注入字符
            '/((\%3D)|(=))[^\n]*((\%27)|(\')|(\-\-)|(\%3B)|(;))/i', // = 后跟注入
            '/\w*((\%27)|(\'))((\%6F)|o|(\%4F))((\%72)|r|(\%52))/i', // OR注入
            '/((\%27)|(\'))union/i',                                 // UNION注入
            '/exec(\s|\+)+(s|x)p\w+/i',                             // 存储过程
            '/UNION\s+SELECT/i',                                     // UNION SELECT
            '/SELECT.*FROM/i',                                       // SELECT FROM
            '/INSERT\s+INTO/i',                                      // INSERT
            '/DELETE\s+FROM/i',                                      // DELETE
            '/DROP\s+(TABLE|DATABASE)/i',                            // DROP
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 清理输入（移除潜在危险字符）
     */
    public function sanitizeInput(string $input): string {
        // 移除NULL字节
        $input = str_replace(chr(0), '', $input);
        
        // 移除不可见字符
        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input);
        
        return $input;
    }
}
