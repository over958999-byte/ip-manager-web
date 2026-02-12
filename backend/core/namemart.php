<?php
/**
 * Namemart API 服务
 * 用于批量查询和购买域名
 */

class NamemartService {
    private $memberId;
    private $apiKey;
    private $baseUrl = 'https://api.namemart.com/v2';
    
    public function __construct(string $memberId = '', string $apiKey = '') {
        $this->memberId = $memberId;
        $this->apiKey = $apiKey;
    }
    
    /**
     * 设置 API 凭证
     */
    public function setCredentials(string $memberId, string $apiKey): void {
        $this->memberId = $memberId;
        $this->apiKey = $apiKey;
    }
    
    /**
     * 生成签名
     */
    private function generateSign(string $timestamp): string {
        return md5($this->memberId . $this->apiKey . $timestamp);
    }
    
    /**
     * 获取当前时间戳（UTC+8格式，Namemart API 要求）
     */
    private function getTimestamp(): string {
        $tz = new DateTimeZone('Asia/Shanghai');
        $dt = new DateTime('now', $tz);
        return $dt->format('Y-m-d H:i:s');
    }
    
    /**
     * 发送 API 请求
     */
    private function request(string $endpoint, array $data = []): array {
        $url = $this->baseUrl . $endpoint;
        $timestamp = $this->getTimestamp();
        
        // 添加公共参数
        $data['member_id'] = $this->memberId;
        $data['timestamp'] = $timestamp;
        $data['sign'] = $this->generateSign($timestamp);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json; charset=UTF-8'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['code' => -1, 'message' => '请求失败: ' . $error];
        }
        
        $result = json_decode($response, true);
        if (!$result) {
            return ['code' => -1, 'message' => '响应解析失败: ' . $response];
        }
        
        return $result;
    }
    
    /**
     * 验证 API 配置是否有效（通过查询一个域名测试）
     */
    public function verifyConfig(): array {
        $result = $this->checkDomain('namemart.com');
        if (isset($result['code']) && $result['code'] === 1000) {
            return ['success' => true, 'message' => '配置有效'];
        }
        return ['success' => false, 'message' => $result['message'] ?? '配置无效'];
    }
    
    /**
     * 检查单个域名是否可注册
     */
    public function checkDomain(string $domain, bool $showPrice = true): array {
        return $this->request('/domain/check', [
            'domain' => strtolower(trim($domain)),
            'show_price_flag' => $showPrice
        ]);
    }
    
    /**
     * 批量检查域名
     */
    public function checkDomains(array $domains, bool $showPrice = true): array {
        $results = [];
        foreach ($domains as $domain) {
            $domain = strtolower(trim($domain));
            if (empty($domain)) continue;
            
            $result = $this->checkDomain($domain, $showPrice);
            if ($result['code'] === 1000 && isset($result['data'])) {
                $data = $result['data'];
                $results[] = [
                    'domain' => $data['domain'],
                    'available' => $data['value'] === 0,
                    'status' => $data['value'], // 0=可注册, 1=已注册, 2=不可注册
                    'status_text' => $this->getStatusText($data['value']),
                    'desc' => $data['desc'] ?? '',
                    'price' => $data['normal_price'] ?? $data['price'] ?? null,
                    'price_symbol' => $data['price_symbol'] ?? '$',
                    'is_premium' => isset($data['price']),
                    'min_period' => $data['min_period'] ?? 1
                ];
            } else {
                $results[] = [
                    'domain' => $domain,
                    'available' => false,
                    'status' => -1,
                    'status_text' => '查询失败',
                    'desc' => $result['message'] ?? '未知错误',
                    'price' => null
                ];
            }
        }
        return $results;
    }
    
    /**
     * 获取状态文本
     */
    private function getStatusText(int $value): string {
        switch ($value) {
            case 0: return '可注册';
            case 1: return '已被注册';
            case 2: return '不可注册';
            default: return '未知';
        }
    }
    
    /**
     * 创建联系人
     */
    public function createContact(array $contactData): array {
        $result = $this->request('/contact/create', $contactData);
        
        // 记录API原始返回，便于调试
        error_log('Namemart createContact response: ' . json_encode($result));
        
        if (isset($result['code']) && $result['code'] === 1000) {
            // API 文档返回结构: { code: 1000, data: { contact_id: "xxx" } }
            // 也可能直接是: { code: 1000, contact_id: "xxx" }
            $contactId = $result['data']['contact_id'] 
                ?? $result['contact_id'] 
                ?? $result['data']['contactId']
                ?? $result['contactId']
                ?? null;
            
            if ($contactId) {
                return [
                    'success' => true,
                    'contact_id' => $contactId
                ];
            }
            
            // 返回整个 data 让前端看看实际结构
            return [
                'success' => true,
                'contact_id' => null,
                'raw_data' => $result['data'] ?? $result,
                'message' => 'API返回成功但未找到contact_id字段'
            ];
        }
        return ['success' => false, 'message' => $result['message'] ?? '创建联系人失败', 'raw' => $result];
    }
    
    /**
     * 获取联系人信息
     */
    public function getContactInfo(string $contactId): array {
        return $this->request('/contact/info', ['contact_id' => $contactId]);
    }
    
    /**
     * 注册域名
     */
    public function registerDomain(string $domain, int $year, string $contactId, string $dns1, string $dns2): array {
        $result = $this->request('/domain/create', [
            'domain' => strtolower(trim($domain)),
            'year' => $year,
            'contact_id' => $contactId,
            'dns1' => $dns1,
            'dns2' => $dns2
        ]);
        
        if ($result['code'] === 1000) {
            return [
                'success' => true,
                'async' => $result['async'] ?? false,
                'domain' => $result['data']['domain'] ?? $domain,
                'task_no' => $result['data']['task_no'] ?? null
            ];
        }
        return ['success' => false, 'message' => $result['message'] ?? '注册失败'];
    }
    
    /**
     * 获取域名信息
     */
    public function getDomainInfo(string $domain): array {
        return $this->request('/domain/info', ['domain' => strtolower(trim($domain))]);
    }
    
    /**
     * 更新域名 DNS
     */
    public function updateDns(string $domain, string $dns1, string $dns2, string $dns3 = '', string $dns4 = ''): array {
        $data = [
            'domain' => strtolower(trim($domain)),
            'dns1' => $dns1,
            'dns2' => $dns2
        ];
        if ($dns3) $data['dns3'] = $dns3;
        if ($dns4) $data['dns4'] = $dns4;
        
        $result = $this->request('/domain/updateDns', $data);
        
        if ($result['code'] === 1000) {
            return ['success' => true, 'domain' => $result['data']['domain'] ?? $domain];
        }
        return ['success' => false, 'message' => $result['message'] ?? '更新DNS失败'];
    }
    
    /**
     * 查询任务状态
     */
    public function getTaskStatus(string $taskNo): array {
        $result = $this->request('/domain/task/status', ['task_no' => $taskNo]);
        
        if ($result['code'] === 1000 && isset($result['data'])) {
            return [
                'success' => true,
                'status' => $result['data']['status'],
                'status_text' => $this->getTaskStatusText($result['data']['status']),
                'message' => $result['data']['msg'] ?? ''
            ];
        }
        return ['success' => false, 'message' => $result['message'] ?? '查询失败'];
    }
    
    /**
     * 获取任务状态文本
     */
    private function getTaskStatusText(int $status): string {
        switch ($status) {
            case 0: return '等待执行';
            case 1: return '进行中';
            case 2: return '成功';
            case 3: return '失败';
            default: return '未知';
        }
    }
    
    /**
     * 续费域名
     */
    public function renewDomain(string $domain, int $year, string $expireDate): array {
        $result = $this->request('/domain/renew', [
            'domain' => strtolower(trim($domain)),
            'year' => (string)$year,
            'expire_date' => $expireDate
        ]);
        
        if ($result['code'] === 1000) {
            return [
                'success' => true,
                'async' => $result['async'] ?? false,
                'task_no' => $result['data']['task_no'] ?? null
            ];
        }
        return ['success' => false, 'message' => $result['message'] ?? '续费失败'];
    }
    
    /**
     * 添加解析记录
     */
    public function addDnsRecord(string $domain, string $host, string $type, string $value, int $mx = null): array {
        $data = [
            'domain' => strtolower(trim($domain)),
            'record_host' => $host,
            'record_type' => strtoupper($type),
            'record_value' => $value
        ];
        if ($mx !== null && strtoupper($type) === 'MX') {
            $data['record_mx'] = $mx;
        }
        
        $result = $this->request('/analysis/add', $data);
        return ['success' => $result['code'] === 1000, 'message' => $result['message'] ?? ''];
    }
    
    /**
     * 获取解析记录列表
     */
    public function getDnsRecords(string $domain): array {
        $result = $this->request('/analysis/list', ['domain' => strtolower(trim($domain))]);
        
        if ($result['code'] === 1000 && isset($result['data'])) {
            return [
                'success' => true,
                'domain' => $result['data']['domain'],
                'count' => $result['data']['record_count'] ?? 0,
                'records' => $result['data']['record_detail_list'] ?? []
            ];
        }
        return ['success' => false, 'message' => $result['message'] ?? '获取失败', 'records' => []];
    }
    
    /**
     * 删除解析记录
     */
    public function deleteDnsRecord(string $domain, string $host, string $type, string $value, int $mx = null): array {
        $data = [
            'domain' => strtolower(trim($domain)),
            'record_host' => $host,
            'record_type' => strtoupper($type),
            'record_value' => $value
        ];
        if ($mx !== null) {
            $data['record_mx'] = (string)$mx;
        }
        
        $result = $this->request('/analysis/delete', $data);
        return ['success' => $result['code'] === 1000, 'message' => $result['message'] ?? ''];
    }
    
    /**
     * 清空解析记录
     */
    public function clearDnsRecords(string $domain): array {
        $result = $this->request('/analysis/clean', ['domain' => strtolower(trim($domain))]);
        return ['success' => $result['code'] === 1000, 'message' => $result['message'] ?? ''];
    }
}
