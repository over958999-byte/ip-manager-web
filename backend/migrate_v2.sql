-- =====================================================
-- 困King分发平台 - V2.0 数据库迁移脚本
-- 添加安全、审计、通知等新功能所需的表
-- 执行: mysql -u root -p ip_manager < migrate_v2.sql
-- =====================================================

USE ip_manager;

-- =====================================================
-- 登录失败记录表（用于登录锁定功能）
-- =====================================================
CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL COMMENT 'IP地址',
    username VARCHAR(100) DEFAULT NULL COMMENT '尝试的用户名',
    attempt_count INT UNSIGNED DEFAULT 1 COMMENT '尝试次数',
    locked_until DATETIME DEFAULT NULL COMMENT '锁定到期时间',
    first_attempt_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '首次尝试时间',
    last_attempt_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最后尝试时间',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_ip (ip_address),
    INDEX idx_locked_until (locked_until),
    INDEX idx_last_attempt (last_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='登录失败记录';

-- =====================================================
-- 用户表（多用户支持）
-- =====================================================
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL COMMENT '用户名',
    password_hash VARCHAR(255) NOT NULL COMMENT '密码哈希',
    email VARCHAR(100) DEFAULT NULL COMMENT '邮箱',
    role ENUM('admin', 'operator', 'viewer') DEFAULT 'viewer' COMMENT '角色',
    totp_secret VARCHAR(100) DEFAULT NULL COMMENT 'TOTP密钥（双因素认证）',
    totp_enabled TINYINT(1) DEFAULT 0 COMMENT '是否启用双因素认证',
    last_login_at DATETIME DEFAULT NULL COMMENT '最后登录时间',
    last_login_ip VARCHAR(45) DEFAULT NULL COMMENT '最后登录IP',
    login_count INT UNSIGNED DEFAULT 0 COMMENT '登录次数',
    enabled TINYINT(1) DEFAULT 1 COMMENT '是否启用',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_username (username),
    UNIQUE KEY uk_email (email),
    INDEX idx_role (role),
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表';

-- 插入默认管理员账户（密码: admin123，使用bcrypt哈希）
INSERT INTO users (username, password_hash, email, role, enabled) VALUES
('admin', '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/X4gNvAeENvPxlqoSy', 'admin@example.com', 'admin', 1)
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- =====================================================
-- 角色权限表
-- =====================================================
CREATE TABLE IF NOT EXISTS role_permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role ENUM('admin', 'operator', 'viewer') NOT NULL COMMENT '角色',
    resource VARCHAR(50) NOT NULL COMMENT '资源',
    action VARCHAR(20) NOT NULL COMMENT '操作: read, create, update, delete',
    allowed TINYINT(1) DEFAULT 1 COMMENT '是否允许',
    UNIQUE KEY uk_role_resource_action (role, resource, action),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色权限';

-- 初始化权限
INSERT INTO role_permissions (role, resource, action, allowed) VALUES
-- Admin 拥有所有权限
('admin', '*', '*', 1),
-- Operator 可以管理规则和域名
('operator', 'rules', 'read', 1),
('operator', 'rules', 'create', 1),
('operator', 'rules', 'update', 1),
('operator', 'rules', 'delete', 1),
('operator', 'domains', 'read', 1),
('operator', 'domains', 'create', 1),
('operator', 'domains', 'update', 1),
('operator', 'shortlinks', 'read', 1),
('operator', 'shortlinks', 'create', 1),
('operator', 'shortlinks', 'update', 1),
('operator', 'shortlinks', 'delete', 1),
('operator', 'stats', 'read', 1),
-- Viewer 只读
('viewer', 'rules', 'read', 1),
('viewer', 'domains', 'read', 1),
('viewer', 'shortlinks', 'read', 1),
('viewer', 'stats', 'read', 1)
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);

-- =====================================================
-- API Key 管理表
-- =====================================================
CREATE TABLE IF NOT EXISTS api_keys (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED DEFAULT NULL COMMENT '关联用户ID',
    name VARCHAR(100) NOT NULL COMMENT 'API Key名称',
    key_hash VARCHAR(64) NOT NULL COMMENT 'Key哈希（SHA256）',
    key_prefix VARCHAR(8) NOT NULL COMMENT 'Key前缀（用于识别）',
    permissions JSON DEFAULT NULL COMMENT '权限列表',
    rate_limit INT UNSIGNED DEFAULT 1000 COMMENT '每小时请求限制',
    last_used_at DATETIME DEFAULT NULL COMMENT '最后使用时间',
    last_used_ip VARCHAR(45) DEFAULT NULL COMMENT '最后使用IP',
    use_count BIGINT UNSIGNED DEFAULT 0 COMMENT '使用次数',
    enabled TINYINT(1) DEFAULT 1,
    expires_at DATETIME DEFAULT NULL COMMENT '过期时间',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_key_hash (key_hash),
    INDEX idx_prefix (key_prefix),
    INDEX idx_user (user_id),
    INDEX idx_enabled (enabled),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API Key管理';

-- =====================================================
-- 操作审计日志表
-- =====================================================
CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED DEFAULT NULL COMMENT '操作用户ID',
    username VARCHAR(50) DEFAULT NULL COMMENT '操作用户名',
    action VARCHAR(50) NOT NULL COMMENT '操作类型',
    resource_type VARCHAR(50) DEFAULT NULL COMMENT '资源类型',
    resource_id VARCHAR(100) DEFAULT NULL COMMENT '资源ID',
    old_value JSON DEFAULT NULL COMMENT '旧值',
    new_value JSON DEFAULT NULL COMMENT '新值',
    ip_address VARCHAR(45) DEFAULT NULL COMMENT 'IP地址',
    user_agent VARCHAR(500) DEFAULT NULL COMMENT 'User-Agent',
    result ENUM('success', 'failure') DEFAULT 'success' COMMENT '结果',
    error_message TEXT DEFAULT NULL COMMENT '错误信息',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_resource (resource_type, resource_id),
    INDEX idx_created (created_at),
    INDEX idx_result (result)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作审计日志';

-- =====================================================
-- Webhook 配置表
-- =====================================================
CREATE TABLE IF NOT EXISTS webhooks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL COMMENT 'Webhook名称',
    platform ENUM('wecom', 'dingtalk', 'feishu', 'slack', 'custom') NOT NULL COMMENT '平台类型',
    url VARCHAR(500) NOT NULL COMMENT 'Webhook URL',
    secret VARCHAR(100) DEFAULT NULL COMMENT '签名密钥',
    events JSON DEFAULT NULL COMMENT '订阅的事件类型',
    alert_levels JSON DEFAULT '["error", "critical"]' COMMENT '告警级别',
    headers JSON DEFAULT NULL COMMENT '自定义请求头',
    enabled TINYINT(1) DEFAULT 1,
    last_triggered_at DATETIME DEFAULT NULL COMMENT '最后触发时间',
    success_count INT UNSIGNED DEFAULT 0 COMMENT '成功次数',
    failure_count INT UNSIGNED DEFAULT 0 COMMENT '失败次数',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_platform (platform),
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Webhook配置';

-- =====================================================
-- Webhook 发送日志表
-- =====================================================
CREATE TABLE IF NOT EXISTS webhook_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    webhook_id INT UNSIGNED NOT NULL COMMENT 'Webhook ID',
    event_type VARCHAR(50) NOT NULL COMMENT '事件类型',
    payload JSON DEFAULT NULL COMMENT '发送内容',
    response_code INT DEFAULT NULL COMMENT 'HTTP响应码',
    response_body TEXT DEFAULT NULL COMMENT '响应内容',
    success TINYINT(1) DEFAULT 0,
    error_message TEXT DEFAULT NULL,
    duration_ms INT UNSIGNED DEFAULT NULL COMMENT '耗时(毫秒)',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_webhook (webhook_id),
    INDEX idx_event (event_type),
    INDEX idx_success (success),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Webhook发送日志';

-- =====================================================
-- 备份记录表
-- =====================================================
CREATE TABLE IF NOT EXISTS backup_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL COMMENT '文件名',
    filepath VARCHAR(500) DEFAULT NULL COMMENT '本地路径',
    size BIGINT UNSIGNED DEFAULT 0 COMMENT '文件大小(字节)',
    cloud_uploaded TINYINT(1) DEFAULT 0 COMMENT '是否上传云端',
    cloud_provider VARCHAR(20) DEFAULT NULL COMMENT '云存储提供商',
    cloud_url VARCHAR(500) DEFAULT NULL COMMENT '云端URL',
    success TINYINT(1) DEFAULT 1,
    error TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_success (success)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='备份记录';

-- =====================================================
-- 系统指标表（用于 Prometheus 指标持久化）
-- =====================================================
CREATE TABLE IF NOT EXISTS system_metrics (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    metric_name VARCHAR(100) NOT NULL COMMENT '指标名称',
    metric_value DOUBLE NOT NULL COMMENT '指标值',
    labels JSON DEFAULT NULL COMMENT '标签',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name (metric_name),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统指标';

-- =====================================================
-- 添加新字段到现有表
-- =====================================================

-- 给 config 表添加描述字段
ALTER TABLE config 
    ADD COLUMN IF NOT EXISTS `description` VARCHAR(255) DEFAULT NULL COMMENT '配置说明',
    ADD COLUMN IF NOT EXISTS `type` ENUM('string', 'int', 'bool', 'json') DEFAULT 'string' COMMENT '值类型';

-- =====================================================
-- 更新存储过程
-- =====================================================

DROP PROCEDURE IF EXISTS cleanup_old_data;

DELIMITER //
CREATE PROCEDURE cleanup_old_data()
BEGIN
    -- 清理30天前的日志
    DELETE FROM jump_logs WHERE visited_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    DELETE FROM antibot_logs WHERE logged_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    DELETE FROM domain_safety_logs WHERE checked_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY);
    DELETE FROM webhook_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    -- 清理7天前的IP缓存
    DELETE FROM ip_country_cache WHERE updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
    
    -- 清理30天前的监控指标
    DELETE FROM system_metrics WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    -- 清理过期的临时封禁
    DELETE FROM antibot_blocks WHERE until_at < NOW();
    
    -- 清理过期的登录尝试记录
    DELETE FROM login_attempts WHERE last_attempt_at < DATE_SUB(NOW(), INTERVAL 1 DAY);
END //
DELIMITER ;

-- =====================================================
-- 创建定时事件（每天凌晨2点执行清理）
-- =====================================================

SET GLOBAL event_scheduler = ON;

DROP EVENT IF EXISTS daily_cleanup;

CREATE EVENT IF NOT EXISTS daily_cleanup
ON SCHEDULE EVERY 1 DAY
STARTS CONCAT(CURDATE() + INTERVAL 1 DAY, ' 02:00:00')
DO CALL cleanup_old_data();

SELECT 'V2.0 Migration completed!' as status;
