<?php
/**
 * Prometheus 监控指标导出
 * 提供标准的 /metrics 端点
 */

class PrometheusMetrics {
    private static $instance = null;
    private $db;
    private $redis;
    
    // 指标存储
    private $metrics = [];
    
    // 指标类型
    const TYPE_COUNTER = 'counter';
    const TYPE_GAUGE = 'gauge';
    const TYPE_HISTOGRAM = 'histogram';
    const TYPE_SUMMARY = 'summary';
    
    // 预定义桶（用于响应时间直方图）
    const DEFAULT_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10];
    
    private function __construct() {
        if (class_exists('Database')) {
            $this->db = Database::getInstance();
        }
        if (class_exists('RedisCache')) {
            $this->redis = RedisCache::getInstance();
        }
        
        $this->registerDefaultMetrics();
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 注册默认指标
     */
    private function registerDefaultMetrics(): void {
        // 系统指标
        $this->registerGauge('ip_manager_up', '服务是否运行', []);
        $this->registerGauge('ip_manager_info', '服务信息', ['version', 'php_version']);
        
        // HTTP 请求指标
        $this->registerCounter('ip_manager_http_requests_total', 'HTTP 请求总数', ['method', 'endpoint', 'status']);
        $this->registerHistogram('ip_manager_http_request_duration_seconds', 'HTTP 请求耗时', ['method', 'endpoint']);
        
        // 业务指标
        $this->registerGauge('ip_manager_ip_pool_total', 'IP 池中的 IP 总数', ['status']);
        $this->registerGauge('ip_manager_domains_total', '域名总数', ['status']);
        $this->registerGauge('ip_manager_jump_rules_total', '跳转规则总数', ['status']);
        $this->registerCounter('ip_manager_jumps_total', '跳转请求总数', ['rule_id', 'device_type']);
        
        // 缓存指标
        $this->registerCounter('ip_manager_cache_hits_total', '缓存命中数', ['cache_type']);
        $this->registerCounter('ip_manager_cache_misses_total', '缓存未命中数', ['cache_type']);
        
        // 数据库指标
        $this->registerGauge('ip_manager_db_connections', '数据库连接数', ['state']);
        $this->registerHistogram('ip_manager_db_query_duration_seconds', '数据库查询耗时', ['query_type']);
        
        // 安全指标
        $this->registerCounter('ip_manager_login_attempts_total', '登录尝试总数', ['status']);
        $this->registerGauge('ip_manager_blocked_ips', '被封锁的 IP 数', []);
        $this->registerCounter('ip_manager_antibot_challenges_total', '反爬验证次数', ['result']);
    }
    
    /**
     * 注册 Counter 指标
     */
    public function registerCounter(string $name, string $help, array $labels): void {
        $this->metrics[$name] = [
            'type' => self::TYPE_COUNTER,
            'help' => $help,
            'labels' => $labels,
            'values' => []
        ];
    }
    
    /**
     * 注册 Gauge 指标
     */
    public function registerGauge(string $name, string $help, array $labels): void {
        $this->metrics[$name] = [
            'type' => self::TYPE_GAUGE,
            'help' => $help,
            'labels' => $labels,
            'values' => []
        ];
    }
    
    /**
     * 注册 Histogram 指标
     */
    public function registerHistogram(string $name, string $help, array $labels, ?array $buckets = null): void {
        $this->metrics[$name] = [
            'type' => self::TYPE_HISTOGRAM,
            'help' => $help,
            'labels' => $labels,
            'buckets' => $buckets ?? self::DEFAULT_BUCKETS,
            'values' => []
        ];
    }
    
    /**
     * Counter 增加
     */
    public function incCounter(string $name, array $labelValues = [], float $value = 1): void {
        $key = $this->buildKey($name, $labelValues);
        
        if ($this->redis) {
            $this->redis->incr("metrics:{$key}", $value);
        } else {
            if (!isset($this->metrics[$name]['values'][$key])) {
                $this->metrics[$name]['values'][$key] = ['labels' => $labelValues, 'value' => 0];
            }
            $this->metrics[$name]['values'][$key]['value'] += $value;
        }
    }
    
    /**
     * Gauge 设置
     */
    public function setGauge(string $name, float $value, array $labelValues = []): void {
        $key = $this->buildKey($name, $labelValues);
        
        if ($this->redis) {
            $this->redis->set("metrics:{$key}", $value, 300);
        } else {
            $this->metrics[$name]['values'][$key] = ['labels' => $labelValues, 'value' => $value];
        }
    }
    
    /**
     * Gauge 增加
     */
    public function incGauge(string $name, float $value = 1, array $labelValues = []): void {
        $key = $this->buildKey($name, $labelValues);
        
        if ($this->redis) {
            $this->redis->incr("metrics:{$key}", $value);
        } else {
            if (!isset($this->metrics[$name]['values'][$key])) {
                $this->metrics[$name]['values'][$key] = ['labels' => $labelValues, 'value' => 0];
            }
            $this->metrics[$name]['values'][$key]['value'] += $value;
        }
    }
    
    /**
     * Histogram 观测
     */
    public function observeHistogram(string $name, float $value, array $labelValues = []): void {
        $key = $this->buildKey($name, $labelValues);
        $buckets = $this->metrics[$name]['buckets'] ?? self::DEFAULT_BUCKETS;
        
        if ($this->redis) {
            // 增加 sum 和 count
            $this->redis->incr("metrics:{$key}_sum", $value);
            $this->redis->incr("metrics:{$key}_count", 1);
            
            // 更新桶
            foreach ($buckets as $bucket) {
                if ($value <= $bucket) {
                    $this->redis->incr("metrics:{$key}_bucket_{$bucket}", 1);
                }
            }
            $this->redis->incr("metrics:{$key}_bucket_+Inf", 1);
        } else {
            if (!isset($this->metrics[$name]['values'][$key])) {
                $this->metrics[$name]['values'][$key] = [
                    'labels' => $labelValues,
                    'sum' => 0,
                    'count' => 0,
                    'buckets' => array_fill_keys($buckets, 0)
                ];
                $this->metrics[$name]['values'][$key]['buckets']['+Inf'] = 0;
            }
            
            $this->metrics[$name]['values'][$key]['sum'] += $value;
            $this->metrics[$name]['values'][$key]['count']++;
            
            foreach ($buckets as $bucket) {
                if ($value <= $bucket) {
                    $this->metrics[$name]['values'][$key]['buckets'][$bucket]++;
                }
            }
            $this->metrics[$name]['values'][$key]['buckets']['+Inf']++;
        }
    }
    
    /**
     * 记录请求（方便使用的包装方法）
     */
    public function recordRequest(string $method, string $endpoint, int $status, float $duration): void {
        $this->incCounter('ip_manager_http_requests_total', [$method, $endpoint, (string)$status]);
        $this->observeHistogram('ip_manager_http_request_duration_seconds', $duration, [$method, $endpoint]);
    }
    
    /**
     * 导出 Prometheus 格式
     */
    public function export(): string {
        $output = [];
        
        // 收集实时指标
        $this->collectLiveMetrics();
        
        foreach ($this->metrics as $name => $metric) {
            // 帮助信息
            $output[] = "# HELP {$name} {$metric['help']}";
            $output[] = "# TYPE {$name} {$metric['type']}";
            
            $values = $this->getMetricValues($name, $metric);
            
            switch ($metric['type']) {
                case self::TYPE_COUNTER:
                case self::TYPE_GAUGE:
                    foreach ($values as $v) {
                        $labelStr = $this->formatLabels($v['labels'], $metric['labels']);
                        $output[] = "{$name}{$labelStr} {$v['value']}";
                    }
                    break;
                    
                case self::TYPE_HISTOGRAM:
                    foreach ($values as $v) {
                        $labelStr = $this->formatLabels($v['labels'], $metric['labels']);
                        foreach ($v['buckets'] as $bucket => $count) {
                            $le = ($bucket === '+Inf') ? '+Inf' : $bucket;
                            if ($labelStr) {
                                $bucketLabel = str_replace('}', ",le=\"{$le}\"}", $labelStr);
                            } else {
                                $bucketLabel = "{le=\"{$le}\"}";
                            }
                            $output[] = "{$name}_bucket{$bucketLabel} {$count}";
                        }
                        $output[] = "{$name}_sum{$labelStr} {$v['sum']}";
                        $output[] = "{$name}_count{$labelStr} {$v['count']}";
                    }
                    break;
            }
            
            $output[] = '';
        }
        
        return implode("\n", $output);
    }
    
    /**
     * 收集实时指标
     */
    private function collectLiveMetrics(): void {
        // 服务状态
        $this->setGauge('ip_manager_up', 1);
        $this->setGauge('ip_manager_info', 1, [
            defined('APP_VERSION') ? APP_VERSION : '1.0.0',
            PHP_VERSION
        ]);
        
        if (!$this->db) return;
        
        try {
            $pdo = $this->db->getPdo();
            
            // IP 池统计 (简单表结构，只统计总数)
            $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM ip_pool");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $this->setGauge('ip_manager_ip_pool_total', (float)$row['cnt'], ['active']);
            }
            
            // 域名统计 (使用 jump_domains 表)
            $stmt = $pdo->query("SELECT safety_status, COUNT(*) as cnt FROM jump_domains GROUP BY safety_status");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $status = $row['safety_status'] ?? 'unknown';
                $this->setGauge('ip_manager_domains_total', (float)$row['cnt'], [$status]);
            }
            
            // 跳转规则统计
            $stmt = $pdo->query("SELECT enabled, COUNT(*) as cnt FROM jump_rules GROUP BY enabled");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $status = $row['enabled'] ? 'enabled' : 'disabled';
                $this->setGauge('ip_manager_jump_rules_total', (float)$row['cnt'], [$status]);
            }
            
            // 数据库连接数
            $stmt = $pdo->query("SHOW STATUS WHERE Variable_name IN ('Threads_connected', 'Threads_running')");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($row['Variable_name'] === 'Threads_connected') {
                    $this->setGauge('ip_manager_db_connections', (float)$row['Value'], ['connected']);
                } elseif ($row['Variable_name'] === 'Threads_running') {
                    $this->setGauge('ip_manager_db_connections', (float)$row['Value'], ['running']);
                }
            }
            
            // 登录尝试（今日）
            $stmt = $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE created_at >= CURDATE()");
            $this->setGauge('ip_manager_blocked_ips', (float)$stmt->fetchColumn());
            
        } catch (Exception $e) {
            // 忽略数据库错误
        }
    }
    
    /**
     * 获取指标值
     */
    private function getMetricValues(string $name, array $metric): array {
        if (!$this->redis) {
            return $metric['values'];
        }
        
        // 从 Redis 获取
        $values = [];
        $pattern = "metrics:{$name}:*";
        $keys = $this->redis->keys($pattern);
        
        foreach ($keys as $key) {
            $labelPart = str_replace("metrics:{$name}:", '', $key);
            $labelValues = explode(':', $labelPart);
            
            if ($metric['type'] === self::TYPE_HISTOGRAM) {
                // 直方图需要特殊处理
                $baseKey = "metrics:{$name}:{$labelPart}";
                $values[] = [
                    'labels' => $labelValues,
                    'sum' => (float)($this->redis->get("{$baseKey}_sum") ?? 0),
                    'count' => (float)($this->redis->get("{$baseKey}_count") ?? 0),
                    'buckets' => $this->getHistogramBuckets($baseKey, $metric['buckets'])
                ];
            } else {
                $values[] = [
                    'labels' => $labelValues,
                    'value' => (float)($this->redis->get($key) ?? 0)
                ];
            }
        }
        
        // 如果没有 Redis 数据，返回内存中的值
        if (empty($values)) {
            return $metric['values'];
        }
        
        return $values;
    }
    
    /**
     * 获取直方图桶值
     */
    private function getHistogramBuckets(string $baseKey, array $buckets): array {
        $result = [];
        foreach ($buckets as $bucket) {
            $result[$bucket] = (float)($this->redis->get("{$baseKey}_bucket_{$bucket}") ?? 0);
        }
        $result['+Inf'] = (float)($this->redis->get("{$baseKey}_bucket_+Inf") ?? 0);
        return $result;
    }
    
    /**
     * 构建键
     */
    private function buildKey(string $name, array $labelValues): string {
        if (empty($labelValues)) {
            return $name;
        }
        return $name . ':' . implode(':', $labelValues);
    }
    
    /**
     * 格式化标签
     */
    private function formatLabels(array $values, array $labels): string {
        if (empty($labels) || empty($values)) {
            return '';
        }
        
        $pairs = [];
        foreach ($labels as $i => $label) {
            $value = $values[$i] ?? '';
            $pairs[] = "{$label}=\"{$value}\"";
        }
        
        return '{' . implode(',', $pairs) . '}';
    }
    
    /**
     * 重置所有指标
     */
    public function reset(): void {
        foreach ($this->metrics as $name => &$metric) {
            $metric['values'] = [];
        }
        
        if ($this->redis) {
            $keys = $this->redis->keys('metrics:*');
            foreach ($keys as $key) {
                $this->redis->delete($key);
            }
        }
    }
}

/**
 * 请求计时器
 */
class RequestTimer {
    private $startTime;
    private $method;
    private $endpoint;
    
    public function __construct(string $method, string $endpoint) {
        $this->startTime = microtime(true);
        $this->method = $method;
        $this->endpoint = $endpoint;
    }
    
    public function finish(int $status): void {
        $duration = microtime(true) - $this->startTime;
        PrometheusMetrics::getInstance()->recordRequest(
            $this->method,
            $this->endpoint,
            $status,
            $duration
        );
    }
}
