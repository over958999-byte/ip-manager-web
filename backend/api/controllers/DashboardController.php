<?php
/**
 * 数据大盘控制器
 */

require_once __DIR__ . '/BaseController.php';

class DashboardController extends BaseController
{
    /**
     * 获取仪表盘统计数据
     */
    public function stats(): void
    {
        $this->requireLogin();
        
        // 跳转规则统计
        $jumpStats = $this->db->fetch(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) as active,
                SUM(clicks) as total_clicks
            FROM jump_rules"
        );
        
        // 短链接统计
        $shortlinkStats = $this->db->fetch(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) as active,
                SUM(clicks) as total_clicks
            FROM shortlinks"
        );
        
        // 域名统计
        $domainStats = $this->db->fetch(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN cf_zone_id IS NOT NULL THEN 1 ELSE 0 END) as cf_enabled
            FROM domains"
        );
        
        // IP 池统计
        $ipPoolStats = $this->db->fetch(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) as blocked
            FROM ip_pool"
        );
        
        // 今日访问量
        $today = date('Y-m-d');
        $todayVisits = $this->db->fetchColumn(
            "SELECT SUM(clicks) FROM jump_rules WHERE DATE(updated_at) = ?",
            [$today]
        ) ?? 0;
        
        // 反爬虫统计
        $antibotStats = $this->db->fetch(
            "SELECT 
                COUNT(*) as blocked_ips,
                SUM(block_count) as total_blocks
            FROM antibot_blocks"
        );
        
        $this->success([
            'jump_rules' => $jumpStats,
            'shortlinks' => $shortlinkStats,
            'domains' => $domainStats,
            'ip_pool' => $ipPoolStats,
            'antibot' => $antibotStats,
            'today_visits' => (int)$todayVisits,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * 获取趋势数据
     */
    public function trend(): void
    {
        $this->requireLogin();
        
        $range = $this->param('range', '7d');
        
        // 根据范围确定天数
        $days = match($range) {
            '24h' => 1,
            '7d' => 7,
            '30d' => 30,
            '90d' => 90,
            default => 7
        };
        
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        
        // 获取每日点击趋势
        $clickTrend = $this->db->fetchAll(
            "SELECT 
                DATE(created_at) as date,
                COUNT(*) as count
            FROM access_logs 
            WHERE created_at >= ?
            GROUP BY DATE(created_at)
            ORDER BY date",
            [$startDate]
        );
        
        // 获取每日新增规则趋势
        $ruleTrend = $this->db->fetchAll(
            "SELECT 
                DATE(created_at) as date,
                COUNT(*) as count
            FROM jump_rules 
            WHERE created_at >= ?
            GROUP BY DATE(created_at)
            ORDER BY date",
            [$startDate]
        );
        
        // 填充缺失的日期
        $trend = [];
        for ($i = $days; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $clicks = 0;
            $rules = 0;
            
            foreach ($clickTrend as $item) {
                if ($item['date'] === $date) {
                    $clicks = (int)$item['count'];
                    break;
                }
            }
            
            foreach ($ruleTrend as $item) {
                if ($item['date'] === $date) {
                    $rules = (int)$item['count'];
                    break;
                }
            }
            
            $trend[] = [
                'date' => $date,
                'clicks' => $clicks,
                'rules' => $rules
            ];
        }
        
        $this->success(['trend' => $trend, 'range' => $range]);
    }
    
    /**
     * 获取实时日志
     */
    public function realtimeLogs(): void
    {
        $this->requireLogin();
        
        $limit = min(100, (int)($this->param('limit') ?? 20));
        
        $logs = $this->db->fetchAll(
            "SELECT * FROM access_logs ORDER BY created_at DESC LIMIT ?",
            [$limit]
        );
        
        $this->success($logs);
    }
    
    /**
     * 获取系统状态
     */
    public function systemStatus(): void
    {
        $this->requireLogin();
        
        // 系统信息
        $status = [
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
            'memory_peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
            'uptime' => $this->getUptime(),
            'load_average' => function_exists('sys_getloadavg') ? sys_getloadavg() : [0, 0, 0],
            'disk_free' => round(disk_free_space('/') / 1024 / 1024 / 1024, 2) . ' GB',
            'disk_total' => round(disk_total_space('/') / 1024 / 1024 / 1024, 2) . ' GB'
        ];
        
        // 数据库连接状态
        try {
            $this->db->fetchColumn("SELECT 1");
            $status['database'] = 'connected';
        } catch (Exception $e) {
            $status['database'] = 'disconnected';
        }
        
        // Redis 连接状态（如果启用）
        $status['redis'] = $this->checkRedisStatus();
        
        $this->success($status);
    }
    
    /**
     * 获取系统运行时间
     */
    private function getUptime(): string
    {
        if (PHP_OS_FAMILY === 'Linux' && file_exists('/proc/uptime')) {
            $uptime = file_get_contents('/proc/uptime');
            $uptime = (int)explode(' ', $uptime)[0];
            
            $days = floor($uptime / 86400);
            $hours = floor(($uptime % 86400) / 3600);
            $minutes = floor(($uptime % 3600) / 60);
            
            return "{$days}天 {$hours}小时 {$minutes}分钟";
        }
        
        return 'Unknown';
    }
    
    /**
     * 检查 Redis 状态
     */
    private function checkRedisStatus(): string
    {
        if (!class_exists('Redis')) {
            return 'not_installed';
        }
        
        $enabled = $this->db->getConfig('redis_enabled', false);
        if (!$enabled) {
            return 'disabled';
        }
        
        try {
            $redis = new Redis();
            $host = $this->db->getConfig('redis_host', '127.0.0.1');
            $port = $this->db->getConfig('redis_port', 6379);
            $redis->connect($host, $port, 1);
            $redis->ping();
            return 'connected';
        } catch (Exception $e) {
            return 'disconnected';
        }
    }
}
