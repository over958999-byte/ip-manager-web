<?php
/**
 * 系统健康检查和监控接口
 * 
 * 访问: /health.php
 * 返回: JSON格式的系统状态
 * 
 * 参数:
 *   ?detail=1  - 返回详细信息
 *   ?format=prometheus - 返回 Prometheus 格式
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../backend/core/database.php';
require_once __DIR__ . '/../backend/core/cache.php';
require_once __DIR__ . '/../backend/core/rate_limiter.php';
require_once __DIR__ . '/../backend/core/circuit_breaker.php';
require_once __DIR__ . '/../backend/core/message_queue.php';

// 可选加载日志模块
$loggerFile = __DIR__ . '/../backend/core/logger.php';
if (file_exists($loggerFile)) {
    require_once $loggerFile;
}

$startTime = microtime(true);
$showDetail = isset($_GET['detail']);
$prometheusFormat = ($_GET['format'] ?? '') === 'prometheus';

$health = [
    'status' => 'healthy',
    'timestamp' => date('c'),
    'version' => '2.0.0',
    'components' => [],
    'metrics' => [],
];

// 检查数据库
try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    // 连接测试
    $dbStart = microtime(true);
    $stmt = $pdo->query("SELECT 1");
    $stmt->fetch();
    $dbLatency = (microtime(true) - $dbStart) * 1000;
    
    $health['components']['database'] = [
        'status' => 'healthy',
        'type' => 'mysql',
        'latency_ms' => round($dbLatency, 2),
    ];
    
    // 获取数据库统计
    $stats = $pdo->query("SHOW STATUS LIKE 'Threads_connected'")->fetch(PDO::FETCH_ASSOC);
    $health['metrics']['db_connections'] = (int)($stats['Value'] ?? 0);
    
    // 获取更多数据库指标
    if ($showDetail) {
        $stats = $pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC);
        $health['metrics']['db_queries_total'] = (int)($stats['Value'] ?? 0);
        
        $stats = $pdo->query("SHOW STATUS LIKE 'Slow_queries'")->fetch(PDO::FETCH_ASSOC);
        $health['metrics']['db_slow_queries'] = (int)($stats['Value'] ?? 0);
        
        // 数据库大小
        $stmt = $pdo->query("SELECT SUM(data_length + index_length) as size FROM information_schema.tables WHERE table_schema = DATABASE()");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $health['metrics']['db_size_mb'] = round(($row['size'] ?? 0) / 1024 / 1024, 2);
    }
    
} catch (Exception $e) {
    $health['status'] = 'unhealthy';
    $health['components']['database'] = [
        'status' => 'unhealthy',
        'error' => $e->getMessage(),
    ];
}


// 检查缓存
try {
    $cache = CacheService::getInstance();
    $stats = $cache->getStats();
    
    $health['components']['cache'] = [
        'status' => 'healthy',
        'memory_items' => $stats['memory_items'],
        'bloom_items' => $stats['bloom_items'],
        'apcu_enabled' => $stats['apcu_enabled'],
    ];
    
    if ($stats['apcu_enabled'] && $stats['apcu_info']) {
        $health['metrics']['cache_memory_mb'] = round($stats['apcu_info']['mem_size'] / 1024 / 1024, 2);
        $health['metrics']['cache_hits'] = $stats['apcu_info']['num_hits'] ?? 0;
        $health['metrics']['cache_misses'] = $stats['apcu_info']['num_misses'] ?? 0;
        $health['metrics']['cache_hit_rate'] = $stats['apcu_info']['num_hits'] > 0 
            ? round($stats['apcu_info']['num_hits'] / ($stats['apcu_info']['num_hits'] + $stats['apcu_info']['num_misses']) * 100, 2)
            : 0;
    }
} catch (Exception $e) {
    $health['components']['cache'] = [
        'status' => 'degraded',
        'error' => $e->getMessage(),
    ];
}

// 检查限流器
try {
    $rateLimiter = RateLimiter::getInstance();
    $stats = $rateLimiter->getStats();
    
    $health['components']['rate_limiter'] = [
        'status' => 'healthy',
        'rules' => array_keys($stats['rules']),
    ];
} catch (Exception $e) {
    $health['components']['rate_limiter'] = [
        'status' => 'degraded',
        'error' => $e->getMessage(),
    ];
}

// 检查熔断器
try {
    $circuitBreaker = CircuitBreaker::getInstance();
    $states = $circuitBreaker->getAllStates();
    
    $hasOpenCircuit = false;
    foreach ($states as $service => $state) {
        if ($state['state'] === 'open') {
            $hasOpenCircuit = true;
            break;
        }
    }
    
    $health['components']['circuit_breaker'] = [
        'status' => $hasOpenCircuit ? 'degraded' : 'healthy',
        'circuits' => $states,
    ];
    
    if ($hasOpenCircuit) {
        $health['status'] = 'degraded';
    }
} catch (Exception $e) {
    $health['components']['circuit_breaker'] = [
        'status' => 'degraded',
        'error' => $e->getMessage(),
    ];
}

// 检查消息队列
try {
    $queue = MessageQueue::getInstance();
    $queueStats = $queue->getQueueStats();
    
    $health['components']['message_queue'] = [
        'status' => 'healthy',
        'queues' => $queueStats,
    ];
    
    $totalPending = 0;
    foreach ($queueStats as $q) {
        $totalPending += $q['size'] ?? 0;
    }
    $health['metrics']['queue_pending'] = $totalPending;
    
} catch (Exception $e) {
    $health['components']['message_queue'] = [
        'status' => 'degraded',
        'error' => $e->getMessage(),
    ];
}

// 系统指标
$health['metrics']['response_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);
$health['metrics']['memory_usage_mb'] = round(memory_get_usage(true) / 1024 / 1024, 2);
$health['metrics']['php_version'] = PHP_VERSION;

// 获取规则统计
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total, SUM(total_clicks) as clicks FROM jump_rules WHERE enabled = 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $health['metrics']['active_rules'] = (int)($row['total'] ?? 0);
    $health['metrics']['total_clicks'] = (int)($row['clicks'] ?? 0);
} catch (Exception $e) {
    // 忽略
}

// 设置HTTP状态码
if ($health['status'] === 'unhealthy') {
    http_response_code(503);
} elseif ($health['status'] === 'degraded') {
    http_response_code(200); // 降级但仍可服务
}

echo json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
