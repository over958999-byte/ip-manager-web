<?php
/**
 * 服务器验证端点
 * 用于验证域名是否正确解析到本服务器（支持Cloudflare等CDN）
 */

// 获取服务器公网IP
function getServerPublicIp() {
    $cacheFile = '/tmp/server_public_ip.cache';
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        return trim(file_get_contents($cacheFile));
    }
    
    $services = [
        'https://api.ipify.org',
        'https://ipinfo.io/ip',
        'https://icanhazip.com'
    ];
    
    foreach ($services as $service) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $service,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $ip = trim(curl_exec($ch));
        curl_close($ch);
        
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            file_put_contents($cacheFile, $ip);
            return $ip;
        }
    }
    
    return $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
}

// 生成验证token（与api.php中的逻辑一致）
$serverIp = getServerPublicIp();
$verifyToken = md5($serverIp . '_ip_manager_' . date('Ymd'));

// 只响应验证请求
header('Content-Type: text/plain');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo $verifyToken;
