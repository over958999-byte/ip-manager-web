<?php
/**
 * API v2 入口点
 * 使用新的 MVC 路由系统
 */

// 错误处理
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 设置响应头
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-API-Token, X-CSRF-Token');
header('Access-Control-Max-Age: 86400');

// 处理 OPTIONS 预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 启动会话（配置安全的 session 参数）
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
               || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
    
    // 检查是否有 remember_token cookie
    $sessionLifetime = 0; // 默认关闭浏览器后失效
    if (!empty($_COOKIE['remember_token'])) {
        $sessionLifetime = 7 * 24 * 3600; // 7天
    }
    
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax' // 使用 Lax 以支持正常的导航请求
    ]);
    
    session_start();
}

// 加载核心组件
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/router.php';
require_once __DIR__ . '/../core/container.php';
require_once __DIR__ . '/../core/utils.php';
require_once __DIR__ . '/../core/redis.php';
require_once __DIR__ . '/../core/middleware.php';
require_once __DIR__ . '/../core/prometheus.php';

// 自动加载控制器
spl_autoload_register(function($class) {
    $controllerPath = __DIR__ . '/controllers/' . $class . '.php';
    if (file_exists($controllerPath)) {
        require_once $controllerPath;
    }
});

// 全局异常处理
set_exception_handler(function(Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '服务器内部错误',
        'error' => defined('DEBUG') && DEBUG ? $e->getMessage() : null
    ]);
    
    // 记录错误日志
    error_log('[API Error] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
});

// 获取路由器实例
$router = Router::getInstance();

// 全局中间件
$router->middleware([
    new CorsMiddleware(['*']),           // CORS 跨域
    new SecurityHeadersMiddleware(),      // 安全响应头
    new RateLimitMiddleware(120, 60),     // 限流: 每分钟120次请求
    new LogMiddleware(),                  // 请求日志
]);

// 设置路由前缀
$router->group(['prefix' => '/api/v2'], function($router) {
    // 加载路由定义
    $routeDefinition = require __DIR__ . '/routes.php';
    $routeDefinition($router);
});

// 处理旧版兼容性 - 支持 action 参数方式
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? $_POST['action'] ?? $input['action'] ?? '';

if (!empty($action)) {
    // 旧版 API 兼容模式 - 映射 action 到新路由
    $actionMap = [
        // Auth
        'login' => ['POST', '/api/v2/auth/login'],
        'logout' => ['POST', '/api/v2/auth/logout'],
        'check_login' => ['GET', '/api/v2/auth/check'],
        'get_csrf_token' => ['GET', '/api/v2/auth/csrf'],
        'change_password' => ['POST', '/api/v2/auth/change-password'],
        
        // Jump rules
        'jump_list' => ['GET', '/api/v2/jump-rules'],
        'jump_create' => ['POST', '/api/v2/jump-rules'],
        'jump_update' => ['PUT', '/api/v2/jump-rules/{id}'],
        'jump_delete' => ['DELETE', '/api/v2/jump-rules/{id}'],
        'jump_toggle' => ['POST', '/api/v2/jump-rules/{id}/toggle'],
        'jump_batch_create' => ['POST', '/api/v2/jump-rules/batch'],
        'jump_stats' => ['GET', '/api/v2/jump-rules/stats'],
        'jump_dashboard' => ['GET', '/api/v2/jump-rules/dashboard'],
        'jump_groups' => ['GET', '/api/v2/jump-rules/groups'],
        'jump_group_create' => ['POST', '/api/v2/jump-rules/groups'],
        'jump_group_delete' => ['DELETE', '/api/v2/jump-rules/groups/{id}'],
        
        // Shortlinks
        'shortlink_list' => ['GET', '/api/v2/shortlinks'],
        'shortlink_create' => ['POST', '/api/v2/shortlinks'],
        'shortlink_get' => ['GET', '/api/v2/shortlinks/{code}'],
        'shortlink_update' => ['PUT', '/api/v2/shortlinks/{id}'],
        'shortlink_delete' => ['DELETE', '/api/v2/shortlinks/{id}'],
        'shortlink_toggle' => ['POST', '/api/v2/shortlinks/{id}/toggle'],
        'shortlink_stats' => ['GET', '/api/v2/shortlinks/{id}/stats'],
        'shortlink_batch_create' => ['POST', '/api/v2/shortlinks/batch'],
        'shortlink_dashboard' => ['GET', '/api/v2/shortlinks/dashboard'],
        'shortlink_groups' => ['GET', '/api/v2/shortlinks/groups'],
        'shortlink_add_group' => ['POST', '/api/v2/shortlinks/groups'],
        'shortlink_delete_group' => ['DELETE', '/api/v2/shortlinks/groups/{id}'],
        'shortlink_config' => ['GET', '/api/v2/shortlinks/config'],
        
        // Domains
        'domain_list' => ['GET', '/api/v2/domains'],
        'domain_add' => ['POST', '/api/v2/domains'],
        'domain_update' => ['PUT', '/api/v2/domains/{id}'],
        'domain_delete' => ['DELETE', '/api/v2/domains/{id}'],
        'domain_check' => ['GET', '/api/v2/domains/check'],
        'domain_check_all' => ['POST', '/api/v2/domains/check-all'],
        
        // Cloudflare
        'cf_get_config' => ['GET', '/api/v2/cloudflare/config'],
        'cf_save_config' => ['POST', '/api/v2/cloudflare/config'],
        'cf_list_zones' => ['GET', '/api/v2/cloudflare/zones'],
        'cf_add_domain' => ['POST', '/api/v2/cloudflare/zones'],
        'cf_batch_add_domains' => ['POST', '/api/v2/cloudflare/zones/batch'],
        'cf_enable_https' => ['POST', '/api/v2/cloudflare/zones/{domain}/https'],
        'cf_remove_domain' => ['DELETE', '/api/v2/cloudflare/zones/{domain}'],
        'cf_get_dns_records' => ['GET', '/api/v2/cloudflare/zones/{zoneId}/dns'],
        'cf_add_dns_record' => ['POST', '/api/v2/cloudflare/zones/{zoneId}/dns'],
        'cf_update_dns_record' => ['PUT', '/api/v2/cloudflare/zones/{zoneId}/dns/{recordId}'],
        'cf_delete_dns_record' => ['DELETE', '/api/v2/cloudflare/zones/{zoneId}/dns/{recordId}'],
        'cf_get_zone_details' => ['GET', '/api/v2/cloudflare/zones/{zoneId}/details'],
        'cf_delete_zone' => ['DELETE', '/api/v2/cloudflare/zones/{zoneId}/full'],
        
        // IP Pool
        'get_ip_pool' => ['GET', '/api/v2/ip-pool'],
        'add_to_pool' => ['POST', '/api/v2/ip-pool'],
        'remove_from_pool' => ['DELETE', '/api/v2/ip-pool'],
        'clear_pool' => ['DELETE', '/api/v2/ip-pool/all'],
        'activate_from_pool' => ['POST', '/api/v2/ip-pool/activate'],
        'return_to_pool' => ['POST', '/api/v2/ip-pool/return'],
        
        // Antibot
        'get_antibot_stats' => ['GET', '/api/v2/antibot/stats'],
        'get_antibot_config' => ['GET', '/api/v2/antibot/config'],
        'update_antibot_config' => ['PUT', '/api/v2/antibot/config'],
        'antibot_unblock' => ['POST', '/api/v2/antibot/unblock'],
        'antibot_clear_blocks' => ['DELETE', '/api/v2/antibot/blocks'],
        'antibot_reset_stats' => ['POST', '/api/v2/antibot/reset-stats'],
        'antibot_add_blacklist' => ['POST', '/api/v2/antibot/blacklist'],
        'antibot_remove_blacklist' => ['DELETE', '/api/v2/antibot/blacklist/{ip}'],
        'antibot_add_whitelist' => ['POST', '/api/v2/antibot/whitelist'],
        'antibot_remove_whitelist' => ['DELETE', '/api/v2/antibot/whitelist/{ip}'],
        
        // IP Blacklist
        'ip_blacklist_stats' => ['GET', '/api/v2/ip-blacklist/stats'],
        'ip_blacklist_list' => ['GET', '/api/v2/ip-blacklist'],
        'ip_blacklist_add' => ['POST', '/api/v2/ip-blacklist'],
        'ip_blacklist_remove' => ['DELETE', '/api/v2/ip-blacklist/{id}'],
        'ip_blacklist_toggle' => ['PUT', '/api/v2/ip-blacklist/{id}/toggle'],
        'ip_blacklist_check' => ['GET', '/api/v2/ip-blacklist/check'],
        'ip_blacklist_import' => ['POST', '/api/v2/ip-blacklist/import'],
        'ip_blacklist_refresh' => ['POST', '/api/v2/ip-blacklist/refresh'],
        'ip_blacklist_sync_threat_intel' => ['POST', '/api/v2/ip-blacklist/sync-threat-intel'],
        
        // System
        'system_info' => ['GET', '/api/v2/system/info'],
        'system_check_update' => ['GET', '/api/v2/system/check-update'],
        'system_update' => ['POST', '/api/v2/system/update'],
        'get_stats' => ['GET', '/api/v2/system/stats'],
        'get_ip_stats' => ['GET', '/api/v2/system/stats/{ip}'],
        'clear_stats' => ['DELETE', '/api/v2/system/stats'],
        'export' => ['GET', '/api/v2/system/export'],
        'import' => ['POST', '/api/v2/system/import'],
        
        // API Token
        'api_token_list' => ['GET', '/api/v2/api-tokens'],
        'api_token_create' => ['POST', '/api/v2/api-tokens'],
        'api_token_update' => ['PUT', '/api/v2/api-tokens/{id}'],
        'api_token_delete' => ['DELETE', '/api/v2/api-tokens/{id}'],
        'api_token_regenerate' => ['POST', '/api/v2/api-tokens/{id}/regenerate'],
        'api_token_logs' => ['GET', '/api/v2/api-tokens/logs'],
        
        // External API
        'external_create_shortlink' => ['POST', '/api/v2/external/shortlinks'],
        'external_list_shortlinks' => ['GET', '/api/v2/external/shortlinks'],
        'external_get_shortlink' => ['GET', '/api/v2/external/shortlinks/{code}'],
        'external_delete_shortlink' => ['DELETE', '/api/v2/external/shortlinks/{code}'],
        'external_get_stats' => ['GET', '/api/v2/external/shortlinks/{code}/stats'],
        'external_batch_stats' => ['POST', '/api/v2/external/shortlinks/batch-stats'],
        'external_list_domains' => ['GET', '/api/v2/external/domains'],
        
        // Legacy IP Redirect (兼容旧版)
        'get_redirects' => ['GET', '/api/v2/jump-rules'],
        'add_redirect' => ['POST', '/api/v2/jump-rules'],
        'update_redirect' => ['PUT', '/api/v2/jump-rules/{id}'],
        'delete_redirect' => ['DELETE', '/api/v2/jump-rules/{id}'],
        'toggle_redirect' => ['POST', '/api/v2/jump-rules/{id}/toggle'],
        'batch_add' => ['POST', '/api/v2/jump-rules/batch'],
        
        // Domain Safety
        'domain_safety_check' => ['POST', '/api/v2/domains/safety/check'],
        'domain_safety_check_all' => ['POST', '/api/v2/domains/safety/check-all'],
        'domain_safety_stats' => ['GET', '/api/v2/domains/safety/stats'],
        'domain_safety_logs' => ['GET', '/api/v2/domains/safety/logs'],
        'domain_safety_config' => ['GET', '/api/v2/domains/safety/config'],
        
        // Namemart
        'nm_get_config' => ['GET', '/api/v2/namemart/config'],
        'nm_save_config' => ['POST', '/api/v2/namemart/config'],
        'nm_check_domains' => ['POST', '/api/v2/namemart/check'],
        'nm_register_domains' => ['POST', '/api/v2/namemart/register'],
        'nm_get_task_status' => ['GET', '/api/v2/namemart/task/{taskNo}'],
        'nm_get_domain_info' => ['GET', '/api/v2/namemart/domain/{domain}'],
        'nm_update_dns' => ['POST', '/api/v2/namemart/dns'],
        'nm_create_contact' => ['POST', '/api/v2/namemart/contact'],
        'nm_get_contact_info' => ['GET', '/api/v2/namemart/contact/{contactId}'],
        
        // Dashboard
        'dashboard_stats' => ['GET', '/api/v2/dashboard/stats'],
        'dashboard_trend' => ['GET', '/api/v2/dashboard/trend'],
        'realtime_logs' => ['GET', '/api/v2/dashboard/realtime-logs'],
        'system_status' => ['GET', '/api/v2/dashboard/system-status'],
        
        // System Health & Monitoring
        'system_health' => ['GET', '/api/v2/system/health'],
        'prometheus_metrics' => ['GET', '/api/v2/system/metrics'],
        'cache_stats' => ['GET', '/api/v2/system/cache-stats'],
        
        // Import/Export
        'export_data' => ['POST', '/api/v2/import-export/export'],
        'import_data' => ['POST', '/api/v2/import-export/import'],
        'export_template' => ['GET', '/api/v2/import-export/template'],
        
        // Webhooks
        'webhooks_list' => ['GET', '/api/v2/webhooks'],
        'webhook_create' => ['POST', '/api/v2/webhooks'],
        'webhook_update' => ['PUT', '/api/v2/webhooks/{id}'],
        'webhook_delete' => ['DELETE', '/api/v2/webhooks/{id}'],
        'webhook_test' => ['POST', '/api/v2/webhooks/{id}/test'],
        'webhook_logs' => ['GET', '/api/v2/webhooks/logs'],
        
        // Audit Logs
        'audit_logs' => ['GET', '/api/v2/audit/logs'],
        'audit_logs_export' => ['POST', '/api/v2/audit/export'],
        
        // User Management
        'users_list' => ['GET', '/api/v2/users'],
        'user_create' => ['POST', '/api/v2/users'],
        'user_update' => ['PUT', '/api/v2/users/{id}'],
        'user_delete' => ['DELETE', '/api/v2/users/{id}'],
        'user_reset_password' => ['POST', '/api/v2/users/{id}/reset-password'],
        
        // TOTP
        'totp_status' => ['GET', '/api/v2/auth/totp/status'],
        'totp_enable' => ['POST', '/api/v2/auth/totp/enable'],
        'totp_verify' => ['POST', '/api/v2/auth/totp/verify'],
        'totp_disable' => ['POST', '/api/v2/auth/totp/disable'],
        
        // API Keys
        'api_keys_list' => ['GET', '/api/v2/api-tokens'],
        'api_key_create' => ['POST', '/api/v2/api-tokens'],
        'api_key_update' => ['PUT', '/api/v2/api-tokens/{id}'],
        'api_key_delete' => ['DELETE', '/api/v2/api-tokens/{id}'],
        'api_key_regenerate' => ['POST', '/api/v2/api-tokens/{id}/regenerate'],
        
        // Backups
        'backup_list' => ['GET', '/api/v2/backups'],
        'backup_create' => ['POST', '/api/v2/backups'],
        'backup_restore' => ['POST', '/api/v2/backups/restore'],
        'backup_download' => ['GET', '/api/v2/backups/{filename}/download'],
        'backup_delete' => ['DELETE', '/api/v2/backups/{filename}'],
    ];
    
    if (isset($actionMap[$action])) {
        // 设置新的请求方法和 URI
        [$method, $uri] = $actionMap[$action];
        
        // 替换 URI 中的参数
        if (preg_match_all('/\{(\w+)\}/', $uri, $matches)) {
            foreach ($matches[1] as $param) {
                $value = $_GET[$param] ?? $input[$param] ?? '';
                $uri = str_replace('{' . $param . '}', $value, $uri);
            }
        }
        
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
    } else {
        // 未知 action，返回错误
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '未知操作: ' . $action]);
        exit;
    }
}

// 分发请求
$router->dispatch();
