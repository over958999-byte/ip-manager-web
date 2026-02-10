<?php
/**
 * 威胁情报同步脚本
 * 从多个公开威胁情报源同步恶意IP和爬虫IP
 * 
 * 使用: php sync_threat_intel.php [--force]
 * 建议: 每天运行一次 (0 3 * * * php /path/to/sync_threat_intel.php)
 */

require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../../public/ip_blacklist.php';

class ThreatIntelSync {
    private $pdo;
    private $ipBlacklist;
    private $forceUpdate = false;
    private $stats = [
        'total_fetched' => 0,
        'total_added' => 0,
        'total_updated' => 0,
        'total_failed' => 0,
        'sources' => []
    ];
    
    // 威胁情报源配置
    private $sources = [
        // ==================== 恶意IP源 ====================
        [
            'name' => 'Emerging Threats Compromised IPs',
            'url' => 'https://rules.emergingthreats.net/blockrules/compromised-ips.txt',
            'type' => 'malicious',
            'category' => 'Emerging Threats',
            'parser' => 'line_ips',
            'enabled' => true
        ],
        [
            'name' => 'Spamhaus DROP',
            'url' => 'https://www.spamhaus.org/drop/drop.txt',
            'type' => 'malicious',
            'category' => 'Spamhaus DROP',
            'parser' => 'spamhaus',
            'enabled' => true
        ],
        [
            'name' => 'Spamhaus EDROP',
            'url' => 'https://www.spamhaus.org/drop/edrop.txt',
            'type' => 'malicious',
            'category' => 'Spamhaus EDROP',
            'parser' => 'spamhaus',
            'enabled' => true
        ],
        [
            'name' => 'Blocklist.de All Attackers',
            'url' => 'https://lists.blocklist.de/lists/all.txt',
            'type' => 'malicious',
            'category' => 'Blocklist.de',
            'parser' => 'line_ips',
            'enabled' => true,
            'limit' => 10000  // 限制数量
        ],
        [
            'name' => 'AbuseIPDB Blacklist',
            'url' => 'https://raw.githubusercontent.com/borestad/blocklist-abuseipdb/main/abuseipdb-s100-14d.ipv4',
            'type' => 'malicious',
            'category' => 'AbuseIPDB',
            'parser' => 'line_ips',
            'enabled' => true
        ],
        [
            'name' => 'CI Army Bad IPs',
            'url' => 'https://cinsscore.com/list/ci-badguys.txt',
            'type' => 'malicious',
            'category' => 'CI Army',
            'parser' => 'line_ips',
            'enabled' => true
        ],
        [
            'name' => 'FeodoTracker Botnet C2',
            'url' => 'https://feodotracker.abuse.ch/downloads/ipblocklist.txt',
            'type' => 'malicious',
            'category' => 'FeodoTracker',
            'parser' => 'abuse_ch',
            'enabled' => true
        ],
        [
            'name' => 'SSL Blacklist',
            'url' => 'https://sslbl.abuse.ch/blacklist/sslipblacklist.txt',
            'type' => 'malicious',
            'category' => 'SSL Blacklist',
            'parser' => 'abuse_ch',
            'enabled' => true
        ],
        
        // ==================== 爬虫/扫描器IP源 ====================
        [
            'name' => 'Firehol Level 1 (Spammers)',
            'url' => 'https://raw.githubusercontent.com/firehol/blocklist-ipsets/master/firehol_level1.netset',
            'type' => 'malicious',
            'category' => 'Firehol Level1',
            'parser' => 'netset',
            'enabled' => true
        ],
        [
            'name' => 'Firehol Level 2 (Attacks)',
            'url' => 'https://raw.githubusercontent.com/firehol/blocklist-ipsets/master/firehol_level2.netset',
            'type' => 'malicious',
            'category' => 'Firehol Level2',
            'parser' => 'netset',
            'enabled' => true
        ],
        [
            'name' => 'Stamparm Malware IPs',
            'url' => 'https://raw.githubusercontent.com/stamparm/ipsum/master/levels/3.txt',
            'type' => 'malicious',
            'category' => 'Stamparm ipsum',
            'parser' => 'ipsum',
            'enabled' => true
        ],
        
        // ==================== 数据中心/代理IP源 ====================
        [
            'name' => 'DataCenter IPs',
            'url' => 'https://raw.githubusercontent.com/jhassine/server-ip-addresses/master/data/datacenters.txt',
            'type' => 'datacenter',
            'category' => 'DataCenter',
            'parser' => 'line_cidr',
            'enabled' => true
        ],
        [
            'name' => 'Public Proxies',
            'url' => 'https://raw.githubusercontent.com/clarketm/proxy-list/master/proxy-list-raw.txt',
            'type' => 'proxy',
            'category' => 'Public Proxy',
            'parser' => 'proxy_list',
            'enabled' => true
        ],
        
        // ==================== 爬虫UA相关IP ====================
        [
            'name' => 'Known Bot IPs',
            'url' => 'https://raw.githubusercontent.com/mitchellkrogza/nginx-ultimate-bad-bot-blocker/master/_generator_lists/bad-ip-addresses.list',
            'type' => 'bot',
            'category' => 'Bad Bot',
            'parser' => 'line_ips',
            'enabled' => true
        ],
        
        // ==================== Tor Exit Nodes ====================
        [
            'name' => 'Tor Exit Nodes',
            'url' => 'https://check.torproject.org/torbulkexitlist',
            'type' => 'proxy',
            'category' => 'Tor Exit',
            'parser' => 'line_ips',
            'enabled' => true
        ],
    ];
    
    public function __construct($forceUpdate = false) {
        $this->pdo = Database::getInstance()->getConnection();
        $this->ipBlacklist = IpBlacklist::getInstance();
        $this->forceUpdate = $forceUpdate;
    }
    
    /**
     * 运行同步
     */
    public function run(): array {
        $this->log("========== 威胁情报同步开始 ==========");
        $this->log("强制更新: " . ($this->forceUpdate ? '是' : '否'));
        
        foreach ($this->sources as $source) {
            if (!$source['enabled']) {
                $this->log("[跳过] {$source['name']} - 已禁用");
                continue;
            }
            
            try {
                $this->syncSource($source);
            } catch (Exception $e) {
                $this->log("[错误] {$source['name']}: " . $e->getMessage());
                $this->stats['sources'][$source['name']] = [
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }
            
            // 避免请求过快
            usleep(500000); // 0.5秒
        }
        
        // 刷新缓存
        $this->log("刷新缓存...");
        $this->ipBlacklist->refreshCache();
        
        $this->log("========== 同步完成 ==========");
        $this->log("总获取: {$this->stats['total_fetched']}");
        $this->log("总添加: {$this->stats['total_added']}");
        $this->log("总更新: {$this->stats['total_updated']}");
        $this->log("总失败: {$this->stats['total_failed']}");
        
        return $this->stats;
    }
    
    /**
     * 同步单个源
     */
    private function syncSource(array $source): void {
        $this->log("[同步] {$source['name']} ...");
        
        // 下载数据
        $content = $this->fetchUrl($source['url']);
        if (empty($content)) {
            throw new Exception("无法获取数据");
        }
        
        // 解析IP
        $ips = $this->parseContent($content, $source['parser']);
        
        // 限制数量
        if (isset($source['limit']) && count($ips) > $source['limit']) {
            $ips = array_slice($ips, 0, $source['limit']);
        }
        
        $this->stats['total_fetched'] += count($ips);
        
        // 批量导入
        $result = $this->importIps($ips, $source);
        
        $this->stats['sources'][$source['name']] = [
            'status' => 'success',
            'fetched' => count($ips),
            'added' => $result['added'],
            'updated' => $result['updated'],
            'failed' => $result['failed']
        ];
        
        $this->log("  获取: " . count($ips) . ", 添加: {$result['added']}, 更新: {$result['updated']}");
    }
    
    /**
     * 获取URL内容
     */
    private function fetchUrl(string $url): ?string {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'Mozilla/5.0 (compatible; ThreatIntelSync/1.0)',
                'follow_location' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        
        $content = @file_get_contents($url, false, $ctx);
        return $content ?: null;
    }
    
    /**
     * 解析内容
     */
    private function parseContent(string $content, string $parser): array {
        $ips = [];
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // 跳过空行和注释
            if (empty($line) || $line[0] === '#' || $line[0] === ';') {
                continue;
            }
            
            $ip = null;
            
            switch ($parser) {
                case 'line_ips':
                    // 每行一个IP
                    if (filter_var($line, FILTER_VALIDATE_IP)) {
                        $ip = $line;
                    }
                    break;
                    
                case 'line_cidr':
                    // 每行一个IP或CIDR
                    if ($this->isValidIpOrCidr($line)) {
                        $ip = $line;
                    }
                    break;
                    
                case 'spamhaus':
                    // Spamhaus格式: CIDR ; SBLxxxxxxx
                    if (preg_match('/^(\d+\.\d+\.\d+\.\d+(?:\/\d+)?)\s*;/', $line, $m)) {
                        $ip = $m[1];
                    }
                    break;
                    
                case 'abuse_ch':
                    // Abuse.ch格式: 跳过注释后每行IP
                    if (filter_var($line, FILTER_VALIDATE_IP)) {
                        $ip = $line;
                    }
                    break;
                    
                case 'netset':
                    // Firehol netset格式
                    if ($this->isValidIpOrCidr($line)) {
                        $ip = $line;
                    }
                    break;
                    
                case 'ipsum':
                    // ipsum格式: IP\tcount
                    $parts = explode("\t", $line);
                    if (isset($parts[0]) && filter_var($parts[0], FILTER_VALIDATE_IP)) {
                        $ip = $parts[0];
                    }
                    break;
                    
                case 'proxy_list':
                    // 代理列表格式: IP:PORT
                    $parts = explode(":", $line);
                    if (isset($parts[0]) && filter_var($parts[0], FILTER_VALIDATE_IP)) {
                        $ip = $parts[0];
                    }
                    break;
            }
            
            if ($ip && !in_array($ip, $ips)) {
                $ips[] = $ip;
            }
        }
        
        return $ips;
    }
    
    /**
     * 验证IP或CIDR格式
     */
    private function isValidIpOrCidr(string $input): bool {
        if (filter_var($input, FILTER_VALIDATE_IP)) {
            return true;
        }
        
        if (strpos($input, '/') !== false) {
            list($ip, $bits) = explode('/', $input);
            return filter_var($ip, FILTER_VALIDATE_IP) && $bits >= 0 && $bits <= 32;
        }
        
        return false;
    }
    
    /**
     * 批量导入IP
     */
    private function importIps(array $ips, array $source): array {
        $added = 0;
        $updated = 0;
        $failed = 0;
        
        // 使用事务批量处理
        $this->pdo->beginTransaction();
        
        try {
            $checkStmt = $this->pdo->prepare("SELECT id FROM ip_blacklist WHERE ip_cidr = ?");
            $insertStmt = $this->pdo->prepare("
                INSERT INTO ip_blacklist (ip_cidr, ip_start, ip_end, type, category, name, source)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $updateStmt = $this->pdo->prepare("
                UPDATE ip_blacklist SET updated_at = NOW() WHERE id = ?
            ");
            
            foreach ($ips as $ip) {
                try {
                    // 检查是否已存在
                    $checkStmt->execute([$ip]);
                    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        // 更新时间
                        if ($this->forceUpdate) {
                            $updateStmt->execute([$existing['id']]);
                            $updated++;
                        }
                    } else {
                        // 计算IP范围
                        list($ipStart, $ipEnd) = $this->cidrToRange($ip);
                        
                        $insertStmt->execute([
                            $ip,
                            $ipStart,
                            $ipEnd,
                            $source['type'],
                            $source['category'],
                            $source['name'],
                            $source['url']
                        ]);
                        $added++;
                    }
                } catch (Exception $e) {
                    $failed++;
                }
            }
            
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        
        $this->stats['total_added'] += $added;
        $this->stats['total_updated'] += $updated;
        $this->stats['total_failed'] += $failed;
        
        return ['added' => $added, 'updated' => $updated, 'failed' => $failed];
    }
    
    /**
     * CIDR转IP范围
     */
    private function cidrToRange(string $cidr): array {
        if (strpos($cidr, '/') === false) {
            $ipLong = ip2long($cidr);
            return [$ipLong, $ipLong];
        }
        
        list($ip, $bits) = explode('/', $cidr);
        $bits = intval($bits);
        $ipLong = ip2long($ip);
        
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
        $start = $ipLong & $mask;
        $end = $start | (~$mask & 0xFFFFFFFF);
        
        if ($start < 0) $start = sprintf('%u', $start);
        if ($end < 0) $end = sprintf('%u', $end);
        
        return [$start, $end];
    }
    
    /**
     * 日志输出
     */
    private function log(string $message): void {
        $time = date('Y-m-d H:i:s');
        echo "[{$time}] {$message}\n";
    }
}

// 命令行运行
if (php_sapi_name() === 'cli') {
    $forceUpdate = in_array('--force', $argv ?? []);
    
    $sync = new ThreatIntelSync($forceUpdate);
    $stats = $sync->run();
    
    // 保存同步记录
    try {
        $pdo = Database::getInstance()->getConnection();
        $pdo->exec("
            INSERT INTO system_logs (type, action, details, created_at)
            VALUES ('cron', 'threat_intel_sync', '" . addslashes(json_encode($stats)) . "', NOW())
        ");
    } catch (Exception $e) {
        // 忽略日志错误
    }
}
