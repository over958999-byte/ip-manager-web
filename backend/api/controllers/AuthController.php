<?php
/**
 * 认证控制器
 * 处理登录、登出、密码修改等认证相关功能
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../../core/validator.php';

class AuthController extends BaseController
{
    /**
     * 获取 CSRF Token
     * GET /api/v2/auth/csrf
     */
    public function getCsrfToken(): void
    {
        $this->success([
            'csrf_token' => $this->security->getCsrfToken()
        ]);
    }
    
    /**
     * 用户登录
     * POST /api/v2/auth/login
     */
    public function login(): void
    {
        $password = $this->param('password', '');
        $totpCode = $this->param('totp_code', '');
        $storedPassword = $this->db->getConfig('admin_password', 'admin123');
        $passwordHash = $this->db->getConfig('admin_password_hash', '');
        
        $loginSuccess = false;
        
        // 优先使用哈希密码验证
        if (!empty($passwordHash)) {
            $loginSuccess = $this->security->verifyPassword($password, $passwordHash);
            
            // 检查是否需要重新哈希（算法升级时）
            if ($loginSuccess && $this->security->needsRehash($passwordHash)) {
                $newHash = $this->security->hashPassword($password);
                $this->db->setConfig('admin_password_hash', $newHash);
            }
        } else {
            // 兼容旧版明文密码
            $loginSuccess = ($password === $storedPassword);
            
            // 如果明文密码验证成功，自动迁移到哈希存储
            if ($loginSuccess) {
                $newHash = $this->security->hashPassword($password);
                $this->db->setConfig('admin_password_hash', $newHash);
                Logger::logInfo('密码已自动迁移到哈希存储');
            }
        }
        
        if (!$loginSuccess) {
            Logger::logSecurityEvent('登录失败，密码错误');
            $this->error('密码错误', 401);
            return;
        }
        
        // 检查是否启用了 TOTP
        $totpEnabled = $this->db->getConfig('totp_enabled', false);
        $totpSecret = $this->db->getConfig('totp_secret', '');
        
        if ($totpEnabled && !empty($totpSecret)) {
            // 需要 TOTP 验证
            if (empty($totpCode)) {
                // 返回需要 TOTP 的标志
                $this->success([
                    'require_totp' => true,
                    'message' => '请输入双因素认证码'
                ], '需要双因素认证');
                return;
            }
            
            // 验证 TOTP 码
            if (!$this->verifyTotpCode($totpSecret, $totpCode)) {
                Logger::logSecurityEvent('TOTP 验证码错误');
                $this->error('双因素认证码错误', 401);
                return;
            }
        }
        
        // 登录成功
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        $_SESSION['user_id'] = 1;  // 管理员用户ID
        $_SESSION['username'] = 'admin';  // 管理员用户名
        session_regenerate_id(true); // 防止会话固定攻击
        
        // 记录登录审计日志
        $this->audit('login', 'user', 1, ['ip' => $this->getClientIp()]);
        
        // 处理"记住我"功能
        $remember = $this->param('remember', false);
        if ($remember) {
            // 生成 remember token 并设置长期 cookie
            $rememberToken = bin2hex(random_bytes(32));
            $expiry = time() + (7 * 24 * 3600); // 7天
            
            // 保存 token 到数据库（用于验证）
            $this->db->setConfig('remember_token', $rememberToken);
            $this->db->setConfig('remember_token_expiry', $expiry);
            
            // 设置 cookie
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                       || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
            setcookie('remember_token', $rememberToken, [
                'expires' => $expiry,
                'path' => '/',
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            
            // 延长 session cookie 生命周期
            $params = session_get_cookie_params();
            setcookie(session_name(), session_id(), [
                'expires' => $expiry,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
        
        $this->audit('login', 'user', null, ['ip' => $this->getClientIp()]);
        Logger::logInfo('用户登录成功');
        
        $this->success([
            'csrf_token' => $this->security->getCsrfToken()
        ], '登录成功');
    }
    
    /**
     * 验证 TOTP 代码
     */
    private function verifyTotpCode(string $secret, string $code, int $window = 1): bool
    {
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            return false;
        }
        
        $timestamp = time();
        $period = 30;
        
        // 检查当前及前后 window 个时间窗口
        for ($i = -$window; $i <= $window; $i++) {
            $checkTime = $timestamp + ($i * $period);
            $expectedCode = $this->generateTotpCode($secret, $checkTime);
            if (hash_equals($expectedCode, $code)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 生成 TOTP 代码
     */
    private function generateTotpCode(string $secret, int $timestamp): string
    {
        $period = 30;
        $digits = 6;
        
        $counter = floor($timestamp / $period);
        $counterBytes = pack('J', $counter);
        
        $decodedSecret = $this->base32Decode($secret);
        $hash = hash_hmac('sha1', $counterBytes, $decodedSecret, true);
        
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % pow(10, $digits);
        
        return str_pad($code, $digits, '0', STR_PAD_LEFT);
    }
    
    /**
     * Base32 解码
     */
    private function base32Decode(string $input): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $input = strtoupper($input);
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';
        
        for ($i = 0; $i < strlen($input); $i++) {
            $val = strpos($alphabet, $input[$i]);
            if ($val === false) continue;
            
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }
        
        return $output;
    }
    
    /**
     * 用户登出
     * POST /api/v2/auth/logout
     */
    public function logout(): void
    {
        $this->audit('logout', 'user');
        
        // 清除 remember token
        $this->db->setConfig('remember_token', '');
        $this->db->setConfig('remember_token_expiry', 0);
        
        // 删除 remember_token cookie
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                   || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
        setcookie('remember_token', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        
        session_destroy();
        $this->success(null, '已退出登录');
    }
    
    /**
     * 检查登录状态
     * GET /api/v2/auth/check
     */
    public function checkLogin(): void
    {
        $loggedIn = $this->isLoggedIn();
        
        // 如果未登录，尝试通过 remember_token 恢复登录状态
        if (!$loggedIn && !empty($_COOKIE['remember_token'])) {
            $storedToken = $this->db->getConfig('remember_token', '');
            $tokenExpiry = (int)$this->db->getConfig('remember_token_expiry', 0);
            
            if (!empty($storedToken) 
                && hash_equals($storedToken, $_COOKIE['remember_token'])
                && $tokenExpiry > time()) {
                // Token 有效，恢复登录状态
                $_SESSION['logged_in'] = true;
                $_SESSION['login_time'] = time();
                $_SESSION['user_id'] = 1;  // 管理员用户ID
                $_SESSION['username'] = 'admin';  // 管理员用户名
                session_regenerate_id(true);
                $loggedIn = true;
                
                // 刷新 token 和 session 过期时间
                $newExpiry = time() + (7 * 24 * 3600);
                $this->db->setConfig('remember_token_expiry', $newExpiry);
                
                $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                           || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
                setcookie('remember_token', $storedToken, [
                    'expires' => $newExpiry,
                    'path' => '/',
                    'secure' => $isHttps,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            }
        }
        
        $this->success([
            'logged_in' => $loggedIn,
            'username' => $loggedIn ? ($_SESSION['username'] ?? 'admin') : null,
            'login_time' => $_SESSION['login_time'] ?? null
        ]);
    }
    
    /**
     * 修改密码
     * POST /api/v2/auth/password
     */
    public function changePassword(): void
    {
        $this->requireLogin();
        
        $oldPassword = $this->param('old_password', '');
        $newPassword = $this->param('new_password', '');
        
        // 密码验证
        $validator = new Validator(['new_password' => $newPassword]);
        $validator->required('new_password', '新密码不能为空')
                  ->minLength('new_password', 6, '新密码长度不能少于6个字符');
        
        if ($validator->fails()) {
            $this->error($validator->getFirstError());
        }
        
        // 验证旧密码
        $passwordHash = $this->db->getConfig('admin_password_hash', '');
        $storedPassword = $this->db->getConfig('admin_password', 'admin123');
        
        $oldPasswordValid = false;
        if (!empty($passwordHash)) {
            $oldPasswordValid = $this->security->verifyPassword($oldPassword, $passwordHash);
        } else {
            $oldPasswordValid = ($oldPassword === $storedPassword);
        }
        
        if (!$oldPasswordValid) {
            Logger::logSecurityEvent('修改密码失败，原密码错误');
            $this->error('原密码错误');
        }
        
        // 保存新密码（哈希存储）
        $newHash = $this->security->hashPassword($newPassword);
        if ($this->db->setConfig('admin_password_hash', $newHash)) {
            // 清除旧的明文密码
            $this->db->setConfig('admin_password', '');
            
            $this->audit('change_password', 'user');
            Logger::logInfo('管理员密码已修改');
            
            $this->success(null, '密码修改成功');
        } else {
            $this->error('保存失败');
        }
    }
    
    /**
     * 获取 TOTP 状态
     * GET /api/v2/auth/totp/status
     */
    public function totpStatus(): void
    {
        $this->requireLogin();
        
        $totpEnabled = $this->db->getConfig('totp_enabled', false);
        $totpSecret = $this->db->getConfig('totp_secret', '');
        
        $this->success([
            'enabled' => $totpEnabled && !empty($totpSecret),
            'configured' => !empty($totpSecret)
        ]);
    }
    
    /**
     * 启用 TOTP
     * POST /api/v2/auth/totp/enable
     */
    public function totpEnable(): void
    {
        $this->requireLogin();
        
        // 生成 TOTP 密钥
        $secret = $this->generateTotpSecret();
        
        // 临时保存（未验证前）
        $_SESSION['pending_totp_secret'] = $secret;
        
        // 生成 QR 码 URL
        $appName = urlencode('IP管理器');
        $otpauthUrl = "otpauth://totp/{$appName}?secret={$secret}&issuer={$appName}";
        
        $this->success([
            'secret' => $secret,
            'qr_url' => "https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl=" . urlencode($otpauthUrl),
            'otpauth_url' => $otpauthUrl
        ], '请使用验证器 App 扫描二维码，然后输入验证码完成设置');
    }
    
    /**
     * 验证 TOTP 并完成启用
     * POST /api/v2/auth/totp/verify
     */
    public function totpVerify(): void
    {
        $this->requireLogin();
        
        $code = $this->requiredParam('code', '验证码不能为空');
        
        // 检查是否有待验证的密钥
        $pendingSecret = $_SESSION['pending_totp_secret'] ?? null;
        $existingSecret = $this->db->getConfig('totp_secret', '');
        
        $secret = $pendingSecret ?: $existingSecret;
        
        if (empty($secret)) {
            $this->error('请先启用 TOTP');
        }
        
        // 验证 TOTP 码
        if (!$this->verifyTotpCode($secret, $code)) {
            $this->error('验证码错误');
        }
        
        // 如果是首次设置，保存密钥并启用
        if ($pendingSecret) {
            $this->db->setConfig('totp_secret', $pendingSecret);
            $this->db->setConfig('totp_enabled', 1);
            unset($_SESSION['pending_totp_secret']);
            
            $this->audit('totp_enable', 'user');
            $this->success(null, 'TOTP 已启用');
        } else {
            $this->success(null, '验证成功');
        }
    }
    
    /**
     * 禁用 TOTP
     * POST /api/v2/auth/totp/disable
     */
    public function totpDisable(): void
    {
        $this->requireLogin();
        
        $code = $this->requiredParam('code', '验证码不能为空');
        
        $secret = $this->db->getConfig('totp_secret', '');
        
        if (empty($secret)) {
            $this->error('TOTP 未启用');
        }
        
        // 验证 TOTP 码
        if (!$this->verifyTotpCode($secret, $code)) {
            $this->error('验证码错误');
        }
        
        // 禁用 TOTP
        $this->db->setConfig('totp_enabled', 0);
        $this->db->setConfig('totp_secret', '');
        
        $this->audit('totp_disable', 'user');
        $this->success(null, 'TOTP 已禁用');
    }
    
    /**
     * 生成 TOTP 密钥
     */
    private function generateTotpSecret(int $length = 16): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $chars[random_int(0, 31)];
        }
        return $secret;
    }
}
