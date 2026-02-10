<?php
/**
 * Redis 缓存服务
 * 支持分布式部署，兼容无 Redis 时降级到 APCu
 */

class RedisCache {
    private static $instance = null;
    private $redis = null;
    private $connected = false;
    private $prefix = 'ipm:';
    
    // 配置
    private $config = [
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => null,
        'database' => 0,
        'timeout' => 2.0,
        'retry_interval' => 100,
        'read_timeout' => 2.0,
    ];
    
    private function __construct() {
        $this->loadConfig();
        $this->connect();
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 加载配置
     */
    private function loadConfig(): void {
        // 从环境变量加载
        if (getenv('REDIS_HOST')) {
            $this->config['host'] = getenv('REDIS_HOST');
        }
        if (getenv('REDIS_PORT')) {
            $this->config['port'] = (int)getenv('REDIS_PORT');
        }
        if (getenv('REDIS_PASSWORD')) {
            $this->config['password'] = getenv('REDIS_PASSWORD');
        }
        if (getenv('REDIS_DATABASE')) {
            $this->config['database'] = (int)getenv('REDIS_DATABASE');
        }
        if (getenv('REDIS_PREFIX')) {
            $this->prefix = getenv('REDIS_PREFIX');
        }
        
        // 从数据库配置加载
        if (class_exists('Database')) {
            try {
                $db = Database::getInstance();
                $redisConfig = $db->getConfig('redis_config', []);
                if (!empty($redisConfig)) {
                    $this->config = array_merge($this->config, $redisConfig);
                }
            } catch (Exception $e) {
                // 忽略
            }
        }
    }
    
    /**
     * 连接 Redis
     */
    private function connect(): void {
        if (!class_exists('Redis')) {
            return;
        }
        
        try {
            $this->redis = new Redis();
            $this->connected = $this->redis->connect(
                $this->config['host'],
                $this->config['port'],
                $this->config['timeout'],
                null,
                $this->config['retry_interval']
            );
            
            if ($this->connected && !empty($this->config['password'])) {
                $this->redis->auth($this->config['password']);
            }
            
            if ($this->connected && $this->config['database'] > 0) {
                $this->redis->select($this->config['database']);
            }
            
            // 设置读取超时
            $this->redis->setOption(Redis::OPT_READ_TIMEOUT, $this->config['read_timeout']);
            
        } catch (Exception $e) {
            $this->connected = false;
            if (class_exists('Logger')) {
                Logger::logWarning('Redis连接失败', ['error' => $e->getMessage()]);
            }
        }
    }
    
    /**
     * 检查是否连接
     */
    public function isConnected(): bool {
        if (!$this->connected || !$this->redis) {
            return false;
        }
        
        try {
            return $this->redis->ping() === '+PONG' || $this->redis->ping() === true;
        } catch (Exception $e) {
            $this->connected = false;
            return false;
        }
    }
    
    /**
     * 获取缓存
     */
    public function get(string $key, $default = null) {
        $key = $this->prefix . $key;
        
        // 尝试 Redis
        if ($this->isConnected()) {
            try {
                $value = $this->redis->get($key);
                if ($value !== false) {
                    $decoded = @unserialize($value);
                    return $decoded !== false ? $decoded : $value;
                }
            } catch (Exception $e) {
                // 降级到 APCu
            }
        }
        
        // 降级到 APCu
        if (function_exists('apcu_fetch')) {
            $value = apcu_fetch($key, $success);
            if ($success) {
                return $value;
            }
        }
        
        return $default;
    }
    
    /**
     * 设置缓存
     */
    public function set(string $key, $value, int $ttl = 3600): bool {
        $key = $this->prefix . $key;
        $serialized = serialize($value);
        
        $success = false;
        
        // 尝试 Redis
        if ($this->isConnected()) {
            try {
                $success = $this->redis->setex($key, $ttl, $serialized);
            } catch (Exception $e) {
                // 继续到 APCu
            }
        }
        
        // 同时写入 APCu（本地缓存加速）
        if (function_exists('apcu_store')) {
            apcu_store($key, $value, min($ttl, 60)); // APCu 短期缓存
        }
        
        return $success;
    }
    
    /**
     * 删除缓存
     */
    public function delete(string $key): bool {
        $key = $this->prefix . $key;
        
        $success = false;
        
        if ($this->isConnected()) {
            try {
                $success = $this->redis->del($key) > 0;
            } catch (Exception $e) {
                // 忽略
            }
        }
        
        if (function_exists('apcu_delete')) {
            apcu_delete($key);
        }
        
        return $success;
    }
    
    /**
     * 检查键是否存在
     */
    public function exists(string $key): bool {
        $key = $this->prefix . $key;
        
        if ($this->isConnected()) {
            try {
                return $this->redis->exists($key) > 0;
            } catch (Exception $e) {
                // 降级
            }
        }
        
        if (function_exists('apcu_exists')) {
            return apcu_exists($key);
        }
        
        return false;
    }
    
    /**
     * 自增
     */
    public function incr(string $key, int $step = 1): int {
        $key = $this->prefix . $key;
        
        if ($this->isConnected()) {
            try {
                return $this->redis->incrBy($key, $step);
            } catch (Exception $e) {
                // 降级
            }
        }
        
        // APCu 降级
        if (function_exists('apcu_inc')) {
            return apcu_inc($key, $step) ?: 0;
        }
        
        return 0;
    }
    
    /**
     * 自减
     */
    public function decr(string $key, int $step = 1): int {
        $key = $this->prefix . $key;
        
        if ($this->isConnected()) {
            try {
                return $this->redis->decrBy($key, $step);
            } catch (Exception $e) {
                // 降级
            }
        }
        
        if (function_exists('apcu_dec')) {
            return apcu_dec($key, $step) ?: 0;
        }
        
        return 0;
    }
    
    /**
     * 批量获取
     */
    public function mget(array $keys): array {
        $prefixedKeys = array_map(fn($k) => $this->prefix . $k, $keys);
        $result = [];
        
        if ($this->isConnected()) {
            try {
                $values = $this->redis->mget($prefixedKeys);
                foreach ($keys as $i => $key) {
                    $value = $values[$i] ?? false;
                    $result[$key] = $value !== false ? @unserialize($value) : null;
                }
                return $result;
            } catch (Exception $e) {
                // 降级
            }
        }
        
        // APCu 降级
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }
        
        return $result;
    }
    
    /**
     * 批量设置
     */
    public function mset(array $items, int $ttl = 3600): bool {
        if ($this->isConnected()) {
            try {
                $pipe = $this->redis->multi(Redis::PIPELINE);
                foreach ($items as $key => $value) {
                    $pipe->setex($this->prefix . $key, $ttl, serialize($value));
                }
                $pipe->exec();
                return true;
            } catch (Exception $e) {
                // 降级
            }
        }
        
        // APCu 降级
        foreach ($items as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        
        return true;
    }
    
    /**
     * 按模式删除
     */
    public function deletePattern(string $pattern): int {
        if (!$this->isConnected()) {
            return 0;
        }
        
        try {
            $keys = $this->redis->keys($this->prefix . $pattern);
            if (!empty($keys)) {
                return $this->redis->del($keys);
            }
        } catch (Exception $e) {
            // 忽略
        }
        
        return 0;
    }
    
    /**
     * 清空所有缓存
     */
    public function flush(): bool {
        if ($this->isConnected()) {
            try {
                // 只删除带前缀的键
                $keys = $this->redis->keys($this->prefix . '*');
                if (!empty($keys)) {
                    $this->redis->del($keys);
                }
            } catch (Exception $e) {
                // 忽略
            }
        }
        
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
        
        return true;
    }
    
    /**
     * 获取缓存统计
     */
    public function getStats(): array {
        $stats = [
            'redis_connected' => $this->isConnected(),
            'redis_host' => $this->config['host'],
            'redis_port' => $this->config['port'],
        ];
        
        if ($this->isConnected()) {
            try {
                $info = $this->redis->info();
                $stats['redis_version'] = $info['redis_version'] ?? 'unknown';
                $stats['redis_memory_used'] = $info['used_memory_human'] ?? '0B';
                $stats['redis_connected_clients'] = $info['connected_clients'] ?? 0;
                $stats['redis_total_keys'] = $this->redis->dbSize();
                $stats['redis_uptime_days'] = $info['uptime_in_days'] ?? 0;
            } catch (Exception $e) {
                $stats['redis_error'] = $e->getMessage();
            }
        }
        
        // APCu 统计
        if (function_exists('apcu_cache_info')) {
            $apcuInfo = @apcu_cache_info(true);
            if ($apcuInfo) {
                $stats['apcu_enabled'] = true;
                $stats['apcu_memory'] = round(($apcuInfo['mem_size'] ?? 0) / 1024 / 1024, 2) . 'MB';
                $stats['apcu_entries'] = $apcuInfo['num_entries'] ?? 0;
            }
        }
        
        return $stats;
    }
    
    // ==================== 高级功能 ====================
    
    /**
     * 获取或设置（缓存穿透保护）
     */
    public function remember(string $key, int $ttl, callable $callback) {
        $value = $this->get($key);
        
        if ($value !== null) {
            return $value;
        }
        
        $value = $callback();
        
        if ($value !== null) {
            $this->set($key, $value, $ttl);
        }
        
        return $value;
    }
    
    /**
     * 分布式锁
     */
    public function lock(string $key, int $ttl = 10): bool {
        $key = $this->prefix . 'lock:' . $key;
        
        if ($this->isConnected()) {
            try {
                return $this->redis->set($key, time(), ['NX', 'EX' => $ttl]) === true;
            } catch (Exception $e) {
                // 降级
            }
        }
        
        return true; // 无 Redis 时不锁定
    }
    
    /**
     * 释放锁
     */
    public function unlock(string $key): bool {
        return $this->delete('lock:' . $key);
    }
    
    /**
     * 限流检查
     */
    public function rateLimit(string $key, int $limit, int $window): array {
        $key = 'rate:' . $key;
        
        if ($this->isConnected()) {
            try {
                $current = $this->redis->incr($this->prefix . $key);
                if ($current === 1) {
                    $this->redis->expire($this->prefix . $key, $window);
                }
                
                $ttl = $this->redis->ttl($this->prefix . $key);
                
                return [
                    'allowed' => $current <= $limit,
                    'current' => $current,
                    'limit' => $limit,
                    'remaining' => max(0, $limit - $current),
                    'reset' => time() + $ttl
                ];
            } catch (Exception $e) {
                // 降级允许
            }
        }
        
        return ['allowed' => true, 'current' => 0, 'limit' => $limit, 'remaining' => $limit];
    }
    
    /**
     * 发布消息
     */
    public function publish(string $channel, $message): int {
        if (!$this->isConnected()) {
            return 0;
        }
        
        try {
            return $this->redis->publish(
                $this->prefix . $channel,
                is_string($message) ? $message : json_encode($message)
            );
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * 添加到列表
     */
    public function lpush(string $key, $value): int {
        if (!$this->isConnected()) {
            return 0;
        }
        
        try {
            return $this->redis->lPush($this->prefix . $key, serialize($value));
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * 从列表弹出
     */
    public function rpop(string $key) {
        if (!$this->isConnected()) {
            return null;
        }
        
        try {
            $value = $this->redis->rPop($this->prefix . $key);
            return $value !== false ? @unserialize($value) : null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * 获取原生 Redis 实例
     */
    public function getRedis(): ?Redis {
        return $this->redis;
    }
}
