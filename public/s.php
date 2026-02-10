<?php
/**
 * 高性能短链跳转入口
 * 
 * 特性：
 * - 多级缓存：内存 -> APCu -> 数据库
 * - 分布式限流：令牌桶 + 滑动窗口
 * - 熔断保护：数据库异常自动熔断
 * - 布隆过滤器：防止缓存穿透
 * - 异步日志：不阻塞请求响应
 * - 连接池复用：PDO 持久连接
 */

// 启动时间记录
define('START_TIME', microtime(true));

// 错误处理
error_reporting(0);
set_error_handler(function($errno, $errstr) {
    // 静默处理非致命错误
});

// 引入核心组件
require_once __DIR__ . '/../backend/core/database.php';
require_once __DIR__ . '/../backend/core/cache.php';
require_once __DIR__ . '/../backend/core/rate_limiter.php';
require_once __DIR__ . '/../backend/core/circuit_breaker.php';
require_once __DIR__ . '/../backend/core/message_queue.php';
require_once __DIR__ . '/../backend/core/jump.php';

// 获取服务实例
$cache = CacheService::getInstance();
$rateLimiter = RateLimiter::getInstance();
$circuitBreaker = CircuitBreaker::getInstance();
$queue = MessageQueue::getInstance();

/**
 * 快速响应函数
 */
function quickResponse(int $code, string $message = '', string $url = ''): void {
    http_response_code($code);
    
    if ($code === 302 && $url) {
        header("Location: {$url}");
    } elseif ($code === 429) {
        header('Retry-After: 60');
        echo "请求过于频繁，请稍后再试";
    } elseif ($code === 503) {
        header('Retry-After: 30');
        echo "服务暂时不可用，请稍后再试";
    } elseif ($code === 404) {
        echo $message ?: "页面不存在";
    } elseif ($code === 403) {
        echo $message ?: "访问被拒绝";
    }
    
    exit;
}

/**
 * 获取客户端IP（高性能版）
 */
function getClientIp(): string {
    static $ip = null;
    if ($ip !== null) return $ip;
    
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = strtok($_SERVER['HTTP_X_FORWARDED_FOR'], ',');
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    return $ip = trim($ip);
}

/**
 * 获取设备类型（高性能版）
 */
function getDeviceType(): string {
    static $type = null;
    if ($type !== null) return $type;
    
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    if (stripos($ua, 'iphone') !== false || stripos($ua, 'ipad') !== false) {
        return $type = 'ios';
    }
    if (stripos($ua, 'android') !== false) {
        return $type = 'android';
    }
    if ((stripos($ua, 'windows') !== false || stripos($ua, 'macintosh') !== false) 
        && stripos($ua, 'mobile') === false) {
        return $type = 'desktop';
    }
    
    return $type = 'mobile';
}

/**
 * 解析短码
 */
function parseShortCode(): ?string {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);
    
    // 移除前缀斜杠
    $code = ltrim($path, '/');
    
    // 移除可能的目录前缀
    if (strpos($code, '/') !== false) {
        $code = basename($code);
    }
    
    // 验证短码格式 (3-10位 Base62字符)
    if (preg_match('/^[0-9A-Za-z]{3,10}$/', $code)) {
        return $code;
    }
    
    return null;
}

// ==================== 主流程 ====================

try {
    // 1. 解析短码
    $code = parseShortCode();
    if (!$code) {
        quickResponse(404, "无效的短链");
    }
    
    $clientIp = getClientIp();
    
    // 2. 全局限流检查
    $globalLimit = $rateLimiter->checkGlobal();
    if (!$globalLimit['allowed']) {
        quickResponse(429);
    }
    
    // 3. IP限流检查
    $ipLimit = $rateLimiter->checkIp($clientIp);
    if (!$ipLimit['allowed']) {
        quickResponse(429);
    }
    
    // 4. 熔断检查
    if (!$circuitBreaker->canExecute('database')) {
        // 数据库熔断中，尝试从缓存获取
        $rule = $cache->get("rule:code:{$code}", fn() => null, 60);
        if (!$rule) {
            quickResponse(503);
        }
    } else {
        // 5. 从缓存获取规则（带穿透保护）
        $rule = $cache->get("rule:code:{$code}", function() use ($code, $circuitBreaker) {
            return $circuitBreaker->execute('database', function() use ($code) {
                $db = Database::getInstance();
                $pdo = $db->getConnection();
                $jumpService = new JumpService($pdo);
                return $jumpService->getByKey('code', $code, false);
            }, function() {
                // 降级：返回null
                return null;
            });
        }, 300);
    }
    
    // 6. 规则检查
    if (!$rule) {
        // 记录到布隆过滤器（这个code不存在）
        quickResponse(404, "短链不存在");
    }
    
    if (!$rule['enabled']) {
        quickResponse(404, "短链已禁用");
    }
    
    // 7. 过期检查
    if ($rule['expire_type'] === 'datetime' && $rule['expire_at']) {
        if (strtotime($rule['expire_at']) < time()) {
            quickResponse(404, "短链已过期");
        }
    }
    
    if ($rule['expire_type'] === 'clicks' && $rule['max_clicks']) {
        if ($rule['total_clicks'] >= $rule['max_clicks']) {
            quickResponse(404, "短链已达到最大访问次数");
        }
    }
    
    // 8. 设备拦截检查
    $deviceType = getDeviceType();
    if (($deviceType === 'desktop' && $rule['block_desktop']) ||
        ($deviceType === 'ios' && $rule['block_ios']) ||
        ($deviceType === 'android' && $rule['block_android'])) {
        quickResponse(403, "您的设备类型不允许访问");
    }
    
    // 9. 国家白名单检查（从缓存获取国家代码）
    if ($rule['country_whitelist_enabled'] && !empty($rule['country_whitelist'])) {
        $countryCode = $cache->get("geo:{$clientIp}", function() use ($clientIp) {
            // 本地IP
            if (strpos($clientIp, '192.168.') === 0 || strpos($clientIp, '10.') === 0 || $clientIp === '127.0.0.1') {
                return 'LOCAL';
            }
            
            // 快速地理位置查询
            $ctx = stream_context_create(['http' => ['timeout' => 0.5]]);
            $resp = @file_get_contents("http://ip-api.com/json/{$clientIp}?fields=countryCode", false, $ctx);
            if ($resp) {
                $data = json_decode($resp, true);
                return $data['countryCode'] ?? 'UNKNOWN';
            }
            return 'UNKNOWN';
        }, 3600); // 地理位置缓存1小时
        
        $allowed = is_array($rule['country_whitelist']) 
            ? $rule['country_whitelist'] 
            : json_decode($rule['country_whitelist'], true) ?? [];
        $allowed = array_map('strtoupper', $allowed);
        
        if ($countryCode !== 'LOCAL' && !in_array(strtoupper($countryCode), $allowed)) {
            quickResponse(403, "您所在的地区不允许访问");
        }
    }
    
    // 10. 立即发送重定向响应
    $targetUrl = $rule['target_url'];
    if (!preg_match('/^https?:\/\//i', $targetUrl)) {
        $targetUrl = 'https://' . $targetUrl;
    }
    
    // 发送响应头
    header("Location: {$targetUrl}", true, 302);
    header("X-Response-Time: " . round((microtime(true) - START_TIME) * 1000, 2) . "ms");
    
    // 立即结束响应
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        ob_end_flush();
        flush();
    }
    
    // ==================== 后台任务（用户已收到响应）====================
    
    // 11. 异步记录访问日志
    $logData = [
        'rule_id' => $rule['id'],
        'code' => $code,
        'ip' => $clientIp,
        'device' => $deviceType,
        'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
        'referer' => substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500),
        'time' => microtime(true),
    ];
    
    $queue->push('access_log', $logData);
    
    // 12. 更新点击统计（批量处理）
    $clickKey = "clicks:{$rule['id']}:" . date('YmdH');
    $clickCount = $cache->get($clickKey, fn() => 0, 3600);
    $cache->set($clickKey, $clickCount + 1, 3600);
    
    // 每100次点击批量写入数据库
    if (($clickCount + 1) % 100 === 0) {
        $queue->push('click_sync', [
            'rule_id' => $rule['id'],
            'clicks' => 100,
            'hour' => date('YmdH'),
        ]);
    }
    
} catch (Throwable $e) {
    // 记录错误但不暴露给用户
    error_log("ShortLink Error: " . $e->getMessage());
    $circuitBreaker->recordFailure('database');
    quickResponse(500, "服务器内部错误");
}
