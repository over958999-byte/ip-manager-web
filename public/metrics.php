<?php
/**
 * Prometheus 指标端点
 */

require_once __DIR__ . '/../backend/core/prometheus.php';

// 设置正确的 Content-Type
header('Content-Type: text/plain; version=0.0.4; charset=utf-8');

// 检查认证（可选）
$authToken = getenv('METRICS_AUTH_TOKEN');
if ($authToken) {
    $requestToken = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $requestToken = str_replace('Bearer ', '', $requestToken);
    
    if ($requestToken !== $authToken) {
        http_response_code(401);
        echo "Unauthorized\n";
        exit;
    }
}

// 导出指标
$metrics = PrometheusMetrics::getInstance();
echo $metrics->export();
