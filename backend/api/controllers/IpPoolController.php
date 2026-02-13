<?php
/**
 * IP 池控制器
 * 管理 IP 资源池的增删改查
 */

require_once __DIR__ . '/BaseController.php';

class IpPoolController extends BaseController
{
    /**
     * 获取 IP 池列表
     * GET /api/v2/ip-pool
     */
    public function list(): void
    {
        $this->requireLogin();
        
        $pool = $this->db->getIpPool();
        $this->success(['ip_pool' => $pool]);
    }
    
    /**
     * 批量添加 IP 到池中
     * POST /api/v2/ip-pool
     * 
     * 支持的格式：
     * - 单个IP: 38.14.208.66
     * - IP范围简写: 38.14.208.66-126 (表示 38.14.208.66 到 38.14.208.126)
     * - CIDR格式: 38.14.208.0/24
     */
    public function add(): void
    {
        $this->requireLogin();
        
        $ipsText = trim($this->requiredParam('ips', 'IP列表不能为空'));
        
        $lines = preg_split('/[\n\r]+/', $ipsText);
        $lines = array_filter(array_map('trim', $lines));
        
        $added = 0;
        $skipped = 0;
        
        foreach ($lines as $line) {
            if (empty($line)) continue;
            
            // 解析IP（支持多种格式）
            $expandedIps = $this->parseIpInput($line);
            
            foreach ($expandedIps as $ip) {
                // 验证 IP 格式（支持 IP 或 IP:端口）
                if (!$this->validateIpOrIpPort($ip)) {
                    $skipped++;
                    continue;
                }
                
                if ($this->db->isInPool($ip) || $this->db->getRedirect($ip)) {
                    $skipped++;
                } elseif ($this->db->addToPool($ip)) {
                    $added++;
                }
            }
        }
        
        $message = "成功添加 {$added} 个IP到池中";
        if ($skipped > 0) {
            $message .= "，跳过 {$skipped} 个无效或重复IP";
        }
        
        $this->audit('add_to_pool', 'ip_pool', null, ['count' => $added]);
        $this->success(['added' => $added, 'skipped' => $skipped], $message);
    }
    
    /**
     * 解析IP输入，支持多种格式
     * 
     * @param string $input 输入字符串
     * @return array IP数组
     */
    private function parseIpInput(string $input): array
    {
        $input = trim($input);
        
        // 格式1: IP范围简写 38.14.208.66-126 (最后一段范围)
        if (preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3})\.(\d{1,3})-(\d{1,3})$/', $input, $matches)) {
            $prefix = $matches[1];
            $start = (int)$matches[2];
            $end = (int)$matches[3];
            
            if ($start <= $end && $start >= 0 && $end <= 255) {
                $ips = [];
                for ($i = $start; $i <= $end; $i++) {
                    $ips[] = "{$prefix}.{$i}";
                }
                return $ips;
            }
        }
        
        // 格式2: 完整IP范围 38.14.208.66-38.14.208.126
        if (preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})-(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})$/', $input, $matches)) {
            $startIp = $matches[1];
            $endIp = $matches[2];
            
            if (filter_var($startIp, FILTER_VALIDATE_IP) && filter_var($endIp, FILTER_VALIDATE_IP)) {
                $start = ip2long($startIp);
                $end = ip2long($endIp);
                
                if ($start !== false && $end !== false && $start <= $end) {
                    // 限制最大范围为65536个IP，防止内存溢出
                    if (($end - $start) > 65536) {
                        $end = $start + 65536;
                    }
                    $ips = [];
                    for ($i = $start; $i <= $end; $i++) {
                        $ips[] = long2ip($i);
                    }
                    return $ips;
                }
            }
        }
        
        // 格式3: CIDR格式 38.14.208.0/24
        if (preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\/(\d{1,2})$/', $input, $matches)) {
            $ip = $matches[1];
            $cidr = (int)$matches[2];
            
            if (filter_var($ip, FILTER_VALIDATE_IP) && $cidr >= 0 && $cidr <= 32) {
                // 只支持 /24 及以上（最多256个IP），防止内存溢出
                if ($cidr >= 24) {
                    $ipLong = ip2long($ip);
                    $mask = -1 << (32 - $cidr);
                    $network = $ipLong & $mask;
                    $broadcast = $network | (~$mask & 0xFFFFFFFF);
                    
                    $ips = [];
                    for ($i = $network; $i <= $broadcast; $i++) {
                        $ips[] = long2ip($i);
                    }
                    return $ips;
                }
            }
        }
        
        // 格式4: 逗号或空格分隔的多个IP
        if (strpos($input, ',') !== false || strpos($input, ' ') !== false) {
            $parts = preg_split('/[\s,]+/', $input);
            return array_filter(array_map('trim', $parts));
        }
        
        // 默认: 单个IP
        return [$input];
    }
    
    /**
     * 验证IP或IP:端口格式
     * 
     * @param string $input
     * @return bool
     */
    private function validateIpOrIpPort(string $input): bool
    {
        // 格式1: 纯IP
        if (filter_var($input, FILTER_VALIDATE_IP)) {
            return true;
        }
        
        // 格式2: IP:端口
        if (preg_match('/^(.+):(\d+)$/', $input, $matches)) {
            $ip = $matches[1];
            $port = (int)$matches[2];
            return filter_var($ip, FILTER_VALIDATE_IP) && $port > 0 && $port <= 65535;
        }
        
        return false;
    }
    
    /**
     * 从池中移除 IP
     * DELETE /api/v2/ip-pool
     */
    public function remove(): void
    {
        $this->requireLogin();
        
        $ips = $this->param('ips', []);
        $ip = trim($this->param('ip', ''));
        
        if (empty($ips) && !empty($ip)) {
            $ips = [$ip];
        }
        
        if (empty($ips)) {
            $this->error('IP不能为空');
        }
        
        $removed = 0;
        foreach ($ips as $ip) {
            $ip = trim($ip);
            if (!empty($ip) && $this->db->removeFromPool($ip)) {
                $removed++;
            }
        }
        
        $this->audit('remove_from_pool', 'ip_pool', null, ['count' => $removed]);
        $this->success(['removed' => $removed], "已从池中移除 {$removed} 个IP");
    }
    
    /**
     * 清空 IP 池
     * DELETE /api/v2/ip-pool/all
     */
    public function clear(): void
    {
        $this->requireLogin();
        
        $this->db->clearPool();
        $this->audit('clear_pool', 'ip_pool');
        $this->success(null, 'IP池已清空');
    }
    
    /**
     * 从池中激活 IP（分配到跳转规则）
     * POST /api/v2/ip-pool/activate
     */
    public function activate(): void
    {
        $this->requireLogin();
        
        $ips = $this->param('ips', []);
        $url = trim($this->param('url', ''));
        $note = trim($this->param('note', ''));
        
        if (empty($ips) || empty($url)) {
            $this->error('IP和URL不能为空');
        }
        
        // 自动补全 URL
        $url = $this->autoCompleteUrl($url);
        
        $activated = 0;
        foreach ($ips as $ip) {
            $ip = trim($ip);
            if (!empty($ip) && $this->db->isInPool($ip)) {
                $this->db->removeFromPool($ip);
                if ($this->db->addRedirect($ip, $url, $note)) {
                    $activated++;
                }
            }
        }
        
        $this->audit('activate_from_pool', 'ip_pool', null, ['count' => $activated]);
        $this->success(['activated' => $activated], "成功激活 {$activated} 个IP");
    }
    
    /**
     * 将 IP 退回池中
     * POST /api/v2/ip-pool/return
     */
    public function returnToPool(): void
    {
        $this->requireLogin();
        
        $ip = trim($this->requiredParam('ip', 'IP不能为空'));
        
        $this->db->deleteRedirect($ip);
        $this->db->addToPool($ip);
        
        $this->audit('return_to_pool', 'ip_pool', null, ['ip' => $ip]);
        $this->success(null, 'IP已退回池中');
    }
    
    /**
     * 自动补全 URL
     */
    private function autoCompleteUrl(string $url): string
    {
        $url = trim($url);
        if (!empty($url) && !preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        return $url;
    }
}
