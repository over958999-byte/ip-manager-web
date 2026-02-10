<?php
/**
 * Webhook 通知服务
 * 支持企业微信、钉钉、飞书、自定义 Webhook
 */

class WebhookNotifier {
    private static $instance = null;
    private $db;
    
    // 通知类型
    const TYPE_WECOM = 'wecom';           // 企业微信
    const TYPE_DINGTALK = 'dingtalk';     // 钉钉
    const TYPE_FEISHU = 'feishu';         // 飞书
    const TYPE_SLACK = 'slack';           // Slack
    const TYPE_CUSTOM = 'custom';         // 自定义
    
    // 告警级别
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_CRITICAL = 'critical';
    
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
     * 发送通知
     */
    public function send(string $title, string $content, string $level = self::LEVEL_INFO, array $extra = []): array {
        $results = [];
        $webhooks = $this->getEnabledWebhooks();
        
        foreach ($webhooks as $webhook) {
            // 检查是否匹配告警级别
            $minLevel = $webhook['min_level'] ?? self::LEVEL_INFO;
            if (!$this->shouldNotify($level, $minLevel)) {
                continue;
            }
            
            $result = $this->sendToWebhook($webhook, $title, $content, $level, $extra);
            $results[$webhook['name']] = $result;
            
            // 记录发送日志
            $this->logNotification($webhook['id'], $title, $level, $result['success']);
        }
        
        return $results;
    }
    
    /**
     * 发送到指定 Webhook
     */
    private function sendToWebhook(array $webhook, string $title, string $content, string $level, array $extra): array {
        $type = $webhook['type'];
        $url = $webhook['url'];
        
        try {
            switch ($type) {
                case self::TYPE_WECOM:
                    return $this->sendWecom($url, $title, $content, $level, $extra);
                case self::TYPE_DINGTALK:
                    return $this->sendDingtalk($url, $title, $content, $level, $extra, $webhook['secret'] ?? null);
                case self::TYPE_FEISHU:
                    return $this->sendFeishu($url, $title, $content, $level, $extra, $webhook['secret'] ?? null);
                case self::TYPE_SLACK:
                    return $this->sendSlack($url, $title, $content, $level, $extra);
                case self::TYPE_CUSTOM:
                    return $this->sendCustom($url, $title, $content, $level, $extra, $webhook['headers'] ?? []);
                default:
                    return ['success' => false, 'error' => '不支持的 Webhook 类型'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * 发送企业微信通知
     */
    private function sendWecom(string $url, string $title, string $content, string $level, array $extra): array {
        $color = $this->getLevelColor($level, 'wecom');
        
        $data = [
            'msgtype' => 'markdown',
            'markdown' => [
                'content' => sprintf(
                    "## %s\n\n%s\n\n> 级别：<font color=\"%s\">%s</font>\n> 时间：%s",
                    $title,
                    $content,
                    $color,
                    strtoupper($level),
                    date('Y-m-d H:i:s')
                )
            ]
        ];
        
        // 添加 @ 功能
        if (!empty($extra['mentioned_list'])) {
            $data['markdown']['content'] .= "\n\n" . implode(' ', array_map(fn($u) => "@{$u}", $extra['mentioned_list']));
        }
        
        return $this->httpPost($url, $data);
    }
    
    /**
     * 发送钉钉通知
     */
    private function sendDingtalk(string $url, string $title, string $content, string $level, array $extra, ?string $secret): array {
        // 签名（如果有密钥）
        if ($secret) {
            $timestamp = time() * 1000;
            $sign = urlencode(base64_encode(hash_hmac('sha256', $timestamp . "\n" . $secret, $secret, true)));
            $url .= (strpos($url, '?') !== false ? '&' : '?') . "timestamp={$timestamp}&sign={$sign}";
        }
        
        $color = $this->getLevelColor($level, 'dingtalk');
        
        $data = [
            'msgtype' => 'markdown',
            'markdown' => [
                'title' => $title,
                'text' => sprintf(
                    "## %s\n\n%s\n\n> **级别**：%s  \n> **时间**：%s",
                    $title,
                    $content,
                    strtoupper($level),
                    date('Y-m-d H:i:s')
                )
            ]
        ];
        
        // @ 功能
        if (!empty($extra['at_mobiles'])) {
            $data['at'] = ['atMobiles' => $extra['at_mobiles'], 'isAtAll' => false];
        }
        if (!empty($extra['at_all'])) {
            $data['at'] = ['isAtAll' => true];
        }
        
        return $this->httpPost($url, $data);
    }
    
    /**
     * 发送飞书通知
     */
    private function sendFeishu(string $url, string $title, string $content, string $level, array $extra, ?string $secret): array {
        $timestamp = time();
        
        $data = [
            'msg_type' => 'interactive',
            'card' => [
                'header' => [
                    'title' => ['tag' => 'plain_text', 'content' => $title],
                    'template' => $this->getLevelColor($level, 'feishu')
                ],
                'elements' => [
                    ['tag' => 'markdown', 'content' => $content],
                    ['tag' => 'note', 'elements' => [
                        ['tag' => 'plain_text', 'content' => sprintf('级别: %s | 时间: %s', strtoupper($level), date('Y-m-d H:i:s'))]
                    ]]
                ]
            ]
        ];
        
        // 签名
        if ($secret) {
            $sign = base64_encode(hash_hmac('sha256', '', $timestamp . "\n" . $secret, true));
            $data['timestamp'] = (string)$timestamp;
            $data['sign'] = $sign;
        }
        
        return $this->httpPost($url, $data);
    }
    
    /**
     * 发送 Slack 通知
     */
    private function sendSlack(string $url, string $title, string $content, string $level, array $extra): array {
        $color = $this->getLevelColor($level, 'slack');
        
        $data = [
            'attachments' => [[
                'color' => $color,
                'title' => $title,
                'text' => $content,
                'fields' => [
                    ['title' => 'Level', 'value' => strtoupper($level), 'short' => true],
                    ['title' => 'Time', 'value' => date('Y-m-d H:i:s'), 'short' => true]
                ],
                'footer' => '困King分发平台',
                'ts' => time()
            ]]
        ];
        
        return $this->httpPost($url, $data);
    }
    
    /**
     * 发送自定义 Webhook
     */
    private function sendCustom(string $url, string $title, string $content, string $level, array $extra, array $headers): array {
        $data = [
            'title' => $title,
            'content' => $content,
            'level' => $level,
            'timestamp' => time(),
            'datetime' => date('Y-m-d H:i:s'),
            'extra' => $extra
        ];
        
        return $this->httpPost($url, $data, $headers);
    }
    
    /**
     * HTTP POST 请求
     */
    private function httpPost(string $url, array $data, array $headers = []): array {
        $defaultHeaders = [
            'Content-Type: application/json',
            'User-Agent: IPManager-Webhook/1.0'
        ];
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => $error, 'http_code' => 0];
        }
        
        $success = $httpCode >= 200 && $httpCode < 300;
        $responseData = json_decode($response, true);
        
        // 检查各平台的特定响应
        if ($success && $responseData) {
            if (isset($responseData['errcode']) && $responseData['errcode'] !== 0) {
                $success = false;
            }
            if (isset($responseData['StatusCode']) && $responseData['StatusCode'] !== 0) {
                $success = false;
            }
        }
        
        return [
            'success' => $success,
            'http_code' => $httpCode,
            'response' => $responseData
        ];
    }
    
    /**
     * 获取级别对应的颜色
     */
    private function getLevelColor(string $level, string $platform): string {
        $colors = [
            'wecom' => [
                self::LEVEL_INFO => 'info',
                self::LEVEL_WARNING => 'warning',
                self::LEVEL_ERROR => 'warning',
                self::LEVEL_CRITICAL => 'comment'
            ],
            'dingtalk' => [
                self::LEVEL_INFO => '#1890ff',
                self::LEVEL_WARNING => '#faad14',
                self::LEVEL_ERROR => '#f5222d',
                self::LEVEL_CRITICAL => '#722ed1'
            ],
            'feishu' => [
                self::LEVEL_INFO => 'blue',
                self::LEVEL_WARNING => 'orange',
                self::LEVEL_ERROR => 'red',
                self::LEVEL_CRITICAL => 'purple'
            ],
            'slack' => [
                self::LEVEL_INFO => '#36a64f',
                self::LEVEL_WARNING => '#daa038',
                self::LEVEL_ERROR => '#d00000',
                self::LEVEL_CRITICAL => '#8b0000'
            ]
        ];
        
        return $colors[$platform][$level] ?? $colors[$platform][self::LEVEL_INFO];
    }
    
    /**
     * 检查是否应该通知
     */
    private function shouldNotify(string $level, string $minLevel): bool {
        $levels = [
            self::LEVEL_INFO => 0,
            self::LEVEL_WARNING => 1,
            self::LEVEL_ERROR => 2,
            self::LEVEL_CRITICAL => 3
        ];
        
        return ($levels[$level] ?? 0) >= ($levels[$minLevel] ?? 0);
    }
    
    /**
     * 获取启用的 Webhooks
     */
    private function getEnabledWebhooks(): array {
        if (!$this->db) return [];
        
        try {
            $pdo = $this->db->getPdo();
            $stmt = $pdo->query("SELECT * FROM webhooks WHERE enabled = 1 ORDER BY id");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * 记录通知日志
     */
    private function logNotification(int $webhookId, string $title, string $level, bool $success): void {
        if (!$this->db) return;
        
        try {
            $pdo = $this->db->getPdo();
            $stmt = $pdo->prepare(
                "INSERT INTO webhook_logs (webhook_id, title, level, success, created_at) VALUES (?, ?, ?, ?, NOW())"
            );
            $stmt->execute([$webhookId, $title, $level, $success ? 1 : 0]);
        } catch (Exception $e) {
            // 忽略
        }
    }
    
    // ==================== 快捷方法 ====================
    
    /**
     * 发送信息通知
     */
    public function info(string $title, string $content, array $extra = []): array {
        return $this->send($title, $content, self::LEVEL_INFO, $extra);
    }
    
    /**
     * 发送警告通知
     */
    public function warning(string $title, string $content, array $extra = []): array {
        return $this->send($title, $content, self::LEVEL_WARNING, $extra);
    }
    
    /**
     * 发送错误通知
     */
    public function error(string $title, string $content, array $extra = []): array {
        return $this->send($title, $content, self::LEVEL_ERROR, $extra);
    }
    
    /**
     * 发送严重告警
     */
    public function critical(string $title, string $content, array $extra = []): array {
        return $this->send($title, $content, self::LEVEL_CRITICAL, $extra);
    }
    
    // ==================== 预定义告警 ====================
    
    /**
     * 登录失败告警
     */
    public function alertLoginFailed(string $ip, int $attempts): array {
        return $this->warning(
            '🔐 登录失败告警',
            "IP **{$ip}** 连续登录失败 **{$attempts}** 次",
            ['ip' => $ip, 'attempts' => $attempts]
        );
    }
    
    /**
     * IP 锁定告警
     */
    public function alertIpLocked(string $ip, int $duration): array {
        return $this->error(
            '🚫 IP 被锁定',
            "IP **{$ip}** 因多次登录失败已被锁定 **{$duration}** 秒",
            ['ip' => $ip, 'duration' => $duration]
        );
    }
    
    /**
     * 系统异常告警
     */
    public function alertSystemError(string $error, array $context = []): array {
        return $this->critical(
            '⚠️ 系统异常',
            "发生系统异常：\n```\n{$error}\n```",
            $context
        );
    }
    
    /**
     * 服务状态告警
     */
    public function alertServiceDown(string $service, string $reason = ''): array {
        return $this->critical(
            '🔴 服务异常',
            "服务 **{$service}** 状态异常" . ($reason ? "：{$reason}" : ''),
            ['service' => $service, 'reason' => $reason]
        );
    }
    
    /**
     * 域名安全告警
     */
    public function alertDomainUnsafe(string $domain, string $reason): array {
        return $this->error(
            '🌐 域名安全告警',
            "域名 **{$domain}** 检测到安全问题：{$reason}",
            ['domain' => $domain, 'reason' => $reason]
        );
    }
}

// 便捷函数
function webhook_notify(string $title, string $content, string $level = 'info'): array {
    return WebhookNotifier::getInstance()->send($title, $content, $level);
}
