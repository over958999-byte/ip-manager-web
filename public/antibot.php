<?php
/**
 * 高级反爬虫防护系统 - 数据库版本
 * 多层检测机制：频率限制、UA检测、行为分析、黑名单、蜜罐陷阱、恶意IP库
 */

require_once __DIR__ . '/../backend/core/database.php';

// 优先使用数据库版本的IP黑名单，如果表不存在则回退到文件版本
$useDbBlacklist = false;
try {
    $pdo = Database::getInstance()->getConnection();
    $stmt = $pdo->query("SELECT 1 FROM ip_blacklist LIMIT 1");
    $useDbBlacklist = true;
    require_once __DIR__ . '/ip_blacklist.php';
} catch (Exception $e) {
    require_once __DIR__ . '/bad_ips.php';
}

class AntiBot {
    private $config;
    private $db;
    private $visitorIp;
    private $userAgent;
    private $requestUri;
    private $targetIp = '';
    
    // 已知爬虫UA关键词
    private $botKeywords = [
        'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider', 'yandexbot',
        'sogou', '360spider', 'bytespider', 'petalbot', 'semrushbot', 'ahrefsbot',
        'bot', 'spider', 'crawler', 'scraper', 'curl', 'wget', 'python', 'java',
        'php', 'perl', 'ruby', 'go-http', 'node-fetch', 'axios', 'httpclient',
        'okhttp', 'requests', 'scrapy', 'puppeteer', 'playwright', 'selenium',
        'headless', 'phantom', 'nightmare', 'casper',
        'nikto', 'nmap', 'sqlmap', 'acunetix', 'nessus', 'masscan', 'zmap',
        'nuclei', 'dirbuster', 'gobuster', 'wfuzz', 'burp', 'zaproxy',
        'libwww', 'mechanize', 'feedfetcher', 'facebookexternalhit', 'twitterbot'
    ];
    
    // 蜜罐路径
    private $honeypotPaths = [
        '/wp-admin', '/wp-login.php', '/administrator', '/admin.php.bak',
        '/phpmyadmin', '/.env', '/.git', '/config.php.bak', '/backup',
        '/db.sql', '/dump.sql', '/.htaccess', '/web.config', '/xmlrpc.php',
        '/wp-content', '/wp-includes', '/.well-known/security.txt'
    ];
    
    // 必须有的请求头
    private $requiredHeaders = ['accept', 'accept-language', 'accept-encoding'];
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->loadConfig();
        $this->visitorIp = $this->getVisitorIp();
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $this->requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    }
    
    private function loadConfig() {
        $this->config = $this->db->getAntibotConfig();
    }
    
    public function setTargetIp($ip) {
        $this->targetIp = $ip;
    }
    
    public function getTargetIp() {
        return $this->targetIp;
    }
    
    private function getVisitorIp() {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    /**
     * 主检测方法
     */
    public function check() {
        if (!($this->config['enabled'] ?? true)) {
            return ['allowed' => true];
        }
        
        // 1. 检查IP白名单
        if ($this->isWhitelisted()) {
            return ['allowed' => true, 'reason' => 'whitelisted'];
        }
        
        // 2. 检查IP黑名单
        if ($this->isBlacklisted()) {
            return ['allowed' => false, 'reason' => 'blacklisted', 'message' => 'IP已被封禁'];
        }
        
        // 3. UA检测
        if ($this->config['ua_check']['enabled'] ?? true) {
            $uaResult = $this->checkUserAgent();
            if (!$uaResult['allowed']) {
                $this->logBlock('ua_check', $uaResult['detail'] ?? '');
                return $uaResult;
            }
        }
        
        // 4. 请求头检测
        if ($this->config['header_check']['enabled'] ?? true) {
            $headerResult = $this->checkHeaders();
            if (!$headerResult['allowed']) {
                $this->logBlock('header_check', $headerResult['detail'] ?? '');
                return $headerResult;
            }
        }
        
        // 5. 蜜罐检测
        if ($this->config['honeypot']['enabled'] ?? true) {
            $honeypotResult = $this->checkHoneypot();
            if (!$honeypotResult['allowed']) {
                if ($this->config['honeypot']['auto_block'] ?? true) {
                    $this->addToBlacklist($this->visitorIp, '触发蜜罐陷阱: ' . $this->requestUri);
                }
                $this->logBlock('honeypot', $this->requestUri);
                return $honeypotResult;
            }
        }
        
        // 6. 检查是否已被临时封禁
        if ($this->isBlocked()) {
            return ['allowed' => false, 'reason' => 'rate_blocked', 'message' => '请求过于频繁，请稍后再试'];
        }
        
        // 7. 频率限制
        if ($this->config['rate_limit']['enabled'] ?? true) {
            $rateResult = $this->checkRateLimit();
            if (!$rateResult['allowed']) {
                $this->blockIp($this->visitorIp, $this->config['rate_limit']['block_duration'] ?? 3600);
                $this->logBlock('rate_limit', '超出请求频率限制');
                return $rateResult;
            }
        }
        
        // 8. 行为分析
        if ($this->config['behavior_check']['enabled'] ?? true) {
            $behaviorResult = $this->checkBehavior();
            if (!$behaviorResult['allowed']) {
                $this->blockIp($this->visitorIp, $this->config['rate_limit']['block_duration'] ?? 3600);
                $this->logBlock('behavior', $behaviorResult['detail'] ?? '');
                return $behaviorResult;
            }
        }
        
        // 9. 恶意IP数据库检测
        if ($this->config['bad_ip_database']['enabled'] ?? true) {
            $badIpResult = $this->checkBadIpDatabase();
            if (!$badIpResult['allowed']) {
                $this->logBlock('bad_ip_database', $badIpResult['detail'] ?? '恶意IP');
                return $badIpResult;
            }
        }
        
        // 记录此次请求
        $this->recordRequest();
        
        return ['allowed' => true];
    }
    
    /**
     * 检查User-Agent
     */
    private function checkUserAgent() {
        $uaConfig = $this->config['ua_check'] ?? [];
        
        // 检查空UA
        if (($uaConfig['block_empty_ua'] ?? true) && empty(trim($this->userAgent))) {
            return ['allowed' => false, 'reason' => 'empty_ua', 'message' => 'Access Denied', 'detail' => 'UA为空'];
        }
        
        // 检查UA长度
        if (strlen($this->userAgent) < 30) {
            return ['allowed' => false, 'reason' => 'short_ua', 'message' => 'Access Denied', 'detail' => 'UA长度过短'];
        }
        
        $ua = strtolower($this->userAgent);
        
        // 检查Mozilla标识
        if (strpos($ua, 'mozilla') === false) {
            return ['allowed' => false, 'reason' => 'invalid_ua', 'message' => 'Access Denied', 'detail' => 'UA缺少Mozilla标识'];
        }
        
        // 检查UA白名单
        $uaWhitelist = $uaConfig['whitelist'] ?? [];
        foreach ($uaWhitelist as $keyword) {
            if (stripos($ua, strtolower($keyword)) !== false) {
                return ['allowed' => true];
            }
        }
        
        // 检查已知爬虫关键词
        if ($uaConfig['block_known_bots'] ?? true) {
            foreach ($this->botKeywords as $keyword) {
                if (strpos($ua, $keyword) !== false) {
                    return ['allowed' => false, 'reason' => 'known_bot', 'message' => 'Bot Access Denied', 'detail' => '检测到爬虫关键词: ' . $keyword];
                }
            }
        }
        
        return ['allowed' => true];
    }
    
    /**
     * 检查请求头
     */
    private function checkHeaders() {
        $headerConfig = $this->config['header_check'] ?? [];
        
        if (!($headerConfig['check_required_headers'] ?? true)) {
            return ['allowed' => true];
        }
        
        $missingHeaders = [];
        foreach ($this->requiredHeaders as $header) {
            $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
            if (empty($_SERVER[$serverKey])) {
                $missingHeaders[] = $header;
            }
        }
        
        if (count($missingHeaders) >= 2) {
            return [
                'allowed' => false,
                'reason' => 'missing_headers',
                'message' => 'Access Denied',
                'detail' => '缺少请求头: ' . implode(', ', $missingHeaders)
            ];
        }
        
        return ['allowed' => true];
    }
    
    /**
     * 蜜罐检测
     */
    private function checkHoneypot() {
        $path = strtolower(parse_url($this->requestUri, PHP_URL_PATH) ?: '/');
        
        foreach ($this->honeypotPaths as $honeypot) {
            if (strpos($path, strtolower($honeypot)) !== false) {
                return [
                    'allowed' => false,
                    'reason' => 'honeypot',
                    'message' => 'Not Found',
                    'http_code' => 404
                ];
            }
        }
        
        return ['allowed' => true];
    }
    
    /**
     * 恶意IP数据库检测
     */
    private function checkBadIpDatabase() {
        $config = $this->config['bad_ip_database'] ?? [];
        
        if ($config['block_malicious'] ?? true) {
            $result = BadIpDatabase::check($this->visitorIp);
            if (!$result['allowed']) {
                return [
                    'allowed' => false,
                    'reason' => 'bad_ip_database',
                    'message' => 'Access Denied',
                    'detail' => $result['detail'] ?? '已知恶意IP',
                    'http_code' => 403
                ];
            }
        }
        
        if ($config['block_datacenter'] ?? false) {
            $dcResult = BadIpDatabase::isDatacenter($this->visitorIp);
            if ($dcResult['is_datacenter']) {
                return [
                    'allowed' => false,
                    'reason' => 'datacenter_ip',
                    'message' => 'Access Denied',
                    'detail' => '数据中心IP: ' . $dcResult['range'],
                    'http_code' => 403
                ];
            }
        }
        
        if ($config['block_known_bots'] ?? false) {
            $botResult = BadIpDatabase::isKnownBot($this->visitorIp);
            if ($botResult['is_bot']) {
                return [
                    'allowed' => false,
                    'reason' => 'known_bot_ip',
                    'message' => 'Bot Access Denied',
                    'detail' => $botResult['reason'],
                    'http_code' => 403
                ];
            }
        }
        
        return ['allowed' => true];
    }
    
    /**
     * 频率限制检测
     */
    private function checkRateLimit() {
        $rateConfig = $this->config['rate_limit'] ?? [];
        $window = $rateConfig['time_window'] ?? 60;
        $maxRequests = $rateConfig['max_requests'] ?? 60;
        
        $count = $this->db->getRequestCount($this->visitorIp, $window);
        
        if ($count >= $maxRequests) {
            return [
                'allowed' => false,
                'reason' => 'rate_limit',
                'message' => '请求过于频繁，请稍后再试'
            ];
        }
        
        return ['allowed' => true];
    }
    
    /**
     * 行为分析
     */
    private function checkBehavior() {
        $behaviorConfig = $this->config['behavior_check'] ?? [];
        $window = $behaviorConfig['time_window'] ?? 300;
        $maxSuspicious = $behaviorConfig['suspicious_paths'] ?? 5;
        
        $suspiciousCount = $this->db->getSuspiciousPathCount($this->visitorIp, $window);
        
        if ($suspiciousCount >= $maxSuspicious) {
            return [
                'allowed' => false,
                'reason' => 'suspicious_behavior',
                'message' => 'Access Denied',
                'detail' => '访问可疑路径过多'
            ];
        }
        
        return ['allowed' => true];
    }
    
    /**
     * 记录请求
     */
    private function recordRequest() {
        $path = parse_url($this->requestUri, PHP_URL_PATH) ?: '/';
        $suspicious = $this->isSuspiciousPath($path);
        $this->db->recordAntibotRequest($this->visitorIp, $path, $suspicious);
    }
    
    /**
     * 判断是否为可疑路径
     */
    private function isSuspiciousPath($path) {
        $suspiciousPatterns = [
            '/\.php\d*$/',
            '/\.(bak|old|backup|sql|env|git|svn)/',
            '/(admin|login|wp-|phpmyadmin|config)/i',
            '/\.\.\//'
        ];
        
        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $path)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 检查是否在白名单
     */
    private function isWhitelisted() {
        return $this->db->isInAntibotWhitelist($this->visitorIp);
    }
    
    /**
     * 检查是否在黑名单
     */
    private function isBlacklisted() {
        return $this->db->isInAntibotBlacklist($this->visitorIp);
    }
    
    /**
     * 检查是否被临时封禁
     */
    private function isBlocked() {
        return $this->db->isBlocked($this->visitorIp);
    }
    
    /**
     * 临时封禁IP
     */
    private function blockIp($ip, $duration) {
        $this->db->blockIp($ip, $duration, 'auto_blocked');
    }
    
    /**
     * 添加到永久黑名单
     */
    public function addToBlacklist($ip, $reason = '') {
        $this->db->addToBlacklist($ip, $reason);
        $this->logBlock('blacklist_add', $reason);
    }
    
    /**
     * 记录封禁日志
     */
    private function logBlock($reason, $detail = '') {
        if (!($this->config['log_blocked'] ?? true)) {
            return;
        }
        
        // 更新统计
        $this->db->incrementAntibotStats($reason);
        
        // 记录详细日志
        $this->db->logBlock(
            $this->visitorIp,
            $this->targetIp,
            $reason,
            $detail,
            substr($this->userAgent, 0, 200),
            substr($this->requestUri, 0, 200)
        );
        
        // 检查是否需要自动加入黑名单
        $this->checkAutoBlacklist($reason);
    }
    
    /**
     * 检查是否需要自动加入黑名单
     */
    private function checkAutoBlacklist($currentReason) {
        $autoConfig = $this->config['auto_blacklist'] ?? [];
        
        if (!($autoConfig['enabled'] ?? true)) {
            return;
        }
        
        if ($this->isBlacklisted() || $this->isWhitelisted()) {
            return;
        }
        
        $maxBlocks = $autoConfig['max_blocks'] ?? 5;
        $timeWindow = $autoConfig['time_window'] ?? 300;
        $excludeReasons = $autoConfig['exclude_reasons'] ?? [];
        
        $recentBlocks = $this->db->getRecentBlockCount($this->visitorIp, $timeWindow, $excludeReasons);
        
        if ($recentBlocks >= $maxBlocks) {
            $this->addToBlacklist(
                $this->visitorIp,
                '自动拉黑: ' . $timeWindow . '秒内被拦截' . $recentBlocks . '次'
            );
        }
    }
    
    /**
     * 输出拦截响应
     */
    public function block($result) {
        $httpCode = $result['http_code'] ?? 403;
        $blockAction = $this->config['block_action'] ?? ['type' => 'error_page'];
        $actionType = $blockAction['type'] ?? 'error_page';
        
        // 随机延迟
        $delayMin = ($blockAction['delay_min'] ?? 100) * 1000;
        $delayMax = ($blockAction['delay_max'] ?? 500) * 1000;
        usleep(rand($delayMin, $delayMax));
        
        switch ($actionType) {
            case 'redirect':
                $redirectUrl = $blockAction['redirect_url'] ?? 'https://www.google.com';
                header('Location: ' . $redirectUrl, true, 302);
                exit;
                
            case 'silent_log':
                return;
                
            case 'fake_content':
                http_response_code(200);
                $fakeContent = $blockAction['fake_content'] ?? '<html><body>Page not found</body></html>';
                echo $fakeContent;
                exit;
                
            case 'slow_response':
                http_response_code($httpCode);
                sleep(rand(5, 15));
                $this->outputErrorPage($httpCode, $blockAction['custom_message'] ?? 'Access Denied');
                exit;
                
            case 'connection_reset':
                header('HTTP/1.1 500 Internal Server Error');
                header('Connection: close');
                exit;
                
            case 'tarpit':
                http_response_code(200);
                header('Content-Type: text/html');
                $chars = str_split('Loading... Please wait... ' . str_repeat('.', 1000));
                foreach ($chars as $char) {
                    echo $char;
                    flush();
                    usleep(100000);
                }
                exit;
                
            case 'random_error':
                $errorCodes = [400, 403, 404, 500, 502, 503];
                $randomCode = $errorCodes[array_rand($errorCodes)];
                http_response_code($randomCode);
                $this->outputErrorPage($randomCode, $blockAction['custom_message'] ?? 'Error');
                exit;
                
            case 'captcha':
                http_response_code(403);
                $this->outputCaptchaPage();
                exit;
                
            case 'error_page':
            default:
                http_response_code($httpCode);
                $this->outputErrorPage($httpCode, $blockAction['custom_message'] ?? $result['message'] ?? 'Access Denied');
                exit;
        }
    }
    
    /**
     * 输出错误页面
     */
    private function outputErrorPage($httpCode, $message) {
        echo '<!DOCTYPE html><html><head><title>' . ($httpCode == 404 ? 'Not Found' : 'Access Denied') . '</title>';
        echo '<style>body{font-family:Arial;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;background:#f5f5f5;}';
        echo '.container{text-align:center;padding:40px;background:white;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.1);}';
        echo 'h1{color:#e74c3c;margin-bottom:10px;}p{color:#666;}</style></head>';
        echo '<body><div class="container"><h1>' . $httpCode . '</h1>';
        echo '<p>' . htmlspecialchars($message) . '</p></div></body></html>';
    }
    
    /**
     * 输出验证码挑战页面
     */
    private function outputCaptchaPage() {
        echo '<!DOCTYPE html><html><head><title>安全验证</title>';
        echo '<style>body{font-family:Arial;display:flex;justify-content:center;align-items:center;height:100vh;margin:0;background:#f5f5f5;}';
        echo '.container{text-align:center;padding:40px;background:white;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.1);max-width:400px;}';
        echo 'h2{color:#333;margin-bottom:20px;}p{color:#666;margin-bottom:20px;}';
        echo 'button{padding:12px 30px;background:#667eea;color:white;border:none;border-radius:5px;cursor:pointer;font-size:16px;}';
        echo 'button:hover{background:#5a6fd6;}</style></head>';
        echo '<body><div class="container"><h2>🛡️ 安全验证</h2>';
        echo '<p>请点击下方按钮证明您是人类访客</p>';
        echo '<button onclick="verify()">我不是机器人</button>';
        echo '<script>function verify(){document.cookie="_antibot_human=1;path=/;max-age=3600";location.reload();}</script>';
        echo '</div></body></html>';
    }
    
    /**
     * 获取统计数据（静态方法，用于管理面板）
     */
    public static function getStats() {
        $db = Database::getInstance();
        return $db->getAntibotStats();
    }
    
    /**
     * 获取当前封禁列表
     */
    public static function getBlockedList() {
        $db = Database::getInstance();
        return $db->getBlockedList();
    }
    
    /**
     * 解除封禁
     */
    public static function unblock($ip) {
        $db = Database::getInstance();
        return $db->unblockIp($ip);
    }
    
    /**
     * 清空所有封禁
     */
    public static function clearAllBlocks() {
        $db = Database::getInstance();
        $db->clearAllBlocks();
        return true;
    }
    
    /**
     * 重置统计
     */
    public static function resetStats() {
        $db = Database::getInstance();
        $db->resetAntibotStats();
        return true;
    }
}
