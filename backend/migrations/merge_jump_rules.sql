-- 合并跳转规则数据库迁移
-- 将 IP跳转 和 短链服务 合并为统一的跳转规则表
-- 执行: mysql -u root ip_manager < merge_jump_rules.sql

USE ip_manager;

-- 创建统一的跳转规则表
CREATE TABLE IF NOT EXISTS jump_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    
    -- 规则类型: ip=IP匹配跳转, code=短码跳转
    rule_type ENUM('ip', 'code') NOT NULL DEFAULT 'code',
    
    -- 匹配标识: IP地址 或 短码
    match_key VARCHAR(100) NOT NULL,
    
    -- 目标URL
    target_url VARCHAR(2048) NOT NULL,
    
    -- 基本信息
    title VARCHAR(200) DEFAULT '',
    note VARCHAR(500) DEFAULT '',
    group_tag VARCHAR(50) DEFAULT 'default',
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
    KEY idx_created (created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;

-- 迁移 redirects 表数据到 jump_rules
INSERT IGNORE INTO jump_rules (
    rule_type, match_key, target_url, note, enabled,
    block_desktop, block_ios, block_android,
    country_whitelist_enabled, country_whitelist,
    total_clicks, unique_visitors, created_at
)
SELECT 
    'ip' as rule_type,
    ip as match_key,
    url as target_url,
    COALESCE(note, '') as note,
    COALESCE(enabled, 1),
    COALESCE(block_desktop, 0),
    COALESCE(block_ios, 0),
    COALESCE(block_android, 0),
    COALESCE(country_whitelist_enabled, 0),
    country_whitelist,
    COALESCE((SELECT total_clicks FROM visit_stats WHERE target_ip = redirects.ip), 0),
    COALESCE((SELECT COUNT(*) FROM unique_visitors WHERE target_ip = redirects.ip), 0),
    COALESCE(created_at, NOW())
FROM redirects;

-- 迁移 short_links 表数据到 jump_rules
INSERT IGNORE INTO jump_rules (
    rule_type, match_key, target_url, title, group_tag, enabled,
    expire_type, expire_at, max_clicks,
    total_clicks, unique_visitors, last_access_at, created_at
)
SELECT 
    'code' as rule_type,
    code as match_key,
    original_url as target_url,
    COALESCE(title, '') as title,
    COALESCE(group_tag, 'default'),
    COALESCE(enabled, 1),
    COALESCE(expire_type, 'permanent'),
    expire_at,
    max_clicks,
    COALESCE(total_clicks, 0),
    COALESCE(unique_visitors, 0),
    last_access_at,
    COALESCE(created_at, NOW())
FROM short_links;

-- 统一的访问日志表
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

-- 迁移 visit_logs 到 jump_logs
INSERT IGNORE INTO jump_logs (rule_id, rule_type, match_key, visitor_ip, country, user_agent, visited_at)
SELECT 
    COALESCE((SELECT id FROM jump_rules WHERE rule_type='ip' AND match_key=vl.target_ip), 0),
    'ip',
    vl.target_ip,
    vl.visitor_ip,
    COALESCE(vl.country, ''),
    COALESCE(vl.user_agent, ''),
    vl.visited_at
FROM visit_logs vl;

-- 迁移 short_link_logs 到 jump_logs
INSERT IGNORE INTO jump_logs (rule_id, rule_type, match_key, visitor_ip, country, device_type, os, browser, referer, visited_at)
SELECT 
    COALESCE((SELECT id FROM jump_rules WHERE rule_type='code' AND match_key=sll.code), 0),
    'code',
    sll.code,
    sll.visitor_ip,
    COALESCE(sll.country, ''),
    COALESCE(sll.device_type, 'unknown'),
    COALESCE(sll.os, ''),
    COALESCE(sll.browser, ''),
    COALESCE(sll.referer, ''),
    sll.visited_at
FROM short_link_logs sll;

-- 统一的UV表
CREATE TABLE IF NOT EXISTS jump_uv (
    rule_id BIGINT UNSIGNED NOT NULL,
    visitor_hash BINARY(16) NOT NULL,
    first_visit DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rule_id, visitor_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 迁移 unique_visitors 到 jump_uv
INSERT IGNORE INTO jump_uv (rule_id, visitor_hash, first_visit)
SELECT 
    COALESCE((SELECT id FROM jump_rules WHERE rule_type='ip' AND match_key=uv.target_ip), 0),
    UNHEX(MD5(CONCAT(uv.target_ip, ':', uv.visitor_ip))),
    uv.first_visit
FROM unique_visitors uv;

-- 迁移 short_link_uv 到 jump_uv
INSERT IGNORE INTO jump_uv (rule_id, visitor_hash, first_visit)
SELECT 
    COALESCE((SELECT id FROM jump_rules WHERE rule_type='code' AND match_key=(SELECT code FROM short_links WHERE id=sluv.link_id)), 0),
    sluv.visitor_hash,
    sluv.first_visit
FROM short_link_uv sluv;

-- 分组表（沿用）
CREATE TABLE IF NOT EXISTS jump_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tag VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(500) DEFAULT '',
    rule_count INT UNSIGNED DEFAULT 0,
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 初始化默认分组
INSERT IGNORE INTO jump_groups (tag, name, description, sort_order) VALUES
('default', '默认分组', '未分类的跳转规则', 0),
('ip', 'IP跳转', 'IP跳转规则', 1),
('shortlink', '短链服务', '短链跳转规则', 2);

-- 更新分组统计
UPDATE jump_groups SET rule_count = (
    SELECT COUNT(*) FROM jump_rules WHERE group_tag = jump_groups.tag
);

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

-- 系统配置表（短链相关配置）
INSERT IGNORE INTO config (`key`, `value`) VALUES
('jump_domain', 'http://localhost:8080/j/'),
('code_length', '6');

SELECT 'Migration completed!' as status;
