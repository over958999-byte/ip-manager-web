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
try {
    $db = Database::getInstance();
} catch (Throwable $e) {
    // 避免直接 500 导致前端只显示“Request failed with status code 500”
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'message' => '数据库连接失败或未初始化，请检查 MySQL 连接与数据库导入（backend/database.sql, backend/init_config.sql）。',
        'detail' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

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
    $allowed_ips = $db->getConfig('admin_allowed_ips', []);
    $secret_key = $db->getConfig('admin_secret_key', '');
    
    // 如果白名单为空，允许所有IP访问
    if (empty($allowed_ips)) {
        return true;
    }
    
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

    case 'domain_check':
        // 检测域名解析状态
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        $domain = trim($input['domain'] ?? $_GET['domain'] ?? '');
        if (empty($domain)) {
            echo json_encode(['success' => false, 'message' => '域名不能为空']);
            exit;
        }
        
        // 移除协议前缀
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');
        
        // 获取服务器IP
        $serverIp = $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? '';
        
        // DNS解析域名
        $resolvedIps = [];
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
        
        echo json_encode([
            'success' => true,
            'data' => [
                'domain' => $domain,
                'server_ip' => $serverIp,
                'resolved_ips' => $resolvedIps,
                'is_resolved' => $isResolved,
                'status' => $isResolved ? 'ok' : (empty($resolvedIps) ? 'not_resolved' : 'wrong_ip')
            ]
        ]);
        break;

    case 'domain_check_all':
        // 批量检测所有域名
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../core/jump.php';
        $jump = new JumpService($db->getPdo());
        
        $domains = $jump->getDomains(false);
        $serverIp = $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? '';
        $results = [];
        
        foreach ($domains as $d) {
            $domain = preg_replace('#^https?://#', '', $d['domain']);
            $domain = rtrim($domain, '/');
            
            $resolvedIps = [];
            $dnsRecords = @dns_get_record($domain, DNS_A);
            if ($dnsRecords) {
                foreach ($dnsRecords as $record) {
                    if (isset($record['ip'])) {
                        $resolvedIps[] = $record['ip'];
                    }
                }
            }
            
            $hostIp = @gethostbyname($domain);
            if ($hostIp !== $domain && !in_array($hostIp, $resolvedIps)) {
                $resolvedIps[] = $hostIp;
            }
            
            $isResolved = in_array($serverIp, $resolvedIps);
            
            $results[$d['id']] = [
                'resolved_ips' => $resolvedIps,
                'is_resolved' => $isResolved,
                'status' => $isResolved ? 'ok' : (empty($resolvedIps) ? 'not_resolved' : 'wrong_ip')
            ];
        }
        
        echo json_encode([
            'success' => true,
            'server_ip' => $serverIp,
            'data' => $results
        ]);
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
        
        // 自动构建前端
        $buildOutput = [];
        $buildSuccess = false;
        $frontendDir = $installDir . '/backend/frontend';
        $distDir = $installDir . '/dist';
        
        if (is_dir($frontendDir) && file_exists($frontendDir . '/package.json')) {
            chdir($frontendDir);
            
            // 检查 node 和 npm 是否可用
            $nodeVersion = shell_exec('node -v 2>&1');
            $npmVersion = shell_exec('npm -v 2>&1');
            
            if ($nodeVersion && $npmVersion && strpos($nodeVersion, 'v') === 0) {
                // 检查 node_modules 是否存在，不存在则安装依赖
                if (!is_dir($frontendDir . '/node_modules')) {
                    $buildOutput[] = '正在安装依赖...';
                    exec('npm install 2>&1', $npmInstallOutput, $npmInstallReturn);
                    $buildOutput = array_merge($buildOutput, $npmInstallOutput);
                    
                    if ($npmInstallReturn !== 0) {
                        $buildOutput[] = '依赖安装失败，跳过编译';
                    }
                }
                
                // 执行编译
                if (is_dir($frontendDir . '/node_modules')) {
                    $buildOutput[] = '正在编译前端...';
                    exec('npm run build 2>&1', $npmBuildOutput, $npmBuildReturn);
                    $buildOutput = array_merge($buildOutput, $npmBuildOutput);
                    
                    if ($npmBuildReturn === 0) {
                        $buildSuccess = true;
                        $buildOutput[] = '前端编译成功！';
                    } else {
                        $buildOutput[] = '前端编译失败';
                    }
                }
            } else {
                $buildOutput[] = '未检测到 Node.js 环境，跳过前端编译';
                $buildOutput[] = '请手动执行: cd backend/frontend && npm install && npm run build';
            }
        }
        
        echo json_encode([
            'success' => true, 
            'message' => '更新成功！' . ($buildSuccess ? '前端已自动重新编译。' : ''),
            'build_success' => $buildSuccess,
            'build_output' => implode("\n", $buildOutput),
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

    // ==================== Cloudflare API ====================
    
    case 'cf_get_config':
        // 获取 Cloudflare 配置
        $cfConfig = $db->getConfig('cloudflare', []);
        echo json_encode([
            'success' => true,
            'config' => [
                'api_token' => !empty($cfConfig['api_token']) ? '********' . substr($cfConfig['api_token'], -4) : '',
                'account_id' => $cfConfig['account_id'] ?? '',
                'configured' => !empty($cfConfig['api_token']) && !empty($cfConfig['account_id'])
            ]
        ]);
        break;
        
    case 'cf_save_config':
        // 保存 Cloudflare 配置
        $apiToken = $input['api_token'] ?? '';
        $accountId = $input['account_id'] ?? '';
        
        if (empty($apiToken) || empty($accountId)) {
            echo json_encode(['success' => false, 'message' => 'API Token 和 Account ID 不能为空']);
            break;
        }
        
        // 如果 Token 是掩码格式（以 ******** 开头），保留原来的值
        if (strpos($apiToken, '********') === 0) {
            $existingConfig = $db->getConfig('cloudflare', []);
            if (!empty($existingConfig['api_token'])) {
                $apiToken = $existingConfig['api_token'];
            } else {
                echo json_encode(['success' => false, 'message' => '请输入完整的 API Token']);
                break;
            }
        }
        
        // 验证 Token
        require_once __DIR__ . '/../core/cloudflare.php';
        $cf = new CloudflareService($apiToken, $accountId);
        $verify = $cf->verifyToken();
        
        if (!$verify['success']) {
            echo json_encode(['success' => false, 'message' => 'API Token 验证失败: ' . $verify['message']]);
            break;
        }
        
        $db->setConfig('cloudflare', [
            'api_token' => $apiToken,
            'account_id' => $accountId
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Cloudflare 配置已保存']);
        break;
        
    case 'cf_list_zones':
        // 获取本系统管理的 Cloudflare 域名列表（从数据库读取）
        $cfConfig = $db->getConfig('cloudflare', []);
        if (empty($cfConfig['api_token'])) {
            echo json_encode(['success' => false, 'message' => '请先配置 Cloudflare API']);
            break;
        }
        
        // 从数据库获取本系统添加的域名
        $stmt = $pdo->query("SELECT * FROM cf_domains ORDER BY created_at DESC");
        $localDomains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 如果有域名，从 Cloudflare API 获取最新状态
        if (!empty($localDomains)) {
            require_once __DIR__ . '/../core/cloudflare.php';
            $cf = new CloudflareService($cfConfig['api_token'], $cfConfig['account_id']);
            
            $zones = [];
            foreach ($localDomains as $d) {
                $zone = [
                    'id' => $d['zone_id'],
                    'name' => $d['domain'],
                    'status' => $d['status'],
                    'name_servers' => json_decode($d['nameservers'] ?: '[]', true),
                    'server_ip' => $d['server_ip'],
                    'https_enabled' => (bool)$d['https_enabled'],
                    'added_to_pool' => (bool)$d['added_to_pool'],
                    'created_at' => $d['created_at']
                ];
                
                // 如果有 zone_id，从 Cloudflare 获取最新状态
                if ($d['zone_id']) {
                    $cfStatus = $cf->getZoneStatus($d['zone_id']);
                    if ($cfStatus) {
                        $zone['status'] = $cfStatus['status'];
                        $zone['name_servers'] = $cfStatus['name_servers'] ?? $zone['name_servers'];
                        // 更新数据库状态
                        $pdo->prepare("UPDATE cf_domains SET status = ?, nameservers = ? WHERE id = ?")
                            ->execute([$cfStatus['status'], json_encode($cfStatus['name_servers'] ?? []), $d['id']]);
                    }
                }
                
                $zones[] = $zone;
            }
            
            echo json_encode(['success' => true, 'zones' => $zones, 'total' => count($zones)]);
        } else {
            echo json_encode(['success' => true, 'zones' => [], 'total' => 0]);
        }
        break;
        
    case 'cf_add_domain':
        // 添加域名到 Cloudflare（一键配置）
        $cfConfig = $db->getConfig('cloudflare', []);
        if (empty($cfConfig['api_token'])) {
            echo json_encode(['success' => false, 'message' => '请先配置 Cloudflare API']);
            break;
        }
        
        $domain = trim($input['domain'] ?? '');
        $enableHttps = $input['enable_https'] ?? true;
        $addToDomainPool = $input['add_to_pool'] ?? true;
        
        if (empty($domain)) {
            echo json_encode(['success' => false, 'message' => '域名不能为空']);
            break;
        }
        
        // 动态获取当前服务器公网IP
        $serverIp = @file_get_contents('https://api.ipify.org') ?: @file_get_contents('https://ifconfig.me/ip');
        if (empty($serverIp)) {
            $serverIp = $_SERVER['SERVER_ADDR'] ?? '';
        }
        $serverIp = trim($serverIp);
        
        if (empty($serverIp)) {
            echo json_encode(['success' => false, 'message' => '无法获取服务器IP']);
            break;
        }
        
        require_once __DIR__ . '/../core/cloudflare.php';
        $cf = new CloudflareService($cfConfig['api_token'], $cfConfig['account_id']);
        $result = $cf->quickSetup($domain, $serverIp, $enableHttps);
        
        // 如果成功，保存到数据库
        if ($result['success']) {
            $rootDomain = $result['root_domain'] ?? $domain;
            $stmt = $pdo->prepare("INSERT INTO cf_domains (domain, zone_id, status, nameservers, server_ip, https_enabled, added_to_pool) 
                VALUES (?, ?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE zone_id = VALUES(zone_id), status = VALUES(status), nameservers = VALUES(nameservers), 
                server_ip = VALUES(server_ip), https_enabled = VALUES(https_enabled), added_to_pool = VALUES(added_to_pool), updated_at = NOW()");
            $stmt->execute([
                $rootDomain,
                $result['zone_id'] ?? null,
                'active',
                json_encode($result['name_servers'] ?? []),
                $serverIp,
                $enableHttps ? 1 : 0,
                $addToDomainPool ? 1 : 0
            ]);
            
            // 如果需要添加到域名池
            if ($addToDomainPool) {
                require_once __DIR__ . '/../core/jump.php';
                $jumpService = new JumpService($db->getPdo());
                $jumpService->addDomain('https://' . $rootDomain, $rootDomain . ' (Cloudflare)', false);
            }
        }
        
        echo json_encode($result);
        break;
        
    case 'cf_batch_add_domains':
        // 批量添加域名到 Cloudflare
        $cfConfig = $db->getConfig('cloudflare', []);
        if (empty($cfConfig['api_token'])) {
            echo json_encode(['success' => false, 'message' => '请先配置 Cloudflare API']);
            break;
        }
        
        $domains = $input['domains'] ?? [];
        $enableHttps = $input['enable_https'] ?? true;
        $addToDomainPool = $input['add_to_pool'] ?? true;
        
        if (empty($domains)) {
            echo json_encode(['success' => false, 'message' => '域名列表不能为空']);
            break;
        }
        
        // 动态获取当前服务器公网IP
        $serverIp = @file_get_contents('https://api.ipify.org') ?: @file_get_contents('https://ifconfig.me/ip');
        if (empty($serverIp)) {
            $serverIp = $_SERVER['SERVER_ADDR'] ?? '';
        }
        $serverIp = trim($serverIp);
        
        if (empty($serverIp)) {
            echo json_encode(['success' => false, 'message' => '无法获取服务器IP']);
            break;
        }
        
        require_once __DIR__ . '/../core/cloudflare.php';
        require_once __DIR__ . '/../core/jump.php';
        
        $cf = new CloudflareService($cfConfig['api_token'], $cfConfig['account_id']);
        $jumpService = new JumpService($db->getPdo());
        
        $results = [];
        $successCount = 0;
        $failCount = 0;
        
        foreach ($domains as $domain) {
            $domain = trim($domain);
            if (empty($domain)) continue;
            
            $result = $cf->quickSetup($domain, $serverIp, $enableHttps);
            
            if ($result['success']) {
                $successCount++;
                if ($addToDomainPool) {
                    $jumpService->addDomain('https://' . $domain, $domain . ' (Cloudflare)', false);
                }
            } else {
                $failCount++;
            }
            
            $results[] = [
                'domain' => $domain,
                'success' => $result['success'],
                'message' => $result['message'] ?? '',
                'name_servers' => $result['name_servers'] ?? []
            ];
            
            // 避免 API 限流
            usleep(500000); // 0.5 秒
        }
        
        echo json_encode([
            'success' => true,
            'results' => $results,
            'summary' => [
                'total' => count($domains),
                'success' => $successCount,
                'failed' => $failCount
            ]
        ]);
        break;
        
    case 'cf_enable_https':
        // 为已有域名开启 HTTPS
        $cfConfig = $db->getConfig('cloudflare', []);
        if (empty($cfConfig['api_token'])) {
            echo json_encode(['success' => false, 'message' => '请先配置 Cloudflare API']);
            break;
        }
        
        $domain = trim($input['domain'] ?? '');
        if (empty($domain)) {
            echo json_encode(['success' => false, 'message' => '域名不能为空']);
            break;
        }
        
        require_once __DIR__ . '/../core/cloudflare.php';
        $cf = new CloudflareService($cfConfig['api_token'], $cfConfig['account_id']);
        
        // 获取 Zone ID
        $zoneId = $cf->getZoneId($domain);
        if (!$zoneId) {
            echo json_encode(['success' => false, 'message' => '域名未在 Cloudflare 中找到']);
            break;
        }
        
        $steps = [];
        
        // 设置 SSL 模式
        $sslResult = $cf->setSslMode($zoneId, 'full');
        $steps[] = ['step' => 'SSL 模式', 'success' => $sslResult['success']];
        
        // 开启始终 HTTPS
        $httpsResult = $cf->enableAlwaysHttps($zoneId);
        $steps[] = ['step' => '始终使用 HTTPS', 'success' => $httpsResult['success']];
        
        // 开启自动重写
        $rewriteResult = $cf->enableAutomaticHttpsRewrites($zoneId);
        $steps[] = ['step' => '自动 HTTPS 重写', 'success' => $rewriteResult['success']];
        
        // 更新数据库中的 HTTPS 状态
        $pdo->prepare("UPDATE cf_domains SET https_enabled = 1 WHERE domain = ?")->execute([$domain]);
        
        echo json_encode([
            'success' => true,
            'steps' => $steps
        ]);
        break;
    
    case 'cf_remove_domain':
        // 从本地记录中删除域名（不删除 Cloudflare 上的）
        $domain = trim($input['domain'] ?? '');
        if (empty($domain)) {
            echo json_encode(['success' => false, 'message' => '域名不能为空']);
            break;
        }
        
        $stmt = $pdo->prepare("DELETE FROM cf_domains WHERE domain = ?");
        $stmt->execute([$domain]);
        
        echo json_encode(['success' => true, 'message' => '域名已从管理列表中移除']);
        break;

    // ==================== 域名安全检测 API ====================
    
    case 'domain_safety_check':
        // 检测单个域名安全状态
        require_once __DIR__ . '/../core/domain_safety.php';
        $checker = new DomainSafetyChecker($db->getPdo());
        
        $domain = trim($input['domain'] ?? '');
        $domainId = intval($input['domain_id'] ?? 0);
        
        if (empty($domain)) {
            echo json_encode(['success' => false, 'message' => '域名不能为空']);
            break;
        }
        
        $result = $checker->checkDomain($domain, $domainId);
        echo json_encode($result);
        break;
        
    case 'domain_safety_check_all':
        // 检测所有域名安全状态
        require_once __DIR__ . '/../core/domain_safety.php';
        $checker = new DomainSafetyChecker($db->getPdo());
        
        $result = $checker->checkAllDomains();
        echo json_encode($result);
        break;
        
    case 'domain_safety_stats':
        // 获取域名安全状态统计
        require_once __DIR__ . '/../core/domain_safety.php';
        $checker = new DomainSafetyChecker($db->getPdo());
        
        $stats = $checker->getStats();
        $dangerDomains = $checker->getDangerDomains();
        
        echo json_encode([
            'success' => true,
            'stats' => $stats,
            'danger_domains' => $dangerDomains
        ]);
        break;
        
    case 'domain_safety_logs':
        // 获取检测日志
        require_once __DIR__ . '/../core/domain_safety.php';
        $checker = new DomainSafetyChecker($db->getPdo());
        
        $limit = intval($input['limit'] ?? 100);
        $logs = $checker->getLogs($limit);
        
        echo json_encode([
            'success' => true,
            'logs' => $logs
        ]);
        break;
        
    case 'domain_safety_config':
        // 获取/保存安全检测配置
        require_once __DIR__ . '/../core/domain_safety.php';
        $checker = new DomainSafetyChecker($db->getPdo());
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($input['config'])) {
            $checker->saveConfig($input['config']);
            echo json_encode(['success' => true, 'message' => '配置已保存']);
        } else {
            echo json_encode([
                'success' => true,
                'config' => $checker->getConfig()
            ]);
        }
        break;

    // =====================================================
    // IP黑名单库管理 API
    // =====================================================
    
    case 'ip_blacklist_list':
        // 获取IP黑名单规则列表
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../../public/ip_blacklist.php';
        $ipBlacklist = IpBlacklist::getInstance();
        
        $filters = [
            'type' => $input['type'] ?? null,
            'category' => $input['category'] ?? null,
            'enabled' => isset($input['enabled']) ? (bool)$input['enabled'] : null,
            'search' => $input['search'] ?? null,
            'limit' => $input['limit'] ?? 200,
            'offset' => $input['offset'] ?? 0
        ];
        
        $rules = $ipBlacklist->getRules($filters);
        $stats = $ipBlacklist->getStats();
        $categories = $ipBlacklist->getCategories();
        
        echo json_encode([
            'success' => true,
            'rules' => $rules,
            'stats' => $stats,
            'categories' => $categories
        ]);
        break;
        
    case 'ip_blacklist_add':
        // 添加IP黑名单规则
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../../public/ip_blacklist.php';
        $ipBlacklist = IpBlacklist::getInstance();
        
        $ipCidr = trim($input['ip_cidr'] ?? '');
        $type = $input['type'] ?? 'custom';
        $category = $input['category'] ?? null;
        $name = $input['name'] ?? null;
        
        if (empty($ipCidr)) {
            echo json_encode(['success' => false, 'message' => 'IP/CIDR不能为空']);
            exit;
        }
        
        // 验证IP格式
        if (strpos($ipCidr, '/') !== false) {
            list($ip, $bits) = explode('/', $ipCidr);
            if (!filter_var($ip, FILTER_VALIDATE_IP) || $bits < 0 || $bits > 32) {
                echo json_encode(['success' => false, 'message' => 'IP/CIDR格式无效']);
                exit;
            }
        } else {
            if (!filter_var($ipCidr, FILTER_VALIDATE_IP)) {
                echo json_encode(['success' => false, 'message' => 'IP格式无效']);
                exit;
            }
        }
        
        if ($ipBlacklist->addRule($ipCidr, $type, $category, $name)) {
            echo json_encode(['success' => true, 'message' => '添加成功']);
        } else {
            echo json_encode(['success' => false, 'message' => '添加失败，可能已存在']);
        }
        break;
        
    case 'ip_blacklist_remove':
        // 删除IP黑名单规则
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../../public/ip_blacklist.php';
        $ipBlacklist = IpBlacklist::getInstance();
        
        $id = intval($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID无效']);
            exit;
        }
        
        if ($ipBlacklist->removeRule($id)) {
            echo json_encode(['success' => true, 'message' => '删除成功']);
        } else {
            echo json_encode(['success' => false, 'message' => '删除失败']);
        }
        break;
        
    case 'ip_blacklist_toggle':
        // 启用/禁用IP黑名单规则
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../../public/ip_blacklist.php';
        $ipBlacklist = IpBlacklist::getInstance();
        
        $id = intval($input['id'] ?? 0);
        $enabled = (bool)($input['enabled'] ?? true);
        
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID无效']);
            exit;
        }
        
        if ($ipBlacklist->toggleRule($id, $enabled)) {
            echo json_encode(['success' => true, 'message' => $enabled ? '已启用' : '已禁用']);
        } else {
            echo json_encode(['success' => false, 'message' => '操作失败']);
        }
        break;
        
    case 'ip_blacklist_check':
        // 检查IP是否在黑名单中
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../../public/ip_blacklist.php';
        
        $ip = trim($input['ip'] ?? '');
        if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            echo json_encode(['success' => false, 'message' => 'IP格式无效']);
            exit;
        }
        
        $result = IpBlacklist::check($ip);
        echo json_encode([
            'success' => true,
            'ip' => $ip,
            'result' => $result
        ]);
        break;
        
    case 'ip_blacklist_import':
        // 批量导入IP黑名单规则
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../../public/ip_blacklist.php';
        $ipBlacklist = IpBlacklist::getInstance();
        
        $rules = $input['rules'] ?? [];
        if (empty($rules) || !is_array($rules)) {
            echo json_encode(['success' => false, 'message' => '规则数据无效']);
            exit;
        }
        
        $result = $ipBlacklist->importRules($rules);
        echo json_encode([
            'success' => true,
            'message' => "导入完成: {$result['success']}成功, {$result['failed']}失败",
            'result' => $result
        ]);
        break;
        
    case 'ip_blacklist_refresh':
        // 强制刷新缓存
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../../public/ip_blacklist.php';
        IpBlacklist::refreshCache();
        echo json_encode(['success' => true, 'message' => '缓存已刷新']);
        break;

    default:
        echo json_encode(['success' => false, 'message' => '未知操作']);
        break;
}
