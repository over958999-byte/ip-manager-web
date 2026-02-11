-- ==============================================
-- 数据库性能优化脚本 V2
-- 执行前请先备份数据库
-- ==============================================

-- 1. 开启慢查询日志
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL slow_query_log_file = '/var/log/mysql/slow-query.log';
SET GLOBAL long_query_time = 1;
SET GLOBAL log_queries_not_using_indexes = 'ON';

-- 2. access_logs 表分区（按月）
-- 注意：需要先删除现有的普通索引，转换为分区表

ALTER TABLE access_logs DROP PRIMARY KEY;
ALTER TABLE access_logs ADD PRIMARY KEY (id, created_at);

ALTER TABLE access_logs
PARTITION BY RANGE (TO_DAYS(created_at)) (
    PARTITION p202601 VALUES LESS THAN (TO_DAYS('2026-02-01')),
    PARTITION p202602 VALUES LESS THAN (TO_DAYS('2026-03-01')),
    PARTITION p202603 VALUES LESS THAN (TO_DAYS('2026-04-01')),
    PARTITION p202604 VALUES LESS THAN (TO_DAYS('2026-05-01')),
    PARTITION p202605 VALUES LESS THAN (TO_DAYS('2026-06-01')),
    PARTITION p202606 VALUES LESS THAN (TO_DAYS('2026-07-01')),
    PARTITION p202607 VALUES LESS THAN (TO_DAYS('2026-08-01')),
    PARTITION p202608 VALUES LESS THAN (TO_DAYS('2026-09-01')),
    PARTITION p202609 VALUES LESS THAN (TO_DAYS('2026-10-01')),
    PARTITION p202610 VALUES LESS THAN (TO_DAYS('2026-11-01')),
    PARTITION p202611 VALUES LESS THAN (TO_DAYS('2026-12-01')),
    PARTITION p202612 VALUES LESS THAN (TO_DAYS('2027-01-01')),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);

-- 3. 自动分区维护存储过程
DELIMITER //

CREATE PROCEDURE IF NOT EXISTS maintain_access_logs_partitions()
BEGIN
    DECLARE next_month DATE;
    DECLARE partition_name VARCHAR(20);
    DECLARE partition_value INT;
    
    SET next_month = DATE_ADD(LAST_DAY(CURDATE()), INTERVAL 1 DAY);
    SET partition_name = CONCAT('p', DATE_FORMAT(next_month, '%Y%m'));
    SET partition_value = TO_DAYS(DATE_ADD(next_month, INTERVAL 1 MONTH));
    
    -- 检查分区是否存在
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.PARTITIONS 
        WHERE TABLE_NAME = 'access_logs' 
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
            WHERE TABLE_NAME = 'access_logs'
            AND PARTITION_NAME != 'p_future'
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
END //

DELIMITER ;

-- 4. 创建定时事件（每天凌晨 3 点执行）
CREATE EVENT IF NOT EXISTS evt_maintain_partitions
ON SCHEDULE EVERY 1 DAY
STARTS CONCAT(CURDATE() + INTERVAL 1 DAY, ' 03:00:00')
DO CALL maintain_access_logs_partitions();

-- 5. 审计日志归档表
CREATE TABLE IF NOT EXISTS audit_logs_archive (
    id BIGINT PRIMARY KEY,
    user_id INT,
    username VARCHAR(50),
    action VARCHAR(100),
    resource_type VARCHAR(50),
    resource_id VARCHAR(100),
    details JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at DATETIME,
    archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_archived_date (archived_at),
    INDEX idx_original_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='审计日志归档表'
ROW_FORMAT=COMPRESSED;

-- 6. 审计日志归档存储过程
DELIMITER //

CREATE PROCEDURE IF NOT EXISTS archive_old_audit_logs(IN days_to_keep INT)
BEGIN
    DECLARE cutoff_date DATETIME;
    SET cutoff_date = DATE_SUB(NOW(), INTERVAL days_to_keep DAY);
    
    -- 插入到归档表
    INSERT INTO audit_logs_archive 
        (id, user_id, username, action, resource_type, resource_id, details, ip_address, user_agent, created_at)
    SELECT id, user_id, username, action, resource_type, resource_id, details, ip_address, user_agent, created_at
    FROM audit_logs
    WHERE created_at < cutoff_date;
    
    -- 删除原表数据
    DELETE FROM audit_logs WHERE created_at < cutoff_date;
    
    SELECT ROW_COUNT() AS archived_count;
END //

DELIMITER ;

-- 7. 读写分离健康检查视图
CREATE OR REPLACE VIEW v_replication_status AS
SELECT 
    @@hostname AS server,
    @@read_only AS is_readonly,
    @@server_id AS server_id,
    (SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE COMMAND != 'Sleep') AS active_connections,
    (SELECT SUM(VARIABLE_VALUE) FROM performance_schema.global_status 
     WHERE VARIABLE_NAME IN ('Com_select', 'Com_insert', 'Com_update', 'Com_delete')) AS total_queries;

-- 8. 连接池优化建议（ProxySQL 配置参考）
-- 注意：这是配置示例，需要在 ProxySQL 中执行
/*
-- 主库配置
INSERT INTO mysql_servers(hostgroup_id, hostname, port, weight, max_connections) 
VALUES (10, 'mysql-master', 3306, 1000, 100);

-- 从库配置
INSERT INTO mysql_servers(hostgroup_id, hostname, port, weight, max_connections) 
VALUES (20, 'mysql-slave-1', 3306, 500, 100);
INSERT INTO mysql_servers(hostgroup_id, hostname, port, weight, max_connections) 
VALUES (20, 'mysql-slave-2', 3306, 500, 100);

-- 读写分离规则
INSERT INTO mysql_query_rules(rule_id, active, match_pattern, destination_hostgroup, apply)
VALUES (1, 1, '^SELECT.*FOR UPDATE', 10, 1);
INSERT INTO mysql_query_rules(rule_id, active, match_pattern, destination_hostgroup, apply)
VALUES (2, 1, '^SELECT', 20, 1);
INSERT INTO mysql_query_rules(rule_id, active, match_pattern, destination_hostgroup, apply)
VALUES (3, 1, '.*', 10, 1);

LOAD MYSQL SERVERS TO RUNTIME;
LOAD MYSQL QUERY RULES TO RUNTIME;
SAVE MYSQL SERVERS TO DISK;
SAVE MYSQL QUERY RULES TO DISK;
*/

-- 9. 新增业务监控指标表
CREATE TABLE IF NOT EXISTS metrics_snapshot (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    metric_name VARCHAR(100) NOT NULL,
    metric_value DECIMAL(20, 6) NOT NULL,
    labels JSON,
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_metric_time (metric_name, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. 指标采集存储过程
DELIMITER //

CREATE PROCEDURE IF NOT EXISTS collect_metrics()
BEGIN
    -- 总请求数
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
    FROM access_logs WHERE DATE(created_at) = CURDATE();
    
    -- 缓存命中率（需要应用层写入）
    -- INSERT INTO metrics_snapshot (metric_name, metric_value, labels)
    -- VALUES ('cache_hit_rate', ?, '{"cache": "lru"}');
END //

DELIMITER ;

-- 11. 定时采集指标
CREATE EVENT IF NOT EXISTS evt_collect_metrics
ON SCHEDULE EVERY 1 MINUTE
DO CALL collect_metrics();

-- 启用事件调度器
SET GLOBAL event_scheduler = ON;

-- 12. 清理旧指标数据（保留 7 天）
CREATE EVENT IF NOT EXISTS evt_cleanup_metrics
ON SCHEDULE EVERY 1 DAY
STARTS CONCAT(CURDATE() + INTERVAL 1 DAY, ' 04:00:00')
DO DELETE FROM metrics_snapshot WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
