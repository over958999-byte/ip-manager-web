<?php
/**
 * 高性能多级缓存服务
 * 支持：缓存穿透防护、缓存击穿防护、缓存雪崩防护、缓存一致性
 * 
 * 缓存层级: Local Memory -> APCu -> Redis (可选)
 * TTL抖动策略：防止缓存雪崩
 */

class CacheService {
    private static $instance = null;
    
    // 内存缓存（进程级，最快）
    private $memoryCache = [];
    private $memoryCacheTime = [];
    
    // Redis连接（可选）
    private $redis = null;
    
    // 配置
    private $config = [
        'default_ttl' => 300,           // 默认缓存5分钟
        'null_ttl' => 60,               // 空值缓存1分钟（防穿透）
        'lock_timeout' => 5,            // 锁超时5秒
        'lock_wait' => 100000,          // 锁等待100ms
        'max_memory_items' => 10000,    // 内存缓存最大条目
        'ttl_jitter_percent' => 20,     // TTL抖动百分比（防雪崩）
        'local_ttl' => 10,              // 本地缓存短TTL（秒）
        'enable_redis' => false,        // 是否启用Redis
        'redis_prefix' => 'ipmgr:',     // Redis键前缀
    ];
    
    // 缓存层级
    const LAYER_MEMORY = 'memory';
    const LAYER_APCU = 'apcu';
    const LAYER_REDIS = 'redis';
    
    // 布隆过滤器（简化版）
    private $bloomFilter = [];
    private $bloomSize = 100000;
    
    // 文件锁目录
    private $lockDir;
    
    // APCu是否可用
    private $apcuEnabled = false;
    
    // 统计
    private $stats = [
        'hits' => ['memory' => 0, 'apcu' => 0, 'redis' => 0],
        'misses' => 0,
    ];
    
    private function __construct() {
        $this->lockDir = sys_get_temp_dir() . '/ip_manager_locks';
        if (!is_dir($this->lockDir)) {
            @mkdir($this->lockDir, 0755, true);
        }
        
        // 检测APCu
        $this->apcuEnabled = function_exists('apcu_fetch') && apcu_enabled();
        
        // 初始化Redis（如果配置了）
        $this->initRedis();
        
        // 从环境变量加载配置
        $this->loadConfig();
    }
    
    /**
     * 从环境变量加载配置
     */
    private function loadConfig(): void {
        if (getenv('CACHE_DEFAULT_TTL')) {
            $this->config['default_ttl'] = (int)getenv('CACHE_DEFAULT_TTL');
        }
        if (getenv('CACHE_LOCAL_TTL')) {
            $this->config['local_ttl'] = (int)getenv('CACHE_LOCAL_TTL');
        }
        if (getenv('CACHE_TTL_JITTER')) {
            $this->config['ttl_jitter_percent'] = (int)getenv('CACHE_TTL_JITTER');
        }
    }
    
    /**
     * 初始化Redis连接
     */
    private function initRedis(): void {
        if (!class_exists('Redis')) {
            return;
        }
        
        $redisHost = getenv('REDIS_HOST');
        if (!$redisHost) {
            return;
        }
        
        try {
            $this->redis = new Redis();
            $this->redis->connect(
                $redisHost,
                (int)(getenv('REDIS_PORT') ?: 6379),
                2.0  // 连接超时
            );
            
            $redisPass = getenv('REDIS_PASSWORD');
            if ($redisPass) {
                $this->redis->auth($redisPass);
            }
            
            $this->config['enable_redis'] = true;
        } catch (Exception $e) {
            $this->redis = null;
            if (class_exists('Logger')) {
                Logger::logWarning('Redis连接失败，降级到APCu/内存', ['error' => $e->getMessage()]);
            }
        }
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 计算带抖动的TTL（防雪崩）
     */
    private function jitterTtl(int $ttl): int {
        $jitterRange = (int)($ttl * $this->config['ttl_jitter_percent'] / 100);
        return $ttl + mt_rand(-$jitterRange, $jitterRange);
    }
    
    /**
     * 获取缓存（带穿透/击穿保护，多级回退）
     * @param string $key 缓存键
     * @param callable $loader 数据加载器（缓存未命中时调用）
     * @param int $ttl 过期时间（秒）
     * @param array $options 选项 ['local_ttl' => int, 'skip_local' => bool]
     * @return mixed
     */
    public function get(string $key, callable $loader, int $ttl = 0, array $options = []): mixed {
        if ($ttl <= 0) {
            $ttl = $this->config['default_ttl'];
        }
        
        // 添加TTL抖动防雪崩
        $ttl = $this->jitterTtl($ttl);
        $localTtl = $options['local_ttl'] ?? $this->config['local_ttl'];
        
        // 1. 尝试从内存缓存获取（最快）
        if (empty($options['skip_local'])) {
            $result = $this->getFromMemory($key);
            if ($result !== null) {
                $this->stats['hits']['memory']++;
                return $result['value'];
            }
        }
        
        // 2. 尝试从APCu获取
        if ($this->apcuEnabled) {
            $result = apcu_fetch($key, $success);
            if ($success) {
                $this->stats['hits']['apcu']++;
                // 回填内存缓存（短TTL）
                $this->setToMemory($key, $result, $localTtl);
                return $result;
            }
        }
        
        // 3. 尝试从Redis获取
        if ($this->redis) {
            try {
                $result = $this->redis->get($this->config['redis_prefix'] . $key);
                if ($result !== false) {
                    $this->stats['hits']['redis']++;
                    $value = json_decode($result, true);
                    // 回填上层缓存
                    $this->setToMemory($key, $value, $localTtl);
                    if ($this->apcuEnabled) {
                        apcu_store($key, $value, $ttl);
                    }
                    return $value;
                }
            } catch (Exception $e) {
                // Redis失败，继续
            }
        }
        
        // 4. 检查布隆过滤器（防穿透）
        if ($this->bloomCheck($key) === false) {
            $this->stats['misses']++;
            return null;
        }
        
        // 5. 获取互斥锁（防击穿）
        $lockKey = "lock:{$key}";
        $locked = $this->lock($lockKey, $this->config['lock_timeout']);
        
        if (!$locked) {
            // 未获取到锁，等待后重试从缓存读取
            usleep($this->config['lock_wait']);
            $result = $this->getFromMemory($key);
            if ($result !== null) {
                return $result['value'];
            }
            if ($this->apcuEnabled) {
                $result = apcu_fetch($key, $success);
                if ($success) {
                    return $result;
                }
            }
        }
        
        try {
            // 6. 执行数据加载
            $value = $loader();
            $this->stats['misses']++;
            
            // 7. 更新布隆过滤器
            if ($value !== null) {
                $this->bloomAdd($key);
            }
            
            // 8. 写入所有缓存层
            $this->setAll($key, $value, $value === null ? $this->config['null_ttl'] : $ttl);
            
            return $value;
        } finally {
            // 9. 释放锁
            if ($locked) {
                $this->unlock($lockKey);
            }
        }
    }
    
    /**
     * 设置缓存（写入所有层）
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool {
        if ($ttl <= 0) {
            $ttl = $this->config['default_ttl'];
        }
        return $this->setAll($key, $value, $ttl);
    }
    
    /**
     * 写入所有缓存层
     */
    private function setAll(string $key, mixed $value, int $ttl): bool {
        // 写入内存缓存（短TTL）
        $this->setToMemory($key, $value, min($ttl, $this->config['local_ttl']));
        
        // 写入APCu
        if ($this->apcuEnabled) {
            apcu_store($key, $value, $ttl);
        }
        
        // 写入Redis
        if ($this->redis) {
            try {
                $this->redis->setex(
                    $this->config['redis_prefix'] . $key, 
                    $ttl, 
                    json_encode($value, JSON_UNESCAPED_UNICODE)
                );
            } catch (Exception $e) {
                // Redis写入失败，不影响主流程
            }
        }
        
        return true;
    }
    
    /**
     * 删除缓存（延迟双删保证一致性）
     */
    public function delete(string $key, bool $doubleDelete = true): bool {
        // 第一次删除：所有层
        $this->deleteFromMemory($key);
        if ($this->apcuEnabled) {
            apcu_delete($key);
        }
        if ($this->redis) {
            try {
                $this->redis->del($this->config['redis_prefix'] . $key);
            } catch (Exception $e) {
                // 忽略
            }
        }
        
        // 延迟双删（异步或注册shutdown）
        if ($doubleDelete) {
            register_shutdown_function(function() use ($key) {
                usleep(500000); // 500ms后
                $this->deleteFromMemory($key);
                if ($this->apcuEnabled) {
                    apcu_delete($key);
                }
                if ($this->redis) {
                    try {
                        $this->redis->del($this->config['redis_prefix'] . $key);
                    } catch (Exception $e) {
                        // 忽略
                    }
                }
            });
        }
        
        return true;
    }
    
    /**
     * 批量获取（减少穿透）
     */
    public function mget(array $keys, callable $batchLoader): array {
        $results = [];
        $missingKeys = [];
        
        foreach ($keys as $key) {
            $result = $this->getFromMemory($key);
            if ($result !== null) {
                $results[$key] = $result['value'];
            } else if ($this->apcuEnabled) {
                $value = apcu_fetch($key, $success);
                if ($success) {
                    $results[$key] = $value;
                } else {
                    $missingKeys[] = $key;
                }
            } else {
                $missingKeys[] = $key;
            }
        }
        
        // 批量加载缺失的
        if (!empty($missingKeys)) {
            $loaded = $batchLoader($missingKeys);
            foreach ($loaded as $key => $value) {
                $this->set($key, $value);
                $results[$key] = $value;
            }
        }
        
        return $results;
    }
    
    // ==================== 内存缓存 ====================
    
    private function getFromMemory(string $key): ?array {
        if (!isset($this->memoryCache[$key])) {
            return null;
        }
        
        // 检查过期
        if (time() > $this->memoryCacheTime[$key]) {
            unset($this->memoryCache[$key], $this->memoryCacheTime[$key]);
            return null;
        }
        
        return ['value' => $this->memoryCache[$key]];
    }
    
    private function setToMemory(string $key, mixed $value, int $ttl): void {
        // LRU淘汰
        if (count($this->memoryCache) >= $this->config['max_memory_items']) {
            // 删除最早的10%
            $deleteCount = (int)($this->config['max_memory_items'] * 0.1);
            $keys = array_keys($this->memoryCache);
            for ($i = 0; $i < $deleteCount && $i < count($keys); $i++) {
                unset($this->memoryCache[$keys[$i]], $this->memoryCacheTime[$keys[$i]]);
            }
        }
        
        $this->memoryCache[$key] = $value;
        $this->memoryCacheTime[$key] = time() + $ttl;
    }
    
    private function deleteFromMemory(string $key): void {
        unset($this->memoryCache[$key], $this->memoryCacheTime[$key]);
    }
    
    // ==================== 布隆过滤器（防穿透）====================
    
    private function bloomHash(string $key): array {
        // 使用多个hash函数
        $h1 = crc32($key) % $this->bloomSize;
        $h2 = abs(crc32(md5($key))) % $this->bloomSize;
        $h3 = abs(crc32(sha1($key))) % $this->bloomSize;
        return [$h1, $h2, $h3];
    }
    
    public function bloomAdd(string $key): void {
        foreach ($this->bloomHash($key) as $pos) {
            $this->bloomFilter[$pos] = true;
        }
    }
    
    public function bloomCheck(string $key): bool {
        // 布隆过滤器为空时返回true（可能存在）
        if (empty($this->bloomFilter)) {
            return true;
        }
        
        foreach ($this->bloomHash($key) as $pos) {
            if (!isset($this->bloomFilter[$pos])) {
                return false; // 肯定不存在
            }
        }
        return true; // 可能存在
    }
    
    /**
     * 预热布隆过滤器
     */
    public function warmupBloom(array $keys): void {
        foreach ($keys as $key) {
            $this->bloomAdd($key);
        }
    }
    
    // ==================== 分布式锁 ====================
    
    /**
     * 获取锁
     */
    public function lock(string $key, int $timeout = 5): bool {
        $lockFile = $this->lockDir . '/' . md5($key) . '.lock';
        $startTime = time();
        
        while (true) {
            $fp = @fopen($lockFile, 'x');
            if ($fp !== false) {
                // 写入过期时间
                fwrite($fp, (string)(time() + $timeout));
                fclose($fp);
                return true;
            }
            
            // 检查锁是否过期
            if (file_exists($lockFile)) {
                $content = @file_get_contents($lockFile);
                if ($content && (int)$content < time()) {
                    // 锁已过期，尝试删除
                    @unlink($lockFile);
                    continue;
                }
            }
            
            // 超时检查
            if (time() - $startTime >= $timeout) {
                return false;
            }
            
            usleep(10000); // 等待10ms
        }
    }
    
    /**
     * 释放锁
     */
    public function unlock(string $key): bool {
        $lockFile = $this->lockDir . '/' . md5($key) . '.lock';
        return @unlink($lockFile);
    }
    
    // ==================== 缓存预热 ====================
    
    /**
     * 预热缓存
     * @param array $items [key => loader] 或 [key => value]
     */
    public function warmup(array $items): int {
        $count = 0;
        foreach ($items as $key => $item) {
            if (is_callable($item)) {
                $value = $item();
            } else {
                $value = $item;
            }
            $this->set($key, $value);
            $this->bloomAdd($key);
            $count++;
        }
        return $count;
    }
    
    // ==================== 统计 ====================
    
    public function getStats(): array {
        return [
            'memory_items' => count($this->memoryCache),
            'bloom_items' => count($this->bloomFilter),
            'apcu_enabled' => $this->apcuEnabled,
            'apcu_info' => $this->apcuEnabled ? apcu_cache_info(true) : null,
        ];
    }
    
    /**
     * 清空所有缓存
     */
    public function flush(): bool {
        $this->memoryCache = [];
        $this->memoryCacheTime = [];
        $this->bloomFilter = [];
        
        if ($this->apcuEnabled) {
            apcu_clear_cache();
        }
        
        return true;
    }
}
