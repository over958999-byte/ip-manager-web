<?php
/**
 * 数据库读写分离支持
 * 主从架构，写操作走主库，读操作走从库
 * 增强：强一致性读策略
 */

class DatabaseCluster {
    private static $instance = null;
    
    private $master = null;  // 主库连接
    private $slaves = [];    // 从库连接池
    private $currentSlave = 0;
    
    // 配置
    private $config = [
        'master' => [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'ip_manager',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4'
        ],
        'slaves' => [],
        'sticky' => true,           // 写后读粘性（同一请求写后读走主库）
        'sticky_ttl' => 5,          // 粘性持续时间（秒）
        'strong_consistency' => false, // 全局强一致性读开关
    ];
    
    private $hasWritten = false;    // 本次请求是否有写操作
    private $writeTimestamp = 0;    // 最后写入时间戳
    
    // 强一致性读的表（这些表读取时强制走主库）
    private $strongConsistencyTables = [
        'users',
        'api_tokens',
        'settings',
    ];
    
    private function __construct() {
        $this->loadConfig();
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
        // 从环境变量加载主库配置
        if (getenv('DB_MASTER_HOST')) {
            $this->config['master']['host'] = getenv('DB_MASTER_HOST');
        }
        if (getenv('DB_MASTER_PORT')) {
            $this->config['master']['port'] = (int)getenv('DB_MASTER_PORT');
        }
        if (getenv('DB_MASTER_NAME')) {
            $this->config['master']['database'] = getenv('DB_MASTER_NAME');
        }
        if (getenv('DB_MASTER_USER')) {
            $this->config['master']['username'] = getenv('DB_MASTER_USER');
        }
        if (getenv('DB_MASTER_PASS')) {
            $this->config['master']['password'] = getenv('DB_MASTER_PASS');
        }
        
        // 强一致性配置
        if (getenv('DB_STRONG_CONSISTENCY')) {
            $this->config['strong_consistency'] = filter_var(getenv('DB_STRONG_CONSISTENCY'), FILTER_VALIDATE_BOOLEAN);
        }
        if (getenv('DB_STICKY_TTL')) {
            $this->config['sticky_ttl'] = (int)getenv('DB_STICKY_TTL');
        }
        
        // 从环境变量加载从库配置（支持多个从库）
        $slaveIndex = 1;
        while (getenv("DB_SLAVE{$slaveIndex}_HOST")) {
            $this->config['slaves'][] = [
                'host' => getenv("DB_SLAVE{$slaveIndex}_HOST"),
                'port' => (int)(getenv("DB_SLAVE{$slaveIndex}_PORT") ?: 3306),
                'database' => getenv("DB_SLAVE{$slaveIndex}_NAME") ?: $this->config['master']['database'],
                'username' => getenv("DB_SLAVE{$slaveIndex}_USER") ?: $this->config['master']['username'],
                'password' => getenv("DB_SLAVE{$slaveIndex}_PASS") ?: $this->config['master']['password'],
                'charset' => $this->config['master']['charset'],
                'weight' => (int)(getenv("DB_SLAVE{$slaveIndex}_WEIGHT") ?: 1)
            ];
            $slaveIndex++;
        }
        
        // 从数据库配置文件加载
        if (file_exists(__DIR__ . '/db_cluster_config.php')) {
            $clusterConfig = require __DIR__ . '/db_cluster_config.php';
            $this->config = array_merge($this->config, $clusterConfig);
        }
    }
    
    /**
     * 设置强一致性读模式
     */
    public function setStrongConsistency(bool $enabled): void {
        $this->config['strong_consistency'] = $enabled;
    }
    
    /**
     * 添加需要强一致性读的表
     */
    public function addStrongConsistencyTable(string $table): void {
        if (!in_array($table, $this->strongConsistencyTables)) {
            $this->strongConsistencyTables[] = $table;
        }
    }
    
    /**
     * 获取主库连接（写操作）
     */
    public function getMaster(): PDO {
        if ($this->master === null) {
            $this->master = $this->createConnection($this->config['master']);
        }
        $this->hasWritten = true;
        $this->writeTimestamp = microtime(true);
        return $this->master;
    }
    
    /**
     * 获取从库连接（读操作）
     * @param bool $forceStrong 强制使用强一致性读（走主库）
     */
    public function getSlave(bool $forceStrong = false): PDO {
        // 全局强一致性模式
        if ($this->config['strong_consistency'] || $forceStrong) {
            return $this->getMaster();
        }
        
        // 如果启用了粘性且本次请求有写操作（在TTL内）
        if ($this->config['sticky'] && $this->hasWritten) {
            $elapsed = microtime(true) - $this->writeTimestamp;
            if ($elapsed < $this->config['sticky_ttl']) {
                return $this->getMaster();
            }
        }
        
        // 如果没有从库配置，走主库
        if (empty($this->config['slaves'])) {
            return $this->getMaster();
        }
        
        // 加权轮询选择从库
        $slave = $this->selectSlave();
        $key = md5(json_encode($slave));
        
        if (!isset($this->slaves[$key])) {
            try {
                $this->slaves[$key] = $this->createConnection($slave);
            } catch (Exception $e) {
                // 从库连接失败，回退到主库
                if (class_exists('Logger')) {
                    Logger::getInstance()->warning('从库连接失败，回退到主库', ['error' => $e->getMessage()]);
                }
                return $this->getMaster();
            }
        }
        
        return $this->slaves[$key];
    }
    
    /**
     * 选择从库（加权轮询）
     */
    private function selectSlave(): array {
        $totalWeight = 0;
        foreach ($this->config['slaves'] as $slave) {
            $totalWeight += $slave['weight'] ?? 1;
        }
        
        $random = mt_rand(1, $totalWeight);
        $currentWeight = 0;
        
        foreach ($this->config['slaves'] as $slave) {
            $currentWeight += $slave['weight'] ?? 1;
            if ($random <= $currentWeight) {
                return $slave;
            }
        }
        
        return $this->config['slaves'][0];
    }
    
    /**
     * 创建数据库连接
     */
    private function createConnection(array $config): PDO {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'] ?? 3306,
            $config['database'],
            $config['charset'] ?? 'utf8mb4'
        );
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];
        
        return new PDO($dsn, $config['username'], $config['password'], $options);
    }
    
    /**
     * 执行写操作
     */
    public function write(string $sql, array $params = []): int {
        $pdo = $this->getMaster();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
    
    /**
     * 执行读操作
     * @param bool $forceStrong 强制使用强一致性读
     */
    public function read(string $sql, array $params = [], bool $forceStrong = false): array {
        // 检查SQL是否涉及强一致性表
        if (!$forceStrong) {
            $forceStrong = $this->isStrongConsistencyQuery($sql);
        }
        
        $pdo = $this->getSlave($forceStrong);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * 执行读操作（单条）
     * @param bool $forceStrong 强制使用强一致性读
     */
    public function readOne(string $sql, array $params = [], bool $forceStrong = false): ?array {
        // 检查SQL是否涉及强一致性表
        if (!$forceStrong) {
            $forceStrong = $this->isStrongConsistencyQuery($sql);
        }
        
        $pdo = $this->getSlave($forceStrong);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }
    
    /**
     * 执行读操作（单列）
     */
    public function readColumn(string $sql, array $params = []): mixed {
        $pdo = $this->getSlave();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
    
    /**
     * 检查SQL是否涉及需要强一致性读的表
     */
    private function isStrongConsistencyQuery(string $sql): bool {
        $sqlLower = strtolower($sql);
        foreach ($this->strongConsistencyTables as $table) {
            if (strpos($sqlLower, strtolower($table)) !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 插入并返回 ID
     */
    public function insert(string $sql, array $params = []): int {
        $pdo = $this->getMaster();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$pdo->lastInsertId();
    }
    
    /**
     * 开启事务
     */
    public function beginTransaction(): bool {
        return $this->getMaster()->beginTransaction();
    }
    
    /**
     * 提交事务
     */
    public function commit(): bool {
        return $this->getMaster()->commit();
    }
    
    /**
     * 回滚事务
     */
    public function rollback(): bool {
        return $this->getMaster()->rollBack();
    }
    
    /**
     * 强制走主库（用于需要立即读取写入数据的场景）
     */
    public function useMaster(): self {
        $this->hasWritten = true;
        return $this;
    }
    
    /**
     * 获取集群状态
     */
    public function getStatus(): array {
        $status = [
            'master' => $this->getConnectionStatus($this->config['master']),
            'slaves' => [],
            'has_written' => $this->hasWritten
        ];
        
        foreach ($this->config['slaves'] as $i => $slave) {
            $status['slaves'][$i] = $this->getConnectionStatus($slave);
        }
        
        return $status;
    }
    
    /**
     * 获取连接状态
     */
    private function getConnectionStatus(array $config): array {
        try {
            $pdo = $this->createConnection($config);
            $stmt = $pdo->query("SELECT 1");
            return [
                'host' => $config['host'],
                'port' => $config['port'] ?? 3306,
                'status' => 'online',
                'weight' => $config['weight'] ?? 1
            ];
        } catch (Exception $e) {
            return [
                'host' => $config['host'],
                'port' => $config['port'] ?? 3306,
                'status' => 'offline',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * 检查主从延迟
     */
    public function getReplicationLag(): ?int {
        if (empty($this->config['slaves'])) {
            return null;
        }
        
        try {
            $slave = $this->getSlave();
            $stmt = $slave->query("SHOW SLAVE STATUS");
            $status = $stmt->fetch();
            
            if ($status && isset($status['Seconds_Behind_Master'])) {
                return (int)$status['Seconds_Behind_Master'];
            }
        } catch (Exception $e) {
            // 忽略
        }
        
        return null;
    }
}
