<?php
/**
 * 后台API接口 - 数据库版本
 */

session_start();

require_once __DIR__ . '/../core/database.php';

// CORS支持（开发环境）
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, ['http://localhost:3000', 'http://127.0.0.1:3000'])) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}

// 处理预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// 获取数据库实例
$db = Database::getInstance();

// 获取客户端真实IP
function getClientIp() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    }
    return trim($ip);
}

// 检查后台访问权限
function checkAdminAccess() {
    global $db;
    
    $client_ip = getClientIp();
    $allowed_ips = $db->getConfig('admin_allowed_ips', ['127.0.0.1', '::1']);
    $secret_key = $db->getConfig('admin_secret_key', '');
    
    // 开发环境：允许 localhost 各种形式的访问
    $dev_ips = ['127.0.0.1', '::1', 'localhost', '0.0.0.0'];
    if (in_array($client_ip, $dev_ips)) {
        return true;
    }
    
    // 检查IP白名单
    if (in_array($client_ip, $allowed_ips)) {
        return true;
    }
    
    // 检查密钥参数
    $request_key = $_GET['key'] ?? $_POST['key'] ?? '';
    if (!empty($secret_key) && $secret_key !== 'your_secret_key_here' && $request_key === $secret_key) {
        return true;
    }
    
    return false;
}

// 验证访问权限
if (!checkAdminAccess()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '禁止访问']);
    exit;
}

// 检查登录状态
function checkLogin() {
    global $db;
    
    if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
        return true;
    }
    
    $secret_key = $db->getConfig('admin_secret_key', '');
    $request_key = $_GET['key'] ?? $_POST['key'] ?? '';
    
    if (!empty($secret_key) && $secret_key !== 'your_secret_key_here' && $request_key === $secret_key) {
        $_SESSION['logged_in'] = true;
        return true;
    }
    
    return false;
}

// URL自动补全https
function autoCompleteUrl($url) {
    $url = trim($url);
    if (empty($url)) return $url;
    if (!preg_match('/^https?:\/\//i', $url)) {
        $url = 'https://' . $url;
    }
    return $url;
}

// 获取请求数据
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';

// 处理不同的API请求
switch ($action) {
    case 'login':
        $password = $input['password'] ?? '';
        $admin_password = $db->getConfig('admin_password', 'admin123');
        if ($password === $admin_password) {
            $_SESSION['logged_in'] = true;
            echo json_encode(['success' => true, 'message' => '登录成功']);
        } else {
            echo json_encode(['success' => false, 'message' => '密码错误']);
        }
        break;

    case 'logout':
        session_destroy();
        echo json_encode(['success' => true, 'message' => '已退出登录']);
        break;

    case 'check_login':
        echo json_encode(['logged_in' => checkLogin()]);
        break;

    case 'get_redirects':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $redirects = $db->getRedirects();
        echo json_encode(['success' => true, 'redirects' => $redirects]);
        break;

    case 'add_redirect':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $ip = trim($input['ip'] ?? '');
        $url = autoCompleteUrl($input['url'] ?? '');
        $note = trim($input['note'] ?? '');
        
        if (empty($ip) || empty($url)) {
            echo json_encode(['success' => false, 'message' => 'IP和URL不能为空']);
            exit;
        }
        
        if ($db->addRedirect($ip, $url, $note)) {
            echo json_encode(['success' => true, 'message' => '添加成功']);
        } else {
            echo json_encode(['success' => false, 'message' => '添加失败，IP可能已存在']);
        }
        break;

    case 'update_redirect':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $ip = trim($input['ip'] ?? '');
        
        if (empty($ip)) {
            echo json_encode(['success' => false, 'message' => 'IP不能为空']);
            exit;
        }
        
        $data = [
            'url' => autoCompleteUrl($input['url'] ?? ''),
            'note' => trim($input['note'] ?? ''),
            'enabled' => $input['enabled'] ?? true,
            'block_desktop' => $input['block_desktop'] ?? false,
            'block_ios' => $input['block_ios'] ?? false,
            'block_android' => $input['block_android'] ?? false,
            'country_whitelist_enabled' => $input['country_whitelist_enabled'] ?? false,
            'country_whitelist' => $input['country_whitelist'] ?? []
        ];
        
        if ($db->updateRedirect($ip, $data)) {
            echo json_encode(['success' => true, 'message' => '更新成功']);
        } else {
            echo json_encode(['success' => false, 'message' => '更新失败']);
        }
        break;

    case 'delete_redirect':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $ip = trim($input['ip'] ?? '');
        
        if (empty($ip)) {
            echo json_encode(['success' => false, 'message' => 'IP不能为空']);
            exit;
        }
        
        if ($db->deleteRedirect($ip)) {
            echo json_encode(['success' => true, 'message' => '删除成功']);
        } else {
            echo json_encode(['success' => false, 'message' => '删除失败']);
        }
        break;

    case 'toggle_redirect':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $ip = trim($input['ip'] ?? '');
        
        $enabled = $db->toggleRedirect($ip);
        if ($enabled !== null) {
            echo json_encode(['success' => true, 'message' => '状态已切换', 'enabled' => $enabled]);
        } else {
            echo json_encode(['success' => false, 'message' => '该IP不存在']);
        }
        break;

    case 'batch_add':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $ips = $input['ips'] ?? [];
        $url = autoCompleteUrl($input['url'] ?? '');
        $note = trim($input['note'] ?? '');
        
        if (empty($ips) || empty($url)) {
            echo json_encode(['success' => false, 'message' => 'IP列表和URL不能为空']);
            exit;
        }
        
        $count = 0;
        foreach ($ips as $ip) {
            $ip = trim($ip);
            if (!empty($ip) && $db->addRedirect($ip, $url, $note)) {
                $count++;
            }
        }
        
        echo json_encode(['success' => true, 'message' => "成功添加 {$count} 条记录"]);
        break;

    case 'change_password':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $old_password = $input['old_password'] ?? '';
        $new_password = $input['new_password'] ?? '';
        
        if (empty($new_password)) {
            echo json_encode(['success' => false, 'message' => '新密码不能为空']);
            exit;
        }
        
        $admin_password = $db->getConfig('admin_password', 'admin123');
        if ($old_password !== $admin_password) {
            echo json_encode(['success' => false, 'message' => '原密码错误']);
            exit;
        }
        
        if ($db->setConfig('admin_password', $new_password)) {
            echo json_encode(['success' => true, 'message' => '密码修改成功']);
        } else {
            echo json_encode(['success' => false, 'message' => '保存失败']);
        }
        break;

    case 'export':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $redirects = $db->getRedirects();
        echo json_encode(['success' => true, 'data' => $redirects]);
        break;

    case 'import':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $data = $input['data'] ?? [];
        if (empty($data)) {
            echo json_encode(['success' => false, 'message' => '导入数据为空']);
            exit;
        }
        
        $count = 0;
        foreach ($data as $ip => $info) {
            if (is_array($info) && isset($info['url'])) {
                if ($db->addRedirect($ip, $info['url'], $info['note'] ?? '')) {
                    $db->updateRedirect($ip, $info);
                    $count++;
                }
            }
        }
        
        echo json_encode(['success' => true, 'message' => "成功导入 {$count} 条记录"]);
        break;

    case 'get_stats':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $stats = $db->getVisitStats();
        echo json_encode(['success' => true, 'stats' => $stats]);
        break;

    case 'get_ip_stats':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $ip = trim($input['ip'] ?? $_GET['ip'] ?? '');
        if (empty($ip)) {
            echo json_encode(['success' => false, 'message' => 'IP不能为空']);
            exit;
        }
        $stats = $db->getIpStats($ip);
        echo json_encode(['success' => true, 'stats' => $stats]);
        break;

    case 'clear_stats':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $ip = trim($input['ip'] ?? '');
        $db->clearStats($ip ?: null);
        echo json_encode(['success' => true, 'message' => '统计已清空']);
        break;

    // ========== IP池管理 ==========
    case 'get_ip_pool':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $pool = $db->getIpPool();
        echo json_encode(['success' => true, 'ip_pool' => $pool]);
        break;

    case 'add_to_pool':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $ips_text = trim($input['ips'] ?? '');
        if (empty($ips_text)) {
            echo json_encode(['success' => false, 'message' => 'IP列表不能为空']);
            exit;
        }
        
        $ips = preg_split('/[\s,\n\r]+/', $ips_text);
        $ips = array_filter(array_map('trim', $ips));
        
        $added = 0;
        $skipped = 0;
        foreach ($ips as $ip) {
            if (!empty($ip)) {
                if ($db->isInPool($ip) || $db->getRedirect($ip)) {
                    $skipped++;
                } elseif ($db->addToPool($ip)) {
                    $added++;
                }
            }
        }
        
        echo json_encode(['success' => true, 'message' => "成功添加 {$added} 个IP到池中" . ($skipped > 0 ? "，跳过 {$skipped} 个重复IP" : "")]);
        break;

    case 'remove_from_pool':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $ips = $input['ips'] ?? [];
        if (empty($ips) && !empty($input['ip'])) {
            $ips = [trim($input['ip'])];
        }
        if (empty($ips)) {
            echo json_encode(['success' => false, 'message' => 'IP不能为空']);
            exit;
        }
        
        foreach ($ips as $ip) {
            $db->removeFromPool($ip);
        }
        
        echo json_encode(['success' => true, 'message' => '已从池中移除 ' . count($ips) . ' 个IP']);
        break;

    case 'clear_pool':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $db->clearPool();
        echo json_encode(['success' => true, 'message' => 'IP池已清空']);
        break;

    case 'activate_from_pool':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $ips = $input['ips'] ?? [];
        $url = autoCompleteUrl($input['url'] ?? '');
        $note = trim($input['note'] ?? '');
        
        if (empty($ips) || empty($url)) {
            echo json_encode(['success' => false, 'message' => 'IP和URL不能为空']);
            exit;
        }
        
        $activated = 0;
        foreach ($ips as $ip) {
            $ip = trim($ip);
            if (!empty($ip) && $db->isInPool($ip)) {
                $db->removeFromPool($ip);
                if ($db->addRedirect($ip, $url, $note)) {
                    $activated++;
                }
            }
        }
        
        echo json_encode(['success' => true, 'message' => "成功激活 {$activated} 个IP"]);
        break;

    case 'return_to_pool':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $ip = trim($input['ip'] ?? '');
        if (empty($ip)) {
            echo json_encode(['success' => false, 'message' => 'IP不能为空']);
            exit;
        }
        
        $db->deleteRedirect($ip);
        $db->addToPool($ip);
        
        echo json_encode(['success' => true, 'message' => 'IP已退回池中']);
        break;

    // ========== 反爬虫管理接口 ==========
    case 'get_antibot_stats':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../../public/bad_ips.php';
        
        $stats = $db->getAntibotStats();
        $stats['recent_logs'] = $db->getAntibotLogs(100);
        $blocked = $db->getBlockedList();
        $config = $db->getAntibotConfig();
        $config['ip_blacklist'] = $db->getAntibotBlacklist();
        $config['ip_whitelist'] = $db->getAntibotWhitelist();
        $badIpStats = BadIpDatabase::getStats();
        
        echo json_encode([
            'success' => true,
            'stats' => $stats,
            'blocked_list' => $blocked,
            'config' => $config,
            'bad_ip_stats' => $badIpStats
        ]);
        break;

    case 'get_antibot_config':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $config = $db->getAntibotConfig();
        $config['ip_blacklist'] = $db->getAntibotBlacklist();
        $config['ip_whitelist'] = $db->getAntibotWhitelist();
        echo json_encode([
            'success' => true,
            'config' => $config
        ]);
        break;

    case 'update_antibot_config':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $newConfig = $input['config'] ?? [];
        
        // 分离黑白名单（它们存在单独的表中）
        unset($newConfig['ip_blacklist']);
        unset($newConfig['ip_whitelist']);
        
        $db->updateAntibotConfig($newConfig);
        echo json_encode(['success' => true, 'message' => '配置已更新']);
        break;

    case 'antibot_unblock':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $ip = trim($input['ip'] ?? '');
        if (empty($ip)) {
            echo json_encode(['success' => false, 'message' => 'IP不能为空']);
            exit;
        }
        
        if ($db->unblockIp($ip)) {
            echo json_encode(['success' => true, 'message' => '已解除封禁']);
        } else {
            echo json_encode(['success' => false, 'message' => '解除失败或IP未被封禁']);
        }
        break;

    case 'antibot_clear_blocks':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $db->clearAllBlocks();
        echo json_encode(['success' => true, 'message' => '已清空所有封禁']);
        break;

    case 'antibot_reset_stats':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $db->resetAntibotStats();
        echo json_encode(['success' => true, 'message' => '统计已重置']);
        break;

    case 'antibot_add_blacklist':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $ip = trim($input['ip'] ?? '');
        if (empty($ip)) {
            echo json_encode(['success' => false, 'message' => 'IP不能为空']);
            exit;
        }
        
        $db->addToBlacklist($ip);
        echo json_encode(['success' => true, 'message' => 'IP已加入黑名单']);
        break;

    case 'antibot_remove_blacklist':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $ip = trim($input['ip'] ?? '');
        $db->removeFromBlacklist($ip);
        echo json_encode(['success' => true, 'message' => 'IP已从黑名单移除']);
        break;

    case 'antibot_add_whitelist':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $ip = trim($input['ip'] ?? '');
        if (empty($ip)) {
            echo json_encode(['success' => false, 'message' => 'IP不能为空']);
            exit;
        }
        
        $db->addToWhitelist($ip);
        echo json_encode(['success' => true, 'message' => 'IP已加入白名单']);
        break;

    case 'antibot_remove_whitelist':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        $ip = trim($input['ip'] ?? '');
        $db->removeFromWhitelist($ip);
        echo json_encode(['success' => true, 'message' => 'IP已从白名单移除']);
        break;

    // ==================== 短链服务 API ====================
    
    case 'shortlink_create':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/shortlink.php';
        $shortLink = new ShortLinkService($db->getPdo());
        
        $url = trim($input['url'] ?? '');
        $options = [
            'title' => trim($input['title'] ?? ''),
            'group_tag' => trim($input['group_tag'] ?? 'default'),
            'custom_code' => trim($input['custom_code'] ?? ''),
            'expire_type' => $input['expire_type'] ?? 'permanent',
            'expire_at' => $input['expire_at'] ?? null,
            'max_clicks' => $input['max_clicks'] ?? null
        ];
        
        $result = $shortLink->create($url, $options);
        echo json_encode($result);
        break;

    case 'shortlink_list':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/shortlink.php';
        $shortLink = new ShortLinkService($db->getPdo());
        
        $filters = [
            'group_tag' => $_GET['group_tag'] ?? $input['group_tag'] ?? '',
            'search' => $_GET['search'] ?? $input['search'] ?? '',
            'enabled' => isset($_GET['enabled']) ? (bool)$_GET['enabled'] : (isset($input['enabled']) ? (bool)$input['enabled'] : null),
            'limit' => (int)($_GET['limit'] ?? $input['limit'] ?? 50),
            'offset' => (int)($_GET['offset'] ?? $input['offset'] ?? 0)
        ];
        
        // 清理空值
        $filters = array_filter($filters, fn($v) => $v !== '' && $v !== null);
        
        $result = $shortLink->getAll($filters);
        echo json_encode(['success' => true, 'data' => $result]);
        break;

    case 'shortlink_get':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/shortlink.php';
        $shortLink = new ShortLinkService($db->getPdo());
        
        $code = trim($_GET['code'] ?? $input['code'] ?? '');
        if (empty($code)) {
            echo json_encode(['success' => false, 'message' => '短码不能为空']);
            exit;
        }
        
        $link = $shortLink->getByCode($code, false);
        if ($link) {
            $link['short_url'] = $shortLink->getShortUrl($code);
            echo json_encode(['success' => true, 'data' => $link]);
        } else {
            echo json_encode(['success' => false, 'message' => '短链接不存在']);
        }
        break;

    case 'shortlink_update':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/shortlink.php';
        $shortLink = new ShortLinkService($db->getPdo());
        
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID无效']);
            exit;
        }
        
        $data = [];
        foreach (['original_url', 'title', 'group_tag', 'enabled', 'expire_type', 'expire_at', 'max_clicks'] as $field) {
            if (isset($input[$field])) {
                $data[$field] = $input[$field];
            }
        }
        
        if ($shortLink->update($id, $data)) {
            echo json_encode(['success' => true, 'message' => '更新成功']);
        } else {
            echo json_encode(['success' => false, 'message' => '更新失败']);
        }
        break;

    case 'shortlink_delete':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/shortlink.php';
        $shortLink = new ShortLinkService($db->getPdo());
        
        $id = (int)($input['id'] ?? 0);
        if ($shortLink->delete($id)) {
            echo json_encode(['success' => true, 'message' => '删除成功']);
        } else {
            echo json_encode(['success' => false, 'message' => '删除失败']);
        }
        break;

    case 'shortlink_toggle':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/shortlink.php';
        $shortLink = new ShortLinkService($db->getPdo());
        
        $id = (int)($input['id'] ?? 0);
        $enabled = $shortLink->toggle($id);
        if ($enabled !== null) {
            echo json_encode(['success' => true, 'enabled' => $enabled]);
        } else {
            echo json_encode(['success' => false, 'message' => '操作失败']);
        }
        break;

    case 'shortlink_stats':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/shortlink.php';
        $shortLink = new ShortLinkService($db->getPdo());
        
        $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
        $days = (int)($_GET['days'] ?? $input['days'] ?? 7);
        
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID无效']);
            exit;
        }
        
        $stats = $shortLink->getStats($id, $days);
        echo json_encode(['success' => true, 'data' => $stats]);
        break;

    case 'shortlink_groups':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/shortlink.php';
        $shortLink = new ShortLinkService($db->getPdo());
        
        $groups = $shortLink->getGroups();
        echo json_encode(['success' => true, 'data' => $groups]);
        break;

    case 'shortlink_add_group':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/shortlink.php';
        $shortLink = new ShortLinkService($db->getPdo());
        
        $tag = trim($input['tag'] ?? '');
        $name = trim($input['name'] ?? '');
        
        if (empty($tag) || empty($name)) {
            echo json_encode(['success' => false, 'message' => '标签和名称不能为空']);
            exit;
        }
        
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $tag)) {
            echo json_encode(['success' => false, 'message' => '标签只能包含字母数字下划线和横杠']);
            exit;
        }
        
        if ($shortLink->addGroup($tag, $name, $input['description'] ?? '')) {
            echo json_encode(['success' => true, 'message' => '添加成功']);
        } else {
            echo json_encode(['success' => false, 'message' => '添加失败，标签可能已存在']);
        }
        break;

    case 'shortlink_delete_group':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/shortlink.php';
        $shortLink = new ShortLinkService($db->getPdo());
        
        $tag = trim($input['tag'] ?? '');
        if ($tag === 'default') {
            echo json_encode(['success' => false, 'message' => '默认分组不能删除']);
            exit;
        }
        
        if ($shortLink->deleteGroup($tag)) {
            echo json_encode(['success' => true, 'message' => '删除成功']);
        } else {
            echo json_encode(['success' => false, 'message' => '删除失败']);
        }
        break;

    case 'shortlink_batch_create':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/shortlink.php';
        $shortLink = new ShortLinkService($db->getPdo());
        
        $urls = $input['urls'] ?? [];
        if (empty($urls) || !is_array($urls)) {
            echo json_encode(['success' => false, 'message' => 'URLs不能为空']);
            exit;
        }
        
        $defaultOptions = [
            'group_tag' => $input['group_tag'] ?? 'default',
            'expire_type' => $input['expire_type'] ?? 'permanent'
        ];
        
        $results = $shortLink->batchCreate($urls, $defaultOptions);
        $success = count(array_filter($results, fn($r) => $r['success']));
        
        echo json_encode([
            'success' => true,
            'message' => "成功创建 $success 个短链接",
            'data' => $results
        ]);
        break;

    case 'shortlink_dashboard':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/shortlink.php';
        $shortLink = new ShortLinkService($db->getPdo());
        
        $stats = $shortLink->getDashboardStats();
        echo json_encode(['success' => true, 'data' => $stats]);
        break;

    case 'shortlink_config':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/shortlink.php';
        $shortLink = new ShortLinkService($db->getPdo());
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($input['config'])) {
            // 保存配置
            foreach ($input['config'] as $key => $value) {
                $shortLink->setConfig($key, $value);
            }
            echo json_encode(['success' => true, 'message' => '配置已保存']);
        } else {
            // 获取配置
            $config = [
                'domain' => $shortLink->getConfig('domain', 'http://localhost:8080/public/s/'),
                'code_length' => $shortLink->getConfig('code_length', 6),
                'log_days' => $shortLink->getConfig('log_days', 90)
            ];
            echo json_encode(['success' => true, 'data' => $config]);
        }
        break;

    // ==================== 统一跳转管理 API ====================
    
    case 'jump_list':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/jump.php';
        $jump = new JumpService($db->getPdo());
        
        $filters = [
            'rule_type' => $_GET['rule_type'] ?? '',
            'group_tag' => $_GET['group_tag'] ?? '',
            'search' => $_GET['search'] ?? '',
            'enabled' => isset($_GET['enabled']) ? (bool)$_GET['enabled'] : null
        ];
        
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($_GET['page_size'] ?? 20)));
        $filters['limit'] = $pageSize;
        $filters['offset'] = ($page - 1) * $pageSize;
        
        $data = $jump->getList($filters);
        $total = $jump->getCount($filters);
        
        echo json_encode(['success' => true, 'data' => $data, 'total' => $total]);
        break;

    case 'jump_create':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/jump.php';
        $jump = new JumpService($db->getPdo());
        
        $type = $input['rule_type'] ?? 'code';
        $matchKey = $input['match_key'] ?? '';
        $url = autoCompleteUrl($input['target_url'] ?? '');
        
        $options = [
            'title' => $input['title'] ?? '',
            'note' => $input['note'] ?? '',
            'group_tag' => $input['group_tag'] ?? ($type === 'ip' ? 'ip' : 'shortlink'),
            'enabled' => $input['enabled'] ?? 1,
            'block_desktop' => $input['block_desktop'] ?? 0,
            'block_ios' => $input['block_ios'] ?? 0,
            'block_android' => $input['block_android'] ?? 0,
            'country_whitelist_enabled' => $input['country_whitelist_enabled'] ?? 0,
            'country_whitelist' => $input['country_whitelist'] ?? [],
            'expire_type' => $input['expire_type'] ?? 'permanent',
            'expire_at' => $input['expire_at'] ?? null,
            'max_clicks' => $input['max_clicks'] ?? null
        ];
        
        $result = $jump->create($type, $matchKey, $url, $options);
        echo json_encode($result);
        break;

    case 'jump_update':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/jump.php';
        $jump = new JumpService($db->getPdo());
        
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID无效']);
            exit;
        }
        
        unset($input['id'], $input['action']);
        if (isset($input['target_url'])) {
            $input['target_url'] = autoCompleteUrl($input['target_url']);
        }
        
        $result = $jump->update($id, $input);
        echo json_encode($result);
        break;

    case 'jump_delete':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/jump.php';
        $jump = new JumpService($db->getPdo());
        
        $id = (int)($input['id'] ?? 0);
        $result = $jump->delete($id);
        echo json_encode($result);
        break;

    case 'jump_toggle':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/jump.php';
        $jump = new JumpService($db->getPdo());
        
        $id = (int)($input['id'] ?? 0);
        $result = $jump->toggle($id);
        echo json_encode($result);
        break;

    case 'jump_batch_create':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/jump.php';
        $jump = new JumpService($db->getPdo());
        
        $type = $input['rule_type'] ?? 'code';
        $items = $input['items'] ?? [];
        $targetUrl = autoCompleteUrl($input['target_url'] ?? '');
        $domainId = $input['domain_id'] ?? null;
        
        if (empty($items)) {
            echo json_encode(['success' => false, 'message' => '项目不能为空']);
            exit;
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
        
        $result = $jump->batchCreate($type, $processedItems);
        echo json_encode($result);
        break;

    case 'jump_stats':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/jump.php';
        $jump = new JumpService($db->getPdo());
        
        $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
        $days = (int)($_GET['days'] ?? $input['days'] ?? 7);
        
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID无效']);
            exit;
        }
        
        $result = $jump->getStats($id, $days);
        echo json_encode($result);
        break;

    case 'jump_groups':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/jump.php';
        $jump = new JumpService($db->getPdo());
        
        $groups = $jump->getGroups();
        echo json_encode(['success' => true, 'data' => $groups]);
        break;

    case 'jump_group_create':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/jump.php';
        $jump = new JumpService($db->getPdo());
        
        $tag = trim($input['tag'] ?? '');
        $name = trim($input['name'] ?? '');
        $desc = $input['description'] ?? '';
        
        $result = $jump->createGroup($tag, $name, $desc);
        echo json_encode($result);
        break;

    case 'jump_group_delete':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/jump.php';
        $jump = new JumpService($db->getPdo());
        
        $tag = trim($input['tag'] ?? '');
        $result = $jump->deleteGroup($tag);
        echo json_encode($result);
        break;

    case 'jump_dashboard':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/jump.php';
        $jump = new JumpService($db->getPdo());
        
        $ruleType = $_GET['rule_type'] ?? null;
        $stats = $jump->getDashboardStats($ruleType);
        echo json_encode(['success' => true, 'data' => $stats]);
        break;

    // ==================== 域名池管理 API ====================
    
    case 'domain_list':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/jump.php';
        $jump = new JumpService($db->getPdo());
        
        $enabledOnly = isset($_GET['enabled_only']) && $_GET['enabled_only'];
        $domains = $jump->getDomains($enabledOnly);
        echo json_encode(['success' => true, 'data' => $domains]);
        break;

    case 'domain_add':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/jump.php';
        $jump = new JumpService($db->getPdo());
        
        $domain = trim($input['domain'] ?? '');
        $name = trim($input['name'] ?? '');
        $isDefault = !empty($input['is_default']);
        
        $result = $jump->addDomain($domain, $name, $isDefault);
        echo json_encode($result);
        break;

    case 'domain_update':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/jump.php';
        $jump = new JumpService($db->getPdo());
        
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID无效']);
            exit;
        }
        
        unset($input['id'], $input['action']);
        $result = $jump->updateDomain($id, $input);
        echo json_encode($result);
        break;

    case 'domain_delete':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/jump.php';
        $jump = new JumpService($db->getPdo());
        
        $id = (int)($input['id'] ?? 0);
        $result = $jump->deleteDomain($id);
        echo json_encode($result);
        break;

    // ==================== 系统更新 API ====================
    
    case 'system_check_update':
        // 检查更新（不需要登录）
        $repoUrl = 'https://api.github.com/repos/over958999-byte/ip-manager-web/commits/master';
        $localVersionFile = __DIR__ . '/../../.git/refs/heads/master';
        
        $localVersion = '';
        if (file_exists($localVersionFile)) {
            $localVersion = trim(file_get_contents($localVersionFile));
        }
        
        // 获取远程最新版本
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $repoUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'IP-Manager-Updater');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            echo json_encode([
                'success' => false, 
                'message' => '无法连接到GitHub，请检查网络'
            ]);
            exit;
        }
        
        $data = json_decode($response, true);
        $remoteVersion = $data['sha'] ?? '';
        $commitMessage = $data['commit']['message'] ?? '';
        $commitDate = $data['commit']['committer']['date'] ?? '';
        
        $hasUpdate = !empty($remoteVersion) && $remoteVersion !== $localVersion;
        
        echo json_encode([
            'success' => true,
            'data' => [
                'has_update' => $hasUpdate,
                'local_version' => substr($localVersion, 0, 7),
                'remote_version' => substr($remoteVersion, 0, 7),
                'commit_message' => $commitMessage,
                'commit_date' => $commitDate,
                'current_version' => '1.0.0'
            ]
        ]);
        break;

    case 'system_update':
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        $installDir = realpath(__DIR__ . '/../..');
        
        // 检查是否是git仓库
        if (!is_dir($installDir . '/.git')) {
            echo json_encode([
                'success' => false, 
                'message' => '当前不是Git仓库，无法自动更新。请手动更新或重新部署。'
            ]);
            exit;
        }
        
        // 执行git pull
        $output = [];
        $returnVar = 0;
        
        // 备份配置文件
        $configFile = $installDir . '/backend/core/db_config.php';
        $configBackup = '';
        if (file_exists($configFile)) {
            $configBackup = file_get_contents($configFile);
        }
        
        // 切换到项目目录并执行git操作
        chdir($installDir);
        
        // 获取更新
        exec('git fetch origin 2>&1', $output, $returnVar);
        if ($returnVar !== 0) {
            echo json_encode([
                'success' => false, 
                'message' => 'Git fetch失败: ' . implode("\n", $output)
            ]);
            exit;
        }
        
        // 重置到最新版本
        $output = [];
        exec('git reset --hard origin/master 2>&1', $output, $returnVar);
        if ($returnVar !== 0) {
            echo json_encode([
                'success' => false, 
                'message' => 'Git reset失败: ' . implode("\n", $output)
            ]);
            exit;
        }
        
        // 恢复配置文件
        if (!empty($configBackup)) {
            file_put_contents($configFile, $configBackup);
        }
        
        // 检查是否需要重新构建前端
        $needRebuild = false;
        $frontendDir = $installDir . '/backend/frontend';
        if (is_dir($frontendDir) && file_exists($frontendDir . '/package.json')) {
            // 检查node_modules是否存在
            if (!is_dir($frontendDir . '/node_modules')) {
                $needRebuild = true;
            }
        }
        
        echo json_encode([
            'success' => true, 
            'message' => '更新成功！' . ($needRebuild ? '前端需要重新构建，请执行 npm install && npm run build' : ''),
            'need_rebuild' => $needRebuild,
            'output' => implode("\n", $output)
        ]);
        break;

    case 'system_info':
        // 获取系统信息
        $installDir = realpath(__DIR__ . '/../..');
        $localVersionFile = $installDir . '/.git/refs/heads/master';
        
        $localVersion = '';
        if (file_exists($localVersionFile)) {
            $localVersion = substr(trim(file_get_contents($localVersionFile)), 0, 7);
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'version' => '1.0.0',
                'commit' => $localVersion,
                'php_version' => PHP_VERSION,
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'install_dir' => $installDir,
                'is_git_repo' => is_dir($installDir . '/.git')
            ]
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => '未知操作']);
        break;
}
