<?php
/**
 * 自动备份服务
 * 支持本地备份、云存储（阿里云 OSS、腾讯云 COS、AWS S3）
 */

class BackupService {
    private static $instance = null;
    private $db;
    
    // 备份配置
    private $config = [
        'backup_dir' => '/var/backups/ip-manager',
        'retention_days' => 7,          // 本地保留天数
        'cloud_retention_days' => 30,   // 云端保留天数
        'compress' => true,
        'encrypt' => false,
        'encrypt_key' => '',
        'cloud_provider' => null,       // aliyun, tencent, aws
        'cloud_config' => []
    ];
    
    private function __construct() {
        if (class_exists('Database')) {
            $this->db = Database::getInstance();
        }
        $this->loadConfig();
    }
    
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 加载配置
     */
    private function loadConfig(): void {
        // 从环境变量加载
        if (getenv('BACKUP_DIR')) {
            $this->config['backup_dir'] = getenv('BACKUP_DIR');
        }
        if (getenv('BACKUP_RETENTION_DAYS')) {
            $this->config['retention_days'] = (int)getenv('BACKUP_RETENTION_DAYS');
        }
        if (getenv('BACKUP_CLOUD_PROVIDER')) {
            $this->config['cloud_provider'] = getenv('BACKUP_CLOUD_PROVIDER');
        }
        
        // 云存储配置
        if (getenv('BACKUP_CLOUD_ENDPOINT')) {
            $this->config['cloud_config']['endpoint'] = getenv('BACKUP_CLOUD_ENDPOINT');
        }
        if (getenv('BACKUP_CLOUD_BUCKET')) {
            $this->config['cloud_config']['bucket'] = getenv('BACKUP_CLOUD_BUCKET');
        }
        if (getenv('BACKUP_CLOUD_ACCESS_KEY')) {
            $this->config['cloud_config']['access_key'] = getenv('BACKUP_CLOUD_ACCESS_KEY');
        }
        if (getenv('BACKUP_CLOUD_SECRET_KEY')) {
            $this->config['cloud_config']['secret_key'] = getenv('BACKUP_CLOUD_SECRET_KEY');
        }
        if (getenv('BACKUP_CLOUD_REGION')) {
            $this->config['cloud_config']['region'] = getenv('BACKUP_CLOUD_REGION');
        }
        
        // 从数据库加载
        if ($this->db) {
            $dbConfig = $this->db->getConfig('backup_config', []);
            if (!empty($dbConfig)) {
                $this->config = array_merge($this->config, $dbConfig);
            }
        }
    }
    
    /**
     * 执行完整备份
     */
    public function backup(bool $uploadToCloud = true): array {
        $result = [
            'success' => false,
            'filename' => '',
            'size' => 0,
            'cloud_uploaded' => false,
            'error' => null
        ];
        
        try {
            // 确保备份目录存在
            if (!is_dir($this->config['backup_dir'])) {
                mkdir($this->config['backup_dir'], 0755, true);
            }
            
            $timestamp = date('Ymd_His');
            $filename = "backup_{$timestamp}.sql";
            $filepath = $this->config['backup_dir'] . '/' . $filename;
            
            // 执行 mysqldump
            $dumpResult = $this->mysqldump($filepath);
            if (!$dumpResult['success']) {
                throw new Exception($dumpResult['error']);
            }
            
            // 压缩
            if ($this->config['compress']) {
                $gzFilepath = $filepath . '.gz';
                $this->compress($filepath, $gzFilepath);
                unlink($filepath);
                $filepath = $gzFilepath;
                $filename .= '.gz';
            }
            
            // 加密
            if ($this->config['encrypt'] && !empty($this->config['encrypt_key'])) {
                $encFilepath = $filepath . '.enc';
                $this->encrypt($filepath, $encFilepath);
                unlink($filepath);
                $filepath = $encFilepath;
                $filename .= '.enc';
            }
            
            $result['filename'] = $filename;
            $result['filepath'] = $filepath;
            $result['size'] = filesize($filepath);
            
            // 上传到云存储
            if ($uploadToCloud && $this->config['cloud_provider']) {
                $uploadResult = $this->uploadToCloud($filepath, $filename);
                $result['cloud_uploaded'] = $uploadResult['success'];
                $result['cloud_url'] = $uploadResult['url'] ?? null;
                $result['cloud_error'] = $uploadResult['error'] ?? null;
            }
            
            // 清理旧备份
            $this->cleanupOldBackups();
            
            // 记录日志
            $this->logBackup($result);
            
            $result['success'] = true;
            
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
            if (class_exists('Logger')) {
                Logger::logError('备份失败', ['error' => $e->getMessage()]);
            }
        }
        
        return $result;
    }
    
    /**
     * 执行 mysqldump
     */
    private function mysqldump(string $outputFile): array {
        // 获取数据库配置
        $host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
        $name = defined('DB_NAME') ? DB_NAME : 'ip_manager';
        $user = defined('DB_USER') ? DB_USER : 'root';
        $pass = defined('DB_PASS') ? DB_PASS : '';
        
        // 构建命令
        $command = sprintf(
            'mysqldump -h%s -u%s %s --single-transaction --quick --lock-tables=false %s > %s 2>&1',
            escapeshellarg($host),
            escapeshellarg($user),
            $pass ? '-p' . escapeshellarg($pass) : '',
            escapeshellarg($name),
            escapeshellarg($outputFile)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            return [
                'success' => false,
                'error' => implode("\n", $output)
            ];
        }
        
        return ['success' => true];
    }
    
    /**
     * 压缩文件
     */
    private function compress(string $source, string $dest): void {
        $gz = gzopen($dest, 'wb9');
        $fp = fopen($source, 'rb');
        
        while (!feof($fp)) {
            gzwrite($gz, fread($fp, 1024 * 512));
        }
        
        fclose($fp);
        gzclose($gz);
    }
    
    /**
     * 解压文件
     */
    private function decompress(string $source, string $dest): void {
        $gz = gzopen($source, 'rb');
        $fp = fopen($dest, 'wb');
        
        while (!gzeof($gz)) {
            fwrite($fp, gzread($gz, 1024 * 512));
        }
        
        gzclose($gz);
        fclose($fp);
    }
    
    /**
     * 加密文件
     */
    private function encrypt(string $source, string $dest): void {
        $key = $this->config['encrypt_key'];
        $iv = openssl_random_pseudo_bytes(16);
        
        $data = file_get_contents($source);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        
        file_put_contents($dest, $iv . $encrypted);
    }
    
    /**
     * 解密文件
     */
    private function decrypt(string $source, string $dest): void {
        $key = $this->config['encrypt_key'];
        
        $data = file_get_contents($source);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        
        file_put_contents($dest, $decrypted);
    }
    
    /**
     * 上传到云存储
     */
    private function uploadToCloud(string $filepath, string $filename): array {
        $provider = $this->config['cloud_provider'];
        $config = $this->config['cloud_config'];
        
        switch ($provider) {
            case 'aliyun':
                return $this->uploadToAliyunOSS($filepath, $filename, $config);
            case 'tencent':
                return $this->uploadToTencentCOS($filepath, $filename, $config);
            case 'aws':
                return $this->uploadToAwsS3($filepath, $filename, $config);
            default:
                return ['success' => false, 'error' => "不支持的云存储提供商: {$provider}"];
        }
    }
    
    /**
     * 上传到阿里云 OSS
     */
    private function uploadToAliyunOSS(string $filepath, string $filename, array $config): array {
        // 简化实现，使用 HTTP PUT
        $endpoint = $config['endpoint'];
        $bucket = $config['bucket'];
        $accessKey = $config['access_key'];
        $secretKey = $config['secret_key'];
        
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $object = 'backups/' . date('Y/m/') . $filename;
        $contentType = 'application/octet-stream';
        
        $stringToSign = "PUT\n\n{$contentType}\n{$date}\n/{$bucket}/{$object}";
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $secretKey, true));
        
        $url = "https://{$bucket}.{$endpoint}/{$object}";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_PUT => true,
            CURLOPT_INFILE => fopen($filepath, 'r'),
            CURLOPT_INFILESIZE => filesize($filepath),
            CURLOPT_HTTPHEADER => [
                "Date: {$date}",
                "Content-Type: {$contentType}",
                "Authorization: OSS {$accessKey}:{$signature}"
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            return ['success' => true, 'url' => $url];
        }
        
        return ['success' => false, 'error' => "HTTP {$httpCode}: {$response}"];
    }
    
    /**
     * 上传到腾讯云 COS
     */
    private function uploadToTencentCOS(string $filepath, string $filename, array $config): array {
        // 类似阿里云，简化实现
        $region = $config['region'] ?? 'ap-guangzhou';
        $bucket = $config['bucket'];
        $secretId = $config['access_key'];
        $secretKey = $config['secret_key'];
        
        $object = 'backups/' . date('Y/m/') . $filename;
        $host = "{$bucket}.cos.{$region}.myqcloud.com";
        $url = "https://{$host}/{$object}";
        
        // 简化签名
        $timestamp = time();
        $keyTime = ($timestamp - 60) . ';' . ($timestamp + 3600);
        $signKey = hash_hmac('sha1', $keyTime, $secretKey);
        
        $httpString = "put\n/{$object}\n\nhost={$host}\n";
        $stringToSign = "sha1\n{$keyTime}\n" . sha1($httpString) . "\n";
        $signature = hash_hmac('sha1', $stringToSign, $signKey);
        
        $authorization = "q-sign-algorithm=sha1&q-ak={$secretId}&q-sign-time={$keyTime}&q-key-time={$keyTime}&q-header-list=host&q-url-param-list=&q-signature={$signature}";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_PUT => true,
            CURLOPT_INFILE => fopen($filepath, 'r'),
            CURLOPT_INFILESIZE => filesize($filepath),
            CURLOPT_HTTPHEADER => [
                "Host: {$host}",
                "Authorization: {$authorization}"
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            return ['success' => true, 'url' => $url];
        }
        
        return ['success' => false, 'error' => "HTTP {$httpCode}: {$response}"];
    }
    
    /**
     * 上传到 AWS S3
     */
    private function uploadToAwsS3(string $filepath, string $filename, array $config): array {
        $region = $config['region'] ?? 'us-east-1';
        $bucket = $config['bucket'];
        $accessKey = $config['access_key'];
        $secretKey = $config['secret_key'];
        
        $object = 'backups/' . date('Y/m/') . $filename;
        $host = "{$bucket}.s3.{$region}.amazonaws.com";
        $url = "https://{$host}/{$object}";
        
        // AWS Signature V4（简化版）
        $service = 's3';
        $timestamp = gmdate('Ymd\THis\Z');
        $datestamp = gmdate('Ymd');
        
        $contentHash = hash_file('sha256', $filepath);
        
        $canonicalRequest = "PUT\n/{$object}\n\nhost:{$host}\nx-amz-content-sha256:{$contentHash}\nx-amz-date:{$timestamp}\n\nhost;x-amz-content-sha256;x-amz-date\n{$contentHash}";
        
        $scope = "{$datestamp}/{$region}/{$service}/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$timestamp}\n{$scope}\n" . hash('sha256', $canonicalRequest);
        
        $signingKey = hash_hmac('sha256', 'aws4_request', 
            hash_hmac('sha256', $service, 
                hash_hmac('sha256', $region, 
                    hash_hmac('sha256', $datestamp, "AWS4{$secretKey}", true), true), true), true);
        
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        
        $authorization = "AWS4-HMAC-SHA256 Credential={$accessKey}/{$scope}, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature={$signature}";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_PUT => true,
            CURLOPT_INFILE => fopen($filepath, 'r'),
            CURLOPT_INFILESIZE => filesize($filepath),
            CURLOPT_HTTPHEADER => [
                "Host: {$host}",
                "x-amz-content-sha256: {$contentHash}",
                "x-amz-date: {$timestamp}",
                "Authorization: {$authorization}"
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            return ['success' => true, 'url' => $url];
        }
        
        return ['success' => false, 'error' => "HTTP {$httpCode}: {$response}"];
    }
    
    /**
     * 清理旧备份
     */
    private function cleanupOldBackups(): int {
        $deleted = 0;
        $threshold = time() - ($this->config['retention_days'] * 86400);
        
        $files = glob($this->config['backup_dir'] . '/backup_*.{sql,sql.gz,sql.gz.enc}', GLOB_BRACE);
        
        foreach ($files as $file) {
            if (filemtime($file) < $threshold) {
                unlink($file);
                $deleted++;
            }
        }
        
        return $deleted;
    }
    
    /**
     * 记录备份日志
     */
    private function logBackup(array $result): void {
        if (!$this->db) return;
        
        try {
            $pdo = $this->db->getPdo();
            $stmt = $pdo->prepare(
                "INSERT INTO backup_logs (filename, size, cloud_uploaded, cloud_url, success, error, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $result['filename'],
                $result['size'],
                $result['cloud_uploaded'] ? 1 : 0,
                $result['cloud_url'] ?? null,
                $result['success'] ? 1 : 0,
                $result['error']
            ]);
        } catch (Exception $e) {
            // 忽略
        }
    }
    
    /**
     * 获取备份列表
     */
    public function getBackupList(): array {
        $files = glob($this->config['backup_dir'] . '/backup_*.{sql,sql.gz,sql.gz.enc}', GLOB_BRACE);
        
        $backups = [];
        foreach ($files as $file) {
            $backups[] = [
                'filename' => basename($file),
                'filepath' => $file,
                'size' => filesize($file),
                'created_at' => date('Y-m-d H:i:s', filemtime($file))
            ];
        }
        
        // 按时间倒序
        usort($backups, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        
        return $backups;
    }
    
    /**
     * 恢复备份
     */
    public function restore(string $filename): array {
        $filepath = $this->config['backup_dir'] . '/' . $filename;
        
        if (!file_exists($filepath)) {
            return ['success' => false, 'error' => '备份文件不存在'];
        }
        
        try {
            $sqlFile = $filepath;
            
            // 解密
            if (str_ends_with($filepath, '.enc')) {
                $decFile = str_replace('.enc', '', $filepath);
                $this->decrypt($filepath, $decFile);
                $sqlFile = $decFile;
            }
            
            // 解压
            if (str_ends_with($sqlFile, '.gz')) {
                $rawFile = str_replace('.gz', '', $sqlFile);
                $this->decompress($sqlFile, $rawFile);
                if ($sqlFile !== $filepath) unlink($sqlFile);
                $sqlFile = $rawFile;
            }
            
            // 执行恢复
            $host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
            $name = defined('DB_NAME') ? DB_NAME : 'ip_manager';
            $user = defined('DB_USER') ? DB_USER : 'root';
            $pass = defined('DB_PASS') ? DB_PASS : '';
            
            $command = sprintf(
                'mysql -h%s -u%s %s %s < %s 2>&1',
                escapeshellarg($host),
                escapeshellarg($user),
                $pass ? '-p' . escapeshellarg($pass) : '',
                escapeshellarg($name),
                escapeshellarg($sqlFile)
            );
            
            exec($command, $output, $returnCode);
            
            // 清理临时文件
            if ($sqlFile !== $filepath) {
                unlink($sqlFile);
            }
            
            if ($returnCode !== 0) {
                return ['success' => false, 'error' => implode("\n", $output)];
            }
            
            if (class_exists('Logger')) {
                Logger::logInfo('数据库已从备份恢复', ['filename' => $filename]);
            }
            
            return ['success' => true, 'message' => '恢复成功'];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
