<?php
/**
 * 系统健康检查和监控接口
 * 
 * 访问: /health.php
 * 返回: JSON格式的系统状态
 * 
 * 参数:
 *   ?detail=1  - 返回详细信息（需要鉴权）
 *   ?format=prometheus - 返回 Prometheus 格式
 * 
 * 安全增强:
 *   - IP白名单校验
 *   - Bearer Token鉴权（详细信息）
 *   - 敏感信息脱敏
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache');

// ==================== 安全校验 ====================

/**
 * 获取客户端真实IP
 */
function getClientIpHealth(): string {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * IP白名单校验
 */
function isIpAllowed(string $ip): bool {
    // 从环境变量获取白名单，默认允许本地和私有网络
    $whitelist = getenv('HEALTH_IP_WHITELIST') ?: '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16';
    $allowedIps = array_map('trim', explode(',', $whitelist));
    
    foreach ($allowedIps as $allowed) {
        if (strpos($allowed, '/') !== false) {
            // CIDR格式
            if (ipInCidr($ip, $allowed)) {
                return true;
            }
        } else {
            // 精确匹配
            if ($ip === $allowed) {
                return true;
            }
        }
    }
    return false;
}

/**
 * CIDR匹配
 */
function ipInCidr(string $ip, string $cidr): bool {
    list($subnet, $bits) = explode('/', $cidr);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - (int)$bits);
        return ($ip & $mask) === ($subnet & $mask);
    }
    return false;
}

/**
 * Token鉴权校验
 */
function isTokenValid(): bool {
    $authToken = getenv('HEALTH_AUTH_TOKEN');
    if (empty($authToken)) {
        return true; // 未配置token则不校验
    }
    
    $requestToken = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $requestToken = str_replace('Bearer ', '', $requestToken);
    
    return hash_equals($authToken, $requestToken);
}

// 获取客户端IP
$clientIp = getClientIpHealth();
$showDetail = isset($_GET['detail']);

// IP白名单校验（基础健康检查允许所有访问，详细信息需要白名单）
if ($showDetail && !isIpAllowed($clientIp)) {
    http_response_code(403);
    echo json_encode(['error' => 'IP not allowed', 'ip' => $clientIp]);
    exit;
}

// Token鉴权（详细信息需要token）
if ($showDetail && !isTokenValid()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ==================== 加载依赖 ====================

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
$prometheusFormat = ($_GET['format'] ?? '') === 'prometheus';

$health = [
    'status' => 'healthy',
    'timestamp' => date('c'),
    'version' => '2.0.0',
    'components' => [],
];

// 详细信息模式才返回metrics
if ($showDetail) {
    $health['metrics'] = [];
}

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
        'latency_ms' => round($dbLatency, 2),
    ];
    
    // 详细模式：获取数据库统计（脱敏）
    if ($showDetail) {
        $stats = $pdo->query("SHOW STATUS LIKE 'Threads_connected'")->fetch(PDO::FETCH_ASSOC);
        $health['metrics']['db_connections'] = (int)($stats['Value'] ?? 0);
        
        $stats = $pdo->query("SHOW STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC);
        $health['metrics']['db_queries_total'] = (int)($stats['Value'] ?? 0);
        
        $stats = $pdo->query("SHOW STATUS LIKE 'Slow_queries'")->fetch(PDO::FETCH_ASSOC);
        $health['metrics']['db_slow_queries'] = (int)($stats['Value'] ?? 0);
    }
    
} catch (Exception $e) {
    $health['status'] = 'unhealthy';
    $health['components']['database'] = [
        'status' => 'unhealthy',
        // 不暴露详细错误信息
        'error' => $showDetail ? $e->getMessage() : 'connection_failed',
    ];
}


// 检查缓存
try {
    $cache = CacheService::getInstance();
    $stats = $cache->getStats();
    
    $health['components']['cache'] = [
        'status' => 'healthy',
        'apcu_enabled' => $stats['apcu_enabled'],
    ];
    
    // 详细模式：返回缓存指标
    if ($showDetail && $stats['apcu_enabled'] && $stats['apcu_info']) {
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
        'error' => $showDetail ? $e->getMessage() : 'unavailable',
    ];
}

// 检查限流器
try {
    $rateLimiter = RateLimiter::getInstance();
    
    $health['components']['rate_limiter'] = [
        'status' => 'healthy',
    ];
} catch (Exception $e) {
    $health['components']['rate_limiter'] = [
        'status' => 'degraded',
        'error' => $showDetail ? $e->getMessage() : 'unavailable',
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
    ];
    
    // 详细模式才返回具体熔断状态
    if ($showDetail) {
        $health['components']['circuit_breaker']['circuits'] = $states;
    }
    
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
    
    $health['components']['message_queue'] = [
        'status' => 'healthy',
    ];
    
    // 详细模式返回队列统计
    if ($showDetail) {
        $queueStats = $queue->getQueueStats();
        $health['components']['message_queue']['queues'] = $queueStats;
        
        $totalPending = 0;
        foreach ($queueStats as $q) {
            $totalPending += $q['size'] ?? 0;
        }
        $health['metrics']['queue_pending'] = $totalPending;
    }
    
} catch (Exception $e) {
    $health['components']['message_queue'] = [
        'status' => 'degraded',
        'error' => $showDetail ? $e->getMessage() : 'unavailable',
    ];
}

// 基础系统指标（简化版）
$health['response_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);

// 详细模式返回更多系统指标
if ($showDetail) {
    $health['metrics']['response_time_ms'] = $health['response_time_ms'];
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
}

// 设置HTTP状态码
if ($health['status'] === 'unhealthy') {
    http_response_code(503);
} elseif ($health['status'] === 'degraded') {
    http_response_code(200); // 降级但仍可服务
}

echo json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
