-- =====================================================
-- IP管理器 - 完整数据库初始化脚本
-- 合并了所有表结构和初始数据
-- 执行: mysql -u root -p < install.sql
-- =====================================================

-- 创建数据库
CREATE DATABASE IF NOT EXISTS ip_manager DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
USE ip_manager;

-- =====================================================
-- 第一部分: 基础系统表
-- =====================================================

-- 系统配置表
CREATE TABLE IF NOT EXISTS config (
    `key` VARCHAR(100) PRIMARY KEY,
    `value` TEXT,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- IP国家缓存表
CREATE TABLE IF NOT EXISTS ip_country_cache (
    ip VARCHAR(45) PRIMARY KEY,
    country_code VARCHAR(10) NOT NULL,
    country_name VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_country_code (country_code),
    INDEX idx_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IP地理位置缓存';

-- =====================================================
-- 第二部分: 跳转规则系统
-- =====================================================

-- 域名管理表
CREATE TABLE IF NOT EXISTS jump_domains (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain VARCHAR(255) NOT NULL,
    name VARCHAR(100) DEFAULT '',
    is_default TINYINT(1) DEFAULT 0,
    enabled TINYINT(1) DEFAULT 1,
    use_count INT UNSIGNED DEFAULT 0,
    safety_status ENUM('unknown', 'safe', 'warning', 'danger') DEFAULT 'unknown' COMMENT '安全状态',
    safety_detail JSON DEFAULT NULL COMMENT '安全检测详情',
    last_check_at DATETIME DEFAULT NULL COMMENT '最后检测时间',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 统一跳转规则表
CREATE TABLE IF NOT EXISTS jump_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_type ENUM('ip', 'code') NOT NULL DEFAULT 'code' COMMENT '规则类型',
    match_key VARCHAR(100) NOT NULL COMMENT 'IP地址或短码',
    target_url VARCHAR(2048) NOT NULL COMMENT '目标URL',
    title VARCHAR(200) DEFAULT '',
    note VARCHAR(500) DEFAULT '',
    group_tag VARCHAR(50) DEFAULT 'default',
    domain_id INT UNSIGNED DEFAULT NULL,
    enabled TINYINT(1) DEFAULT 1,
    -- 设备限制 (IP跳转用)
    block_desktop TINYINT(1) DEFAULT 0,
    block_ios TINYINT(1) DEFAULT 0,
    block_android TINYINT(1) DEFAULT 0,
    -- 国家白名单 (IP跳转用)
    country_whitelist_enabled TINYINT(1) DEFAULT 0,
    country_whitelist JSON DEFAULT NULL,
    -- 过期设置 (短码跳转用)
    expire_type ENUM('permanent', 'datetime', 'clicks') DEFAULT 'permanent',
    expire_at DATETIME DEFAULT NULL,
    max_clicks INT UNSIGNED DEFAULT NULL,
    -- 统计数据
    total_clicks BIGINT UNSIGNED DEFAULT 0,
    unique_visitors INT UNSIGNED DEFAULT 0,
    last_access_at DATETIME DEFAULT NULL,
    -- 时间戳
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- 索引
    UNIQUE KEY uk_type_key (rule_type, match_key),
    KEY idx_enabled (enabled),
    KEY idx_group (group_tag),
    KEY idx_type (rule_type),
    KEY idx_domain (domain_id),
    KEY idx_created (created_at DESC),
    KEY idx_clicks (total_clicks DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;

-- 分组表
CREATE TABLE IF NOT EXISTS jump_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tag VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(500) DEFAULT '',
    rule_count INT UNSIGNED DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 访问日志表
CREATE TABLE IF NOT EXISTS jump_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id BIGINT UNSIGNED NOT NULL,
    rule_type ENUM('ip', 'code') NOT NULL,
    match_key VARCHAR(100) NOT NULL,
    visitor_ip VARCHAR(45) NOT NULL,
    country VARCHAR(100) DEFAULT '',
    device_type ENUM('desktop', 'mobile', 'tablet', 'ios', 'android', 'unknown') DEFAULT 'unknown',
    os VARCHAR(30) DEFAULT '',
    browser VARCHAR(30) DEFAULT '',
    user_agent VARCHAR(500) DEFAULT '',
    referer VARCHAR(500) DEFAULT '',
    visited_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rule (rule_id),
    KEY idx_type_key (rule_type, match_key),
    KEY idx_time (visited_at),
    KEY idx_visitor (visitor_ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- UV表
CREATE TABLE IF NOT EXISTS jump_uv (
    rule_id BIGINT UNSIGNED NOT NULL,
    visitor_hash BINARY(16) NOT NULL,
    first_visit DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rule_id, visitor_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 日统计表
CREATE TABLE IF NOT EXISTS jump_daily_stats (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id BIGINT UNSIGNED NOT NULL,
    stat_date DATE NOT NULL,
    clicks INT UNSIGNED DEFAULT 0,
    unique_visitors INT UNSIGNED DEFAULT 0,
    UNIQUE KEY uk_rule_date (rule_id, stat_date),
    KEY idx_date (stat_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 第三部分: 反爬虫系统
-- =====================================================

-- 反爬虫配置表
CREATE TABLE IF NOT EXISTS antibot_config (
    `key` VARCHAR(100) PRIMARY KEY,
    `value` TEXT,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 反爬虫请求记录表
CREATE TABLE IF NOT EXISTS antibot_requests (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    visitor_ip VARCHAR(45) NOT NULL,
    request_time INT NOT NULL,
    INDEX idx_ip_time (visitor_ip, request_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 反爬虫临时封禁表
CREATE TABLE IF NOT EXISTS antibot_blocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL UNIQUE,
    reason VARCHAR(200) DEFAULT '',
    blocked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    until_at DATETIME NOT NULL,
    INDEX idx_ip (ip),
    INDEX idx_until (until_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 反爬虫统计表
CREATE TABLE IF NOT EXISTS antibot_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reason VARCHAR(100) NOT NULL UNIQUE,
    count INT DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 反爬虫拦截日志表
CREATE TABLE IF NOT EXISTS antibot_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    visitor_ip VARCHAR(45) NOT NULL,
    target_ip VARCHAR(45) DEFAULT '',
    user_agent VARCHAR(500) DEFAULT '',
    request_uri VARCHAR(500) DEFAULT '',
    reason VARCHAR(100) NOT NULL,
    detail VARCHAR(500) DEFAULT '',
    logged_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_visitor_ip (visitor_ip),
    INDEX idx_logged_at (logged_at),
    INDEX idx_reason (reason)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 反爬虫IP黑名单表 (手动添加)
CREATE TABLE IF NOT EXISTS antibot_blacklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL UNIQUE,
    reason VARCHAR(500) DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 反爬虫IP白名单表
CREATE TABLE IF NOT EXISTS antibot_whitelist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 行为分析数据表
CREATE TABLE IF NOT EXISTS antibot_behavior (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    visitor_ip VARCHAR(45) NOT NULL,
    path VARCHAR(500) NOT NULL,
    suspicious TINYINT(1) DEFAULT 0,
    recorded_at INT NOT NULL,
    INDEX idx_ip_time (visitor_ip, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 第四部分: IP黑名单库 (数据库版)
-- =====================================================

-- IP黑名单库表
CREATE TABLE IF NOT EXISTS ip_blacklist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_cidr VARCHAR(50) NOT NULL COMMENT 'IP地址或CIDR段',
    ip_start BIGINT UNSIGNED NOT NULL COMMENT 'IP范围起始',
    ip_end BIGINT UNSIGNED NOT NULL COMMENT 'IP范围结束',
    type ENUM('malicious', 'bot', 'datacenter', 'proxy', 'custom') NOT NULL DEFAULT 'custom' COMMENT '类型',
    category VARCHAR(50) DEFAULT NULL COMMENT '分类',
    name VARCHAR(100) DEFAULT NULL COMMENT '名称描述',
    enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
    hit_count INT UNSIGNED DEFAULT 0 COMMENT '命中次数',
    last_hit_at DATETIME DEFAULT NULL COMMENT '最后命中时间',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ip_range (ip_start, ip_end),
    INDEX idx_type_enabled (type, enabled),
    INDEX idx_category (category),
    UNIQUE KEY uk_ip_cidr (ip_cidr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IP黑名单库';

-- 缓存版本表
CREATE TABLE IF NOT EXISTS ip_blacklist_version (
    id INT PRIMARY KEY DEFAULT 1,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 第五部分: 域名安全检测
-- =====================================================

-- 安全检测日志表
CREATE TABLE IF NOT EXISTS domain_safety_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    domain VARCHAR(255) NOT NULL,
    check_source VARCHAR(50) NOT NULL COMMENT '检测来源',
    status ENUM('safe', 'warning', 'danger') NOT NULL,
    detail TEXT DEFAULT NULL,
    checked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_domain_id (domain_id),
    INDEX idx_domain (domain),
    INDEX idx_checked_at (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 第六部分: 系统监控
-- =====================================================

-- 系统监控表
CREATE TABLE IF NOT EXISTS system_metrics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    metric_name VARCHAR(100) NOT NULL,
    metric_value DECIMAL(20,4) NOT NULL,
    tags JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_metric_name (metric_name),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统监控指标';

-- =====================================================
-- 第七部分: 旧表兼容 (可选保留)
-- =====================================================

-- IP跳转规则表 (旧版兼容)
CREATE TABLE IF NOT EXISTS redirects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL UNIQUE,
    url VARCHAR(2048) NOT NULL,
    note VARCHAR(500) DEFAULT '',
    enabled TINYINT(1) DEFAULT 1,
    block_desktop TINYINT(1) DEFAULT 0,
    block_ios TINYINT(1) DEFAULT 0,
    block_android TINYINT(1) DEFAULT 0,
    country_whitelist_enabled TINYINT(1) DEFAULT 0,
    country_whitelist JSON DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ip (ip),
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- IP池表 (旧版兼容)
CREATE TABLE IF NOT EXISTS ip_pool (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 访问统计表 (旧版兼容)
CREATE TABLE IF NOT EXISTS visit_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    target_ip VARCHAR(45) NOT NULL,
    total_clicks INT DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_target_ip (target_ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 访问记录表 (旧版兼容)
CREATE TABLE IF NOT EXISTS visit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    target_ip VARCHAR(45) NOT NULL,
    visitor_ip VARCHAR(45) NOT NULL,
    country VARCHAR(100) DEFAULT '',
    user_agent VARCHAR(500) DEFAULT '',
    visited_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_target_ip (target_ip),
    INDEX idx_visited_at (visited_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 独立访客表 (旧版兼容)
CREATE TABLE IF NOT EXISTS unique_visitors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    target_ip VARCHAR(45) NOT NULL,
    visitor_ip VARCHAR(45) NOT NULL,
    first_visit DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_target_visitor (target_ip, visitor_ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- 初始化数据
-- =====================================================

-- 系统配置
INSERT IGNORE INTO config (`key`, `value`) VALUES 
('admin_password', 'admin123'),
('admin_allowed_ips', '[]'),
('admin_secret_key', ''),
('jump_domain', 'http://localhost:8080/j/'),
('code_length', '6'),
('domain_safety_check', '{"enabled":true,"interval":60}');

-- 反爬虫配置
INSERT IGNORE INTO antibot_config (`key`, `value`) VALUES 
('enabled', 'true'),
('log_blocked', 'true'),
('rate_limit', '{"enabled":true,"max_requests":60,"time_window":60,"block_duration":3600}'),
('ua_check', '{"enabled":true,"block_empty_ua":true,"block_known_bots":true,"whitelist":[]}'),
('header_check', '{"enabled":true,"check_required_headers":true}'),
('behavior_check', '{"enabled":true,"max_404_count":10,"suspicious_paths":5,"time_window":300}'),
('honeypot', '{"enabled":true,"auto_block":true}'),
('auto_blacklist', '{"enabled":true,"max_blocks":5,"time_window":300,"exclude_reasons":[]}'),
('bad_ip_database', '{"enabled":true,"block_malicious":true,"block_datacenter":false,"block_known_bots":true}'),
('block_action', '{"type":"error_page","delay_min":100,"delay_max":500}');

-- 统计初始化
INSERT IGNORE INTO antibot_stats (reason, count) VALUES ('total_blocked', 0);

-- 默认分组
INSERT IGNORE INTO jump_groups (tag, name, description, sort_order) VALUES
('default', '默认分组', '未分类的跳转规则', 0),
('ip', 'IP跳转', 'IP跳转规则', 1),
('shortlink', '短链服务', '短链跳转规则', 2);

-- IP黑名单缓存版本
INSERT IGNORE INTO ip_blacklist_version (id, version) VALUES (1, 1);

-- =====================================================
-- IP黑名单库默认数据
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
-- 清理存储过程
-- =====================================================

DELIMITER //
CREATE PROCEDURE IF NOT EXISTS cleanup_old_data()
BEGIN
    -- 清理30天前的日志
    DELETE FROM jump_logs WHERE visited_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    DELETE FROM antibot_logs WHERE logged_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    DELETE FROM domain_safety_logs WHERE checked_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    -- 清理7天前的IP缓存
    DELETE FROM ip_country_cache WHERE updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
    
    -- 清理30天前的监控指标
    DELETE FROM system_metrics WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    -- 清理过期的临时封禁
    DELETE FROM antibot_blocks WHERE until_at < NOW();
END //
DELIMITER ;

SELECT 'Database installation completed!' as status;
