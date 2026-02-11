<?php
/**
 * Namemart 域名购买控制器
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../../core/namemart.php';

class NamemartController extends BaseController
{
    private Namemart $namemart;
    
    public function __construct()
    {
        parent::__construct();
        $this->namemart = new Namemart($this->db);
    }
    
    /**
     * 获取 Namemart 配置
     */
    public function getConfig(): void
    {
        $this->requireLogin();
        
        $config = [
            'api_key' => $this->db->getConfig('namemart_api_key', ''),
            'api_secret' => $this->db->getConfig('namemart_api_secret', ''),
            'enabled' => $this->db->getConfig('namemart_enabled', false)
        ];
        
        // 隐藏敏感信息
        if ($config['api_secret']) {
            $config['api_secret'] = '******' . substr($config['api_secret'], -4);
        }
        
        $this->success($config);
    }
    
    /**
     * 保存 Namemart 配置
     */
    public function saveConfig(): void
    {
        $this->requireLogin();
        
        $apiKey = $this->param('api_key');
        $apiSecret = $this->param('api_secret');
        $enabled = $this->param('enabled');
        
        if ($apiKey !== null) {
            $this->db->setConfig('namemart_api_key', $apiKey);
        }
        if ($apiSecret !== null && strpos($apiSecret, '******') === false) {
            $this->db->setConfig('namemart_api_secret', $apiSecret);
        }
        if ($enabled !== null) {
            $this->db->setConfig('namemart_enabled', $enabled ? 1 : 0);
        }
        
        $this->audit('namemart_config_update', 'namemart');
        $this->success(null, '配置保存成功');
    }
    
    /**
     * 检查域名可用性
     */
    public function checkDomains(): void
    {
        $this->requireLogin();
        
        $domains = $this->requiredParam('domains', '域名列表不能为空');
        
        if (!is_array($domains)) {
            $domains = array_map('trim', explode("\n", $domains));
        }
        
        $domains = array_filter($domains);
        
        if (empty($domains)) {
            $this->error('域名列表不能为空');
        }
        
        try {
            $result = $this->namemart->checkDomains($domains);
            $this->success($result);
        } catch (Exception $e) {
            $this->error('检查失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 注册域名
     */
    public function registerDomains(): void
    {
        $this->requireLogin();
        
        $domains = $this->requiredParam('domains', '域名列表不能为空');
        $contactId = $this->requiredParam('contact_id', '联系人 ID 不能为空');
        $years = $this->param('years', 1);
        
        if (!is_array($domains)) {
            $domains = [$domains];
        }
        
        try {
            $result = $this->namemart->registerDomains($domains, $contactId, $years);
            
            $this->audit('namemart_register', 'namemart', null, ['domains' => $domains]);
            $this->success($result, '注册任务已提交');
        } catch (Exception $e) {
            $this->error('注册失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取任务状态
     */
    public function getTaskStatus(): void
    {
        $this->requireLogin();
        
        $taskNo = $this->requiredParam('taskNo', '任务号不能为空');
        
        try {
            $result = $this->namemart->getTaskStatus($taskNo);
            $this->success($result);
        } catch (Exception $e) {
            $this->error('查询失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取域名信息
     */
    public function getDomainInfo(): void
    {
        $this->requireLogin();
        
        $domain = $this->requiredParam('domain', '域名不能为空');
        
        try {
            $result = $this->namemart->getDomainInfo($domain);
            $this->success($result);
        } catch (Exception $e) {
            $this->error('查询失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 更新域名 DNS
     */
    public function updateDns(): void
    {
        $this->requireLogin();
        
        $domain = $this->requiredParam('domain', '域名不能为空');
        $dns1 = $this->requiredParam('dns1', 'DNS1 不能为空');
        $dns2 = $this->param('dns2', '');
        
        try {
            $result = $this->namemart->updateDns($domain, $dns1, $dns2);
            
            $this->audit('namemart_update_dns', 'namemart', null, ['domain' => $domain]);
            $this->success($result, 'DNS 更新成功');
        } catch (Exception $e) {
            $this->error('更新失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 创建联系人
     */
    public function createContact(): void
    {
        $this->requireLogin();
        
        $data = [
            'first_name' => $this->requiredParam('first_name', '名不能为空'),
            'last_name' => $this->requiredParam('last_name', '姓不能为空'),
            'email' => $this->requiredParam('email', '邮箱不能为空'),
            'phone' => $this->requiredParam('phone', '电话不能为空'),
            'address' => $this->requiredParam('address', '地址不能为空'),
            'city' => $this->requiredParam('city', '城市不能为空'),
            'state' => $this->param('state', ''),
            'country' => $this->requiredParam('country', '国家不能为空'),
            'zip_code' => $this->requiredParam('zip_code', '邮编不能为空')
        ];
        
        try {
            $result = $this->namemart->createContact($data);
            
            $this->audit('namemart_create_contact', 'namemart', null, ['email' => $data['email']]);
            $this->success($result, '联系人创建成功');
        } catch (Exception $e) {
            $this->error('创建失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取联系人信息
     */
    public function getContactInfo(): void
    {
        $this->requireLogin();
        
        $contactId = $this->requiredParam('contactId', '联系人 ID 不能为空');
        
        try {
            $result = $this->namemart->getContactInfo($contactId);
            $this->success($result);
        } catch (Exception $e) {
            $this->error('查询失败: ' . $e->getMessage());
        }
    }
}
