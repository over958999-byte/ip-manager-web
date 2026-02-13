<?php
/**
 * IP跳转处理脚本 - 使用 JumpService
 * 当用户访问服务器IP时，根据 jump_rules 表配置跳转到指定页面
 */

require_once __DIR__ . '/../backend/core/database.php';
require_once __DIR__ . '/../backend/core/jump.php';

// 获取数据库实例
$db = Database::getInstance();
$jumpService = new JumpService($db->getPdo());

// 预先确定目标IP和跳转配置
$_pre_host = $_SERVER['HTTP_HOST'] ?? '';
$_pre_host_without_port = preg_replace('/:\d+$/', '', $_pre_host);
$_pre_server_ip = $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? '';
$_pre_server_port = $_SERVER['SERVER_PORT'] ?? '80';
$_pre_matched_rule = null;
$_pre_target_url = null;

// 从 jump_rules 表查找匹配的跳转配置
// 先尝试带端口匹配
$rule = $jumpService->getByKey('ip', $_pre_host);
if (!$rule || !$rule['enabled']) {
    // 尝试不带端口
    $rule = $jumpService->getByKey('ip', $_pre_host_without_port);
}
if (!$rule || !$rule['enabled']) {
    // 尝试服务器IP
    $rule = $jumpService->getByKey('ip', $_pre_server_ip);
}
if (!$rule || !$rule['enabled']) {
    // 尝试服务器IP:端口
    $rule = $jumpService->getByKey('ip', $_pre_server_ip . ':' . $_pre_server_port);
}

if ($rule && $rule['enabled']) {
    // 检查端口匹配逻辑
    if ($rule['port_match_enabled']) {
        // 开启端口匹配，match_key 必须包含端口才有效
        if (strpos($rule['match_key'], ':') !== false) {
            $_pre_matched_rule = $rule;
            $_pre_target_url = $rule['target_url'];
        }
    } else {
        $_pre_matched_rule = $rule;
        $_pre_target_url = $rule['target_url'];
    }
}

// 引入反爬虫模块
require_once __DIR__ . '/antibot.php';

// 执行反爬虫检测
$antibot = new AntiBot();

// 设置被访问的目标IP
if ($_pre_matched_rule) {
    $antibot->setTargetIp($_pre_matched_rule['match_key']);
}

$checkResult = $antibot->check();

if (!$checkResult['allowed']) {
    $antibot->block($checkResult);
    exit;
}

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

// 加载 GeoIP 服务（如果可用）
$geoIpService = null;
$geoIpFile = __DIR__ . '/../backend/core/geoip.php';
if (file_exists($geoIpFile)) {
    require_once $geoIpFile;
    $geoIpService = GeoIpService::getInstance();
}

/**
 * 获取IP国家代码
 */
function getIpCountryCode($ip) {
    global $db, $geoIpService;
    
    if (in_array($ip, ['127.0.0.1', '::1', 'localhost']) || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
        return 'LOCAL';
    }
    
    if ($geoIpService) {
        return $geoIpService->getCountryCode($ip);
    }
    
    $cached = $db->getIpCountryCache($ip);
    if ($cached) {
        return $cached;
    }
    
    // 使用ip-api.com
    $url = "http://ip-api.com/json/{$ip}?fields=status,countryCode,country";
    $context = stream_context_create(['http' => ['timeout' => 2]]);
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
    
    return 'desktop';
}

// 主逻辑
$target_url = $_pre_target_url;
$matched_rule = $_pre_matched_rule;

// 检查规则是否有效
if ($matched_rule && !$jumpService->isValid($matched_rule)) {
    $matched_rule = null;
    $target_url = null;
}

// 检查设备拦截
$blocked_device = null;
if ($matched_rule) {
    $blocked_device = $jumpService->checkDeviceBlock($matched_rule, detectDeviceType());
}

// 检查国家白名单
$blocked_country = null;
if ($matched_rule && $matched_rule['country_whitelist_enabled']) {
    $visitor_ip = getVisitorIp();
    $visitor_country = getIpCountryCode($visitor_ip);
    $blocked_country = $jumpService->checkCountryBlock($matched_rule, $visitor_country);
}

if ($target_url && $matched_rule && !$blocked_device && !$blocked_country) {
    // 执行跳转
    $visitor_ip = getVisitorIp();
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $deviceInfo = JumpService::parseUserAgent($ua);
    
    // 记录点击统计
    $visitorInfo = [
        'ip' => $visitor_ip,
        'country' => getIpCountryCode($visitor_ip),
        'device_type' => $deviceInfo['device_type'],
        'os' => $deviceInfo['os'],
        'browser' => $deviceInfo['browser'],
        'user_agent' => substr($ua, 0, 500),
        'referer' => substr($referer, 0, 500)
    ];
    
    // 异步记录（使用 register_shutdown_function）
    register_shutdown_function(function() use ($jumpService, $matched_rule, $visitorInfo) {
        $jumpService->recordClick(
            $matched_rule['id'],
            $matched_rule['rule_type'],
            $matched_rule['match_key'],
            $visitorInfo
        );
    });
    
    header("Location: " . $target_url, true, 302);
    exit;
} elseif ($blocked_country) {
    // 国家不在白名单中
    $antibot->block([
        'allowed' => false,
        'reason' => 'country_block',
        'message' => '您所在的地区(' . $blocked_country . ')不允许访问',
        'http_code' => 403
    ]);
    exit;
} elseif ($blocked_device) {
    // 设备被拦截
    $device_names = [
        'desktop' => '桌面设备',
        'ios' => 'iOS设备',
        'android' => 'Android设备'
    ];
    $antibot->block([
        'allowed' => false,
        'reason' => 'device_block',
        'message' => '该设备类型(' . ($device_names[$blocked_device] ?? $blocked_device) . ')已被禁止访问',
        'http_code' => 403
    ]);
    exit;
} else {
    // 没有配置跳转，显示默认页面
    $host_without_port = $_pre_host_without_port;
    $server_ip = $_pre_server_ip;
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>欢迎</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                display: flex;
                justify-content: center;
                align-items: center;
                height: 100vh;
                margin: 0;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
            }
            .container {
                text-align: center;
            }
            h1 { font-size: 2.5em; }
            p { font-size: 1.2em; opacity: 0.8; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>欢迎访问</h1>
            <p>当前IP: <?php echo htmlspecialchars($host_without_port ?: $server_ip); ?></p>
            <p>此IP暂未配置跳转</p>
        </div>
    </body>
    </html>
    <?php
}
?>
