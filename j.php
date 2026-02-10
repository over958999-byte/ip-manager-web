<?php
/**
 * 统一跳转入口
 * 支持：
 *   1. IP跳转：直接访问 IP 时触发
 *   2. 短码跳转：访问 /j/CODE 或 /j.php?c=CODE 触发
 */

require_once __DIR__ . '/backend/core/database.php';
require_once __DIR__ . '/backend/core/jump.php';

// 获取数据库和跳转服务实例
$db = Database::getInstance();
$pdo = $db->getPDO();
$jumpService = new JumpService($pdo);

// 获取客户端真实IP
function getVisitorIp() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    }
    return trim($ip);
}

/**
 * 获取IP国家代码
 */
function getIpCountryCode($ip) {
    global $db;
    
    if (in_array($ip, ['127.0.0.1', '::1', 'localhost']) || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
        return 'LOCAL';
    }
    
    $cached = $db->getIpCountryCache($ip);
    if ($cached) {
        return $cached;
    }
    
    $url = "http://ip-api.com/json/{$ip}?fields=status,countryCode,country";
    $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $context);
    
    $countryCode = 'UNKNOWN';
    $countryName = '未知';
    
    if ($response) {
        $data = json_decode($response, true);
        if ($data && $data['status'] === 'success') {
            $countryCode = $data['countryCode'] ?? 'UNKNOWN';
            $countryName = $data['country'] ?? '未知';
        }
    }
    
    $db->setIpCountryCache($ip, $countryCode, $countryName);
    
    return $countryCode;
}

/**
 * 检测设备类型
 */
function detectDeviceType() {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    if (preg_match('/iphone|ipad|ipod/i', $ua)) {
        return 'ios';
    }
    if (preg_match('/android/i', $ua)) {
        return 'android';
    }
    if (preg_match('/windows|macintosh|mac os x|linux/i', $ua) && !preg_match('/android|mobile/i', $ua)) {
        return 'desktop';
    }
    if (preg_match('/mobile|webos|blackberry|opera mini|opera mobi|iemobile|windows phone/i', $ua)) {
        return 'mobile';
    }
    
    return 'desktop';
}

/**
 * 显示错误页面
 */
function showErrorPage($message, $code = 403) {
    http_response_code($code);
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>访问受限</title>
        <style>
            body { font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background: #f5f5f5; }
            .container { text-align: center; padding: 40px; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            h1 { color: #e74c3c; margin-bottom: 10px; }
            p { color: #666; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>⚠️ 访问受限</h1>
            <p><?php echo htmlspecialchars($message); ?></p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/**
 * 显示默认页面
 */
function showDefaultPage($ip = '') {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>欢迎</title>
        <style>
            body { font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
            .container { text-align: center; }
            h1 { font-size: 2.5em; }
            p { font-size: 1.2em; opacity: 0.8; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>欢迎访问</h1>
            <?php if ($ip): ?>
            <p>当前IP: <?php echo htmlspecialchars($ip); ?></p>
            <p>此IP暂未配置跳转</p>
            <?php else: ?>
            <p>页面不存在</p>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/**
 * 异步记录访问统计
 */
function recordClickAsync($jumpService, $rule, $visitorInfo) {
    // 确保输出已发送
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
    
    // 关闭连接后继续执行
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    
    $jumpService->recordClick(
        $rule['id'],
        $rule['rule_type'],
        $rule['match_key'],
        $visitorInfo
    );
}

// ==================== 主逻辑 ====================

$rule = null;
$ruleType = null;
$matchKey = null;

// 1. 检查是否是短码跳转 (/j/CODE 或 /CODE 或 ?code=CODE)
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$code = null;

// 支持 /j/CODE 格式
if (preg_match('#^/j/([a-zA-Z0-9_-]+)#', $requestUri, $matches)) {
    $code = $matches[1];
}
// 支持直接 /CODE 格式 (4-10位字母数字)
elseif (preg_match('#^/([a-zA-Z0-9]{4,10})(?:\?|$)#', $requestUri, $matches)) {
    $code = $matches[1];
}
// 支持 ?code=CODE 或 ?c=CODE 格式
elseif (isset($_GET['code'])) {
    $code = $_GET['code'];
}
elseif (isset($_GET['c'])) {
    $code = $_GET['c'];
}

if ($code) {
    // 短码跳转模式
    $ruleType = JumpService::TYPE_CODE;
    $matchKey = $code;
    $rule = $jumpService->getByKey(JumpService::TYPE_CODE, $code);
    
    if (!$rule) {
        showErrorPage('短链接不存在或已过期', 404);
    }
} else {
    // 2. IP跳转模式
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $hostWithoutPort = preg_replace('/:\d+$/', '', $host);
    $serverIp = $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? '';
    
    // 先尝试 HOST
    $rule = $jumpService->getByKey(JumpService::TYPE_IP, $hostWithoutPort);
    if ($rule) {
        $matchKey = $hostWithoutPort;
    } else {
        // 再尝试 SERVER_ADDR
        $rule = $jumpService->getByKey(JumpService::TYPE_IP, $serverIp);
        if ($rule) {
            $matchKey = $serverIp;
        }
    }
    
    if ($rule) {
        $ruleType = JumpService::TYPE_IP;
    } else {
        // 没有匹配的规则，显示默认页面
        showDefaultPage($hostWithoutPort ?: $serverIp);
    }
}

// 验证规则有效性
if (!$jumpService->isValid($rule)) {
    if ($rule['expire_type'] === 'datetime') {
        showErrorPage('链接已过期', 410);
    } elseif ($rule['expire_type'] === 'clicks') {
        showErrorPage('链接已达到最大访问次数', 410);
    } else {
        showErrorPage('链接已禁用', 403);
    }
}

// 获取访问者信息
$visitorIp = getVisitorIp();
$deviceType = detectDeviceType();
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$uaInfo = JumpService::parseUserAgent($ua);

// 检查设备限制 (仅IP跳转)
if ($ruleType === JumpService::TYPE_IP) {
    $blockedDevice = $jumpService->checkDeviceBlock($rule, $deviceType);
    if ($blockedDevice) {
        $deviceNames = [
            'desktop' => '桌面设备',
            'ios' => 'iOS设备',
            'android' => 'Android设备'
        ];
        showErrorPage('该设备类型(' . ($deviceNames[$blockedDevice] ?? $blockedDevice) . ')已被禁止访问');
    }
    
    // 检查国家白名单
    $countryCode = getIpCountryCode($visitorIp);
    $blockedCountry = $jumpService->checkCountryBlock($rule, $countryCode);
    if ($blockedCountry) {
        showErrorPage('您所在的地区(' . $blockedCountry . ')不允许访问');
    }
}

// 准备访问者信息
$visitorInfo = [
    'ip' => $visitorIp,
    'country' => getIpCountryCode($visitorIp),
    'device_type' => $uaInfo['device_type'],
    'os' => $uaInfo['os'],
    'browser' => $uaInfo['browser'],
    'user_agent' => substr($ua, 0, 500),
    'referer' => substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500)
];

// 注册关闭函数，异步记录统计
register_shutdown_function(function() use ($jumpService, $rule, $visitorInfo) {
    recordClickAsync($jumpService, $rule, $visitorInfo);
});

// 执行跳转
header("Location: " . $rule['target_url'], true, 302);
exit;
