<?php
/**
 * 备份管理控制器
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../../core/backup.php';

class BackupController extends BaseController
{
    private Backup $backup;
    
    public function __construct()
    {
        parent::__construct();
        $this->backup = new Backup($this->db);
    }
    
    /**
     * 获取备份列表
     */
    public function list(): void
    {
        $this->requireLogin();
        
        $backups = $this->backup->list();
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
            $result = $this->backup->create($uploadToCloud);
            
            $this->audit('backup_create', 'backup', null, ['filename' => $result['filename']]);
            $this->success($result, '备份创建成功');
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
            $this->backup->restore($filename);
            
            $this->audit('backup_restore', 'backup', null, ['filename' => $filename]);
            $this->success(null, '备份恢复成功');
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
        $backupDir = $this->backup->getBackupDir();
        $filepath = $backupDir . '/' . $filename;
        
        if (!file_exists($filepath)) {
            $this->error('备份文件不存在');
        }
        
        // 检查文件扩展名
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if (!in_array($ext, ['sql', 'zip', 'gz'])) {
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
        
        try {
            $this->backup->delete($filename);
            
            $this->audit('backup_delete', 'backup', null, ['filename' => $filename]);
            $this->success(null, '备份删除成功');
        } catch (Exception $e) {
            $this->error('备份删除失败: ' . $e->getMessage());
        }
    }
}
