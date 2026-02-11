<?php
/**
 * Prometheus 指标端点
 * 
 * 安全增强:
 *   - IP白名单校验
 *   - Bearer Token鉴权
 */

// ==================== 安全校验 ====================

/**
 * 获取客户端真实IP
 */
function getClientIpMetrics(): string {
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
function isMetricsIpAllowed(string $ip): bool {
    $whitelist = getenv('METRICS_IP_WHITELIST') ?: getenv('HEALTH_IP_WHITELIST') ?: '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16';
    $allowedIps = array_map('trim', explode(',', $whitelist));
    
    foreach ($allowedIps as $allowed) {
        if (strpos($allowed, '/') !== false) {
            if (metricsIpInCidr($ip, $allowed)) {
                return true;
            }
        } else {
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
function metricsIpInCidr(string $ip, string $cidr): bool {
    list($subnet, $bits) = explode('/', $cidr);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - (int)$bits);
        return ($ip & $mask) === ($subnet & $mask);
    }
    return false;
}

// 获取客户端IP
$clientIp = getClientIpMetrics();

// IP白名单校验
if (!isMetricsIpAllowed($clientIp)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "Forbidden\n";
    exit;
}

// 检查认证（必须）
$authToken = getenv('METRICS_AUTH_TOKEN');
if ($authToken) {
    $requestToken = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $requestToken = str_replace('Bearer ', '', $requestToken);
    
    if (!hash_equals($authToken, $requestToken)) {
        http_response_code(401);
        header('Content-Type: text/plain');
        echo "Unauthorized\n";
        exit;
    }
}

// ==================== 导出指标 ====================

require_once __DIR__ . '/../backend/core/prometheus.php';

// 设置正确的 Content-Type
header('Content-Type: text/plain; version=0.0.4; charset=utf-8');

// 导出指标
$metrics = PrometheusMetrics::getInstance();
echo $metrics->export();
