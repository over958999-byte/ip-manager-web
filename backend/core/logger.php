<?php
/**
 * 结构化日志服务
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
    ];
    
    // 日志目录
    private $logDir;
    
    private function __construct() {
        $this->logDir = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }
        $this->config['log_file'] = $this->logDir . '/app.log';
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
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
        
        // 构建日志数据
        $logData = [
            'timestamp' => date('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'request_id' => $this->getRequestId(),
        ];
        
        // 添加请求信息
        if (!empty($_SERVER['REQUEST_URI'])) {
            $logData['request'] = [
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
                'uri' => $_SERVER['REQUEST_URI'] ?? '',
                'ip' => $this->getClientIp(),
            ];
        }
        
        // 写入日志
        $this->write($logData);
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
                "[%s] %s: %s %s\n",
                $data['timestamp'],
                $data['level'],
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
     * 获取请求ID
     */
    private function getRequestId(): string {
        static $requestId = null;
        if ($requestId === null) {
            $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? substr(md5(uniqid('', true)), 0, 12);
        }
        return $requestId;
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
            return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
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
    public static function logSecurityEvent(string $event, array $context = []): void {
        self::getInstance()->warning('安全事件: ' . $event, array_merge($context, [
            'ip' => self::getInstance()->getClientIp(),
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
