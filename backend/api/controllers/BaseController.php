<?php
/**
 * 控制器基类
 * 提供通用方法：响应、验证、权限检查等
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
    
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->security = Security::getInstance();
        $this->input = $this->getInput();
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
     * 成功响应
     */
    protected function success($data = null, string $message = '操作成功'): void
    {
        $response = ['success' => true, 'message' => $message];
        if ($data !== null) {
            $response['data'] = $data;
        }
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * 错误响应
     */
    protected function error(string $message, int $code = 400): void
    {
        echo json_encode([
            'success' => false,
            'message' => $message,
            'code' => $code
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
     * 记录审计日志
     */
    protected function audit(string $action, string $resourceType = null, $resourceId = null, array $details = []): void
    {
        try {
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
                json_encode($details['old'] ?? null),
                json_encode($details['new'] ?? null),
                $this->getClientIp(),
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (Exception $e) {
            Logger::logError('审计日志记录失败', ['error' => $e->getMessage()]);
        }
    }
}
