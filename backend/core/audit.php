<?php
/**
 * 操作审计日志模块
 * 记录所有管理员操作
 */

class AuditLog {
    private static $instance = null;
    private $db;
    
    // 操作类型
    const ACTION_LOGIN = 'login';
    const ACTION_LOGOUT = 'logout';
    const ACTION_CREATE = 'create';
    const ACTION_UPDATE = 'update';
    const ACTION_DELETE = 'delete';
    const ACTION_EXPORT = 'export';
    const ACTION_IMPORT = 'import';
    const ACTION_CONFIG = 'config';
    const ACTION_SECURITY = 'security';
    
    // 资源类型
    const RESOURCE_USER = 'user';
    const RESOURCE_RULE = 'rule';
    const RESOURCE_DOMAIN = 'domain';
    const RESOURCE_IP_POOL = 'ip_pool';
    const RESOURCE_SHORTLINK = 'shortlink';
    const RESOURCE_CONFIG = 'config';
    const RESOURCE_API_TOKEN = 'api_token';
    const RESOURCE_ANTIBOT = 'antibot';
    
    private function __construct() {
        $this->db = Database::getInstance();
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 记录审计日志
     */
    public function log(
        string $action,
        string $resource,
        ?string $resourceId = null,
        ?array $details = null,
        ?string $userId = null
    ): bool {
        try {
            $pdo = $this->db->getPdo();
            
            $stmt = $pdo->prepare(
                "INSERT INTO audit_logs (
                    user_id, action, resource, resource_id, 
                    details, ip, user_agent, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            
            $stmt->execute([
                $userId ?? $this->getCurrentUserId(),
                $action,
                $resource,
                $resourceId,
                $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
                $this->getClientIp(),
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500)
            ]);
            
            return true;
        } catch (Exception $e) {
            if (class_exists('Logger')) {
                Logger::logError('审计日志记录失败', ['error' => $e->getMessage()]);
            }
            return false;
        }
    }
    
    /**
     * 快捷方法：记录登录
     */
    public function logLogin(bool $success, ?string $reason = null): bool {
        return $this->log(
            self::ACTION_LOGIN,
            self::RESOURCE_USER,
            null,
            ['success' => $success, 'reason' => $reason]
        );
    }
    
    /**
     * 快捷方法：记录登出
     */
    public function logLogout(): bool {
        return $this->log(self::ACTION_LOGOUT, self::RESOURCE_USER);
    }
    
    /**
     * 快捷方法：记录创建操作
     */
    public function logCreate(string $resource, string $resourceId, ?array $data = null): bool {
        return $this->log(self::ACTION_CREATE, $resource, $resourceId, $data);
    }
    
    /**
     * 快捷方法：记录更新操作
     */
    public function logUpdate(string $resource, string $resourceId, ?array $changes = null): bool {
        return $this->log(self::ACTION_UPDATE, $resource, $resourceId, $changes);
    }
    
    /**
     * 快捷方法：记录删除操作
     */
    public function logDelete(string $resource, string $resourceId, ?array $data = null): bool {
        return $this->log(self::ACTION_DELETE, $resource, $resourceId, $data);
    }
    
    /**
     * 快捷方法：记录配置变更
     */
    public function logConfig(string $key, $oldValue, $newValue): bool {
        return $this->log(
            self::ACTION_CONFIG,
            self::RESOURCE_CONFIG,
            $key,
            ['old' => $oldValue, 'new' => $newValue]
        );
    }
    
    /**
     * 快捷方法：记录安全事件
     */
    public function logSecurity(string $event, ?array $details = null): bool {
        return $this->log(self::ACTION_SECURITY, self::RESOURCE_USER, null, 
            array_merge(['event' => $event], $details ?? [])
        );
    }
    
    /**
     * 获取审计日志列表
     */
    public function getLogs(array $filters = [], int $page = 1, int $perPage = 50): array {
        try {
            $pdo = $this->db->getPdo();
            
            $where = ['1=1'];
            $params = [];
            
            if (!empty($filters['action'])) {
                $where[] = 'action = ?';
                $params[] = $filters['action'];
            }
            
            if (!empty($filters['resource'])) {
                $where[] = 'resource = ?';
                $params[] = $filters['resource'];
            }
            
            if (!empty($filters['user_id'])) {
                $where[] = 'user_id = ?';
                $params[] = $filters['user_id'];
            }
            
            if (!empty($filters['ip'])) {
                $where[] = 'ip = ?';
                $params[] = $filters['ip'];
            }
            
            if (!empty($filters['start_date'])) {
                $where[] = 'created_at >= ?';
                $params[] = $filters['start_date'];
            }
            
            if (!empty($filters['end_date'])) {
                $where[] = 'created_at <= ?';
                $params[] = $filters['end_date'] . ' 23:59:59';
            }
            
            if (!empty($filters['search'])) {
                $where[] = '(resource_id LIKE ? OR details LIKE ?)';
                $search = '%' . $filters['search'] . '%';
                $params[] = $search;
                $params[] = $search;
            }
            
            $whereStr = implode(' AND ', $where);
            
            // 获取总数
            $countSql = "SELECT COUNT(*) FROM audit_logs WHERE {$whereStr}";
            $stmt = $pdo->prepare($countSql);
            $stmt->execute($params);
            $total = (int)$stmt->fetchColumn();
            
            // 获取数据
            $offset = ($page - 1) * $perPage;
            $dataSql = "SELECT * FROM audit_logs WHERE {$whereStr} ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $stmt = $pdo->prepare($dataSql);
            $stmt->execute(array_merge($params, [$perPage, $offset]));
            
            $logs = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $row['details'] = $row['details'] ? json_decode($row['details'], true) : null;
                $logs[] = $row;
            }
            
            return [
                'data' => $logs,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($total / $perPage)
            ];
        } catch (Exception $e) {
            return ['data' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 0];
        }
    }
    
    /**
     * 获取审计统计
     */
    public function getStats(int $days = 7): array {
        try {
            $pdo = $this->db->getPdo();
            
            // 按操作类型统计
            $stmt = $pdo->prepare(
                "SELECT action, COUNT(*) as count 
                 FROM audit_logs 
                 WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY action"
            );
            $stmt->execute([$days]);
            $byAction = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // 按日期统计
            $stmt = $pdo->prepare(
                "SELECT DATE(created_at) as date, COUNT(*) as count 
                 FROM audit_logs 
                 WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY DATE(created_at)
                 ORDER BY date"
            );
            $stmt->execute([$days]);
            $byDate = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // 按资源类型统计
            $stmt = $pdo->prepare(
                "SELECT resource, COUNT(*) as count 
                 FROM audit_logs 
                 WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY resource"
            );
            $stmt->execute([$days]);
            $byResource = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // 最近活跃 IP
            $stmt = $pdo->prepare(
                "SELECT ip, COUNT(*) as count 
                 FROM audit_logs 
                 WHERE created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
                 GROUP BY ip
                 ORDER BY count DESC
                 LIMIT 10"
            );
            $stmt->execute([$days]);
            $topIps = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'by_action' => $byAction,
                'by_date' => $byDate,
                'by_resource' => $byResource,
                'top_ips' => $topIps
            ];
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * 清理过期日志
     */
    public function cleanup(int $retentionDays = 90): int {
        try {
            $pdo = $this->db->getPdo();
            $stmt = $pdo->prepare(
                "DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
            );
            $stmt->execute([$retentionDays]);
            return $stmt->rowCount();
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * 导出日志
     */
    public function export(array $filters = [], string $format = 'csv'): string {
        $logs = $this->getLogs($filters, 1, 10000);
        
        if ($format === 'csv') {
            $output = "ID,用户,操作,资源,资源ID,详情,IP,时间\n";
            foreach ($logs['data'] as $log) {
                $details = $log['details'] ? json_encode($log['details'], JSON_UNESCAPED_UNICODE) : '';
                $output .= sprintf(
                    "%d,%s,%s,%s,%s,\"%s\",%s,%s\n",
                    $log['id'],
                    $log['user_id'] ?? 'admin',
                    $log['action'],
                    $log['resource'],
                    $log['resource_id'] ?? '',
                    str_replace('"', '""', $details),
                    $log['ip'],
                    $log['created_at']
                );
            }
            return $output;
        }
        
        return json_encode($logs['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    
    /**
     * 获取当前用户ID
     */
    private function getCurrentUserId(): ?string {
        return $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? 'admin';
    }
    
    /**
     * 获取客户端IP
     */
    private function getClientIp(): string {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

// 便捷函数
function audit_log(string $action, string $resource, ?string $resourceId = null, ?array $details = null): bool {
    return AuditLog::getInstance()->log($action, $resource, $resourceId, $details);
}
