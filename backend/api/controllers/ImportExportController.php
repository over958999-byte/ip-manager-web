<?php
/**
 * 批量导入导出控制器
 */

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../../core/import_export.php';

class ImportExportController extends BaseController
{
    private ImportExport $importExport;
    
    public function __construct()
    {
        parent::__construct();
        $this->importExport = new ImportExport($this->db);
    }
    
    /**
     * 导出数据
     */
    public function export(): void
    {
        $this->requireLogin();
        
        $type = $this->requiredParam('type', '导出类型不能为空');
        $format = $this->param('format', 'csv');
        $filters = $this->param('filters', []);
        
        // 支持的导出类型
        $allowedTypes = ['jump_rules', 'shortlinks', 'domains', 'ip_pool', 'ip_blacklist'];
        if (!in_array($type, $allowedTypes)) {
            $this->error('不支持的导出类型: ' . $type);
        }
        
        // 支持的格式
        if (!in_array($format, ['csv', 'json', 'xlsx'])) {
            $this->error('不支持的导出格式: ' . $format);
        }
        
        try {
            $data = $this->importExport->export($type, $filters);
            
            $filename = $type . '_' . date('Y-m-d_His');
            
            if ($format === 'json') {
                header('Content-Type: application/json; charset=utf-8');
                header("Content-Disposition: attachment; filename=\"{$filename}.json\"");
                echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            } elseif ($format === 'csv') {
                header('Content-Type: text/csv; charset=utf-8');
                header("Content-Disposition: attachment; filename=\"{$filename}.csv\"");
                echo "\xEF\xBB\xBF"; // UTF-8 BOM
                
                if (!empty($data)) {
                    $output = fopen('php://output', 'w');
                    fputcsv($output, array_keys($data[0]));
                    foreach ($data as $row) {
                        fputcsv($output, $row);
                    }
                    fclose($output);
                }
            }
            
            $this->audit('export_data', 'import_export', null, ['type' => $type, 'format' => $format, 'count' => count($data)]);
            exit;
            
        } catch (Exception $e) {
            $this->error('导出失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 导入数据
     */
    public function import(): void
    {
        $this->requireLogin();
        
        $type = $this->requiredParam('type', '导入类型不能为空');
        
        // 检查是否有上传文件
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->error('请上传文件');
        }
        
        $file = $_FILES['file'];
        
        // 检查文件大小（最大 10MB）
        if ($file['size'] > 10 * 1024 * 1024) {
            $this->error('文件大小超过限制（最大 10MB）');
        }
        
        // 检查文件类型
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'json'])) {
            $this->error('不支持的文件格式（支持 CSV、JSON）');
        }
        
        try {
            $content = file_get_contents($file['tmp_name']);
            
            if ($ext === 'json') {
                $data = json_decode($content, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->error('JSON 格式错误');
                }
            } else {
                // 解析 CSV
                $data = [];
                $lines = str_getcsv($content, "\n");
                $headers = str_getcsv(array_shift($lines));
                
                foreach ($lines as $line) {
                    if (trim($line)) {
                        $values = str_getcsv($line);
                        if (count($values) === count($headers)) {
                            $data[] = array_combine($headers, $values);
                        }
                    }
                }
            }
            
            $result = $this->importExport->import($type, $data);
            
            $this->audit('import_data', 'import_export', null, ['type' => $type, 'success' => $result['success'], 'failed' => $result['failed']]);
            $this->success($result, '导入完成');
            
        } catch (Exception $e) {
            $this->error('导入失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取导入模板
     */
    public function template(): void
    {
        $this->requireLogin();
        
        $type = $this->requiredParam('type', '类型不能为空');
        $format = $this->param('format', 'csv');
        
        // 模板定义
        $templates = [
            'jump_rules' => [
                'columns' => ['match_type', 'match_value', 'target_url', 'rule_type', 'enabled', 'tag'],
                'example' => [
                    ['ip', '192.168.1.1', 'https://example.com', 'jump', 1, 'demo'],
                    ['ip_range', '192.168.1.0/24', 'https://example.com', 'jump', 1, 'demo']
                ]
            ],
            'shortlinks' => [
                'columns' => ['code', 'target_url', 'enabled', 'tag'],
                'example' => [
                    ['abc123', 'https://example.com', 1, 'demo'],
                    ['xyz789', 'https://google.com', 1, 'demo']
                ]
            ],
            'domains' => [
                'columns' => ['domain', 'enabled', 'tag'],
                'example' => [
                    ['example.com', 1, 'demo'],
                    ['test.com', 1, 'demo']
                ]
            ],
            'ip_pool' => [
                'columns' => ['ip', 'status', 'tag'],
                'example' => [
                    ['192.168.1.1', 'idle', 'demo'],
                    ['192.168.1.2', 'idle', 'demo']
                ]
            ],
            'ip_blacklist' => [
                'columns' => ['ip', 'reason', 'enabled', 'source'],
                'example' => [
                    ['10.0.0.1', '恶意访问', 1, 'manual'],
                    ['10.0.0.0/24', '批量封禁', 1, 'manual']
                ]
            ]
        ];
        
        if (!isset($templates[$type])) {
            $this->error('不支持的模板类型: ' . $type);
        }
        
        $template = $templates[$type];
        $filename = $type . '_template';
        
        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"{$filename}.json\"");
            
            $data = [];
            foreach ($template['example'] as $row) {
                $data[] = array_combine($template['columns'], $row);
            }
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            header('Content-Type: text/csv; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"{$filename}.csv\"");
            echo "\xEF\xBB\xBF"; // UTF-8 BOM
            
            $output = fopen('php://output', 'w');
            fputcsv($output, $template['columns']);
            foreach ($template['example'] as $row) {
                fputcsv($output, $row);
            }
            fclose($output);
        }
        
        exit;
    }
}
