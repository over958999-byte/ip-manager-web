-- =====================================================
-- IP管理器 - 完整数据库安装脚本
-- 版本: 3.0 (合并版)
-- 说明: 包含所有表结构、索引、存储过程、初始数据
-- 合并自: install.sql, migrate_v2.sql, migrate_ip_blacklist.sql, migrate_performance.sql
-- =====================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- =====================================================
-- 创建数据库
-- =====================================================
CREATE DATABASE IF NOT EXISTS ip_manager 
    DEFAULT CHARACTER SET utf8mb4 
    COLLATE utf8mb4_unicode_ci;

USE ip_manager;

-- =====================================================
-- 第一部分: 核心配置表
-- =====================================================

-- 系统配置表
CREATE TABLE IF NOT EXISTS config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) NOT NULL UNIQUE,
    `value` TEXT,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 初始配置
INSERT INTO config (`key`, `value`, description) VALUES
('cf_api_token', '', 'Cloudflare API Token'),
('cf_zone_id', '', 'Cloudflare Zone ID'),
('max_ips_per_pool', '1000', '每个资源池最大IP数量'),
('default_ttl', '3600', '默认缓存TTL(秒)'),
('redirect_mode', 'http', '跳转模式: http/js'),
('log_retention_days', '30', '日志保留天数'),
('anti_bot_enabled', '1', '是否启用反爬'),
('rate_limit_per_minute', '60', '每分钟请求限制'),
('cache_driver', 'redis', '缓存驱动: apcu/redis/file'),
('geoip_provider', 'ipinfo', 'GeoIP提供商: ipinfo/maxmind/ip2location'),
('enable_prometheus', '0', '是否启用Prometheus监控'),
('enable_audit_log', '1', '是否启用审计日志'),
('session_timeout', '3600', '会话超时时间(秒)'),
('max_login_attempts', '5', '最大登录尝试次数'),
('password_min_length', '8', '密码最小长度'),
('enable_two_factor', '0', '是否启用两步验证'),
('api_rate_limit', '100', 'API每分钟限制'),
('webhook_timeout', '30', 'Webhook超时时间(秒)'),
('backup_retention_days', '7', '备份保留天数'),
('maintenance_mode', '0', '维护模式')
ON DUPLICATE KEY UPDATE `key`=`key`;

-- =====================================================
-- 第二部分: IP相关表
-- =====================================================

-- IP国家缓存表
CREATE TABLE IF NOT EXISTS ip_country_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    country_code VARCHAR(2),
    country_name VARCHAR(100),
    region VARCHAR(100),
    city VARCHAR(100),
    isp VARCHAR(255),
    org VARCHAR(255),
    asn VARCHAR(50),
    is_proxy TINYINT(1) DEFAULT 0,
    is_vpn TINYINT(1) DEFAULT 0,
    is_tor TINYINT(1) DEFAULT 0,
    is_datacenter TINYINT(1) DEFAULT 0,
    threat_level ENUM('low', 'medium', 'high', 'critical') DEFAULT 'low',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_ip (ip),
    INDEX idx_country (country_code),
    INDEX idx_threat (threat_level),
    INDEX idx_updated (updated_at),
    INDEX idx_ip_country (ip, country_code),
    INDEX idx_proxy_check (is_proxy, is_vpn, is_tor, is_datacenter)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- IP黑名单表
CREATE TABLE IF NOT EXISTS ip_blacklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_cidr VARCHAR(50) NOT NULL COMMENT 'IP或CIDR',
    ip_start INT UNSIGNED NOT NULL COMMENT 'IP范围起始',
    ip_end INT UNSIGNED NOT NULL COMMENT 'IP范围结束',
    type ENUM('bot', 'malicious', 'custom') DEFAULT 'custom' COMMENT '类型',
    category VARCHAR(50) DEFAULT NULL COMMENT '分类',
    name VARCHAR(100) DEFAULT NULL COMMENT '名称',
    description TEXT COMMENT '说明',
    source VARCHAR(50) DEFAULT 'manual' COMMENT '数据来源: manual/threat_intel/auto',
    enabled TINYINT(1) DEFAULT 1 COMMENT '是否启用',
    hit_count INT UNSIGNED DEFAULT 0 COMMENT '命中次数',
    last_hit_at TIMESTAMP NULL COMMENT '最后命中时间',
    expires_at TIMESTAMP NULL COMMENT '过期时间',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_cidr (ip_cidr),
    INDEX idx_ip_range (ip_start, ip_end),
    INDEX idx_type (type),
    INDEX idx_category (category),
    INDEX idx_enabled (enabled),
    INDEX idx_source (source),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 第三部分: Cloudflare域名管理
-- =====================================================

-- CF域名表
CREATE TABLE IF NOT EXISTS cf_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    zone_id VARCHAR(50),
    dns_record_id VARCHAR(50),
    target_ip VARCHAR(45),
    status ENUM('active', 'pending', 'inactive', 'error') DEFAULT 'pending',
    ssl_status ENUM('none', 'flexible', 'full', 'strict') DEFAULT 'flexible',
    proxy_status TINYINT(1) DEFAULT 1,
    error_message TEXT,
    last_check_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_domain (domain),
    INDEX idx_status (status),
    INDEX idx_zone (zone_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 第四部分: 跳转规则系统
-- =====================================================

-- 跳转域名表
CREATE TABLE IF NOT EXISTS jump_domains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL COMMENT '域名',
    name VARCHAR(100) COMMENT '备注名称',
    status ENUM('active', 'inactive', 'banned') DEFAULT 'active',
    ssl_enabled TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_domain (domain),
    INDEX idx_status (status),
    INDEX idx_domain_status (domain, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 跳转规则分组表
CREATE TABLE IF NOT EXISTS jump_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL COMMENT '分组名称',
    description TEXT COMMENT '描述',
    priority INT DEFAULT 0 COMMENT '优先级',
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_priority (priority),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 跳转规则表
CREATE TABLE IF NOT EXISTS jump_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT DEFAULT NULL COMMENT '分组ID',
    name VARCHAR(100) NOT NULL COMMENT '规则名称',
    source_domain VARCHAR(255) NOT NULL COMMENT '来源域名',
    source_path VARCHAR(500) DEFAULT '/' COMMENT '来源路径',
    target_url TEXT NOT NULL COMMENT '目标URL',
    countries VARCHAR(500) DEFAULT '' COMMENT '允许的国家代码,逗号分隔',
    excluded_countries VARCHAR(500) DEFAULT '' COMMENT '排除的国家代码',
    redirect_type ENUM('301', '302', '307', '308', 'js', 'meta', 'iframe') DEFAULT '302',
    match_mode ENUM('exact', 'prefix', 'regex', 'wildcard') DEFAULT 'exact',
    priority INT DEFAULT 0 COMMENT '优先级,数字越大越优先',
    weight INT DEFAULT 100 COMMENT '权重(用于A/B测试)',
    status ENUM('active', 'inactive', 'testing') DEFAULT 'active',
    
    -- 时间控制
    start_time TIMESTAMP NULL COMMENT '开始时间',
    end_time TIMESTAMP NULL COMMENT '结束时间',
    
    -- 设备/浏览器过滤
    device_types VARCHAR(100) DEFAULT '' COMMENT '设备类型: mobile,desktop,tablet',
    browsers VARCHAR(200) DEFAULT '' COMMENT '浏览器: chrome,firefox,safari',
    os_types VARCHAR(200) DEFAULT '' COMMENT '操作系统: windows,mac,ios,android',
    
    -- UA过滤
    allowed_ua_patterns TEXT COMMENT '允许的UA正则',
    blocked_ua_patterns TEXT COMMENT '阻止的UA正则',
    
    -- Referer过滤
    allowed_referers TEXT COMMENT '允许的Referer',
    blocked_referers TEXT COMMENT '阻止的Referer',
    
    -- IP过滤
    allowed_ips TEXT COMMENT '允许的IP列表',
    blocked_ips TEXT COMMENT '阻止的IP列表',
    
    -- 统计
    visit_count INT UNSIGNED DEFAULT 0 COMMENT '访问次数',
    unique_visitors INT UNSIGNED DEFAULT 0 COMMENT '独立访客',
    last_visit_at TIMESTAMP NULL COMMENT '最后访问时间',
    
    -- 元数据
    tags VARCHAR(500) DEFAULT '' COMMENT '标签',
    notes TEXT COMMENT '备注',
    created_by INT DEFAULT NULL COMMENT '创建者ID',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_source (source_domain, source_path),
    INDEX idx_group (group_id),
    INDEX idx_status (status),
    INDEX idx_priority (priority DESC),
    INDEX idx_domain_status_priority (source_domain, status, priority DESC),
    INDEX idx_countries (countries(100)),
    INDEX idx_time_range (start_time, end_time),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (group_id) REFERENCES jump_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 跳转日志表
CREATE TABLE IF NOT EXISTS jump_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
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
    visited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_rule (rule_id),
    INDEX idx_visited (visited_at),
    INDEX idx_ip (ip),
    INDEX idx_country (country_code),
    INDEX idx_domain_visited (domain, visited_at),
    INDEX idx_rule_visited (rule_id, visited_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 跳转UV统计表
CREATE TABLE IF NOT EXISTS jump_uv (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    rule_id INT NOT NULL,
    visitor_hash VARCHAR(64) NOT NULL COMMENT 'IP+UA的哈希',
    date DATE NOT NULL,
    visit_count INT DEFAULT 1,
    first_visit_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_visit_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_visitor (rule_id, visitor_hash, date),
    INDEX idx_date (date),
    INDEX idx_rule_date (rule_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 跳转每日统计表
CREATE TABLE IF NOT EXISTS jump_daily_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rule_id INT NOT NULL,
    date DATE NOT NULL,
    pv INT DEFAULT 0,
    uv INT DEFAULT 0,
    country_stats JSON COMMENT '国家统计',
    device_stats JSON COMMENT '设备统计',
    referer_stats JSON COMMENT '来源统计',
    hourly_stats JSON COMMENT '小时统计',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_stat (rule_id, date),
    INDEX idx_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 短链接表
CREATE TABLE IF NOT EXISTS short_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL COMMENT '短码',
    original_url TEXT NOT NULL COMMENT '原始URL',
    domain VARCHAR(255) DEFAULT NULL COMMENT '绑定域名',
    group_tag VARCHAR(100) DEFAULT NULL COMMENT '分组标签',
    
    -- 跳转设置
    redirect_type ENUM('301', '302', '307', 'js', 'meta') DEFAULT '302',
    
    -- 过滤条件
    countries VARCHAR(500) DEFAULT '' COMMENT '允许国家',
    excluded_countries VARCHAR(500) DEFAULT '' COMMENT '排除国家',
    device_types VARCHAR(100) DEFAULT '' COMMENT '设备类型',
    
    -- 状态和统计
    enabled TINYINT(1) DEFAULT 1,
    total_clicks INT UNSIGNED DEFAULT 0,
    unique_clicks INT UNSIGNED DEFAULT 0,
    last_click_at TIMESTAMP NULL,
    
    -- 时间控制
    expires_at TIMESTAMP NULL,
    
    -- 备注
    note TEXT,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_code (code),
    INDEX idx_domain (domain),
    INDEX idx_group (group_tag),
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 第五部分: 反爬/验证系统
-- =====================================================

-- 反爬配置表
CREATE TABLE IF NOT EXISTS antibot_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    enabled TINYINT(1) DEFAULT 1,
    mode ENUM('off', 'monitor', 'challenge', 'block') DEFAULT 'challenge',
    
    -- 检测阈值
    rate_limit INT DEFAULT 60 COMMENT '每分钟请求限制',
    burst_limit INT DEFAULT 10 COMMENT '突发请求限制',
    session_limit INT DEFAULT 1000 COMMENT '会话请求限制',
    
    -- 检测规则
    check_js TINYINT(1) DEFAULT 1 COMMENT 'JS挑战',
    check_captcha TINYINT(1) DEFAULT 0 COMMENT '验证码',
    check_fingerprint TINYINT(1) DEFAULT 1 COMMENT '浏览器指纹',
    check_behavior TINYINT(1) DEFAULT 1 COMMENT '行为分析',
    
    -- 白名单
    whitelist_ips TEXT COMMENT 'IP白名单',
    whitelist_ua TEXT COMMENT 'UA白名单',
    whitelist_referers TEXT COMMENT 'Referer白名单',
    
    -- 响应配置
    block_message TEXT COMMENT '封禁消息',
    challenge_page TEXT COMMENT '挑战页面HTML',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_domain (domain),
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 反爬日志表
CREATE TABLE IF NOT EXISTS antibot_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255),
    ip VARCHAR(45) NOT NULL,
    action ENUM('allow', 'challenge', 'block', 'whitelist') NOT NULL,
    reason VARCHAR(100),
    score INT DEFAULT 0,
    user_agent TEXT,
    fingerprint VARCHAR(64),
    request_path VARCHAR(500),
    country_code VARCHAR(2),
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_ip (ip),
    INDEX idx_domain (domain),
    INDEX idx_action (action),
    INDEX idx_logged (logged_at),
    INDEX idx_ip_logged (ip, logged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 临时封禁表
CREATE TABLE IF NOT EXISTS antibot_blocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    domain VARCHAR(255),
    reason VARCHAR(100),
    blocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    until_at TIMESTAMP NOT NULL,
    
    UNIQUE KEY unique_block (ip, domain),
    INDEX idx_until (until_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 验证会话表
CREATE TABLE IF NOT EXISTS antibot_sessions (
    id VARCHAR(64) PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    domain VARCHAR(255),
    verified TINYINT(1) DEFAULT 0,
    challenge_type VARCHAR(20),
    challenge_data TEXT,
    attempts INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    verified_at TIMESTAMP NULL,
    expires_at TIMESTAMP NOT NULL,
    
    INDEX idx_ip (ip),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 反爬虫统计表
CREATE TABLE IF NOT EXISTS antibot_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reason VARCHAR(100) NOT NULL,
    count INT UNSIGNED DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_reason (reason)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 初始化统计数据
INSERT INTO antibot_stats (reason, count) VALUES ('total_blocked', 0) ON DUPLICATE KEY UPDATE reason=reason;

-- 反爬虫黑名单表
CREATE TABLE IF NOT EXISTS antibot_blacklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    reason VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_ip (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 反爬虫白名单表
CREATE TABLE IF NOT EXISTS antibot_whitelist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    note VARCHAR(255) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_ip (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 第六部分: 用户与权限系统
-- =====================================================

-- 登录尝试表
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    username VARCHAR(100),
    success TINYINT(1) DEFAULT 0,
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip (ip),
    INDEX idx_created (created_at),
    INDEX idx_ip_created (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 用户表
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    role ENUM('admin', 'operator', 'viewer') DEFAULT 'viewer',
    status ENUM('active', 'inactive', 'locked') DEFAULT 'active',
    
    -- 两步验证
    two_factor_enabled TINYINT(1) DEFAULT 0,
    two_factor_secret VARCHAR(64),
    
    -- 密码策略
    password_changed_at TIMESTAMP NULL,
    password_expires_at TIMESTAMP NULL,
    must_change_password TINYINT(1) DEFAULT 0,
    
    -- 登录信息
    last_login_at TIMESTAMP NULL,
    last_login_ip VARCHAR(45),
    login_count INT DEFAULT 0,
    failed_login_count INT DEFAULT 0,
    locked_until TIMESTAMP NULL,
    
    -- API访问
    api_enabled TINYINT(1) DEFAULT 0,
    api_key VARCHAR(64),
    api_key_expires_at TIMESTAMP NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_username (username),
    UNIQUE KEY unique_email (email),
    INDEX idx_role (role),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 角色权限表
CREATE TABLE IF NOT EXISTS role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role ENUM('admin', 'operator', 'viewer') NOT NULL,
    resource VARCHAR(50) NOT NULL COMMENT '资源: domains, rules, users, settings等',
    action VARCHAR(20) NOT NULL COMMENT '操作: create, read, update, delete, export',
    allowed TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_permission (role, resource, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API密钥表
CREATE TABLE IF NOT EXISTS api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    name VARCHAR(100) NOT NULL COMMENT '密钥名称',
    api_key VARCHAR(64) NOT NULL,
    permissions JSON COMMENT '权限列表',
    rate_limit INT DEFAULT 100 COMMENT '每分钟限制',
    
    last_used_at TIMESTAMP NULL,
    request_count BIGINT DEFAULT 0,
    status ENUM('active', 'inactive', 'revoked') DEFAULT 'active',
    expires_at TIMESTAMP NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_key (api_key),
    INDEX idx_user (user_id),
    INDEX idx_status (status),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API Token表 (用于API访问)
CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL COMMENT 'Token名称',
    token VARCHAR(64) NOT NULL COMMENT 'API Token',
    permissions JSON COMMENT '权限配置',
    rate_limit INT DEFAULT 100 COMMENT '每分钟限制',
    enabled TINYINT(1) DEFAULT 1 COMMENT '是否启用',
    note TEXT COMMENT '备注',
    
    last_used_at TIMESTAMP NULL,
    call_count BIGINT DEFAULT 0,
    expires_at TIMESTAMP NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_token (token),
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 第七部分: 审计与日志系统
-- =====================================================

-- 审计日志表
CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    username VARCHAR(50),
    action VARCHAR(50) NOT NULL COMMENT '操作类型',
    resource_type VARCHAR(50) COMMENT '资源类型',
    resource_id VARCHAR(50) COMMENT '资源ID',
    old_value JSON COMMENT '修改前',
    new_value JSON COMMENT '修改后',
    ip VARCHAR(45),
    user_agent TEXT,
    status ENUM('success', 'failure') DEFAULT 'success',
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_resource (resource_type, resource_id),
    INDEX idx_created (created_at),
    INDEX idx_user_created (user_id, created_at),
    INDEX idx_action_created (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 系统日志表
CREATE TABLE IF NOT EXISTS system_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    level ENUM('DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL') DEFAULT 'INFO',
    channel VARCHAR(50) DEFAULT 'app' COMMENT '日志通道',
    message TEXT NOT NULL,
    context JSON COMMENT '上下文数据',
    extra JSON COMMENT '额外信息',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_level (level),
    INDEX idx_channel (channel),
    INDEX idx_created (created_at),
    INDEX idx_level_created (level, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 第八部分: 域名安全检测
-- =====================================================

-- 域名安全检测日志
CREATE TABLE IF NOT EXISTS domain_safety_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    check_type VARCHAR(50) COMMENT '检测类型: google_safe_browsing, phishtank等',
    is_safe TINYINT(1) DEFAULT 1,
    threat_type VARCHAR(100),
    threat_detail JSON,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_domain (domain),
    INDEX idx_checked (checked_at),
    INDEX idx_safe (is_safe)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 威胁情报源配置
CREATE TABLE IF NOT EXISTS threat_intel_sources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL COMMENT '名称',
    type ENUM('ip', 'domain', 'url') NOT NULL COMMENT '类型',
    url TEXT NOT NULL COMMENT '订阅URL',
    format ENUM('plain', 'csv', 'json') DEFAULT 'plain' COMMENT '格式',
    enabled TINYINT(1) DEFAULT 1,
    update_interval INT DEFAULT 3600 COMMENT '更新间隔(秒)',
    last_updated_at TIMESTAMP NULL,
    last_count INT DEFAULT 0 COMMENT '上次获取数量',
    error_count INT DEFAULT 0,
    last_error TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_type (type),
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 第九部分: Webhook与通知系统
-- =====================================================

-- Webhook配置表
CREATE TABLE IF NOT EXISTS webhooks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    url TEXT NOT NULL,
    secret VARCHAR(100) COMMENT '签名密钥',
    events JSON NOT NULL COMMENT '订阅的事件列表',
    headers JSON COMMENT '自定义请求头',
    enabled TINYINT(1) DEFAULT 1,
    retry_count INT DEFAULT 3,
    timeout INT DEFAULT 30,
    
    last_triggered_at TIMESTAMP NULL,
    last_status_code INT,
    failure_count INT DEFAULT 0,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Webhook日志
CREATE TABLE IF NOT EXISTS webhook_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    webhook_id INT NOT NULL,
    event VARCHAR(50),
    payload JSON,
    response_code INT,
    response_body TEXT,
    duration_ms INT,
    status ENUM('success', 'failure', 'pending') DEFAULT 'pending',
    retry_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_webhook (webhook_id),
    INDEX idx_created (created_at),
    INDEX idx_status (status),
    FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 第十部分: 备份与监控系统
-- =====================================================

-- 备份日志表
CREATE TABLE IF NOT EXISTS backup_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('full', 'incremental', 'config') NOT NULL,
    filename VARCHAR(255) NOT NULL,
    filesize BIGINT DEFAULT 0,
    tables_count INT DEFAULT 0,
    rows_count BIGINT DEFAULT 0,
    duration_seconds INT DEFAULT 0,
    status ENUM('running', 'success', 'failure') DEFAULT 'running',
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

-- =====================================================
-- 第十一部分: 遗留兼容表(可选)
-- =====================================================

-- 旧版跳转表(兼容)
CREATE TABLE IF NOT EXISTS redirects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    short_code VARCHAR(50) NOT NULL,
    target_url TEXT NOT NULL,
    countries VARCHAR(255) DEFAULT '',
    redirect_type VARCHAR(10) DEFAULT '302',
    status ENUM('active', 'inactive') DEFAULT 'active',
    visit_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_code (short_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 旧版IP池表(兼容)
CREATE TABLE IF NOT EXISTS ip_pool (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    pool_name VARCHAR(50) DEFAULT 'default',
    country_code VARCHAR(2),
    status ENUM('active', 'inactive') DEFAULT 'active',
    last_used_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pool (pool_name),
    INDEX idx_country (country_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 旧版访问统计(兼容)
CREATE TABLE IF NOT EXISTS visit_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    redirect_id INT,
    date DATE NOT NULL,
    pv INT DEFAULT 0,
    uv INT DEFAULT 0,
    UNIQUE KEY unique_stat (redirect_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 旧版访问日志(兼容)
CREATE TABLE IF NOT EXISTS visit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    redirect_id INT,
    ip VARCHAR(45),
    country_code VARCHAR(2),
    user_agent TEXT,
    referer TEXT,
    visited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_redirect (redirect_id),
    INDEX idx_visited (visited_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 旧版独立访客表(兼容)
CREATE TABLE IF NOT EXISTS unique_visitors (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    redirect_id INT NOT NULL,
    visitor_hash VARCHAR(64) NOT NULL,
    date DATE NOT NULL,
    UNIQUE KEY unique_visitor (redirect_id, visitor_hash, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 第十二部分: 初始数据
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
-- 第十三部分: IP黑名单默认数据
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
-- 第十四部分: 存储过程
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

DELIMITER ;

-- =====================================================
-- 第十五部分: 定时事件
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

-- =====================================================
-- 第十六部分: 性能优化索引
-- =====================================================

-- Dashboard 查询优化索引
ALTER TABLE jump_logs ADD INDEX IF NOT EXISTS idx_visited_device (visited_at, device_type);
ALTER TABLE jump_logs ADD INDEX IF NOT EXISTS idx_visited_country (visited_at, country_code);
ALTER TABLE jump_logs ADD INDEX IF NOT EXISTS idx_date_rule (DATE(visited_at), rule_id);

-- 审计日志查询优化
ALTER TABLE audit_logs ADD INDEX IF NOT EXISTS idx_created_action (created_at, action);
ALTER TABLE audit_logs ADD INDEX IF NOT EXISTS idx_user_created (user_id, created_at);

-- API Token 查询优化
ALTER TABLE api_tokens ADD INDEX IF NOT EXISTS idx_enabled_expires (enabled, expires_at);

-- 短链接查询优化  
ALTER TABLE short_links ADD INDEX IF NOT EXISTS idx_code_enabled (code, enabled);

-- =====================================================
-- 第十七部分: 日志归档表
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
    ip VARCHAR(45),
    user_agent TEXT,
    status VARCHAR(20),
    created_at TIMESTAMP,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_created (created_at),
    INDEX idx_archived (archived_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- 第十八部分: 归档存储过程
-- =====================================================

DELIMITER //

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
        INSERT INTO audit_logs_archive 
        SELECT *, NOW() as archived_at 
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

DELIMITER ;

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

-- =====================================================
-- 完成
-- =====================================================

SET FOREIGN_KEY_CHECKS = 1;

SELECT '========================================' as '';
SELECT 'IP管理器数据库安装完成!' as status;
SELECT '版本: 3.0 (合并版)' as version;
SELECT CONCAT('表数量: ', COUNT(*)) as tables_count FROM information_schema.tables WHERE table_schema = 'ip_manager';
SELECT '========================================' as '';
SELECT '默认管理员: admin / admin123' as admin_info;
SELECT '请立即修改默认密码!' as warning;
SELECT '========================================' as '';
