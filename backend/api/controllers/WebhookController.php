<?php
/**
 * Webhook 控制器
 * 管理 Webhook 配置和测试
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../../core/webhook.php';

class WebhookController extends BaseController
{
    /**
     * 获取 Webhook 列表
     */
    public function list(): void
    {
        $this->requireLogin();
        
        $webhooks = $this->db->fetchAll(
            "SELECT * FROM webhooks ORDER BY created_at DESC"
        );
        
        $this->success($webhooks);
    }
    
    /**
     * 创建 Webhook
     */
    public function create(): void
    {
        $this->requireLogin();
        
        $name = $this->requiredParam('name', 'Webhook 名称不能为空');
        $url = $this->requiredParam('url', 'Webhook URL 不能为空');
        $events = $this->param('events', []);
        $secret = $this->param('secret', '');
        $enabled = $this->param('enabled', 1);
        
        // 验证 URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->error('无效的 URL 格式');
        }
        
        $id = $this->db->insert('webhooks', [
            'name' => $name,
            'url' => $url,
            'events' => json_encode($events),
            'secret' => $secret,
            'enabled' => $enabled ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        $this->audit('webhook_create', 'webhook', $id, ['name' => $name]);
        $this->success(['id' => $id], 'Webhook 创建成功');
    }
    
    /**
     * 更新 Webhook
     */
    public function update(): void
    {
        $this->requireLogin();
        
        $id = $this->requiredParam('id', 'ID 不能为空');
        
        $data = [];
        if ($this->param('name') !== null) $data['name'] = $this->param('name');
        if ($this->param('url') !== null) {
            $url = $this->param('url');
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $this->error('无效的 URL 格式');
            }
            $data['url'] = $url;
        }
        if ($this->param('events') !== null) $data['events'] = json_encode($this->param('events'));
        if ($this->param('secret') !== null) $data['secret'] = $this->param('secret');
        if ($this->param('enabled') !== null) $data['enabled'] = $this->param('enabled') ? 1 : 0;
        
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        $this->db->update('webhooks', $data, ['id' => $id]);
        
        $this->audit('webhook_update', 'webhook', $id);
        $this->success(null, 'Webhook 更新成功');
    }
    
    /**
     * 删除 Webhook
     */
    public function delete(): void
    {
        $this->requireLogin();
        
        $id = $this->requiredParam('id', 'ID 不能为空');
        
        $this->db->delete('webhooks', ['id' => $id]);
        
        $this->audit('webhook_delete', 'webhook', $id);
        $this->success(null, 'Webhook 删除成功');
    }
    
    /**
     * 测试 Webhook
     */
    public function test(): void
    {
        $this->requireLogin();
        
        $id = $this->requiredParam('id', 'ID 不能为空');
        
        $webhook = $this->db->fetch(
            "SELECT * FROM webhooks WHERE id = ?",
            [$id]
        );
        
        if (!$webhook) {
            $this->error('Webhook 不存在');
        }
        
        // 发送测试请求
        $testPayload = [
            'event' => 'test',
            'timestamp' => time(),
            'data' => ['message' => '这是一条测试消息']
        ];
        
        $result = $this->sendWebhookRequest($webhook['url'], $testPayload, $webhook['secret'] ?? '');
        
        $this->audit('webhook_test', 'webhook', $id, ['success' => $result['success']]);
        
        if ($result['success']) {
            $this->success(['response' => $result['response']], 'Webhook 测试成功');
        } else {
            $this->error('Webhook 测试失败: ' . $result['error']);
        }
    }
    
    /**
     * 发送 Webhook 请求
     */
    private function sendWebhookRequest(string $url, array $payload, string $secret = ''): array
    {
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        
        $headers = ['Content-Type: application/json'];
        if ($secret) {
            $signature = hash_hmac('sha256', $jsonPayload, $secret);
            $headers[] = 'X-Webhook-Signature: ' . $signature;
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => $error, 'response' => null];
        }
        
        $success = $httpCode >= 200 && $httpCode < 300;
        return [
            'success' => $success,
            'response' => $response,
            'http_code' => $httpCode,
            'error' => $success ? null : "HTTP {$httpCode}"
        ];
    }
    
    /**
     * 获取 Webhook 日志
     */
    public function logs(): void
    {
        $this->requireLogin();
        
        $webhookId = $this->param('webhook_id');
        $limit = min(100, (int)($this->param('limit') ?? 50));
        
        $sql = "SELECT * FROM webhook_logs";
        $params = [];
        
        if ($webhookId) {
            $sql .= " WHERE webhook_id = ?";
            $params[] = $webhookId;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $logs = $this->db->fetchAll($sql, $params);
        
        $this->success($logs);
    }
}
