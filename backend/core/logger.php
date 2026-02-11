<?php
/**
 * 结构化日志服务
 * 增强：统一字段（trace_id、user_id、client_ip）、慢查询告警、错误率监控
 */

class Logger {
    private static $instance = null;
    
    // 日志级别
    const LEVEL_DEBUG = 'DEBUG';
    const LEVEL_INFO = 'INFO';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_ERROR = 'ERROR';
    const LEVEL_CRITICAL = 'CRITICAL';
    
    // 日志级别优先级
    private $levelPriority = [
        self::LEVEL_DEBUG => 0,
        self::LEVEL_INFO => 1,
        self::LEVEL_WARNING => 2,
        self::LEVEL_ERROR => 3,
        self::LEVEL_CRITICAL => 4,
    ];
    
    // 配置
    private $config = [
        'enabled' => true,
        'min_level' => self::LEVEL_INFO,
        'log_file' => null,
        'max_file_size' => 10485760,  // 10MB
        'max_files' => 5,
        'json_format' => true,
        'slow_query_threshold_ms' => 1000,  // 慢查询阈值
        'error_rate_window' => 60,          // 错误率窗口（秒）
        'error_rate_threshold' => 10,       // 错误率阈值（每分钟）
    ];
    
    // 日志目录
    private $logDir;
    
    // 请求上下文（统一字段）
    private $context = [
        'trace_id' => null,
        'user_id' => null,
        'client_ip' => null,
        'request_path' => null,
    ];
    
    // 错误计数（用于错误率监控）
    private $errorCount = 0;
    private $errorWindowStart = 0;
    
    private function __construct() {
        $this->logDir = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }
        $this->config['log_file'] = $this->logDir . '/app.log';
        
        // 初始化请求上下文
        $this->initContext();
    }
    
    /**
     * 初始化请求上下文
     */
    private function initContext(): void {
        // 生成或获取trace_id
        $this->context['trace_id'] = $_SERVER['HTTP_X_TRACE_ID'] 
            ?? $_SERVER['HTTP_X_REQUEST_ID'] 
            ?? $this->generateTraceId();
        
        // 获取客户端IP
        $this->context['client_ip'] = $this->getClientIp();
        
        // 获取请求路径
        $this->context['request_path'] = $_SERVER['REQUEST_URI'] ?? 'cli';
    }
    
    /**
     * 生成唯一trace_id
     */
    private function generateTraceId(): string {
        return sprintf('%s-%s', 
            substr(md5(uniqid('', true)), 0, 8),
            substr(md5(microtime(true)), 0, 4)
        );
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 设置用户ID（登录后调用）
     */
    public function setUserId(?string $userId): void {
        $this->context['user_id'] = $userId;
    }
    
    /**
     * 设置trace_id（用于分布式追踪）
     */
    public function setTraceId(string $traceId): void {
        $this->context['trace_id'] = $traceId;
    }
    
    /**
     * 获取当前trace_id
     */
    public function getTraceId(): string {
        return $this->context['trace_id'];
    }
    
    /**
     * 配置日志
     */
    public function configure(array $config): void {
        $this->config = array_merge($this->config, $config);
    }
    
    /**
     * 记录日志
     */
    public function log(string $level, string $message, array $context = []): void {
        if (!$this->config['enabled']) {
            return;
        }
        
        // 检查日志级别
        $currentPriority = $this->levelPriority[$level] ?? 1;
        $minPriority = $this->levelPriority[$this->config['min_level']] ?? 1;
        if ($currentPriority < $minPriority) {
            return;
        }
        
        // 构建日志数据（统一字段）
        $logData = [
            'timestamp' => date('c'),
            'level' => $level,
            'message' => $message,
            // 统一字段
            'trace_id' => $this->context['trace_id'],
            'user_id' => $this->context['user_id'],
            'client_ip' => $this->context['client_ip'],
            // 请求信息
            'request' => [
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
                'path' => $this->context['request_path'],
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
            ],
            // 自定义上下文
            'context' => $context,
        ];
        
        // 错误率监控
        if (in_array($level, [self::LEVEL_ERROR, self::LEVEL_CRITICAL])) {
            $this->trackErrorRate();
        }
        
        // 写入日志
        $this->write($logData);
    }
    
    /**
     * 记录慢查询
     */
    public function logSlowQuery(string $sql, float $durationMs, array $params = []): void {
        if ($durationMs < $this->config['slow_query_threshold_ms']) {
            return;
        }
        
        $this->log(self::LEVEL_WARNING, 'Slow query detected', [
            'sql' => $sql,
            'duration_ms' => round($durationMs, 2),
            'params' => $params,
            'threshold_ms' => $this->config['slow_query_threshold_ms'],
        ]);
    }
    
    /**
     * 记录API请求
     */
    public function logApiRequest(string $endpoint, float $durationMs, int $statusCode, array $extra = []): void {
        $level = $statusCode >= 500 ? self::LEVEL_ERROR : 
                ($statusCode >= 400 ? self::LEVEL_WARNING : self::LEVEL_INFO);
        
        $this->log($level, 'API request', array_merge([
            'endpoint' => $endpoint,
            'duration_ms' => round($durationMs, 2),
            'status_code' => $statusCode,
        ], $extra));
    }
    
    /**
     * 追踪错误率
     */
    private function trackErrorRate(): void {
        $now = time();
        
        // 重置窗口
        if ($now - $this->errorWindowStart > $this->config['error_rate_window']) {
            $this->errorCount = 0;
            $this->errorWindowStart = $now;
        }
        
        $this->errorCount++;
        
        // 检查是否超过阈值
        if ($this->errorCount >= $this->config['error_rate_threshold']) {
            // 触发告警（可以发送到Webhook等）
            $this->triggerErrorRateAlert();
        }
    }
    
    /**
     * 触发错误率告警
     */
    private function triggerErrorRateAlert(): void {
        // 避免重复告警
        static $lastAlert = 0;
        $now = time();
        if ($now - $lastAlert < 300) { // 5分钟内不重复告警
            return;
        }
        $lastAlert = $now;
        
        // 写入专门的告警日志
        $alertFile = $this->logDir . '/alerts.log';
        $alert = json_encode([
            'timestamp' => date('c'),
            'type' => 'error_rate_exceeded',
            'error_count' => $this->errorCount,
            'window_seconds' => $this->config['error_rate_window'],
            'threshold' => $this->config['error_rate_threshold'],
        ], JSON_UNESCAPED_UNICODE) . "\n";
        
        @file_put_contents($alertFile, $alert, FILE_APPEND | LOCK_EX);
        
        // 也发送到error_log
        error_log("ERROR RATE ALERT: {$this->errorCount} errors in {$this->config['error_rate_window']}s");
    }
    
    /**
     * 写入日志文件
     */
    private function write(array $data): void {
        $logFile = $this->config['log_file'];
        
        // 检查文件大小，轮转
        if (file_exists($logFile) && filesize($logFile) > $this->config['max_file_size']) {
            $this->rotateLog();
        }
        
        // 格式化
        if ($this->config['json_format']) {
            $line = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            $line = sprintf(
                "[%s] [%s] [%s] %s: %s %s\n",
                $data['timestamp'],
                $data['trace_id'],
                $data['level'],
                $data['client_ip'] ?? '-',
                $data['message'],
                !empty($data['context']) ? json_encode($data['context'], JSON_UNESCAPED_UNICODE) : ''
            );
        }
        
        // 写入
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
        
        // 同时写入 error_log（便于 Docker 日志收集）
        if (in_array($data['level'], [self::LEVEL_ERROR, self::LEVEL_CRITICAL])) {
            error_log($line);
        }
    }
    
    /**
     * 日志轮转
     */
    private function rotateLog(): void {
        $logFile = $this->config['log_file'];
        
        // 删除最旧的日志
        for ($i = $this->config['max_files'] - 1; $i >= 1; $i--) {
            $oldFile = "{$logFile}.{$i}";
            $newFile = "{$logFile}." . ($i + 1);
            if (file_exists($oldFile)) {
                if ($i == $this->config['max_files'] - 1) {
                    @unlink($oldFile);
                } else {
                    @rename($oldFile, $newFile);
                }
            }
        }
        
        // 重命名当前日志
        @rename($logFile, "{$logFile}.1");
    }
    
    /**
     * 获取请求ID（兼容旧方法）
     */
    private function getRequestId(): string {
        return $this->context['trace_id'];
    }
    
    /**
     * 获取客户端IP
     */
    private function getClientIp(): string {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    // ==================== 快捷方法 ====================
    
    public function debug(string $message, array $context = []): void {
        $this->log(self::LEVEL_DEBUG, $message, $context);
    }
    
    public function info(string $message, array $context = []): void {
        $this->log(self::LEVEL_INFO, $message, $context);
    }
    
    public function warning(string $message, array $context = []): void {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }
    
    public function error(string $message, array $context = []): void {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }
    
    public function critical(string $message, array $context = []): void {
        $this->log(self::LEVEL_CRITICAL, $message, $context);
    }
    
    // ==================== 静态快捷方法 ====================
    
    public static function logDebug(string $message, array $context = []): void {
        self::getInstance()->debug($message, $context);
    }
    
    public static function logInfo(string $message, array $context = []): void {
        self::getInstance()->info($message, $context);
    }
    
    public static function logWarning(string $message, array $context = []): void {
        self::getInstance()->warning($message, $context);
    }
    
    public static function logError(string $message, array $context = []): void {
        self::getInstance()->error($message, $context);
    }
    
    public static function logCritical(string $message, array $context = []): void {
        self::getInstance()->critical($message, $context);
    }
    
    // ==================== 特殊日志 ====================
    
    /**
     * 记录 API 调用日志
     */
    public function logApiCall(string $action, array $params, $result, float $duration): void {
        $this->info('API调用', [
            'action' => $action,
            'params' => $params,
            'success' => $result['success'] ?? false,
            'duration_ms' => round($duration * 1000, 2),
        ]);
    }
    
    /**
     * 记录安全事件
     */
    public function logSecurityEvent(string $event, array $context = []): void {
        $this->warning('安全事件: ' . $event, array_merge($context, [
            'ip' => $this->getClientIp(),
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]));
    }
    
    /**
     * 记录性能指标
     */
    public function logPerformance(string $operation, float $duration, array $context = []): void {
        $this->debug('性能指标', array_merge($context, [
            'operation' => $operation,
            'duration_ms' => round($duration * 1000, 2),
            'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ]));
    }
}
