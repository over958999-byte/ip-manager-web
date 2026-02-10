-- 初始化配置数据
INSERT IGNORE INTO config (`key`, `value`) VALUES 
('admin_password', '"admin123"'),
('admin_allowed_ips', '["127.0.0.1", "::1"]'),
('admin_secret_key', '""');

-- 初始化反爬虫配置
INSERT IGNORE INTO antibot_config (`key`, `value`) VALUES 
('enabled', 'true'),
('log_blocked', 'true'),
('rate_limit', '{"enabled":true,"max_requests":60,"time_window":60,"block_duration":3600}'),
('ua_check', '{"enabled":true,"block_empty_ua":true,"block_known_bots":true,"whitelist":[]}'),
('header_check', '{"enabled":true,"check_required_headers":true}'),
('behavior_check', '{"enabled":true,"max_404_count":10,"suspicious_paths":5,"time_window":300}'),
('honeypot', '{"enabled":true,"auto_block":true}'),
('auto_blacklist', '{"enabled":true,"max_blocks":5,"time_window":300,"exclude_reasons":[]}'),
('bad_ip_database', '{"enabled":true,"block_malicious":true,"block_datacenter":false,"block_known_bots":false}'),
('block_action', '{"type":"error_page","delay_min":100,"delay_max":500}');

-- 初始化统计
INSERT IGNORE INTO antibot_stats (reason, count) VALUES ('total_blocked', 0);
