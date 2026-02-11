<?php
/**
 * 外部 API 控制器
 * 处理通过 API Token 认证的外部请求
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../../core/jump.php';

class ExternalApiController extends BaseController
{
    private ?array $tokenData = null;
    
    /**
     * 验证 API Token
     */
    private function validateToken(string $requiredPermission): void
    {
        $apiToken = $_SERVER['HTTP_X_API_TOKEN'] ?? $this->param('api_token', '');
        
        if (empty($apiToken)) {
            $this->apiError('缺少API Token', 401);
        }
        
        $pdo = $this->pdo();
        $stmt = $pdo->prepare("
            SELECT id, permissions, rate_limit, enabled, expires_at 
            FROM api_tokens WHERE token = ?
        ");
        $stmt->execute([$apiToken]);
        $token = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$token) {
            $this->apiError('无效的API Token', 401);
        }
        
        if (!$token['enabled']) {
            $this->apiError('API Token已禁用', 401);
        }
        
        if ($token['expires_at'] && strtotime($token['expires_at']) < time()) {
            $this->apiError('API Token已过期', 401);
        }
        
        $permissions = json_decode($token['permissions'], true) ?: [];
        if (!in_array($requiredPermission, $permissions) && !in_array('*', $permissions)) {
            $this->apiError('权限不足', 403);
        }
        
        // 检查速率限制
        if (!$this->checkRateLimit($token['id'], $token['rate_limit'])) {
            $this->apiError('请求过于频繁，请稍后重试', 429);
        }
        
        // 更新最后使用时间和调用次数
        $pdo->prepare("UPDATE api_tokens SET last_used_at = NOW(), call_count = call_count + 1 WHERE id = ?")
            ->execute([$token['id']]);
        
        $this->tokenData = [
            'token_id' => $token['id'],
            'rate_limit' => $token['rate_limit']
        ];
    }
    
    /**
     * 检查速率限制
     */
    private function checkRateLimit(int $tokenId, int $limit): bool
    {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM api_logs 
            WHERE token_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        ");
        $stmt->execute([$tokenId]);
        return $stmt->fetchColumn() < $limit;
    }
    
    /**
     * 记录 API 调用
     */
    private function logApiCall(string $action, int $statusCode): void
    {
        if (!$this->tokenData) return;
        
        $pdo = $this->pdo();
        $stmt = $pdo->prepare("
            INSERT INTO api_logs (token_id, action, request_data, response_code, ip_address) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $this->tokenData['token_id'],
            $action,
            json_encode($this->input),
            $statusCode,
            $this->getClientIp()
        ]);
    }
    
    /**
     * 返回 API 错误
     */
    private function apiError(string $message, int $code = 400): void
    {
        http_response_code($code);
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }
    
    /**
     * 自动补全 URL
     */
    private function autoCompleteUrl(string $url): string
    {
        $url = trim($url);
        if (!empty($url) && !preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        return $url;
    }
    
    /**
     * 创建短链接
     * POST /api/v2/external/shortlinks
     */
    public function createShortlink(): void
    {
        $this->validateToken('shortlink_create');
        
        $targetUrl = $this->param('url') ?? $this->param('target_url', '');
        if (empty($targetUrl)) {
            $this->logApiCall('shortlink_create', 400);
            $this->apiError('目标URL不能为空');
        }
        
        $jump = new JumpService($this->pdo());
        $options = [
            'title' => $this->param('title', ''),
            'note' => $this->param('note', ''),
            'domain_id' => $this->param('domain_id'),
            'expire_type' => $this->param('expire_type', 'permanent'),
            'expire_at' => $this->param('expire_at'),
            'max_clicks' => $this->param('max_clicks')
        ];
        
        $result = $jump->create('code', '', $this->autoCompleteUrl($targetUrl), $options);
        
        $this->logApiCall('shortlink_create', $result['success'] ? 200 : 400);
        
        if ($result['success']) {
            $data = $result['data'];
            $this->success([
                'id' => $data['id'],
                'code' => $data['match_key'],
                'short_url' => $data['jump_url'],
                'target_url' => $targetUrl
            ]);
        } else {
            $this->apiError($result['message'] ?? '创建失败');
        }
    }
    
    /**
     * 获取短链接信息
     * GET /api/v2/external/shortlinks/{code}
     */
    public function getShortlink(string $code = ''): void
    {
        $this->validateToken('shortlink_stats');
        
        $id = (int)$this->param('id', 0);
        
        if (empty($code) && $id <= 0) {
            $this->apiError('请提供code或id');
        }
        
        $jump = new JumpService($this->pdo());
        
        if (!empty($code)) {
            $rule = $jump->getByKey('code', $code);
        } else {
            $rule = $jump->getById($id);
        }
        
        $this->logApiCall('shortlink_get', $rule ? 200 : 404);
        
        if ($rule) {
            $this->success([
                'id' => $rule['id'],
                'code' => $rule['match_key'],
                'target_url' => $rule['target_url'],
                'title' => $rule['title'],
                'total_clicks' => (int)($rule['total_clicks'] ?? 0),
                'unique_visitors' => (int)($rule['unique_visitors'] ?? 0),
                'enabled' => (bool)$rule['enabled'],
                'created_at' => $rule['created_at']
            ]);
        } else {
            http_response_code(404);
            $this->apiError('短链接不存在', 404);
        }
    }
    
    /**
     * 获取短链接列表
     * GET /api/v2/external/shortlinks
     */
    public function listShortlinks(): void
    {
        $this->validateToken('shortlink_stats');
        
        $page = max(1, (int)$this->param('page', 1));
        $limit = min(100, max(1, (int)$this->param('limit', 20)));
        $offset = ($page - 1) * $limit;
        
        $pdo = $this->pdo();
        $stmt = $pdo->query("SELECT COUNT(*) FROM jump_rules WHERE rule_type = 'code'");
        $total = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("
            SELECT id, match_key as code, target_url, title, total_clicks, unique_visitors, enabled, created_at 
            FROM jump_rules WHERE rule_type = 'code' 
            ORDER BY id DESC LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->logApiCall('shortlink_list', 200);
        
        $this->success([
            'items' => $rules,
            'total' => (int)$total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ]);
    }
    
    /**
     * 删除短链接
     * DELETE /api/v2/external/shortlinks/{code}
     */
    public function deleteShortlink(string $code = ''): void
    {
        $this->validateToken('shortlink_delete');
        
        $id = (int)$this->param('id', 0);
        
        if ($id <= 0 && empty($code)) {
            $this->apiError('请提供id或code');
        }
        
        $jump = new JumpService($this->pdo());
        
        if (!empty($code)) {
            $rule = $jump->getByKey('code', $code);
            if ($rule) $id = $rule['id'];
        }
        
        if ($id > 0) {
            $result = $jump->delete($id);
            $this->logApiCall('shortlink_delete', $result['success'] ? 200 : 400);
            
            if ($result['success']) {
                $this->success(null, '删除成功');
            } else {
                $this->apiError($result['message'] ?? '删除失败');
            }
        } else {
            $this->logApiCall('shortlink_delete', 404);
            $this->apiError('短链接不存在', 404);
        }
    }
    
    /**
     * 获取域名列表
     * GET /api/v2/external/domains
     */
    public function listDomains(): void
    {
        $this->validateToken('shortlink_create');
        
        $stmt = $this->pdo()->query("
            SELECT id, domain, name, is_default, enabled 
            FROM jump_domains WHERE enabled = 1 
            ORDER BY is_default DESC, id ASC
        ");
        $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($domains as &$d) {
            $d['is_default'] = (bool)$d['is_default'];
            $d['enabled'] = (bool)$d['enabled'];
        }
        
        $this->logApiCall('list_domains', 200);
        $this->success($domains);
    }
    
    /**
     * 获取短链接统计
     * GET /api/v2/external/shortlinks/{code}/stats
     */
    public function getStats(string $code = ''): void
    {
        $this->validateToken('shortlink_stats');
        
        $id = (int)$this->param('id', 0);
        
        if (empty($code) && $id <= 0) {
            $this->apiError('请提供code或id');
        }
        
        $jump = new JumpService($this->pdo());
        
        if (!empty($code)) {
            $rule = $jump->getByKey('code', $code);
        } else {
            $rule = $jump->getById($id);
        }
        
        if (!$rule) {
            $this->logApiCall('get_stats', 404);
            $this->apiError('短链接不存在', 404);
        }
        
        // 获取最近7天的点击趋势
        $dailyStats = [];
        try {
            $stmt = $this->pdo()->prepare("
                SELECT DATE(created_at) as date, COUNT(*) as clicks 
                FROM click_logs 
                WHERE rule_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY DATE(created_at)
                ORDER BY date ASC
            ");
            $stmt->execute([$rule['id']]);
            $dailyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // click_logs 表可能不存在
        }
        
        $this->logApiCall('get_stats', 200);
        
        $this->success([
            'id' => $rule['id'],
            'code' => $rule['match_key'],
            'target_url' => $rule['target_url'],
            'title' => $rule['title'] ?? '',
            'total_clicks' => (int)($rule['total_clicks'] ?? 0),
            'unique_visitors' => (int)($rule['unique_visitors'] ?? 0),
            'enabled' => (bool)$rule['enabled'],
            'created_at' => $rule['created_at'],
            'daily_stats' => $dailyStats
        ]);
    }
    
    /**
     * 批量获取短链接统计
     * POST /api/v2/external/shortlinks/batch-stats
     */
    public function batchStats(): void
    {
        $this->validateToken('shortlink_stats');
        
        $codes = $this->param('codes', []);
        $ids = $this->param('ids', []);
        
        if (empty($codes) && empty($ids)) {
            $this->apiError('请提供codes或ids数组');
        }
        
        $results = [];
        $pdo = $this->pdo();
        
        if (!empty($codes)) {
            $placeholders = implode(',', array_fill(0, count($codes), '?'));
            $stmt = $pdo->prepare("
                SELECT id, match_key as code, target_url, title, total_clicks, unique_visitors, enabled, created_at 
                FROM jump_rules WHERE match_key IN ($placeholders)
            ");
            $stmt->execute($codes);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("
                SELECT id, match_key as code, target_url, title, total_clicks, unique_visitors, enabled, created_at 
                FROM jump_rules WHERE id IN ($placeholders)
            ");
            $stmt->execute($ids);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        foreach ($results as &$r) {
            $r['total_clicks'] = (int)$r['total_clicks'];
            $r['unique_visitors'] = (int)$r['unique_visitors'];
            $r['enabled'] = (bool)$r['enabled'];
        }
        
        $this->logApiCall('batch_stats', 200);
        
        $this->success([
            'items' => $results,
            'count' => count($results)
        ]);
    }
}
