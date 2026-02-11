<?php
/**
 * 用户管理控制器
 */

require_once __DIR__ . '/BaseController.php';

class UserController extends BaseController
{
    /**
     * 获取用户列表
     */
    public function list(): void
    {
        $this->requireLogin();
        
        $users = $this->db->fetchAll(
            "SELECT id, username, email, role, status, last_login, created_at FROM users ORDER BY created_at DESC"
        );
        
        $this->success($users);
    }
    
    /**
     * 创建用户
     */
    public function create(): void
    {
        $this->requireLogin();
        
        $username = $this->requiredParam('username', '用户名不能为空');
        $password = $this->requiredParam('password', '密码不能为空');
        $email = $this->param('email', '');
        $role = $this->param('role', 'user');
        
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
        
        $id = $this->db->insert('users', [
            'username' => $username,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'email' => $email,
            'role' => $role,
            'status' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
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
        
        $data = [];
        if ($this->param('email') !== null) $data['email'] = $this->param('email');
        if ($this->param('role') !== null) $data['role'] = $this->param('role');
        if ($this->param('status') !== null) $data['status'] = $this->param('status') ? 1 : 0;
        
        // 如果提供了新密码
        if ($this->param('password')) {
            $password = $this->param('password');
            if (strlen($password) < 6) {
                $this->error('密码长度至少6位');
            }
            $data['password'] = password_hash($password, PASSWORD_DEFAULT);
        }
        
        $data['updated_at'] = date('Y-m-d H:i:s');
        
        $this->db->update('users', $data, ['id' => $id]);
        
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
        
        $this->db->update('users', [
            'password' => password_hash($newPassword, PASSWORD_DEFAULT),
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $id]);
        
        $this->audit('user_reset_password', 'user', $id);
        $this->success(['password' => $newPassword], '密码重置成功');
    }
}
