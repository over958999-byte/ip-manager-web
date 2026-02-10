<?php
/**
 * 域名安全检测服务
 * 检测域名是否被标记为危险/钓鱼/恶意软件等
 */

class DomainSafetyChecker {
    private $pdo;
    private $config;
    
    // 检测API列表
    private $checkApis = [];
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->loadConfig();
    }
    
    /**
     * 加载配置
     */
    private function loadConfig(): void {
        $stmt = $this->pdo->prepare("SELECT `value` FROM config WHERE `key` = 'domain_safety_check'");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->config = $row ? json_decode($row['value'], true) : [
            'enabled' => true,
            'interval' => 60,
            'apis' => []
        ];
    }
    
    /**
     * 保存配置
     */
    public function saveConfig(array $config): bool {
        $stmt = $this->pdo->prepare("INSERT INTO config (`key`, `value`) VALUES ('domain_safety_check', ?) ON DUPLICATE KEY UPDATE `value` = ?");
        $json = json_encode($config);
        return $stmt->execute([$json, $json]);
    }
    
    /**
     * 获取配置
     */
    public function getConfig(): array {
        return $this->config;
    }
    
    /**
     * 检测单个域名
     */
    public function checkDomain(string $domain, int $domainId = 0): array {
        // 清理域名
        $domain = $this->cleanDomain($domain);
        
        $results = [];
        $overallStatus = 'safe';
        $dangers = [];
        $warnings = [];
        
        // 1. URLhaus (AbuseCH) - 免费恶意软件/钓鱼检测API
        $urlhausResult = $this->checkUrlhaus($domain);
        $results['urlhaus'] = $urlhausResult;
        if ($urlhausResult['status'] === 'danger') {
            $dangers[] = 'URLhaus: ' . $urlhausResult['message'];
        } elseif ($urlhausResult['status'] === 'warning') {
            $warnings[] = 'URLhaus: ' . $urlhausResult['message'];
        }
        
        // 2. ThreatFox (AbuseCH) - 免费IOC检测API
        $threatfoxResult = $this->checkThreatFox($domain);
        $results['threatfox'] = $threatfoxResult;
        if ($threatfoxResult['status'] === 'danger') {
            $dangers[] = 'ThreatFox: ' . $threatfoxResult['message'];
        }
        
        // 3. URLVoid - 免费域名信誉检测
        $urlvoidResult = $this->checkUrlVoid($domain);
        $results['urlvoid'] = $urlvoidResult;
        if ($urlvoidResult['status'] === 'danger') {
            $dangers[] = 'URLVoid: ' . $urlvoidResult['message'];
        } elseif ($urlvoidResult['status'] === 'warning') {
            $warnings[] = 'URLVoid: ' . $urlvoidResult['message'];
        }
        
        // 4. Sucuri SiteCheck - 免费网站安全扫描
        $sucuriResult = $this->checkSucuri($domain);
        $results['sucuri'] = $sucuriResult;
        if ($sucuriResult['status'] === 'danger') {
            $dangers[] = 'Sucuri: ' . $sucuriResult['message'];
        } elseif ($sucuriResult['status'] === 'warning') {
            $warnings[] = 'Sucuri: ' . $sucuriResult['message'];
        }
        
        // 5. Norton Safe Web - 免费网站安全评级
        $nortonResult = $this->checkNortonSafeWeb($domain);
        $results['norton'] = $nortonResult;
        if ($nortonResult['status'] === 'danger') {
            $dangers[] = 'Norton: ' . $nortonResult['message'];
        } elseif ($nortonResult['status'] === 'warning') {
            $warnings[] = 'Norton: ' . $nortonResult['message'];
        }
        
        // 确定总体状态
        if (count($dangers) > 0) {
            $overallStatus = 'danger';
        } elseif (count($warnings) > 0) {
            $overallStatus = 'warning';
        }
        
        $detail = [
            'results' => $results,
            'dangers' => $dangers,
            'warnings' => $warnings,
            'checked_at' => date('Y-m-d H:i:s')
        ];
        
        // 更新数据库
        if ($domainId > 0) {
            $this->updateDomainStatus($domainId, $overallStatus, $detail);
            $this->logCheck($domainId, $domain, $overallStatus, $detail);
        }
        
        return [
            'success' => true,
            'domain' => $domain,
            'status' => $overallStatus,
            'detail' => $detail
        ];
    }
    
    /**
     * 批量检测所有域名
     */
    public function checkAllDomains(): array {
        $stmt = $this->pdo->query("SELECT id, domain FROM jump_domains WHERE enabled = 1");
        $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $results = [];
        $stats = ['total' => 0, 'safe' => 0, 'warning' => 0, 'danger' => 0];
        
        foreach ($domains as $domain) {
            $result = $this->checkDomain($domain['domain'], $domain['id']);
            $results[] = $result;
            
            $stats['total']++;
            $stats[$result['status']]++;
            
            // 避免请求过快
            usleep(500000); // 0.5秒
        }
        
        return [
            'success' => true,
            'stats' => $stats,
            'results' => $results
        ];
    }
    
    /**
     * 清理域名，提取纯域名
     */
    private function cleanDomain(string $domain): string {
        // 移除协议
        $domain = preg_replace('/^https?:\/\//i', '', $domain);
        // 移除路径
        $domain = preg_replace('/\/.*$/', '', $domain);
        // 移除端口
        $domain = preg_replace('/:\d+$/', '', $domain);
        return strtolower(trim($domain));
    }
    
    /**
     * 更新域名安全状态
     */
    private function updateDomainStatus(int $domainId, string $status, array $detail): void {
        $stmt = $this->pdo->prepare("
            UPDATE jump_domains 
            SET safety_status = ?, safety_detail = ?, last_check_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$status, json_encode($detail), $domainId]);
    }
    
    /**
     * 记录检测日志
     */
    private function logCheck(int $domainId, string $domain, string $status, array $detail): void {
        $sources = [];
        foreach ($detail['results'] ?? [] as $source => $result) {
            if ($result['status'] !== 'safe') {
                $sources[] = $source;
            }
        }
        
        $stmt = $this->pdo->prepare("
            INSERT INTO domain_safety_logs (domain_id, domain, check_source, status, detail)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $domainId, 
            $domain, 
            implode(',', $sources) ?: 'all_safe',
            $status,
            json_encode($detail)
        ]);
    }
    
    /**
     * URLhaus 检测 (AbuseCH)
     * 免费API，专门检测恶意软件URL
     */
    private function checkUrlhaus(string $domain): array {
        $url = 'https://urlhaus-api.abuse.ch/v1/host/';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['host' => $domain]),
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'IP-Manager-Safety-Checker/1.0'
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['status' => 'unknown', 'message' => '检测失败: ' . $error];
        }
        
        $data = json_decode($response, true);
        
        if (!$data) {
            return ['status' => 'unknown', 'message' => '响应解析失败'];
        }
        
        if ($data['query_status'] === 'no_results') {
            return ['status' => 'safe', 'message' => '未发现威胁'];
        }
        
        if ($data['query_status'] === 'ok' && isset($data['url_count'])) {
            $count = intval($data['url_count']);
            if ($count > 0) {
                return [
                    'status' => 'danger',
                    'message' => "发现 {$count} 条恶意URL记录",
                    'detail' => $data
                ];
            }
        }
        
        return ['status' => 'safe', 'message' => '未发现威胁'];
    }
    
    /**
     * ThreatFox 检测 (AbuseCH)
     * 免费IOC (Indicators of Compromise) 检测API
     */
    private function checkThreatFox(string $domain): array {
        $url = 'https://threatfox-api.abuse.ch/api/v1/';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'query' => 'search_ioc',
                'search_term' => $domain
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'IP-Manager-Safety-Checker/1.0'
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['status' => 'unknown', 'message' => '检测失败: ' . $error];
        }
        
        $data = json_decode($response, true);
        
        if (!$data) {
            return ['status' => 'unknown', 'message' => '响应解析失败'];
        }
        
        if ($data['query_status'] === 'no_result') {
            return ['status' => 'safe', 'message' => '未发现威胁'];
        }
        
        if ($data['query_status'] === 'ok' && !empty($data['data'])) {
            $count = count($data['data']);
            $threats = [];
            foreach (array_slice($data['data'], 0, 3) as $item) {
                $threats[] = $item['threat_type'] ?? 'unknown';
            }
            return [
                'status' => 'danger',
                'message' => "发现 {$count} 条威胁记录: " . implode(', ', array_unique($threats)),
                'detail' => $data
            ];
        }
        
        return ['status' => 'safe', 'message' => '未发现威胁'];
    }
    
    /**
     * URLVoid 检测
     * 免费域名信誉查询
     */
    private function checkUrlVoid(string $domain): array {
        // URLVoid 网页抓取方式（无需API Key）
        $url = 'https://www.urlvoid.com/scan/' . urlencode($domain) . '/';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($error || $httpCode !== 200) {
            return ['status' => 'unknown', 'message' => '检测失败'];
        }
        
        // 解析结果，查找检测数量
        if (preg_match('/Detection\s*Count[^0-9]*(\d+)\s*\/\s*(\d+)/i', $response, $matches)) {
            $detected = intval($matches[1]);
            $total = intval($matches[2]);
            
            if ($detected >= 3) {
                return [
                    'status' => 'danger',
                    'message' => "{$detected}/{$total} 个引擎检测到风险"
                ];
            } elseif ($detected > 0) {
                return [
                    'status' => 'warning',
                    'message' => "{$detected}/{$total} 个引擎检测到风险"
                ];
            }
        }
        
        // 检查是否有黑名单标记
        if (preg_match('/blacklist|malware|phishing|suspicious/i', $response)) {
            if (preg_match('/DETECTED|BLACKLISTED/i', $response)) {
                return ['status' => 'warning', 'message' => '可能存在安全风险'];
            }
        }
        
        return ['status' => 'safe', 'message' => '未发现威胁'];
    }
    
    /**
     * Sucuri SiteCheck 检测
     * 免费网站安全扫描服务
     */
    private function checkSucuri(string $domain): array {
        $url = 'https://sitecheck.sucuri.net/results/' . urlencode($domain);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($error || $httpCode !== 200) {
            return ['status' => 'unknown', 'message' => '检测失败'];
        }
        
        // 检测是否被标记为恶意
        if (preg_match('/blacklisted|malware\s+detected|infected|hacked/i', $response)) {
            return [
                'status' => 'danger',
                'message' => '网站被标记为恶意或已感染'
            ];
        }
        
        // 检测安全警告
        if (preg_match('/security\s+risk|warning|outdated|vulnerable/i', $response)) {
            if (preg_match('/critical|high\s+risk|severe/i', $response)) {
                return [
                    'status' => 'danger',
                    'message' => '发现严重安全风险'
                ];
            }
            return [
                'status' => 'warning',
                'message' => '存在安全警告'
            ];
        }
        
        // 检查是否显示干净状态
        if (preg_match('/no\s+malware|clean|not\s+blacklisted/i', $response)) {
            return ['status' => 'safe', 'message' => '网站安全'];
        }
        
        return ['status' => 'safe', 'message' => '未发现威胁'];
    }
    
    /**
     * Norton Safe Web 检测
     * 免费网站安全评级服务
     */
    private function checkNortonSafeWeb(string $domain): array {
        $url = 'https://safeweb.norton.com/report/show?url=' . urlencode($domain);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: en-US,en;q=0.9'
            ]
        ]);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($error || $httpCode !== 200) {
            return ['status' => 'unknown', 'message' => '检测失败'];
        }
        
        // Norton评级: OK (安全), CAUTION (警告), WARNING (危险), UNTESTED (未测试)
        
        // 检测危险评级
        if (preg_match('/class="[^"]*warning[^"]*"|rating-warning|dangerous|unsafe/i', $response)) {
            return [
                'status' => 'danger',
                'message' => 'Norton评级: 危险'
            ];
        }
        
        // 检测警告评级
        if (preg_match('/class="[^"]*caution[^"]*"|rating-caution|suspicious/i', $response)) {
            return [
                'status' => 'warning',
                'message' => 'Norton评级: 谨慎'
            ];
        }
        
        // 检测安全评级
        if (preg_match('/class="[^"]*ok[^"]*"|rating-ok|safe|trusted/i', $response)) {
            return ['status' => 'safe', 'message' => 'Norton评级: 安全'];
        }
        
        // 未测试
        if (preg_match('/untested|not\s+yet\s+rated/i', $response)) {
            return ['status' => 'safe', 'message' => 'Norton: 尚未评级'];
        }
        
        return ['status' => 'safe', 'message' => '未发现威胁'];
    }
    
    /**
     * 获取域名安全状态统计
     */
    public function getStats(): array {
        $stmt = $this->pdo->query("
            SELECT 
                safety_status,
                COUNT(*) as count
            FROM jump_domains
            WHERE enabled = 1
            GROUP BY safety_status
        ");
        
        $stats = ['total' => 0, 'unknown' => 0, 'safe' => 0, 'warning' => 0, 'danger' => 0];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $stats[$row['safety_status']] = intval($row['count']);
            $stats['total'] += intval($row['count']);
        }
        
        return $stats;
    }
    
    /**
     * 获取危险域名列表
     */
    public function getDangerDomains(): array {
        $stmt = $this->pdo->query("
            SELECT id, domain, name, safety_status, safety_detail, last_check_at
            FROM jump_domains
            WHERE safety_status IN ('warning', 'danger')
            ORDER BY FIELD(safety_status, 'danger', 'warning'), last_check_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 获取检测日志
     */
    public function getLogs(int $limit = 100): array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM domain_safety_logs
            ORDER BY checked_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
