<?php
/**
 * 请求熔断器
 * 当错误率超过阈值时自动熔断，防止雪崩
 */

class CircuitBreaker {
    private static $instance = null;
    
    // 熔断器状态
    const STATE_CLOSED = 'closed';      // 正常
    const STATE_OPEN = 'open';          // 熔断中
    const STATE_HALF_OPEN = 'half_open'; // 半开（尝试恢复）
    
    // 配置
    private $config = [
        'failure_threshold' => 5,       // 失败阈值（连续失败次数）
        'success_threshold' => 3,       // 成功阈值（半开状态下连续成功次数）
        'timeout' => 30,                // 熔断超时（秒）
        'failure_rate' => 50,           // 失败率阈值（百分比）
        'window_size' => 10,            // 统计窗口大小
    ];
    
    // 熔断器数据
    private $circuits = [];
    
    // 存储目录
    private $dataDir;
    
    private function __construct() {
        $this->dataDir = sys_get_temp_dir() . '/ip_manager_circuit';
        if (!is_dir($this->dataDir)) {
            @mkdir($this->dataDir, 0755, true);
        }
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 配置熔断器
     */
    public function configure(array $config): void {
        $this->config = array_merge($this->config, $config);
    }
    
    /**
     * 检查是否可以执行
     */
    public function canExecute(string $service): bool {
        $circuit = $this->getCircuit($service);
        
        switch ($circuit['state']) {
            case self::STATE_CLOSED:
                return true;
                
            case self::STATE_OPEN:
                // 检查是否超时，可以尝试半开
                if (time() - $circuit['last_failure_time'] >= $this->config['timeout']) {
                    $this->updateCircuit($service, ['state' => self::STATE_HALF_OPEN]);
                    return true;
                }
                return false;
                
            case self::STATE_HALF_OPEN:
                return true;
        }
        
        return true;
    }
    
    /**
     * 记录成功
     */
    public function recordSuccess(string $service): void {
        $circuit = $this->getCircuit($service);
        
        $circuit['success_count']++;
        $circuit['total_count']++;
        $circuit['consecutive_failures'] = 0;
        
        // 更新滑动窗口
        $circuit['history'][] = ['success' => true, 'time' => time()];
        $circuit['history'] = array_slice($circuit['history'], -$this->config['window_size']);
        
        // 半开状态下检查是否可以关闭
        if ($circuit['state'] === self::STATE_HALF_OPEN) {
            $circuit['half_open_successes']++;
            if ($circuit['half_open_successes'] >= $this->config['success_threshold']) {
                $circuit['state'] = self::STATE_CLOSED;
                $circuit['half_open_successes'] = 0;
            }
        }
        
        $this->saveCircuit($service, $circuit);
    }
    
    /**
     * 记录失败
     */
    public function recordFailure(string $service): void {
        $circuit = $this->getCircuit($service);
        
        $circuit['failure_count']++;
        $circuit['total_count']++;
        $circuit['consecutive_failures']++;
        $circuit['last_failure_time'] = time();
        
        // 更新滑动窗口
        $circuit['history'][] = ['success' => false, 'time' => time()];
        $circuit['history'] = array_slice($circuit['history'], -$this->config['window_size']);
        
        // 半开状态下立即熔断
        if ($circuit['state'] === self::STATE_HALF_OPEN) {
            $circuit['state'] = self::STATE_OPEN;
            $circuit['half_open_successes'] = 0;
        }
        // 检查是否需要熔断
        else if ($this->shouldTrip($circuit)) {
            $circuit['state'] = self::STATE_OPEN;
        }
        
        $this->saveCircuit($service, $circuit);
    }
    
    /**
     * 判断是否应该熔断
     */
    private function shouldTrip(array $circuit): bool {
        // 连续失败次数超过阈值
        if ($circuit['consecutive_failures'] >= $this->config['failure_threshold']) {
            return true;
        }
        
        // 失败率超过阈值
        if (count($circuit['history']) >= $this->config['window_size']) {
            $failures = count(array_filter($circuit['history'], fn($h) => !$h['success']));
            $failureRate = ($failures / count($circuit['history'])) * 100;
            if ($failureRate >= $this->config['failure_rate']) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 执行带熔断保护的操作
     */
    public function execute(string $service, callable $action, callable $fallback = null): mixed {
        if (!$this->canExecute($service)) {
            if ($fallback) {
                return $fallback();
            }
            throw new Exception("Circuit breaker is open for service: {$service}");
        }
        
        try {
            $result = $action();
            $this->recordSuccess($service);
            return $result;
        } catch (Exception $e) {
            $this->recordFailure($service);
            if ($fallback) {
                return $fallback();
            }
            throw $e;
        }
    }
    
    /**
     * 获取熔断器状态
     */
    public function getState(string $service): string {
        $circuit = $this->getCircuit($service);
        return $circuit['state'];
    }
    
    /**
     * 重置熔断器
     */
    public function reset(string $service): void {
        $this->saveCircuit($service, $this->defaultCircuit());
    }
    
    /**
     * 获取所有熔断器状态
     */
    public function getAllStates(): array {
        $states = [];
        foreach ($this->circuits as $service => $circuit) {
            $states[$service] = [
                'state' => $circuit['state'],
                'failure_count' => $circuit['failure_count'],
                'success_count' => $circuit['success_count'],
                'consecutive_failures' => $circuit['consecutive_failures'],
                'last_failure_time' => $circuit['last_failure_time'],
            ];
        }
        return $states;
    }
    
    // ==================== 存储 ====================
    
    private function defaultCircuit(): array {
        return [
            'state' => self::STATE_CLOSED,
            'failure_count' => 0,
            'success_count' => 0,
            'total_count' => 0,
            'consecutive_failures' => 0,
            'last_failure_time' => 0,
            'half_open_successes' => 0,
            'history' => [],
        ];
    }
    
    private function getCircuit(string $service): array {
        if (isset($this->circuits[$service])) {
            return $this->circuits[$service];
        }
        
        // 从文件加载
        $file = $this->dataDir . '/' . md5($service) . '.json';
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if ($data) {
                $this->circuits[$service] = $data;
                return $data;
            }
        }
        
        return $this->defaultCircuit();
    }
    
    private function updateCircuit(string $service, array $updates): void {
        $circuit = $this->getCircuit($service);
        $circuit = array_merge($circuit, $updates);
        $this->saveCircuit($service, $circuit);
    }
    
    private function saveCircuit(string $service, array $circuit): void {
        $this->circuits[$service] = $circuit;
        
        $file = $this->dataDir . '/' . md5($service) . '.json';
        file_put_contents($file, json_encode($circuit), LOCK_EX);
    }
}
