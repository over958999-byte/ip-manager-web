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
        
        if ($loginSuccess) {
            $_SESSION['logged_in'] = true;
            $_SESSION['login_time'] = time();
            session_regenerate_id(true); // 防止会话固定攻击
            
            $this->audit('login', 'user', null, ['ip' => $this->getClientIp()]);
            Logger::logInfo('用户登录成功');
            
            $this->success([
                'csrf_token' => $this->security->getCsrfToken()
            ], '登录成功');
        } else {
            Logger::logSecurityEvent('登录失败，密码错误');
            $this->error('密码错误', 401);
        }
    }
    
    /**
     * 用户登出
     * POST /api/v2/auth/logout
     */
    public function logout(): void
    {
        $this->audit('logout', 'user');
        session_destroy();
        $this->success(null, '已退出登录');
    }
    
    /**
     * 检查登录状态
     * GET /api/v2/auth/check
     */
    public function checkLogin(): void
    {
        $this->success([
            'logged_in' => $this->isLoggedIn(),
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
    
    /**
     * 验证 TOTP 码
     */
    private function verifyTotpCode(string $secret, string $code): bool
    {
        // Base32 解码
        $secret = $this->base32Decode($secret);
        if ($secret === false) {
            return false;
        }
        
        // 允许前后 30 秒的时间窗口
        $timestamp = floor(time() / 30);
        
        for ($i = -1; $i <= 1; $i++) {
            $expectedCode = $this->generateTotpCode($secret, $timestamp + $i);
            if (hash_equals($expectedCode, $code)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 生成 TOTP 码
     */
    private function generateTotpCode(string $secret, int $timestamp): string
    {
        $msg = pack('J', $timestamp);
        $hash = hash_hmac('sha1', $msg, $secret, true);
        $offset = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        ) % 1000000;
        
        return str_pad($code, 6, '0', STR_PAD_LEFT);
    }
    
    /**
     * Base32 解码
     */
    private function base32Decode(string $input): string|false
    {
        $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $input = strtoupper($input);
        $input = rtrim($input, '=');
        
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';
        
        for ($i = 0; $i < strlen($input); $i++) {
            $val = strpos($map, $input[$i]);
            if ($val === false) {
                return false;
            }
            
            $buffer = ($buffer << 5) | $val;
            $bitsLeft += 5;
            
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }
        
        return $output;
    }
}
