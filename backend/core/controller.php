<?php
/**
 * 控制器基类
 * 提供通用功能：请求处理、响应格式化、验证等
 */

require_once __DIR__ . '/utils.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/validator.php';

abstract class BaseController {
    protected ?Database $db = null;
    protected ?Security $security = null;
    protected array $request = [];
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->security = Security::getInstance();
        $this->parseRequest();
    }
    
    /**
     * 解析请求数据
     */
    protected function parseRequest(): void {
        // GET 参数
        $this->request = $_GET;
        
        // POST 参数
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            
            if (strpos($contentType, 'application/json') !== false) {
                $json = file_get_contents('php://input');
                $data = Utils::jsonDecode($json, []);
                $this->request = array_merge($this->request, $data);
            } else {
                $this->request = array_merge($this->request, $_POST);
            }
        }
    }
    
    /**
     * 获取请求参数
     */
    protected function input(string $key, $default = null) {
        return $this->request[$key] ?? $default;
    }
    
    /**
     * 获取所有请求参数
     */
    protected function all(): array {
        return $this->request;
    }
    
    /**
     * 获取指定的请求参数
     */
    protected function only(array $keys): array {
        return array_intersect_key($this->request, array_flip($keys));
    }
    
    /**
     * 验证请求参数
     */
    protected function validate(array $rules): array {
        $validator = new Validator($this->request, $rules);
        
        if (!$validator->validate()) {
            $this->error($validator->getFirstError(), 422);
            exit;
        }
        
        return $validator->getValidated();
    }
    
    /**
     * 成功响应
     */
    protected function success($data = null, string $message = '操作成功'): array {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data
        ];
    }
    
    /**
     * 错误响应
     */
    protected function error(string $message, int $code = 400, $data = null): void {
        Utils::error($message, $code, $data);
        exit;
    }
    
    /**
     * 分页响应
     */
    protected function paginate(array $items, int $total, int $page, int $perPage): array {
        return [
            'success' => true,
            'data' => $items,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($total / $perPage)
            ]
        ];
    }
    
    /**
     * 检查登录状态
     */
    protected function requireLogin(): void {
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            $this->error('请先登录', 401);
        }
    }
    
    /**
     * 检查权限
     */
    protected function requirePermission(string $permission): void {
        $this->requireLogin();
        
        $userRole = $_SESSION['role'] ?? 'viewer';
        $permissions = $this->getRolePermissions($userRole);
        
        if (!in_array($permission, $permissions) && !in_array('*', $permissions)) {
            $this->error('权限不足', 403);
        }
    }
    
    /**
     * 获取角色权限
     */
    protected function getRolePermissions(string $role): array {
        $rolePermissions = [
            'admin' => ['*'],
            'operator' => ['rules.read', 'rules.write', 'domains.read', 'domains.write', 'stats.read'],
            'viewer' => ['rules.read', 'domains.read', 'stats.read']
        ];
        
        return $rolePermissions[$role] ?? [];
    }
    
    /**
     * 获取当前用户ID
     */
    protected function userId(): ?int {
        return $_SESSION['user_id'] ?? null;
    }
    
    /**
     * 获取当前用户名
     */
    protected function username(): ?string {
        return $_SESSION['username'] ?? null;
    }
    
    /**
     * 记录审计日志
     */
    protected function audit(string $action, string $target = '', array $details = []): void {
        if (class_exists('AuditLog')) {
            AuditLog::log($action, $target, $details, $this->username());
        }
    }
    
    /**
     * 获取客户端IP
     */
    protected function clientIp(): string {
        return Utils::getClientIp();
    }
}
