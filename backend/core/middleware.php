<?php
/**
 * 认证中间件
 * 处理登录验证、权限检查、API Token验证
 */

require_once __DIR__ . '/utils.php';

class AuthMiddleware {
    /**
     * 处理请求
     */
    public function handle(callable $next) {
        // 检查登录状态
        if (!$this->isAuthenticated()) {
            Utils::error('请先登录', 401);
            return null;
        }
        
        return $next();
    }
    
    /**
     * 检查是否已认证
     */
    protected function isAuthenticated(): bool {
        // Session 登录
        if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
            return true;
        }
        
        // API Token
        $token = $this->getApiToken();
        if ($token && $this->validateApiToken($token)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取 API Token
     */
    protected function getApiToken(): ?string {
        // Bearer Token
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }
        
        // Query/Post 参数
        return $_GET['api_token'] ?? $_POST['api_token'] ?? null;
    }
    
    /**
     * 验证 API Token
     */
    protected function validateApiToken(string $token): bool {
        try {
            $db = Database::getInstance();
            $pdo = $db->getPdo();
            
            $stmt = $pdo->prepare("SELECT * FROM api_tokens WHERE token = ? AND enabled = 1");
            $stmt->execute([$token]);
            $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$tokenData) {
                return false;
            }
            
            // 检查过期
            if ($tokenData['expires_at'] && strtotime($tokenData['expires_at']) < time()) {
                return false;
            }
            
            // 更新最后使用时间
            $stmt = $pdo->prepare("UPDATE api_tokens SET last_used_at = NOW(), call_count = call_count + 1 WHERE id = ?");
            $stmt->execute([$tokenData['id']]);
            
            // 设置Session
            $_SESSION['api_token'] = true;
            $_SESSION['api_token_id'] = $tokenData['id'];
            $_SESSION['api_permissions'] = json_decode($tokenData['permissions'], true) ?: [];
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

/**
 * 权限中间件
 */
class PermissionMiddleware {
    private string $permission;
    
    public function __construct(string $permission) {
        $this->permission = $permission;
    }
    
    public function handle(callable $next) {
        $userRole = $_SESSION['role'] ?? 'viewer';
        
        $rolePermissions = [
            'admin' => ['*'],
            'operator' => ['rules.read', 'rules.write', 'domains.read', 'domains.write', 'stats.read'],
            'viewer' => ['rules.read', 'domains.read', 'stats.read']
        ];
        
        $permissions = $rolePermissions[$userRole] ?? [];
        
        if (!in_array($this->permission, $permissions) && !in_array('*', $permissions)) {
            Utils::error('权限不足', 403);
            return null;
        }
        
        return $next();
    }
}

/**
 * 限流中间件
 */
class RateLimitMiddleware {
    private int $maxRequests;
    private int $window;
    
    public function __construct(int $maxRequests = 60, int $window = 60) {
        $this->maxRequests = $maxRequests;
        $this->window = $window;
    }
    
    public function handle(callable $next) {
        $ip = Utils::getClientIp();
        $key = 'ratelimit:' . md5($ip);
        
        // 简单的内存限流（生产环境应使用Redis）
        $cacheFile = sys_get_temp_dir() . '/ratelimit_' . md5($key) . '.json';
        
        $data = ['count' => 0, 'reset' => time() + $this->window];
        
        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true) ?: $data;
        }
        
        // 重置窗口
        if (time() > $data['reset']) {
            $data = ['count' => 0, 'reset' => time() + $this->window];
        }
        
        $data['count']++;
        
        // 保存
        file_put_contents($cacheFile, json_encode($data));
        
        // 设置限流头
        header('X-RateLimit-Limit: ' . $this->maxRequests);
        header('X-RateLimit-Remaining: ' . max(0, $this->maxRequests - $data['count']));
        header('X-RateLimit-Reset: ' . $data['reset']);
        
        if ($data['count'] > $this->maxRequests) {
            http_response_code(429);
            Utils::error('请求过于频繁，请稍后再试', 429);
            return null;
        }
        
        return $next();
    }
}

/**
 * CORS 中间件
 */
class CorsMiddleware {
    private array $allowedOrigins;
    
    public function __construct(array $allowedOrigins = []) {
        $this->allowedOrigins = $allowedOrigins ?: [
            'http://localhost:3000',
            'http://127.0.0.1:3000',
            'http://localhost:5173',
            'http://127.0.0.1:5173'
        ];
    }
    
    public function handle(callable $next) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        if (in_array($origin, $this->allowedOrigins) || in_array('*', $this->allowedOrigins)) {
            header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
            header('Access-Control-Max-Age: 86400');
        }
        
        return $next();
    }
}

/**
 * 安全头中间件
 */
class SecurityHeadersMiddleware {
    public function handle(callable $next) {
        Utils::setSecurityHeaders();
        return $next();
    }
}

/**
 * 日志中间件
 */
class LogMiddleware {
    public function handle(callable $next) {
        $startTime = microtime(true);
        
        // 执行请求
        $response = $next();
        
        // 记录请求日志
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $ip = Utils::maskIp(Utils::getClientIp()); // 脱敏IP
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        
        if (class_exists('Logger')) {
            Logger::logAccess([
                'ip' => $ip,
                'method' => $method,
                'uri' => $uri,
                'duration_ms' => $duration,
                'status' => http_response_code()
            ]);
        }
        
        return $response;
    }
}
