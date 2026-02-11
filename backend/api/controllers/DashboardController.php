<?php
declare(strict_types=1);
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
        
        try {
            $data = $this->loadDashboardStats();
            $this->success($data);
        } catch (Exception $e) {
            // 出错时返回默认数据，并记录错误
            error_log('Dashboard stats error: ' . $e->getMessage());
            $this->success([
                'todayClicks' => 0,
                'totalClicks' => 0,
                'activeRules' => 0,
                'activeDomains' => 0,
                'todayTrend' => 0,
                'weekTrend' => 0,
                'deviceStats' => [],
                'regionStats' => [],
                'topRules' => [],
                'systemStatus' => ['healthy' => false],
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * 加载仪表盘统计数据
     */
    private function loadDashboardStats(): array
    {
        $debug = [];
        
        // 跳转规则统计 - 使用 status='active' 和 visit_count
        try {
            $jumpStats = $this->db->fetch(
                "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                    COALESCE(SUM(visit_count), 0) as total_clicks
                FROM jump_rules"
            ) ?: ['total' => 0, 'active' => 0, 'total_clicks' => 0];
            $debug['jump_rules'] = 'ok';
        } catch (Exception $e) {
            $jumpStats = ['total' => 0, 'active' => 0, 'total_clicks' => 0];
            $debug['jump_rules'] = $e->getMessage();
        }
        
        // 短链接统计 (表名是 short_links)
        try {
            $shortlinkStats = $this->db->fetch(
                "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) as active,
                    COALESCE(SUM(total_clicks), 0) as total_clicks
                FROM short_links"
            ) ?: ['total' => 0, 'active' => 0, 'total_clicks' => 0];
            $debug['short_links'] = 'ok';
        } catch (Exception $e) {
            $shortlinkStats = ['total' => 0, 'active' => 0, 'total_clicks' => 0];
            $debug['short_links'] = $e->getMessage();
        }
        
        // 域名统计 (表名是 jump_domains) - 使用 status='active'
        try {
            $domainStats = $this->db->fetch(
                "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN ssl_enabled = 1 THEN 1 ELSE 0 END) as cf_enabled
                FROM jump_domains"
            ) ?: ['total' => 0, 'active' => 0, 'cf_enabled' => 0];
            $debug['jump_domains'] = 'ok';
        } catch (Exception $e) {
            $domainStats = ['total' => 0, 'active' => 0, 'cf_enabled' => 0];
            $debug['jump_domains'] = $e->getMessage();
        }
        
        // IP 池统计 (简单表结构，只有 id, ip, created_at)
        try {
            $ipPoolStats = $this->db->fetch(
                "SELECT 
                    COUNT(*) as total,
                    COUNT(*) as active,
                    0 as blocked
                FROM ip_pool"
            ) ?: ['total' => 0, 'active' => 0, 'blocked' => 0];
            $debug['ip_pool'] = 'ok';
        } catch (Exception $e) {
            $ipPoolStats = ['total' => 0, 'active' => 0, 'blocked' => 0];
            $debug['ip_pool'] = $e->getMessage();
        }
        
        // 今日访问量 - 从 jump_logs 表获取
        $today = date('Y-m-d');
        try {
            $todayClicks = $this->db->fetchColumn(
                "SELECT COUNT(*) FROM jump_logs WHERE DATE(visited_at) = ?",
                [$today]
            ) ?? 0;
            $debug['todayClicks'] = 'ok: ' . $todayClicks;
        } catch (Exception $e) {
            $todayClicks = 0;
            $debug['todayClicks'] = $e->getMessage();
        }
        
        // 昨日访问量 - 计算趋势
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $yesterdayClicks = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM jump_logs WHERE DATE(visited_at) = ?",
            [$yesterday]
        ) ?? 0;
        
        // 计算今日趋势（与昨天相比的百分比变化）
        $todayTrend = $yesterdayClicks > 0 
            ? round((($todayClicks - $yesterdayClicks) / $yesterdayClicks) * 100, 1)
            : ($todayClicks > 0 ? 100 : 0);
        
        // 本周访问量
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $thisWeekClicks = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM jump_logs WHERE DATE(visited_at) >= ?",
            [$weekStart]
        ) ?? 0;
        
        // 上周访问量
        $lastWeekStart = date('Y-m-d', strtotime('monday last week'));
        $lastWeekEnd = date('Y-m-d', strtotime('sunday last week'));
        $lastWeekClicks = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM jump_logs WHERE DATE(visited_at) BETWEEN ? AND ?",
            [$lastWeekStart, $lastWeekEnd]
        ) ?? 0;
        
        // 计算周趋势
        $weekTrend = $lastWeekClicks > 0
            ? round((($thisWeekClicks - $lastWeekClicks) / $lastWeekClicks) * 100, 1)
            : ($thisWeekClicks > 0 ? 100 : 0);
        
        // 总点击量
        $totalClicks = (int)($jumpStats['total_clicks'] ?? 0) + (int)($shortlinkStats['total_clicks'] ?? 0);
        
        // 反爬虫统计 (表结构: id, ip, reason, blocked_at, until_at)
        $antibotStats = $this->db->fetch(
            "SELECT 
                COUNT(*) as blocked_ips,
                COUNT(*) as total_blocks
            FROM antibot_blocks"
        ) ?: ['blocked_ips' => 0, 'total_blocks' => 0];
        
        // 设备分布统计
        $deviceStats = $this->db->fetchAll(
            "SELECT 
                COALESCE(device_type, 'unknown') as name,
                COUNT(*) as value
            FROM jump_logs 
            WHERE visited_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY device_type
            ORDER BY value DESC
            LIMIT 5"
        );
        
        // 如果没有设备数据，返回默认值
        if (empty($deviceStats)) {
            $deviceStats = [
                ['name' => 'Mobile', 'value' => 0],
                ['name' => 'Desktop', 'value' => 0],
                ['name' => 'Tablet', 'value' => 0]
            ];
        }
        
        // 地区分布统计 - 注意: 字段名是 country_code 不是 country
        $regionStats = $this->db->fetchAll(
            "SELECT 
                COALESCE(country_code, 'Unknown') as name,
                COUNT(*) as value
            FROM jump_logs 
            WHERE visited_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY country_code
            ORDER BY value DESC
            LIMIT 10"
        );
        
        if (empty($regionStats)) {
            $regionStats = [['name' => 'Unknown', 'value' => 0]];
        }
        
        // 热门规则统计 - 使用 visit_count 和 status='active'
        $topRules = $this->db->fetchAll(
            "SELECT 
                COALESCE(name, source_path) as name,
                visit_count as value
            FROM jump_rules 
            WHERE status = 'active'
            ORDER BY visit_count DESC
            LIMIT 10"
        );
        
        if (empty($topRules)) {
            $topRules = [];
        }
        
        // 系统状态（获取真实系统信息）
        $systemStatus = $this->getSystemStatus();
        
        // 返回前端期望的格式
        return [
            // 主要统计数字
            'todayClicks' => (int)$todayClicks,
            'totalClicks' => (int)$totalClicks,
            'activeRules' => (int)($jumpStats['active'] ?? 0),
            'activeDomains' => (int)($domainStats['active'] ?? 0),
            'todayTrend' => $todayTrend,
            'weekTrend' => $weekTrend,
            
            // 图表数据
            'deviceStats' => $deviceStats,
            'regionStats' => $regionStats,
            'topRules' => $topRules,
            
            // 系统状态
            'systemStatus' => $systemStatus,
            
            // 详细统计（保留原有结构供其他页面使用）
            'jump_rules' => $jumpStats,
            'shortlinks' => $shortlinkStats,
            'domains' => $domainStats,
            'ip_pool' => $ipPoolStats,
            'antibot' => $antibotStats,
            
            'updated_at' => date('Y-m-d H:i:s'),
            'cached' => false,
            
            // 调试信息 - 生产环境可移除
            'debug' => $debug
        ];
    }
    
    /**
     * 获取真实系统状态
     */
    private function getSystemStatus(): array
    {
        $cpu = 0;
        $memory = 0;
        $disk = 0;
        
        // CPU 使用率
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $cpu = min(100, (int)($load[0] * 10)); // 粗略估计
        }
        
        // 内存使用率
        $memInfo = @file_get_contents('/proc/meminfo');
        if ($memInfo) {
            preg_match('/MemTotal:\s+(\d+)/', $memInfo, $total);
            preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $available);
            if (!empty($total[1]) && !empty($available[1])) {
                $memory = (int)(100 - ($available[1] / $total[1] * 100));
            }
        }
        
        // 磁盘使用率
        $totalSpace = @disk_total_space('/');
        $freeSpace = @disk_free_space('/');
        if ($totalSpace && $freeSpace) {
            $disk = (int)(100 - ($freeSpace / $totalSpace * 100));
        }
        
        // 数据库连接数
        $dbConnections = 0;
        try {
            $result = $this->db->fetch("SHOW STATUS LIKE 'Threads_connected'");
            $dbConnections = (int)($result['Value'] ?? 0);
        } catch (Exception $e) {}
        
        return [
            'cpu' => $cpu,
            'memory' => $memory,
            'disk' => $disk,
            'db_connections' => $dbConnections,
            'uptime' => $this->getUptime()
        ];
    }
    
    /**
     * 获取系统运行时间
     */
    private function getUptime(): string
    {
        $uptime = @file_get_contents('/proc/uptime');
        if ($uptime) {
            $seconds = (int)explode(' ', $uptime)[0];
            $days = floor($seconds / 86400);
            $hours = floor(($seconds % 86400) / 3600);
            return "{$days}天{$hours}小时";
        }
        return 'N/A';
    }
    
    /**
     * 获取趋势数据（带缓存）
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
        
        // 获取每日点击趋势 (使用 jump_logs 表)
        $clickTrend = $this->db->fetchAll(
            "SELECT 
                DATE(visited_at) as date,
                COUNT(*) as count
            FROM jump_logs 
            WHERE visited_at >= ?
            GROUP BY DATE(visited_at)
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
        $dates = [];
        $pv = [];  // 页面浏览量 (clicks)
        $uv = [];  // 独立访客数
        
        for ($i = $days; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $clicks = 0;
            
            foreach ($clickTrend as $item) {
                if ($item['date'] === $date) {
                    $clicks = (int)$item['count'];
                    break;
                }
            }
            
            $dates[] = date('m-d', strtotime($date));
            $pv[] = $clicks;
            $uv[] = max(1, (int)($clicks * 0.7)); // 模拟UV（约为PV的70%）
        }
        
        // 获取实际的UV数据（如果有的话）
        $uvTrend = $this->db->fetchAll(
            "SELECT 
                DATE(visited_at) as date,
                COUNT(DISTINCT ip) as count
            FROM jump_logs 
            WHERE visited_at >= ?
            GROUP BY DATE(visited_at)
            ORDER BY date",
            [$startDate]
        );
        
        // 用实际UV数据覆盖
        if (!empty($uvTrend)) {
            $uvMap = [];
            foreach ($uvTrend as $item) {
                $uvMap[$item['date']] = (int)$item['count'];
            }
            for ($i = $days; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-{$i} days"));
                $idx = $days - $i;
                if (isset($uvMap[$date])) {
                    $uv[$idx] = $uvMap[$date];
                }
            }
        }
        
        $this->success([
            'dates' => $dates,
            'pv' => $pv,
            'uv' => $uv,
            'range' => $range
        ]);
    }
    
    /**
     * 获取实时日志
     */
    public function realtimeLogs(): void
    {
        $this->requireLogin();
        
        $limit = min(100, (int)($this->param('limit') ?? 20));
        
        // 使用 jump_logs 表 - 字段名与 database_full.sql 一致
        $logs = $this->db->fetchAll(
            "SELECT 
                id,
                COALESCE(rule_id, 0) as rule_id,
                domain,
                path,
                ip,
                country_code as country,
                device_type,
                browser,
                referer,
                visited_at as created_at
            FROM jump_logs 
            ORDER BY visited_at DESC 
            LIMIT ?",
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
