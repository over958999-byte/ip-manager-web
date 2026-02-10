<?php
/**
 * IP跳转处理脚本 - 数据库版本
 * 当用户访问服务器IP时，根据配置跳转到指定页面
 */

require_once __DIR__ . '/../backend/core/database.php';

// 获取数据库实例
$db = Database::getInstance();

// 预先确定目标IP和跳转配置
$_pre_host = $_SERVER['HTTP_HOST'] ?? '';
$_pre_host_without_port = preg_replace('/:\d+$/', '', $_pre_host);
$_pre_server_ip = $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? '';
$_pre_matched_ip = null;
$_pre_matched_config = null;
$_pre_target_url = null;

// 从数据库查找匹配的跳转配置
$redirect = $db->getRedirect($_pre_host_without_port);
if ($redirect && $redirect['enabled']) {
    $_pre_matched_ip = $_pre_host_without_port;
    $_pre_matched_config = $redirect;
    $_pre_target_url = $redirect['url'];
} else {
    $redirect = $db->getRedirect($_pre_server_ip);
    if ($redirect && $redirect['enabled']) {
        $_pre_matched_ip = $_pre_server_ip;
        $_pre_matched_config = $redirect;
        $_pre_target_url = $redirect['url'];
    }
}

// 引入反爬虫模块
require_once __DIR__ . '/antibot.php';

// 执行反爬虫检测
$antibot = new AntiBot();

// 设置被访问的目标IP
if ($_pre_matched_ip) {
    $antibot->setTargetIp($_pre_matched_ip);
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
 * 获取IP国家代码（用于白名单检测）
 * 使用新的 GeoIP 服务，支持多源查询和失败重试
 */
function getIpCountryCode($ip) {
    global $db, $geoIpService;
    
    // 本地IP不查询
    if (in_array($ip, ['127.0.0.1', '::1', 'localhost']) || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
        return 'LOCAL';
    }
    
    // 使用新的 GeoIP 服务
    if ($geoIpService) {
        return $geoIpService->getCountryCode($ip);
    }
    
    // 降级：使用旧的查询方式
    // 检查数据库缓存
    $cached = $db->getIpCountryCache($ip);
    if ($cached) {
        return $cached;
    }
    
    // 使用ip-api.com免费接口（带重试）
    $maxRetries = 2;
    $countryCode = 'UNKNOWN';
    $countryName = '未知';
    
    for ($retry = 0; $retry <= $maxRetries; $retry++) {
        $url = "http://ip-api.com/json/{$ip}?fields=status,countryCode,country";
        $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
        $response = @file_get_contents($url, false, $context);
        
        if ($response) {
            $data = json_decode($response, true);
            if ($data && $data['status'] === 'success') {
                $countryCode = $data['countryCode'] ?? 'UNKNOWN';
                $countryName = $data['country'] ?? '未知';
                break;
            }
        }
        
        if ($retry < $maxRetries) {
            usleep(100000); // 100ms 后重试
        }
    }
    
    // 保存到数据库缓存
    $db->setIpCountryCache($ip, $countryCode, $countryName);
    
    return $countryCode;
}

/**
 * 获取IP国家名称
 */
function getIpCountry($ip) {
    global $db, $geoIpService;
    
    // 本地IP不查询
    if (in_array($ip, ['127.0.0.1', '::1', 'localhost']) || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
        return '本地';
    }
    
    // 使用新的 GeoIP 服务
    if ($geoIpService) {
        return $geoIpService->getCountryName($ip);
    }
    
    // 降级：使用旧的查询方式
    // 检查数据库缓存
    $cached = $db->getIpCountryNameCache($ip);
    if ($cached) {
        return $cached;
    }
    
    // 调用getIpCountryCode会自动更新缓存
    getIpCountryCode($ip);
    
    $cached = $db->getIpCountryNameCache($ip);
    return $cached ?: '未知';
}

/**
 * 记录访问统计
 */
function recordVisit($target_ip, $visitor_ip) {
    global $db;
    $country = getIpCountry($visitor_ip);
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);
    $db->recordVisit($target_ip, $visitor_ip, $country, $ua);
}

/**
 * 异步记录访问统计
 */
function recordVisitAsync($target_ip, $visitor_ip) {
    // 确保输出已发送
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
    
    // 关闭连接后继续执行
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    
    recordVisit($target_ip, $visitor_ip);
}

/**
 * 检测设备类型
 */
function detectDeviceType() {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // iOS检测
    if (preg_match('/iphone|ipad|ipod/i', $ua)) {
        return 'ios';
    }
    
    // Android检测
    if (preg_match('/android/i', $ua)) {
        return 'android';
    }
    
    // 桌面设备检测
    if (preg_match('/windows|macintosh|mac os x|linux/i', $ua) && !preg_match('/android|mobile/i', $ua)) {
        return 'desktop';
    }
    
    // 其他移动设备
    if (preg_match('/mobile|webos|blackberry|opera mini|opera mobi|iemobile|windows phone/i', $ua)) {
        return 'mobile';
    }
    
    return 'desktop';
}

/**
 * 检查是否应该拦截设备
 */
function shouldBlockDevice($redirect_config) {
    $device_type = detectDeviceType();
    
    if ($device_type === 'desktop' && !empty($redirect_config['block_desktop'])) {
        return 'desktop';
    }
    
    if ($device_type === 'ios' && !empty($redirect_config['block_ios'])) {
        return 'ios';
    }
    
    if ($device_type === 'android' && !empty($redirect_config['block_android'])) {
        return 'android';
    }
    
    return false;
}

// 主逻辑
$target_url = $_pre_target_url;
$matched_ip = $_pre_matched_ip;
$matched_config = $_pre_matched_config;

// 检查设备拦截
$blocked_device = $matched_config ? shouldBlockDevice($matched_config) : false;

// 检查国家白名单
$blocked_country = false;
if ($matched_config && !empty($matched_config['country_whitelist_enabled']) && !empty($matched_config['country_whitelist'])) {
    $visitor_ip = getVisitorIp();
    $visitor_country = getIpCountryCode($visitor_ip);
    $allowed_countries = is_array($matched_config['country_whitelist']) 
        ? $matched_config['country_whitelist'] 
        : json_decode($matched_config['country_whitelist'], true) ?? [];
    $allowed_countries = array_map('strtoupper', $allowed_countries);
    
    if (!in_array(strtoupper($visitor_country), $allowed_countries)) {
        $blocked_country = $visitor_country;
    }
}

if ($target_url && $matched_config && !$blocked_device && !$blocked_country) {
    // 执行跳转
    $visitor_ip = getVisitorIp();
    
    // 注册关闭函数，异步记录统计
    register_shutdown_function(function() use ($matched_ip, $visitor_ip) {
        recordVisitAsync($matched_ip, $visitor_ip);
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
