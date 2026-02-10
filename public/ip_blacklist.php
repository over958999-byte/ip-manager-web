<?php
/**
 * IP黑名单库 - 数据库 + 缓存版本
 * 从数据库加载规则，缓存到内存，高效查询
 */

require_once __DIR__ . '/../backend/core/database.php';

class IpBlacklist {
    private static $instance = null;
    private static $cache = null;
    private static $cacheVersion = 0;
    private static $cacheExpireTime = 0;
    private static $cacheTTL = 300; // 缓存5分钟
    
    private $pdo;
    
    private function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 检查IP是否在黑名单中
     * 使用缓存，高效查询
     */
    public static function check(string $ip): array {
        $instance = self::getInstance();
        $cache = $instance->getCache();
        
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return ['blocked' => false, 'reason' => 'invalid_ip'];
        }
        
        // 二分查找匹配的IP范围
        foreach ($cache['ranges'] as $range) {
            if ($ipLong >= $range['ip_start'] && $ipLong <= $range['ip_end']) {
                // 更新命中计数（异步，不阻塞）
                $instance->updateHitCountAsync($range['id']);
                
                return [
                    'blocked' => true,
                    'type' => $range['type'],
                    'category' => $range['category'],
                    'name' => $range['name'],
                    'ip_cidr' => $range['ip_cidr']
                ];
            }
        }
        
        return ['blocked' => false];
    }
    
    /**
     * 检查是否为恶意IP
     */
    public static function isMalicious(string $ip): array {
        $result = self::check($ip);
        if ($result['blocked'] && $result['type'] === 'malicious') {
            return [
                'is_bad' => true,
                'type' => 'malicious',
                'reason' => $result['name'] ?? '恶意IP'
            ];
        }
        return ['is_bad' => false];
    }
    
    /**
     * 检查是否为已知爬虫
     */
    public static function isKnownBot(string $ip): array {
        $result = self::check($ip);
        if ($result['blocked'] && $result['type'] === 'bot') {
            return [
                'is_bot' => true,
                'type' => 'known_bot',
                'reason' => '已知爬虫: ' . ($result['name'] ?? $result['category'])
            ];
        }
        return ['is_bot' => false];
    }
    
    /**
     * 检查是否为数据中心IP
     */
    public static function isDatacenter(string $ip): array {
        $result = self::check($ip);
        if ($result['blocked'] && $result['type'] === 'datacenter') {
            return [
                'is_datacenter' => true,
                'range' => $result['ip_cidr']
            ];
        }
        return ['is_datacenter' => false];
    }
    
    /**
     * 获取缓存，自动刷新
     */
    private function getCache(): array {
        $now = time();
        
        // 检查缓存是否过期
        if (self::$cache !== null && $now < self::$cacheExpireTime) {
            // 检查版本是否变化
            $currentVersion = $this->getCacheVersion();
            if ($currentVersion === self::$cacheVersion) {
                return self::$cache;
            }
        }
        
        // 重新加载缓存
        return $this->loadCache();
    }
    
    /**
     * 从数据库加载缓存
     */
    private function loadCache(): array {
        try {
            $stmt = $this->pdo->query("
                SELECT id, ip_cidr, ip_start, ip_end, type, category, name 
                FROM ip_blacklist 
                WHERE enabled = 1 
                ORDER BY ip_start
            ");
            $ranges = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 转换为整数以便比较
            foreach ($ranges as &$range) {
                $range['ip_start'] = intval($range['ip_start']);
                $range['ip_end'] = intval($range['ip_end']);
            }
            
            self::$cache = ['ranges' => $ranges];
            self::$cacheVersion = $this->getCacheVersion();
            self::$cacheExpireTime = time() + self::$cacheTTL;
            
            return self::$cache;
        } catch (Exception $e) {
            // 数据库错误时返回空缓存
            return ['ranges' => []];
        }
    }
    
    /**
     * 获取缓存版本号
     */
    private function getCacheVersion(): int {
        try {
            $stmt = $this->pdo->query("SELECT version FROM ip_blacklist_version WHERE id = 1");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? intval($row['version']) : 0;
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * 更新版本号（修改数据后调用）
     */
    public function incrementVersion(): void {
        try {
            $this->pdo->exec("UPDATE ip_blacklist_version SET version = version + 1 WHERE id = 1");
        } catch (Exception $e) {
            // ignore
        }
    }
    
    /**
     * 异步更新命中计数
     */
    private function updateHitCountAsync(int $id): void {
        // 使用register_shutdown_function在请求结束后更新
        register_shutdown_function(function() use ($id) {
            try {
                $pdo = Database::getInstance()->getConnection();
                $stmt = $pdo->prepare("UPDATE ip_blacklist SET hit_count = hit_count + 1, last_hit_at = NOW() WHERE id = ?");
                $stmt->execute([$id]);
            } catch (Exception $e) {
                // ignore
            }
        });
    }
    
    /**
     * 强制刷新缓存
     */
    public static function refreshCache(): void {
        self::$cache = null;
        self::$cacheExpireTime = 0;
    }
    
    /**
     * 添加IP规则
     */
    public function addRule(string $ipCidr, string $type, string $category, string $name): bool {
        try {
            list($ipStart, $ipEnd) = $this->cidrToRange($ipCidr);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO ip_blacklist (ip_cidr, ip_start, ip_end, type, category, name)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE type = ?, category = ?, name = ?, enabled = 1
            ");
            $result = $stmt->execute([$ipCidr, $ipStart, $ipEnd, $type, $category, $name, $type, $category, $name]);
            
            if ($result) {
                $this->incrementVersion();
            }
            return $result;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * 删除IP规则
     */
    public function removeRule(int $id): bool {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM ip_blacklist WHERE id = ?");
            $result = $stmt->execute([$id]);
            if ($result) {
                $this->incrementVersion();
            }
            return $result;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * 启用/禁用规则
     */
    public function toggleRule(int $id, bool $enabled): bool {
        try {
            $stmt = $this->pdo->prepare("UPDATE ip_blacklist SET enabled = ? WHERE id = ?");
            $result = $stmt->execute([$enabled ? 1 : 0, $id]);
            if ($result) {
                $this->incrementVersion();
            }
            return $result;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * 获取所有规则
     */
    public function getRules(array $filters = []): array {
        try {
            $sql = "SELECT * FROM ip_blacklist WHERE 1=1";
            $params = [];
            
            if (!empty($filters['type'])) {
                $sql .= " AND type = ?";
                $params[] = $filters['type'];
            }
            if (!empty($filters['category'])) {
                $sql .= " AND category = ?";
                $params[] = $filters['category'];
            }
            if (isset($filters['enabled'])) {
                $sql .= " AND enabled = ?";
                $params[] = $filters['enabled'] ? 1 : 0;
            }
            if (!empty($filters['search'])) {
                $sql .= " AND (ip_cidr LIKE ? OR name LIKE ? OR category LIKE ?)";
                $search = '%' . $filters['search'] . '%';
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
            }
            
            $sql .= " ORDER BY type, category, ip_start";
            
            if (!empty($filters['limit'])) {
                $sql .= " LIMIT " . intval($filters['limit']);
                if (!empty($filters['offset'])) {
                    $sql .= " OFFSET " . intval($filters['offset']);
                }
            }
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * 获取统计信息
     */
    public function getStats(): array {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    type,
                    COUNT(*) as count,
                    SUM(hit_count) as total_hits,
                    SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) as enabled_count
                FROM ip_blacklist
                GROUP BY type
            ");
            $byType = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $this->pdo->query("
                SELECT 
                    category,
                    COUNT(*) as count,
                    SUM(hit_count) as total_hits
                FROM ip_blacklist
                WHERE enabled = 1
                GROUP BY category
                ORDER BY total_hits DESC
                LIMIT 20
            ");
            $byCategory = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $this->pdo->query("
                SELECT COUNT(*) as total, SUM(hit_count) as total_hits
                FROM ip_blacklist
            ");
            $total = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'total_rules' => intval($total['total']),
                'total_hits' => intval($total['total_hits']),
                'by_type' => $byType,
                'by_category' => $byCategory
            ];
        } catch (Exception $e) {
            return ['total_rules' => 0, 'total_hits' => 0, 'by_type' => [], 'by_category' => []];
        }
    }
    
    /**
     * 获取分类列表
     */
    public function getCategories(): array {
        try {
            $stmt = $this->pdo->query("SELECT DISTINCT category FROM ip_blacklist WHERE category IS NOT NULL ORDER BY category");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * CIDR转换为IP范围
     */
    private function cidrToRange(string $cidr): array {
        if (strpos($cidr, '/') === false) {
            $ipLong = ip2long($cidr);
            return [$ipLong, $ipLong];
        }
        
        list($ip, $bits) = explode('/', $cidr);
        $bits = intval($bits);
        $ipLong = ip2long($ip);
        
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
        $start = $ipLong & $mask;
        $end = $start | (~$mask & 0xFFFFFFFF);
        
        // 处理PHP的有符号整数问题
        if ($start < 0) $start = sprintf('%u', $start);
        if ($end < 0) $end = sprintf('%u', $end);
        
        return [$start, $end];
    }
    
    /**
     * 批量导入规则
     */
    public function importRules(array $rules): array {
        $success = 0;
        $failed = 0;
        
        foreach ($rules as $rule) {
            if ($this->addRule(
                $rule['ip_cidr'],
                $rule['type'] ?? 'custom',
                $rule['category'] ?? null,
                $rule['name'] ?? null
            )) {
                $success++;
            } else {
                $failed++;
            }
        }
        
        return ['success' => $success, 'failed' => $failed];
    }
}

/**
 * 兼容旧的 BadIpDatabase 类
 * 保持向后兼容
 */
class BadIpDatabase {
    public static function check($ip) {
        $result = IpBlacklist::check($ip);
        if ($result['blocked']) {
            return [
                'allowed' => false,
                'reason' => 'bad_ip_database',
                'detail' => $result['name'] ?? $result['category'] ?? '黑名单IP',
                'type' => $result['type']
            ];
        }
        return ['allowed' => true];
    }
    
    public static function isMalicious($ip) {
        return IpBlacklist::isMalicious($ip);
    }
    
    public static function isKnownBot($ip) {
        return IpBlacklist::isKnownBot($ip);
    }
    
    public static function isDatacenter($ip) {
        return IpBlacklist::isDatacenter($ip);
    }
    
    public static function getStats() {
        return IpBlacklist::getInstance()->getStats();
    }
}
