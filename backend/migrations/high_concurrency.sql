-- 高并发支持数据库迁移
-- 添加日志表和UV统计表

-- 访问日志表（用于异步写入）
CREATE TABLE IF NOT EXISTS jump_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id INT UNSIGNED NOT NULL,
    visitor_ip VARCHAR(45) NOT NULL,
    device_type ENUM('desktop', 'ios', 'android', 'mobile', 'unknown') DEFAULT 'unknown',
    user_agent VARCHAR(500),
    referer VARCHAR(500),
    country_code VARCHAR(10),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_rule_id (rule_id),
    INDEX idx_created_at (created_at),
    INDEX idx_visitor_ip (visitor_ip),
    INDEX idx_rule_date (rule_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='访问日志表';

-- UV统计表
CREATE TABLE IF NOT EXISTS jump_uv (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id INT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    uv_count INT UNSIGNED DEFAULT 0,
    pv_count INT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY uk_rule_date (rule_id, date),
    INDEX idx_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='UV统计表';

-- IP地理位置缓存表
CREATE TABLE IF NOT EXISTS ip_country_cache (
    ip VARCHAR(45) PRIMARY KEY,
    country_code VARCHAR(10) NOT NULL,
    country_name VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_country_code (country_code),
    INDEX idx_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IP地理位置缓存';

-- 添加jump_rules表的索引优化
ALTER TABLE jump_rules 
    ADD INDEX IF NOT EXISTS idx_type_key (rule_type, match_key),
    ADD INDEX IF NOT EXISTS idx_enabled (enabled),
    ADD INDEX IF NOT EXISTS idx_clicks (total_clicks DESC);

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

-- 定期清理存储过程
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS cleanup_old_data()
BEGIN
    -- 清理30天前的日志
    DELETE FROM jump_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    -- 清理90天前的UV统计
    DELETE FROM jump_uv WHERE date < DATE_SUB(NOW(), INTERVAL 90 DAY);
    
    -- 清理7天前的IP缓存
    DELETE FROM ip_country_cache WHERE updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
    
    -- 清理30天前的监控指标
    DELETE FROM system_metrics WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    -- 优化表
    OPTIMIZE TABLE jump_logs;
END //
DELIMITER ;

-- 创建定时事件（需要开启事件调度器）
-- SET GLOBAL event_scheduler = ON;
CREATE EVENT IF NOT EXISTS daily_cleanup
ON SCHEDULE EVERY 1 DAY
STARTS (TIMESTAMP(CURRENT_DATE) + INTERVAL 3 HOUR)
DO CALL cleanup_old_data();
