-- IP管理器数据库结构
-- 使用: mysql -u root ip_manager < database.sql

USE ip_manager;

-- 系统配置表
CREATE TABLE IF NOT EXISTS config (
    `key` VARCHAR(100) PRIMARY KEY,
    `value` TEXT,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- IP跳转规则表
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

-- IP池表
CREATE TABLE IF NOT EXISTS ip_pool (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 访问统计表
CREATE TABLE IF NOT EXISTS visit_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    target_ip VARCHAR(45) NOT NULL,
    total_clicks INT DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_target_ip (target_ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 访问记录表
CREATE TABLE IF NOT EXISTS visit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    target_ip VARCHAR(45) NOT NULL,
    visitor_ip VARCHAR(45) NOT NULL,
    country VARCHAR(100) DEFAULT '',
    user_agent VARCHAR(500) DEFAULT '',
    visited_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_target_ip (target_ip),
    INDEX idx_visitor_ip (visitor_ip),
    INDEX idx_visited_at (visited_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 独立访客表
CREATE TABLE IF NOT EXISTS unique_visitors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    target_ip VARCHAR(45) NOT NULL,
    visitor_ip VARCHAR(45) NOT NULL,
    first_visit DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_target_visitor (target_ip, visitor_ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

-- 反爬虫IP黑名单表
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

-- IP国家缓存表
CREATE TABLE IF NOT EXISTS ip_country_cache (
    ip VARCHAR(45) PRIMARY KEY,
    country VARCHAR(100) NOT NULL,
    country_code VARCHAR(10) DEFAULT '',
    cached_at INT NOT NULL,
    INDEX idx_cached_at (cached_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 插入默认配置
INSERT INTO config (`key`, `value`) VALUES 
('admin_password', 'admin123'),
('admin_allowed_ips', '["127.0.0.1", "::1"]'),
('admin_secret_key', '')
ON DUPLICATE KEY UPDATE `key`=`key`;

-- 插入默认反爬虫配置
INSERT INTO antibot_config (`key`, `value`) VALUES 
('enabled', 'true'),
('rate_limit', '{"enabled":true,"max_requests":60,"time_window":60,"block_duration":3600}'),
('ua_check', '{"enabled":true,"block_empty_ua":true,"block_known_bots":true,"whitelist":[]}'),
('header_check', '{"enabled":true,"check_required_headers":true}'),
('behavior_check', '{"enabled":true,"max_404_count":10,"suspicious_paths":5,"time_window":300}'),
('honeypot', '{"enabled":true,"auto_block":true}'),
('auto_blacklist', '{"enabled":true,"max_blocks":5,"time_window":300,"exclude_reasons":[]}'),
('bad_ip_database', '{"enabled":true,"block_malicious":true,"block_datacenter":false,"block_known_bots":false}'),
('log_blocked', 'true')
ON DUPLICATE KEY UPDATE `key`=`key`;

-- 初始化统计总数
INSERT INTO antibot_stats (reason, count) VALUES ('total_blocked', 0)
ON DUPLICATE KEY UPDATE reason=reason;
