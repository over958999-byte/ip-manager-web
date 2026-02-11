-- 高并发优化索引迁移脚本
-- 为关键表补充复合索引

-- ===== jump_rules 表索引优化 =====

-- 复合索引：启用状态 + 短码 (短链查询最常用)
CREATE INDEX IF NOT EXISTS idx_jump_rules_enabled_code ON jump_rules(enabled, code);

-- 复合索引：域名 + 启用状态 (按域名过滤)
CREATE INDEX IF NOT EXISTS idx_jump_rules_domain_enabled ON jump_rules(domain, enabled);

-- 复合索引：创建时间 + 启用状态 (时间范围查询)
CREATE INDEX IF NOT EXISTS idx_jump_rules_created_enabled ON jump_rules(created_at, enabled);

-- 访问量统计索引
CREATE INDEX IF NOT EXISTS idx_jump_rules_clicks ON jump_rules(total_clicks DESC);


-- ===== access_logs 表索引优化 =====

-- 复合索引：规则ID + 访问时间 (统计分析)
CREATE INDEX IF NOT EXISTS idx_access_logs_rule_time ON access_logs(rule_id, accessed_at);

-- 复合索引：IP + 访问时间 (IP分析)
CREATE INDEX IF NOT EXISTS idx_access_logs_ip_time ON access_logs(ip, accessed_at);

-- 复合索引：访问时间 + 状态 (时间范围查询)
CREATE INDEX IF NOT EXISTS idx_access_logs_time_status ON access_logs(accessed_at, status);


-- ===== audit_logs 表索引优化 =====

-- 复合索引：用户ID + 创建时间 (用户操作历史)
CREATE INDEX IF NOT EXISTS idx_audit_logs_user_time ON audit_logs(user_id, created_at);

-- 复合索引：动作 + 资源类型 + 创建时间 (操作类型过滤)
CREATE INDEX IF NOT EXISTS idx_audit_logs_action_resource_time ON audit_logs(action, resource, created_at);

-- IP索引 (安全审计)
CREATE INDEX IF NOT EXISTS idx_audit_logs_ip ON audit_logs(ip);


-- ===== users 表索引优化 =====

-- 唯一索引：用户名 (登录查询)
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_username ON users(username);

-- 复合索引：角色 + 状态 (角色过滤)
CREATE INDEX IF NOT EXISTS idx_users_role_status ON users(role, status);


-- ===== api_tokens 表索引优化 =====

-- 唯一索引：token (API鉴权)
CREATE UNIQUE INDEX IF NOT EXISTS idx_api_tokens_token ON api_tokens(token);

-- 复合索引：用户ID + 状态 + 过期时间 (用户token列表)
CREATE INDEX IF NOT EXISTS idx_api_tokens_user_status_expires ON api_tokens(user_id, status, expires_at);


-- ===== login_attempts 表索引优化 =====

-- 复合索引：IP + 尝试时间 (登录锁定检查)
CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_time ON login_attempts(ip, attempted_at);

-- 复合索引：用户名 + 尝试时间 (用户锁定检查)
CREATE INDEX IF NOT EXISTS idx_login_attempts_username_time ON login_attempts(username, attempted_at);


-- ===== ip_pool 表索引优化 =====

-- 复合索引：状态 + 地区 (IP池分配)
CREATE INDEX IF NOT EXISTS idx_ip_pool_status_region ON ip_pool(status, region);

-- 复合索引：类型 + 状态 (IP类型筛选)
CREATE INDEX IF NOT EXISTS idx_ip_pool_type_status ON ip_pool(type, status);


-- ===== domains 表索引优化 =====

-- 唯一索引：域名 (域名查询)
CREATE UNIQUE INDEX IF NOT EXISTS idx_domains_domain ON domains(domain);

-- 复合索引：状态 + 安全检查时间 (安全扫描)
CREATE INDEX IF NOT EXISTS idx_domains_status_check ON domains(status, last_check_at);


-- ===== shortlinks 表索引优化 =====

-- 唯一索引：短码 (短链查询，最重要)
CREATE UNIQUE INDEX IF NOT EXISTS idx_shortlinks_code ON shortlinks(code);

-- 复合索引：用户ID + 创建时间 (用户短链列表)
CREATE INDEX IF NOT EXISTS idx_shortlinks_user_created ON shortlinks(user_id, created_at);

-- 复合索引：状态 + 过期时间 (清理任务)
CREATE INDEX IF NOT EXISTS idx_shortlinks_status_expires ON shortlinks(status, expires_at);


-- ===== 分析查询优化提示 =====
-- 以下索引根据实际查询模式添加

-- 如果经常按日期统计访问量:
-- CREATE INDEX idx_access_logs_date ON access_logs(DATE(accessed_at));

-- 如果经常按小时统计:
-- CREATE INDEX idx_access_logs_hour ON access_logs(DATE_FORMAT(accessed_at, '%Y-%m-%d %H:00:00'));


-- ===== 索引维护建议 =====
-- 定期运行:
-- ANALYZE TABLE jump_rules, access_logs, audit_logs, users, api_tokens;
-- 
-- 查看索引使用情况:
-- SHOW INDEX FROM jump_rules;
-- EXPLAIN SELECT * FROM jump_rules WHERE enabled = 1 AND code = 'abc123';
