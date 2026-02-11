<?php
/**
 * 后台API接口 - 数据库版本
 */

// 加载核心模块
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/validator.php';
require_once __DIR__ . '/../core/logger.php';

// 全局异常处理
set_exception_handler(function(Throwable $e) {
    Logger::logError('未捕获异常', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    http_response_code(200);
    echo json_encode([
        'success' => false,
        'message' => '服务器内部错误，请稍后重试'
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// 使用安全模块启动 session
$security = Security::getInstance();
$security->startSession();

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

// 获取服务器公网IP（优先从配置读取，否则自动检测）
function getServerPublicIp() {
    global $db;
    
    // 优先从配置读取
    $config = $db->getConfig('server', []);
    if (!empty($config['public_ip'])) {
        return $config['public_ip'];
    }
    
    // 尝试从外部服务获取公网IP
    $ip = @file_get_contents('https://api.ipify.org?format=text');
    if ($ip && filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE)) {
        // 缓存到配置
        $db->setConfig('server', ['public_ip' => trim($ip)]);
        return trim($ip);
    }
    
    // 备用方案：使用 SERVER_ADDR（可能是内网IP）
    return $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? '';
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

// 验证API Token
function validateApiToken($db, $token, $requiredPermission = null) {
    if (empty($token)) {
        return ['valid' => false, 'error' => 'API Token不能为空'];
    }
    
    $pdo = $db->getPdo();
    $stmt = $pdo->prepare("SELECT * FROM api_tokens WHERE token = ?");
    $stmt->execute([$token]);
    $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tokenData) {
        return ['valid' => false, 'error' => '无效的API Token'];
    }
    
    if (!$tokenData['enabled']) {
        return ['valid' => false, 'error' => 'API Token已禁用'];
    }
    
    if ($tokenData['expires_at'] && strtotime($tokenData['expires_at']) < time()) {
        return ['valid' => false, 'error' => 'API Token已过期'];
    }
    
    // 检查权限
    $permissions = json_decode($tokenData['permissions'], true) ?: [];
    if ($requiredPermission && !in_array($requiredPermission, $permissions)) {
        return ['valid' => false, 'error' => '权限不足: ' . $requiredPermission];
    }
    
    // 更新最后使用时间和调用次数
    $pdo->prepare("UPDATE api_tokens SET last_used_at = NOW(), call_count = call_count + 1 WHERE id = ?")
       ->execute([$tokenData['id']]);
    
    return [
        'valid' => true,
        'token_id' => $tokenData['id'],
        'rate_limit' => $tokenData['rate_limit'],
        'permissions' => $permissions
    ];
}

// 检查API速率限制
function checkApiRateLimit($db, $tokenId, $limit) {
    $stmt = $db->getPdo()->prepare("SELECT COUNT(*) FROM api_logs WHERE token_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
    $stmt->execute([$tokenId]);
    $count = $stmt->fetchColumn();
    
    return $count < $limit;
}

// 记录API调用日志
function logApiCall($db, $tokenId, $action, $requestData, $responseCode = 200) {
    $stmt = $db->getPdo()->prepare("INSERT INTO api_logs (token_id, action, request_data, response_code, ip) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $tokenId,
        $action,
        json_encode($requestData),
        $responseCode,
        getClientIp()
    ]);
}

// 获取请求数据
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? $input['action'] ?? '';

// 嘆健康检查和 CSRF token 获取不需要 CSRF 验证
$csrfExemptActions = ['login', 'check_login', 'logout', 'get_csrf_token', 'health'];

// CSRF 保护（仅对 POST 请求）
$csrfEnabled = $db->getConfig('csrf_enabled', false); // 默认关闭，可在设置中开启
if ($csrfEnabled && $_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, $csrfExemptActions)) {
    $csrfToken = $security->getCsrfTokenFromRequest();
    if (!$security->validateCsrfToken($csrfToken)) {
        Logger::logSecurityEvent('CSRF验证失败', ['action' => $action]);
        echo json_encode(['success' => false, 'message' => 'CSRF验证失败，请刷新页面重试']);
        exit;
    }
}

// 处理不同的API请求
switch ($action) {
    // 获取 CSRF Token
    case 'get_csrf_token':
        echo json_encode([
            'success' => true,
            'csrf_token' => $security->getCsrfToken()
        ]);
        break;

    case 'login':
        $password = $input['password'] ?? '';
        $storedPassword = $db->getConfig('admin_password', 'admin123');
        $passwordHash = $db->getConfig('admin_password_hash', '');
        
        $loginSuccess = false;
        
        // 优先使用哈希密码验证
        if (!empty($passwordHash)) {
            $loginSuccess = $security->verifyPassword($password, $passwordHash);
            
            // 检查是否需要重新哈希（算法升级时）
            if ($loginSuccess && $security->needsRehash($passwordHash)) {
                $newHash = $security->hashPassword($password);
                $db->setConfig('admin_password_hash', $newHash);
            }
        } else {
            // 兼容旧版明文密码
            $loginSuccess = ($password === $storedPassword);
            
            // 如果明文密码验证成功，自动迁移到哈希存储
            if ($loginSuccess) {
                $newHash = $security->hashPassword($password);
                $db->setConfig('admin_password_hash', $newHash);
                // 清除明文密码（保留备用）
                // $db->setConfig('admin_password', '');
                Logger::logInfo('密码已自动迁移到哈希存储');
            }
        }
        
        if ($loginSuccess) {
            $_SESSION['logged_in'] = true;
            $_SESSION['login_time'] = time();
            session_regenerate_id(true); // 防止会话固定攻击
            Logger::logInfo('用户登录成功');
            echo json_encode([
                'success' => true, 
                'message' => '登录成功',
                'csrf_token' => $security->getCsrfToken()
            ]);
        } else {
            Logger::logSecurityEvent('登录失败，密码错误');
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
        
        // 密码验证
        $validator = new Validator(['new_password' => $new_password]);
        $validator->required('new_password', '新密码不能为空')
                  ->minLength('new_password', 6, '新密码长度不能少于6个字符');
        
        if ($validator->fails()) {
            echo json_encode(['success' => false, 'message' => $validator->getFirstError()]);
            exit;
        }
        
        // 验证旧密码
        $passwordHash = $db->getConfig('admin_password_hash', '');
        $storedPassword = $db->getConfig('admin_password', 'admin123');
        
        $oldPasswordValid = false;
        if (!empty($passwordHash)) {
            $oldPasswordValid = $security->verifyPassword($old_password, $passwordHash);
        } else {
            $oldPasswordValid = ($old_password === $storedPassword);
        }
        
        if (!$oldPasswordValid) {
            Logger::logSecurityEvent('修改密码失败，原密码错误');
            echo json_encode(['success' => false, 'message' => '原密码错误']);
            exit;
        }
        
        // 保存新密码（哈希存储）
        $newHash = $security->hashPassword($new_password);
        if ($db->setConfig('admin_password_hash', $newHash)) {
            // 清除旧的明文密码
            $db->setConfig('admin_password', '');
            Logger::logInfo('管理员密码已修改');
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
            'domain_id' => $input['domain_id'] ?? null,
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
        
        // 获取服务器公网IP
        $serverIp = getServerPublicIp();
        
        // 生成唯一验证token
        $verifyToken = md5($serverIp . '_ip_manager_' . date('Ymd'));
        
        // 方法1: 使用HTTPS请求验证（支持Cloudflare等CDN）
        $isResolved = false;
        $verifyMethod = 'https';
        $resolvedIps = [];
        
        // 尝试HTTPS验证端点
        $verifyUrl = "https://{$domain}/_verify_server";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $verifyUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'IPManager-Verify/1.0'
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // 检查是否返回正确的验证token
        if ($httpCode === 200 && trim($response) === $verifyToken) {
            $isResolved = true;
            $resolvedIps = ['(通过Cloudflare/CDN)'];
        } else {
            // 方法2: 回退到DNS检查（用于非CDN情况）
            $verifyMethod = 'dns';
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
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'domain' => $domain,
                'server_ip' => $serverIp,
                'resolved_ips' => $resolvedIps,
                'is_resolved' => $isResolved,
                'verify_method' => $verifyMethod,
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
        $serverIp = getServerPublicIp();
        $verifyToken = md5($serverIp . '_ip_manager_' . date('Ymd'));
        $results = [];
        
        foreach ($domains as $d) {
            $domain = preg_replace('#^https?://#', '', $d['domain']);
            $domain = rtrim($domain, '/');
            
            // 跳过IP地址类型的域名
            if (filter_var($domain, FILTER_VALIDATE_IP)) {
                $results[$d['id']] = [
                    'resolved_ips' => [$domain],
                    'is_resolved' => $domain === $serverIp,
                    'verify_method' => 'ip',
                    'status' => $domain === $serverIp ? 'ok' : 'wrong_ip'
                ];
                continue;
            }
            
            $isResolved = false;
            $verifyMethod = 'https';
            $resolvedIps = [];
            
            // 方法1: 使用HTTPS请求验证（支持Cloudflare等CDN）
            $verifyUrl = "https://{$domain}/_verify_server";
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $verifyUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'IPManager-Verify/1.0'
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && trim($response) === $verifyToken) {
                $isResolved = true;
                $resolvedIps = ['(通过Cloudflare/CDN)'];
            } else {
                // 方法2: 回退到DNS检查
                $verifyMethod = 'dns';
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
            }
            
            $results[$d['id']] = [
                'resolved_ips' => $resolvedIps,
                'is_resolved' => $isResolved,
                'verify_method' => $verifyMethod,
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
        $pdo = $db->getPdo();
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
        
        // 获取服务器公网IP
        $serverIp = getServerPublicIp();
        
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
        
        // 获取服务器公网IP
        $serverIp = getServerPublicIp();
        
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
    
    case 'cf_get_dns_records':
        // 获取域名的DNS记录
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        $cfConfig = $db->getConfig('cloudflare', []);
        if (empty($cfConfig['api_token'])) {
            echo json_encode(['success' => false, 'message' => '请先配置 Cloudflare API']);
            break;
        }
        
        $zoneId = trim($input['zone_id'] ?? '');
        if (empty($zoneId)) {
            echo json_encode(['success' => false, 'message' => 'Zone ID 不能为空']);
            break;
        }
        
        require_once __DIR__ . '/../core/cloudflare.php';
        $cf = new CloudflareService($cfConfig['api_token'], $cfConfig['account_id']);
        
        $result = $cf->getDnsRecords($zoneId);
        echo json_encode($result);
        break;
    
    case 'cf_add_dns_record':
        // 添加DNS记录
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        $cfConfig = $db->getConfig('cloudflare', []);
        if (empty($cfConfig['api_token'])) {
            echo json_encode(['success' => false, 'message' => '请先配置 Cloudflare API']);
            break;
        }
        
        $zoneId = trim($input['zone_id'] ?? '');
        $type = strtoupper(trim($input['type'] ?? 'A'));
        $name = trim($input['name'] ?? '');
        $content = trim($input['content'] ?? '');
        $proxied = $input['proxied'] ?? true;
        $ttl = intval($input['ttl'] ?? 1);
        
        if (empty($zoneId) || empty($name) || empty($content)) {
            echo json_encode(['success' => false, 'message' => '参数不完整']);
            break;
        }
        
        require_once __DIR__ . '/../core/cloudflare.php';
        $cf = new CloudflareService($cfConfig['api_token'], $cfConfig['account_id']);
        
        $result = $cf->addDnsRecord($zoneId, $type, $name, $content, $proxied, $ttl);
        echo json_encode($result);
        break;
    
    case 'cf_update_dns_record':
        // 更新DNS记录
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        $cfConfig = $db->getConfig('cloudflare', []);
        if (empty($cfConfig['api_token'])) {
            echo json_encode(['success' => false, 'message' => '请先配置 Cloudflare API']);
            break;
        }
        
        $zoneId = trim($input['zone_id'] ?? '');
        $recordId = trim($input['record_id'] ?? '');
        $type = strtoupper(trim($input['type'] ?? 'A'));
        $name = trim($input['name'] ?? '');
        $content = trim($input['content'] ?? '');
        $proxied = $input['proxied'] ?? true;
        $ttl = intval($input['ttl'] ?? 1);
        
        if (empty($zoneId) || empty($recordId) || empty($name) || empty($content)) {
            echo json_encode(['success' => false, 'message' => '参数不完整']);
            break;
        }
        
        require_once __DIR__ . '/../core/cloudflare.php';
        $cf = new CloudflareService($cfConfig['api_token'], $cfConfig['account_id']);
        
        $result = $cf->updateDnsRecord($zoneId, $recordId, $type, $name, $content, $proxied, $ttl);
        echo json_encode($result);
        break;
    
    case 'cf_delete_dns_record':
        // 删除DNS记录
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        $cfConfig = $db->getConfig('cloudflare', []);
        if (empty($cfConfig['api_token'])) {
            echo json_encode(['success' => false, 'message' => '请先配置 Cloudflare API']);
            break;
        }
        
        $zoneId = trim($input['zone_id'] ?? '');
        $recordId = trim($input['record_id'] ?? '');
        
        if (empty($zoneId) || empty($recordId)) {
            echo json_encode(['success' => false, 'message' => '参数不完整']);
            break;
        }
        
        require_once __DIR__ . '/../core/cloudflare.php';
        $cf = new CloudflareService($cfConfig['api_token'], $cfConfig['account_id']);
        
        $result = $cf->deleteDnsRecord($zoneId, $recordId);
        echo json_encode($result);
        break;
    
    case 'cf_get_zone_details':
        // 获取域名详细信息
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        $cfConfig = $db->getConfig('cloudflare', []);
        if (empty($cfConfig['api_token'])) {
            echo json_encode(['success' => false, 'message' => '请先配置 Cloudflare API']);
            break;
        }
        
        $zoneId = trim($input['zone_id'] ?? '');
        if (empty($zoneId)) {
            echo json_encode(['success' => false, 'message' => 'Zone ID 不能为空']);
            break;
        }
        
        require_once __DIR__ . '/../core/cloudflare.php';
        $cf = new CloudflareService($cfConfig['api_token'], $cfConfig['account_id']);
        
        $result = $cf->getZoneDetails($zoneId);
        echo json_encode($result);
        break;
    
    case 'cf_delete_zone':
        // 删除Cloudflare域名
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        $cfConfig = $db->getConfig('cloudflare', []);
        if (empty($cfConfig['api_token'])) {
            echo json_encode(['success' => false, 'message' => '请先配置 Cloudflare API']);
            break;
        }
        
        $zoneId = trim($input['zone_id'] ?? '');
        $domain = trim($input['domain'] ?? '');
        
        if (empty($zoneId)) {
            echo json_encode(['success' => false, 'message' => 'Zone ID 不能为空']);
            break;
        }
        
        require_once __DIR__ . '/../core/cloudflare.php';
        $cf = new CloudflareService($cfConfig['api_token'], $cfConfig['account_id']);
        
        $result = $cf->deleteZone($zoneId);
        
        if ($result['success'] && $domain) {
            // 从本地数据库中删除
            $stmt = $pdo->prepare("DELETE FROM cf_domains WHERE domain = ?");
            $stmt->execute([$domain]);
        }
        
        echo json_encode($result);
        break;

    // ==================== Namemart 域名购买 API ====================
    
    case 'nm_get_config':
        // 获取 Namemart 配置
        $nmConfig = $db->getConfig('namemart', []);
        echo json_encode([
            'success' => true,
            'config' => [
                'member_id' => $nmConfig['member_id'] ?? '',
                'api_key' => !empty($nmConfig['api_key']) ? '********' . substr($nmConfig['api_key'], -4) : '',
                'contact_id' => $nmConfig['contact_id'] ?? '',
                'default_dns1' => $nmConfig['default_dns1'] ?? 'ns1.domainnamedns.com',
                'default_dns2' => $nmConfig['default_dns2'] ?? 'ns2.domainnamedns.com',
                'configured' => !empty($nmConfig['member_id']) && !empty($nmConfig['api_key'])
            ]
        ]);
        break;
        
    case 'nm_save_config':
        // 保存 Namemart 配置
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        $memberId = trim($input['member_id'] ?? '');
        $apiKey = trim($input['api_key'] ?? '');
        $contactId = trim($input['contact_id'] ?? '');
        $defaultDns1 = trim($input['default_dns1'] ?? 'ns1.domainnamedns.com');
        $defaultDns2 = trim($input['default_dns2'] ?? 'ns2.domainnamedns.com');
        
        if (empty($memberId) || empty($apiKey)) {
            echo json_encode(['success' => false, 'message' => 'Member ID 和 API Key 不能为空']);
            break;
        }
        
        // 如果 API Key 是掩码格式，保留原值
        if (strpos($apiKey, '********') === 0) {
            $existingConfig = $db->getConfig('namemart', []);
            if (!empty($existingConfig['api_key'])) {
                $apiKey = $existingConfig['api_key'];
            } else {
                echo json_encode(['success' => false, 'message' => '请输入完整的 API Key']);
                break;
            }
        }
        
        // 验证配置
        require_once __DIR__ . '/../core/namemart.php';
        $nm = new NamemartService($memberId, $apiKey);
        $verify = $nm->verifyConfig();
        
        if (!$verify['success']) {
            echo json_encode(['success' => false, 'message' => 'API 验证失败: ' . $verify['message']]);
            break;
        }
        
        $db->setConfig('namemart', [
            'member_id' => $memberId,
            'api_key' => $apiKey,
            'contact_id' => $contactId,
            'default_dns1' => $defaultDns1,
            'default_dns2' => $defaultDns2
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Namemart 配置已保存']);
        break;
        
    case 'nm_check_domains':
        // 批量查询域名可注册状态
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        $nmConfig = $db->getConfig('namemart', []);
        if (empty($nmConfig['api_key'])) {
            echo json_encode(['success' => false, 'message' => '请先配置 Namemart API']);
            break;
        }
        
        $domainsText = $input['domains'] ?? '';
        $domains = preg_split('/[\s,;]+/', trim($domainsText), -1, PREG_SPLIT_NO_EMPTY);
        
        if (empty($domains)) {
            echo json_encode(['success' => false, 'message' => '域名列表不能为空']);
            break;
        }
        
        // 限制每次最多查询 50 个域名
        if (count($domains) > 50) {
            $domains = array_slice($domains, 0, 50);
        }
        
        require_once __DIR__ . '/../core/namemart.php';
        $nm = new NamemartService($nmConfig['member_id'], $nmConfig['api_key']);
        
        $results = $nm->checkDomains($domains, true);
        
        echo json_encode([
            'success' => true,
            'results' => $results,
            'total' => count($results),
            'available' => count(array_filter($results, fn($r) => $r['available']))
        ]);
        break;
        
    case 'nm_register_domains':
        // 批量注册域名
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        $nmConfig = $db->getConfig('namemart', []);
        if (empty($nmConfig['api_key'])) {
            echo json_encode(['success' => false, 'message' => '请先配置 Namemart API']);
            break;
        }
        
        if (empty($nmConfig['contact_id'])) {
            echo json_encode(['success' => false, 'message' => '请先配置联系人 ID']);
            break;
        }
        
        $domains = $input['domains'] ?? [];
        $years = intval($input['years'] ?? 1);
        $addToCloudflare = $input['add_to_cloudflare'] ?? false;
        $dns1 = $input['dns1'] ?? $nmConfig['default_dns1'];
        $dns2 = $input['dns2'] ?? $nmConfig['default_dns2'];
        
        if (empty($domains)) {
            echo json_encode(['success' => false, 'message' => '域名列表不能为空']);
            break;
        }
        
        if ($years < 1 || $years > 10) {
            $years = 1;
        }
        
        require_once __DIR__ . '/../core/namemart.php';
        $nm = new NamemartService($nmConfig['member_id'], $nmConfig['api_key']);
        
        // 如果需要添加到 Cloudflare，先获取 Cloudflare NS
        $cfNameservers = null;
        if ($addToCloudflare) {
            $cfConfig = $db->getConfig('cloudflare', []);
            if (!empty($cfConfig['api_token']) && !empty($cfConfig['account_id'])) {
                require_once __DIR__ . '/../core/cloudflare.php';
                $cf = new CloudflareService($cfConfig['api_token'], $cfConfig['account_id']);
            } else {
                $addToCloudflare = false;
            }
        }
        
        $results = [];
        $successCount = 0;
        $failCount = 0;
        
        foreach ($domains as $domain) {
            $domain = strtolower(trim($domain));
            if (empty($domain)) continue;
            
            $useDns1 = $dns1;
            $useDns2 = $dns2;
            $cfInfo = null;
            
            // 如果需要添加到 Cloudflare，先添加域名获取 NS
            if ($addToCloudflare && isset($cf)) {
                $cfResult = $cf->addZone($domain);
                if ($cfResult['success'] && !empty($cfResult['name_servers'])) {
                    $useDns1 = $cfResult['name_servers'][0] ?? $dns1;
                    $useDns2 = $cfResult['name_servers'][1] ?? $dns2;
                    $cfNameservers = $cfResult['name_servers'];
                    $cfInfo = [
                        'success' => true,
                        'zone_id' => $cfResult['zone_id'] ?? null,
                        'name_servers' => $cfResult['name_servers']
                    ];
                    
                    // 保存到 cf_domains 表（Cloudflare管理）
                    $pdo = $db->getPdo();
                    try {
                        $stmt = $pdo->prepare("INSERT INTO cf_domains (domain, zone_id, status, nameservers, server_ip, https_enabled, added_to_pool, created_at) VALUES (?, ?, ?, ?, ?, 0, 0, NOW()) ON DUPLICATE KEY UPDATE zone_id = VALUES(zone_id), nameservers = VALUES(nameservers), status = VALUES(status)");
                        $stmt->execute([
                            $cfResult['root_domain'] ?? $domain,
                            $cfResult['zone_id'],
                            $cfResult['status'] ?? 'pending',
                            json_encode($cfResult['name_servers']),
                            ''
                        ]);
                    } catch (Exception $e) {
                        // 忽略保存失败，不影响主流程
                    }
                } else {
                    $cfInfo = [
                        'success' => false,
                        'message' => $cfResult['message'] ?? '添加到Cloudflare失败'
                    ];
                }
            }
            
            // 注册域名
            $result = $nm->registerDomain($domain, $years, $nmConfig['contact_id'], $useDns1, $useDns2);
            
            if ($result['success']) {
                $successCount++;
                $results[] = [
                    'domain' => $domain,
                    'success' => true,
                    'message' => $result['async'] ? '注册任务已提交（异步）' : '注册成功',
                    'task_no' => $result['task_no'] ?? null,
                    'nameservers' => [$useDns1, $useDns2],
                    'cloudflare' => $addToCloudflare,
                    'cf_info' => $cfInfo
                ];
            } else {
                $failCount++;
                $results[] = [
                    'domain' => $domain,
                    'success' => false,
                    'message' => $result['message']
                ];
            }
            
            // 避免 API 限流
            usleep(300000);
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
        
    case 'nm_get_task_status':
        // 查询任务状态
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        $nmConfig = $db->getConfig('namemart', []);
        if (empty($nmConfig['api_key'])) {
            echo json_encode(['success' => false, 'message' => '请先配置 Namemart API']);
            break;
        }
        
        $taskNo = trim($input['task_no'] ?? '');
        if (empty($taskNo)) {
            echo json_encode(['success' => false, 'message' => '任务号不能为空']);
            break;
        }
        
        require_once __DIR__ . '/../core/namemart.php';
        $nm = new NamemartService($nmConfig['member_id'], $nmConfig['api_key']);
        
        $result = $nm->getTaskStatus($taskNo);
        echo json_encode($result);
        break;
        
    case 'nm_get_domain_info':
        // 获取域名信息
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        $nmConfig = $db->getConfig('namemart', []);
        if (empty($nmConfig['api_key'])) {
            echo json_encode(['success' => false, 'message' => '请先配置 Namemart API']);
            break;
        }
        
        $domain = trim($input['domain'] ?? '');
        if (empty($domain)) {
            echo json_encode(['success' => false, 'message' => '域名不能为空']);
            break;
        }
        
        require_once __DIR__ . '/../core/namemart.php';
        $nm = new NamemartService($nmConfig['member_id'], $nmConfig['api_key']);
        
        $result = $nm->getDomainInfo($domain);
        echo json_encode($result);
        break;
        
    case 'nm_update_dns':
        // 更新域名 DNS
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        $nmConfig = $db->getConfig('namemart', []);
        if (empty($nmConfig['api_key'])) {
            echo json_encode(['success' => false, 'message' => '请先配置 Namemart API']);
            break;
        }
        
        $domain = trim($input['domain'] ?? '');
        $dns1 = trim($input['dns1'] ?? '');
        $dns2 = trim($input['dns2'] ?? '');
        
        if (empty($domain) || empty($dns1) || empty($dns2)) {
            echo json_encode(['success' => false, 'message' => '域名和 DNS 服务器不能为空']);
            break;
        }
        
        require_once __DIR__ . '/../core/namemart.php';
        $nm = new NamemartService($nmConfig['member_id'], $nmConfig['api_key']);
        
        $result = $nm->updateDns($domain, $dns1, $dns2);
        echo json_encode($result);
        break;
        
    case 'nm_create_contact':
        // 创建联系人
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        $nmConfig = $db->getConfig('namemart', []);
        if (empty($nmConfig['api_key'])) {
            echo json_encode(['success' => false, 'message' => '请先配置 Namemart API']);
            break;
        }
        
        $contactData = [
            'template_name' => $input['template_name'] ?? 'DefaultTemplate',
            'contact_type' => intval($input['contact_type'] ?? 0),
            'first_name' => $input['first_name'] ?? '',
            'last_name' => $input['last_name'] ?? '',
            'country_code' => $input['country_code'] ?? 'SG',
            'province' => $input['province'] ?? '',
            'city' => $input['city'] ?? '',
            'street' => $input['street'] ?? '',
            'post_code' => $input['post_code'] ?? '',
            'tel_area_code' => $input['tel_area_code'] ?? '',
            'tel' => $input['tel'] ?? '',
            'fax_area_code' => $input['fax_area_code'] ?? '',
            'fax' => $input['fax'] ?? '',
            'email' => $input['email'] ?? ''
        ];
        
        if ($contactData['contact_type'] == 1) {
            $contactData['org'] = $input['org'] ?? '';
        }
        
        // 验证必填字段
        $required = ['first_name', 'last_name', 'country_code', 'province', 'city', 'street', 'post_code', 'tel_area_code', 'tel', 'email'];
        foreach ($required as $field) {
            if (empty($contactData[$field])) {
                echo json_encode(['success' => false, 'message' => "缺少必填字段: $field"]);
                break 2;
            }
        }
        
        require_once __DIR__ . '/../core/namemart.php';
        $nm = new NamemartService($nmConfig['member_id'], $nmConfig['api_key']);
        
        $result = $nm->createContact($contactData);
        
        if ($result['success']) {
            // 自动保存联系人 ID 到配置
            $nmConfig['contact_id'] = $result['contact_id'];
            $db->setConfig('namemart', $nmConfig);
        }
        
        echo json_encode($result);
        break;
        
    case 'nm_get_contact_info':
        // 获取联系人信息
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        $nmConfig = $db->getConfig('namemart', []);
        if (empty($nmConfig['api_key'])) {
            echo json_encode(['success' => false, 'message' => '请先配置 Namemart API']);
            break;
        }
        
        $contactId = trim($input['contact_id'] ?? $nmConfig['contact_id'] ?? '');
        if (empty($contactId)) {
            echo json_encode(['success' => false, 'message' => '联系人 ID 不能为空']);
            break;
        }
        
        require_once __DIR__ . '/../core/namemart.php';
        $nm = new NamemartService($nmConfig['member_id'], $nmConfig['api_key']);
        
        $result = $nm->getContactInfo($contactId);
        echo json_encode($result);
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
    
    case 'ip_blacklist_stats':
        // 单独获取IP黑名单统计（用于页面自动加载）
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        require_once __DIR__ . '/../../public/ip_blacklist.php';
        $ipBlacklist = IpBlacklist::getInstance();
        
        echo json_encode([
            'success' => true,
            'stats' => $ipBlacklist->getStats()
        ]);
        break;
    
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
        
    case 'ip_blacklist_sync_threat_intel':
        // 同步威胁情报（从公开源获取恶意IP）
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        // 后台异步执行
        $forceUpdate = (bool)($input['force'] ?? false);
        $scriptPath = __DIR__ . '/../cron/sync_threat_intel.php';
        
        if (!file_exists($scriptPath)) {
            echo json_encode(['success' => false, 'message' => '同步脚本不存在']);
            exit;
        }
        
        // 检查是否正在运行
        $lockFile = '/tmp/threat_intel_sync.lock';
        if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 300) {
            echo json_encode(['success' => false, 'message' => '同步正在进行中，请稍后再试']);
            exit;
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
        
        echo json_encode([
            'success' => true, 
            'message' => '威胁情报同步已启动，预计需要1-2分钟完成'
        ]);
        break;

    // ==================== API Token 管理 ====================
    
    case 'api_token_list':
        // 获取API Token列表
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        $stmt = $db->getPdo()->query("SELECT id, name, token, permissions, rate_limit, enabled, last_used_at, call_count, created_at, expires_at, note FROM api_tokens ORDER BY id DESC");
        $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($tokens as &$t) {
            $t['permissions'] = json_decode($t['permissions'], true) ?: [];
            // 隐藏token中间部分
            $t['token_display'] = substr($t['token'], 0, 8) . '****' . substr($t['token'], -8);
        }
        
        echo json_encode(['success' => true, 'data' => $tokens]);
        break;
        
    case 'api_token_create':
        // 创建API Token
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        $name = trim($input['name'] ?? '');
        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Token名称不能为空']);
            exit;
        }
        
        // 生成64位随机Token
        $token = bin2hex(random_bytes(32));
        $permissions = json_encode($input['permissions'] ?? ['shortlink_create', 'shortlink_stats']);
        $rateLimit = intval($input['rate_limit'] ?? 100);
        $expiresAt = !empty($input['expires_at']) ? $input['expires_at'] : null;
        $note = $input['note'] ?? '';
        
        $stmt = $db->getPdo()->prepare("INSERT INTO api_tokens (name, token, permissions, rate_limit, expires_at, note) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $token, $permissions, $rateLimit, $expiresAt, $note]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Token创建成功',
            'data' => [
                'id' => $db->getPdo()->lastInsertId(),
                'token' => $token  // 仅创建时返回完整token
            ]
        ]);
        break;
        
    case 'api_token_update':
        // 更新API Token
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        $id = intval($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID无效']);
            exit;
        }
        
        $updates = [];
        $params = [];
        
        if (isset($input['name'])) {
            $updates[] = 'name = ?';
            $params[] = $input['name'];
        }
        if (isset($input['permissions'])) {
            $updates[] = 'permissions = ?';
            $params[] = json_encode($input['permissions']);
        }
        if (isset($input['rate_limit'])) {
            $updates[] = 'rate_limit = ?';
            $params[] = intval($input['rate_limit']);
        }
        if (isset($input['enabled'])) {
            $updates[] = 'enabled = ?';
            $params[] = intval($input['enabled']);
        }
        if (array_key_exists('expires_at', $input)) {
            $updates[] = 'expires_at = ?';
            $params[] = $input['expires_at'] ?: null;
        }
        if (isset($input['note'])) {
            $updates[] = 'note = ?';
            $params[] = $input['note'];
        }
        
        if (empty($updates)) {
            echo json_encode(['success' => false, 'message' => '没有要更新的字段']);
            exit;
        }
        
        $params[] = $id;
        $sql = "UPDATE api_tokens SET " . implode(', ', $updates) . " WHERE id = ?";
        $db->getPdo()->prepare($sql)->execute($params);
        
        echo json_encode(['success' => true, 'message' => '更新成功']);
        break;
        
    case 'api_token_delete':
        // 删除API Token
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        $id = intval($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID无效']);
            exit;
        }
        
        $db->getPdo()->prepare("DELETE FROM api_tokens WHERE id = ?")->execute([$id]);
        $db->getPdo()->prepare("DELETE FROM api_logs WHERE token_id = ?")->execute([$id]);
        
        echo json_encode(['success' => true, 'message' => '删除成功']);
        break;
        
    case 'api_token_regenerate':
        // 重新生成Token
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        $id = intval($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID无效']);
            exit;
        }
        
        $newToken = bin2hex(random_bytes(32));
        $db->getPdo()->prepare("UPDATE api_tokens SET token = ? WHERE id = ?")->execute([$newToken, $id]);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Token已重新生成',
            'data' => ['token' => $newToken]
        ]);
        break;
        
    case 'api_token_logs':
        // 获取API调用日志
        if (!checkLogin()) {
            echo json_encode(['success' => false, 'message' => '请先登录']);
            exit;
        }
        
        $tokenId = intval($input['token_id'] ?? $_GET['token_id'] ?? 0);
        $limit = intval($input['limit'] ?? $_GET['limit'] ?? 100);
        
        $sql = "SELECT l.*, t.name as token_name FROM api_logs l LEFT JOIN api_tokens t ON l.token_id = t.id";
        $params = [];
        
        if ($tokenId > 0) {
            $sql .= " WHERE l.token_id = ?";
            $params[] = $tokenId;
        }
        
        $sql .= " ORDER BY l.id DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $db->getPdo()->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($logs as &$log) {
            $log['request_data'] = json_decode($log['request_data'], true);
        }
        
        echo json_encode(['success' => true, 'data' => $logs]);
        break;

    // ==================== 外部 API 接口 ====================
    
    case 'external_create_shortlink':
        // 外部API: 创建短链接
        $apiToken = $_SERVER['HTTP_X_API_TOKEN'] ?? $input['api_token'] ?? '';
        $tokenData = validateApiToken($db, $apiToken, 'shortlink_create');
        
        if (!$tokenData['valid']) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => $tokenData['error']]);
            exit;
        }
        
        // 检查速率限制
        if (!checkApiRateLimit($db, $tokenData['token_id'], $tokenData['rate_limit'])) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => '请求过于频繁，请稍后重试']);
            exit;
        }
        
        require_once __DIR__ . '/../core/jump.php';
        $jump = new JumpService($db->getPdo());
        
        $targetUrl = $input['url'] ?? $input['target_url'] ?? '';
        if (empty($targetUrl)) {
            logApiCall($db, $tokenData['token_id'], 'shortlink_create', $input, 400);
            echo json_encode(['success' => false, 'error' => '目标URL不能为空']);
            exit;
        }
        
        $options = [
            'title' => $input['title'] ?? '',
            'note' => $input['note'] ?? '',
            'domain_id' => $input['domain_id'] ?? null,
            'expire_type' => $input['expire_type'] ?? 'permanent',
            'expire_at' => $input['expire_at'] ?? null,
            'max_clicks' => $input['max_clicks'] ?? null
        ];
        
        $result = $jump->create('code', '', autoCompleteUrl($targetUrl), $options);
        
        logApiCall($db, $tokenData['token_id'], 'shortlink_create', $input, $result['success'] ? 200 : 400);
        
        if ($result['success']) {
            $data = $result['data'];
            echo json_encode([
                'success' => true,
                'data' => [
                    'id' => $data['id'],
                    'code' => $data['match_key'],
                    'short_url' => $data['jump_url'],
                    'target_url' => $targetUrl
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => $result['message']]);
        }
        break;
        
    case 'external_get_shortlink':
        // 外部API: 获取短链接信息
        $apiToken = $_SERVER['HTTP_X_API_TOKEN'] ?? $input['api_token'] ?? '';
        $tokenData = validateApiToken($db, $apiToken, 'shortlink_stats');
        
        if (!$tokenData['valid']) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => $tokenData['error']]);
            exit;
        }
        
        if (!checkApiRateLimit($db, $tokenData['token_id'], $tokenData['rate_limit'])) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => '请求过于频繁']);
            exit;
        }
        
        $code = $input['code'] ?? $_GET['code'] ?? '';
        $id = intval($input['id'] ?? $_GET['id'] ?? 0);
        
        if (empty($code) && $id <= 0) {
            echo json_encode(['success' => false, 'error' => '请提供code或id']);
            exit;
        }
        
        require_once __DIR__ . '/../core/jump.php';
        $jump = new JumpService($db->getPdo());
        
        if (!empty($code)) {
            $rule = $jump->getByKey('code', $code);
        } else {
            $rule = $jump->getById($id);
        }
        
        logApiCall($db, $tokenData['token_id'], 'shortlink_get', $input, $rule ? 200 : 404);
        
        if ($rule) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'id' => $rule['id'],
                    'code' => $rule['match_key'],
                    'target_url' => $rule['target_url'],
                    'title' => $rule['title'],
                    'total_clicks' => $rule['total_clicks'] ?? 0,
                    'unique_visitors' => $rule['unique_visitors'] ?? 0,
                    'enabled' => (bool)$rule['enabled'],
                    'created_at' => $rule['created_at']
                ]
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => '短链接不存在']);
        }
        break;
        
    case 'external_list_shortlinks':
        // 外部API: 列出短链接
        $apiToken = $_SERVER['HTTP_X_API_TOKEN'] ?? $input['api_token'] ?? '';
        $tokenData = validateApiToken($db, $apiToken, 'shortlink_stats');
        
        if (!$tokenData['valid']) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => $tokenData['error']]);
            exit;
        }
        
        if (!checkApiRateLimit($db, $tokenData['token_id'], $tokenData['rate_limit'])) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => '请求过于频繁']);
            exit;
        }
        
        $page = max(1, intval($input['page'] ?? $_GET['page'] ?? 1));
        $limit = min(100, max(1, intval($input['limit'] ?? $_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        
        $pdo = $db->getPdo();
        $stmt = $pdo->query("SELECT COUNT(*) FROM jump_rules WHERE rule_type = 'code'");
        $total = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT id, match_key as code, target_url, title, total_clicks, unique_visitors, enabled, created_at FROM jump_rules WHERE rule_type = 'code' ORDER BY id DESC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        logApiCall($db, $tokenData['token_id'], 'shortlink_list', $input, 200);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'items' => $rules,
                'total' => (int)$total,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($total / $limit)
            ]
        ]);
        break;
        
    case 'external_delete_shortlink':
        // 外部API: 删除短链接
        $apiToken = $_SERVER['HTTP_X_API_TOKEN'] ?? $input['api_token'] ?? '';
        $tokenData = validateApiToken($db, $apiToken, 'shortlink_delete');
        
        if (!$tokenData['valid']) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => $tokenData['error']]);
            exit;
        }
        
        if (!checkApiRateLimit($db, $tokenData['token_id'], $tokenData['rate_limit'])) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => '请求过于频繁']);
            exit;
        }
        
        $id = intval($input['id'] ?? 0);
        $code = $input['code'] ?? '';
        
        if ($id <= 0 && empty($code)) {
            echo json_encode(['success' => false, 'error' => '请提供id或code']);
            exit;
        }
        
        require_once __DIR__ . '/../core/jump.php';
        $jump = new JumpService($db->getPdo());
        
        if (!empty($code)) {
            $rule = $jump->getByKey('code', $code);
            if ($rule) $id = $rule['id'];
        }
        
        if ($id > 0) {
            $result = $jump->delete($id);
            logApiCall($db, $tokenData['token_id'], 'shortlink_delete', $input, $result['success'] ? 200 : 400);
            echo json_encode($result);
        } else {
            logApiCall($db, $tokenData['token_id'], 'shortlink_delete', $input, 404);
            echo json_encode(['success' => false, 'error' => '短链接不存在']);
        }
        break;
        
    case 'external_list_domains':
        // 外部API: 获取域名列表
        $apiToken = $_SERVER['HTTP_X_API_TOKEN'] ?? $input['api_token'] ?? '';
        $tokenData = validateApiToken($db, $apiToken, 'shortlink_create');
        
        if (!$tokenData['valid']) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => $tokenData['error']]);
            exit;
        }
        
        if (!checkApiRateLimit($db, $tokenData['token_id'], $tokenData['rate_limit'])) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => '请求过于频繁']);
            exit;
        }
        
        $stmt = $db->getPdo()->query("SELECT id, domain, name, is_default, enabled FROM jump_domains WHERE enabled = 1 ORDER BY is_default DESC, id ASC");
        $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 处理域名格式
        foreach ($domains as &$d) {
            $d['is_default'] = (bool)$d['is_default'];
            $d['enabled'] = (bool)$d['enabled'];
        }
        
        logApiCall($db, $tokenData['token_id'], 'list_domains', $input, 200);
        
        echo json_encode([
            'success' => true,
            'data' => $domains
        ]);
        break;
        
    case 'external_get_stats':
        // 外部API: 获取短链接点击统计
        $apiToken = $_SERVER['HTTP_X_API_TOKEN'] ?? $input['api_token'] ?? '';
        $tokenData = validateApiToken($db, $apiToken, 'shortlink_stats');
        
        if (!$tokenData['valid']) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => $tokenData['error']]);
            exit;
        }
        
        if (!checkApiRateLimit($db, $tokenData['token_id'], $tokenData['rate_limit'])) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => '请求过于频繁']);
            exit;
        }
        
        $code = $input['code'] ?? $_GET['code'] ?? '';
        $id = intval($input['id'] ?? $_GET['id'] ?? 0);
        
        if (empty($code) && $id <= 0) {
            echo json_encode(['success' => false, 'error' => '请提供code或id']);
            exit;
        }
        
        require_once __DIR__ . '/../core/jump.php';
        $jump = new JumpService($db->getPdo());
        
        if (!empty($code)) {
            $rule = $jump->getByKey('code', $code);
        } else {
            $rule = $jump->getById($id);
        }
        
        if (!$rule) {
            logApiCall($db, $tokenData['token_id'], 'get_stats', $input, 404);
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => '短链接不存在']);
            exit;
        }
        
        // 获取最近7天的点击趋势 (如果有click_logs表)
        $dailyStats = [];
        try {
            $stmt = $db->getPdo()->prepare("
                SELECT DATE(created_at) as date, COUNT(*) as clicks 
                FROM click_logs 
                WHERE rule_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY DATE(created_at)
                ORDER BY date ASC
            ");
            $stmt->execute([$rule['id']]);
            $dailyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // click_logs表可能不存在，忽略错误
        }
        
        logApiCall($db, $tokenData['token_id'], 'get_stats', $input, 200);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'id' => $rule['id'],
                'code' => $rule['match_key'],
                'target_url' => $rule['target_url'],
                'title' => $rule['title'] ?? '',
                'total_clicks' => (int)($rule['total_clicks'] ?? 0),
                'unique_visitors' => (int)($rule['unique_visitors'] ?? 0),
                'enabled' => (bool)$rule['enabled'],
                'created_at' => $rule['created_at'],
                'daily_stats' => $dailyStats
            ]
        ]);
        break;
        
    case 'external_batch_stats':
        // 外部API: 批量获取短链接统计
        $apiToken = $_SERVER['HTTP_X_API_TOKEN'] ?? $input['api_token'] ?? '';
        $tokenData = validateApiToken($db, $apiToken, 'shortlink_stats');
        
        if (!$tokenData['valid']) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => $tokenData['error']]);
            exit;
        }
        
        if (!checkApiRateLimit($db, $tokenData['token_id'], $tokenData['rate_limit'])) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => '请求过于频繁']);
            exit;
        }
        
        $codes = $input['codes'] ?? [];
        $ids = $input['ids'] ?? [];
        
        if (empty($codes) && empty($ids)) {
            echo json_encode(['success' => false, 'error' => '请提供codes或ids数组']);
            exit;
        }
        
        $results = [];
        
        $pdo = $db->getPdo();
        if (!empty($codes)) {
            $placeholders = implode(',', array_fill(0, count($codes), '?'));
            $stmt = $pdo->prepare("SELECT id, match_key as code, target_url, title, total_clicks, unique_visitors, enabled, created_at FROM jump_rules WHERE match_key IN ($placeholders)");
            $stmt->execute($codes);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, match_key as code, target_url, title, total_clicks, unique_visitors, enabled, created_at FROM jump_rules WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // 格式化结果
        foreach ($results as &$r) {
            $r['total_clicks'] = (int)$r['total_clicks'];
            $r['unique_visitors'] = (int)$r['unique_visitors'];
            $r['enabled'] = (bool)$r['enabled'];
        }
        
        logApiCall($db, $tokenData['token_id'], 'batch_stats', $input, 200);
        
        echo json_encode([
            'success' => true,
            'data' => $results,
            'count' => count($results)
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => '未知操作']);
        break;
}
