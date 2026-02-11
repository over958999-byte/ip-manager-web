<?php
/**
 * 批量导入导出服务
 * 支持 CSV、Excel (XLSX) 格式
 */

class ImportExportService {
    private static $instance = null;
    private $db;
    
    // 支持的格式
    const FORMAT_CSV = 'csv';
    const FORMAT_XLSX = 'xlsx';
    const FORMAT_JSON = 'json';
    
    // 导出类型
    const TYPE_IP_POOL = 'ip_pool';
    const TYPE_DOMAINS = 'domains';
    const TYPE_JUMP_RULES = 'jump_rules';
    const TYPE_SHORTLINKS = 'shortlinks';
    
    // 列定义
    private $columns = [
        'ip_pool' => [
            'ip' => 'IP地址',
            'port' => '端口',
            'protocol' => '协议',
            'country' => '国家',
            'region' => '地区',
            'city' => '城市',
            'isp' => '运营商',
            'status' => '状态',
            'speed' => '速度(ms)',
            'last_check' => '最后检查时间'
        ],
        'domains' => [
            'domain' => '域名',
            'type' => '类型',
            'target' => '目标地址',
            'is_safe' => '是否安全',
            'cf_zone_id' => 'Cloudflare Zone ID',
            'enabled' => '启用状态',
            'created_at' => '创建时间'
        ],
        'jump_rules' => [
            'name' => '规则名称',
            'source_domain' => '源域名',
            'target_url' => '目标URL',
            'device_filter' => '设备过滤',
            'region_filter' => '地区过滤',
            'weight' => '权重',
            'enabled' => '启用状态',
            'created_at' => '创建时间'
        ],
        'shortlinks' => [
            'code' => '短码',
            'original_url' => '原始URL',
            'clicks' => '点击数',
            'created_at' => '创建时间',
            'expires_at' => '过期时间'
        ]
    ];
    
    private function __construct() {
        if (class_exists('Database')) {
            $this->db = Database::getInstance();
        }
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 导出数据
     */
    public function export(string $type, string $format = self::FORMAT_CSV, array $filters = []): array {
        $columns = $this->columns[$type] ?? [];
        if (empty($columns)) {
            return ['success' => false, 'error' => '不支持的导出类型'];
        }
        
        // 获取数据
        $data = $this->fetchData($type, $filters);
        
        if (empty($data)) {
            return ['success' => false, 'error' => '没有数据可导出'];
        }
        
        // 生成文件
        $filename = "{$type}_" . date('Ymd_His');
        
        switch ($format) {
            case self::FORMAT_CSV:
                return $this->exportToCsv($data, $columns, $filename);
            case self::FORMAT_XLSX:
                return $this->exportToXlsx($data, $columns, $filename);
            case self::FORMAT_JSON:
                return $this->exportToJson($data, $filename);
            default:
                return ['success' => false, 'error' => '不支持的格式'];
        }
    }
    
    /**
     * 获取数据
     */
    private function fetchData(string $type, array $filters): array {
        if (!$this->db) return [];
        
        $pdo = $this->db->getPdo();
        
        switch ($type) {
            case self::TYPE_IP_POOL:
                $sql = "SELECT ip, port, protocol, country, region, city, isp, status, speed, last_check FROM ip_pool WHERE 1=1";
                break;
            case self::TYPE_DOMAINS:
                $sql = "SELECT domain, name, safety_status, enabled, created_at FROM jump_domains WHERE 1=1";
                break;
            case self::TYPE_JUMP_RULES:
                $sql = "SELECT match_key, target_url, title, note, total_clicks, enabled, created_at FROM jump_rules WHERE 1=1";
                break;
            case self::TYPE_SHORTLINKS:
                $sql = "SELECT code, original_url, total_clicks, created_at FROM short_links WHERE 1=1";
                break;
            default:
                return [];
        }
        
        // 应用过滤条件
        $params = [];
        if (!empty($filters['status'])) {
            $sql .= " AND status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['start_date'])) {
            $sql .= " AND created_at >= ?";
            $params[] = $filters['start_date'];
        }
        if (!empty($filters['end_date'])) {
            $sql .= " AND created_at <= ?";
            $params[] = $filters['end_date'];
        }
        
        $sql .= " ORDER BY id DESC LIMIT 100000";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 导出为 CSV
     */
    private function exportToCsv(array $data, array $columns, string $filename): array {
        $filepath = sys_get_temp_dir() . "/{$filename}.csv";
        
        $fp = fopen($filepath, 'w');
        
        // 添加 BOM 以支持 Excel 中文
        fwrite($fp, "\xEF\xBB\xBF");
        
        // 写入表头
        fputcsv($fp, array_values($columns));
        
        // 写入数据
        foreach ($data as $row) {
            $line = [];
            foreach (array_keys($columns) as $key) {
                $line[] = $row[$key] ?? '';
            }
            fputcsv($fp, $line);
        }
        
        fclose($fp);
        
        return [
            'success' => true,
            'filepath' => $filepath,
            'filename' => "{$filename}.csv",
            'rows' => count($data)
        ];
    }
    
    /**
     * 导出为 XLSX（简单实现，使用 XML）
     */
    private function exportToXlsx(array $data, array $columns, string $filename): array {
        $filepath = sys_get_temp_dir() . "/{$filename}.xlsx";
        
        // 创建临时目录
        $tmpDir = sys_get_temp_dir() . "/xlsx_" . uniqid();
        mkdir($tmpDir);
        mkdir("{$tmpDir}/_rels");
        mkdir("{$tmpDir}/xl");
        mkdir("{$tmpDir}/xl/_rels");
        mkdir("{$tmpDir}/xl/worksheets");
        
        // [Content_Types].xml
        file_put_contents("{$tmpDir}/[Content_Types].xml", '<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>');
        
        // _rels/.rels
        file_put_contents("{$tmpDir}/_rels/.rels", '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');
        
        // xl/workbook.xml
        file_put_contents("{$tmpDir}/xl/workbook.xml", '<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="Sheet1" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>');
        
        // xl/_rels/workbook.xml.rels
        file_put_contents("{$tmpDir}/xl/_rels/workbook.xml.rels", '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>');
        
        // 构建工作表数据
        $sheetData = '<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheetData>';
        
        // 表头行
        $row = 1;
        $sheetData .= "<row r=\"{$row}\">";
        $col = 0;
        foreach (array_values($columns) as $header) {
            $colLetter = $this->getColumnLetter($col++);
            $escapedHeader = htmlspecialchars($header, ENT_XML1);
            $sheetData .= "<c r=\"{$colLetter}{$row}\" t=\"inlineStr\"><is><t>{$escapedHeader}</t></is></c>";
        }
        $sheetData .= "</row>";
        
        // 数据行
        foreach ($data as $rowData) {
            $row++;
            $sheetData .= "<row r=\"{$row}\">";
            $col = 0;
            foreach (array_keys($columns) as $key) {
                $colLetter = $this->getColumnLetter($col++);
                $value = $rowData[$key] ?? '';
                $escapedValue = htmlspecialchars((string)$value, ENT_XML1);
                $sheetData .= "<c r=\"{$colLetter}{$row}\" t=\"inlineStr\"><is><t>{$escapedValue}</t></is></c>";
            }
            $sheetData .= "</row>";
        }
        
        $sheetData .= '</sheetData></worksheet>';
        
        file_put_contents("{$tmpDir}/xl/worksheets/sheet1.xml", $sheetData);
        
        // 创建 ZIP
        $zip = new ZipArchive();
        $zip->open($filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        
        $this->addDirToZip($zip, $tmpDir, '');
        
        $zip->close();
        
        // 清理临时目录
        $this->deleteDir($tmpDir);
        
        return [
            'success' => true,
            'filepath' => $filepath,
            'filename' => "{$filename}.xlsx",
            'rows' => count($data)
        ];
    }
    
    /**
     * 导出为 JSON
     */
    private function exportToJson(array $data, string $filename): array {
        $filepath = sys_get_temp_dir() . "/{$filename}.json";
        
        file_put_contents($filepath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        
        return [
            'success' => true,
            'filepath' => $filepath,
            'filename' => "{$filename}.json",
            'rows' => count($data)
        ];
    }
    
    /**
     * 导入数据
     */
    public function import(string $type, string $filepath, string $format = null): array {
        // 自动检测格式
        if (!$format) {
            $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
            $format = $ext;
        }
        
        // 解析文件
        switch ($format) {
            case self::FORMAT_CSV:
                $data = $this->parseCsv($filepath);
                break;
            case self::FORMAT_XLSX:
                $data = $this->parseXlsx($filepath);
                break;
            case self::FORMAT_JSON:
                $data = json_decode(file_get_contents($filepath), true);
                break;
            default:
                return ['success' => false, 'error' => '不支持的格式'];
        }
        
        if (empty($data)) {
            return ['success' => false, 'error' => '文件为空或格式错误'];
        }
        
        // 验证和导入
        return $this->importData($type, $data);
    }
    
    /**
     * 解析 CSV
     */
    private function parseCsv(string $filepath): array {
        $data = [];
        $headers = null;
        
        $fp = fopen($filepath, 'r');
        
        // 跳过 BOM
        $bom = fread($fp, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($fp);
        }
        
        while (($row = fgetcsv($fp)) !== false) {
            if ($headers === null) {
                $headers = $row;
                continue;
            }
            
            $item = [];
            foreach ($headers as $i => $header) {
                $item[$header] = $row[$i] ?? '';
            }
            $data[] = $item;
        }
        
        fclose($fp);
        
        return $data;
    }
    
    /**
     * 解析 XLSX
     */
    private function parseXlsx(string $filepath): array {
        $zip = new ZipArchive();
        if ($zip->open($filepath) !== true) {
            return [];
        }
        
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        
        if (!$sheetXml) {
            return [];
        }
        
        $xml = simplexml_load_string($sheetXml);
        if (!$xml) {
            return [];
        }
        
        $data = [];
        $headers = [];
        $firstRow = true;
        
        foreach ($xml->sheetData->row as $row) {
            $rowData = [];
            foreach ($row->c as $cell) {
                $value = '';
                if (isset($cell->is->t)) {
                    $value = (string)$cell->is->t;
                } elseif (isset($cell->v)) {
                    $value = (string)$cell->v;
                }
                $rowData[] = $value;
            }
            
            if ($firstRow) {
                $headers = $rowData;
                $firstRow = false;
                continue;
            }
            
            $item = [];
            foreach ($headers as $i => $header) {
                $item[$header] = $rowData[$i] ?? '';
            }
            $data[] = $item;
        }
        
        return $data;
    }
    
    /**
     * 导入数据到数据库
     */
    private function importData(string $type, array $data): array {
        if (!$this->db) {
            return ['success' => false, 'error' => '数据库未连接'];
        }
        
        $pdo = $this->db->getPdo();
        $imported = 0;
        $skipped = 0;
        $errors = [];
        
        // 映射中文列名到英文
        $reverseColumns = [];
        foreach ($this->columns[$type] ?? [] as $key => $label) {
            $reverseColumns[$label] = $key;
        }
        
        try {
            $pdo->beginTransaction();
            
            foreach ($data as $index => $row) {
                // 转换列名
                $item = [];
                foreach ($row as $key => $value) {
                    $mappedKey = $reverseColumns[$key] ?? $key;
                    $item[$mappedKey] = $value;
                }
                
                // 验证必填字段
                $validation = $this->validateRow($type, $item);
                if (!$validation['valid']) {
                    $skipped++;
                    $errors[] = "第 " . ($index + 2) . " 行: " . $validation['error'];
                    continue;
                }
                
                // 插入数据
                $result = $this->insertRow($pdo, $type, $item);
                if ($result['success']) {
                    $imported++;
                } else {
                    $skipped++;
                    $errors[] = "第 " . ($index + 2) . " 行: " . $result['error'];
                }
            }
            
            $pdo->commit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
        
        return [
            'success' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 10) // 只返回前10条错误
        ];
    }
    
    /**
     * 验证行数据
     */
    private function validateRow(string $type, array $row): array {
        switch ($type) {
            case self::TYPE_IP_POOL:
                if (empty($row['ip'])) {
                    return ['valid' => false, 'error' => 'IP地址不能为空'];
                }
                if (!filter_var($row['ip'], FILTER_VALIDATE_IP)) {
                    return ['valid' => false, 'error' => 'IP地址格式无效'];
                }
                break;
                
            case self::TYPE_DOMAINS:
                if (empty($row['domain'])) {
                    return ['valid' => false, 'error' => '域名不能为空'];
                }
                break;
                
            case self::TYPE_JUMP_RULES:
                if (empty($row['name'])) {
                    return ['valid' => false, 'error' => '规则名称不能为空'];
                }
                if (empty($row['target_url'])) {
                    return ['valid' => false, 'error' => '目标URL不能为空'];
                }
                break;
        }
        
        return ['valid' => true];
    }
    
    /**
     * 插入行数据
     */
    private function insertRow(PDO $pdo, string $type, array $row): array {
        try {
            switch ($type) {
                case self::TYPE_IP_POOL:
                    $stmt = $pdo->prepare(
                        "INSERT INTO ip_pool (ip, port, protocol, country, region, city, isp, status, speed, created_at) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                         ON DUPLICATE KEY UPDATE port=VALUES(port), protocol=VALUES(protocol), status=VALUES(status)"
                    );
                    $stmt->execute([
                        $row['ip'],
                        $row['port'] ?? 80,
                        $row['protocol'] ?? 'http',
                        $row['country'] ?? '',
                        $row['region'] ?? '',
                        $row['city'] ?? '',
                        $row['isp'] ?? '',
                        $row['status'] ?? 'active',
                        $row['speed'] ?? 0
                    ]);
                    break;
                    
                case self::TYPE_DOMAINS:
                    $stmt = $pdo->prepare(
                        "INSERT INTO domains (domain, type, target, is_safe, cf_zone_id, enabled, created_at) 
                         VALUES (?, ?, ?, ?, ?, ?, NOW())
                         ON DUPLICATE KEY UPDATE target=VALUES(target), is_safe=VALUES(is_safe)"
                    );
                    $stmt->execute([
                        $row['domain'],
                        $row['type'] ?? 'cname',
                        $row['target'] ?? '',
                        $row['is_safe'] ?? 1,
                        $row['cf_zone_id'] ?? '',
                        $row['enabled'] ?? 1
                    ]);
                    break;
                    
                case self::TYPE_JUMP_RULES:
                    $stmt = $pdo->prepare(
                        "INSERT INTO jump_rules (name, source_domain, target_url, device_filter, region_filter, weight, enabled, created_at) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
                    );
                    $stmt->execute([
                        $row['name'],
                        $row['source_domain'] ?? '',
                        $row['target_url'],
                        $row['device_filter'] ?? '',
                        $row['region_filter'] ?? '',
                        $row['weight'] ?? 100,
                        $row['enabled'] ?? 1
                    ]);
                    break;
                    
                case self::TYPE_SHORTLINKS:
                    $stmt = $pdo->prepare(
                        "INSERT INTO shortlinks (code, original_url, created_at, expires_at) 
                         VALUES (?, ?, NOW(), ?)
                         ON DUPLICATE KEY UPDATE original_url=VALUES(original_url)"
                    );
                    $stmt->execute([
                        $row['code'] ?? $this->generateShortCode(),
                        $row['original_url'],
                        $row['expires_at'] ?? null
                    ]);
                    break;
            }
            
            return ['success' => true];
            
        } catch (PDOException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 生成短码
     */
    private function generateShortCode(int $length = 6): string {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $code;
    }
    
    /**
     * 获取 Excel 列字母
     */
    private function getColumnLetter(int $index): string {
        $letter = '';
        while ($index >= 0) {
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = intval($index / 26) - 1;
        }
        return $letter;
    }
    
    /**
     * 添加目录到 ZIP
     */
    private function addDirToZip(ZipArchive $zip, string $dir, string $base): void {
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $filepath = "{$dir}/{$file}";
            $zipPath = $base ? "{$base}/{$file}" : $file;
            
            if (is_dir($filepath)) {
                $this->addDirToZip($zip, $filepath, $zipPath);
            } else {
                $zip->addFile($filepath, $zipPath);
            }
        }
    }
    
    /**
     * 删除目录
     */
    private function deleteDir(string $dir): void {
        if (!is_dir($dir)) return;
        
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $filepath = "{$dir}/{$file}";
            if (is_dir($filepath)) {
                $this->deleteDir($filepath);
            } else {
                unlink($filepath);
            }
        }
        rmdir($dir);
    }
    
    /**
     * 获取导出模板
     */
    public function getTemplate(string $type, string $format = self::FORMAT_CSV): array {
        $columns = $this->columns[$type] ?? [];
        if (empty($columns)) {
            return ['success' => false, 'error' => '不支持的类型'];
        }
        
        // 创建示例数据
        $sampleData = $this->getSampleData($type);
        
        $filename = "{$type}_template";
        
        switch ($format) {
            case self::FORMAT_CSV:
                return $this->exportToCsv($sampleData, $columns, $filename);
            case self::FORMAT_XLSX:
                return $this->exportToXlsx($sampleData, $columns, $filename);
            default:
                return ['success' => false, 'error' => '不支持的格式'];
        }
    }
    
    /**
     * 获取示例数据
     */
    private function getSampleData(string $type): array {
        switch ($type) {
            case self::TYPE_IP_POOL:
                return [
                    ['ip' => '192.168.1.1', 'port' => '8080', 'protocol' => 'http', 'country' => 'CN', 'region' => '广东', 'city' => '深圳', 'isp' => '电信', 'status' => 'active', 'speed' => '100', 'last_check' => '2024-01-01 00:00:00']
                ];
            case self::TYPE_DOMAINS:
                return [
                    ['domain' => 'example.com', 'type' => 'cname', 'target' => 'target.example.com', 'is_safe' => '1', 'cf_zone_id' => '', 'enabled' => '1', 'created_at' => '2024-01-01 00:00:00']
                ];
            case self::TYPE_JUMP_RULES:
                return [
                    ['name' => '示例规则', 'source_domain' => 'from.example.com', 'target_url' => 'https://to.example.com', 'device_filter' => 'mobile', 'region_filter' => 'CN', 'weight' => '100', 'enabled' => '1', 'created_at' => '2024-01-01 00:00:00']
                ];
            default:
                return [];
        }
    }
}
