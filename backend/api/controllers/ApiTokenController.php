<?php
/**
 * API Token 控制器
 * 管理对外开放的 API Token
 */

require_once __DIR__ . '/BaseController.php';

class ApiTokenController extends BaseController
{
    /**
     * 获取 Token 列表
     * GET /api/v2/api-tokens
     */
    public function list(): void
    {
        $this->requireLogin();
        
        $stmt = $this->pdo()->query("
            SELECT id, name, token, permissions, rate_limit, enabled, 
                   last_used_at, call_count, created_at, expires_at, note 
            FROM api_tokens ORDER BY id DESC
        ");
        $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($tokens as &$t) {
            $t['permissions'] = json_decode($t['permissions'], true) ?: [];
            // 隐藏 token 中间部分
            $t['token_display'] = substr($t['token'], 0, 8) . '****' . substr($t['token'], -8);
        }
        
        $this->success($tokens);
    }
    
    /**
     * 创建 Token
     * POST /api/v2/api-tokens
     */
    public function create(): void
    {
        $this->requireLogin();
        
        $name = trim($this->requiredParam('name', 'Token名称不能为空'));
        
        // 生成 64 位随机 Token
        $token = bin2hex(random_bytes(32));
        $permissions = json_encode($this->param('permissions', ['shortlink_create', 'shortlink_stats']));
        $rateLimit = (int)$this->param('rate_limit', 100);
        $expiresAt = $this->param('expires_at') ?: null;
        $note = $this->param('note', '');
        
        $stmt = $this->pdo()->prepare("
            INSERT INTO api_tokens (name, token, permissions, rate_limit, expires_at, note) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $token, $permissions, $rateLimit, $expiresAt, $note]);
        
        $id = $this->pdo()->lastInsertId();
        $this->audit('api_token_create', 'api_token', $id);
        
        $this->success([
            'id' => $id,
            'token' => $token  // 仅创建时返回完整 token
        ], 'Token创建成功');
    }
    
    /**
     * 更新 Token
     * PUT /api/v2/api-tokens/{id}
     */
    public function update(int $id): void
    {
        $this->requireLogin();
        
        if ($id <= 0) {
            $this->error('ID无效');
        }
        
        $updates = [];
        $params = [];
        
        if ($this->param('name') !== null) {
            $updates[] = 'name = ?';
            $params[] = $this->param('name');
        }
        if ($this->param('permissions') !== null) {
            $updates[] = 'permissions = ?';
            $params[] = json_encode($this->param('permissions'));
        }
        if ($this->param('rate_limit') !== null) {
            $updates[] = 'rate_limit = ?';
            $params[] = (int)$this->param('rate_limit');
        }
        if ($this->param('enabled') !== null) {
            $updates[] = 'enabled = ?';
            $params[] = (int)$this->param('enabled');
        }
        if (array_key_exists('expires_at', $this->input)) {
            $updates[] = 'expires_at = ?';
            $params[] = $this->param('expires_at') ?: null;
        }
        if ($this->param('note') !== null) {
            $updates[] = 'note = ?';
            $params[] = $this->param('note');
        }
        
        if (empty($updates)) {
            $this->error('没有要更新的字段');
        }
        
        $params[] = $id;
        $sql = "UPDATE api_tokens SET " . implode(', ', $updates) . " WHERE id = ?";
        $this->pdo()->prepare($sql)->execute($params);
        
        $this->audit('api_token_update', 'api_token', $id);
        $this->success(null, '更新成功');
    }
    
    /**
     * 删除 Token
     * DELETE /api/v2/api-tokens/{id}
     */
    public function delete(int $id): void
    {
        $this->requireLogin();
        
        if ($id <= 0) {
            $this->error('ID无效');
        }
        
        $pdo = $this->pdo();
        $pdo->prepare("DELETE FROM api_tokens WHERE id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM api_logs WHERE token_id = ?")->execute([$id]);
        
        $this->audit('api_token_delete', 'api_token', $id);
        $this->success(null, '删除成功');
    }
    
    /**
     * 重新生成 Token
     * POST /api/v2/api-tokens/{id}/regenerate
     */
    public function regenerate(int $id): void
    {
        $this->requireLogin();
        
        if ($id <= 0) {
            $this->error('ID无效');
        }
        
        $newToken = bin2hex(random_bytes(32));
        $this->pdo()->prepare("UPDATE api_tokens SET token = ? WHERE id = ?")
            ->execute([$newToken, $id]);
        
        $this->audit('api_token_regenerate', 'api_token', $id);
        $this->success(['token' => $newToken], 'Token已重新生成');
    }
    
    /**
     * 获取 API 调用日志
     * GET /api/v2/api-tokens/logs
     */
    public function logs(): void
    {
        $this->requireLogin();
        
        $tokenId = (int)$this->param('token_id', 0);
        $limit = (int)$this->param('limit', 100);
        
        $sql = "SELECT l.*, t.name as token_name 
                FROM api_logs l 
                LEFT JOIN api_tokens t ON l.token_id = t.id";
        $params = [];
        
        if ($tokenId > 0) {
            $sql .= " WHERE l.token_id = ?";
            $params[] = $tokenId;
        }
        
        $sql .= " ORDER BY l.id DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($logs as &$log) {
            $log['request_data'] = json_decode($log['request_data'], true);
        }
        
        $this->success($logs);
    }
}
