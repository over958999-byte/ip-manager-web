<?php
/**
 * 分布式限流服务
 * 支持：令牌桶、滑动窗口、漏桶算法
 * 增强：管理端/跳转端分离、按用户/API Key细粒度限流
 */

class RateLimiter {
    private static $instance = null;
    
    // 限流配置
    private $rules = [];
    
    // 文件存储目录
    private $dataDir;
    
    // 内存计数器（进程级）
    private $counters = [];
    
    // Redis连接（可选）
    private $redis = null;
    
    private function __construct() {
        $this->dataDir = sys_get_temp_dir() . '/ip_manager_ratelimit';
        if (!is_dir($this->dataDir)) {
            @mkdir($this->dataDir, 0755, true);
        }
        
        // 初始化Redis
        $this->initRedis();
        
        // 默认规则（分离管理端与跳转端）
        $this->rules = [
            // ===== 跳转端规则（高吞吐） =====
            'shortlink' => [
                'rate' => 1000,         // 每秒1000请求
                'burst' => 2000,        // 突发2000
                'window' => 1,
            ],
            'shortlink_ip' => [
                'rate' => 50,           // 每IP每秒50请求
                'burst' => 100,
                'window' => 1,
            ],
            
            // ===== 管理端规则（更严格） =====
            'admin' => [
                'rate' => 100,          // 每秒100请求
                'burst' => 200,
                'window' => 1,
            ],
            'admin_ip' => [
                'rate' => 30,           // 每IP每秒30请求
                'burst' => 60,
                'window' => 1,
            ],
            'admin_user' => [
                'rate' => 60,           // 每用户每秒60请求
                'burst' => 120,
                'window' => 1,
            ],
            
            // ===== API规则（按Key限流） =====
            'api' => [
                'rate' => 50,           // 每秒50请求
                'burst' => 100,
                'window' => 1,
            ],
            'api_key' => [
                'rate' => 100,          // 每API Key每秒100请求
                'burst' => 200,
                'window' => 1,
            ],
            'api_key_premium' => [
                'rate' => 500,          // 高级API Key每秒500请求
                'burst' => 1000,
                'window' => 1,
            ],
            
            // ===== 通用规则 =====
            'default' => [
                'rate' => 100,          // 每秒100请求
                'burst' => 200,
                'window' => 1,
            ],
            'ip' => [
                'rate' => 30,           // 每IP每秒30请求
                'burst' => 60,
                'window' => 1,
            ],
            
            // ===== 登录保护 =====
            'login' => [
                'rate' => 5,            // 每秒5次登录尝试
                'burst' => 10,
                'window' => 60,         // 60秒窗口
            ],
            'login_ip' => [
                'rate' => 10,           // 每IP每分钟10次
                'burst' => 15,
                'window' => 60,
            ],
        ];
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
                2.0
            );
            
            $redisPass = getenv('REDIS_PASSWORD');
            if ($redisPass) {
                $this->redis->auth($redisPass);
            }
        } catch (Exception $e) {
            $this->redis = null;
        }
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 设置自定义规则
     */
    public function setRule(string $name, int $rate, int $burst = 0, int $window = 1): void {
        $this->rules[$name] = [
            'rate' => $rate,
            'burst' => $burst ?: $rate * 2,
            'window' => $window,
        ];
    }
    
    /**
     * 检查短链跳转限流（高性能）
     */
    public function checkShortlink(string $ip): array {
        // 全局限流
        $global = $this->tokenBucket('global', 'shortlink');
        if (!$global['allowed']) {
            return $global;
        }
        
        // IP限流
        return $this->tokenBucket($ip, 'shortlink_ip');
    }
    
    /**
     * 检查管理端限流
     */
    public function checkAdmin(string $ip, ?string $userId = null): array {
        // 全局限流
        $global = $this->tokenBucket('global', 'admin');
        if (!$global['allowed']) {
            return $global;
        }
        
        // IP限流
        $ipResult = $this->tokenBucket($ip, 'admin_ip');
        if (!$ipResult['allowed']) {
            return $ipResult;
        }
        
        // 用户限流（如果有用户ID）
        if ($userId) {
            return $this->tokenBucket($userId, 'admin_user');
        }
        
        return $ipResult;
    }
    
    /**
     * 检查API限流（按API Key）
     */
    public function checkApi(string $ip, ?string $apiKey = null, bool $isPremium = false): array {
        // 全局限流
        $global = $this->tokenBucket('global', 'api');
        if (!$global['allowed']) {
            return $global;
        }
        
        // IP限流
        $ipResult = $this->tokenBucket($ip, 'ip');
        if (!$ipResult['allowed']) {
            return $ipResult;
        }
        
        // API Key限流
        if ($apiKey) {
            $ruleName = $isPremium ? 'api_key_premium' : 'api_key';
            return $this->tokenBucket($apiKey, $ruleName);
        }
        
        return $ipResult;
    }
    
    /**
     * 检查登录限流
     */
    public function checkLogin(string $ip, ?string $username = null): array {
        // IP限流
        $ipResult = $this->slidingWindow($ip, 'login_ip');
        if (!$ipResult['allowed']) {
            return $ipResult;
        }
        
        // 用户名限流（如果有）
        if ($username) {
            return $this->slidingWindow($username, 'login');
        }
        
        return $ipResult;
    }
    
    /**
     * 令牌桶限流
     * @param string $key 限流键
     * @param string $ruleName 规则名称
     * @return array ['allowed' => bool, 'remaining' => int, 'reset' => int]
     */
    public function tokenBucket(string $key, string $ruleName = 'default'): array {
        $rule = $this->rules[$ruleName] ?? $this->rules['default'];
        $bucketKey = "token:{$ruleName}:{$key}";
        
        // 使用Redis（如果可用）
        if ($this->redis) {
            return $this->tokenBucketRedis($bucketKey, $rule);
        }
        
        $now = microtime(true);
        $bucket = $this->getBucket($bucketKey);
        
        if ($bucket === null) {
            // 初始化桶
            $bucket = [
                'tokens' => $rule['burst'],
                'last_time' => $now,
            ];
        } else {
            // 计算新增令牌
            $elapsed = $now - $bucket['last_time'];
            $newTokens = $elapsed * $rule['rate'];
            $bucket['tokens'] = min($rule['burst'], $bucket['tokens'] + $newTokens);
            $bucket['last_time'] = $now;
        }
        
        // 尝试获取令牌
        if ($bucket['tokens'] >= 1) {
            $bucket['tokens'] -= 1;
            $this->saveBucket($bucketKey, $bucket);
            
            return [
                'allowed' => true,
                'remaining' => (int)$bucket['tokens'],
                'reset' => (int)ceil((1 - fmod($bucket['tokens'], 1)) / $rule['rate']),
            ];
        }
        
        // 计算重置时间
        $resetTime = (int)ceil((1 - $bucket['tokens']) / $rule['rate']);
        
        return [
            'allowed' => false,
            'remaining' => 0,
            'reset' => $resetTime,
        ];
    }
    
    /**
     * Redis令牌桶实现（分布式）
     */
    private function tokenBucketRedis(string $key, array $rule): array {
        $now = microtime(true);
        $redisKey = "ratelimit:{$key}";
        
        // Lua脚本实现原子操作
        $script = <<<LUA
local key = KEYS[1]
local rate = tonumber(ARGV[1])
local burst = tonumber(ARGV[2])
local now = tonumber(ARGV[3])

local data = redis.call('HMGET', key, 'tokens', 'last_time')
local tokens = tonumber(data[1]) or burst
local last_time = tonumber(data[2]) or now

-- 计算新令牌
local elapsed = now - last_time
local new_tokens = math.min(burst, tokens + elapsed * rate)

if new_tokens >= 1 then
    new_tokens = new_tokens - 1
    redis.call('HMSET', key, 'tokens', new_tokens, 'last_time', now)
    redis.call('EXPIRE', key, 60)
    return {1, math.floor(new_tokens)}
else
    return {0, 0}
end
LUA;
        
        try {
            $result = $this->redis->eval($script, [$redisKey, $rule['rate'], $rule['burst'], $now], 1);
            return [
                'allowed' => (bool)$result[0],
                'remaining' => (int)$result[1],
                'reset' => 1,
            ];
        } catch (Exception $e) {
            // Redis失败，降级到内存
            return $this->tokenBucket(str_replace('ratelimit:', '', $key), 'default');
        }
    }
    
    /**
     * 滑动窗口限流
     * @param string $key 限流键
     * @param string $ruleName 规则名称
     * @return array
     */
    public function slidingWindow(string $key, string $ruleName = 'default'): array {
        $rule = $this->rules[$ruleName] ?? $this->rules['default'];
        $windowKey = "window:{$ruleName}:{$key}";
        
        $now = time();
        $windowStart = $now - $rule['window'];
        
        // 获取窗口数据
        $window = $this->getWindow($windowKey);
        
        // 清理过期请求
        $window = array_filter($window, fn($t) => $t > $windowStart);
        
        // 检查是否超限
        $count = count($window);
        if ($count >= $rule['rate']) {
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset' => $rule['window'],
                'count' => $count,
            ];
        }
        
        // 添加当前请求
        $window[] = $now;
        $this->saveWindow($windowKey, $window);
        
        return [
            'allowed' => true,
            'remaining' => $rule['rate'] - $count - 1,
            'reset' => $rule['window'],
            'count' => $count + 1,
        ];
    }
    
    /**
     * 固定窗口限流（高性能）
     */
    public function fixedWindow(string $key, string $ruleName = 'default'): array {
        $rule = $this->rules[$ruleName] ?? $this->rules['default'];
        $windowSize = $rule['window'];
        $currentWindow = (int)(time() / $windowSize);
        $counterKey = "fixed:{$ruleName}:{$key}:{$currentWindow}";
        
        // 使用内存计数器
        if (!isset($this->counters[$counterKey])) {
            $this->counters[$counterKey] = 0;
        }
        
        $count = ++$this->counters[$counterKey];
        
        // 清理旧窗口
        $this->cleanOldCounters($currentWindow - 1);
        
        if ($count > $rule['rate']) {
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset' => ($currentWindow + 1) * $windowSize - time(),
                'count' => $count,
            ];
        }
        
        return [
            'allowed' => true,
            'remaining' => $rule['rate'] - $count,
            'reset' => ($currentWindow + 1) * $windowSize - time(),
            'count' => $count,
        ];
    }
    
    /**
     * 漏桶限流
     */
    public function leakyBucket(string $key, string $ruleName = 'default'): array {
        $rule = $this->rules[$ruleName] ?? $this->rules['default'];
        $bucketKey = "leaky:{$ruleName}:{$key}";
        
        $now = microtime(true);
        $bucket = $this->getBucket($bucketKey);
        
        if ($bucket === null) {
            $bucket = [
                'water' => 0,
                'last_leak' => $now,
            ];
        }
        
        // 漏水
        $elapsed = $now - $bucket['last_leak'];
        $leaked = $elapsed * $rule['rate'];
        $bucket['water'] = max(0, $bucket['water'] - $leaked);
        $bucket['last_leak'] = $now;
        
        // 加水
        if ($bucket['water'] < $rule['burst']) {
            $bucket['water'] += 1;
            $this->saveBucket($bucketKey, $bucket);
            
            return [
                'allowed' => true,
                'remaining' => (int)($rule['burst'] - $bucket['water']),
                'reset' => 0,
            ];
        }
        
        return [
            'allowed' => false,
            'remaining' => 0,
            'reset' => (int)ceil(($bucket['water'] - $rule['burst'] + 1) / $rule['rate']),
        ];
    }
    
    /**
     * IP限流快捷方法
     */
    public function checkIp(string $ip): array {
        return $this->tokenBucket($ip, 'ip');
    }
    
    /**
     * 全局限流
     */
    public function checkGlobal(): array {
        return $this->tokenBucket('global', 'shortlink');
    }
    
    // ==================== 存储 ====================
    
    private function getBucket(string $key): ?array {
        if (function_exists('apcu_fetch')) {
            $data = apcu_fetch($key, $success);
            if ($success) {
                return $data;
            }
        }
        
        $file = $this->dataDir . '/' . md5($key) . '.json';
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            return $data ?: null;
        }
        
        return null;
    }
    
    private function saveBucket(string $key, array $bucket): void {
        if (function_exists('apcu_store')) {
            apcu_store($key, $bucket, 3600);
        }
        
        $file = $this->dataDir . '/' . md5($key) . '.json';
        file_put_contents($file, json_encode($bucket), LOCK_EX);
    }
    
    private function getWindow(string $key): array {
        if (function_exists('apcu_fetch')) {
            $data = apcu_fetch($key, $success);
            if ($success) {
                return $data;
            }
        }
        
        $file = $this->dataDir . '/' . md5($key) . '.win';
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            return $data ?: [];
        }
        
        return [];
    }
    
    private function saveWindow(string $key, array $window): void {
        if (function_exists('apcu_store')) {
            apcu_store($key, $window, 60);
        }
        
        $file = $this->dataDir . '/' . md5($key) . '.win';
        file_put_contents($file, json_encode($window), LOCK_EX);
    }
    
    private function cleanOldCounters(int $threshold): void {
        foreach ($this->counters as $key => $value) {
            if (preg_match('/:(\d+)$/', $key, $matches)) {
                if ((int)$matches[1] < $threshold) {
                    unset($this->counters[$key]);
                }
            }
        }
    }
    
    /**
     * 获取统计
     */
    public function getStats(): array {
        return [
            'rules' => $this->rules,
            'counters_count' => count($this->counters),
        ];
    }
}
