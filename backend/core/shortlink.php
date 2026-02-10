<?php
/**
 * 短链接服务类
 */
class ShortLinkService {
    private $pdo;
    private $codeLength = 6;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * 生成随机短码
     */
    private function generateCode($length = null) {
        $length = $length ?? $this->codeLength;
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $code;
    }
    
    /**
     * 检查短码是否存在
     */
    private function codeExists($code) {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM short_links WHERE code = ?");
        $stmt->execute([$code]);
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * 创建短链接
     */
    public function create($url, $options = []) {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'message' => '无效的URL'];
        }
        
        // 自定义短码或生成随机短码
        $code = !empty($options['custom_code']) ? $options['custom_code'] : null;
        
        if ($code) {
            // 验证自定义短码格式
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $code)) {
                return ['success' => false, 'message' => '短码只能包含字母、数字、下划线和横线'];
            }
            if (strlen($code) < 3 || strlen($code) > 20) {
                return ['success' => false, 'message' => '短码长度需在3-20字符之间'];
            }
            if ($this->codeExists($code)) {
                return ['success' => false, 'message' => '短码已存在'];
            }
        } else {
            // 生成唯一短码
            $attempts = 0;
            do {
                $code = $this->generateCode();
                $attempts++;
                if ($attempts > 100) {
                    return ['success' => false, 'message' => '生成短码失败，请重试'];
                }
            } while ($this->codeExists($code));
        }
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO short_links (code, domain, original_url, title, group_tag, expire_type, expire_at, max_clicks, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $domain = $options['domain'] ?? null;
            $title = $options['title'] ?? '';
            $groupTag = $options['group_tag'] ?? 'default';
            $expireType = $options['expire_type'] ?? 'permanent';
            $expireAt = $options['expire_at'] ?? null;
            $maxClicks = $options['max_clicks'] ?? null;
            
            // 过期时间处理
            if ($expireType === 'permanent') {
                $expireAt = null;
                $maxClicks = null;
            } elseif ($expireType === 'clicks') {
                $expireAt = null;
            } elseif ($expireType === 'datetime') {
                $maxClicks = null;
            }
            
            $stmt->execute([
                $code,
                $domain,
                $url,
                $title,
                $groupTag,
                $expireType,
                $expireAt,
                $maxClicks
            ]);
            
            $id = $this->pdo->lastInsertId();
            
            return [
                'success' => true,
                'message' => '创建成功',
                'data' => [
                    'id' => $id,
                    'code' => $code,
                    'short_url' => $this->getShortUrl($code, $domain),
                    'original_url' => $url
                ]
            ];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => '创建失败: ' . $e->getMessage()];
        }
    }
    
    /**
     * 获取短链接完整URL
     */
    public function getShortUrl($code, $domain = null) {
        if (empty($domain)) {
            // 从数据库获取域名
            $stmt = $this->pdo->prepare("SELECT domain FROM short_links WHERE code = ?");
            $stmt->execute([$code]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $domain = $row['domain'] ?? null;
        }
        
        if (!empty($domain)) {
            // 使用配置的域名
            $protocol = 'https://';
            return $protocol . $domain . '/j/' . $code;
        }
        
        // 回退到服务器地址
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        return $protocol . $host . '/j/' . $code;
    }
    
    /**
     * 获取所有短链接
     */
    public function getAll($filters = []) {
        $where = [];
        $params = [];
        
        if (!empty($filters['group_tag'])) {
            $where[] = "group_tag = ?";
            $params[] = $filters['group_tag'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = "(code LIKE ? OR original_url LIKE ? OR title LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        
        if (isset($filters['enabled'])) {
            $where[] = "enabled = ?";
            $params[] = $filters['enabled'] ? 1 : 0;
        }
        
        $sql = "SELECT * FROM short_links";
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY created_at DESC";
        
        if (isset($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
            if (isset($filters['offset'])) {
                $sql .= " OFFSET " . (int)$filters['offset'];
            }
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 添加短链接URL
        foreach ($results as &$row) {
            $row['short_url'] = $this->getShortUrl($row['code'], $row['domain']);
        }
        
        return $results;
    }
    
    /**
     * 根据短码获取链接
     */
    public function getByCode($code, $incrementClick = true) {
        $stmt = $this->pdo->prepare("SELECT * FROM short_links WHERE code = ?");
        $stmt->execute([$code]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$link) {
            return null;
        }
        
        // 检查是否启用
        if (!$link['enabled']) {
            return null;
        }
        
        // 检查是否过期
        if ($link['expire_type'] === 'datetime' && $link['expire_at']) {
            if (strtotime($link['expire_at']) < time()) {
                return null;
            }
        }
        
        // 检查点击次数限制
        if ($link['expire_type'] === 'clicks' && $link['max_clicks']) {
            if ($link['total_clicks'] >= $link['max_clicks']) {
                return null;
            }
        }
        
        // 增加点击计数
        if ($incrementClick) {
            $this->incrementClick($link['id']);
        }
        
        return $link;
    }
    
    /**
     * 根据ID获取链接
     */
    public function getById($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM short_links WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * 更新短链接
     */
    public function update($id, $data) {
        $allowedFields = ['original_url', 'title', 'group_tag', 'enabled', 'expire_type', 'expire_at', 'max_clicks', 'domain'];
        $updates = [];
        $params = [];
        
        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields)) {
                $updates[] = "$field = ?";
                $params[] = $value;
            }
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $params[] = $id;
        
        try {
            $sql = "UPDATE short_links SET " . implode(", ", $updates) . " WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * 删除短链接
     */
    public function delete($id) {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM short_links WHERE id = ?");
            return $stmt->execute([$id]);
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * 批量删除短链接
     */
    public function batchDelete($ids) {
        if (empty($ids) || !is_array($ids)) {
            return false;
        }
        
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->pdo->prepare("DELETE FROM short_links WHERE id IN ($placeholders)");
            return $stmt->execute($ids);
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * 增加点击次数
     */
    public function incrementClick($id) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE short_links 
                SET total_clicks = total_clicks + 1, 
                    last_access_at = NOW() 
                WHERE id = ?
            ");
            return $stmt->execute([$id]);
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * 获取分组列表
     */
    public function getGroups() {
        $stmt = $this->pdo->query("SELECT DISTINCT group_tag FROM short_links ORDER BY group_tag");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * 获取统计信息
     */
    public function getStats() {
        $stmt = $this->pdo->query("
            SELECT 
                COUNT(*) as total_links,
                SUM(total_clicks) as total_clicks,
                SUM(unique_visitors) as total_visitors,
                COUNT(CASE WHEN enabled = 1 THEN 1 END) as active_links
            FROM short_links
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * 切换启用状态
     */
    public function toggleEnabled($id) {
        try {
            $stmt = $this->pdo->prepare("UPDATE short_links SET enabled = NOT enabled WHERE id = ?");
            return $stmt->execute([$id]);
        } catch (PDOException $e) {
            return false;
        }
    }
}
