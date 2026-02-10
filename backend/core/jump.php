<?php
/**
 * 统一跳转服务核心类
 * 合并 IP跳转 和 短链服务
 * 
 * 高性能设计：
 * - 内存缓存热点规则
 * - Base62 短码生成
 * - 预编译 SQL 语句
 * - 异步日志写入
 */

class JumpService {
    private $pdo;
    private static $cache = [];
    private static $cacheTime = [];
    private const CACHE_TTL = 300; // 5分钟缓存
    private const BASE62_CHARS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    
    // 规则类型常量
    public const TYPE_IP = 'ip';
    public const TYPE_CODE = 'code';
    
    // 预编译语句缓存
    private $stmtGetByKey = null;
    private $stmtRecordClick = null;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }
    
    // ==================== 短码生成 ====================
    
    /**
     * 生成短码 (Base62 + 随机)
     */
    public function generateCode(int $length = 6): string {
        $code = '';
        $max = strlen(self::BASE62_CHARS) - 1;
        for ($i = 0; $i < $length; $i++) {
            $code .= self::BASE62_CHARS[random_int(0, $max)];
        }
        return $code;
    }
    
    /**
     * 生成唯一短码
     */
    public function generateUniqueCode(int $length = 6, int $maxAttempts = 10): ?string {
        $stmt = $this->pdo->prepare("SELECT 1 FROM jump_rules WHERE rule_type = 'code' AND match_key = ? LIMIT 1");
        
        for ($i = 0; $i < $maxAttempts; $i++) {
            $code = $this->generateCode($length);
            $stmt->execute([$code]);
            if (!$stmt->fetch()) {
                return $code;
            }
        }
        
        // 增加长度重试
        if ($length < 8) {
            return $this->generateUniqueCode($length + 1, $maxAttempts);
        }
        
        return null;
    }
    
    // ==================== 域名池管理 ====================
    
    /**
     * 获取域名列表
     */
    public function getDomains(bool $enabledOnly = false): array {
        $sql = "SELECT * FROM jump_domains";
        if ($enabledOnly) {
            $sql .= " WHERE enabled = 1";
        }
        $sql .= " ORDER BY is_default DESC, id ASC";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 获取域名
     */
    public function getDomain(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM jump_domains WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * 获取默认域名
     */
    public function getDefaultDomain(): ?array {
        $stmt = $this->pdo->query("SELECT * FROM jump_domains WHERE is_default = 1 AND enabled = 1 LIMIT 1");
        $domain = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$domain) {
            // 如果没有默认域名，取第一个启用的
            $stmt = $this->pdo->query("SELECT * FROM jump_domains WHERE enabled = 1 LIMIT 1");
            $domain = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        return $domain ?: null;
    }
    
    /**
     * 添加域名
     */
    public function addDomain(string $domain, string $name = '', bool $isDefault = false): array {
        $domain = trim($domain);
        $domain = rtrim($domain, '/'); // 移除尾部斜杠
        
        if (empty($domain)) {
            return ['success' => false, 'message' => '域名不能为空'];
        }
        
        // 自动补全 HTTPS 协议
        if (!preg_match('/^https?:\/\//i', $domain)) {
            $domain = 'https://' . $domain;
        }
        
        // 检查是否已存在
        $stmt = $this->pdo->prepare("SELECT 1 FROM jump_domains WHERE domain = ?");
        $stmt->execute([$domain]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => '域名已存在'];
        }
        
        try {
            // 如果设为默认，先取消其他默认
            if ($isDefault) {
                $this->pdo->exec("UPDATE jump_domains SET is_default = 0");
            }
            
            $stmt = $this->pdo->prepare("INSERT INTO jump_domains (domain, name, is_default) VALUES (?, ?, ?)");
            $stmt->execute([$domain, $name, $isDefault ? 1 : 0]);
            
            return ['success' => true, 'id' => $this->pdo->lastInsertId()];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => '添加失败: ' . $e->getMessage()];
        }
    }
    
    /**
     * 更新域名
     */
    public function updateDomain(int $id, array $data): array {
        $domain = $this->getDomain($id);
        if (!$domain) {
            return ['success' => false, 'message' => '域名不存在'];
        }
        
        $updates = [];
        $params = [];
        
        if (isset($data['domain'])) {
            $updates[] = "domain = ?";
            $params[] = rtrim(trim($data['domain']), '/');
        }
        if (isset($data['name'])) {
            $updates[] = "name = ?";
            $params[] = $data['name'];
        }
        if (isset($data['enabled'])) {
            $updates[] = "enabled = ?";
            $params[] = $data['enabled'] ? 1 : 0;
        }
        if (isset($data['is_default']) && $data['is_default']) {
            // 先取消其他默认
            $this->pdo->exec("UPDATE jump_domains SET is_default = 0");
            $updates[] = "is_default = 1";
        }
        
        if (empty($updates)) {
            return ['success' => false, 'message' => '没有要更新的字段'];
        }
        
        $params[] = $id;
        $stmt = $this->pdo->prepare("UPDATE jump_domains SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);
        
        return ['success' => true];
    }
    
    /**
     * 删除域名
     */
    public function deleteDomain(int $id): array {
        $domain = $this->getDomain($id);
        if (!$domain) {
            return ['success' => false, 'message' => '域名不存在'];
        }
        
        if ($domain['is_default']) {
            return ['success' => false, 'message' => '不能删除默认域名'];
        }
        
        // 检查是否有规则使用此域名
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM jump_rules WHERE domain_id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            return ['success' => false, 'message' => '该域名下有规则，请先删除或转移规则'];
        }
        
        $this->pdo->prepare("DELETE FROM jump_domains WHERE id = ?")->execute([$id]);
        return ['success' => true];
    }
    
    // ==================== 规则 CRUD ====================
    
    /**
     * 创建跳转规则
     */
    public function create(string $type, string $matchKey, string $url, array $options = []): array {
        $url = trim($url);
        $matchKey = trim($matchKey);
        
        if (empty($url)) {
            return ['success' => false, 'message' => '目标URL不能为空'];
        }
        
        // URL 格式化
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }
        
        // 根据类型处理 match_key
        if ($type === self::TYPE_CODE) {
            // 短码模式：自动生成或使用自定义
            if (empty($matchKey)) {
                $matchKey = $this->generateUniqueCode();
                if (!$matchKey) {
                    return ['success' => false, 'message' => '生成短码失败，请重试'];
                }
            } else {
                // 验证自定义短码格式
                if (!preg_match('/^[a-zA-Z0-9_-]{2,20}$/', $matchKey)) {
                    return ['success' => false, 'message' => '短码格式无效，只能包含字母数字下划线和横杠，长度2-20'];
                }
            }
        } elseif ($type === self::TYPE_IP) {
            // IP模式：验证IP格式
            if (empty($matchKey)) {
                return ['success' => false, 'message' => 'IP地址不能为空'];
            }
        } else {
            return ['success' => false, 'message' => '无效的规则类型'];
        }
        
        // 检查是否已存在
        $stmt = $this->pdo->prepare("SELECT 1 FROM jump_rules WHERE rule_type = ? AND match_key = ?");
        $stmt->execute([$type, $matchKey]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => ($type === self::TYPE_IP ? 'IP已存在' : '短码已被使用')];
        }
        
        // 处理过期设置
        $expireType = $options['expire_type'] ?? 'permanent';
        $expireAt = null;
        $maxClicks = null;
        
        if ($expireType === 'datetime' && !empty($options['expire_at'])) {
            $expireAt = $options['expire_at'];
        } elseif ($expireType === 'clicks' && !empty($options['max_clicks'])) {
            $maxClicks = (int)$options['max_clicks'];
        }
        
        try {
            // 处理域名ID
            $domainId = null;
            if ($type === self::TYPE_CODE) {
                if (!empty($options['domain_id'])) {
                    $domainId = (int)$options['domain_id'];
                } else {
                    // 使用默认域名
                    $defaultDomain = $this->getDefaultDomain();
                    $domainId = $defaultDomain ? $defaultDomain['id'] : null;
                }
                
                // 更新域名使用计数
                if ($domainId) {
                    $this->pdo->prepare("UPDATE jump_domains SET use_count = use_count + 1 WHERE id = ?")
                              ->execute([$domainId]);
                }
            }
            
            $stmt = $this->pdo->prepare("
                INSERT INTO jump_rules (
                    rule_type, match_key, target_url, title, note, group_tag, domain_id, enabled,
                    block_desktop, block_ios, block_android,
                    country_whitelist_enabled, country_whitelist,
                    expire_type, expire_at, max_clicks
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $countryWhitelist = isset($options['country_whitelist']) 
                ? (is_array($options['country_whitelist']) ? json_encode($options['country_whitelist']) : $options['country_whitelist'])
                : null;
            
            $stmt->execute([
                $type,
                $matchKey,
                $url,
                $options['title'] ?? '',
                $options['note'] ?? '',
                $options['group_tag'] ?? ($type === self::TYPE_IP ? 'ip' : 'shortlink'),
                $domainId,
                (int)($options['enabled'] ?? 1),
                (int)($options['block_desktop'] ?? 0),
                (int)($options['block_ios'] ?? 0),
                (int)($options['block_android'] ?? 0),
                (int)($options['country_whitelist_enabled'] ?? 0),
                $countryWhitelist,
                $expireType,
                $expireAt,
                $maxClicks
            ]);
            
            $id = $this->pdo->lastInsertId();
            
            // 更新分组计数
            $groupTag = $options['group_tag'] ?? ($type === self::TYPE_IP ? 'ip' : 'shortlink');
            $this->pdo->prepare("UPDATE jump_groups SET rule_count = rule_count + 1 WHERE tag = ?")
                      ->execute([$groupTag]);
            
            return [
                'success' => true,
                'data' => [
                    'id' => $id,
                    'rule_type' => $type,
                    'match_key' => $matchKey,
                    'target_url' => $url,
                    'domain_id' => $domainId,
                    'jump_url' => $this->getJumpUrl($type, $matchKey, $domainId)
                ]
            ];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => '创建失败: ' . $e->getMessage()];
        }
    }
    
    /**
     * 获取跳转URL
     */
    public function getJumpUrl(string $type, string $matchKey, ?int $domainId = null): string {
        if ($type === self::TYPE_IP) {
            return 'http://' . $matchKey . '/';
        }
        
        // 获取域名
        $domain = null;
        if ($domainId) {
            $domainData = $this->getDomain($domainId);
            if ($domainData) {
                $domain = $domainData['domain'];
            }
        }
        
        if (!$domain) {
            $defaultDomain = $this->getDefaultDomain();
            $domain = $defaultDomain ? $defaultDomain['domain'] : 'http://localhost:8080/j';
        }
        
        // 确保域名有协议前缀
        if (!preg_match('/^https?:\/\//i', $domain)) {
            $domain = 'https://' . $domain;
        }
        
        return rtrim($domain, '/') . '/' . $matchKey;
    }
    
    /**
     * 通过类型和key获取规则（带缓存）
     */
    public function getByKey(string $type, string $matchKey, bool $useCache = true): ?array {
        $matchKey = trim($matchKey);
        $cacheKey = $type . ':' . $matchKey;
        
        // 检查缓存
        if ($useCache && isset(self::$cache[$cacheKey])) {
            if (time() - self::$cacheTime[$cacheKey] < self::CACHE_TTL) {
                return self::$cache[$cacheKey];
            }
            unset(self::$cache[$cacheKey], self::$cacheTime[$cacheKey]);
        }
        
        if (!$this->stmtGetByKey) {
            $this->stmtGetByKey = $this->pdo->prepare("
                SELECT * FROM jump_rules WHERE rule_type = ? AND match_key = ? LIMIT 1
            ");
        }
        
        $this->stmtGetByKey->execute([$type, $matchKey]);
        $row = $this->stmtGetByKey->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $row['enabled'] = (bool)$row['enabled'];
            $row['block_desktop'] = (bool)$row['block_desktop'];
            $row['block_ios'] = (bool)$row['block_ios'];
            $row['block_android'] = (bool)$row['block_android'];
            $row['country_whitelist_enabled'] = (bool)$row['country_whitelist_enabled'];
            $row['total_clicks'] = (int)$row['total_clicks'];
            $row['unique_visitors'] = (int)$row['unique_visitors'];
            $row['max_clicks'] = $row['max_clicks'] ? (int)$row['max_clicks'] : null;
            
            if ($row['country_whitelist'] && is_string($row['country_whitelist'])) {
                $row['country_whitelist'] = json_decode($row['country_whitelist'], true) ?? [];
            }
            
            // 缓存结果
            if ($useCache) {
                self::$cache[$cacheKey] = $row;
                self::$cacheTime[$cacheKey] = time();
            }
        }
        
        return $row ?: null;
    }
    
    /**
     * 通过ID获取规则
     */
    public function getById(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM jump_rules WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $row['enabled'] = (bool)$row['enabled'];
            $row['block_desktop'] = (bool)$row['block_desktop'];
            $row['block_ios'] = (bool)$row['block_ios'];
            $row['block_android'] = (bool)$row['block_android'];
            $row['country_whitelist_enabled'] = (bool)$row['country_whitelist_enabled'];
            $row['total_clicks'] = (int)$row['total_clicks'];
            $row['unique_visitors'] = (int)$row['unique_visitors'];
            
            if ($row['country_whitelist'] && is_string($row['country_whitelist'])) {
                $row['country_whitelist'] = json_decode($row['country_whitelist'], true) ?? [];
            }
        }
        
        return $row ?: null;
    }
    
    /**
     * 检查规则是否有效
     */
    public function isValid(array $rule): bool {
        if (!$rule['enabled']) {
            return false;
        }
        
        // 检查过期时间
        if ($rule['expire_type'] === 'datetime' && $rule['expire_at']) {
            if (strtotime($rule['expire_at']) < time()) {
                return false;
            }
        }
        
        // 检查点击次数限制
        if ($rule['expire_type'] === 'clicks' && $rule['max_clicks']) {
            if ($rule['total_clicks'] >= $rule['max_clicks']) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * 检查设备是否被拦截
     */
    public function checkDeviceBlock(array $rule, string $deviceType): ?string {
        if ($deviceType === 'desktop' && $rule['block_desktop']) {
            return 'desktop';
        }
        if ($deviceType === 'ios' && $rule['block_ios']) {
            return 'ios';
        }
        if ($deviceType === 'android' && $rule['block_android']) {
            return 'android';
        }
        return null;
    }
    
    /**
     * 检查国家是否被拦截
     */
    public function checkCountryBlock(array $rule, string $countryCode): ?string {
        if (!$rule['country_whitelist_enabled'] || empty($rule['country_whitelist'])) {
            return null;
        }
        
        $allowedCountries = array_map('strtoupper', $rule['country_whitelist']);
        if (!in_array(strtoupper($countryCode), $allowedCountries)) {
            return $countryCode;
        }
        
        return null;
    }
    
    /**
     * 获取规则列表
     */
    public function getList(array $filters = []): array {
        $where = ["1=1"];
        $params = [];
        
        if (!empty($filters['rule_type'])) {
            $where[] = "rule_type = ?";
            $params[] = $filters['rule_type'];
        }
        
        if (!empty($filters['group_tag'])) {
            $where[] = "group_tag = ?";
            $params[] = $filters['group_tag'];
        }
        
        if (isset($filters['enabled'])) {
            $where[] = "enabled = ?";
            $params[] = $filters['enabled'] ? 1 : 0;
        }
        
        if (!empty($filters['search'])) {
            $where[] = "(match_key LIKE ? OR target_url LIKE ? OR title LIKE ? OR note LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params = array_merge($params, [$search, $search, $search, $search]);
        }
        
        $sql = "SELECT * FROM jump_rules WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY created_at DESC";
        
        if (!empty($filters['limit'])) {
            $offset = $filters['offset'] ?? 0;
            $sql .= " LIMIT " . (int)$offset . ", " . (int)$filters['limit'];
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($rows as &$row) {
            $row['enabled'] = (bool)$row['enabled'];
            $row['block_desktop'] = (bool)$row['block_desktop'];
            $row['block_ios'] = (bool)$row['block_ios'];
            $row['block_android'] = (bool)$row['block_android'];
            $row['country_whitelist_enabled'] = (bool)$row['country_whitelist_enabled'];
            $row['total_clicks'] = (int)$row['total_clicks'];
            $row['unique_visitors'] = (int)$row['unique_visitors'];
            $row['domain_id'] = $row['domain_id'] ? (int)$row['domain_id'] : null;
            $row['jump_url'] = $this->getJumpUrl($row['rule_type'], $row['match_key'], $row['domain_id']);
            
            if ($row['country_whitelist'] && is_string($row['country_whitelist'])) {
                $row['country_whitelist'] = json_decode($row['country_whitelist'], true) ?? [];
            }
        }
        
        return $rows;
    }
    
    /**
     * 获取规则总数
     */
    public function getCount(array $filters = []): int {
        $where = ["1=1"];
        $params = [];
        
        if (!empty($filters['rule_type'])) {
            $where[] = "rule_type = ?";
            $params[] = $filters['rule_type'];
        }
        
        if (!empty($filters['group_tag'])) {
            $where[] = "group_tag = ?";
            $params[] = $filters['group_tag'];
        }
        
        if (isset($filters['enabled'])) {
            $where[] = "enabled = ?";
            $params[] = $filters['enabled'] ? 1 : 0;
        }
        
        if (!empty($filters['search'])) {
            $where[] = "(match_key LIKE ? OR target_url LIKE ? OR title LIKE ? OR note LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params = array_merge($params, [$search, $search, $search, $search]);
        }
        
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM jump_rules WHERE " . implode(' AND ', $where));
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * 更新规则
     */
    public function update(int $id, array $data): array {
        $rule = $this->getById($id);
        if (!$rule) {
            return ['success' => false, 'message' => '规则不存在'];
        }
        
        $updates = [];
        $params = [];
        
        $allowedFields = [
            'target_url', 'title', 'note', 'group_tag', 'enabled',
            'block_desktop', 'block_ios', 'block_android',
            'country_whitelist_enabled', 'country_whitelist',
            'expire_type', 'expire_at', 'max_clicks'
        ];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                
                if ($field === 'target_url' && !empty($value)) {
                    if (!preg_match('/^https?:\/\//i', $value)) {
                        $value = 'https://' . $value;
                    }
                }
                
                if ($field === 'country_whitelist' && is_array($value)) {
                    $value = json_encode($value);
                }
                
                if (in_array($field, ['enabled', 'block_desktop', 'block_ios', 'block_android', 'country_whitelist_enabled'])) {
                    $value = $value ? 1 : 0;
                }
                
                $updates[] = "$field = ?";
                $params[] = $value;
            }
        }
        
        if (empty($updates)) {
            return ['success' => false, 'message' => '没有要更新的字段'];
        }
        
        $params[] = $id;
        
        try {
            $stmt = $this->pdo->prepare("UPDATE jump_rules SET " . implode(', ', $updates) . " WHERE id = ?");
            $stmt->execute($params);
            
            // 清除缓存
            $cacheKey = $rule['rule_type'] . ':' . $rule['match_key'];
            unset(self::$cache[$cacheKey], self::$cacheTime[$cacheKey]);
            
            return ['success' => true, 'message' => '更新成功'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => '更新失败: ' . $e->getMessage()];
        }
    }
    
    /**
     * 删除规则
     */
    public function delete(int $id): array {
        $rule = $this->getById($id);
        if (!$rule) {
            return ['success' => false, 'message' => '规则不存在'];
        }
        
        try {
            $this->pdo->beginTransaction();
            
            // 删除规则
            $this->pdo->prepare("DELETE FROM jump_rules WHERE id = ?")->execute([$id]);
            
            // 删除日志（可选，也可以保留）
            // $this->pdo->prepare("DELETE FROM jump_logs WHERE rule_id = ?")->execute([$id]);
            // $this->pdo->prepare("DELETE FROM jump_uv WHERE rule_id = ?")->execute([$id]);
            
            // 更新分组计数
            $this->pdo->prepare("UPDATE jump_groups SET rule_count = rule_count - 1 WHERE tag = ? AND rule_count > 0")
                      ->execute([$rule['group_tag']]);
            
            $this->pdo->commit();
            
            // 清除缓存
            $cacheKey = $rule['rule_type'] . ':' . $rule['match_key'];
            unset(self::$cache[$cacheKey], self::$cacheTime[$cacheKey]);
            
            return ['success' => true, 'message' => '删除成功'];
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => '删除失败: ' . $e->getMessage()];
        }
    }
    
    /**
     * 切换启用状态
     */
    public function toggle(int $id): array {
        $rule = $this->getById($id);
        if (!$rule) {
            return ['success' => false, 'message' => '规则不存在'];
        }
        
        $newEnabled = $rule['enabled'] ? 0 : 1;
        $this->pdo->prepare("UPDATE jump_rules SET enabled = ? WHERE id = ?")->execute([$newEnabled, $id]);
        
        // 清除缓存
        $cacheKey = $rule['rule_type'] . ':' . $rule['match_key'];
        unset(self::$cache[$cacheKey], self::$cacheTime[$cacheKey]);
        
        return ['success' => true, 'enabled' => (bool)$newEnabled];
    }
    
    // ==================== 访问统计 ====================
    
    /**
     * 记录点击
     */
    public function recordClick(int $ruleId, string $type, string $matchKey, array $visitorInfo): void {
        try {
            // 更新点击计数
            $this->pdo->prepare("
                UPDATE jump_rules 
                SET total_clicks = total_clicks + 1, last_access_at = NOW() 
                WHERE id = ?
            ")->execute([$ruleId]);
            
            // 插入访问日志
            $stmt = $this->pdo->prepare("
                INSERT INTO jump_logs (rule_id, rule_type, match_key, visitor_ip, country, device_type, os, browser, user_agent, referer)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $ruleId,
                $type,
                $matchKey,
                $visitorInfo['ip'] ?? '',
                $visitorInfo['country'] ?? '',
                $visitorInfo['device_type'] ?? 'unknown',
                $visitorInfo['os'] ?? '',
                $visitorInfo['browser'] ?? '',
                $visitorInfo['user_agent'] ?? '',
                $visitorInfo['referer'] ?? ''
            ]);
            
            // 更新UV (使用 visitor_ip 的 MD5 作为 hash)
            $visitorHash = md5($visitorInfo['ip'] ?? '', true); // 返回 16字节二进制
            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO jump_uv (rule_id, visitor_hash) VALUES (?, ?)
            ");
            $isNewVisitor = $stmt->execute([$ruleId, $visitorHash]);
            
            if ($stmt->rowCount() > 0) {
                // 新访客，更新UV计数
                $this->pdo->prepare("UPDATE jump_rules SET unique_visitors = unique_visitors + 1 WHERE id = ?")
                          ->execute([$ruleId]);
            }
            
            // 更新日统计
            $today = date('Y-m-d');
            $this->pdo->prepare("
                INSERT INTO jump_daily_stats (rule_id, stat_date, clicks, unique_visitors) 
                VALUES (?, ?, 1, ?)
                ON DUPLICATE KEY UPDATE 
                    clicks = clicks + 1,
                    unique_visitors = unique_visitors + VALUES(unique_visitors)
            ")->execute([$ruleId, $today, $stmt->rowCount() > 0 ? 1 : 0]);
            
            // 清除缓存
            $cacheKey = $type . ':' . $matchKey;
            unset(self::$cache[$cacheKey], self::$cacheTime[$cacheKey]);
            
        } catch (PDOException $e) {
            error_log("JumpService::recordClick error: " . $e->getMessage());
        }
    }
    
    /**
     * 获取规则统计
     */
    public function getStats(int $ruleId, int $days = 7): array {
        $rule = $this->getById($ruleId);
        if (!$rule) {
            return ['success' => false, 'message' => '规则不存在'];
        }
        
        // 总体统计
        $stats = [
            'total_clicks' => $rule['total_clicks'],
            'unique_visitors' => $rule['unique_visitors'],
            'last_access_at' => $rule['last_access_at']
        ];
        
        // 日统计
        $stmt = $this->pdo->prepare("
            SELECT stat_date, clicks, unique_visitors 
            FROM jump_daily_stats 
            WHERE rule_id = ? AND stat_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            ORDER BY stat_date
        ");
        $stmt->execute([$ruleId, $days]);
        $stats['daily'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 设备分布
        $stmt = $this->pdo->prepare("
            SELECT device_type, COUNT(*) as count 
            FROM jump_logs 
            WHERE rule_id = ? 
            GROUP BY device_type
        ");
        $stmt->execute([$ruleId]);
        $stats['devices'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 国家分布 (Top 10)
        $stmt = $this->pdo->prepare("
            SELECT country, COUNT(*) as count 
            FROM jump_logs 
            WHERE rule_id = ? AND country != ''
            GROUP BY country
            ORDER BY count DESC
            LIMIT 10
        ");
        $stmt->execute([$ruleId]);
        $stats['countries'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 最近访问记录
        $stmt = $this->pdo->prepare("
            SELECT visitor_ip, country, device_type, os, browser, visited_at 
            FROM jump_logs 
            WHERE rule_id = ? 
            ORDER BY visited_at DESC 
            LIMIT 20
        ");
        $stmt->execute([$ruleId]);
        $stats['recent_visits'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return ['success' => true, 'data' => $stats];
    }
    
    /**
     * 获取仪表盘统计
     */
    public function getDashboardStats(?string $ruleType = null): array {
        $typeWhere = $ruleType ? " WHERE rule_type = '$ruleType'" : "";
        $typeAnd = $ruleType ? " AND jr.rule_type = '$ruleType'" : "";
        
        $stats = [];
        
        // 总规则数
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM jump_rules $typeWhere");
        $stats['total_rules'] = (int)$stmt->fetchColumn();
        
        // 活跃规则数
        $whereEnabled = $ruleType ? " WHERE enabled = 1 AND rule_type = '$ruleType'" : " WHERE enabled = 1";
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM jump_rules $whereEnabled");
        $stats['active_rules'] = (int)$stmt->fetchColumn();
        
        // 总点击量
        $stmt = $this->pdo->query("SELECT COALESCE(SUM(total_clicks), 0) FROM jump_rules $typeWhere");
        $stats['total_clicks'] = (int)$stmt->fetchColumn();
        
        // 今日点击
        $today = date('Y-m-d');
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(jds.clicks), 0) 
            FROM jump_daily_stats jds
            JOIN jump_rules jr ON jds.rule_id = jr.id
            WHERE jds.stat_date = ? $typeAnd
        ");
        $stmt->execute([$today]);
        $stats['today_clicks'] = (int)$stmt->fetchColumn();
        
        // 今日UV
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(jds.unique_visitors), 0) 
            FROM jump_daily_stats jds
            JOIN jump_rules jr ON jds.rule_id = jr.id
            WHERE jds.stat_date = ? $typeAnd
        ");
        $stmt->execute([$today]);
        $stats['today_uv'] = (int)$stmt->fetchColumn();
        
        // 按类型统计
        $stmt = $this->pdo->query("
            SELECT rule_type, COUNT(*) as count, SUM(total_clicks) as clicks 
            FROM jump_rules GROUP BY rule_type
        ");
        $byType = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stats['by_type'] = $byType;
        
        // 为Dashboard提供便捷字段
        $stats['ip_rules'] = 0;
        $stats['code_rules'] = 0;
        foreach ($byType as $row) {
            if ($row['rule_type'] === 'ip') {
                $stats['ip_rules'] = (int)$row['count'];
            } elseif ($row['rule_type'] === 'code') {
                $stats['code_rules'] = (int)$row['count'];
            }
        }
        
        return $stats;
    }
    
    // ==================== 分组管理 ====================
    
    /**
     * 获取分组列表
     */
    public function getGroups(): array {
        $stmt = $this->pdo->query("SELECT * FROM jump_groups ORDER BY sort_order, id");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 创建分组
     */
    public function createGroup(string $tag, string $name, string $description = ''): array {
        $tag = trim($tag);
        $name = trim($name);
        
        if (empty($tag) || empty($name)) {
            return ['success' => false, 'message' => '标识和名称不能为空'];
        }
        
        try {
            $stmt = $this->pdo->prepare("INSERT INTO jump_groups (tag, name, description) VALUES (?, ?, ?)");
            $stmt->execute([$tag, $name, $description]);
            return ['success' => true, 'id' => $this->pdo->lastInsertId()];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => '创建失败: ' . $e->getMessage()];
        }
    }
    
    /**
     * 删除分组
     */
    public function deleteGroup(string $tag): array {
        if (in_array($tag, ['default', 'ip', 'shortlink'])) {
            return ['success' => false, 'message' => '系统分组不能删除'];
        }
        
        // 将该分组的规则移到默认分组
        $this->pdo->prepare("UPDATE jump_rules SET group_tag = 'default' WHERE group_tag = ?")
                  ->execute([$tag]);
        
        $this->pdo->prepare("DELETE FROM jump_groups WHERE tag = ?")->execute([$tag]);
        
        // 更新默认分组计数
        $this->pdo->query("UPDATE jump_groups SET rule_count = (SELECT COUNT(*) FROM jump_rules WHERE group_tag = 'default') WHERE tag = 'default'");
        
        return ['success' => true];
    }
    
    // ==================== 辅助方法 ====================
    
    /**
     * 获取配置
     */
    public function getConfig(string $key, $default = null) {
        $stmt = $this->pdo->prepare("SELECT `value` FROM config WHERE `key` = ?");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : $default;
    }
    
    /**
     * 设置配置
     */
    public function setConfig(string $key, $value): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO config (`key`, `value`) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
        ");
        $stmt->execute([$key, $value]);
    }
    
    /**
     * 批量创建规则
     */
    public function batchCreate(string $type, array $items, string $defaultUrl = ''): array {
        $success = 0;
        $failed = 0;
        $errors = [];
        
        foreach ($items as $item) {
            $matchKey = is_array($item) ? ($item['match_key'] ?? $item['ip'] ?? $item['code'] ?? '') : $item;
            $url = is_array($item) ? ($item['url'] ?? $item['target_url'] ?? $defaultUrl) : $defaultUrl;
            $options = is_array($item) ? $item : [];
            
            $result = $this->create($type, $matchKey, $url, $options);
            if ($result['success']) {
                $success++;
            } else {
                $failed++;
                $errors[] = $matchKey . ': ' . $result['message'];
            }
        }
        
        return [
            'success' => true,
            'created' => $success,
            'failed' => $failed,
            'errors' => $errors
        ];
    }
    
    /**
     * 解析设备信息
     */
    public static function parseUserAgent(string $ua): array {
        $info = [
            'device_type' => 'unknown',
            'os' => '',
            'browser' => ''
        ];
        
        // 设备类型
        if (preg_match('/iphone|ipad|ipod/i', $ua)) {
            $info['device_type'] = 'ios';
            $info['os'] = 'iOS';
        } elseif (preg_match('/android/i', $ua)) {
            if (preg_match('/mobile/i', $ua)) {
                $info['device_type'] = 'mobile';
            } else {
                $info['device_type'] = 'tablet';
            }
            $info['os'] = 'Android';
        } elseif (preg_match('/windows/i', $ua)) {
            $info['device_type'] = 'desktop';
            $info['os'] = 'Windows';
        } elseif (preg_match('/macintosh|mac os x/i', $ua)) {
            $info['device_type'] = 'desktop';
            $info['os'] = 'macOS';
        } elseif (preg_match('/linux/i', $ua)) {
            $info['device_type'] = 'desktop';
            $info['os'] = 'Linux';
        }
        
        // 浏览器
        if (preg_match('/chrome\/[\d.]+/i', $ua)) {
            $info['browser'] = 'Chrome';
        } elseif (preg_match('/firefox\/[\d.]+/i', $ua)) {
            $info['browser'] = 'Firefox';
        } elseif (preg_match('/safari\/[\d.]+/i', $ua) && !preg_match('/chrome/i', $ua)) {
            $info['browser'] = 'Safari';
        } elseif (preg_match('/edge\/[\d.]+/i', $ua)) {
            $info['browser'] = 'Edge';
        } elseif (preg_match('/msie|trident/i', $ua)) {
            $info['browser'] = 'IE';
        }
        
        return $info;
    }
}
