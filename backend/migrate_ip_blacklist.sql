-- =====================================================
-- IP黑名单表增强 - 添加威胁情报来源字段
-- 执行: mysql -u root -p ip_manager < migrate_ip_blacklist.sql
-- =====================================================

USE ip_manager;

-- 添加source字段（威胁情报来源URL）
ALTER TABLE ip_blacklist 
ADD COLUMN IF NOT EXISTS source VARCHAR(500) DEFAULT NULL COMMENT '数据来源URL' AFTER name;

-- 添加updated_at字段
ALTER TABLE ip_blacklist 
ADD COLUMN IF NOT EXISTS updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间' AFTER created_at;

-- 添加索引优化查询
ALTER TABLE ip_blacklist 
ADD INDEX IF NOT EXISTS idx_category (category),
ADD INDEX IF NOT EXISTS idx_source (source(100));

-- 创建系统日志表（用于记录定时任务等操作）
CREATE TABLE IF NOT EXISTS system_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL COMMENT '日志类型: cron, system, error',
    action VARCHAR(100) NOT NULL COMMENT '操作',
    details JSON COMMENT '详细信息',
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type (type),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统日志';

-- 创建威胁情报同步配置表
CREATE TABLE IF NOT EXISTS threat_intel_sources (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL COMMENT '源名称',
    url VARCHAR(500) NOT NULL COMMENT 'URL',
    type ENUM('malicious', 'bot', 'datacenter', 'proxy', 'custom') DEFAULT 'malicious' COMMENT 'IP类型',
    category VARCHAR(100) DEFAULT NULL COMMENT '分类',
    parser VARCHAR(50) DEFAULT 'line_ips' COMMENT '解析器类型',
    enabled TINYINT(1) DEFAULT 1 COMMENT '是否启用',
    last_sync_at DATETIME DEFAULT NULL COMMENT '最后同步时间',
    last_sync_count INT DEFAULT 0 COMMENT '最后同步数量',
    sync_interval INT DEFAULT 86400 COMMENT '同步间隔（秒）',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_url (url(255)),
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='威胁情报源配置';

-- 插入默认威胁情报源
INSERT IGNORE INTO threat_intel_sources (name, url, type, category, parser, enabled) VALUES
('Emerging Threats', 'https://rules.emergingthreats.net/blockrules/compromised-ips.txt', 'malicious', 'Emerging Threats', 'line_ips', 1),
('Spamhaus DROP', 'https://www.spamhaus.org/drop/drop.txt', 'malicious', 'Spamhaus DROP', 'spamhaus', 1),
('Spamhaus EDROP', 'https://www.spamhaus.org/drop/edrop.txt', 'malicious', 'Spamhaus EDROP', 'spamhaus', 1),
('Blocklist.de', 'https://lists.blocklist.de/lists/all.txt', 'malicious', 'Blocklist.de', 'line_ips', 1),
('AbuseIPDB', 'https://raw.githubusercontent.com/borestad/blocklist-abuseipdb/main/abuseipdb-s100-14d.ipv4', 'malicious', 'AbuseIPDB', 'line_ips', 1),
('CI Army', 'https://cinsscore.com/list/ci-badguys.txt', 'malicious', 'CI Army', 'line_ips', 1),
('FeodoTracker', 'https://feodotracker.abuse.ch/downloads/ipblocklist.txt', 'malicious', 'FeodoTracker', 'abuse_ch', 1),
('SSL Blacklist', 'https://sslbl.abuse.ch/blacklist/sslipblacklist.txt', 'malicious', 'SSL Blacklist', 'abuse_ch', 1),
('Firehol Level1', 'https://raw.githubusercontent.com/firehol/blocklist-ipsets/master/firehol_level1.netset', 'malicious', 'Firehol Level1', 'netset', 1),
('Firehol Level2', 'https://raw.githubusercontent.com/firehol/blocklist-ipsets/master/firehol_level2.netset', 'malicious', 'Firehol Level2', 'netset', 1),
('Stamparm ipsum', 'https://raw.githubusercontent.com/stamparm/ipsum/master/levels/3.txt', 'malicious', 'Stamparm ipsum', 'ipsum', 1),
('Bad Bot IPs', 'https://raw.githubusercontent.com/mitchellkrogza/nginx-ultimate-bad-bot-blocker/master/_generator_lists/bad-ip-addresses.list', 'bot', 'Bad Bot', 'line_ips', 1),
('Tor Exit Nodes', 'https://check.torproject.org/torbulkexitlist', 'proxy', 'Tor Exit', 'line_ips', 1),
('Public Proxies', 'https://raw.githubusercontent.com/clarketm/proxy-list/master/proxy-list-raw.txt', 'proxy', 'Public Proxy', 'proxy_list', 1);
