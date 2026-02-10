-- 短链服务数据库结构
-- 执行: mysql -u root ip_manager < shortlink.sql

USE ip_manager;

-- 短链接主表（优化索引支持高并发查询）
CREATE TABLE IF NOT EXISTS short_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(10) NOT NULL,
    original_url VARCHAR(2048) NOT NULL,
    title VARCHAR(200) DEFAULT '',
    group_tag VARCHAR(50) DEFAULT 'default',
    enabled TINYINT(1) DEFAULT 1,
    expire_type ENUM('permanent', 'datetime', 'clicks') DEFAULT 'permanent',
    expire_at DATETIME DEFAULT NULL,
    max_clicks INT UNSIGNED DEFAULT NULL,
    total_clicks BIGINT UNSIGNED DEFAULT 0,
    unique_visitors INT UNSIGNED DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_access_at DATETIME DEFAULT NULL,
    UNIQUE KEY uk_code (code),
    KEY idx_enabled_code (enabled, code),
    KEY idx_group (group_tag),
    KEY idx_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;

-- 访问日志表
CREATE TABLE IF NOT EXISTS short_link_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    link_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(10) NOT NULL,
    visitor_ip VARCHAR(45) NOT NULL,
    country VARCHAR(50) DEFAULT '',
    device_type ENUM('desktop', 'mobile', 'tablet', 'unknown') DEFAULT 'unknown',
    os VARCHAR(30) DEFAULT '',
    browser VARCHAR(30) DEFAULT '',
    referer VARCHAR(500) DEFAULT '',
    visited_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_link_time (link_id, visited_at),
    KEY idx_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- UV 表（用于快速统计独立访客）
CREATE TABLE IF NOT EXISTS short_link_uv (
    link_id BIGINT UNSIGNED NOT NULL,
    visitor_hash BINARY(16) NOT NULL,
    first_visit DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (link_id, visitor_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 分组表
CREATE TABLE IF NOT EXISTS short_link_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tag VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(500) DEFAULT '',
    link_count INT UNSIGNED DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 每日统计表（用于图表）
CREATE TABLE IF NOT EXISTS short_link_daily_stats (
    link_id BIGINT UNSIGNED NOT NULL,
    stat_date DATE NOT NULL,
    clicks INT UNSIGNED DEFAULT 0,
    unique_visitors INT UNSIGNED DEFAULT 0,
    PRIMARY KEY (link_id, stat_date),
    KEY idx_date (stat_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 短链配置表
CREATE TABLE IF NOT EXISTS short_link_config (
    cfg_key VARCHAR(50) PRIMARY KEY,
    cfg_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 初始化数据
INSERT IGNORE INTO short_link_groups (tag, name, description, sort_order) VALUES 
('default', 'Default', 'Default group', 0),
('marketing', 'Marketing', 'Marketing links', 1),
('social', 'Social', 'Social media links', 2);

INSERT IGNORE INTO short_link_config (cfg_key, cfg_value) VALUES 
('domain', 'http://localhost:8080/public/s/'),
('code_length', '6'),
('log_days', '90');
