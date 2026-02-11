<?php
/**
 * 备份管理控制器
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../../core/backup.php';

class BackupController extends BaseController
{
    /**
     * 获取 BackupService 实例
     */
    private function getBackupService(): BackupService
    {
        return BackupService::getInstance();
    }
    
    /**
     * 获取备份列表
     */
    public function list(): void
    {
        $this->requireLogin();
        
        $backups = $this->getBackupService()->getBackupList();
        $this->success($backups);
    }
    
    /**
     * 创建备份
     */
    public function create(): void
    {
        $this->requireLogin();
        
        $uploadToCloud = $this->param('upload_to_cloud', true);
        
        try {
            $result = $this->getBackupService()->backup($uploadToCloud);
            
            if ($result['success']) {
                $this->audit('backup_create', 'backup', null, ['filename' => $result['filename'] ?? '']);
                $this->success($result, '备份创建成功');
            } else {
                $this->error('备份创建失败: ' . ($result['error'] ?? '未知错误'));
            }
        } catch (Exception $e) {
            $this->error('备份创建失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 恢复备份
     */
    public function restore(): void
    {
        $this->requireLogin();
        
        $filename = $this->requiredParam('filename', '备份文件名不能为空');
        
        try {
            $result = $this->getBackupService()->restore($filename);
            
            if ($result['success']) {
                $this->audit('backup_restore', 'backup', null, ['filename' => $filename]);
                $this->success(null, '备份恢复成功');
            } else {
                $this->error('备份恢复失败: ' . ($result['error'] ?? '未知错误'));
            }
        } catch (Exception $e) {
            $this->error('备份恢复失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 下载备份
     */
    public function download(): void
    {
        $this->requireLogin();
        
        $filename = $this->requiredParam('filename', '备份文件名不能为空');
        
        // 安全检查：防止路径遍历
        $filename = basename($filename);
        $backups = $this->getBackupService()->getBackupList();
        
        // 查找文件路径
        $filepath = null;
        foreach ($backups as $backup) {
            if ($backup['filename'] === $filename) {
                $filepath = $backup['filepath'];
                break;
            }
        }
        
        if (!$filepath || !file_exists($filepath)) {
            $this->error('备份文件不存在');
        }
        
        // 检查文件扩展名
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if (!in_array($ext, ['sql', 'gz', 'enc'])) {
            $this->error('不支持的文件类型');
        }
        
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        
        readfile($filepath);
        exit;
    }
    
    /**
     * 删除备份
     */
    public function delete(): void
    {
        $this->requireLogin();
        
        $filename = $this->requiredParam('filename', '备份文件名不能为空');
        
        // 安全检查：防止路径遍历
        $filename = basename($filename);
        $backups = $this->getBackupService()->getBackupList();
        
        // 查找文件路径
        $filepath = null;
        foreach ($backups as $backup) {
            if ($backup['filename'] === $filename) {
                $filepath = $backup['filepath'];
                break;
            }
        }
        
        if (!$filepath || !file_exists($filepath)) {
            $this->error('备份文件不存在');
        }
        
        try {
            unlink($filepath);
            $this->audit('backup_delete', 'backup', null, ['filename' => $filename]);
            $this->success(null, '备份删除成功');
        } catch (Exception $e) {
            $this->error('备份删除失败: ' . $e->getMessage());
        }
    }
}
