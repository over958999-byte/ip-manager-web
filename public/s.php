<?php
/**
 * 短链跳转入口 - 实时统计版
 */

// 启动时间记录
define('START_TIME', microtime(true));

// 错误处理
error_reporting(0);
set_error_handler(function($errno, $errstr) {});

// 引入核心组件
require_once __DIR__ . '/../backend/core/database.php';
require_once __DIR__ . '/../backend/core/jump.php';

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
 * 获取客户端IP
 */
function getClientIp(): string {
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && $_SERVER['HTTP_X_FORWARDED_FOR'] !== '') {
        return trim(strtok($_SERVER['HTTP_X_FORWARDED_FOR'], ','));
    } elseif (isset($_SERVER['HTTP_X_REAL_IP']) && $_SERVER['HTTP_X_REAL_IP'] !== '') {
        return trim($_SERVER['HTTP_X_REAL_IP']);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * 获取设备类型
 */
function getDeviceType(): string {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (stripos($ua, 'iphone') !== false || stripos($ua, 'ipad') !== false) {
        return 'ios';
    }
    if (stripos($ua, 'android') !== false) {
        return 'android';
    }
    if ((stripos($ua, 'windows') !== false || stripos($ua, 'macintosh') !== false)
        && stripos($ua, 'mobile') === false) {
        return 'desktop';
    }
    return 'mobile';
}

/**
 * 解析短码
 */
function parseShortCode(): ?string {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);
    $code = ltrim($path, '/');
    if (strpos($code, '/') !== false) {
        $code = basename($code);
    }
    if (preg_match('/^[0-9A-Za-z]{3,20}$/', $code)) {
        return $code;
    }
    return null;
}

// ==================== 主流程 ====================

try {
    // 1. 解析短码
    $code = parseShortCode();
    if ($code === null) {
        quickResponse(404, "无效的短链");
    }

    $clientIp = getClientIp();

    // 2. 获取数据库连接和规则
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    $jumpService = new JumpService($pdo);
    
    $rule = $jumpService->getByKey('code', $code, false);

    // 3. 规则检查
    if ($rule === null) {
        quickResponse(404, "短链不存在");
    }

    if (empty($rule['enabled'])) {
        quickResponse(404, "短链已禁用");
    }

    // 4. 过期检查
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

    // 5. 设备拦截检查
    $deviceType = getDeviceType();
    if (($deviceType === 'desktop' && $rule['block_desktop']) ||
        ($deviceType === 'ios' && $rule['block_ios']) ||
        ($deviceType === 'android' && $rule['block_android'])) {
        quickResponse(403, "您的设备类型不允许访问");
    }

    // 6. 国家白名单检查
    if ($rule['country_whitelist_enabled'] && $rule['country_whitelist']) {
        // 本地IP跳过检查
        $isLocalIp = (strpos($clientIp, '192.168.') === 0 || strpos($clientIp, '10.') === 0 || $clientIp === '127.0.0.1');
        if ($isLocalIp === false) {
            $ctx = stream_context_create(['http' => ['timeout' => 0.5]]);
            $resp = @file_get_contents("http://ip-api.com/json/{$clientIp}?fields=countryCode", false, $ctx);
            $countryCode = 'UNKNOWN';
            if ($resp) {
                $data = json_decode($resp, true);
                $countryCode = $data['countryCode'] ?? 'UNKNOWN';
            }
            
            $allowed = is_array($rule['country_whitelist'])
                ? $rule['country_whitelist']
                : json_decode($rule['country_whitelist'], true) ?? [];
            $allowed = array_map('strtoupper', $allowed);
            
            if (in_array(strtoupper($countryCode), $allowed) === false) {
                quickResponse(403, "您所在的地区不允许访问");
            }
        }
    }

    // 7. 发送重定向响应
    $targetUrl = $rule['target_url'];
    if (preg_match('/^https?:\/\//i', $targetUrl) === 0) {
        $targetUrl = 'https://' . $targetUrl;
    }

    header("Location: {$targetUrl}", true, 302);
    header("X-Response-Time: " . round((microtime(true) - START_TIME) * 1000, 2) . "ms");

    // 立即结束响应
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        ob_end_flush();
        flush();
    }

    // ==================== 后台任务（实时更新）====================

    // 8. 实时更新点击统计
    $stmt = $pdo->prepare("UPDATE jump_rules SET total_clicks = total_clicks + 1, last_access_at = NOW() WHERE id = ?");
    $stmt->execute([$rule['id']]);

    // 9. 更新独立访客（基于IP去重，同一IP每天只计算一次）
    $visitorKey = 'visitor:' . $rule['id'] . ':' . date('Ymd') . ':' . md5($clientIp);
    $cacheFile = sys_get_temp_dir() . '/' . md5($visitorKey) . '.tmp';
    
    if (file_exists($cacheFile) === false) {
        // 新访客，更新UV
        @file_put_contents($cacheFile, '1');
        $stmt = $pdo->prepare("UPDATE jump_rules SET unique_visitors = unique_visitors + 1 WHERE id = ?");
        $stmt->execute([$rule['id']]);
    }

} catch (Throwable $e) {
    error_log("ShortLink Error: " . $e->getMessage());
    quickResponse(500, "服务器内部错误");
}
