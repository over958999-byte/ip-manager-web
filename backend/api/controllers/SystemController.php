<?php
/**
 * 系统控制器
 * 处理系统信息、更新检查、备份等功能
 */

require_once __DIR__ . '/BaseController.php';

class SystemController extends BaseController
{
    private const REPO_URL = 'https://api.github.com/repos/over958999-byte/ip-manager-web/commits/master';
    private const CURRENT_VERSION = '1.0.0';
    
    /**
     * 获取系统信息
     * GET /api/v2/system/info
     */
    public function info(): void
    {
        $installDir = realpath(__DIR__ . '/../../..');
        $localVersionFile = $installDir . '/.git/refs/heads/master';
        
        $localVersion = '';
        if (file_exists($localVersionFile)) {
            $localVersion = substr(trim(file_get_contents($localVersionFile)), 0, 7);
        }
        
        $this->success([
            'version' => self::CURRENT_VERSION,
            'commit' => $localVersion,
            'php_version' => PHP_VERSION,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'install_dir' => $installDir,
            'is_git_repo' => is_dir($installDir . '/.git')
        ]);
    }
    
    /**
     * 检查系统更新
     * GET /api/v2/system/check-update
     */
    public function checkUpdate(): void
    {
        $installDir = realpath(__DIR__ . '/../../..');
        $localVersionFile = $installDir . '/.git/refs/heads/master';
        
        $localVersion = '';
        if (file_exists($localVersionFile)) {
            $localVersion = trim(file_get_contents($localVersionFile));
        }
        
        // 获取远程最新版本
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::REPO_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'IP-Manager-Updater',
            CURLOPT_TIMEOUT => 10
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            $this->error('无法连接到GitHub，请检查网络');
        }
        
        $data = json_decode($response, true);
        $remoteVersion = $data['sha'] ?? '';
        $commitMessage = $data['commit']['message'] ?? '';
        $commitDate = $data['commit']['committer']['date'] ?? '';
        
        $hasUpdate = !empty($remoteVersion) && $remoteVersion !== $localVersion;
        
        $this->success([
            'has_update' => $hasUpdate,
            'local_version' => substr($localVersion, 0, 7),
            'remote_version' => substr($remoteVersion, 0, 7),
            'commit_message' => $commitMessage,
            'commit_date' => $commitDate,
            'current_version' => self::CURRENT_VERSION
        ]);
    }
    
    /**
     * 执行系统更新
     * POST /api/v2/system/update
     */
    public function update(): void
    {
        $this->requireLogin();
        
        $installDir = realpath(__DIR__ . '/../../..');
        
        // 检查是否是 git 仓库
        if (!is_dir($installDir . '/.git')) {
            $this->error('当前不是Git仓库，无法自动更新。请手动更新或重新部署。');
        }
        
        // 备份配置文件
        $configFile = $installDir . '/backend/core/db_config.php';
        $configBackup = '';
        if (file_exists($configFile)) {
            $configBackup = file_get_contents($configFile);
        }
        
        // 切换到项目目录
        chdir($installDir);
        
        // 获取更新
        $output = [];
        exec('git fetch origin 2>&1', $output, $returnVar);
        if ($returnVar !== 0) {
            $this->error('Git fetch失败: ' . implode("\n", $output));
        }
        
        // 重置到最新版本
        $output = [];
        exec('git reset --hard origin/master 2>&1', $output, $returnVar);
        if ($returnVar !== 0) {
            $this->error('Git reset失败: ' . implode("\n", $output));
        }
        
        // 恢复配置文件
        if (!empty($configBackup)) {
            file_put_contents($configFile, $configBackup);
        }
        
        // 自动构建前端
        $buildResult = $this->buildFrontend($installDir);
        
        $this->audit('system_update', 'system');
        
        $this->success([
            'build_success' => $buildResult['success'],
            'build_output' => $buildResult['output'],
            'git_output' => implode("\n", $output)
        ], '更新成功！' . ($buildResult['success'] ? '前端已自动重新编译。' : ''));
    }
    
    /**
     * 构建前端
     */
    private function buildFrontend(string $installDir): array
    {
        $buildOutput = [];
        $buildSuccess = false;
        $frontendDir = $installDir . '/backend/frontend';
        
        if (!is_dir($frontendDir) || !file_exists($frontendDir . '/package.json')) {
            return ['success' => false, 'output' => '前端目录不存在'];
        }
        
        chdir($frontendDir);
        
        // 检查 node 和 npm 是否可用
        $nodeVersion = shell_exec('node -v 2>&1');
        $npmVersion = shell_exec('npm -v 2>&1');
        
        if (!$nodeVersion || !$npmVersion || strpos($nodeVersion, 'v') !== 0) {
            $buildOutput[] = '未检测到 Node.js 环境，跳过前端编译';
            $buildOutput[] = '请手动执行: cd backend/frontend && npm install && npm run build';
            return ['success' => false, 'output' => implode("\n", $buildOutput)];
        }
        
        // 检查 node_modules 是否存在
        if (!is_dir($frontendDir . '/node_modules')) {
            $buildOutput[] = '正在安装依赖...';
            exec('npm install 2>&1', $npmInstallOutput, $npmInstallReturn);
            $buildOutput = array_merge($buildOutput, $npmInstallOutput);
            
            if ($npmInstallReturn !== 0) {
                $buildOutput[] = '依赖安装失败，跳过编译';
                return ['success' => false, 'output' => implode("\n", $buildOutput)];
            }
        }
        
        // 执行编译
        $buildOutput[] = '正在编译前端...';
        exec('npm run build 2>&1', $npmBuildOutput, $npmBuildReturn);
        $buildOutput = array_merge($buildOutput, $npmBuildOutput);
        
        if ($npmBuildReturn === 0) {
            $buildSuccess = true;
            $buildOutput[] = '前端编译成功！';
        } else {
            $buildOutput[] = '前端编译失败';
        }
        
        return ['success' => $buildSuccess, 'output' => implode("\n", $buildOutput)];
    }
    
    /**
     * 获取访问统计
     * GET /api/v2/system/stats
     */
    public function getStats(): void
    {
        $this->requireLogin();
        
        $stats = $this->db->getVisitStats();
        $this->success($stats);
    }
    
    /**
     * 获取指定 IP 的统计
     * GET /api/v2/system/stats/{ip}
     */
    public function getIpStats(string $ip): void
    {
        $this->requireLogin();
        
        if (empty($ip)) {
            $this->error('IP不能为空');
        }
        
        $stats = $this->db->getIpStats($ip);
        $this->success($stats);
    }
    
    /**
     * 清空统计
     * DELETE /api/v2/system/stats
     */
    public function clearStats(): void
    {
        $this->requireLogin();
        
        $ip = trim($this->param('ip', ''));
        $this->db->clearStats($ip ?: null);
        
        $this->audit('clear_stats', 'system', null, $ip ? ['ip' => $ip] : []);
        $this->success(null, '统计已清空');
    }
    
    /**
     * 导出所有重定向规则
     * GET /api/v2/system/export
     */
    public function export(): void
    {
        $this->requireLogin();
        
        $redirects = $this->db->getRedirects();
        $this->success($redirects);
    }
    
    /**
     * 导入重定向规则
     * POST /api/v2/system/import
     */
    public function import(): void
    {
        $this->requireLogin();
        
        $data = $this->param('data', []);
        
        if (empty($data)) {
            $this->error('导入数据为空');
        }
        
        $count = 0;
        foreach ($data as $ip => $info) {
            if (is_array($info) && isset($info['url'])) {
                if ($this->db->addRedirect($ip, $info['url'], $info['note'] ?? '')) {
                    $this->db->updateRedirect($ip, $info);
                    $count++;
                }
            }
        }
        
        $this->audit('import', 'system', null, ['count' => $count]);
        $this->success(['count' => $count], "成功导入 {$count} 条记录");
    }
    
    /**
     * 获取系统健康状态
     * GET /api/v2/system/health
     */
    public function health(): void
    {
        $this->requireLogin();
        
        $startTime = defined('APP_START_TIME') ? APP_START_TIME : ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        
        // 数据库状态
        $dbStatus = [
            'connected' => false,
            'connections' => 0,
            'queries_per_min' => 0,
            'slow_queries' => 0,
            'size' => 0
        ];
        
        try {
            $pdo = $this->pdo();
            
            // 测试数据库连接
            $pdo->query("SELECT 1");
            $dbStatus['connected'] = true;
            
            // 获取数据库大小
            $stmt = $pdo->query("SELECT SUM(data_length + index_length) as size FROM information_schema.tables WHERE table_schema = DATABASE()");
            if ($stmt && $row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $dbStatus['size'] = (int)($row['size'] ?? 0);
            }
            
            // 获取进程列表统计
            $processStmt = $pdo->query("SHOW PROCESSLIST");
            if ($processStmt) {
                $dbStatus['connections'] = $processStmt->rowCount();
            }
            
            // 获取状态变量
            $statusStmt = $pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Queries', 'Slow_queries', 'Uptime')");
            if ($statusStmt) {
                $statusVars = [];
                while ($row = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
                    $statusVars[$row['Variable_name']] = $row['Value'];
                }
                $uptime = (int)($statusVars['Uptime'] ?? 1);
                $queries = (int)($statusVars['Queries'] ?? 0);
                $dbStatus['queries_per_min'] = $uptime > 0 ? round($queries / ($uptime / 60)) : 0;
                $dbStatus['slow_queries'] = (int)($statusVars['Slow_queries'] ?? 0);
            }
        } catch (Exception $e) {
            $dbStatus['error'] = $e->getMessage();
        }
        
        // 缓存状态
        $cacheStatus = [
            'enabled' => false,
            'type' => null,
            'hit_rate' => 0,
            'memory_used' => 0,
            'memory_total' => 0,
            'keys' => 0
        ];
        
        // 检查 Redis
        if (class_exists('Redis')) {
            try {
                $redis = new Redis();
                $redisHost = getenv('REDIS_HOST') ?: '127.0.0.1';
                $redisPort = getenv('REDIS_PORT') ?: 6379;
                
                if (@$redis->connect($redisHost, (int)$redisPort, 1)) {
                    $cacheStatus['enabled'] = true;
                    $cacheStatus['type'] = 'Redis';
                    
                    $info = $redis->info();
                    $cacheStatus['memory_used'] = (int)($info['used_memory'] ?? 0);
                    $cacheStatus['memory_total'] = (int)($info['total_system_memory'] ?? $info['used_memory'] ?? 0);
                    $cacheStatus['keys'] = (int)($redis->dbSize() ?? 0);
                    
                    // 计算命中率
                    $hits = (int)($info['keyspace_hits'] ?? 0);
                    $misses = (int)($info['keyspace_misses'] ?? 0);
                    $total = $hits + $misses;
                    $cacheStatus['hit_rate'] = $total > 0 ? round(($hits / $total) * 100, 1) : 0;
                    
                    $redis->close();
                }
            } catch (Exception $e) {
                // Redis 不可用，忽略
            }
        }
        
        // 如果 Redis 不可用，检查 APCu
        if (!$cacheStatus['enabled'] && function_exists('apcu_cache_info')) {
            try {
                $apcuInfo = @apcu_cache_info(true);
                $smaInfo = @apcu_sma_info(true);
                
                if ($apcuInfo !== false) {
                    $cacheStatus['enabled'] = true;
                    $cacheStatus['type'] = 'APCu';
                    $cacheStatus['memory_used'] = (int)($apcuInfo['mem_size'] ?? 0);
                    $cacheStatus['memory_total'] = (int)($smaInfo['seg_size'] ?? 0);
                    $cacheStatus['keys'] = (int)($apcuInfo['num_entries'] ?? 0);
                    
                    $hits = (int)($apcuInfo['num_hits'] ?? 0);
                    $misses = (int)($apcuInfo['num_misses'] ?? 0);
                    $total = $hits + $misses;
                    $cacheStatus['hit_rate'] = $total > 0 ? round(($hits / $total) * 100, 1) : 0;
                }
            } catch (Exception $e) {
                // APCu 不可用
            }
        }
        
        // 系统性能指标
        $metrics = [
            'avg_response_time' => round((microtime(true) - $startTime) * 1000, 2),
            'requests_per_min' => 0,
            'error_rate' => 0,
            'uptime' => $this->formatUptime()
        ];
        
        // 尝试从统计文件读取性能数据
        $statsFile = sys_get_temp_dir() . '/ip_manager_stats.json';
        if (file_exists($statsFile)) {
            $statsData = @json_decode(file_get_contents($statsFile), true);
            if ($statsData) {
                $metrics['requests_per_min'] = $statsData['requests_per_min'] ?? 0;
                $metrics['error_rate'] = $statsData['error_rate'] ?? 0;
            }
        }
        
        $this->success([
            'status' => 'healthy',
            'database' => $dbStatus,
            'cache' => $cacheStatus,
            'metrics' => $metrics,
            'uptime' => $metrics['uptime'],
            'timestamp' => date('c')
        ]);
    }
    
    /**
     * 获取 Prometheus 格式的指标
     * GET /api/v2/system/metrics
     */
    public function metrics(): void
    {
        // 指标可以公开访问，但建议在生产环境加保护
        $metrics = [];
        
        // 添加一些基本指标
        $metrics[] = '# HELP ip_manager_requests_total Total number of requests';
        $metrics[] = '# TYPE ip_manager_requests_total counter';
        $metrics[] = 'ip_manager_requests_total{status="success"} ' . ($this->getStatValue('success_count') ?: 0);
        $metrics[] = 'ip_manager_requests_total{status="error"} ' . ($this->getStatValue('error_count') ?: 0);
        
        $metrics[] = '';
        $metrics[] = '# HELP ip_manager_response_time_seconds Response time in seconds';
        $metrics[] = '# TYPE ip_manager_response_time_seconds gauge';
        $metrics[] = 'ip_manager_response_time_seconds ' . (($this->getStatValue('avg_response_time') ?: 0) / 1000);
        
        $metrics[] = '';
        $metrics[] = '# HELP ip_manager_blocked_total Total blocked requests';
        $metrics[] = '# TYPE ip_manager_blocked_total counter';
        $metrics[] = 'ip_manager_blocked_total ' . ($this->getStatValue('blocked_count') ?: 0);
        
        // 如果需要返回 JSON
        if ($this->param('format') === 'json') {
            $this->success([
                'metrics' => $metrics,
                'format' => 'prometheus'
            ]);
        }
        
        // 默认返回 Prometheus 文本格式
        header('Content-Type: text/plain; charset=utf-8');
        echo implode("\n", $metrics);
        exit;
    }
    
    /**
     * 获取缓存统计
     * GET /api/v2/system/cache-stats
     */
    public function cacheStats(): void
    {
        $this->requireLogin();
        
        $stats = [
            'enabled' => false,
            'type' => null,
            'details' => []
        ];
        
        // Redis 统计
        if (class_exists('Redis')) {
            try {
                $redis = new Redis();
                $redisHost = getenv('REDIS_HOST') ?: '127.0.0.1';
                $redisPort = getenv('REDIS_PORT') ?: 6379;
                
                if (@$redis->connect($redisHost, (int)$redisPort, 1)) {
                    $stats['enabled'] = true;
                    $stats['type'] = 'Redis';
                    
                    $info = $redis->info();
                    $stats['details'] = [
                        'version' => $info['redis_version'] ?? 'unknown',
                        'uptime_days' => round(($info['uptime_in_seconds'] ?? 0) / 86400, 1),
                        'connected_clients' => $info['connected_clients'] ?? 0,
                        'used_memory_human' => $info['used_memory_human'] ?? '0B',
                        'used_memory_peak_human' => $info['used_memory_peak_human'] ?? '0B',
                        'total_connections_received' => $info['total_connections_received'] ?? 0,
                        'total_commands_processed' => $info['total_commands_processed'] ?? 0,
                        'keyspace_hits' => $info['keyspace_hits'] ?? 0,
                        'keyspace_misses' => $info['keyspace_misses'] ?? 0,
                        'expired_keys' => $info['expired_keys'] ?? 0,
                        'evicted_keys' => $info['evicted_keys'] ?? 0,
                        'db_keys' => $redis->dbSize()
                    ];
                    
                    $redis->close();
                }
            } catch (Exception $e) {
                $stats['error'] = 'Redis: ' . $e->getMessage();
            }
        }
        
        // APCu 统计
        if (!$stats['enabled'] && function_exists('apcu_cache_info')) {
            try {
                $apcuInfo = @apcu_cache_info();
                $smaInfo = @apcu_sma_info();
                
                if ($apcuInfo !== false) {
                    $stats['enabled'] = true;
                    $stats['type'] = 'APCu';
                    $stats['details'] = [
                        'num_entries' => $apcuInfo['num_entries'] ?? 0,
                        'num_hits' => $apcuInfo['num_hits'] ?? 0,
                        'num_misses' => $apcuInfo['num_misses'] ?? 0,
                        'num_inserts' => $apcuInfo['num_inserts'] ?? 0,
                        'expunges' => $apcuInfo['expunges'] ?? 0,
                        'mem_size' => $this->formatBytes($apcuInfo['mem_size'] ?? 0),
                        'start_time' => date('Y-m-d H:i:s', $apcuInfo['start_time'] ?? 0),
                        'avail_mem' => $this->formatBytes($smaInfo['avail_mem'] ?? 0),
                        'seg_size' => $this->formatBytes($smaInfo['seg_size'] ?? 0)
                    ];
                }
            } catch (Exception $e) {
                $stats['error'] = 'APCu: ' . $e->getMessage();
            }
        }
        
        if (!$stats['enabled']) {
            $stats['message'] = '未启用缓存（建议启用 Redis 或 APCu 以提升性能）';
        }
        
        $this->success($stats);
    }
    
    /**
     * 格式化运行时间
     */
    private function formatUptime(): string
    {
        $uptimeFile = '/proc/uptime';
        if (file_exists($uptimeFile)) {
            $uptime = (float)explode(' ', file_get_contents($uptimeFile))[0];
        } else {
            // Windows 或其他系统
            $uptime = time() - ($_SERVER['REQUEST_TIME'] ?? time());
        }
        
        $days = floor($uptime / 86400);
        $hours = floor(($uptime % 86400) / 3600);
        $mins = floor(($uptime % 3600) / 60);
        
        if ($days > 0) {
            return "{$days}天 {$hours}小时";
        } elseif ($hours > 0) {
            return "{$hours}小时 {$mins}分钟";
        } else {
            return "{$mins}分钟";
        }
    }
    
    /**
     * 格式化字节数
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 B';
        $k = 1024;
        $sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes) / log($k));
        return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
    }
    
    /**
     * 获取统计值
     */
    private function getStatValue(string $key): int
    {
        $statsFile = sys_get_temp_dir() . '/ip_manager_stats.json';
        if (file_exists($statsFile)) {
            $stats = @json_decode(file_get_contents($statsFile), true);
            return (int)($stats[$key] ?? 0);
        }
        return 0;
    }
}
