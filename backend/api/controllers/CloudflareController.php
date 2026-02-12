<?php
/**
 * Cloudflare 控制器
 * 处理 Cloudflare 域名管理、DNS记录、HTTPS配置等
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../../core/cloudflare.php';
require_once __DIR__ . '/../../core/jump.php';

class CloudflareController extends BaseController
{
    private ?CloudflareService $cf = null;
    private array $cfConfig = [];
    
    public function __construct()
    {
        parent::__construct();
        $this->cfConfig = $this->db->getConfig('cloudflare', []);
    }
    
    /**
     * 初始化 Cloudflare 服务
     */
    private function initCloudflare(): CloudflareService
    {
        if (!$this->cf) {
            if (empty($this->cfConfig['api_token'])) {
                $this->error('请先配置 Cloudflare API');
            }
            $this->cf = new CloudflareService(
                $this->cfConfig['api_token'],
                $this->cfConfig['account_id'] ?? ''
            );
        }
        return $this->cf;
    }
    
    /**
     * 获取 Cloudflare 配置
     * GET /api/v2/cloudflare/config
     */
    public function getConfig(): void
    {
        $this->requireLogin();
        
        $config = [
            'api_token' => !empty($this->cfConfig['api_token']) 
                ? '********' . substr($this->cfConfig['api_token'], -4) 
                : '',
            'account_id' => $this->cfConfig['account_id'] ?? '',
            'configured' => !empty($this->cfConfig['api_token']) && !empty($this->cfConfig['account_id'])
        ];
        
        $this->success(['config' => $config]);
    }
    
    /**
     * 保存 Cloudflare 配置
     * POST /api/v2/cloudflare/config
     */
    public function saveConfig(): void
    {
        $this->requireLogin();
        
        $apiToken = $this->param('api_token', '');
        $accountId = $this->param('account_id', '');
        
        if (empty($apiToken) || empty($accountId)) {
            $this->error('API Token 和 Account ID 不能为空');
        }
        
        // 如果 Token 是掩码格式，保留原来的值
        if (strpos($apiToken, '********') === 0) {
            if (!empty($this->cfConfig['api_token'])) {
                $apiToken = $this->cfConfig['api_token'];
            } else {
                $this->error('请输入完整的 API Token');
            }
        }
        
        // 验证 Token
        $cf = new CloudflareService($apiToken, $accountId);
        $verify = $cf->verifyToken();
        
        if (!$verify['success']) {
            $this->error('API Token 验证失败: ' . ($verify['message'] ?? '未知错误'));
        }
        
        $this->db->setConfig('cloudflare', [
            'api_token' => $apiToken,
            'account_id' => $accountId
        ]);
        
        $this->audit('cf_save_config');
        $this->success(null, 'Cloudflare 配置已保存');
    }
    
    /**
     * 获取本系统管理的 Cloudflare 域名列表
     * GET /api/v2/cloudflare/zones
     */
    public function listZones(): void
    {
        $this->requireLogin();
        
        if (empty($this->cfConfig['api_token'])) {
            $this->error('请先配置 Cloudflare API');
        }
        
        $pdo = $this->pdo();
        
        // 检查表是否存在，不存在则创建
        try {
            $pdo->query("SELECT 1 FROM cf_domains LIMIT 1");
        } catch (PDOException $e) {
            // 表不存在，创建它
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS cf_domains (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    domain VARCHAR(255) NOT NULL UNIQUE,
                    zone_id VARCHAR(64),
                    status VARCHAR(32) DEFAULT 'pending',
                    nameservers TEXT,
                    server_ip VARCHAR(45),
                    https_enabled TINYINT(1) DEFAULT 0,
                    added_to_pool TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_domain (domain),
                    INDEX idx_zone_id (zone_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
        
        try {
            $stmt = $pdo->query("SELECT * FROM cf_domains ORDER BY created_at DESC");
            $localDomains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->success(['zones' => [], 'total' => 0, 'error' => $e->getMessage()]);
            return;
        }
        
        if (empty($localDomains)) {
            $this->success(['zones' => [], 'total' => 0]);
            return;
        }
        
        $cf = $this->initCloudflare();
        $zones = [];
        
        foreach ($localDomains as $d) {
            $zone = [
                'id' => $d['zone_id'],
                'name' => $d['domain'],
                'status' => $d['status'],
                'name_servers' => json_decode($d['nameservers'] ?: '[]', true),
                'server_ip' => $d['server_ip'],
                'https_enabled' => (bool)$d['https_enabled'],
                'added_to_pool' => (bool)$d['added_to_pool'],
                'created_at' => $d['created_at']
            ];
            
            // 从 Cloudflare 获取最新状态
            if ($d['zone_id']) {
                $cfStatus = $cf->getZoneStatus($d['zone_id']);
                if ($cfStatus) {
                    $zone['status'] = $cfStatus['status'];
                    $zone['name_servers'] = $cfStatus['name_servers'] ?? $zone['name_servers'];
                    // 更新数据库状态
                    $pdo->prepare("UPDATE cf_domains SET status = ?, nameservers = ? WHERE id = ?")
                        ->execute([$cfStatus['status'], json_encode($cfStatus['name_servers'] ?? []), $d['id']]);
                }
            }
            
            $zones[] = $zone;
        }
        
        $this->success(['zones' => $zones, 'total' => count($zones)]);
    }
    
    /**
     * 添加域名到 Cloudflare（一键配置）
     * POST /api/v2/cloudflare/zones
     */
    public function addDomain(): void
    {
        $this->requireLogin();
        
        $domain = trim($this->requiredParam('domain', '域名不能为空'));
        $enableHttps = (bool)$this->param('enable_https', true);
        $addToDomainPool = (bool)$this->param('add_to_pool', true);
        
        $serverIp = $this->getServerPublicIp();
        if (empty($serverIp)) {
            $this->error('无法获取服务器IP');
        }
        
        $cf = $this->initCloudflare();
        $result = $cf->quickSetup($domain, $serverIp, $enableHttps);
        
        if ($result['success']) {
            $rootDomain = $result['root_domain'] ?? $domain;
            $pdo = $this->pdo();
            
            $stmt = $pdo->prepare("INSERT INTO cf_domains (domain, zone_id, status, nameservers, server_ip, https_enabled, added_to_pool) 
                VALUES (?, ?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE zone_id = VALUES(zone_id), status = VALUES(status), nameservers = VALUES(nameservers), 
                server_ip = VALUES(server_ip), https_enabled = VALUES(https_enabled), added_to_pool = VALUES(added_to_pool), updated_at = NOW()");
            $stmt->execute([
                $rootDomain,
                $result['zone_id'] ?? null,
                'active',
                json_encode($result['name_servers'] ?? []),
                $serverIp,
                $enableHttps ? 1 : 0,
                $addToDomainPool ? 1 : 0
            ]);
            
            // 添加到域名池
            if ($addToDomainPool) {
                $jumpService = new JumpService($pdo);
                $jumpService->addDomain('https://' . $rootDomain, $rootDomain . ' (Cloudflare)', false);
            }
            
            $this->audit('cf_add_domain', 'domain', null, ['domain' => $rootDomain]);
        }
        
        if ($result['success']) {
            $this->success($result);
        } else {
            $this->error($result['message'] ?? '添加域名失败');
        }
    }
    
    /**
     * 批量添加域名到 Cloudflare
     * POST /api/v2/cloudflare/zones/batch
     */
    public function batchAddDomains(): void
    {
        $this->requireLogin();
        
        $domains = $this->param('domains', []);
        $enableHttps = (bool)$this->param('enable_https', true);
        $addToDomainPool = (bool)$this->param('add_to_pool', true);
        
        if (empty($domains)) {
            $this->error('域名列表不能为空');
        }
        
        $serverIp = $this->getServerPublicIp();
        if (empty($serverIp)) {
            $this->error('无法获取服务器IP');
        }
        
        $cf = $this->initCloudflare();
        $jumpService = new JumpService($this->pdo());
        
        $results = [];
        $successCount = 0;
        $failCount = 0;
        
        foreach ($domains as $domain) {
            $domain = trim($domain);
            if (empty($domain)) continue;
            
            $result = $cf->quickSetup($domain, $serverIp, $enableHttps);
            
            if ($result['success']) {
                $successCount++;
                if ($addToDomainPool) {
                    $jumpService->addDomain('https://' . $domain, $domain . ' (Cloudflare)', false);
                }
            } else {
                $failCount++;
            }
            
            $results[] = [
                'domain' => $domain,
                'success' => $result['success'],
                'message' => $result['message'] ?? '',
                'name_servers' => $result['name_servers'] ?? []
            ];
            
            // 避免 API 限流
            usleep(500000);
        }
        
        $this->audit('cf_batch_add_domains', null, null, ['count' => $successCount]);
        
        $this->success([
            'results' => $results,
            'summary' => [
                'total' => count($domains),
                'success' => $successCount,
                'failed' => $failCount
            ]
        ]);
    }
    
    /**
     * 为域名开启 HTTPS
     * POST /api/v2/cloudflare/zones/{domain}/https
     */
    public function enableHttps(string $domain): void
    {
        $this->requireLogin();
        
        $domain = trim($domain);
        if (empty($domain)) {
            $this->error('域名不能为空');
        }
        
        $cf = $this->initCloudflare();
        
        $zoneId = $cf->getZoneId($domain);
        if (!$zoneId) {
            $this->error('域名未在 Cloudflare 中找到');
        }
        
        $steps = [];
        
        // 设置 SSL 模式
        $sslResult = $cf->setSslMode($zoneId, 'full');
        $steps[] = ['step' => 'SSL 模式', 'success' => $sslResult['success']];
        
        // 开启始终 HTTPS
        $httpsResult = $cf->enableAlwaysHttps($zoneId);
        $steps[] = ['step' => '始终使用 HTTPS', 'success' => $httpsResult['success']];
        
        // 开启自动重写
        $rewriteResult = $cf->enableAutomaticHttpsRewrites($zoneId);
        $steps[] = ['step' => '自动 HTTPS 重写', 'success' => $rewriteResult['success']];
        
        // 更新数据库
        $this->pdo()->prepare("UPDATE cf_domains SET https_enabled = 1 WHERE domain = ?")
            ->execute([$domain]);
        
        $this->audit('cf_enable_https', 'domain', null, ['domain' => $domain]);
        
        $this->success(['steps' => $steps]);
    }
    
    /**
     * 从本地记录中移除域名
     * DELETE /api/v2/cloudflare/zones/{domain}
     */
    public function removeDomain(string $domain): void
    {
        $this->requireLogin();
        
        $domain = trim($domain);
        if (empty($domain)) {
            $this->error('域名不能为空');
        }
        
        $stmt = $this->pdo()->prepare("DELETE FROM cf_domains WHERE domain = ?");
        $stmt->execute([$domain]);
        
        $this->audit('cf_remove_domain', 'domain', null, ['domain' => $domain]);
        $this->success(null, '域名已从管理列表中移除');
    }
    
    /**
     * 获取域名的 DNS 记录
     * GET /api/v2/cloudflare/zones/{zoneId}/dns
     */
    public function getDnsRecords(string $zoneId): void
    {
        $this->requireLogin();
        
        if (empty($zoneId)) {
            $this->error('Zone ID 不能为空');
        }
        
        $cf = $this->initCloudflare();
        $result = $cf->getDnsRecords($zoneId);
        
        if ($result['success']) {
            $this->success($result);
        } else {
            $this->error($result['message'] ?? '获取DNS记录失败');
        }
    }
    
    /**
     * 添加 DNS 记录
     * POST /api/v2/cloudflare/zones/{zoneId}/dns
     */
    public function addDnsRecord(string $zoneId): void
    {
        $this->requireLogin();
        
        $type = strtoupper(trim($this->param('type', 'A')));
        $name = trim($this->requiredParam('name', '记录名称不能为空'));
        $content = trim($this->requiredParam('content', '记录内容不能为空'));
        $proxied = (bool)$this->param('proxied', true);
        $ttl = (int)$this->param('ttl', 1);
        
        $cf = $this->initCloudflare();
        $result = $cf->addDnsRecord($zoneId, $type, $name, $content, $proxied, $ttl);
        
        if ($result['success']) {
            $this->audit('cf_add_dns_record', 'dns', null, ['zone_id' => $zoneId, 'name' => $name]);
            $this->success($result);
        } else {
            $this->error($result['message'] ?? '添加DNS记录失败');
        }
    }
    
    /**
     * 更新 DNS 记录
     * PUT /api/v2/cloudflare/zones/{zoneId}/dns/{recordId}
     */
    public function updateDnsRecord(string $zoneId, string $recordId): void
    {
        $this->requireLogin();
        
        $type = strtoupper(trim($this->param('type', 'A')));
        $name = trim($this->requiredParam('name', '记录名称不能为空'));
        $content = trim($this->requiredParam('content', '记录内容不能为空'));
        $proxied = (bool)$this->param('proxied', true);
        $ttl = (int)$this->param('ttl', 1);
        
        $cf = $this->initCloudflare();
        $result = $cf->updateDnsRecord($zoneId, $recordId, $type, $name, $content, $proxied, $ttl);
        
        if ($result['success']) {
            $this->audit('cf_update_dns_record', 'dns', $recordId);
            $this->success($result);
        } else {
            $this->error($result['message'] ?? '更新DNS记录失败');
        }
    }
    
    /**
     * 删除 DNS 记录
     * DELETE /api/v2/cloudflare/zones/{zoneId}/dns/{recordId}
     */
    public function deleteDnsRecord(string $zoneId, string $recordId): void
    {
        $this->requireLogin();
        
        $cf = $this->initCloudflare();
        $result = $cf->deleteDnsRecord($zoneId, $recordId);
        
        if ($result['success']) {
            $this->audit('cf_delete_dns_record', 'dns', $recordId);
            $this->success(null, 'DNS记录已删除');
        } else {
            $this->error($result['message'] ?? '删除DNS记录失败');
        }
    }
    
    /**
     * 获取域名详细信息
     * GET /api/v2/cloudflare/zones/{zoneId}/details
     */
    public function getZoneDetails(string $zoneId): void
    {
        $this->requireLogin();
        
        if (empty($zoneId)) {
            $this->error('Zone ID 不能为空');
        }
        
        $cf = $this->initCloudflare();
        $result = $cf->getZoneDetails($zoneId);
        
        if ($result['success']) {
            $this->success($result);
        } else {
            $this->error($result['message'] ?? '获取域名详情失败');
        }
    }
    
    /**
     * 删除 Cloudflare 域名（包括从CF账户中删除）
     * DELETE /api/v2/cloudflare/zones/{zoneId}/full
     */
    public function deleteZone(string $zoneId): void
    {
        $this->requireLogin();
        
        $domain = trim($this->param('domain', ''));
        
        if (empty($zoneId)) {
            $this->error('Zone ID 不能为空');
        }
        
        $cf = $this->initCloudflare();
        $result = $cf->deleteZone($zoneId);
        
        if ($result['success'] && $domain) {
            $this->pdo()->prepare("DELETE FROM cf_domains WHERE domain = ?")
                ->execute([$domain]);
            $this->audit('cf_delete_zone', 'zone', $zoneId, ['domain' => $domain]);
        }
        
        if ($result['success']) {
            $this->success(null, '域名已从Cloudflare删除');
        } else {
            $this->error($result['message'] ?? '删除域名失败');
        }
    }
    
    /**
     * 获取服务器公网IP
     */
    private function getServerPublicIp(): string
    {
        $config = $this->db->getConfig('server', []);
        if (!empty($config['public_ip'])) {
            return $config['public_ip'];
        }
        
        $ip = @file_get_contents('https://api.ipify.org?format=text');
        if ($ip && filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE)) {
            $this->db->setConfig('server', ['public_ip' => trim($ip)]);
            return trim($ip);
        }
        
        return $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? '';
    }
}
