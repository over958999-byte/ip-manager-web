<?php
/**
 * 审计日志控制器
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../../core/audit.php';

class AuditController extends BaseController
{
    /**
     * 获取审计日志
     */
    public function logs(): void
    {
        $this->requireLogin();
        
        $page = max(1, (int)($this->param('page') ?? 1));
        $limit = min(100, (int)($this->param('limit') ?? 20));
        $action = $this->param('action');
        $username = $this->param('username') ?? $this->param('user');  // 支持两种参数名
        $resourceType = $this->param('resource_type');
        $ip = $this->param('ip');
        $startDate = $this->param('start_date');
        $endDate = $this->param('end_date');
        
        $offset = ($page - 1) * $limit;
        
        $where = [];
        $params = [];
        
        if ($action) {
            $where[] = "action LIKE ?";
            $params[] = "%{$action}%";
        }
        
        if ($username) {
            $where[] = "username LIKE ?";
            $params[] = "%{$username}%";
        }
        
        if ($resourceType) {
            $where[] = "resource_type = ?";
            $params[] = $resourceType;
        }
        
        if ($ip) {
            $where[] = "ip LIKE ?";
            $params[] = "%{$ip}%";
        }
        
        if ($startDate) {
            $where[] = "created_at >= ?";
            $params[] = $startDate;
        }
        
        if ($endDate) {
            $where[] = "created_at <= ?";
            $params[] = $endDate . ' 23:59:59';
        }
        
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        
        // 确保表存在
        $this->ensureTableExists();
        
        // 总数
        $total = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM audit_logs {$whereClause}",
            $params
        ) ?? 0;
        
        // 日志列表
        $logs = $this->db->fetchAll(
            "SELECT * FROM audit_logs {$whereClause} ORDER BY created_at DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        ) ?? [];
        
        $this->success([
            'list' => $logs,
            'total' => (int)$total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $total > 0 ? ceil($total / $limit) : 0
        ]);
    }
    
    /**
     * 确保审计日志表存在
     */
    private function ensureTableExists(): void
    {
        try {
            $this->pdo()->exec("
                CREATE TABLE IF NOT EXISTS audit_logs (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT,
                    username VARCHAR(50),
                    action VARCHAR(50) NOT NULL COMMENT '操作类型',
                    resource_type VARCHAR(50) COMMENT '资源类型',
                    resource_id VARCHAR(50) COMMENT '资源ID',
                    old_value JSON COMMENT '修改前',
                    new_value JSON COMMENT '修改后',
                    ip VARCHAR(45),
                    user_agent TEXT,
                    status ENUM('success', 'failure') DEFAULT 'success',
                    error_message TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user (user_id),
                    INDEX idx_action (action),
                    INDEX idx_resource (resource_type, resource_id),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Exception $e) {
            // 忽略，表可能已存在
        }
    }
    
    /**
     * 导出审计日志
     */
    public function export(): void
    {
        $this->requireLogin();
        
        $action = $this->param('action');
        $username = $this->param('username') ?? $this->param('user');
        $startDate = $this->param('start_date');
        $endDate = $this->param('end_date');
        $format = $this->param('format', 'csv');
        
        $where = [];
        $params = [];
        
        if ($action) {
            $where[] = "action LIKE ?";
            $params[] = "%{$action}%";
        }
        
        if ($username) {
            $where[] = "username LIKE ?";
            $params[] = "%{$username}%";
        }
        
        if ($startDate) {
            $where[] = "created_at >= ?";
            $params[] = $startDate;
        }
        
        if ($endDate) {
            $where[] = "created_at <= ?";
            $params[] = $endDate . ' 23:59:59';
        }
        
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $logs = $this->db->fetchAll(
            "SELECT * FROM audit_logs {$whereClause} ORDER BY created_at DESC LIMIT 10000",
            $params
        );
        
        if ($format === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="audit_logs_' . date('Y-m-d') . '.json"');
            echo json_encode($logs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="audit_logs_' . date('Y-m-d') . '.csv"');
            echo "\xEF\xBB\xBF"; // UTF-8 BOM
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['ID', '操作', '用户', 'IP', '详情', '时间']);
            
            foreach ($logs as $log) {
                fputcsv($output, [
                    $log['id'],
                    $log['action'],
                    $log['user'] ?? '',
                    $log['ip'] ?? '',
                    $log['details'] ?? '',
                    $log['created_at']
                ]);
            }
            
            fclose($output);
        }
        
        exit;
    }
}
