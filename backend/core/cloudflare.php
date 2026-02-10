<?php
/**
 * Cloudflare API 服务
 * 用于自动添加域名到 Cloudflare 并开启 HTTPS
 */

class CloudflareService {
    private $apiToken;
    private $accountId;
    private $baseUrl = 'https://api.cloudflare.com/client/v4';
    
    public function __construct(string $apiToken = '', string $accountId = '') {
        $this->apiToken = $apiToken;
        $this->accountId = $accountId;
    }
    
    /**
     * 设置 API 凭证
     */
    public function setCredentials(string $apiToken, string $accountId): void {
        $this->apiToken = $apiToken;
        $this->accountId = $accountId;
    }
    
    /**
     * 发送 API 请求
     */
    private function request(string $method, string $endpoint, array $data = null): array {
        $url = $this->baseUrl . $endpoint;
        
        $headers = [
            'Authorization: Bearer ' . $this->apiToken,
            'Content-Type: application/json'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'message' => '请求失败: ' . $error];
        }
        
        $result = json_decode($response, true);
        if (!$result) {
            return ['success' => false, 'message' => '响应解析失败'];
        }
        
        return $result;
    }
    
    /**
     * 验证 API Token 是否有效
     */
    public function verifyToken(): array {
        $result = $this->request('GET', '/user/tokens/verify');
        
        if (isset($result['success']) && $result['success']) {
            return ['success' => true, 'message' => 'Token 有效'];
        }
        
        return [
            'success' => false, 
            'message' => $result['errors'][0]['message'] ?? 'Token 无效'
        ];
    }
    
    /**
     * 添加域名到 Cloudflare
     */
    public function addZone(string $domain): array {
        $data = [
            'name' => $domain,
            'account' => ['id' => $this->accountId],
            'jump_start' => true
        ];
        
        $result = $this->request('POST', '/zones', $data);
        
        if (isset($result['success']) && $result['success']) {
            return [
                'success' => true,
                'zone_id' => $result['result']['id'],
                'name_servers' => $result['result']['name_servers'],
                'status' => $result['result']['status']
            ];
        }
        
        $errorMsg = '添加失败';
        if (isset($result['errors'][0]['message'])) {
            $errorMsg = $result['errors'][0]['message'];
        }
        
        return ['success' => false, 'message' => $errorMsg];
    }
    
    /**
     * 获取域名的 Zone ID
     */
    public function getZoneId(string $domain): ?string {
        $result = $this->request('GET', '/zones?name=' . urlencode($domain));
        
        if (isset($result['success']) && $result['success'] && !empty($result['result'])) {
            return $result['result'][0]['id'];
        }
        
        return null;
    }
    
    /**
     * 获取域名列表
     */
    public function listZones(int $page = 1, int $perPage = 50): array {
        $result = $this->request('GET', "/zones?page={$page}&per_page={$perPage}");
        
        if (isset($result['success']) && $result['success']) {
            return [
                'success' => true,
                'zones' => $result['result'],
                'total' => $result['result_info']['total_count'] ?? count($result['result'])
            ];
        }
        
        return ['success' => false, 'zones' => [], 'message' => $result['errors'][0]['message'] ?? '获取失败'];
    }
    
    /**
     * 添加 DNS 记录
     */
    public function addDnsRecord(string $zoneId, string $type, string $name, string $content, bool $proxied = true, int $ttl = 3600, ?int $priority = null): array {
        $data = [
            'type' => strtoupper($type),
            'name' => $name,
            'content' => $content,
            'ttl' => $ttl
        ];
        
        if (in_array(strtoupper($type), ['A', 'AAAA', 'CNAME'])) {
            $data['proxied'] = $proxied;
        }
        
        if (strtoupper($type) === 'MX' && $priority !== null) {
            $data['priority'] = $priority;
        }
        
        $result = $this->request('POST', "/zones/{$zoneId}/dns_records", $data);
        
        if (isset($result['success']) && $result['success']) {
            return ['success' => true, 'record_id' => $result['result']['id']];
        }
        
        return ['success' => false, 'message' => $result['errors'][0]['message'] ?? '添加 DNS 记录失败'];
    }
    
    /**
     * 开启始终使用 HTTPS
     */
    public function enableAlwaysHttps(string $zoneId): array {
        $result = $this->request('PATCH', "/zones/{$zoneId}/settings/always_use_https", ['value' => 'on']);
        
        if (isset($result['success']) && $result['success']) {
            return ['success' => true, 'value' => $result['result']['value']];
        }
        
        return ['success' => false, 'message' => $result['errors'][0]['message'] ?? '开启 HTTPS 失败'];
    }
    
    /**
     * 获取 HTTPS 设置状态
     */
    public function getHttpsStatus(string $zoneId): array {
        $result = $this->request('GET', "/zones/{$zoneId}/settings/always_use_https");
        
        if (isset($result['success']) && $result['success']) {
            return ['success' => true, 'value' => $result['result']['value']];
        }
        
        return ['success' => false, 'value' => 'unknown'];
    }
    
    /**
     * 开启自动 HTTPS 重写
     */
    public function enableAutomaticHttpsRewrites(string $zoneId): array {
        $result = $this->request('PATCH', "/zones/{$zoneId}/settings/automatic_https_rewrites", ['value' => 'on']);
        
        if (isset($result['success']) && $result['success']) {
            return ['success' => true];
        }
        
        return ['success' => false, 'message' => $result['errors'][0]['message'] ?? '设置失败'];
    }
    
    /**
     * 设置 SSL 模式
     */
    public function setSslMode(string $zoneId, string $mode = 'full'): array {
        // 模式: off, flexible, full, strict
        $result = $this->request('PATCH', "/zones/{$zoneId}/settings/ssl", ['value' => $mode]);
        
        if (isset($result['success']) && $result['success']) {
            return ['success' => true, 'value' => $result['result']['value']];
        }
        
        return ['success' => false, 'message' => $result['errors'][0]['message'] ?? '设置 SSL 模式失败'];
    }
    
    /**
     * 一键添加域名并配置（添加域名 + DNS + 开启 HTTPS）
     */
    public function quickSetup(string $domain, string $serverIp, bool $enableHttps = true): array {
        $steps = [];
        
        // 1. 添加域名
        $zoneResult = $this->addZone($domain);
        if (!$zoneResult['success']) {
            // 如果域名已存在，尝试获取 Zone ID
            $zoneId = $this->getZoneId($domain);
            if ($zoneId) {
                $steps[] = ['step' => '添加域名', 'status' => 'exists', 'message' => '域名已存在'];
                $zoneResult = ['success' => true, 'zone_id' => $zoneId, 'name_servers' => []];
            } else {
                return ['success' => false, 'message' => $zoneResult['message'], 'steps' => $steps];
            }
        } else {
            $steps[] = ['step' => '添加域名', 'status' => 'success', 'name_servers' => $zoneResult['name_servers']];
        }
        
        $zoneId = $zoneResult['zone_id'];
        
        // 等待 API 同步
        sleep(1);
        
        // 2. 添加根域名 A 记录
        $dnsResult = $this->addDnsRecord($zoneId, 'A', '@', $serverIp, true);
        $steps[] = [
            'step' => '添加 A 记录 (@)',
            'status' => $dnsResult['success'] ? 'success' : 'failed',
            'message' => $dnsResult['message'] ?? ''
        ];
        
        // 3. 添加 www A 记录
        $wwwResult = $this->addDnsRecord($zoneId, 'A', 'www', $serverIp, true);
        $steps[] = [
            'step' => '添加 A 记录 (www)',
            'status' => $wwwResult['success'] ? 'success' : 'failed',
            'message' => $wwwResult['message'] ?? ''
        ];
        
        // 4. 开启 HTTPS
        if ($enableHttps) {
            // 设置 SSL 模式为 Full
            $sslResult = $this->setSslMode($zoneId, 'full');
            $steps[] = [
                'step' => '设置 SSL 模式 (Full)',
                'status' => $sslResult['success'] ? 'success' : 'failed',
                'message' => $sslResult['message'] ?? ''
            ];
            
            // 开启始终使用 HTTPS
            $httpsResult = $this->enableAlwaysHttps($zoneId);
            $steps[] = [
                'step' => '开启始终使用 HTTPS',
                'status' => $httpsResult['success'] ? 'success' : 'failed',
                'message' => $httpsResult['message'] ?? ''
            ];
            
            // 开启自动 HTTPS 重写
            $rewriteResult = $this->enableAutomaticHttpsRewrites($zoneId);
            $steps[] = [
                'step' => '开启自动 HTTPS 重写',
                'status' => $rewriteResult['success'] ? 'success' : 'failed',
                'message' => $rewriteResult['message'] ?? ''
            ];
        }
        
        return [
            'success' => true,
            'zone_id' => $zoneId,
            'name_servers' => $zoneResult['name_servers'] ?? [],
            'steps' => $steps
        ];
    }
    
    /**
     * 删除域名
     */
    public function deleteZone(string $zoneId): array {
        $result = $this->request('DELETE', "/zones/{$zoneId}");
        
        if (isset($result['success']) && $result['success']) {
            return ['success' => true];
        }
        
        return ['success' => false, 'message' => $result['errors'][0]['message'] ?? '删除失败'];
    }
}
