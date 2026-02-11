<?php
/**
 * 跳转规则控制器
 * 处理短链接/IP跳转规则的 CRUD 操作
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../../core/jump.php';

class JumpController extends BaseController
{
    private JumpService $jump;
    
    public function __construct()
    {
        parent::__construct();
        $this->jump = new JumpService($this->pdo());
    }
    
    /**
     * 获取规则列表
     * GET /api/v2/jump/rules
     */
    public function list(): void
    {
        $this->requireLogin();
        
        $filters = [
            'rule_type' => $this->param('rule_type', ''),
            'group_tag' => $this->param('group_tag', ''),
            'search' => $this->param('search', ''),
            'enabled' => $this->param('enabled') !== null ? (bool)$this->param('enabled') : null
        ];
        
        $pagination = $this->pagination();
        $filters['limit'] = $pagination['limit'];
        $filters['offset'] = $pagination['offset'];
        
        $data = $this->jump->getList($filters);
        $total = $this->jump->getCount($filters);
        
        $this->paginate($data, $total, $pagination['page'], $pagination['limit']);
    }
    
    /**
     * 创建规则
     * POST /api/v2/jump/rules
     */
    public function create(): void
    {
        $this->requireLogin();
        
        $type = $this->param('rule_type', 'code');
        $matchKey = $this->param('match_key', '');
        $targetUrl = $this->autoCompleteUrl($this->param('target_url', ''));
        
        $options = [
            'title' => $this->param('title', ''),
            'note' => $this->param('note', ''),
            'group_tag' => $this->param('group_tag', $type === 'ip' ? 'ip' : 'shortlink'),
            'domain_id' => $this->param('domain_id'),
            'enabled' => $this->param('enabled', 1),
            'block_desktop' => $this->param('block_desktop', 0),
            'block_ios' => $this->param('block_ios', 0),
            'block_android' => $this->param('block_android', 0),
            'country_whitelist_enabled' => $this->param('country_whitelist_enabled', 0),
            'country_whitelist' => $this->param('country_whitelist', []),
            'expire_type' => $this->param('expire_type', 'permanent'),
            'expire_at' => $this->param('expire_at'),
            'max_clicks' => $this->param('max_clicks')
        ];
        
        $result = $this->jump->create($type, $matchKey, $targetUrl, $options);
        
        if ($result['success']) {
            $this->audit('create_rule', 'jump_rule', $result['data']['id'] ?? null, ['new' => $options]);
        }
        
        $this->outputResult($result);
    }
    
    /**
     * 更新规则
     * PUT /api/v2/jump/rules/{id}
     */
    public function update(int $id): void
    {
        $this->requireLogin();
        
        if ($id <= 0) {
            $this->error('ID无效');
        }
        
        // 获取旧数据用于审计
        $oldRule = $this->jump->getById($id);
        
        $data = $this->input;
        unset($data['id'], $data['action']);
        
        if (isset($data['target_url'])) {
            $data['target_url'] = $this->autoCompleteUrl($data['target_url']);
        }
        
        $result = $this->jump->update($id, $data);
        
        if ($result['success']) {
            $this->audit('update_rule', 'jump_rule', $id, ['old' => $oldRule, 'new' => $data]);
        }
        
        $this->outputResult($result);
    }
    
    /**
     * 删除规则
     * DELETE /api/v2/jump/rules/{id}
     */
    public function delete(int $id): void
    {
        $this->requireLogin();
        
        $oldRule = $this->jump->getById($id);
        $result = $this->jump->delete($id);
        
        if ($result['success']) {
            $this->audit('delete_rule', 'jump_rule', $id, ['old' => $oldRule]);
        }
        
        $this->outputResult($result);
    }
    
    /**
     * 切换规则状态
     * POST /api/v2/jump/rules/{id}/toggle
     */
    public function toggle(int $id): void
    {
        $this->requireLogin();
        
        $result = $this->jump->toggle($id);
        
        if ($result['success']) {
            $this->audit('toggle_rule', 'jump_rule', $id);
        }
        
        $this->outputResult($result);
    }
    
    /**
     * 批量创建规则
     * POST /api/v2/jump/rules/batch
     */
    public function batchCreate(): void
    {
        $this->requireLogin();
        
        $type = $this->param('rule_type', 'code');
        $items = $this->param('items', []);
        $targetUrl = $this->autoCompleteUrl($this->param('target_url', ''));
        $domainId = $this->param('domain_id');
        
        if (empty($items)) {
            $this->error('项目不能为空');
        }
        
        // 处理批量数据
        if ($type === 'code') {
            // 短链批量：items 是原始URL列表
            $processedItems = array_map(function($url) use ($domainId) {
                return ['match_key' => '', 'target_url' => trim($url), 'domain_id' => $domainId];
            }, $items);
        } else {
            // IP批量：items 是IP列表，使用统一的 targetUrl
            $processedItems = array_map(function($ip) use ($targetUrl) {
                return ['match_key' => trim($ip), 'target_url' => $targetUrl];
            }, $items);
        }
        
        $result = $this->jump->batchCreate($type, $processedItems);
        
        if ($result['success']) {
            $this->audit('batch_create_rules', 'jump_rule', null, ['count' => count($items)]);
        }
        
        $this->outputResult($result);
    }
    
    /**
     * 获取规则统计
     * GET /api/v2/jump/rules/{id}/stats
     */
    public function stats(int $id): void
    {
        $this->requireLogin();
        
        if ($id <= 0) {
            $this->error('ID无效');
        }
        
        $days = intval($this->param('days', 7));
        $result = $this->jump->getStats($id, $days);
        
        $this->outputResult($result);
    }
    
    /**
     * 获取仪表盘统计
     * GET /api/v2/jump/dashboard
     */
    public function dashboard(): void
    {
        $this->requireLogin();
        
        $ruleType = $this->param('rule_type');
        $stats = $this->jump->getDashboardStats($ruleType);
        
        $this->success($stats);
    }
    
    /**
     * 获取分组列表
     * GET /api/v2/jump/groups
     */
    public function groups(): void
    {
        $this->requireLogin();
        
        $groups = $this->jump->getGroups();
        $this->success($groups);
    }
    
    /**
     * 创建分组
     * POST /api/v2/jump/groups
     */
    public function createGroup(): void
    {
        $this->requireLogin();
        
        $tag = trim($this->requiredParam('tag', '分组标签不能为空'));
        $name = trim($this->param('name', ''));
        $desc = $this->param('description', '');
        
        $result = $this->jump->createGroup($tag, $name, $desc);
        
        if ($result['success']) {
            $this->audit('create_group', 'jump_group', $tag);
        }
        
        $this->outputResult($result);
    }
    
    /**
     * 删除分组
     * DELETE /api/v2/jump/groups/{tag}
     */
    public function deleteGroup(string $tag): void
    {
        $this->requireLogin();
        
        $result = $this->jump->deleteGroup($tag);
        
        if ($result['success']) {
            $this->audit('delete_group', 'jump_group', $tag);
        }
        
        $this->outputResult($result);
    }
    
    /**
     * 自动补全 URL
     */
    private function autoCompleteUrl(string $url): string
    {
        $url = trim($url);
        if (empty($url)) return '';
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }
        return $url;
    }
    
    /**
     * 输出 JumpService 返回的结果
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
