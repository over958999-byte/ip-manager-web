<?php
/**
 * LRU (Least Recently Used) 缓存实现
 * 高性能内存缓存，支持容量限制和过期时间
 */

class LRUCache {
    // 缓存数据
    private array $cache = [];
    
    // 访问顺序（双向链表模拟）
    private array $order = [];
    
    // 过期时间
    private array $expires = [];
    
    // 最大容量
    private int $capacity;
    
    // 统计信息
    private int $hits = 0;
    private int $misses = 0;
    
    public function __construct(int $capacity = 10000) {
        $this->capacity = $capacity;
    }
    
    /**
     * 获取缓存
     */
    public function get(string $key, $default = null) {
        // 检查是否存在
        if (!isset($this->cache[$key])) {
            $this->misses++;
            return $default;
        }
        
        // 检查是否过期
        if (isset($this->expires[$key]) && $this->expires[$key] < time()) {
            $this->delete($key);
            $this->misses++;
            return $default;
        }
        
        // 移动到队尾（最近使用）
        $this->touch($key);
        $this->hits++;
        
        return $this->cache[$key];
    }
    
    /**
     * 设置缓存
     */
    public function set(string $key, $value, int $ttl = 0): bool {
        // 如果已存在，先删除旧位置
        if (isset($this->cache[$key])) {
            $this->removeFromOrder($key);
        }
        
        // 淘汰策略：超出容量时删除最久未使用的
        while (count($this->cache) >= $this->capacity) {
            $this->evict();
        }
        
        // 添加到缓存
        $this->cache[$key] = $value;
        $this->order[] = $key;
        
        // 设置过期时间
        if ($ttl > 0) {
            $this->expires[$key] = time() + $ttl;
        } else {
            unset($this->expires[$key]);
        }
        
        return true;
    }
    
    /**
     * 删除缓存
     */
    public function delete(string $key): bool {
        if (!isset($this->cache[$key])) {
            return false;
        }
        
        unset($this->cache[$key]);
        unset($this->expires[$key]);
        $this->removeFromOrder($key);
        
        return true;
    }
    
    /**
     * 检查是否存在
     */
    public function has(string $key): bool {
        if (!isset($this->cache[$key])) {
            return false;
        }
        
        // 检查过期
        if (isset($this->expires[$key]) && $this->expires[$key] < time()) {
            $this->delete($key);
            return false;
        }
        
        return true;
    }
    
    /**
     * 获取或设置（缓存穿透保护）
     */
    public function remember(string $key, callable $callback, int $ttl = 0) {
        $value = $this->get($key);
        
        if ($value !== null) {
            return $value;
        }
        
        $value = $callback();
        $this->set($key, $value, $ttl);
        
        return $value;
    }
    
    /**
     * 批量获取
     */
    public function mget(array $keys): array {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }
        return $results;
    }
    
    /**
     * 批量设置
     */
    public function mset(array $items, int $ttl = 0): bool {
        foreach ($items as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }
    
    /**
     * 清空缓存
     */
    public function flush(): bool {
        $this->cache = [];
        $this->order = [];
        $this->expires = [];
        return true;
    }
    
    /**
     * 获取缓存大小
     */
    public function size(): int {
        return count($this->cache);
    }
    
    /**
     * 获取统计信息
     */
    public function stats(): array {
        $total = $this->hits + $this->misses;
        return [
            'size' => count($this->cache),
            'capacity' => $this->capacity,
            'hits' => $this->hits,
            'misses' => $this->misses,
            'hit_rate' => $total > 0 ? round($this->hits / $total * 100, 2) : 0,
        ];
    }
    
    /**
     * 重置统计
     */
    public function resetStats(): void {
        $this->hits = 0;
        $this->misses = 0;
    }
    
    /**
     * 更新访问顺序
     */
    private function touch(string $key): void {
        $this->removeFromOrder($key);
        $this->order[] = $key;
    }
    
    /**
     * 从顺序列表中移除
     */
    private function removeFromOrder(string $key): void {
        $index = array_search($key, $this->order, true);
        if ($index !== false) {
            unset($this->order[$index]);
            // 重新索引
            $this->order = array_values($this->order);
        }
    }
    
    /**
     * 淘汰最久未使用的项
     */
    private function evict(): void {
        if (empty($this->order)) {
            return;
        }
        
        // 先尝试清理过期项
        $now = time();
        foreach ($this->expires as $key => $expireAt) {
            if ($expireAt < $now) {
                $this->delete($key);
                return;
            }
        }
        
        // 删除最久未使用的（队首）
        $oldestKey = array_shift($this->order);
        if ($oldestKey !== null) {
            unset($this->cache[$oldestKey]);
            unset($this->expires[$oldestKey]);
        }
    }
    
    /**
     * 清理过期项
     */
    public function gc(): int {
        $count = 0;
        $now = time();
        
        foreach ($this->expires as $key => $expireAt) {
            if ($expireAt < $now) {
                $this->delete($key);
                $count++;
            }
        }
        
        return $count;
    }
}

/**
 * 增强型多级缓存服务
 * L1: LRU内存缓存 -> L2: APCu -> L3: Redis -> L4: MySQL
 */
class MultiLevelCache {
    private static ?MultiLevelCache $instance = null;
    
    private LRUCache $l1Cache;
    private bool $apcuEnabled;
    private ?object $redis = null;
    
    // 配置
    private array $config = [
        'l1_capacity' => 10000,      // L1 缓存容量
        'l1_ttl' => 60,              // L1 默认TTL
        'l2_ttl' => 300,             // L2 默认TTL
        'l3_ttl' => 3600,            // L3 默认TTL
        'null_ttl' => 30,            // 空值TTL（防穿透）
    ];
    
    private function __construct() {
        $this->l1Cache = new LRUCache($this->config['l1_capacity']);
        $this->apcuEnabled = function_exists('apcu_fetch') && apcu_enabled();
        $this->initRedis();
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 初始化 Redis
     */
    private function initRedis(): void {
        if (class_exists('RedisCache')) {
            try {
                $this->redis = RedisCache::getInstance();
            } catch (Exception $e) {
                $this->redis = null;
            }
        }
    }
    
    /**
     * 多级获取
     */
    public function get(string $key, callable $loader = null, int $ttl = 0) {
        // L1: LRU 内存缓存
        $value = $this->l1Cache->get($key);
        if ($value !== null) {
            return $value;
        }
        
        // L2: APCu
        if ($this->apcuEnabled) {
            $value = apcu_fetch($key, $success);
            if ($success) {
                // 回填 L1
                $this->l1Cache->set($key, $value, $this->config['l1_ttl']);
                return $value;
            }
        }
        
        // L3: Redis
        if ($this->redis) {
            $value = $this->redis->get($key);
            if ($value !== null) {
                // 回填 L1 & L2
                $this->l1Cache->set($key, $value, $this->config['l1_ttl']);
                if ($this->apcuEnabled) {
                    apcu_store($key, $value, $this->config['l2_ttl']);
                }
                return $value;
            }
        }
        
        // 所有缓存未命中，执行加载器
        if ($loader !== null) {
            $value = $loader();
            $this->set($key, $value, $ttl);
            return $value;
        }
        
        return null;
    }
    
    /**
     * 多级设置
     */
    public function set(string $key, $value, int $ttl = 0): bool {
        $isNull = $value === null;
        
        // 空值使用较短TTL
        $l1Ttl = $isNull ? $this->config['null_ttl'] : ($ttl ?: $this->config['l1_ttl']);
        $l2Ttl = $isNull ? $this->config['null_ttl'] : ($ttl ?: $this->config['l2_ttl']);
        $l3Ttl = $isNull ? $this->config['null_ttl'] : ($ttl ?: $this->config['l3_ttl']);
        
        // L1
        $this->l1Cache->set($key, $value, $l1Ttl);
        
        // L2
        if ($this->apcuEnabled) {
            apcu_store($key, $value, $l2Ttl);
        }
        
        // L3
        if ($this->redis) {
            $this->redis->set($key, $value, $l3Ttl);
        }
        
        return true;
    }
    
    /**
     * 多级删除（延迟双删保证一致性）
     */
    public function delete(string $key, bool $doubleDelete = true): bool {
        // 第一次删除
        $this->l1Cache->delete($key);
        if ($this->apcuEnabled) {
            apcu_delete($key);
        }
        if ($this->redis) {
            $this->redis->delete($key);
        }
        
        // 延迟双删
        if ($doubleDelete) {
            register_shutdown_function(function() use ($key) {
                usleep(300000); // 300ms
                $this->l1Cache->delete($key);
                if ($this->apcuEnabled) {
                    apcu_delete($key);
                }
                if ($this->redis) {
                    $this->redis->delete($key);
                }
            });
        }
        
        return true;
    }
    
    /**
     * 获取统计信息
     */
    public function stats(): array {
        return [
            'l1' => $this->l1Cache->stats(),
            'l2_enabled' => $this->apcuEnabled,
            'l2_info' => $this->apcuEnabled ? apcu_cache_info(true) : null,
            'l3_enabled' => $this->redis !== null,
        ];
    }
    
    /**
     * 清空所有缓存
     */
    public function flush(): bool {
        $this->l1Cache->flush();
        if ($this->apcuEnabled) {
            apcu_clear_cache();
        }
        if ($this->redis) {
            $this->redis->flush();
        }
        return true;
    }
}
