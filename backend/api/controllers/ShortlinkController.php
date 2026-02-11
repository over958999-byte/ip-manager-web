<?php
/**
 * 短链接控制器
 * 处理短链接的 CRUD、分组、配置等操作
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../../core/shortlink.php';

class ShortlinkController extends BaseController
{
    private ShortLinkService $shortlink;
    
    public function __construct()
    {
        parent::__construct();
        $this->shortlink = new ShortLinkService($this->pdo());
    }
    
    /**
     * 创建短链接
     * POST /api/v2/shortlinks
     */
    public function create(): void
    {
        $this->requireLogin();
        
        $url = trim($this->requiredParam('url', '目标URL不能为空'));
        
        $options = [
            'title' => trim($this->param('title', '')),
            'group_tag' => trim($this->param('group_tag', 'default')),
            'custom_code' => trim($this->param('custom_code', '')),
            'expire_type' => $this->param('expire_type', 'permanent'),
            'expire_at' => $this->param('expire_at'),
            'max_clicks' => $this->param('max_clicks')
        ];
        
        $result = $this->shortlink->create($url, $options);
        
        if ($result['success']) {
            $this->audit('create_shortlink', 'shortlink', $result['data']['id'] ?? null);
            $this->success($result['data'], $result['message']);
        } else {
            $this->error($result['message']);
        }
    }
    
    /**
     * 获取短链接列表
     * GET /api/v2/shortlinks
     */
    public function list(): void
    {
        $this->requireLogin();
        
        $pagination = $this->pagination(50);
        
        $filters = [
            'group_tag' => $this->param('group_tag', ''),
            'search' => $this->param('search', ''),
            'enabled' => $this->param('enabled') !== null ? (bool)$this->param('enabled') : null,
            'limit' => $pagination['limit'],
            'offset' => $pagination['offset']
        ];
        
        // 清理空值
        $filters = array_filter($filters, fn($v) => $v !== '' && $v !== null);
        
        $result = $this->shortlink->getAll($filters);
        $this->success($result);
    }
    
    /**
     * 获取单个短链接
     * GET /api/v2/shortlinks/{code}
     */
    public function get(string $code): void
    {
        $this->requireLogin();
        
        if (empty($code)) {
            $this->error('短码不能为空');
        }
        
        $link = $this->shortlink->getByCode($code, false);
        if ($link) {
            $link['short_url'] = $this->shortlink->getShortUrl($code);
            $this->success($link);
        } else {
            $this->error('短链接不存在', 404);
        }
    }
    
    /**
     * 更新短链接
     * PUT /api/v2/shortlinks/{id}
     */
    public function update(int $id): void
    {
        $this->requireLogin();
        
        if ($id <= 0) {
            $this->error('ID无效');
        }
        
        $data = [];
        foreach (['original_url', 'title', 'group_tag', 'enabled', 'expire_type', 'expire_at', 'max_clicks'] as $field) {
            if ($this->param($field) !== null) {
                $data[$field] = $this->param($field);
            }
        }
        
        if ($this->shortlink->update($id, $data)) {
            $this->audit('update_shortlink', 'shortlink', $id);
            $this->success(null, '更新成功');
        } else {
            $this->error('更新失败');
        }
    }
    
    /**
     * 删除短链接
     * DELETE /api/v2/shortlinks/{id}
     */
    public function delete(int $id): void
    {
        $this->requireLogin();
        
        if ($this->shortlink->delete($id)) {
            $this->audit('delete_shortlink', 'shortlink', $id);
            $this->success(null, '删除成功');
        } else {
            $this->error('删除失败');
        }
    }
    
    /**
     * 切换短链接状态
     * POST /api/v2/shortlinks/{id}/toggle
     */
    public function toggle(int $id): void
    {
        $this->requireLogin();
        
        $enabled = $this->shortlink->toggle($id);
        if ($enabled !== null) {
            $this->audit('toggle_shortlink', 'shortlink', $id);
            $this->success(['enabled' => $enabled]);
        } else {
            $this->error('操作失败');
        }
    }
    
    /**
     * 获取短链接统计
     * GET /api/v2/shortlinks/{id}/stats
     */
    public function stats(int $id): void
    {
        $this->requireLogin();
        
        if ($id <= 0) {
            $this->error('ID无效');
        }
        
        $days = intval($this->param('days', 7));
        $stats = $this->shortlink->getStats($id, $days);
        $this->success($stats);
    }
    
    /**
     * 批量创建短链接
     * POST /api/v2/shortlinks/batch
     */
    public function batchCreate(): void
    {
        $this->requireLogin();
        
        $urls = $this->param('urls', []);
        if (empty($urls) || !is_array($urls)) {
            $this->error('URLs不能为空');
        }
        
        $defaultOptions = [
            'group_tag' => $this->param('group_tag', 'default'),
            'expire_type' => $this->param('expire_type', 'permanent')
        ];
        
        $results = $this->shortlink->batchCreate($urls, $defaultOptions);
        $successCount = count(array_filter($results, fn($r) => $r['success']));
        
        $this->audit('batch_create_shortlinks', 'shortlink', null, ['count' => $successCount]);
        
        $this->success([
            'results' => $results,
            'success_count' => $successCount,
            'total' => count($urls)
        ], "成功创建 {$successCount} 个短链接");
    }
    
    /**
     * 获取仪表盘统计
     * GET /api/v2/shortlinks/dashboard
     */
    public function dashboard(): void
    {
        $this->requireLogin();
        
        $stats = $this->shortlink->getDashboardStats();
        $this->success($stats);
    }
    
    /**
     * 获取分组列表
     * GET /api/v2/shortlinks/groups
     */
    public function groups(): void
    {
        $this->requireLogin();
        
        $groups = $this->shortlink->getGroups();
        $this->success($groups);
    }
    
    /**
     * 创建分组
     * POST /api/v2/shortlinks/groups
     */
    public function createGroup(): void
    {
        $this->requireLogin();
        
        $tag = trim($this->requiredParam('tag', '标签不能为空'));
        $name = trim($this->requiredParam('name', '名称不能为空'));
        
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $tag)) {
            $this->error('标签只能包含字母数字下划线和横杠');
        }
        
        if ($this->shortlink->addGroup($tag, $name, $this->param('description', ''))) {
            $this->audit('create_group', 'shortlink_group', $tag);
            $this->success(null, '添加成功');
        } else {
            $this->error('添加失败，标签可能已存在');
        }
    }
    
    /**
     * 删除分组
     * DELETE /api/v2/shortlinks/groups/{tag}
     */
    public function deleteGroup(string $tag): void
    {
        $this->requireLogin();
        
        if ($tag === 'default') {
            $this->error('默认分组不能删除');
        }
        
        if ($this->shortlink->deleteGroup($tag)) {
            $this->audit('delete_group', 'shortlink_group', $tag);
            $this->success(null, '删除成功');
        } else {
            $this->error('删除失败');
        }
    }
    
    /**
     * 获取/保存配置
     * GET/POST /api/v2/shortlinks/config
     */
    public function config(): void
    {
        $this->requireLogin();
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // 保存配置
            $config = [
                'default_domain' => $this->param('default_domain', ''),
                'code_length' => intval($this->param('code_length', 6)),
                'allow_custom_code' => (bool)$this->param('allow_custom_code', true),
                'track_clicks' => (bool)$this->param('track_clicks', true)
            ];
            
            $this->db->setConfig('shortlink', $config);
            $this->audit('update_config', 'shortlink_config');
            $this->success(null, '配置已保存');
        } else {
            // 获取配置
            $config = $this->db->getConfig('shortlink', [
                'default_domain' => '',
                'code_length' => 6,
                'allow_custom_code' => true,
                'track_clicks' => true
            ]);
            $this->success($config);
        }
    }
}
