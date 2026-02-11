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
        $this->success($pool);
    }
    
    /**
     * 批量添加 IP 到池中
     * POST /api/v2/ip-pool
     */
    public function add(): void
    {
        $this->requireLogin();
        
        $ipsText = trim($this->requiredParam('ips', 'IP列表不能为空'));
        
        $ips = preg_split('/[\s,\n\r]+/', $ipsText);
        $ips = array_filter(array_map('trim', $ips));
        
        $added = 0;
        $skipped = 0;
        
        foreach ($ips as $ip) {
            if (empty($ip)) continue;
            
            // 验证 IP 格式
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $skipped++;
                continue;
            }
            
            if ($this->db->isInPool($ip) || $this->db->getRedirect($ip)) {
                $skipped++;
            } elseif ($this->db->addToPool($ip)) {
                $added++;
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
