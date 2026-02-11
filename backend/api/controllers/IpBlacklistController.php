<?php
/**
 * IP 黑名单控制器
 * 全局 IP 黑名单管理，与反爬虫的临时封禁不同
 */

require_once __DIR__ . '/BaseController.php';

class IpBlacklistController extends BaseController
{
    private $ipBlacklist = null;
    
    /**
     * 初始化黑名单服务
     */
    private function getBlacklist()
    {
        if ($this->ipBlacklist === null) {
            require_once __DIR__ . '/../../../public/ip_blacklist.php';
            $this->ipBlacklist = IpBlacklist::getInstance();
        }
        return $this->ipBlacklist;
    }
    
    /**
     * 获取黑名单统计
     * GET /api/v2/ip-blacklist/stats
     */
    public function stats(): void
    {
        $this->requireLogin();
        
        $this->success($this->getBlacklist()->getStats());
    }
    
    /**
     * 获取黑名单规则列表
     * GET /api/v2/ip-blacklist
     */
    public function list(): void
    {
        $this->requireLogin();
        
        $filters = [
            'type' => $this->param('type'),
            'category' => $this->param('category'),
            'enabled' => $this->param('enabled') !== null ? (bool)$this->param('enabled') : null,
            'search' => $this->param('search'),
            'limit' => (int)$this->param('limit', 200),
            'offset' => (int)$this->param('offset', 0)
        ];
        
        $blacklist = $this->getBlacklist();
        $rules = $blacklist->getRules($filters);
        $stats = $blacklist->getStats();
        $categories = $blacklist->getCategories();
        
        $this->success([
            'rules' => $rules,
            'stats' => $stats,
            'categories' => $categories
        ]);
    }
    
    /**
     * 添加黑名单规则
     * POST /api/v2/ip-blacklist
     */
    public function add(): void
    {
        $this->requireLogin();
        
        $ipCidr = trim($this->requiredParam('ip_cidr', 'IP/CIDR不能为空'));
        $type = $this->param('type', 'custom');
        $category = $this->param('category');
        $name = $this->param('name');
        
        // 验证 IP 格式
        if (strpos($ipCidr, '/') !== false) {
            list($ip, $bits) = explode('/', $ipCidr);
            if (!filter_var($ip, FILTER_VALIDATE_IP) || $bits < 0 || $bits > 32) {
                $this->error('IP/CIDR格式无效');
            }
        } else {
            if (!filter_var($ipCidr, FILTER_VALIDATE_IP)) {
                $this->error('IP格式无效');
            }
        }
        
        if ($this->getBlacklist()->addRule($ipCidr, $type, $category, $name)) {
            $this->audit('ip_blacklist_add', 'ip_blacklist', null, ['ip_cidr' => $ipCidr]);
            $this->success(null, '添加成功');
        } else {
            $this->error('添加失败，可能已存在');
        }
    }
    
    /**
     * 删除黑名单规则
     * DELETE /api/v2/ip-blacklist/{id}
     */
    public function remove(int $id): void
    {
        $this->requireLogin();
        
        if ($id <= 0) {
            $this->error('ID无效');
        }
        
        if ($this->getBlacklist()->removeRule($id)) {
            $this->audit('ip_blacklist_remove', 'ip_blacklist', $id);
            $this->success(null, '删除成功');
        } else {
            $this->error('删除失败');
        }
    }
    
    /**
     * 启用/禁用黑名单规则
     * PUT /api/v2/ip-blacklist/{id}/toggle
     */
    public function toggle(int $id): void
    {
        $this->requireLogin();
        
        if ($id <= 0) {
            $this->error('ID无效');
        }
        
        $enabled = (bool)$this->param('enabled', true);
        
        if ($this->getBlacklist()->toggleRule($id, $enabled)) {
            $this->audit('ip_blacklist_toggle', 'ip_blacklist', $id, ['enabled' => $enabled]);
            $this->success(['enabled' => $enabled], $enabled ? '已启用' : '已禁用');
        } else {
            $this->error('操作失败');
        }
    }
    
    /**
     * 检查 IP 是否在黑名单中
     * GET /api/v2/ip-blacklist/check
     */
    public function check(): void
    {
        $this->requireLogin();
        
        $ip = trim($this->requiredParam('ip', 'IP不能为空'));
        
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->error('IP格式无效');
        }
        
        require_once __DIR__ . '/../../../public/ip_blacklist.php';
        $result = IpBlacklist::check($ip);
        
        $this->success([
            'ip' => $ip,
            'result' => $result
        ]);
    }
    
    /**
     * 批量导入黑名单规则
     * POST /api/v2/ip-blacklist/import
     */
    public function import(): void
    {
        $this->requireLogin();
        
        $rules = $this->param('rules', []);
        
        if (empty($rules) || !is_array($rules)) {
            $this->error('规则数据无效');
        }
        
        $result = $this->getBlacklist()->importRules($rules);
        
        $this->audit('ip_blacklist_import', 'ip_blacklist', null, ['count' => $result['success']]);
        $this->success(
            $result,
            "导入完成: {$result['success']}成功, {$result['failed']}失败"
        );
    }
    
    /**
     * 刷新缓存
     * POST /api/v2/ip-blacklist/refresh
     */
    public function refresh(): void
    {
        $this->requireLogin();
        
        require_once __DIR__ . '/../../../public/ip_blacklist.php';
        IpBlacklist::refreshCache();
        
        $this->audit('ip_blacklist_refresh', 'ip_blacklist');
        $this->success(null, '缓存已刷新');
    }
    
    /**
     * 同步威胁情报
     * POST /api/v2/ip-blacklist/sync-threat-intel
     */
    public function syncThreatIntel(): void
    {
        $this->requireLogin();
        
        $forceUpdate = (bool)$this->param('force', false);
        $scriptPath = __DIR__ . '/../../cron/sync_threat_intel.php';
        
        if (!file_exists($scriptPath)) {
            $this->error('同步脚本不存在');
        }
        
        // 检查是否正在运行
        $lockFile = sys_get_temp_dir() . '/threat_intel_sync.lock';
        if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 300) {
            $this->error('同步正在进行中，请稍后再试');
        }
        
        // 创建锁文件
        touch($lockFile);
        
        // 后台执行同步
        $cmd = PHP_BINARY . ' ' . escapeshellarg($scriptPath);
        if ($forceUpdate) {
            $cmd .= ' --force';
        }
        
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen("start /B " . $cmd, "r"));
        } else {
            exec($cmd . ' > /dev/null 2>&1 &');
        }
        
        $this->audit('ip_blacklist_sync_threat_intel', 'ip_blacklist');
        $this->success(null, '威胁情报同步已启动，预计需要1-2分钟完成');
    }
}
