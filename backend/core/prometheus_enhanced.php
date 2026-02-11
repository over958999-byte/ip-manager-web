<?php

/**
 * 增强型 Prometheus 指标收集
 * 
 * 业务指标：
 * - ip_jump_requests_total{rule_type, status, country}
 * - ip_jump_latency_seconds{rule_type}
 * - antibot_blocks_total{reason}
 * - cache_operations_total{cache_level, operation}
 * - api_response_time_seconds{endpoint, method}
 * - active_rules_count{rule_type}
 * - shortlink_clicks_total
 * - domain_safety_score
 */

class PrometheusEnhanced
{
    private static ?self $instance = null;
    private array $metrics = [];
    private array $histogramBuckets;
    private string $prefix = 'ip_manager_';
    
    // Redis 用于存储分布式指标
    private ?\Redis $redis = null;
    
    private function __construct()
    {
        $this->histogramBuckets = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10];
        $this->initRedis();
    }
    
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function initRedis(): void
    {
        try {
            $host = getenv('REDIS_HOST') ?: '127.0.0.1';
            $port = (int)(getenv('REDIS_PORT') ?: 6379);
            $password = getenv('REDIS_PASSWORD') ?: null;
            
            $this->redis = new \Redis();
            $this->redis->connect($host, $port, 2.0);
            
            if ($password) {
                $this->redis->auth($password);
            }
            
            $this->redis->setOption(\Redis::OPT_PREFIX, 'prometheus:');
        } catch (\Exception $e) {
            $this->redis = null;
            error_log("Prometheus Redis connection failed: " . $e->getMessage());
        }
    }
    
    // ==================== 计数器 ====================
    
    /**
     * 记录跳转请求
     */
    public function recordJumpRequest(string $ruleType, string $status, string $country = 'unknown'): void
    {
        $this->incrementCounter('jump_requests_total', [
            'rule_type' => $ruleType,
            'status'    => $status,
            'country'   => $country,
        ]);
    }
    
    /**
     * 记录反爬拦截
     */
    public function recordAntibotBlock(string $reason): void
    {
        $this->incrementCounter('antibot_blocks_total', [
            'reason' => $reason,
        ]);
    }
    
    /**
     * 记录缓存操作
     */
    public function recordCacheOperation(string $level, string $operation, bool $hit): void
    {
        $this->incrementCounter('cache_operations_total', [
            'cache_level' => $level,
            'operation'   => $operation,
            'hit'         => $hit ? 'true' : 'false',
        ]);
    }
    
    /**
     * 记录短链接点击
     */
    public function recordShortlinkClick(string $code): void
    {
        $this->incrementCounter('shortlink_clicks_total', [
            'code' => $code,
        ]);
    }
    
    // ==================== 直方图 ====================
    
    /**
     * 记录跳转延迟
     */
    public function recordJumpLatency(float $seconds, string $ruleType): void
    {
        $this->observeHistogram('jump_latency_seconds', $seconds, [
            'rule_type' => $ruleType,
        ]);
    }
    
    /**
     * 记录 API 响应时间
     */
    public function recordApiResponseTime(float $seconds, string $endpoint, string $method): void
    {
        $this->observeHistogram('api_response_time_seconds', $seconds, [
            'endpoint' => $endpoint,
            'method'   => $method,
        ]);
    }
    
    /**
     * 记录数据库查询时间
     */
    public function recordDbQueryTime(float $seconds, string $query): void
    {
        // 简化查询类型
        $queryType = $this->classifyQuery($query);
        
        $this->observeHistogram('db_query_duration_seconds', $seconds, [
            'query_type' => $queryType,
        ]);
    }
    
    // ==================== 仪表盘 ====================
    
    /**
     * 设置活跃规则数
     */
    public function setActiveRulesCount(int $count, string $ruleType): void
    {
        $this->setGauge('active_rules_count', $count, [
            'rule_type' => $ruleType,
        ]);
    }
    
    /**
     * 设置域名安全分数
     */
    public function setDomainSafetyScore(float $score, string $domain): void
    {
        $this->setGauge('domain_safety_score', $score, [
            'domain' => $domain,
        ]);
    }
    
    /**
     * 设置缓存命中率
     */
    public function setCacheHitRate(float $rate, string $level): void
    {
        $this->setGauge('cache_hit_rate', $rate, [
            'cache_level' => $level,
        ]);
    }
    
    // ==================== 核心方法 ====================
    
    private function incrementCounter(string $name, array $labels = [], int $value = 1): void
    {
        $key = $this->buildKey($name, $labels);
        
        if ($this->redis) {
            $this->redis->incrBy($key, $value);
        } else {
            if (!isset($this->metrics[$key])) {
                $this->metrics[$key] = 0;
            }
            $this->metrics[$key] += $value;
        }
    }
    
    private function observeHistogram(string $name, float $value, array $labels = []): void
    {
        // 记录 sum 和 count
        $sumKey = $this->buildKey($name . '_sum', $labels);
        $countKey = $this->buildKey($name . '_count', $labels);
        
        if ($this->redis) {
            $this->redis->incrByFloat($sumKey, $value);
            $this->redis->incr($countKey);
            
            // 记录每个 bucket
            foreach ($this->histogramBuckets as $bucket) {
                if ($value <= $bucket) {
                    $bucketKey = $this->buildKey($name . '_bucket', array_merge($labels, ['le' => (string)$bucket]));
                    $this->redis->incr($bucketKey);
                }
            }
            // +Inf bucket
            $infKey = $this->buildKey($name . '_bucket', array_merge($labels, ['le' => '+Inf']));
            $this->redis->incr($infKey);
        } else {
            // 本地存储
            if (!isset($this->metrics[$sumKey])) {
                $this->metrics[$sumKey] = 0;
            }
            if (!isset($this->metrics[$countKey])) {
                $this->metrics[$countKey] = 0;
            }
            $this->metrics[$sumKey] += $value;
            $this->metrics[$countKey]++;
        }
    }
    
    private function setGauge(string $name, float $value, array $labels = []): void
    {
        $key = $this->buildKey($name, $labels);
        
        if ($this->redis) {
            $this->redis->set($key, $value);
        } else {
            $this->metrics[$key] = $value;
        }
    }
    
    private function buildKey(string $name, array $labels = []): string
    {
        $fullName = $this->prefix . $name;
        
        if (empty($labels)) {
            return $fullName;
        }
        
        ksort($labels);
        $labelParts = [];
        foreach ($labels as $k => $v) {
            $labelParts[] = $k . '="' . addslashes($v) . '"';
        }
        
        return $fullName . '{' . implode(',', $labelParts) . '}';
    }
    
    private function classifyQuery(string $query): string
    {
        $query = strtoupper(trim($query));
        
        if (str_starts_with($query, 'SELECT')) {
            return 'select';
        } elseif (str_starts_with($query, 'INSERT')) {
            return 'insert';
        } elseif (str_starts_with($query, 'UPDATE')) {
            return 'update';
        } elseif (str_starts_with($query, 'DELETE')) {
            return 'delete';
        }
        
        return 'other';
    }
    
    // ==================== 输出 ====================
    
    /**
     * 生成 Prometheus 格式输出
     */
    public function render(): string
    {
        $output = [];
        
        // 获取所有指标
        $allMetrics = $this->getAllMetrics();
        
        // 添加元数据注释
        $output[] = "# HELP {$this->prefix}jump_requests_total Total number of jump requests";
        $output[] = "# TYPE {$this->prefix}jump_requests_total counter";
        
        $output[] = "# HELP {$this->prefix}jump_latency_seconds Jump request latency in seconds";
        $output[] = "# TYPE {$this->prefix}jump_latency_seconds histogram";
        
        $output[] = "# HELP {$this->prefix}antibot_blocks_total Total antibot blocks";
        $output[] = "# TYPE {$this->prefix}antibot_blocks_total counter";
        
        $output[] = "# HELP {$this->prefix}cache_operations_total Cache operations";
        $output[] = "# TYPE {$this->prefix}cache_operations_total counter";
        
        $output[] = "# HELP {$this->prefix}api_response_time_seconds API response time";
        $output[] = "# TYPE {$this->prefix}api_response_time_seconds histogram";
        
        $output[] = "# HELP {$this->prefix}active_rules_count Number of active rules";
        $output[] = "# TYPE {$this->prefix}active_rules_count gauge";
        
        $output[] = "# HELP {$this->prefix}cache_hit_rate Cache hit rate";
        $output[] = "# TYPE {$this->prefix}cache_hit_rate gauge";
        
        // 输出指标值
        foreach ($allMetrics as $key => $value) {
            $output[] = "$key $value";
        }
        
        return implode("\n", $output) . "\n";
    }
    
    private function getAllMetrics(): array
    {
        if ($this->redis) {
            $keys = $this->redis->keys('prometheus:' . $this->prefix . '*');
            $metrics = [];
            
            foreach ($keys as $key) {
                // 移除前缀
                $cleanKey = str_replace('prometheus:', '', $key);
                $value = $this->redis->get($key);
                $metrics[$cleanKey] = $value;
            }
            
            return $metrics;
        }
        
        return $this->metrics;
    }
    
    /**
     * 收集系统级指标
     */
    public function collectSystemMetrics(): void
    {
        // PHP 进程内存
        $this->setGauge('php_memory_usage_bytes', memory_get_usage(true), []);
        $this->setGauge('php_memory_peak_bytes', memory_get_peak_usage(true), []);
        
        // OPcache 状态
        if (function_exists('opcache_get_status')) {
            $status = opcache_get_status(false);
            if ($status) {
                $this->setGauge('opcache_used_memory_bytes', $status['memory_usage']['used_memory'] ?? 0, []);
                $this->setGauge('opcache_hit_rate', $status['opcache_statistics']['opcache_hit_rate'] ?? 0, []);
            }
        }
        
        // APCu 状态
        if (function_exists('apcu_cache_info')) {
            $info = @apcu_cache_info(true);
            if ($info) {
                $this->setGauge('apcu_entries', $info['num_entries'] ?? 0, []);
                $this->setGauge('apcu_memory_used_bytes', $info['mem_size'] ?? 0, []);
            }
        }
    }
}

// 便捷函数
function prometheus(): PrometheusEnhanced
{
    return PrometheusEnhanced::getInstance();
}
