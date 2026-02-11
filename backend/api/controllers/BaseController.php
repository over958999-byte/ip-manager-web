<?php
declare(strict_types=1);
/**
 * 控制器基类
 * 提供通用方法：响应、验证、权限检查、CSRF验证等
 */

require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/security.php';
require_once __DIR__ . '/../../core/logger.php';
require_once __DIR__ . '/../../core/utils.php';

abstract class BaseController
{
    protected Database $db;
    protected Security $security;
    protected array $input = [];
    
    // CSRF 豁免的 action 列表（登录、获取 CSRF Token 等）
    protected array $csrfExempt = ['login', 'csrf', 'get_csrf_token', 'health', 'metrics'];
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->security = Security::getInstance();
        $this->input = $this->getInput();
    }
    
    /**
     * 验证 CSRF Token（POST/PUT/DELETE 请求强制验证）
     */
    protected function verifyCsrf(string $action = ''): void
    {
        // 检查是否豁免
        if (in_array($action, $this->csrfExempt, true)) {
            return;
        }
        
        // 仅对修改请求验证
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            return;
        }
        
        // API Token 认证的请求豁免 CSRF
        if (isset($_SESSION['api_token']) && $_SESSION['api_token'] === true) {
            return;
        }
        
        // 获取 CSRF Token
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] 
            ?? $this->param('csrf_token') 
            ?? $this->param('_token')
            ?? '';
        
        if (empty($csrfToken) || !$this->security->validateCsrfToken($csrfToken)) {
            Logger::logSecurityEvent('CSRF Token 验证失败', [
                'action' => $action,
                'ip' => $this->getClientIp()
            ]);
            $this->error('CSRF Token 无效，请刷新页面重试', 403);
        }
    }
    
    /**
     * 获取请求输入
     */
    protected function getInput(): array
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        return array_merge($_GET, $_POST, $input);
    }
    
    /**
     * 获取输入参数
     */
    protected function param(string $key, $default = null)
    {
        return $this->input[$key] ?? $_GET[$key] ?? $default;
    }
    
    /**
     * 获取必填参数
     */
    protected function requiredParam(string $key, string $message = null)
    {
        $value = $this->param($key);
        if ($value === null || $value === '') {
            $this->error($message ?? "{$key} 不能为空", 400);
        }
        return $value;
    }
    
    /**
     * 成功响应 - 标准化格式
     */
    protected function success($data = null, string $message = '操作成功'): void
    {
        $response = [
            'success' => true,
            'code' => 0,
            'message' => $message,
            'timestamp' => time()
        ];
        if ($data !== null) {
            $response['data'] = $data;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * 错误响应 - 标准化格式
     */
    protected function error(string $message, int $code = 400): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code >= 100 && $code < 600 ? $code : 400);
        echo json_encode([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'timestamp' => time()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * 检查登录状态
     */
    protected function requireLogin(): void
    {
        if (!$this->isLoggedIn()) {
            $this->error('请先登录', 401);
        }
    }
    
    /**
     * 是否已登录
     */
    protected function isLoggedIn(): bool
    {
        if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
            return true;
        }
        
        // 检查密钥参数
        $secretKey = $this->db->getConfig('admin_secret_key', '');
        $requestKey = $this->param('key', '');
        if (!empty($secretKey) && $secretKey !== 'your_secret_key_here' && $requestKey === $secretKey) {
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取客户端IP
     */
    protected function getClientIp(): string
    {
        return Utils::getClientIp();
    }
    
    /**
     * 获取 PDO 实例
     */
    protected function pdo(): PDO
    {
        return $this->db->getPdo();
    }
    
    /**
     * 分页参数
     */
    protected function pagination(int $defaultLimit = 20, int $maxLimit = 100): array
    {
        $page = max(1, intval($this->param('page', 1)));
        $limit = min($maxLimit, max(1, intval($this->param('limit', $defaultLimit))));
        $offset = ($page - 1) * $limit;
        
        return compact('page', 'limit', 'offset');
    }
    
    /**
     * 返回分页响应
     */
    protected function paginate(array $items, int $total, int $page, int $limit): void
    {
        $this->success([
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ]);
    }
    
    /**
     * 记录审计日志（自动脱敏敏感信息）
     */
    protected function audit(string $action, string $resourceType = null, $resourceId = null, array $details = []): void
    {
        try {
            // 脱敏处理
            $sanitizedDetails = $this->sanitizeLogData($details);
            
            $stmt = $this->pdo()->prepare("
                INSERT INTO audit_logs (user_id, username, action, resource_type, resource_id, old_value, new_value, ip, user_agent, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'success')
            ");
            $stmt->execute([
                $_SESSION['user_id'] ?? null,
                $_SESSION['username'] ?? 'system',
                $action,
                $resourceType,
                $resourceId,
                json_encode($sanitizedDetails['old'] ?? null),
                json_encode($sanitizedDetails['new'] ?? null),
                $this->getClientIp(),
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (Exception $e) {
            Logger::logError('审计日志记录失败', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * 敏感信息脱敏
     * @param array $data 原始数据
     * @return array 脱敏后的数据
     */
    protected function sanitizeLogData(array $data): array
    {
        // 需要脱敏的字段列表
        $sensitiveFields = [
            'password', 'password_hash', 'token', 'api_token', 'secret', 
            'secret_key', 'access_key', 'private_key', 'csrf_token',
            'totp_secret', 'remember_token', 'auth_token', 'jwt',
            'credit_card', 'card_number', 'cvv', 'ssn'
        ];
        
        return $this->maskSensitiveFields($data, $sensitiveFields);
    }
    
    /**
     * 递归遮蔽敏感字段
     */
    private function maskSensitiveFields(array $data, array $sensitiveFields): array
    {
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string)$key);
            
            // 检查是否是敏感字段
            foreach ($sensitiveFields as $field) {
                if (str_contains($lowerKey, $field)) {
                    $data[$key] = $this->maskValue($value);
                    break;
                }
            }
            
            // 递归处理数组
            if (is_array($value) && !isset($data[$key]) || (isset($data[$key]) && is_array($data[$key]))) {
                $data[$key] = $this->maskSensitiveFields($value, $sensitiveFields);
            }
        }
        
        return $data;
    }
    
    /**
     * 遮蔽敏感值
     */
    private function maskValue($value): string
    {
        if (empty($value)) {
            return '[EMPTY]';
        }
        
        $length = is_string($value) ? strlen($value) : 0;
        if ($length <= 4) {
            return '****';
        }
        
        // 保留前2位和后2位
        return substr($value, 0, 2) . str_repeat('*', min($length - 4, 8)) . substr($value, -2);
    }
    
    /**
     * 安全的常量时间字符串比较（防止时序攻击）
     */
    protected function secureCompare(string $known, string $user): bool
    {
        return hash_equals($known, $user);
    }
}
