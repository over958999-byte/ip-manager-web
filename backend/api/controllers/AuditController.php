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
        $user = $this->param('user');
        $startDate = $this->param('start_date');
        $endDate = $this->param('end_date');
        
        $offset = ($page - 1) * $limit;
        
        $where = [];
        $params = [];
        
        if ($action) {
            $where[] = "action LIKE ?";
            $params[] = "%{$action}%";
        }
        
        if ($user) {
            $where[] = "user LIKE ?";
            $params[] = "%{$user}%";
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
        
        // 总数
        $total = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM audit_logs {$whereClause}",
            $params
        );
        
        // 日志列表
        $logs = $this->db->fetchAll(
            "SELECT * FROM audit_logs {$whereClause} ORDER BY created_at DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );
        
        $this->success([
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ]);
    }
    
    /**
     * 导出审计日志
     */
    public function export(): void
    {
        $this->requireLogin();
        
        $action = $this->param('action');
        $user = $this->param('user');
        $startDate = $this->param('start_date');
        $endDate = $this->param('end_date');
        $format = $this->param('format', 'csv');
        
        $where = [];
        $params = [];
        
        if ($action) {
            $where[] = "action LIKE ?";
            $params[] = "%{$action}%";
        }
        
        if ($user) {
            $where[] = "user LIKE ?";
            $params[] = "%{$user}%";
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
