<?php
/**
 * GeoIP 服务
 * 支持多源查询、失败重试、本地缓存
 */

class GeoIpService {
    private static $instance = null;
    private $db;
    private $cache;
    
    // API 提供商列表（按优先级排序）
    private $providers = [
        'ip-api' => [
            'url' => 'http://ip-api.com/json/{ip}?fields=status,countryCode,country,city,isp',
            'timeout' => 2,
            'parse' => 'parseIpApi',
        ],
        'ipinfo' => [
            'url' => 'https://ipinfo.io/{ip}/json',
            'timeout' => 2,
            'parse' => 'parseIpInfo',
        ],
        'ipapi-co' => [
            'url' => 'https://ipapi.co/{ip}/json/',
            'timeout' => 2,
            'parse' => 'parseIpApiCo',
        ],
    ];
    
    // 本地 IP 列表
    private $localIps = ['127.0.0.1', '::1', 'localhost'];
    
    // 私有 IP 前缀
    private $privateIpPrefixes = ['192.168.', '10.', '172.16.', '172.17.', '172.18.', '172.19.', 
                                   '172.20.', '172.21.', '172.22.', '172.23.', '172.24.', '172.25.',
                                   '172.26.', '172.27.', '172.28.', '172.29.', '172.30.', '172.31.'];
    
    // 配置
    private $config = [
        'cache_ttl' => 86400,       // 缓存1天
        'max_retries' => 2,         // 最大重试次数
        'retry_delay' => 100000,    // 重试延迟（微秒）
        'async_queue' => true,      // 是否使用异步队列
    ];
    
    private function __construct() {
        $this->db = Database::getInstance();
        
        // 尝试加载缓存服务
        if (class_exists('CacheService')) {
            $this->cache = CacheService::getInstance();
        }
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 获取 IP 地理信息
     */
    public function lookup(string $ip): array {
        // 检查本地/私有 IP
        if ($this->isLocalIp($ip)) {
            return [
                'country_code' => 'LOCAL',
                'country_name' => '本地',
                'city' => '',
                'isp' => '',
                'source' => 'local',
            ];
        }
        
        // 从数据库缓存获取
        $cached = $this->getFromCache($ip);
        if ($cached) {
            return $cached;
        }
        
        // 从 API 获取
        $result = $this->fetchFromApi($ip);
        
        // 保存到缓存
        if ($result && $result['country_code'] !== 'UNKNOWN') {
            $this->saveToCache($ip, $result);
        }
        
        return $result;
    }
    
    /**
     * 获取国家代码
     */
    public function getCountryCode(string $ip): string {
        $info = $this->lookup($ip);
        return $info['country_code'] ?? 'UNKNOWN';
    }
    
    /**
     * 获取国家名称
     */
    public function getCountryName(string $ip): string {
        $info = $this->lookup($ip);
        return $info['country_name'] ?? '未知';
    }
    
    /**
     * 批量查询（异步友好）
     */
    public function batchLookup(array $ips): array {
        $results = [];
        $uncached = [];
        
        // 先从缓存批量获取
        foreach ($ips as $ip) {
            if ($this->isLocalIp($ip)) {
                $results[$ip] = [
                    'country_code' => 'LOCAL',
                    'country_name' => '本地',
                ];
            } else {
                $cached = $this->getFromCache($ip);
                if ($cached) {
                    $results[$ip] = $cached;
                } else {
                    $uncached[] = $ip;
                }
            }
        }
        
        // 未缓存的逐个查询（注意 API 限流）
        foreach ($uncached as $ip) {
            $results[$ip] = $this->lookup($ip);
            usleep(100000); // 100ms 间隔，避免触发限流
        }
        
        return $results;
    }
    
    /**
     * 异步查询（放入队列）
     */
    public function lookupAsync(string $ip, callable $callback = null): void {
        if ($this->config['async_queue'] && class_exists('MessageQueue')) {
            $queue = MessageQueue::getInstance();
            $queue->push('geoip_lookup', [
                'ip' => $ip,
                'callback' => $callback,
            ]);
        } else {
            // 降级为同步
            $result = $this->lookup($ip);
            if ($callback) {
                $callback($result);
            }
        }
    }
    
    /**
     * 从 API 获取（带重试）
     */
    private function fetchFromApi(string $ip): array {
        $lastError = null;
        
        foreach ($this->providers as $name => $provider) {
            for ($retry = 0; $retry <= $this->config['max_retries']; $retry++) {
                try {
                    $result = $this->callApi($ip, $provider);
                    if ($result && !empty($result['country_code']) && $result['country_code'] !== 'UNKNOWN') {
                        $result['source'] = $name;
                        return $result;
                    }
                } catch (Exception $e) {
                    $lastError = $e->getMessage();
                    if ($retry < $this->config['max_retries']) {
                        usleep($this->config['retry_delay']);
                    }
                }
            }
        }
        
        // 所有提供商都失败
        if (class_exists('Logger')) {
            Logger::getInstance()->warning('GeoIP查询失败', [
                'ip' => $ip,
                'error' => $lastError,
            ]);
        }
        
        return [
            'country_code' => 'UNKNOWN',
            'country_name' => '未知',
            'city' => '',
            'isp' => '',
            'source' => 'none',
            'error' => $lastError,
        ];
    }
    
    /**
     * 调用 API
     */
    private function callApi(string $ip, array $provider): ?array {
        $url = str_replace('{ip}', $ip, $provider['url']);
        
        $context = stream_context_create([
            'http' => [
                'timeout' => $provider['timeout'],
                'ignore_errors' => true,
                'header' => "User-Agent: IPManager/1.0\r\n",
            ],
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            throw new Exception("API请求失败: {$url}");
        }
        
        $data = json_decode($response, true);
        if (!$data) {
            throw new Exception("API响应解析失败");
        }
        
        // 调用对应的解析方法
        $parseMethod = $provider['parse'];
        return $this->$parseMethod($data);
    }
    
    // ==================== API 响应解析 ====================
    
    private function parseIpApi(array $data): ?array {
        if (($data['status'] ?? '') !== 'success') {
            return null;
        }
        return [
            'country_code' => $data['countryCode'] ?? 'UNKNOWN',
            'country_name' => $data['country'] ?? '未知',
            'city' => $data['city'] ?? '',
            'isp' => $data['isp'] ?? '',
        ];
    }
    
    private function parseIpInfo(array $data): ?array {
        if (empty($data['country'])) {
            return null;
        }
        return [
            'country_code' => $data['country'] ?? 'UNKNOWN',
            'country_name' => $this->countryCodeToName($data['country'] ?? ''),
            'city' => $data['city'] ?? '',
            'isp' => $data['org'] ?? '',
        ];
    }
    
    private function parseIpApiCo(array $data): ?array {
        if (!empty($data['error'])) {
            return null;
        }
        return [
            'country_code' => $data['country_code'] ?? 'UNKNOWN',
            'country_name' => $data['country_name'] ?? '未知',
            'city' => $data['city'] ?? '',
            'isp' => $data['org'] ?? '',
        ];
    }
    
    // ==================== 缓存操作 ====================
    
    private function getFromCache(string $ip): ?array {
        // 优先从内存缓存获取
        if ($this->cache) {
            $key = "geoip:{$ip}";
            $cached = $this->cache->get($key, fn() => null, 1);
            if ($cached) {
                return $cached;
            }
        }
        
        // 从数据库缓存获取
        $stmt = $this->db->getPdo()->prepare(
            "SELECT country_code, country_name FROM ip_country_cache WHERE ip = ? AND updated_at > DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        $stmt->execute([$ip]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            return [
                'country_code' => $row['country_code'],
                'country_name' => $row['country_name'],
                'source' => 'db_cache',
            ];
        }
        
        return null;
    }
    
    private function saveToCache(string $ip, array $data): void {
        // 保存到内存缓存
        if ($this->cache) {
            $key = "geoip:{$ip}";
            $this->cache->set($key, $data, $this->config['cache_ttl']);
        }
        
        // 保存到数据库
        $stmt = $this->db->getPdo()->prepare(
            "INSERT INTO ip_country_cache (ip, country_code, country_name) VALUES (?, ?, ?) 
             ON DUPLICATE KEY UPDATE country_code = VALUES(country_code), country_name = VALUES(country_name)"
        );
        $stmt->execute([$ip, $data['country_code'], $data['country_name']]);
    }
    
    // ==================== 工具方法 ====================
    
    private function isLocalIp(string $ip): bool {
        if (in_array($ip, $this->localIps)) {
            return true;
        }
        foreach ($this->privateIpPrefixes as $prefix) {
            if (strpos($ip, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }
    
    private function countryCodeToName(string $code): string {
        static $countries = [
            'CN' => '中国', 'US' => '美国', 'JP' => '日本', 'KR' => '韩国',
            'HK' => '香港', 'TW' => '台湾', 'SG' => '新加坡', 'MY' => '马来西亚',
            'TH' => '泰国', 'VN' => '越南', 'ID' => '印度尼西亚', 'PH' => '菲律宾',
            'IN' => '印度', 'AU' => '澳大利亚', 'NZ' => '新西兰', 'GB' => '英国',
            'DE' => '德国', 'FR' => '法国', 'IT' => '意大利', 'ES' => '西班牙',
            'NL' => '荷兰', 'RU' => '俄罗斯', 'CA' => '加拿大', 'BR' => '巴西',
        ];
        return $countries[$code] ?? $code;
    }
}
