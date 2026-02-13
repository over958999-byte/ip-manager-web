-- ==============================================
-- IP管理器数据库 - 完整合并版
-- 版本: 4.0
-- 合并自: database_full.sql + migrate_database_v2.sql
-- ==============================================
-- 此脚本包含:
-- 1. 完整数据库结构
-- 2. 初始数据
-- 3. 存储过程
-- 4. 定时事件
-- 5. 性能优化配置
-- ==============================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================
-- 第一部分: 基础配置表
-- =====================================================

-- 系统配置表
CREATE TABLE IF NOT EXISTS config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) NOT NULL,
    `value` TEXT,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 配置默认值
INSERT INTO config (`key`, `value`, description) VALUES
('site_name', 'IP管理器', '站点名称'),
('site_url', 'http://localhost', '站点URL'),
('api_rate_limit', '100', '每分钟API请求限制'),
('log_retention_days', '30', '日志保留天数'),
('enable_geoip', '1', '启用GeoIP查询'),
('enable_cloudflare', '0', '启用Cloudflare集成'),
('enable_webhooks', '1', '启用Webhook通知'),
('session_lifetime', '86400', '会话有效期(秒)'),
('max_upload_size', '10485760', '最大上传大小(字节)'),
('default_redirect_type', '302', '默认跳转类型'),
('enable_antibot', '1', '启用反爬虫'),
('enable_threat_intel', '0', '启用威胁情报'),
('maintenance_mode', '0', '维护模式')
ON DUPLICATE KEY UPDATE `key`=`key`;

-- =====================================================
-- 第二部分: IP相关表
-- =====================================================

-- IP国家缓存表
CREATE TABLE IF NOT EXISTS ip_country_cache (
    ip VARCHAR(45) NOT NULL PRIMARY KEY,
    country VARCHAR(100) COMMENT '国家名称(兼容字段)',
    country_code VARCHAR(2),
    country_name VARCHAR(100),
    region VARCHAR(100),
    city VARCHAR(100),
    isp VARCHAR(255),
    asn VARCHAR(50),
    cached_at INT COMMENT '缓存时间戳(兼容字段)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_country (country_code),
    INDEX idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- IP黑名单表
CREATE TABLE IF NOT EXISTS ip_blacklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_cidr VARCHAR(50) NOT NULL COMMENT 'IP或CIDR',
    ip_start BIGINT UNSIGNED COMMENT '起始IP(数值)',
    ip_end BIGINT UNSIGNED COMMENT '结束IP(数值)',
    type ENUM('bot', 'malicious', 'spam', 'custom') DEFAULT 'custom',
    category VARCHAR(50) COMMENT '分类: google, baidu, bing等',
    name VARCHAR(100) COMMENT '名称描述',
    source VARCHAR(100) COMMENT '来源',
    hit_count INT DEFAULT 0 COMMENT '命中次数',
    last_hit_at TIMESTAMP NULL COMMENT '最后命中时间',
    enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ip_range (ip_start, ip_end),
    INDEX idx_category (category),
    INDEX idx_type (type),
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- IP威胁情报表
CREATE TABLE IF NOT EXISTS ip_threats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    threat_type ENUM('malware', 'spam', 'botnet', 'proxy', 'tor', 'vpn', 'scanner') NOT NULL,
    threat_score INT DEFAULT 0 COMMENT '威胁评分 0-100',
    source VARCHAR(100) COMMENT '来源',
    first_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    hit_count INT DEFAULT 1,
    metadata JSON,
    UNIQUE KEY unique_ip_type (ip, threat_type),
    INDEX idx_score (threat_score),
    INDEX idx_type (threat_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 威胁情报源配置
CREATE TABLE IF NOT EXISTS threat_intel_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type ENUM('ip', 'url', 'domain', 'hash') DEFAULT 'ip',
    url TEXT,
    format ENUM('plain', 'csv', 'json') DEFAULT 'plain',
    enabled TINYINT(1) DEFAULT 1,
    update_interval INT DEFAULT 86400 COMMENT '更新间隔(秒)',
    last_update TIMESTAMP NULL,
    last_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 第三部分: Cloudflare域名表
-- =====================================================

CREATE TABLE IF NOT EXISTS cf_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    zone_id VARCHAR(50),
    status ENUM('active', 'pending', 'inactive') DEFAULT 'pending',
    ssl_status VARCHAR(50),
    dns_records JSON,
    settings JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_domain (domain),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 第四部分: 跳转系统表
-- =====================================================

-- 跳转域名表
CREATE TABLE IF NOT EXISTS jump_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    name VARCHAR(100) COMMENT '显示名称',
    description VARCHAR(500),
    ssl_enabled TINYINT(1) DEFAULT 0,
    is_default TINYINT(1) DEFAULT 0 COMMENT '是否默认域名',
    use_count INT DEFAULT 0 COMMENT '使用次数',
    safety_status ENUM('safe', 'warning', 'danger', 'unknown') DEFAULT 'unknown',
    status ENUM('active', 'inactive', 'pending') DEFAULT 'active',
    enabled TINYINT(1) DEFAULT 1,
    cf_zone_id VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_domain (domain),
    INDEX idx_status (status),
    INDEX idx_enabled (enabled),
    INDEX idx_default (is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 跳转分组表
CREATE TABLE IF NOT EXISTS jump_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tag VARCHAR(50) NOT NULL COMMENT '分组标识',
    name VARCHAR(100) NOT NULL,
    description VARCHAR(500),
    rule_count INT DEFAULT 0 COMMENT '规则数量',
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_tag (tag),
    UNIQUE KEY unique_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 默认分组
INSERT INTO jump_groups (tag, name, description) VALUES ('default', '默认分组', '默认分组') ON DUPLICATE KEY UPDATE tag=tag;

-- 跳转规则表
CREATE TABLE IF NOT EXISTS jump_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    title VARCHAR(255) COMMENT '标题',
    note TEXT COMMENT '备注',
    domain_id INT NOT NULL,
    group_id INT,
    group_tag VARCHAR(50) DEFAULT 'default' COMMENT '分组标识',
    path VARCHAR(500) DEFAULT '/',
    match_key VARCHAR(100) COMMENT '匹配键(短链接code等)',
    rule_type ENUM('redirect', 'proxy', 'ab_test', 'weight', 'geo', 'device', 'time', 'referer', 'code') DEFAULT 'redirect',
    target_url TEXT NOT NULL,
    targets JSON COMMENT '多目标配置',
    redirect_type ENUM('301', '302', '307', '308') DEFAULT '302',
    countries VARCHAR(500) COMMENT '国家代码,逗号分隔',
    country_mode ENUM('include', 'exclude') DEFAULT 'include',
    devices VARCHAR(100) COMMENT '设备类型',
    time_rules JSON COMMENT '时间规则',
    referer_rules JSON COMMENT 'Referer规则',
    priority INT DEFAULT 0,
    status ENUM('active', 'inactive', 'testing') DEFAULT 'active',
    total_clicks BIGINT DEFAULT 0,
    visit_count BIGINT DEFAULT 0,
    unique_visitors BIGINT DEFAULT 0,
    last_visit_at TIMESTAMP NULL,
    last_access_at TIMESTAMP NULL,
    expire_type VARCHAR(20) DEFAULT 'permanent' COMMENT '过期类型',
    expire_at TIMESTAMP NULL,
    max_clicks INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    enabled TINYINT(1) DEFAULT 1,
    
    INDEX idx_domain (domain_id),
    INDEX idx_group (group_id),
    INDEX idx_group_tag (group_tag),
    INDEX idx_status (status),
    INDEX idx_priority (priority DESC),
    INDEX idx_path (path(100)),
    INDEX idx_match_key (match_key),
    INDEX idx_rule_type (rule_type),
    INDEX idx_domain_path (domain_id, path(100)),
    INDEX idx_enabled (enabled),
    FOREIGN KEY (domain_id) REFERENCES jump_domains(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES jump_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 跳转日志表
CREATE TABLE IF NOT EXISTS jump_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    rule_id INT NOT NULL,
    rule_type VARCHAR(50),
    match_key VARCHAR(100),
    domain VARCHAR(255),
    path VARCHAR(500),
    target_url TEXT,
    ip VARCHAR(45),
    visitor_ip VARCHAR(45),
    country VARCHAR(100),
    country_code VARCHAR(2),
    user_agent TEXT,
    referer TEXT,
    device_type VARCHAR(20),
    browser VARCHAR(50),
    os VARCHAR(50),
    is_unique TINYINT(1) DEFAULT 0,
    response_time_ms INT DEFAULT 0,
    visited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_rule (rule_id),
    INDEX idx_visited (visited_at),
    INDEX idx_created (created_at),
    INDEX idx_country (country_code),
    INDEX idx_device (device_type),
    INDEX idx_rule_visited (rule_id, visited_at),
    INDEX idx_ip_rule (ip, rule_id),
    INDEX idx_visitor_ip (visitor_ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 唯一访客表(UV统计优化)
CREATE TABLE IF NOT EXISTS jump_unique_visitors (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    rule_id INT NOT NULL,
    visitor_hash VARCHAR(64) NOT NULL COMMENT 'IP+UA的hash',
    date DATE NOT NULL,
    first_visit_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_visitor (rule_id, visitor_hash, date),
    INDEX idx_date (date),
    INDEX idx_rule_date (rule_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 跳转统计表(按规则按天)
CREATE TABLE IF NOT EXISTS jump_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rule_id INT NOT NULL,
    date DATE NOT NULL,
    pv INT DEFAULT 0,
    uv INT DEFAULT 0,
    countries JSON,
    devices JSON,
    browsers JSON,
    referers JSON,
    hours JSON COMMENT '24小时分布',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_stat (rule_id, date),
    INDEX idx_date (date),
    INDEX idx_rule_date (rule_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 第五部分: 短链接表
-- =====================================================

CREATE TABLE IF NOT EXISTS short_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    domain VARCHAR(255) COMMENT '绑定域名',
    original_url TEXT NOT NULL,
    title VARCHAR(255),
    description TEXT,
    group_tag VARCHAR(50) COMMENT '分组标签',
    password VARCHAR(255),
    expire_type VARCHAR(20) DEFAULT 'permanent',
    expire_at TIMESTAMP NULL,
    max_clicks INT,
    click_count INT DEFAULT 0,
    total_clicks INT DEFAULT 0,
    unique_clicks INT DEFAULT 0,
    enabled TINYINT(1) DEFAULT 1,
    user_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_code (code),
    INDEX idx_user (user_id),
    INDEX idx_enabled (enabled),
    INDEX idx_expire (expire_at),
    INDEX idx_group (group_tag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 短链接访问日志
CREATE TABLE IF NOT EXISTS short_link_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    link_id INT NOT NULL,
    ip VARCHAR(45),
    country_code VARCHAR(2),
    user_agent TEXT,
    referer TEXT,
    is_unique TINYINT(1) DEFAULT 0,
    visited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_link (link_id),
    INDEX idx_visited (visited_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 第六部分: 反爬虫系统表
-- =====================================================

-- 反爬配置表
CREATE TABLE IF NOT EXISTS antibot_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    domain VARCHAR(255),
    paths JSON COMMENT '需要保护的路径',
    enabled TINYINT(1) DEFAULT 1,
    challenge_type ENUM('none', 'js', 'captcha', 'slider') DEFAULT 'js',
    challenge_difficulty INT DEFAULT 5,
    rate_limit INT DEFAULT 60 COMMENT '每分钟请求限制',
    block_duration INT DEFAULT 3600 COMMENT '封禁时长(秒)',
    whitelist_ips JSON,
    whitelist_ua JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_name (name),
    INDEX idx_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 反爬日志表
CREATE TABLE IF NOT EXISTS antibot_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    config_id INT,
    ip VARCHAR(45),
    country_code VARCHAR(2),
    path VARCHAR(500),
    user_agent TEXT,
    challenge_type VARCHAR(20),
    result ENUM('pass', 'block', 'challenge') DEFAULT 'pass',
    reason VARCHAR(255),
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_config (config_id),
    INDEX idx_ip (ip),
    INDEX idx_result (result),
    INDEX idx_logged (logged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 反爬封禁表
CREATE TABLE IF NOT EXISTS antibot_blocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    config_id INT,
    reason VARCHAR(255),
    blocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    until_at TIMESTAMP NOT NULL,
    
    UNIQUE KEY unique_ip_config (ip, config_id),
    INDEX idx_until (until_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 反爬验证会话表
CREATE TABLE IF NOT EXISTS antibot_sessions (
    id VARCHAR(64) PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    config_id INT,
    challenge_data JSON,
    verified TINYINT(1) DEFAULT 0,
    attempts INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    
    INDEX idx_ip (ip),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 反爬统计表
CREATE TABLE IF NOT EXISTS antibot_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_id INT,
    date DATE NOT NULL,
    total_requests INT DEFAULT 0,
    passed_requests INT DEFAULT 0,
    blocked_requests INT DEFAULT 0,
    challenged_requests INT DEFAULT 0,
    unique_ips INT DEFAULT 0,
    
    UNIQUE KEY unique_stat (config_id, date),
    INDEX idx_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 全局黑名单(反爬用)
CREATE TABLE IF NOT EXISTS antibot_blacklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pattern VARCHAR(255) NOT NULL COMMENT 'IP或正则',
    pattern_type ENUM('ip', 'cidr', 'regex') DEFAULT 'ip',
    reason VARCHAR(255),
    enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_pattern (pattern)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 全局白名单(反爬用)
CREATE TABLE IF NOT EXISTS antibot_whitelist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pattern VARCHAR(255) NOT NULL,
    pattern_type ENUM('ip', 'cidr', 'regex', 'ua') DEFAULT 'ip',
    reason VARCHAR(255),
    enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_pattern (pattern)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 第七部分: 用户与权限表
-- =====================================================

-- 用户表
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    role ENUM('admin', 'operator', 'viewer') DEFAULT 'viewer',
    status ENUM('active', 'inactive', 'locked') DEFAULT 'active',
    must_change_password TINYINT(1) DEFAULT 0,
    last_login TIMESTAMP NULL COMMENT '最后登录时间(兼容字段)',
    last_login_at TIMESTAMP NULL,
    last_login_ip VARCHAR(45),
    login_count INT DEFAULT 0,
    failed_attempts INT DEFAULT 0,
    locked_until TIMESTAMP NULL,
    two_factor_secret VARCHAR(100),
    two_factor_enabled TINYINT(1) DEFAULT 0,
    api_key VARCHAR(64),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_username (username),
    UNIQUE KEY unique_email (email),
    UNIQUE KEY unique_api_key (api_key),
    INDEX idx_status (status),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 角色权限表
CREATE TABLE IF NOT EXISTS role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role VARCHAR(50) NOT NULL,
    resource VARCHAR(100) NOT NULL,
    action VARCHAR(50) NOT NULL,
    allowed TINYINT(1) DEFAULT 1,
    
    UNIQUE KEY unique_permission (role, resource, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 登录尝试记录
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50),
    ip VARCHAR(45),
    user_agent TEXT,
    success TINYINT(1) DEFAULT 0,
    reason VARCHAR(100),
    locked_until TIMESTAMP NULL COMMENT '锁定截止时间',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_username (username),
    INDEX idx_ip (ip),
    INDEX idx_created (created_at),
    INDEX idx_locked (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API Token表
CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    token VARCHAR(64) NOT NULL,
    note TEXT COMMENT '备注',
    permissions JSON COMMENT '权限列表',
    rate_limit INT DEFAULT 1000,
    expires_at TIMESTAMP NULL,
    last_used_at TIMESTAMP NULL,
    use_count BIGINT DEFAULT 0,
    call_count BIGINT DEFAULT 0 COMMENT '调用次数',
    enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_token (token),
    INDEX idx_user (user_id),
    INDEX idx_enabled (enabled),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 第八部分: 日志与审计表
-- =====================================================

-- 审计日志表
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    username VARCHAR(50),
    action VARCHAR(100) NOT NULL,
    resource_type VARCHAR(50),
    resource_id VARCHAR(100),
    old_value JSON,
    new_value JSON,
    ip VARCHAR(45),
    user_agent TEXT,
    status VARCHAR(20) DEFAULT 'success',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_resource (resource_type, resource_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 系统日志表
CREATE TABLE IF NOT EXISTS system_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) COMMENT '日志类型',
    action VARCHAR(100) COMMENT '操作',
    level ENUM('debug', 'info', 'warning', 'error', 'critical') DEFAULT 'info',
    category VARCHAR(50),
    message TEXT,
    details TEXT,
    context JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_type (type),
    INDEX idx_level (level),
    INDEX idx_category (category),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 域名安全日志
CREATE TABLE IF NOT EXISTS domain_safety_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT COMMENT '域名ID',
    domain VARCHAR(255) NOT NULL,
    check_type VARCHAR(50),
    check_source VARCHAR(100) COMMENT '检测源',
    status ENUM('safe', 'warning', 'danger') DEFAULT 'safe',
    detail TEXT COMMENT '详细信息',
    details JSON,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_domain_id (domain_id),
    INDEX idx_domain (domain),
    INDEX idx_status (status),
    INDEX idx_checked (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 第九部分: Webhook表
-- =====================================================

-- Webhook配置表
CREATE TABLE IF NOT EXISTS webhooks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    url TEXT NOT NULL,
    secret VARCHAR(255),
    events JSON COMMENT '订阅的事件类型',
    headers JSON COMMENT '自定义请求头',
    enabled TINYINT(1) DEFAULT 1,
    retry_count INT DEFAULT 3,
    timeout INT DEFAULT 30,
    last_triggered_at TIMESTAMP NULL,
    last_status VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_name (name),
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Webhook日志表
CREATE TABLE IF NOT EXISTS webhook_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    webhook_id INT NOT NULL,
    event_type VARCHAR(100),
    title VARCHAR(255) COMMENT '通知标题',
    level VARCHAR(20) COMMENT '通知级别',
    payload JSON,
    response_code INT,
    response_body TEXT,
    duration_ms INT,
    success TINYINT(1) DEFAULT 0,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_webhook (webhook_id),
    INDEX idx_created (created_at),
    INDEX idx_success (success),
    FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 第十部分: 备份与任务表
-- =====================================================

-- 备份记录表
CREATE TABLE IF NOT EXISTS backups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    filepath VARCHAR(500) NOT NULL,
    filesize BIGINT DEFAULT 0,
    type ENUM('full', 'partial', 'config') DEFAULT 'full',
    status ENUM('pending', 'running', 'completed', 'failed') DEFAULT 'pending',
    tables_included JSON,
    error_message TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 系统监控指标表
CREATE TABLE IF NOT EXISTS system_metrics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    metric_name VARCHAR(100) NOT NULL,
    metric_value DECIMAL(20,6) NOT NULL,
    metric_type ENUM('counter', 'gauge', 'histogram') DEFAULT 'gauge',
    labels JSON COMMENT '标签',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_name (metric_name),
    INDEX idx_created (created_at),
    INDEX idx_name_created (metric_name, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 业务监控指标快照表 (来自 migrate_database_v2.sql)
CREATE TABLE IF NOT EXISTS metrics_snapshot (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    metric_name VARCHAR(100) NOT NULL,
    metric_value DECIMAL(20, 6) NOT NULL,
    labels JSON,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_metric_time (metric_name, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 小时统计表(性能优化)
CREATE TABLE IF NOT EXISTS stats_hourly (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    rule_id INT NOT NULL,
    hour_start DATETIME NOT NULL,
    pv INT DEFAULT 0,
    uv INT DEFAULT 0,
    unique_ips INT DEFAULT 0,
    avg_response_ms DECIMAL(10,2),
    countries JSON,
    devices JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_hourly (rule_id, hour_start),
    INDEX idx_hour (hour_start),
    INDEX idx_rule_hour (rule_id, hour_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 每日统计表(性能优化)
CREATE TABLE IF NOT EXISTS stats_daily (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    rule_id INT NOT NULL,
    date DATE NOT NULL,
    pv INT DEFAULT 0,
    uv INT DEFAULT 0,
    unique_ips INT DEFAULT 0,
    avg_response_ms DECIMAL(10,2),
    peak_hour TINYINT,
    peak_pv INT,
    countries JSON,
    devices JSON,
    referers JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_daily (rule_id, date),
    INDEX idx_date (date),
    INDEX idx_rule_date (rule_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 缓存预热配置表
CREATE TABLE IF NOT EXISTS cache_warmup_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cache_key VARCHAR(255) NOT NULL,
    warmup_query TEXT NOT NULL,
    ttl INT DEFAULT 3600,
    priority INT DEFAULT 0,
    enabled TINYINT(1) DEFAULT 1,
    last_warmup_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_key (cache_key),
    INDEX idx_enabled_priority (enabled, priority DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API调用日志表
CREATE TABLE IF NOT EXISTS api_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    token_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    request_data JSON,
    response_code INT DEFAULT 200,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_token (token_id),
    INDEX idx_action (action),
    INDEX idx_created (created_at),
    INDEX idx_token_created (token_id, created_at),
    FOREIGN KEY (token_id) REFERENCES api_tokens(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 跳转UV统计表(去重统计用)
CREATE TABLE IF NOT EXISTS jump_uv (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    rule_id INT NOT NULL,
    visitor_hash VARBINARY(16) NOT NULL COMMENT 'IP的MD5 hash',
    date DATE DEFAULT NULL,
    uv_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_visitor (rule_id, visitor_hash),
    UNIQUE KEY unique_daily (rule_id, date),
    INDEX idx_rule (rule_id),
    INDEX idx_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 跳转日统计表
CREATE TABLE IF NOT EXISTS jump_daily_stats (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    rule_id INT NOT NULL,
    stat_date DATE NOT NULL,
    clicks INT DEFAULT 0,
    unique_visitors INT DEFAULT 0,
    countries JSON,
    devices JSON,
    referers JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_stat (rule_id, stat_date),
    INDEX idx_rule (rule_id),
    INDEX idx_date (stat_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- IP黑名单版本表(缓存控制)
CREATE TABLE IF NOT EXISTS ip_blacklist_version (
    id INT PRIMARY KEY DEFAULT 1,
    version INT DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 初始化版本记录
INSERT INTO ip_blacklist_version (id, version) VALUES (1, 1) ON DUPLICATE KEY UPDATE id=id;

-- =====================================================
-- 第十一部分: 遗留兼容表(可选)
-- =====================================================

-- 旧版跳转表(兼容)
CREATE TABLE IF NOT EXISTS redirects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    short_code VARCHAR(50),
    url TEXT NOT NULL,
    target_url TEXT,
    note VARCHAR(255),
    countries VARCHAR(255) DEFAULT '',
    redirect_type VARCHAR(10) DEFAULT '302',
    status ENUM('active', 'inactive') DEFAULT 'active',
    enabled TINYINT(1) DEFAULT 1,
    port_match_enabled TINYINT(1) DEFAULT 0 COMMENT '启用端口匹配(IP:端口访问)',
    visit_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_ip (ip),
    UNIQUE KEY unique_code (short_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 旧版IP池表(兼容) - 扩展版
CREATE TABLE IF NOT EXISTS ip_pool (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    port INT DEFAULT 80,
    protocol VARCHAR(10) DEFAULT 'http',
    pool_name VARCHAR(50) DEFAULT 'default',
    country VARCHAR(100),
    country_code VARCHAR(2),
    region VARCHAR(100),
    city VARCHAR(100),
    isp VARCHAR(255),
    status ENUM('active', 'inactive', 'checking') DEFAULT 'active',
    speed INT DEFAULT 0 COMMENT '响应速度ms',
    last_check TIMESTAMP NULL,
    last_used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_ip (ip),
    INDEX idx_pool (pool_name),
    INDEX idx_country (country_code),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 旧版访问统计(兼容) - 扩展版
CREATE TABLE IF NOT EXISTS visit_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    redirect_id INT,
    target_ip VARCHAR(45),
    date DATE,
    pv INT DEFAULT 0,
    uv INT DEFAULT 0,
    total_clicks INT DEFAULT 0,
    UNIQUE KEY unique_stat (redirect_id, date),
    UNIQUE KEY unique_target (target_ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 旧版访问日志(兼容) - 扩展版
CREATE TABLE IF NOT EXISTS visit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    redirect_id INT,
    target_ip VARCHAR(45),
    visitor_ip VARCHAR(45),
    ip VARCHAR(45),
    country VARCHAR(100),
    country_code VARCHAR(2),
    user_agent TEXT,
    referer TEXT,
    visited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_redirect (redirect_id),
    INDEX idx_target (target_ip),
    INDEX idx_visited (visited_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 旧版独立访客表(兼容) - 扩展版
CREATE TABLE IF NOT EXISTS unique_visitors (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    redirect_id INT,
    target_ip VARCHAR(45),
    visitor_ip VARCHAR(45),
    visitor_hash VARCHAR(64),
    date DATE,
    UNIQUE KEY unique_visitor (redirect_id, visitor_hash, date),
    UNIQUE KEY unique_target_visitor (target_ip, visitor_ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 反爬虫配置表(旧版兼容)
CREATE TABLE IF NOT EXISTS antibot_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) NOT NULL,
    `value` TEXT,
    UNIQUE KEY unique_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 反爬虫请求记录表
CREATE TABLE IF NOT EXISTS antibot_requests (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    visitor_ip VARCHAR(45) NOT NULL,
    request_time INT NOT NULL COMMENT 'Unix时间戳',
    INDEX idx_ip (visitor_ip),
    INDEX idx_time (request_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 反爬虫行为记录表
CREATE TABLE IF NOT EXISTS antibot_behavior (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    visitor_ip VARCHAR(45) NOT NULL,
    path VARCHAR(500),
    suspicious TINYINT(1) DEFAULT 0,
    recorded_at INT NOT NULL COMMENT 'Unix时间戳',
    INDEX idx_ip (visitor_ip),
    INDEX idx_recorded (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 备份日志表
CREATE TABLE IF NOT EXISTS backup_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    size BIGINT DEFAULT 0,
    cloud_uploaded TINYINT(1) DEFAULT 0,
    cloud_url VARCHAR(500),
    success TINYINT(1) DEFAULT 1,
    error TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 域名表(旧版兼容)
CREATE TABLE IF NOT EXISTS domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    type VARCHAR(50) DEFAULT 'jump',
    target TEXT,
    is_safe TINYINT(1) DEFAULT 1,
    cf_zone_id VARCHAR(50),
    enabled TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 短链接表(旧版兼容)
CREATE TABLE IF NOT EXISTS shortlinks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    original_url TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    UNIQUE KEY unique_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 访问日志表(用于分区优化)
CREATE TABLE IF NOT EXISTS access_logs (
    id BIGINT AUTO_INCREMENT,
    rule_id INT,
    ip VARCHAR(45),
    country_code VARCHAR(2),
    user_agent TEXT,
    referer TEXT,
    device_type VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id, created_at),
    INDEX idx_rule (rule_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 第十二部分: 日志归档表
-- =====================================================

-- 跳转日志归档表
CREATE TABLE IF NOT EXISTS jump_logs_archive (
    id BIGINT PRIMARY KEY,
    rule_id INT NOT NULL,
    domain VARCHAR(255),
    path VARCHAR(500),
    target_url TEXT,
    ip VARCHAR(45),
    country_code VARCHAR(2),
    user_agent TEXT,
    referer TEXT,
    device_type VARCHAR(20),
    browser VARCHAR(50),
    os VARCHAR(50),
    is_unique TINYINT(1) DEFAULT 0,
    response_time_ms INT DEFAULT 0,
    visited_at TIMESTAMP,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_visited (visited_at),
    INDEX idx_rule (rule_id),
    INDEX idx_archived (archived_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 审计日志归档表
CREATE TABLE IF NOT EXISTS audit_logs_archive (
    id INT PRIMARY KEY,
    user_id INT,
    username VARCHAR(50),
    action VARCHAR(100),
    resource_type VARCHAR(50),
    resource_id VARCHAR(100),
    old_value JSON,
    new_value JSON,
    details JSON,
    ip VARCHAR(45),
    ip_address VARCHAR(45),
    user_agent TEXT,
    status VARCHAR(20),
    created_at TIMESTAMP,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_created (created_at),
    INDEX idx_archived (archived_at),
    INDEX idx_original_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
ROW_FORMAT=COMPRESSED;

-- =====================================================
-- 第十三部分: 初始数据
-- =====================================================

-- 默认管理员 (密码: admin123, 请立即修改!)
INSERT INTO users (username, password_hash, email, role, status, must_change_password) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@localhost', 'admin', 'active', 1)
ON DUPLICATE KEY UPDATE username=username;

-- 默认权限配置
INSERT INTO role_permissions (role, resource, action, allowed) VALUES
-- Admin权限
('admin', '*', '*', 1),
-- Operator权限
('operator', 'domains', 'create', 1),
('operator', 'domains', 'read', 1),
('operator', 'domains', 'update', 1),
('operator', 'domains', 'delete', 0),
('operator', 'rules', 'create', 1),
('operator', 'rules', 'read', 1),
('operator', 'rules', 'update', 1),
('operator', 'rules', 'delete', 1),
('operator', 'logs', 'read', 1),
('operator', 'stats', 'read', 1),
-- Viewer权限
('viewer', 'domains', 'read', 1),
('viewer', 'rules', 'read', 1),
('viewer', 'logs', 'read', 1),
('viewer', 'stats', 'read', 1)
ON DUPLICATE KEY UPDATE role=role;

-- 默认威胁情报源
INSERT INTO threat_intel_sources (name, type, url, format, update_interval) VALUES
('Firehol Level1', 'ip', 'https://raw.githubusercontent.com/firehol/blocklist-ipsets/master/firehol_level1.netset', 'plain', 86400),
('Emerging Threats', 'ip', 'https://rules.emergingthreats.net/fwrules/emerging-Block-IPs.txt', 'plain', 86400),
('Spamhaus DROP', 'ip', 'https://www.spamhaus.org/drop/drop.txt', 'plain', 86400),
('Abuse.ch SSL Blacklist', 'ip', 'https://sslbl.abuse.ch/blacklist/sslipblacklist.txt', 'plain', 3600),
('PhishTank', 'url', 'http://data.phishtank.com/data/online-valid.csv', 'csv', 3600),
('OpenPhish', 'url', 'https://openphish.com/feed.txt', 'plain', 3600)
ON DUPLICATE KEY UPDATE name=name;

-- 默认缓存预热配置
INSERT INTO cache_warmup_config (cache_key, warmup_query, ttl, priority) VALUES
('active_rules', 'SELECT * FROM jump_rules WHERE status = "active" ORDER BY priority DESC', 300, 100),
('active_domains', 'SELECT * FROM jump_domains WHERE status = "active"', 300, 90),
('ip_blacklist', 'SELECT ip_cidr, ip_start, ip_end, type, category FROM ip_blacklist WHERE enabled = 1', 600, 80),
('antibot_configs', 'SELECT * FROM antibot_configs WHERE enabled = 1', 300, 70)
ON DUPLICATE KEY UPDATE cache_key=cache_key;

-- =====================================================
-- 第十四部分: IP黑名单默认数据
-- =====================================================

-- Google爬虫
INSERT IGNORE INTO ip_blacklist (ip_cidr, ip_start, ip_end, type, category, name) VALUES
('66.249.64.0/19', INET_ATON('66.249.64.0'), INET_ATON('66.249.95.255'), 'bot', 'google', 'Googlebot主段'),
('64.233.160.0/19', INET_ATON('64.233.160.0'), INET_ATON('64.233.191.255'), 'bot', 'google', 'Google通用'),
('72.14.192.0/18', INET_ATON('72.14.192.0'), INET_ATON('72.14.255.255'), 'bot', 'google', 'Google'),
('74.125.0.0/16', INET_ATON('74.125.0.0'), INET_ATON('74.125.255.255'), 'bot', 'google', 'Google'),
('108.177.0.0/17', INET_ATON('108.177.0.0'), INET_ATON('108.177.127.255'), 'bot', 'google', 'Google'),
('142.250.0.0/15', INET_ATON('142.250.0.0'), INET_ATON('142.251.255.255'), 'bot', 'google', 'Google'),
('172.217.0.0/16', INET_ATON('172.217.0.0'), INET_ATON('172.217.255.255'), 'bot', 'google', 'Google'),
('173.194.0.0/16', INET_ATON('173.194.0.0'), INET_ATON('173.194.255.255'), 'bot', 'google', 'Google'),
('209.85.128.0/17', INET_ATON('209.85.128.0'), INET_ATON('209.85.255.255'), 'bot', 'google', 'Google'),
('216.58.192.0/19', INET_ATON('216.58.192.0'), INET_ATON('216.58.223.255'), 'bot', 'google', 'Google'),
('216.239.32.0/19', INET_ATON('216.239.32.0'), INET_ATON('216.239.63.255'), 'bot', 'google', 'Google'),
('35.191.0.0/16', INET_ATON('35.191.0.0'), INET_ATON('35.191.255.255'), 'bot', 'google', 'Google Cloud'),
('130.211.0.0/22', INET_ATON('130.211.0.0'), INET_ATON('130.211.3.255'), 'bot', 'google', 'Google Cloud');

-- 南美银行爬虫 - 巴西
INSERT IGNORE INTO ip_blacklist (ip_cidr, ip_start, ip_end, type, category, name) VALUES
('200.152.32.0/19', INET_ATON('200.152.32.0'), INET_ATON('200.152.63.255'), 'bot', 'brazil_bank', '巴西央行'),
('200.219.128.0/19', INET_ATON('200.219.128.0'), INET_ATON('200.219.159.255'), 'bot', 'brazil_bank', '巴西央行'),
('189.9.0.0/16', INET_ATON('189.9.0.0'), INET_ATON('189.9.255.255'), 'bot', 'brazil_bank', '巴西央行'),
('170.66.0.0/16', INET_ATON('170.66.0.0'), INET_ATON('170.66.255.255'), 'bot', 'brazil_bank', 'Banco do Brasil'),
('200.155.80.0/20', INET_ATON('200.155.80.0'), INET_ATON('200.155.95.255'), 'bot', 'brazil_bank', 'Banco do Brasil'),
('200.201.160.0/20', INET_ATON('200.201.160.0'), INET_ATON('200.201.175.255'), 'bot', 'brazil_bank', 'Caixa'),
('200.196.32.0/19', INET_ATON('200.196.32.0'), INET_ATON('200.196.63.255'), 'bot', 'brazil_bank', 'Itaú'),
('189.8.0.0/16', INET_ATON('189.8.0.0'), INET_ATON('189.8.255.255'), 'bot', 'brazil_bank', 'Itaú'),
('200.155.0.0/20', INET_ATON('200.155.0.0'), INET_ATON('200.155.15.255'), 'bot', 'brazil_bank', 'Bradesco'),
('200.210.0.0/17', INET_ATON('200.210.0.0'), INET_ATON('200.210.127.255'), 'bot', 'brazil_bank', 'Bradesco'),
('200.142.0.0/16', INET_ATON('200.142.0.0'), INET_ATON('200.142.255.255'), 'bot', 'brazil_bank', 'Santander巴西');

-- 南美银行爬虫 - 阿根廷
INSERT IGNORE INTO ip_blacklist (ip_cidr, ip_start, ip_end, type, category, name) VALUES
('200.69.193.0/24', INET_ATON('200.69.193.0'), INET_ATON('200.69.193.255'), 'bot', 'argentina_bank', '阿根廷央行BCRA'),
('200.69.224.0/19', INET_ATON('200.69.224.0'), INET_ATON('200.69.255.255'), 'bot', 'argentina_bank', '阿根廷央行BCRA'),
('181.14.0.0/16', INET_ATON('181.14.0.0'), INET_ATON('181.14.255.255'), 'bot', 'argentina_bank', '阿根廷央行BCRA'),
('200.0.183.0/24', INET_ATON('200.0.183.0'), INET_ATON('200.0.183.255'), 'bot', 'argentina_bank', '阿根廷国家银行'),
('200.5.116.0/22', INET_ATON('200.5.116.0'), INET_ATON('200.5.119.255'), 'bot', 'argentina_bank', '阿根廷国家银行');

-- 南美银行爬虫 - 智利/墨西哥/其他
INSERT IGNORE INTO ip_blacklist (ip_cidr, ip_start, ip_end, type, category, name) VALUES
('200.14.68.0/22', INET_ATON('200.14.68.0'), INET_ATON('200.14.71.255'), 'bot', 'chile_bank', '智利央行'),
('200.31.0.0/18', INET_ATON('200.31.0.0'), INET_ATON('200.31.63.255'), 'bot', 'chile_bank', '智利央行'),
('200.29.0.0/17', INET_ATON('200.29.0.0'), INET_ATON('200.29.127.255'), 'bot', 'chile_bank', 'Banco de Chile'),
('200.52.0.0/16', INET_ATON('200.52.0.0'), INET_ATON('200.52.255.255'), 'bot', 'mexico_bank', '墨西哥央行Banxico'),
('189.203.0.0/16', INET_ATON('189.203.0.0'), INET_ATON('189.203.255.255'), 'bot', 'mexico_bank', '墨西哥央行Banxico'),
('200.57.0.0/16', INET_ATON('200.57.0.0'), INET_ATON('200.57.255.255'), 'bot', 'mexico_bank', 'BBVA墨西哥'),
('200.93.0.0/18', INET_ATON('200.93.0.0'), INET_ATON('200.93.63.255'), 'bot', 'colombia_bank', '哥伦比亚央行'),
('181.52.0.0/14', INET_ATON('181.52.0.0'), INET_ATON('181.55.255.255'), 'bot', 'colombia_bank', '哥伦比亚央行'),
('200.60.0.0/16', INET_ATON('200.60.0.0'), INET_ATON('200.60.255.255'), 'bot', 'peru_bank', '秘鲁央行BCRP'),
('190.12.0.0/16', INET_ATON('190.12.0.0'), INET_ATON('190.12.255.255'), 'bot', 'peru_bank', '秘鲁央行BCRP'),
('200.109.0.0/17', INET_ATON('200.109.0.0'), INET_ATON('200.109.127.255'), 'bot', 'venezuela_bank', '委内瑞拉央行BCV'),
('200.40.0.0/16', INET_ATON('200.40.0.0'), INET_ATON('200.40.255.255'), 'bot', 'uruguay_bank', '乌拉圭央行BCU');

-- 百度/搜狗/360/字节跳动蜘蛛
INSERT IGNORE INTO ip_blacklist (ip_cidr, ip_start, ip_end, type, category, name) VALUES
('111.206.198.0/24', INET_ATON('111.206.198.0'), INET_ATON('111.206.198.255'), 'bot', 'baidu', '百度蜘蛛'),
('111.206.221.0/24', INET_ATON('111.206.221.0'), INET_ATON('111.206.221.255'), 'bot', 'baidu', '百度蜘蛛'),
('123.125.71.0/24', INET_ATON('123.125.71.0'), INET_ATON('123.125.71.255'), 'bot', 'baidu', '百度蜘蛛'),
('180.76.0.0/16', INET_ATON('180.76.0.0'), INET_ATON('180.76.255.255'), 'bot', 'baidu', '百度蜘蛛'),
('220.181.108.0/24', INET_ATON('220.181.108.0'), INET_ATON('220.181.108.255'), 'bot', 'baidu', '百度蜘蛛'),
('220.181.51.0/24', INET_ATON('220.181.51.0'), INET_ATON('220.181.51.255'), 'bot', 'baidu', '百度蜘蛛'),
('106.38.241.0/24', INET_ATON('106.38.241.0'), INET_ATON('106.38.241.255'), 'bot', 'sogou', '搜狗蜘蛛'),
('111.202.100.0/24', INET_ATON('111.202.100.0'), INET_ATON('111.202.100.255'), 'bot', 'sogou', '搜狗蜘蛛'),
('123.126.51.0/24', INET_ATON('123.126.51.0'), INET_ATON('123.126.51.255'), 'bot', 'sogou', '搜狗蜘蛛'),
('220.181.125.0/24', INET_ATON('220.181.125.0'), INET_ATON('220.181.125.255'), 'bot', 'sogou', '搜狗蜘蛛'),
('180.153.232.0/24', INET_ATON('180.153.232.0'), INET_ATON('180.153.232.255'), 'bot', '360', '360蜘蛛'),
('180.163.220.0/24', INET_ATON('180.163.220.0'), INET_ATON('180.163.220.255'), 'bot', '360', '360蜘蛛'),
('110.249.201.0/24', INET_ATON('110.249.201.0'), INET_ATON('110.249.201.255'), 'bot', 'bytedance', '字节跳动'),
('111.225.148.0/24', INET_ATON('111.225.148.0'), INET_ATON('111.225.148.255'), 'bot', 'bytedance', '字节跳动'),
('111.225.149.0/24', INET_ATON('111.225.149.0'), INET_ATON('111.225.149.255'), 'bot', 'bytedance', '字节跳动'),
('220.243.136.0/24', INET_ATON('220.243.136.0'), INET_ATON('220.243.136.255'), 'bot', 'bytedance', '字节跳动');

-- Bing/Yandex蜘蛛
INSERT IGNORE INTO ip_blacklist (ip_cidr, ip_start, ip_end, type, category, name) VALUES
('13.66.139.0/24', INET_ATON('13.66.139.0'), INET_ATON('13.66.139.255'), 'bot', 'bing', 'Bingbot'),
('40.77.167.0/24', INET_ATON('40.77.167.0'), INET_ATON('40.77.167.255'), 'bot', 'bing', 'Bingbot'),
('40.77.188.0/22', INET_ATON('40.77.188.0'), INET_ATON('40.77.191.255'), 'bot', 'bing', 'Bingbot'),
('157.55.16.0/20', INET_ATON('157.55.16.0'), INET_ATON('157.55.31.255'), 'bot', 'bing', 'Bingbot'),
('157.55.32.0/20', INET_ATON('157.55.32.0'), INET_ATON('157.55.47.255'), 'bot', 'bing', 'Bingbot'),
('207.46.0.0/16', INET_ATON('207.46.0.0'), INET_ATON('207.46.255.255'), 'bot', 'bing', 'MSN/Bing'),
('5.45.192.0/18', INET_ATON('5.45.192.0'), INET_ATON('5.45.255.255'), 'bot', 'yandex', 'Yandex'),
('5.255.192.0/18', INET_ATON('5.255.192.0'), INET_ATON('5.255.255.255'), 'bot', 'yandex', 'Yandex'),
('77.88.0.0/18', INET_ATON('77.88.0.0'), INET_ATON('77.88.63.255'), 'bot', 'yandex', 'Yandex'),
('87.250.224.0/19', INET_ATON('87.250.224.0'), INET_ATON('87.250.255.255'), 'bot', 'yandex', 'Yandex'),
('93.158.128.0/18', INET_ATON('93.158.128.0'), INET_ATON('93.158.191.255'), 'bot', 'yandex', 'Yandex'),
('213.180.192.0/19', INET_ATON('213.180.192.0'), INET_ATON('213.180.223.255'), 'bot', 'yandex', 'Yandex');

-- SEO爬虫
INSERT IGNORE INTO ip_blacklist (ip_cidr, ip_start, ip_end, type, category, name) VALUES
('46.229.168.0/24', INET_ATON('46.229.168.0'), INET_ATON('46.229.168.255'), 'bot', 'seo', 'SEMrush'),
('85.208.96.0/22', INET_ATON('85.208.96.0'), INET_ATON('85.208.99.255'), 'bot', 'seo', 'SEMrush'),
('54.36.148.0/22', INET_ATON('54.36.148.0'), INET_ATON('54.36.151.255'), 'bot', 'seo', 'Ahrefs'),
('195.154.122.0/24', INET_ATON('195.154.122.0'), INET_ATON('195.154.122.255'), 'bot', 'seo', 'Ahrefs');

-- 恶意扫描器
INSERT IGNORE INTO ip_blacklist (ip_cidr, ip_start, ip_end, type, category, name) VALUES
('66.240.192.0/18', INET_ATON('66.240.192.0'), INET_ATON('66.240.255.255'), 'malicious', 'scanner', 'Shodan'),
('71.6.128.0/17', INET_ATON('71.6.128.0'), INET_ATON('71.6.255.255'), 'malicious', 'scanner', 'Shodan'),
('80.82.77.0/24', INET_ATON('80.82.77.0'), INET_ATON('80.82.77.255'), 'malicious', 'scanner', 'Shodan'),
('93.120.27.0/24', INET_ATON('93.120.27.0'), INET_ATON('93.120.27.255'), 'malicious', 'scanner', 'Shodan'),
('94.102.49.0/24', INET_ATON('94.102.49.0'), INET_ATON('94.102.49.255'), 'malicious', 'scanner', 'Shodan'),
('185.142.236.0/24', INET_ATON('185.142.236.0'), INET_ATON('185.142.236.255'), 'malicious', 'scanner', 'Shodan'),
('198.20.69.0/24', INET_ATON('198.20.69.0'), INET_ATON('198.20.69.255'), 'malicious', 'scanner', 'Shodan'),
('198.20.70.0/24', INET_ATON('198.20.70.0'), INET_ATON('198.20.70.255'), 'malicious', 'scanner', 'Shodan'),
('162.142.125.0/24', INET_ATON('162.142.125.0'), INET_ATON('162.142.125.255'), 'malicious', 'scanner', 'Censys'),
('167.94.138.0/24', INET_ATON('167.94.138.0'), INET_ATON('167.94.138.255'), 'malicious', 'scanner', 'Censys'),
('167.94.145.0/24', INET_ATON('167.94.145.0'), INET_ATON('167.94.145.255'), 'malicious', 'scanner', 'Censys'),
('167.94.146.0/24', INET_ATON('167.94.146.0'), INET_ATON('167.94.146.255'), 'malicious', 'scanner', 'Censys'),
('167.248.133.0/24', INET_ATON('167.248.133.0'), INET_ATON('167.248.133.255'), 'malicious', 'scanner', 'Censys'),
('71.6.135.0/24', INET_ATON('71.6.135.0'), INET_ATON('71.6.135.255'), 'malicious', 'scanner', 'BinaryEdge'),
('74.120.14.0/24', INET_ATON('74.120.14.0'), INET_ATON('74.120.14.255'), 'malicious', 'scanner', 'BinaryEdge'),
('185.165.190.0/24', INET_ATON('185.165.190.0'), INET_ATON('185.165.190.255'), 'malicious', 'scanner', 'CriminalIP'),
('192.35.168.0/23', INET_ATON('192.35.168.0'), INET_ATON('192.35.169.255'), 'malicious', 'scanner', 'LeakIX');

-- 已知恶意IP段
INSERT IGNORE INTO ip_blacklist (ip_cidr, ip_start, ip_end, type, category, name) VALUES
('5.188.210.0/24', INET_ATON('5.188.210.0'), INET_ATON('5.188.210.255'), 'malicious', 'attacker', '俄罗斯恶意段'),
('45.155.205.0/24', INET_ATON('45.155.205.0'), INET_ATON('45.155.205.255'), 'malicious', 'attacker', '俄罗斯恶意段'),
('45.146.164.0/24', INET_ATON('45.146.164.0'), INET_ATON('45.146.164.255'), 'malicious', 'attacker', '俄罗斯恶意段'),
('193.106.31.0/24', INET_ATON('193.106.31.0'), INET_ATON('193.106.31.255'), 'malicious', 'attacker', '俄罗斯恶意段'),
('193.56.28.0/24', INET_ATON('193.56.28.0'), INET_ATON('193.56.28.255'), 'malicious', 'attacker', '俄罗斯恶意段');

-- =====================================================
-- 第十五部分: 存储过程
-- =====================================================

DELIMITER //

-- 清理旧数据存储过程(合并版)
DROP PROCEDURE IF EXISTS cleanup_old_data//
CREATE PROCEDURE cleanup_old_data()
BEGIN
    DECLARE retention_days INT DEFAULT 30;
    DECLARE cache_retention_days INT DEFAULT 7;
    
    -- 获取配置的保留天数
    SELECT CAST(`value` AS UNSIGNED) INTO retention_days 
    FROM config WHERE `key` = 'log_retention_days';
    
    -- 清理跳转日志
    DELETE FROM jump_logs WHERE visited_at < DATE_SUB(NOW(), INTERVAL retention_days DAY);
    
    -- 清理反爬日志
    DELETE FROM antibot_logs WHERE logged_at < DATE_SUB(NOW(), INTERVAL retention_days DAY);
    
    -- 清理域名安全日志
    DELETE FROM domain_safety_logs WHERE checked_at < DATE_SUB(NOW(), INTERVAL retention_days DAY);
    
    -- 清理审计日志(保留更长时间)
    DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
    
    -- 清理系统日志
    DELETE FROM system_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL retention_days DAY);
    
    -- 清理Webhook日志
    DELETE FROM webhook_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL retention_days DAY);
    
    -- 清理登录尝试记录
    DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
    
    -- 清理IP缓存
    DELETE FROM ip_country_cache WHERE updated_at < DATE_SUB(NOW(), INTERVAL cache_retention_days DAY);
    
    -- 清理监控指标
    DELETE FROM system_metrics WHERE created_at < DATE_SUB(NOW(), INTERVAL retention_days DAY);
    
    -- 清理过期的临时封禁
    DELETE FROM antibot_blocks WHERE until_at < NOW();
    
    -- 清理过期的验证会话
    DELETE FROM antibot_sessions WHERE expires_at < NOW();
    
    -- 清理旧版访问日志
    DELETE FROM visit_logs WHERE visited_at < DATE_SUB(NOW(), INTERVAL retention_days DAY);
    
    -- 清理访问日志
    DELETE FROM access_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL retention_days DAY);
    
    -- 优化表
    OPTIMIZE TABLE jump_logs, antibot_logs, audit_logs, system_logs, webhook_logs, 
                   login_attempts, ip_country_cache, system_metrics;
END//

-- 小时统计聚合存储过程
DROP PROCEDURE IF EXISTS aggregate_hourly_stats//
CREATE PROCEDURE aggregate_hourly_stats()
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_rule_id INT;
    DECLARE v_hour_start DATETIME;
    
    -- 游标遍历需要聚合的规则
    DECLARE cur CURSOR FOR 
        SELECT DISTINCT rule_id, DATE_FORMAT(visited_at, '%Y-%m-%d %H:00:00') as hour_start
        FROM jump_logs 
        WHERE visited_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
        AND visited_at < DATE_FORMAT(NOW(), '%Y-%m-%d %H:00:00');
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    OPEN cur;
    
    read_loop: LOOP
        FETCH cur INTO v_rule_id, v_hour_start;
        IF done THEN
            LEAVE read_loop;
        END IF;
        
        INSERT INTO stats_hourly (rule_id, hour_start, pv, uv, unique_ips, avg_response_ms)
        SELECT 
            rule_id,
            v_hour_start,
            COUNT(*) as pv,
            COUNT(DISTINCT CASE WHEN is_unique = 1 THEN ip END) as uv,
            COUNT(DISTINCT ip) as unique_ips,
            AVG(response_time_ms) as avg_response_ms
        FROM jump_logs 
        WHERE rule_id = v_rule_id 
        AND visited_at >= v_hour_start 
        AND visited_at < DATE_ADD(v_hour_start, INTERVAL 1 HOUR)
        GROUP BY rule_id
        ON DUPLICATE KEY UPDATE
            pv = VALUES(pv),
            uv = VALUES(uv),
            unique_ips = VALUES(unique_ips),
            avg_response_ms = VALUES(avg_response_ms);
    END LOOP;
    
    CLOSE cur;
END//

-- 每日统计聚合存储过程
DROP PROCEDURE IF EXISTS aggregate_daily_stats//
CREATE PROCEDURE aggregate_daily_stats()
BEGIN
    -- 聚合昨天的小时数据到每日数据
    INSERT INTO stats_daily (rule_id, date, pv, uv, unique_ips, avg_response_ms)
    SELECT 
        rule_id,
        DATE(hour_start) as date,
        SUM(pv) as pv,
        SUM(uv) as uv,
        SUM(unique_ips) as unique_ips,
        AVG(avg_response_ms) as avg_response_ms
    FROM stats_hourly
    WHERE DATE(hour_start) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
    GROUP BY rule_id, DATE(hour_start)
    ON DUPLICATE KEY UPDATE
        pv = VALUES(pv),
        uv = VALUES(uv),
        unique_ips = VALUES(unique_ips),
        avg_response_ms = VALUES(avg_response_ms);
END//

-- 更新规则访问统计
DROP PROCEDURE IF EXISTS update_rule_stats//
CREATE PROCEDURE update_rule_stats(IN p_rule_id INT)
BEGIN
    UPDATE jump_rules 
    SET 
        visit_count = (SELECT COUNT(*) FROM jump_logs WHERE rule_id = p_rule_id),
        unique_visitors = (SELECT COUNT(DISTINCT ip) FROM jump_logs WHERE rule_id = p_rule_id),
        last_visit_at = (SELECT MAX(visited_at) FROM jump_logs WHERE rule_id = p_rule_id)
    WHERE id = p_rule_id;
END//

-- 归档旧日志数据
DROP PROCEDURE IF EXISTS archive_old_logs//
CREATE PROCEDURE archive_old_logs(IN retention_days INT)
BEGIN
    DECLARE rows_archived INT DEFAULT 0;
    DECLARE batch_size INT DEFAULT 10000;
    DECLARE archive_date DATE;
    
    SET archive_date = DATE_SUB(CURDATE(), INTERVAL retention_days DAY);
    
    -- 归档跳转日志（分批处理避免锁表太久）
    REPEAT
        INSERT INTO jump_logs_archive 
        SELECT *, NOW() as archived_at 
        FROM jump_logs 
        WHERE DATE(visited_at) < archive_date
        LIMIT batch_size;
        
        SET rows_archived = ROW_COUNT();
        
        IF rows_archived > 0 THEN
            DELETE FROM jump_logs 
            WHERE DATE(visited_at) < archive_date
            LIMIT batch_size;
        END IF;
        
        -- 避免长事务
        DO SLEEP(0.1);
        
    UNTIL rows_archived < batch_size END REPEAT;
    
    -- 归档审计日志
    SET rows_archived = 0;
    REPEAT
        INSERT INTO audit_logs_archive (id, user_id, username, action, resource_type, resource_id, old_value, new_value, ip, user_agent, status, created_at, archived_at)
        SELECT id, user_id, username, action, resource_type, resource_id, old_value, new_value, ip, user_agent, status, created_at, NOW()
        FROM audit_logs 
        WHERE DATE(created_at) < archive_date
        LIMIT batch_size;
        
        SET rows_archived = ROW_COUNT();
        
        IF rows_archived > 0 THEN
            DELETE FROM audit_logs 
            WHERE DATE(created_at) < archive_date
            LIMIT batch_size;
        END IF;
        
        DO SLEEP(0.1);
        
    UNTIL rows_archived < batch_size END REPEAT;
    
    -- 优化表
    OPTIMIZE TABLE jump_logs;
    OPTIMIZE TABLE audit_logs;
END//

-- 清理归档数据（保留1年）
DROP PROCEDURE IF EXISTS cleanup_archive//
CREATE PROCEDURE cleanup_archive(IN archive_retention_days INT)
BEGIN
    DELETE FROM jump_logs_archive 
    WHERE archived_at < DATE_SUB(NOW(), INTERVAL archive_retention_days DAY);
    
    DELETE FROM audit_logs_archive 
    WHERE archived_at < DATE_SUB(NOW(), INTERVAL archive_retention_days DAY);
END//

-- 审计日志归档存储过程 (来自 migrate_database_v2.sql)
DROP PROCEDURE IF EXISTS archive_old_audit_logs//
CREATE PROCEDURE archive_old_audit_logs(IN days_to_keep INT)
BEGIN
    DECLARE cutoff_date DATETIME;
    SET cutoff_date = DATE_SUB(NOW(), INTERVAL days_to_keep DAY);
    
    -- 插入到归档表
    INSERT INTO audit_logs_archive 
        (id, user_id, username, action, resource_type, resource_id, ip, user_agent, created_at)
    SELECT id, user_id, username, action, resource_type, resource_id, ip, user_agent, created_at
    FROM audit_logs
    WHERE created_at < cutoff_date;
    
    -- 删除原表数据
    DELETE FROM audit_logs WHERE created_at < cutoff_date;
    
    SELECT ROW_COUNT() AS archived_count;
END//

-- 指标采集存储过程 (来自 migrate_database_v2.sql)
DROP PROCEDURE IF EXISTS collect_metrics//
CREATE PROCEDURE collect_metrics()
BEGIN
    -- 总规则数
    INSERT INTO metrics_snapshot (metric_name, metric_value, labels)
    SELECT 'ip_jump_total_rules', COUNT(*), '{"type": "all"}' FROM jump_rules;
    
    -- 启用的规则数
    INSERT INTO metrics_snapshot (metric_name, metric_value, labels)
    SELECT 'ip_jump_enabled_rules', COUNT(*), '{"type": "enabled"}' FROM jump_rules WHERE enabled = 1;
    
    -- 按类型统计
    INSERT INTO metrics_snapshot (metric_name, metric_value, labels)
    SELECT 'ip_jump_rules_by_type', COUNT(*), JSON_OBJECT('rule_type', rule_type)
    FROM jump_rules GROUP BY rule_type;
    
    -- 今日访问量
    INSERT INTO metrics_snapshot (metric_name, metric_value, labels)
    SELECT 'ip_jump_today_requests', COUNT(*), '{"period": "today"}'
    FROM jump_logs WHERE DATE(visited_at) = CURDATE();
END//

-- access_logs 分区维护存储过程 (来自 migrate_database_v2.sql)
DROP PROCEDURE IF EXISTS maintain_access_logs_partitions//
CREATE PROCEDURE maintain_access_logs_partitions()
BEGIN
    DECLARE next_month DATE;
    DECLARE partition_name VARCHAR(20);
    DECLARE partition_value INT;
    DECLARE has_partitions INT DEFAULT 0;
    
    -- 检查表是否有分区
    SELECT COUNT(*) INTO has_partitions
    FROM information_schema.PARTITIONS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'access_logs' 
    AND PARTITION_NAME IS NOT NULL;
    
    IF has_partitions > 0 THEN
        SET next_month = DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 1 DAY);
        SET partition_name = CONCAT('p', DATE_FORMAT(next_month, '%Y%m'));
        SET partition_value = TO_DAYS(DATE_ADD(next_month, INTERVAL 1 MONTH));
        
        -- 检查分区是否存在
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.PARTITIONS 
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'access_logs' 
            AND PARTITION_NAME = partition_name
        ) THEN
            SET @sql = CONCAT(
                'ALTER TABLE access_logs REORGANIZE PARTITION p_future INTO (',
                'PARTITION ', partition_name, ' VALUES LESS THAN (', partition_value, '),',
                'PARTITION p_future VALUES LESS THAN MAXVALUE)'
            );
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END IF;
        
        -- 删除超过 90 天的分区
        BEGIN
            DECLARE done INT DEFAULT FALSE;
            DECLARE old_partition VARCHAR(20);
            DECLARE cur CURSOR FOR 
                SELECT PARTITION_NAME 
                FROM information_schema.PARTITIONS 
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'access_logs'
                AND PARTITION_NAME != 'p_future'
                AND PARTITION_NAME IS NOT NULL
                AND PARTITION_DESCRIPTION < TO_DAYS(DATE_SUB(CURDATE(), INTERVAL 90 DAY));
            DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
            
            OPEN cur;
            read_loop: LOOP
                FETCH cur INTO old_partition;
                IF done THEN
                    LEAVE read_loop;
                END IF;
                
                SET @drop_sql = CONCAT('ALTER TABLE access_logs DROP PARTITION ', old_partition);
                PREPARE drop_stmt FROM @drop_sql;
                EXECUTE drop_stmt;
                DEALLOCATE PREPARE drop_stmt;
            END LOOP;
            CLOSE cur;
        END;
    END IF;
END//

DELIMITER ;

-- =====================================================
-- 第十六部分: 定时事件
-- =====================================================

-- 启用事件调度器
SET GLOBAL event_scheduler = ON;

-- 每日清理事件
DROP EVENT IF EXISTS daily_cleanup;
CREATE EVENT IF NOT EXISTS daily_cleanup
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_DATE + INTERVAL 3 HOUR
DO
CALL cleanup_old_data();

-- 每小时统计聚合事件
DROP EVENT IF EXISTS hourly_stats_aggregation;
CREATE EVENT IF NOT EXISTS hourly_stats_aggregation
ON SCHEDULE EVERY 1 HOUR
STARTS CURRENT_TIMESTAMP + INTERVAL 5 MINUTE
DO
CALL aggregate_hourly_stats();

-- 每日统计聚合事件
DROP EVENT IF EXISTS daily_stats_aggregation;
CREATE EVENT IF NOT EXISTS daily_stats_aggregation
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_DATE + INTERVAL 1 DAY + INTERVAL 1 HOUR
DO
CALL aggregate_daily_stats();

-- 每周归档事件（保留90天在线数据）
DROP EVENT IF EXISTS weekly_archive;
CREATE EVENT IF NOT EXISTS weekly_archive
ON SCHEDULE EVERY 1 WEEK
STARTS CURRENT_DATE + INTERVAL 1 DAY + INTERVAL 2 HOUR
DO
CALL archive_old_logs(90);

-- 每月清理归档（保留365天归档）
DROP EVENT IF EXISTS monthly_archive_cleanup;
CREATE EVENT IF NOT EXISTS monthly_archive_cleanup
ON SCHEDULE EVERY 1 MONTH
STARTS CURRENT_DATE + INTERVAL 1 MONTH + INTERVAL 3 HOUR
DO
CALL cleanup_archive(365);

-- 分区维护事件（每天凌晨3点执行）
DROP EVENT IF EXISTS evt_maintain_partitions;
CREATE EVENT IF NOT EXISTS evt_maintain_partitions
ON SCHEDULE EVERY 1 DAY
STARTS CONCAT(CURDATE() + INTERVAL 1 DAY, ' 03:00:00')
DO CALL maintain_access_logs_partitions();

-- 指标采集事件（每分钟执行）
DROP EVENT IF EXISTS evt_collect_metrics;
CREATE EVENT IF NOT EXISTS evt_collect_metrics
ON SCHEDULE EVERY 1 MINUTE
DO CALL collect_metrics();

-- 清理旧指标数据事件（保留7天，每天凌晨4点）
DROP EVENT IF EXISTS evt_cleanup_metrics;
CREATE EVENT IF NOT EXISTS evt_cleanup_metrics
ON SCHEDULE EVERY 1 DAY
STARTS CONCAT(CURDATE() + INTERVAL 1 DAY, ' 04:00:00')
DO DELETE FROM metrics_snapshot WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 7 DAY);

-- =====================================================
-- 第十七部分: 性能优化索引
-- =====================================================

-- 注意: 使用存储过程安全添加索引，避免重复创建错误
DELIMITER //

DROP PROCEDURE IF EXISTS safe_add_index//
CREATE PROCEDURE safe_add_index(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_columns VARCHAR(255)
)
BEGIN
    DECLARE index_exists INT DEFAULT 0;
    
    SELECT COUNT(*) INTO index_exists
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table
      AND INDEX_NAME = p_index;
    
    IF index_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE ', p_table, ' ADD INDEX ', p_index, ' (', p_columns, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//

DELIMITER ;

-- Dashboard 查询优化索引
CALL safe_add_index('jump_logs', 'idx_visited_device', 'visited_at, device_type');
CALL safe_add_index('jump_logs', 'idx_visited_country', 'visited_at, country_code');

-- 审计日志查询优化
CALL safe_add_index('audit_logs', 'idx_created_action', 'created_at, action');
CALL safe_add_index('audit_logs', 'idx_user_created', 'user_id, created_at');

-- API Token 查询优化
CALL safe_add_index('api_tokens', 'idx_enabled_expires', 'enabled, expires_at');

-- 短链接查询优化  
CALL safe_add_index('short_links', 'idx_code_enabled', 'code, enabled');

-- 清理临时存储过程
DROP PROCEDURE IF EXISTS safe_add_index;

-- =====================================================
-- 第十八部分: 读写分离健康检查存储过程 (来自 migrate_database_v2.sql)
-- =====================================================

-- 注意: MySQL 视图不支持系统变量，改用存储过程
DROP PROCEDURE IF EXISTS sp_get_replication_status;
DELIMITER //
CREATE PROCEDURE sp_get_replication_status()
BEGIN
    SELECT 
        @@hostname AS server,
        @@read_only AS is_readonly,
        @@server_id AS server_id,
        (SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE COMMAND != 'Sleep') AS active_connections,
        (SELECT SUM(VARIABLE_VALUE) FROM performance_schema.global_status 
         WHERE VARIABLE_NAME IN ('Com_select', 'Com_insert', 'Com_update', 'Com_delete')) AS total_queries;
END //
DELIMITER ;

-- =====================================================
-- 完成
-- =====================================================

SET FOREIGN_KEY_CHECKS = 1;

SELECT '========================================' as '';
SELECT 'IP管理器数据库安装完成!' as status;
SELECT '版本: 4.0 (完整合并版)' as version;
SELECT CONCAT('表数量: ', COUNT(*)) as tables_count FROM information_schema.tables WHERE table_schema = DATABASE();
SELECT '========================================' as '';
SELECT '默认管理员: admin / admin123' as admin_info;
SELECT '请立即修改默认密码!' as warning;
SELECT '========================================' as '';
SELECT '包含内容:' as '';
SELECT '- 完整数据库结构' as '';
SELECT '- 初始数据 (用户/权限/IP黑名单)' as '';
SELECT '- 存储过程 (清理/归档/统计)' as '';
SELECT '- 定时事件 (自动维护)' as '';
SELECT '- 性能优化索引' as '';
SELECT '========================================' as '';
