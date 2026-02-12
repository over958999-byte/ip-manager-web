<?php
/**
 * 数据库连接和操作类
 */

// 加载数据库配置
$configFile = __DIR__ . '/db_config.php';
if (file_exists($configFile)) {
    require_once $configFile;
}

class Database {
    private static $instance = null;
    private $pdo;
    
    // 数据库配置 - 优先使用 db_config.php 中的配置
    private $host;
    private $dbname;
    private $username;
    private $password;
    private $charset;
    
    private function __construct() {
        // 使用配置文件的值，如果没有则使用默认值
        $this->host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
        $this->dbname = defined('DB_NAME') ? DB_NAME : 'ip_manager';
        $this->username = defined('DB_USER') ? DB_USER : 'root';
        $this->password = defined('DB_PASS') ? DB_PASS : '';
        $this->charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
        
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset={$this->charset}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            throw new Exception("数据库连接失败: " . $e->getMessage());
        }
        
        // 自动初始化核心表
        $this->initCoreTables();
    }
    
    /**
     * 初始化核心表（如果不存在则创建）
     */
    private function initCoreTables(): void
    {
        static $initialized = false;
        if ($initialized) return;
        
        try {
            // config 表
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS config (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    `key` VARCHAR(100) NOT NULL,
                    `value` TEXT,
                    description VARCHAR(255),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_key (`key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // users 表
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(50) NOT NULL,
                    password_hash VARCHAR(255) NOT NULL,
                    email VARCHAR(255),
                    role ENUM('admin', 'operator', 'viewer') DEFAULT 'viewer',
                    status ENUM('active', 'inactive', 'locked') DEFAULT 'active',
                    last_login_at TIMESTAMP NULL,
                    login_count INT DEFAULT 0,
                    totp_enabled TINYINT(1) DEFAULT 0,
                    totp_secret VARCHAR(100),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_username (username)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // audit_logs 表
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS audit_logs (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT,
                    username VARCHAR(50),
                    action VARCHAR(100) NOT NULL,
                    resource_type VARCHAR(50),
                    resource_id VARCHAR(100),
                    old_value JSON,
                    new_value JSON,
                    ip VARCHAR(45),
                    user_agent TEXT,
                    status VARCHAR(20) DEFAULT 'success',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user (user_id),
                    INDEX idx_action (action),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // 检查是否有默认管理员
            $count = $this->pdo->query("SELECT COUNT(*) FROM users WHERE username = 'admin'")->fetchColumn();
            if ($count == 0) {
                $this->pdo->exec("
                    INSERT INTO users (username, password_hash, email, role, status) 
                    VALUES ('admin', '\$2y\$10\$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@localhost', 'admin', 'active')
                ");
            }
            
            $initialized = true;
        } catch (Exception $e) {
            // 忽略初始化错误，可能表已存在
            error_log('Database init: ' . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getPdo() {
        return $this->pdo;
    }
    
    /**
     * 获取数据库连接（getConnection 别名）
     */
    public function getConnection() {
        return $this->pdo;
    }
    
    /**
     * 执行查询并获取单行结果
     */
    public function fetch(string $sql, array $params = []): ?array {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }
    
    /**
     * 执行查询并获取所有结果
     */
    public function fetchAll(string $sql, array $params = []): array {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * 执行查询并获取单个值
     */
    public function fetchColumn(string $sql, array $params = [], int $column = 0): mixed {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn($column);
    }
    
    /**
     * 执行 INSERT/UPDATE/DELETE 语句
     */
    public function execute(string $sql, array $params = []): int {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
    
    /**
     * 通用删除方法
     * @param string $table 表名
     * @param array $where 条件数组，如 ['id' => 1] 或 ['id' => 1, 'status' => 'active']
     * @return int 影响的行数
     */
    public function delete(string $table, array $where): int {
        if (empty($where)) {
            throw new Exception("Delete without conditions is not allowed");
        }
        
        $conditions = [];
        $params = [];
        foreach ($where as $column => $value) {
            $conditions[] = "`{$column}` = ?";
            $params[] = $value;
        }
        
        $sql = "DELETE FROM `{$table}` WHERE " . implode(' AND ', $conditions);
        return $this->execute($sql, $params);
    }

    // ==================== 配置相关 ====================
    
    public function getConfig($key, $default = null) {
        $stmt = $this->pdo->prepare("SELECT `value` FROM config WHERE `key` = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        if ($row) {
            $value = json_decode($row['value'], true);
            return $value !== null ? $value : $row['value'];
        }
        return $default;
    }
    
    public function setConfig($key, $value) {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        $stmt = $this->pdo->prepare("INSERT INTO config (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?");
        return $stmt->execute([$key, $value, $value]);
    }
    
    public function getAllConfig() {
        $stmt = $this->pdo->query("SELECT * FROM config");
        $config = [];
        while ($row = $stmt->fetch()) {
            $value = json_decode($row['value'], true);
            $config[$row['key']] = $value !== null ? $value : $row['value'];
        }
        return $config;
    }
    
    // ==================== IP跳转相关 ====================
    
    /**
     * 获取跳转规则（支持分页和搜索）
     * @param int $page 页码（从1开始）
     * @param int $perPage 每页数量
     * @param string $search 搜索关键词
     * @return array ['data' => [...], 'total' => int, 'page' => int, 'per_page' => int]
     */
    public function getRedirectsPaginated(int $page = 1, int $perPage = 50, string $search = ''): array {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage)); // 限制最大200条
        $offset = ($page - 1) * $perPage;
        
        // 构建查询条件
        $where = '';
        $params = [];
        if (!empty($search)) {
            $where = "WHERE ip LIKE ? OR url LIKE ? OR note LIKE ?";
            $searchParam = "%{$search}%";
            $params = [$searchParam, $searchParam, $searchParam];
        }
        
        // 获取总数
        $countSql = "SELECT COUNT(*) FROM redirects {$where}";
        $stmt = $this->pdo->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();
        
        // 获取数据
        $dataSql = "SELECT * FROM redirects {$where} ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $stmt = $this->pdo->prepare($dataSql);
        $stmt->execute(array_merge($params, [$perPage, $offset]));
        
        $redirects = [];
        while ($row = $stmt->fetch()) {
            $countryWhitelist = $row['country_whitelist'] ? json_decode($row['country_whitelist'], true) : [];
            $redirects[$row['ip']] = [
                'url' => $row['url'],
                'enabled' => (bool)$row['enabled'],
                'note' => $row['note'] ?? '',
                'block_desktop' => (bool)$row['block_desktop'],
                'block_ios' => (bool)$row['block_ios'],
                'block_android' => (bool)$row['block_android'],
                'country_whitelist_enabled' => (bool)$row['country_whitelist_enabled'],
                'country_whitelist' => $countryWhitelist ?: [],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
        }
        
        return [
            'data' => $redirects,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage),
        ];
    }
    
    /**
     * 获取所有跳转规则（兼容旧版）
     */
    public function getRedirects() {
        $stmt = $this->pdo->query("SELECT * FROM redirects ORDER BY created_at DESC");
        $redirects = [];
        while ($row = $stmt->fetch()) {
            $countryWhitelist = $row['country_whitelist'] ? json_decode($row['country_whitelist'], true) : [];
            $redirects[$row['ip']] = [
                'url' => $row['url'],
                'enabled' => (bool)$row['enabled'],
                'note' => $row['note'] ?? '',
                'block_desktop' => (bool)$row['block_desktop'],
                'block_ios' => (bool)$row['block_ios'],
                'block_android' => (bool)$row['block_android'],
                'country_whitelist_enabled' => (bool)$row['country_whitelist_enabled'],
                'country_whitelist' => $countryWhitelist ?: [],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
        }
        return $redirects;
    }
    
    public function getRedirect($ip) {
        $stmt = $this->pdo->prepare("SELECT * FROM redirects WHERE ip = ?");
        $stmt->execute([$ip]);
        $row = $stmt->fetch();
        if ($row) {
            $countryWhitelist = $row['country_whitelist'] ? json_decode($row['country_whitelist'], true) : [];
            return [
                'url' => $row['url'],
                'enabled' => (bool)$row['enabled'],
                'note' => $row['note'] ?? '',
                'block_desktop' => (bool)$row['block_desktop'],
                'block_ios' => (bool)$row['block_ios'],
                'block_android' => (bool)$row['block_android'],
                'country_whitelist_enabled' => (bool)$row['country_whitelist_enabled'],
                'country_whitelist' => $countryWhitelist ?: [],
                'created_at' => $row['created_at']
            ];
        }
        return null;
    }
    
    public function addRedirect($ip, $url, $note = '', $enabled = true) {
        $stmt = $this->pdo->prepare("INSERT INTO redirects (ip, url, note, enabled) VALUES (?, ?, ?, ?)");
        return $stmt->execute([$ip, $url, $note, $enabled ? 1 : 0]);
    }
    
    public function updateRedirect($ip, $data) {
        $fields = [];
        $values = [];
        
        $allowedFields = ['url', 'note', 'enabled', 'block_desktop', 'block_ios', 'block_android', 
                         'country_whitelist_enabled', 'country_whitelist'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                if ($field === 'country_whitelist') {
                    $values[] = json_encode($data[$field]);
                } elseif (is_bool($data[$field])) {
                    $values[] = $data[$field] ? 1 : 0;
                } else {
                    $values[] = $data[$field];
                }
            }
        }
        
        if (empty($fields)) return false;
        
        $values[] = $ip;
        $sql = "UPDATE redirects SET " . implode(', ', $fields) . " WHERE ip = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($values);
    }
    
    public function deleteRedirect($ip) {
        $stmt = $this->pdo->prepare("DELETE FROM redirects WHERE ip = ?");
        return $stmt->execute([$ip]);
    }
    
    public function toggleRedirect($ip) {
        $stmt = $this->pdo->prepare("UPDATE redirects SET enabled = NOT enabled WHERE ip = ?");
        $stmt->execute([$ip]);
        
        $stmt = $this->pdo->prepare("SELECT enabled FROM redirects WHERE ip = ?");
        $stmt->execute([$ip]);
        $row = $stmt->fetch();
        return $row ? (bool)$row['enabled'] : null;
    }
    
    // ==================== IP池相关 ====================
    
    public function getIpPool() {
        $stmt = $this->pdo->query("SELECT ip FROM ip_pool ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    public function addToPool($ip) {
        try {
            $stmt = $this->pdo->prepare("INSERT IGNORE INTO ip_pool (ip) VALUES (?)");
            $stmt->execute([$ip]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function removeFromPool($ip) {
        $stmt = $this->pdo->prepare("DELETE FROM ip_pool WHERE ip = ?");
        return $stmt->execute([$ip]);
    }
    
    public function clearPool() {
        return $this->pdo->exec("TRUNCATE TABLE ip_pool");
    }
    
    public function isInPool($ip) {
        $stmt = $this->pdo->prepare("SELECT 1 FROM ip_pool WHERE ip = ?");
        $stmt->execute([$ip]);
        return $stmt->fetch() !== false;
    }
    
    // ==================== 访问统计相关 ====================
    
    public function recordVisit($targetIp, $visitorIp, $country = '', $userAgent = '') {
        // 更新统计
        $stmt = $this->pdo->prepare("INSERT INTO visit_stats (target_ip, total_clicks) VALUES (?, 1) 
                                     ON DUPLICATE KEY UPDATE total_clicks = total_clicks + 1");
        $stmt->execute([$targetIp]);
        
        // 记录独立访客
        $stmt = $this->pdo->prepare("INSERT IGNORE INTO unique_visitors (target_ip, visitor_ip) VALUES (?, ?)");
        $stmt->execute([$targetIp, $visitorIp]);
        
        // 记录访问日志（限制数量）
        $stmt = $this->pdo->prepare("INSERT INTO visit_logs (target_ip, visitor_ip, country, user_agent) VALUES (?, ?, ?, ?)");
        $stmt->execute([$targetIp, $visitorIp, $country, substr($userAgent, 0, 500)]);
        
        // 清理旧日志（保留最近1000条）
        $this->pdo->exec("DELETE FROM visit_logs WHERE target_ip = '$targetIp' AND id NOT IN 
                         (SELECT id FROM (SELECT id FROM visit_logs WHERE target_ip = '$targetIp' ORDER BY id DESC LIMIT 1000) tmp)");
    }
    
    public function getVisitStats() {
        $stats = [];
        
        // 获取统计
        $stmt = $this->pdo->query("SELECT * FROM visit_stats");
        while ($row = $stmt->fetch()) {
            $ip = $row['target_ip'];
            
            // 获取独立访客数
            $uvStmt = $this->pdo->prepare("SELECT COUNT(*) as cnt FROM unique_visitors WHERE target_ip = ?");
            $uvStmt->execute([$ip]);
            $uvCount = $uvStmt->fetch()['cnt'];
            
            $stats[$ip] = [
                'total_clicks' => (int)$row['total_clicks'],
                'unique_ips' => $this->getUniqueVisitors($ip),
                'visitors' => $this->getVisitLogs($ip, 100)
            ];
        }
        
        return $stats;
    }
    
    public function getIpStats($targetIp) {
        $stmt = $this->pdo->prepare("SELECT * FROM visit_stats WHERE target_ip = ?");
        $stmt->execute([$targetIp]);
        $row = $stmt->fetch();
        
        return [
            'total_clicks' => $row ? (int)$row['total_clicks'] : 0,
            'unique_ips' => $this->getUniqueVisitors($targetIp),
            'visitors' => $this->getVisitLogs($targetIp, 100)
        ];
    }
    
    private function getUniqueVisitors($targetIp) {
        $stmt = $this->pdo->prepare("SELECT visitor_ip FROM unique_visitors WHERE target_ip = ?");
        $stmt->execute([$targetIp]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    private function getVisitLogs($targetIp, $limit = 100) {
        $stmt = $this->pdo->prepare("SELECT visitor_ip as ip, country, user_agent as ua, visited_at as time 
                                     FROM visit_logs WHERE target_ip = ? ORDER BY id DESC LIMIT ?");
        $stmt->execute([$targetIp, $limit]);
        return $stmt->fetchAll();
    }
    
    public function clearStats($targetIp = null) {
        if ($targetIp) {
            $this->pdo->prepare("DELETE FROM visit_stats WHERE target_ip = ?")->execute([$targetIp]);
            $this->pdo->prepare("DELETE FROM visit_logs WHERE target_ip = ?")->execute([$targetIp]);
            $this->pdo->prepare("DELETE FROM unique_visitors WHERE target_ip = ?")->execute([$targetIp]);
        } else {
            $this->pdo->exec("TRUNCATE TABLE visit_stats");
            $this->pdo->exec("TRUNCATE TABLE visit_logs");
            $this->pdo->exec("TRUNCATE TABLE unique_visitors");
        }
        return true;
    }
    
    // ==================== 反爬虫相关 ====================
    
    public function getAntibotConfig($key = null) {
        if ($key) {
            $stmt = $this->pdo->prepare("SELECT `value` FROM antibot_config WHERE `key` = ?");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            if ($row) {
                $value = json_decode($row['value'], true);
                return $value !== null ? $value : $row['value'];
            }
            return null;
        }
        
        $stmt = $this->pdo->query("SELECT * FROM antibot_config");
        $config = [];
        while ($row = $stmt->fetch()) {
            $value = json_decode($row['value'], true);
            $config[$row['key']] = $value !== null ? $value : ($row['value'] === 'true' ? true : ($row['value'] === 'false' ? false : $row['value']));
        }
        return $config;
    }
    
    public function setAntibotConfig($key, $value) {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }
        $stmt = $this->pdo->prepare("INSERT INTO antibot_config (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = ?");
        return $stmt->execute([$key, $value, $value]);
    }
    
    public function updateAntibotConfig($config) {
        foreach ($config as $key => $value) {
            $this->setAntibotConfig($key, $value);
        }
        return true;
    }
    
    // 反爬虫黑名单
    public function getAntibotBlacklist() {
        $stmt = $this->pdo->query("SELECT ip FROM antibot_blacklist");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    public function addToBlacklist($ip, $reason = '') {
        $stmt = $this->pdo->prepare("INSERT IGNORE INTO antibot_blacklist (ip, reason) VALUES (?, ?)");
        return $stmt->execute([$ip, $reason]);
    }
    
    public function removeFromBlacklist($ip) {
        $stmt = $this->pdo->prepare("DELETE FROM antibot_blacklist WHERE ip = ?");
        return $stmt->execute([$ip]);
    }
    
    public function isBlacklisted($ip) {
        $stmt = $this->pdo->prepare("SELECT 1 FROM antibot_blacklist WHERE ip = ?");
        $stmt->execute([$ip]);
        return $stmt->fetch() !== false;
    }
    
    // 反爬虫白名单
    public function getAntibotWhitelist() {
        $stmt = $this->pdo->query("SELECT ip FROM antibot_whitelist");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    public function addToWhitelist($ip) {
        $stmt = $this->pdo->prepare("INSERT IGNORE INTO antibot_whitelist (ip) VALUES (?)");
        return $stmt->execute([$ip]);
    }
    
    public function removeFromWhitelist($ip) {
        $stmt = $this->pdo->prepare("DELETE FROM antibot_whitelist WHERE ip = ?");
        return $stmt->execute([$ip]);
    }
    
    public function isWhitelisted($ip) {
        $stmt = $this->pdo->prepare("SELECT 1 FROM antibot_whitelist WHERE ip = ?");
        $stmt->execute([$ip]);
        return $stmt->fetch() !== false;
    }
    
    // 别名方法
    public function isInAntibotWhitelist($ip) {
        return $this->isWhitelisted($ip);
    }
    
    public function isInAntibotBlacklist($ip) {
        return $this->isBlacklisted($ip);
    }

    // 临时封禁
    public function blockIp($ip, $duration, $reason = '') {
        $until = date('Y-m-d H:i:s', time() + $duration);
        $stmt = $this->pdo->prepare("INSERT INTO antibot_blocks (ip, reason, until_at) VALUES (?, ?, ?) 
                                     ON DUPLICATE KEY UPDATE reason = ?, until_at = ?, blocked_at = NOW()");
        return $stmt->execute([$ip, $reason, $until, $reason, $until]);
    }
    
    public function unblockIp($ip) {
        $stmt = $this->pdo->prepare("DELETE FROM antibot_blocks WHERE ip = ?");
        return $stmt->execute([$ip]);
    }
    
    public function isBlocked($ip) {
        $stmt = $this->pdo->prepare("SELECT 1 FROM antibot_blocks WHERE ip = ? AND until_at > NOW()");
        $stmt->execute([$ip]);
        return $stmt->fetch() !== false;
    }
    
    public function getBlockedList() {
        $this->cleanExpiredBlocks();
        $stmt = $this->pdo->query("SELECT ip, reason, blocked_at, until_at, 
                                   TIMESTAMPDIFF(SECOND, NOW(), until_at) as remaining 
                                   FROM antibot_blocks WHERE until_at > NOW() ORDER BY blocked_at DESC");
        $list = [];
        while ($row = $stmt->fetch()) {
            $list[] = [
                'ip' => $row['ip'],
                'reason' => $row['reason'],
                'since' => $row['blocked_at'],
                'until' => $row['until_at'],
                'remaining' => max(0, (int)$row['remaining'])
            ];
        }
        return $list;
    }
    
    public function clearAllBlocks() {
        return $this->pdo->exec("TRUNCATE TABLE antibot_blocks");
    }
    
    private function cleanExpiredBlocks() {
        $this->pdo->exec("DELETE FROM antibot_blocks WHERE until_at <= NOW()");
    }
    
    // 请求记录
    public function recordRequest($ip) {
        $stmt = $this->pdo->prepare("INSERT INTO antibot_requests (visitor_ip, request_time) VALUES (?, ?)");
        $stmt->execute([$ip, time()]);
        
        // 定期清理（随机1%的概率）
        if (rand(1, 100) === 1) {
            $this->pdo->exec("DELETE FROM antibot_requests WHERE request_time < " . (time() - 86400));
        }
    }
    
    public function recordAntibotRequest($ip, $path, $suspicious = false) {
        // 记录请求
        $this->recordRequest($ip);
        // 记录行为
        $this->recordBehavior($ip, $path, $suspicious);
    }
    
    public function getRequestCount($ip, $timeWindow) {
        $since = time() - $timeWindow;
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as cnt FROM antibot_requests WHERE visitor_ip = ? AND request_time >= ?");
        $stmt->execute([$ip, $since]);
        return (int)$stmt->fetch()['cnt'];
    }
    
    public function getSuspiciousPathCount($ip, $timeWindow) {
        return $this->getSuspiciousBehaviorCount($ip, $timeWindow);
    }
    
    // 行为记录
    public function recordBehavior($ip, $path, $suspicious = false) {
        $stmt = $this->pdo->prepare("INSERT INTO antibot_behavior (visitor_ip, path, suspicious, recorded_at) VALUES (?, ?, ?, ?)");
        $stmt->execute([$ip, $path, $suspicious ? 1 : 0, time()]);
    }
    
    public function getSuspiciousBehaviorCount($ip, $timeWindow) {
        $since = time() - $timeWindow;
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as cnt FROM antibot_behavior WHERE visitor_ip = ? AND suspicious = 1 AND recorded_at >= ?");
        $stmt->execute([$ip, $since]);
        return (int)$stmt->fetch()['cnt'];
    }
    
    // 统计
    public function incrementAntibotStat($reason) {
        $stmt = $this->pdo->prepare("INSERT INTO antibot_stats (reason, count) VALUES (?, 1) 
                                     ON DUPLICATE KEY UPDATE count = count + 1");
        $stmt->execute([$reason]);
        
        // 更新总数
        $this->pdo->exec("INSERT INTO antibot_stats (reason, count) VALUES ('total_blocked', 1) 
                         ON DUPLICATE KEY UPDATE count = count + 1");
    }
    
    public function incrementAntibotStats($reason) {
        return $this->incrementAntibotStat($reason);
    }
    
    public function getRecentBlockCount($ip, $timeWindow, $excludeReasons = []) {
        $since = date('Y-m-d H:i:s', time() - $timeWindow);
        $sql = "SELECT COUNT(*) as cnt FROM antibot_logs WHERE visitor_ip = ? AND logged_at >= ?";
        $params = [$ip, $since];
        
        if (!empty($excludeReasons)) {
            $placeholders = implode(',', array_fill(0, count($excludeReasons), '?'));
            $sql .= " AND reason NOT IN ($placeholders)";
            $params = array_merge($params, $excludeReasons);
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetch()['cnt'];
    }
    
    public function getAntibotStats() {
        $stmt = $this->pdo->query("SELECT reason, count FROM antibot_stats");
        $stats = ['total_blocked' => 0, 'by_reason' => []];
        while ($row = $stmt->fetch()) {
            if ($row['reason'] === 'total_blocked') {
                $stats['total_blocked'] = (int)$row['count'];
            } else {
                $stats['by_reason'][$row['reason']] = (int)$row['count'];
            }
        }
        $stats['currently_blocked'] = count($this->getBlockedList());
        $stats['blacklist_count'] = (int)$this->pdo->query("SELECT COUNT(*) FROM antibot_blacklist")->fetchColumn();
        return $stats;
    }
    
    public function resetAntibotStats() {
        $this->pdo->exec("TRUNCATE TABLE antibot_stats");
        $this->pdo->exec("INSERT INTO antibot_stats (reason, count) VALUES ('total_blocked', 0)");
        $this->pdo->exec("TRUNCATE TABLE antibot_logs");
        return true;
    }
    
    // 拦截日志
    public function logBlock($visitorIp, $targetIp, $reason, $detail = '', $userAgent = '', $requestUri = '') {
        $stmt = $this->pdo->prepare("INSERT INTO antibot_logs (visitor_ip, target_ip, user_agent, request_uri, reason, detail) 
                                     VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$visitorIp, $targetIp, substr($userAgent, 0, 500), substr($requestUri, 0, 500), $reason, $detail]);
        
        // 保留最近1000条
        $this->pdo->exec("DELETE FROM antibot_logs WHERE id NOT IN (SELECT id FROM (SELECT id FROM antibot_logs ORDER BY id DESC LIMIT 1000) tmp)");
    }
    
    public function getAntibotLogs($limit = 100) {
        $stmt = $this->pdo->prepare("SELECT visitor_ip as ip, target_ip, user_agent as ua, request_uri as uri, 
                                     reason, detail, logged_at as time FROM antibot_logs ORDER BY id DESC LIMIT ?");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
    
    // IP国家缓存
    public function getIpCountryCache($ip) {
        $stmt = $this->pdo->prepare("SELECT country_code FROM ip_country_cache WHERE ip = ? AND cached_at > ?");
        $stmt->execute([$ip, time() - 86400]); // 24小时缓存
        $row = $stmt->fetch();
        return $row ? $row['country_code'] : null;
    }
    
    public function getIpCountryNameCache($ip) {
        $stmt = $this->pdo->prepare("SELECT country FROM ip_country_cache WHERE ip = ? AND cached_at > ?");
        $stmt->execute([$ip, time() - 86400]);
        $row = $stmt->fetch();
        return $row ? $row['country'] : null;
    }
    
    public function setIpCountryCache($ip, $countryCode, $countryName = '') {
        $stmt = $this->pdo->prepare("INSERT INTO ip_country_cache (ip, country, country_code, cached_at) VALUES (?, ?, ?, ?) 
                                     ON DUPLICATE KEY UPDATE country = ?, country_code = ?, cached_at = ?");
        $time = time();
        return $stmt->execute([$ip, $countryName, $countryCode, $time, $countryName, $countryCode, $time]);
    }
}
