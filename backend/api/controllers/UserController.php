<?php
/**
 * 用户管理控制器
 */

require_once __DIR__ . '/BaseController.php';

class UserController extends BaseController
{
    /**
     * 确保 users 表存在
     */
    private function ensureTableExists(): void
    {
        static $checked = false;
        if ($checked) return;
        
        try {
            $this->pdo()->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(50) NOT NULL,
                    password_hash VARCHAR(255) NOT NULL,
                    email VARCHAR(255),
                    role ENUM('admin', 'operator', 'viewer') DEFAULT 'viewer',
                    status ENUM('active', 'inactive', 'locked') DEFAULT 'active',
                    last_login_at TIMESTAMP NULL,
                    login_count INT DEFAULT 0,
                    totp_enabled TINYINT(1) DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_username (username)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $checked = true;
        } catch (Exception $e) {
            // 忽略
        }
    }
    
    /**
     * 获取用户列表
     */
    public function list(): void
    {
        $this->requireLogin();
        $this->ensureTableExists();
        
        $users = $this->db->fetchAll(
            "SELECT id, username, email, role, status, totp_enabled, last_login_at, login_count, created_at FROM users ORDER BY created_at DESC"
        ) ?? [];
        
        // 转换 enabled 字段供前端使用
        foreach ($users as &$user) {
            $user['enabled'] = ($user['status'] === 'active') ? 1 : 0;
        }
        
        $this->success(['list' => $users]);
    }
    
    /**
     * 创建用户
     */
    public function create(): void
    {
        $this->requireLogin();
        $this->ensureTableExists();
        
        $username = $this->requiredParam('username', '用户名不能为空');
        $password = $this->requiredParam('password', '密码不能为空');
        $email = $this->param('email', '');
        $role = $this->param('role', 'viewer');
        
        // 验证角色
        if (!in_array($role, ['admin', 'operator', 'viewer'])) {
            $role = 'viewer';
        }
        
        // 检查用户名是否已存在
        $exists = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE username = ?",
            [$username]
        );
        
        if ($exists > 0) {
            $this->error('用户名已存在');
        }
        
        // 密码长度检查
        if (strlen($password) < 6) {
            $this->error('密码长度至少6位');
        }
        
        $stmt = $this->pdo()->prepare("
            INSERT INTO users (username, password_hash, email, role, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'active', NOW(), NOW())
        ");
        $stmt->execute([
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            $email ?: null,
            $role
        ]);
        
        $id = $this->pdo()->lastInsertId();
        
        $this->audit('user_create', 'user', $id, ['username' => $username]);
        $this->success(['id' => $id], '用户创建成功');
    }
    
    /**
     * 更新用户
     */
    public function update(): void
    {
        $this->requireLogin();
        
        $id = $this->requiredParam('id', 'ID 不能为空');
        
        $sets = [];
        $params = [];
        
        if ($this->param('email') !== null) {
            $sets[] = 'email = ?';
            $params[] = $this->param('email') ?: null;
        }
        if ($this->param('role') !== null) {
            $role = $this->param('role');
            if (in_array($role, ['admin', 'operator', 'viewer'])) {
                $sets[] = 'role = ?';
                $params[] = $role;
            }
        }
        if ($this->param('enabled') !== null) {
            $sets[] = 'status = ?';
            $params[] = $this->param('enabled') ? 'active' : 'inactive';
        }
        
        // 如果提供了新密码
        if ($this->param('password')) {
            $password = $this->param('password');
            if (strlen($password) < 6) {
                $this->error('密码长度至少6位');
            }
            $sets[] = 'password_hash = ?';
            $params[] = password_hash($password, PASSWORD_DEFAULT);
        }
        
        if (empty($sets)) {
            $this->error('没有要更新的字段');
        }
        
        $sets[] = 'updated_at = NOW()';
        $params[] = $id;
        
        $sql = "UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?";
        $this->pdo()->prepare($sql)->execute($params);
        
        $this->audit('user_update', 'user', $id);
        $this->success(null, '用户更新成功');
    }
    
    /**
     * 删除用户
     */
    public function delete(): void
    {
        $this->requireLogin();
        
        $id = $this->requiredParam('id', 'ID 不能为空');
        
        // 不能删除自己
        if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $id) {
            $this->error('不能删除当前登录用户');
        }
        
        // 不能删除 admin 用户
        $username = $this->db->fetchColumn("SELECT username FROM users WHERE id = ?", [$id]);
        if ($username === 'admin') {
            $this->error('不能删除管理员用户');
        }
        
        $this->db->delete('users', ['id' => $id]);
        
        $this->audit('user_delete', 'user', $id);
        $this->success(null, '用户删除成功');
    }
    
    /**
     * 重置用户密码
     */
    public function resetPassword(): void
    {
        $this->requireLogin();
        
        $id = $this->requiredParam('id', 'ID 不能为空');
        
        // 生成随机密码
        $newPassword = bin2hex(random_bytes(8));
        
        $stmt = $this->pdo()->prepare("
            UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?
        ");
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $id]);
        
        $this->audit('user_reset_password', 'user', $id);
        $this->success(['password' => $newPassword], '密码重置成功');
    }
    
    /**
     * 切换用户状态
     */
    public function toggle(): void
    {
        $this->requireLogin();
        
        $id = $this->requiredParam('id', 'ID 不能为空');
        $enabled = $this->param('enabled', 1);
        
        $status = $enabled ? 'active' : 'inactive';
        
        $stmt = $this->pdo()->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$status, $id]);
        
        $this->audit('user_toggle', 'user', $id, ['status' => $status]);
        $this->success(null, $enabled ? '用户已启用' : '用户已禁用');
    }
}
