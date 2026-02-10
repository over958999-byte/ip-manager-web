<?php
/**
 * 轻量级消息队列
 * 用于异步处理任务，如访问日志记录
 */

class MessageQueue {
    private static $instance = null;
    
    // 队列存储目录
    private $queueDir;
    
    // 内存队列（用于批量写入）
    private $memoryQueue = [];
    
    // 配置
    private $config = [
        'batch_size' => 100,            // 批量处理大小
        'flush_interval' => 5,          // 刷新间隔（秒）
        'max_retries' => 3,             // 最大重试次数
        'retry_delay' => 1000,          // 重试延迟（毫秒）
    ];
    
    private $lastFlushTime = 0;
    
    private function __construct() {
        $this->queueDir = sys_get_temp_dir() . '/ip_manager_queue';
        if (!is_dir($this->queueDir)) {
            @mkdir($this->queueDir, 0755, true);
        }
        
        $this->lastFlushTime = time();
        
        // 注册关闭时刷新
        register_shutdown_function([$this, 'flush']);
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 发送消息到队列
     */
    public function push(string $queue, array $data, int $priority = 0): bool {
        $message = [
            'id' => uniqid('msg_', true),
            'queue' => $queue,
            'data' => $data,
            'priority' => $priority,
            'created_at' => microtime(true),
            'retries' => 0,
        ];
        
        // 添加到内存队列
        if (!isset($this->memoryQueue[$queue])) {
            $this->memoryQueue[$queue] = [];
        }
        $this->memoryQueue[$queue][] = $message;
        
        // 检查是否需要刷新
        $totalCount = array_sum(array_map('count', $this->memoryQueue));
        if ($totalCount >= $this->config['batch_size'] || 
            time() - $this->lastFlushTime >= $this->config['flush_interval']) {
            $this->flush();
        }
        
        return true;
    }
    
    /**
     * 批量发送消息
     */
    public function pushBatch(string $queue, array $items): int {
        $count = 0;
        foreach ($items as $data) {
            if ($this->push($queue, $data)) {
                $count++;
            }
        }
        return $count;
    }
    
    /**
     * 从队列获取消息
     */
    public function pop(string $queue, int $count = 1): array {
        $this->flush(); // 先刷新内存队列
        
        $queueFile = $this->queueDir . '/' . md5($queue) . '.queue';
        if (!file_exists($queueFile)) {
            return [];
        }
        
        // 获取锁
        $lockFile = $queueFile . '.lock';
        $fp = fopen($lockFile, 'c');
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return [];
        }
        
        try {
            $messages = [];
            $remaining = [];
            
            $handle = fopen($queueFile, 'r');
            if ($handle) {
                while (($line = fgets($handle)) !== false && count($messages) < $count) {
                    $msg = json_decode(trim($line), true);
                    if ($msg) {
                        $messages[] = $msg;
                    }
                }
                // 读取剩余
                while (($line = fgets($handle)) !== false) {
                    $remaining[] = $line;
                }
                fclose($handle);
            }
            
            // 写回剩余
            file_put_contents($queueFile, implode('', $remaining), LOCK_EX);
            
            return $messages;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
    
    /**
     * 查看队列（不移除）
     */
    public function peek(string $queue, int $count = 10): array {
        $queueFile = $this->queueDir . '/' . md5($queue) . '.queue';
        if (!file_exists($queueFile)) {
            return [];
        }
        
        $messages = [];
        $handle = fopen($queueFile, 'r');
        if ($handle) {
            while (($line = fgets($handle)) !== false && count($messages) < $count) {
                $msg = json_decode(trim($line), true);
                if ($msg) {
                    $messages[] = $msg;
                }
            }
            fclose($handle);
        }
        
        return $messages;
    }
    
    /**
     * 获取队列长度
     */
    public function size(string $queue): int {
        $memoryCount = isset($this->memoryQueue[$queue]) ? count($this->memoryQueue[$queue]) : 0;
        
        $queueFile = $this->queueDir . '/' . md5($queue) . '.queue';
        $fileCount = 0;
        if (file_exists($queueFile)) {
            $fileCount = count(file($queueFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        }
        
        return $memoryCount + $fileCount;
    }
    
    /**
     * 刷新内存队列到文件
     */
    public function flush(): void {
        foreach ($this->memoryQueue as $queue => $messages) {
            if (empty($messages)) {
                continue;
            }
            
            $queueFile = $this->queueDir . '/' . md5($queue) . '.queue';
            
            // 按优先级排序
            usort($messages, fn($a, $b) => $b['priority'] - $a['priority']);
            
            // 追加写入
            $lines = array_map(fn($m) => json_encode($m) . "\n", $messages);
            file_put_contents($queueFile, implode('', $lines), FILE_APPEND | LOCK_EX);
        }
        
        $this->memoryQueue = [];
        $this->lastFlushTime = time();
    }
    
    /**
     * 清空队列
     */
    public function clear(string $queue): bool {
        unset($this->memoryQueue[$queue]);
        
        $queueFile = $this->queueDir . '/' . md5($queue) . '.queue';
        if (file_exists($queueFile)) {
            return unlink($queueFile);
        }
        return true;
    }
    
    /**
     * 处理队列消息
     */
    public function process(string $queue, callable $handler, int $batchSize = 10): int {
        $processed = 0;
        
        while (true) {
            $messages = $this->pop($queue, $batchSize);
            if (empty($messages)) {
                break;
            }
            
            foreach ($messages as $message) {
                try {
                    $handler($message['data'], $message);
                    $processed++;
                } catch (Exception $e) {
                    // 重试逻辑
                    if ($message['retries'] < $this->config['max_retries']) {
                        $message['retries']++;
                        $message['last_error'] = $e->getMessage();
                        $this->pushRetry($queue, $message);
                    } else {
                        // 移到死信队列
                        $this->pushDead($queue, $message, $e->getMessage());
                    }
                }
            }
        }
        
        return $processed;
    }
    
    /**
     * 重试消息
     */
    private function pushRetry(string $queue, array $message): void {
        usleep($this->config['retry_delay'] * 1000);
        
        $queueFile = $this->queueDir . '/' . md5($queue) . '.queue';
        file_put_contents($queueFile, json_encode($message) . "\n", FILE_APPEND | LOCK_EX);
    }
    
    /**
     * 死信队列
     */
    private function pushDead(string $queue, array $message, string $error): void {
        $deadFile = $this->queueDir . '/' . md5($queue) . '.dead';
        $message['error'] = $error;
        $message['dead_at'] = microtime(true);
        file_put_contents($deadFile, json_encode($message) . "\n", FILE_APPEND | LOCK_EX);
    }
    
    /**
     * 获取死信队列
     */
    public function getDeadMessages(string $queue, int $count = 100): array {
        $deadFile = $this->queueDir . '/' . md5($queue) . '.dead';
        if (!file_exists($deadFile)) {
            return [];
        }
        
        $messages = [];
        $lines = array_slice(file($deadFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -$count);
        foreach ($lines as $line) {
            $msg = json_decode($line, true);
            if ($msg) {
                $messages[] = $msg;
            }
        }
        
        return $messages;
    }
    
    /**
     * 获取所有队列信息
     */
    public function getQueueStats(): array {
        $stats = [];
        $files = glob($this->queueDir . '/*.queue');
        
        foreach ($files as $file) {
            $name = basename($file, '.queue');
            $stats[$name] = [
                'size' => count(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)),
                'file_size' => filesize($file),
            ];
        }
        
        return $stats;
    }
}
