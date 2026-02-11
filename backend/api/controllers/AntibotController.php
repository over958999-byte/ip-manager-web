<?php
/**
 * 反爬虫控制器
 * 管理反爬虫配置、封禁、黑白名单等
 */

require_once __DIR__ . '/BaseController.php';

class AntibotController extends BaseController
{
    /**
     * 获取反爬统计
     * GET /api/v2/antibot/stats
     */
    public function stats(): void
    {
        $this->requireLogin();
        
        require_once __DIR__ . '/../../../public/bad_ips.php';
        
        $stats = $this->db->getAntibotStats();
        $stats['recent_logs'] = $this->db->getAntibotLogs(100);
        $blocked = $this->db->getBlockedList();
        $config = $this->db->getAntibotConfig();
        $config['ip_blacklist'] = $this->db->getAntibotBlacklist();
        $config['ip_whitelist'] = $this->db->getAntibotWhitelist();
        $badIpStats = BadIpDatabase::getStats();
        
        $this->success([
            'stats' => $stats,
            'blocked_list' => $blocked,
            'config' => $config,
            'bad_ip_stats' => $badIpStats
        ]);
    }
    
    /**
     * 获取反爬配置
     * GET /api/v2/antibot/config
     */
    public function getConfig(): void
    {
        $this->requireLogin();
        
        $config = $this->db->getAntibotConfig();
        $config['ip_blacklist'] = $this->db->getAntibotBlacklist();
        $config['ip_whitelist'] = $this->db->getAntibotWhitelist();
        
        $this->success($config);
    }
    
    /**
     * 更新反爬配置
     * PUT /api/v2/antibot/config
     */
    public function updateConfig(): void
    {
        $this->requireLogin();
        
        $newConfig = $this->param('config', []);
        
        // 分离黑白名单（它们存在单独的表中）
        unset($newConfig['ip_blacklist']);
        unset($newConfig['ip_whitelist']);
        
        $this->db->updateAntibotConfig($newConfig);
        $this->audit('update_antibot_config', 'antibot');
        $this->success(null, '配置已更新');
    }
    
    /**
     * 解除 IP 封禁
     * POST /api/v2/antibot/unblock
     */
    public function unblock(): void
    {
        $this->requireLogin();
        
        $ip = trim($this->requiredParam('ip', 'IP不能为空'));
        
        if ($this->db->unblockIp($ip)) {
            $this->audit('antibot_unblock', 'antibot', null, ['ip' => $ip]);
            $this->success(null, '已解除封禁');
        } else {
            $this->error('解除失败或IP未被封禁');
        }
    }
    
    /**
     * 清空所有封禁
     * DELETE /api/v2/antibot/blocks
     */
    public function clearBlocks(): void
    {
        $this->requireLogin();
        
        $this->db->clearAllBlocks();
        $this->audit('antibot_clear_blocks', 'antibot');
        $this->success(null, '已清空所有封禁');
    }
    
    /**
     * 重置统计
     * POST /api/v2/antibot/reset-stats
     */
    public function resetStats(): void
    {
        $this->requireLogin();
        
        $this->db->resetAntibotStats();
        $this->audit('antibot_reset_stats', 'antibot');
        $this->success(null, '统计已重置');
    }
    
    /**
     * 添加 IP 到黑名单
     * POST /api/v2/antibot/blacklist
     */
    public function addToBlacklist(): void
    {
        $this->requireLogin();
        
        $ip = trim($this->requiredParam('ip', 'IP不能为空'));
        
        $this->db->addToBlacklist($ip);
        $this->audit('antibot_add_blacklist', 'antibot', null, ['ip' => $ip]);
        $this->success(null, 'IP已加入黑名单');
    }
    
    /**
     * 从黑名单移除 IP
     * DELETE /api/v2/antibot/blacklist/{ip}
     */
    public function removeFromBlacklist(string $ip): void
    {
        $this->requireLogin();
        
        $ip = trim($ip);
        $this->db->removeFromBlacklist($ip);
        $this->audit('antibot_remove_blacklist', 'antibot', null, ['ip' => $ip]);
        $this->success(null, 'IP已从黑名单移除');
    }
    
    /**
     * 添加 IP 到白名单
     * POST /api/v2/antibot/whitelist
     */
    public function addToWhitelist(): void
    {
        $this->requireLogin();
        
        $ip = trim($this->requiredParam('ip', 'IP不能为空'));
        
        $this->db->addToWhitelist($ip);
        $this->audit('antibot_add_whitelist', 'antibot', null, ['ip' => $ip]);
        $this->success(null, 'IP已加入白名单');
    }
    
    /**
     * 从白名单移除 IP
     * DELETE /api/v2/antibot/whitelist/{ip}
     */
    public function removeFromWhitelist(string $ip): void
    {
        $this->requireLogin();
        
        $ip = trim($ip);
        $this->db->removeFromWhitelist($ip);
        $this->audit('antibot_remove_whitelist', 'antibot', null, ['ip' => $ip]);
        $this->success(null, 'IP已从白名单移除');
    }
}
