<?php
/**
 * 域名管理控制器
 * 处理域名池的 CRUD、检测、安全检查等操作
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../../core/jump.php';

class DomainController extends BaseController
{
    private JumpService $jump;
    
    public function __construct()
    {
        parent::__construct();
        $this->jump = new JumpService($this->pdo());
    }
    
    /**
     * 获取域名列表
     * GET /api/v2/domains
     */
    public function list(): void
    {
        $this->requireLogin();
        
        $enabledOnly = (bool)$this->param('enabled_only', false);
        $domains = $this->jump->getDomains($enabledOnly);
        $this->success($domains);
    }
    
    /**
     * 添加域名
     * POST /api/v2/domains
     */
    public function create(): void
    {
        $this->requireLogin();
        
        $domain = trim($this->requiredParam('domain', '域名不能为空'));
        $name = trim($this->param('name', ''));
        $isDefault = (bool)$this->param('is_default', false);
        
        $result = $this->jump->addDomain($domain, $name, $isDefault);
        
        if ($result['success']) {
            $this->audit('create_domain', 'domain', $result['data']['id'] ?? null);
        }
        
        $this->outputResult($result);
    }
    
    /**
     * 更新域名
     * PUT /api/v2/domains/{id}
     */
    public function update(int $id): void
    {
        $this->requireLogin();
        
        if ($id <= 0) {
            $this->error('ID无效');
        }
        
        $data = $this->input;
        unset($data['id'], $data['action']);
        
        $result = $this->jump->updateDomain($id, $data);
        
        if ($result['success']) {
            $this->audit('update_domain', 'domain', $id);
        }
        
        $this->outputResult($result);
    }
    
    /**
     * 删除域名
     * DELETE /api/v2/domains/{id}
     */
    public function delete(int $id): void
    {
        $this->requireLogin();
        
        $result = $this->jump->deleteDomain($id);
        
        if ($result['success']) {
            $this->audit('delete_domain', 'domain', $id);
        }
        
        $this->outputResult($result);
    }
    
    /**
     * 检测域名解析状态
     * GET /api/v2/domains/check
     */
    public function check(): void
    {
        $this->requireLogin();
        
        $domain = trim($this->requiredParam('domain', '域名不能为空'));
        
        // 移除协议前缀
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');
        
        // 获取服务器公网IP
        $serverIp = $this->getServerPublicIp();
        
        // 生成唯一验证token
        $verifyToken = md5($serverIp . '_ip_manager_' . date('Ymd'));
        
        // 方法1: 使用HTTPS请求验证（支持Cloudflare等CDN）
        $isResolved = false;
        $verifyMethod = 'https';
        $resolvedIps = [];
        
        // 尝试HTTPS验证端点
        $verifyUrl = "https://{$domain}/_verify_server";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $verifyUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'IPManager-Verify/1.0'
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // 检查是否返回正确的验证token
        if ($httpCode === 200 && trim($response) === $verifyToken) {
            $isResolved = true;
            $resolvedIps = ['(通过Cloudflare/CDN)'];
        } else {
            // 方法2: 回退到DNS检查（用于非CDN情况）
            $verifyMethod = 'dns';
            $dnsRecords = @dns_get_record($domain, DNS_A);
            if ($dnsRecords) {
                foreach ($dnsRecords as $record) {
                    if (isset($record['ip'])) {
                        $resolvedIps[] = $record['ip'];
                    }
                }
            }
            
            // 也尝试gethostbyname
            $hostIp = @gethostbyname($domain);
            if ($hostIp !== $domain && !in_array($hostIp, $resolvedIps)) {
                $resolvedIps[] = $hostIp;
            }
            
            $isResolved = in_array($serverIp, $resolvedIps);
        }
        
        $this->success([
            'domain' => $domain,
            'server_ip' => $serverIp,
            'resolved_ips' => $resolvedIps,
            'is_resolved' => $isResolved,
            'verify_method' => $verifyMethod,
            'status' => $isResolved ? 'ok' : (empty($resolvedIps) ? 'not_resolved' : 'wrong_ip')
        ]);
    }
    
    /**
     * 批量检测所有域名
     * POST /api/v2/domains/check-all
     */
    public function checkAll(): void
    {
        $this->requireLogin();
        
        $domains = $this->jump->getDomains(true);
        $serverIp = $this->getServerPublicIp();
        
        $results = [];
        foreach ($domains as $domain) {
            $resolvedIps = [];
            $dnsRecords = @dns_get_record($domain['domain'], DNS_A);
            if ($dnsRecords) {
                foreach ($dnsRecords as $record) {
                    if (isset($record['ip'])) {
                        $resolvedIps[] = $record['ip'];
                    }
                }
            }
            
            $isResolved = in_array($serverIp, $resolvedIps);
            
            $results[] = [
                'id' => $domain['id'],
                'domain' => $domain['domain'],
                'resolved_ips' => $resolvedIps,
                'is_resolved' => $isResolved,
                'status' => $isResolved ? 'ok' : (empty($resolvedIps) ? 'not_resolved' : 'wrong_ip')
            ];
        }
        
        $this->success([
            'server_ip' => $serverIp,
            'results' => $results
        ]);
    }
    
    /**
     * 域名安全检测
     * POST /api/v2/domains/{id}/safety-check
     */
    public function safetyCheck(int $id = 0): void
    {
        $this->requireLogin();
        
        $domain = $this->param('domain', '');
        
        if ($id > 0) {
            // 从数据库获取域名
            $stmt = $this->pdo()->prepare("SELECT domain FROM jump_domains WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $domain = $row['domain'];
            }
        }
        
        if (empty($domain)) {
            $this->error('域名不能为空');
        }
        
        // 加载域名安全检测服务
        if (file_exists(__DIR__ . '/../../core/domain_safety.php')) {
            require_once __DIR__ . '/../../core/domain_safety.php';
            $safetyChecker = new DomainSafetyChecker($this->pdo());
            $result = $safetyChecker->check($domain);
            $this->success($result);
        } else {
            $this->error('域名安全检测服务不可用');
        }
    }
    
    /**
     * 获取域名安全统计
     * GET /api/v2/domains/safety/stats
     */
    public function safetyStats(): void
    {
        $this->requireLogin();
        
        if (file_exists(__DIR__ . '/../../core/domain_safety.php')) {
            require_once __DIR__ . '/../../core/domain_safety.php';
            $safetyChecker = new DomainSafetyChecker($this->pdo());
            $stats = $safetyChecker->getStats();
            $this->success($stats);
        } else {
            $this->error('域名安全检测服务不可用');
        }
    }
    
    /**
     * 获取域名安全日志
     * GET /api/v2/domains/safety/logs
     */
    public function safetyLogs(): void
    {
        $this->requireLogin();
        
        $pagination = $this->pagination();
        $domain = $this->param('domain', '');
        
        $sql = "SELECT * FROM domain_safety_logs";
        $params = [];
        
        if (!empty($domain)) {
            $sql .= " WHERE domain LIKE ?";
            $params[] = "%{$domain}%";
        }
        
        $sql .= " ORDER BY checked_at DESC LIMIT ? OFFSET ?";
        $params[] = $pagination['limit'];
        $params[] = $pagination['offset'];
        
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 获取总数
        $countSql = "SELECT COUNT(*) FROM domain_safety_logs";
        if (!empty($domain)) {
            $countSql .= " WHERE domain LIKE ?";
            $total = $this->pdo()->prepare($countSql);
            $total->execute(["%{$domain}%"]);
        } else {
            $total = $this->pdo()->query($countSql);
        }
        
        $this->paginate($logs, $total->fetchColumn(), $pagination['page'], $pagination['limit']);
    }
    
    /**
     * 批量检测所有域名的安全状态
     * POST /api/v2/domains/safety/check-all
     */
    public function safetyCheckAll(): void
    {
        $this->requireLogin();
        
        if (file_exists(__DIR__ . '/../../core/domain_safety.php')) {
            require_once __DIR__ . '/../../core/domain_safety.php';
            $safetyChecker = new DomainSafetyChecker($this->pdo());
            
            // 获取所有启用的域名
            $domains = $this->jump->getDomains(true);
            $results = [];
            
            foreach ($domains as $domain) {
                $result = $safetyChecker->check($domain['domain']);
                $results[] = [
                    'id' => $domain['id'],
                    'domain' => $domain['domain'],
                    'result' => $result
                ];
            }
            
            $this->success($results);
        } else {
            $this->error('域名安全检测服务不可用');
        }
    }
    
    /**
     * 获取域名安全配置
     * GET /api/v2/domains/safety/config
     */
    public function safetyConfig(): void
    {
        $this->requireLogin();
        
        $config = [
            'auto_check_enabled' => $this->db->getConfig('domain_safety_auto_check', false),
            'check_interval' => $this->db->getConfig('domain_safety_interval', 86400), // 默认24小时
            'notify_on_unsafe' => $this->db->getConfig('domain_safety_notify', true),
            'disable_on_unsafe' => $this->db->getConfig('domain_safety_auto_disable', false),
            'check_services' => $this->db->getConfig('domain_safety_services', ['google', 'phishtank'])
        ];
        
        $this->success($config);
    }
    
    /**
     * 保存域名安全配置
     * POST /api/v2/domains/safety/config
     */
    public function saveSafetyConfig(): void
    {
        $this->requireLogin();
        
        $config = $this->param('config', []);
        
        if (isset($config['auto_check_enabled'])) {
            $this->db->setConfig('domain_safety_auto_check', $config['auto_check_enabled'] ? 1 : 0);
        }
        if (isset($config['check_interval'])) {
            $this->db->setConfig('domain_safety_interval', (int)$config['check_interval']);
        }
        if (isset($config['notify_on_unsafe'])) {
            $this->db->setConfig('domain_safety_notify', $config['notify_on_unsafe'] ? 1 : 0);
        }
        if (isset($config['disable_on_unsafe'])) {
            $this->db->setConfig('domain_safety_auto_disable', $config['disable_on_unsafe'] ? 1 : 0);
        }
        if (isset($config['check_services'])) {
            $this->db->setConfig('domain_safety_services', $config['check_services']);
        }
        
        $this->audit('update_domain_safety_config', 'system');
        $this->success(null, '配置已保存');
    }
    
    /**
     * 获取服务器公网IP
     */
    private function getServerPublicIp(): string
    {
        // 优先从配置读取
        $config = $this->db->getConfig('server', []);
        if (!empty($config['public_ip'])) {
            return $config['public_ip'];
        }
        
        // 尝试从外部服务获取公网IP
        $ip = @file_get_contents('https://api.ipify.org?format=text');
        if ($ip && filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE)) {
            // 缓存到配置
            $this->db->setConfig('server', ['public_ip' => trim($ip)]);
            return trim($ip);
        }
        
        // 备用方案：使用 SERVER_ADDR
        return $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? '';
    }
    
    /**
     * 输出结果
     */
    private function outputResult(array $result): void
    {
        if ($result['success']) {
            $this->success($result['data'] ?? null, $result['message'] ?? '操作成功');
        } else {
            $this->error($result['message'] ?? '操作失败');
        }
    }
}
