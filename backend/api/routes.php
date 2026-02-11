<?php
/**
 * API 路由定义
 * 使用 RESTful 风格定义所有 API 路由
 */

use function Api\route;
use function Api\group;

return function($router) {
    
    // ==================== 认证相关 ====================
    $router->post('/auth/login', 'AuthController@login');
    $router->post('/auth/logout', 'AuthController@logout');
    $router->get('/auth/check', 'AuthController@checkLogin');
    $router->get('/auth/csrf', 'AuthController@getCsrfToken');
    $router->post('/auth/change-password', 'AuthController@changePassword');
    
    // ==================== 跳转规则 ====================
    $router->get('/jump-rules', 'JumpController@list');
    $router->post('/jump-rules', 'JumpController@create');
    $router->put('/jump-rules/{id}', 'JumpController@update');
    $router->delete('/jump-rules/{id}', 'JumpController@delete');
    $router->post('/jump-rules/{id}/toggle', 'JumpController@toggle');
    $router->post('/jump-rules/batch', 'JumpController@batchCreate');
    $router->get('/jump-rules/stats', 'JumpController@stats');
    $router->get('/jump-rules/dashboard', 'JumpController@dashboard');
    
    // 跳转规则分组
    $router->get('/jump-rules/groups', 'JumpController@groups');
    $router->post('/jump-rules/groups', 'JumpController@createGroup');
    $router->delete('/jump-rules/groups/{id}', 'JumpController@deleteGroup');
    
    // ==================== 短链接 ====================
    $router->get('/shortlinks', 'ShortlinkController@list');
    $router->post('/shortlinks', 'ShortlinkController@create');
    $router->get('/shortlinks/{code}', 'ShortlinkController@get');
    $router->put('/shortlinks/{id}', 'ShortlinkController@update');
    $router->delete('/shortlinks/{id}', 'ShortlinkController@delete');
    $router->post('/shortlinks/{id}/toggle', 'ShortlinkController@toggle');
    $router->get('/shortlinks/{id}/stats', 'ShortlinkController@stats');
    $router->post('/shortlinks/batch', 'ShortlinkController@batchCreate');
    $router->get('/shortlinks/dashboard', 'ShortlinkController@dashboard');
    $router->get('/shortlinks/config', 'ShortlinkController@config');
    
    // 短链接分组
    $router->get('/shortlinks/groups', 'ShortlinkController@groups');
    $router->post('/shortlinks/groups', 'ShortlinkController@createGroup');
    $router->delete('/shortlinks/groups/{id}', 'ShortlinkController@deleteGroup');
    
    // ==================== 域名管理 ====================
    $router->get('/domains', 'DomainController@list');
    $router->post('/domains', 'DomainController@create');
    $router->put('/domains/{id}', 'DomainController@update');
    $router->delete('/domains/{id}', 'DomainController@delete');
    $router->get('/domains/check', 'DomainController@check');
    $router->post('/domains/check-all', 'DomainController@checkAll');
    $router->post('/domains/{id}/safety-check', 'DomainController@safetyCheck');
    $router->get('/domains/safety/stats', 'DomainController@safetyStats');
    $router->get('/domains/safety/logs', 'DomainController@safetyLogs');
    
    // ==================== Cloudflare ====================
    $router->get('/cloudflare/config', 'CloudflareController@getConfig');
    $router->post('/cloudflare/config', 'CloudflareController@saveConfig');
    $router->get('/cloudflare/zones', 'CloudflareController@listZones');
    $router->post('/cloudflare/zones', 'CloudflareController@addDomain');
    $router->post('/cloudflare/zones/batch', 'CloudflareController@batchAddDomains');
    $router->post('/cloudflare/zones/{domain}/https', 'CloudflareController@enableHttps');
    $router->delete('/cloudflare/zones/{domain}', 'CloudflareController@removeDomain');
    $router->get('/cloudflare/zones/{zoneId}/dns', 'CloudflareController@getDnsRecords');
    $router->post('/cloudflare/zones/{zoneId}/dns', 'CloudflareController@addDnsRecord');
    $router->put('/cloudflare/zones/{zoneId}/dns/{recordId}', 'CloudflareController@updateDnsRecord');
    $router->delete('/cloudflare/zones/{zoneId}/dns/{recordId}', 'CloudflareController@deleteDnsRecord');
    $router->get('/cloudflare/zones/{zoneId}/details', 'CloudflareController@getZoneDetails');
    $router->delete('/cloudflare/zones/{zoneId}/full', 'CloudflareController@deleteZone');
    
    // ==================== IP 池管理 ====================
    $router->get('/ip-pool', 'IpPoolController@list');
    $router->post('/ip-pool', 'IpPoolController@add');
    $router->delete('/ip-pool', 'IpPoolController@remove');
    $router->delete('/ip-pool/all', 'IpPoolController@clear');
    $router->post('/ip-pool/activate', 'IpPoolController@activate');
    $router->post('/ip-pool/return', 'IpPoolController@returnToPool');
    
    // ==================== 反爬虫 ====================
    $router->get('/antibot/stats', 'AntibotController@stats');
    $router->get('/antibot/config', 'AntibotController@getConfig');
    $router->put('/antibot/config', 'AntibotController@updateConfig');
    $router->post('/antibot/unblock', 'AntibotController@unblock');
    $router->delete('/antibot/blocks', 'AntibotController@clearBlocks');
    $router->post('/antibot/reset-stats', 'AntibotController@resetStats');
    $router->post('/antibot/blacklist', 'AntibotController@addToBlacklist');
    $router->delete('/antibot/blacklist/{ip}', 'AntibotController@removeFromBlacklist');
    $router->post('/antibot/whitelist', 'AntibotController@addToWhitelist');
    $router->delete('/antibot/whitelist/{ip}', 'AntibotController@removeFromWhitelist');
    
    // ==================== IP 黑名单 ====================
    $router->get('/ip-blacklist/stats', 'IpBlacklistController@stats');
    $router->get('/ip-blacklist', 'IpBlacklistController@list');
    $router->post('/ip-blacklist', 'IpBlacklistController@add');
    $router->delete('/ip-blacklist/{id}', 'IpBlacklistController@remove');
    $router->put('/ip-blacklist/{id}/toggle', 'IpBlacklistController@toggle');
    $router->get('/ip-blacklist/check', 'IpBlacklistController@check');
    $router->post('/ip-blacklist/import', 'IpBlacklistController@import');
    $router->post('/ip-blacklist/refresh', 'IpBlacklistController@refresh');
    $router->post('/ip-blacklist/sync-threat-intel', 'IpBlacklistController@syncThreatIntel');
    
    // ==================== 系统管理 ====================
    $router->get('/system/info', 'SystemController@info');
    $router->get('/system/check-update', 'SystemController@checkUpdate');
    $router->post('/system/update', 'SystemController@update');
    $router->get('/system/stats', 'SystemController@getStats');
    $router->get('/system/stats/{ip}', 'SystemController@getIpStats');
    $router->delete('/system/stats', 'SystemController@clearStats');
    $router->get('/system/export', 'SystemController@export');
    $router->post('/system/import', 'SystemController@import');
    
    // ==================== API Token 管理 ====================
    $router->get('/api-tokens', 'ApiTokenController@list');
    $router->post('/api-tokens', 'ApiTokenController@create');
    $router->put('/api-tokens/{id}', 'ApiTokenController@update');
    $router->delete('/api-tokens/{id}', 'ApiTokenController@delete');
    $router->post('/api-tokens/{id}/regenerate', 'ApiTokenController@regenerate');
    $router->get('/api-tokens/logs', 'ApiTokenController@logs');
    
    // ==================== 外部 API ====================
    $router->post('/external/shortlinks', 'ExternalApiController@createShortlink');
    $router->get('/external/shortlinks', 'ExternalApiController@listShortlinks');
    $router->get('/external/shortlinks/{code}', 'ExternalApiController@getShortlink');
    $router->delete('/external/shortlinks/{code}', 'ExternalApiController@deleteShortlink');
    $router->get('/external/shortlinks/{code}/stats', 'ExternalApiController@getStats');
    $router->post('/external/shortlinks/batch-stats', 'ExternalApiController@batchStats');
    $router->get('/external/domains', 'ExternalApiController@listDomains');
    
    // ==================== 域名安全检测 ====================
    $router->post('/domains/safety/check', 'DomainController@safetyCheck');
    $router->post('/domains/safety/check-all', 'DomainController@safetyCheckAll');
    $router->get('/domains/safety/stats', 'DomainController@safetyStats');
    $router->get('/domains/safety/logs', 'DomainController@safetyLogs');
    $router->get('/domains/safety/config', 'DomainController@safetyConfig');
    $router->post('/domains/safety/config', 'DomainController@saveSafetyConfig');
    
    // ==================== Namemart 域名购买 ====================
    $router->get('/namemart/config', 'NamemartController@getConfig');
    $router->post('/namemart/config', 'NamemartController@saveConfig');
    $router->post('/namemart/check', 'NamemartController@checkDomains');
    $router->post('/namemart/register', 'NamemartController@registerDomains');
    $router->get('/namemart/task/{taskNo}', 'NamemartController@getTaskStatus');
    $router->get('/namemart/domain/{domain}', 'NamemartController@getDomainInfo');
    $router->post('/namemart/dns', 'NamemartController@updateDns');
    $router->post('/namemart/contact', 'NamemartController@createContact');
    $router->get('/namemart/contact/{contactId}', 'NamemartController@getContactInfo');
    
    // ==================== 数据大盘 ====================
    $router->get('/dashboard/stats', 'DashboardController@stats');
    $router->get('/dashboard/trend', 'DashboardController@trend');
    $router->get('/dashboard/realtime-logs', 'DashboardController@realtimeLogs');
    $router->get('/dashboard/system-status', 'DashboardController@systemStatus');
    
    // ==================== 批量导入导出 ====================
    $router->post('/import-export/export', 'ImportExportController@export');
    $router->post('/import-export/import', 'ImportExportController@import');
    $router->get('/import-export/template', 'ImportExportController@template');
    
    // ==================== Webhook 管理 ====================
    $router->get('/webhooks', 'WebhookController@list');
    $router->post('/webhooks', 'WebhookController@create');
    $router->put('/webhooks/{id}', 'WebhookController@update');
    $router->delete('/webhooks/{id}', 'WebhookController@delete');
    $router->post('/webhooks/{id}/test', 'WebhookController@test');
    $router->get('/webhooks/logs', 'WebhookController@logs');
    
    // ==================== 审计日志 ====================
    $router->get('/audit/logs', 'AuditController@logs');
    $router->post('/audit/export', 'AuditController@export');
    
    // ==================== 用户管理 ====================
    $router->get('/users', 'UserController@list');
    $router->post('/users', 'UserController@create');
    $router->put('/users/{id}', 'UserController@update');
    $router->delete('/users/{id}', 'UserController@delete');
    $router->post('/users/{id}/reset-password', 'UserController@resetPassword');
    
    // ==================== TOTP 双因素认证 ====================
    $router->get('/auth/totp/status', 'AuthController@totpStatus');
    $router->post('/auth/totp/enable', 'AuthController@totpEnable');
    $router->post('/auth/totp/verify', 'AuthController@totpVerify');
    $router->post('/auth/totp/disable', 'AuthController@totpDisable');
    
    // ==================== 备份管理 ====================
    $router->get('/backups', 'BackupController@list');
    $router->post('/backups', 'BackupController@create');
    $router->post('/backups/restore', 'BackupController@restore');
    $router->get('/backups/{filename}/download', 'BackupController@download');
    $router->delete('/backups/{filename}', 'BackupController@delete');
    
};
