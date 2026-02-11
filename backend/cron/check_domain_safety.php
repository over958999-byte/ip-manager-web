#!/usr/bin/env php
<?php
/**
 * 域名安全状态定时检测脚本
 * 
 * 使用方法：
 * 1. 添加到 crontab 每分钟执行：
 *    * * * * * php /path/to/backend/cron/check_domain_safety.php >> /var/log/domain_safety.log 2>&1
 * 
 * 2. 或者使用 systemd timer
 */

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 引入数据库
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/domain_safety.php';

// 获取锁文件路径
$lockFile = sys_get_temp_dir() . '/domain_safety_check.lock';

// 检查是否有其他进程在运行
if (file_exists($lockFile)) {
    $pid = file_get_contents($lockFile);
    // 检查进程是否还在运行
    if ($pid && posix_getpgid($pid) !== false) {
        echo date('Y-m-d H:i:s') . " - 另一个检测进程正在运行 (PID: $pid)，跳过本次检测\n";
        exit(0);
    }
}

// 创建锁文件
file_put_contents($lockFile, getmypid());

// 确保脚本结束时删除锁文件
register_shutdown_function(function() use ($lockFile) {
    @unlink($lockFile);
});

try {
    echo date('Y-m-d H:i:s') . " - 开始域名安全检测\n";
    
    $db = Database::getInstance();
    $pdo = $db->getPdo();
    
    // 获取配置
    $checker = new DomainSafetyChecker($pdo);
    $config = $checker->getConfig();
    
    // 检查是否启用
    if (empty($config['enabled'])) {
        echo date('Y-m-d H:i:s') . " - 域名安全检测已禁用，跳过\n";
        exit(0);
    }
    
    // 获取检测间隔（分钟）
    $interval = intval($config['interval'] ?? 60);
    
    // 获取需要检测的域名（上次检测时间超过间隔的）
    $stmt = $pdo->prepare("
        SELECT id, domain, safety_status, last_check_at
        FROM jump_domains 
        WHERE enabled = 1 
        AND (last_check_at IS NULL OR last_check_at < DATE_SUB(NOW(), INTERVAL ? MINUTE))
        ORDER BY last_check_at ASC
        LIMIT 10
    ");
    $stmt->execute([$interval]);
    $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($domains)) {
        echo date('Y-m-d H:i:s') . " - 没有需要检测的域名\n";
        exit(0);
    }
    
    echo date('Y-m-d H:i:s') . " - 发现 " . count($domains) . " 个域名需要检测\n";
    
    $dangerCount = 0;
    $warningCount = 0;
    
    foreach ($domains as $domain) {
        echo date('Y-m-d H:i:s') . " - 检测: {$domain['domain']}\n";
        
        $result = $checker->checkDomain($domain['domain'], $domain['id']);
        
        if ($result['status'] === 'danger') {
            $dangerCount++;
            echo date('Y-m-d H:i:s') . " - ⚠️ 危险: {$domain['domain']}\n";
        } elseif ($result['status'] === 'warning') {
            $warningCount++;
            echo date('Y-m-d H:i:s') . " - ⚠ 警告: {$domain['domain']}\n";
        } else {
            echo date('Y-m-d H:i:s') . " - ✓ 安全: {$domain['domain']}\n";
        }
        
        // 避免请求过快
        usleep(500000); // 0.5秒
    }
    
    echo date('Y-m-d H:i:s') . " - 检测完成，危险: $dangerCount，警告: $warningCount\n";
    
    // 如果发现危险或警告域名，发送 Webhook 通知
    if ($dangerCount > 0 || $warningCount > 0) {
        sendDomainSafetyNotification($pdo, $dangerCount, $warningCount);
    }
    
} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " - 错误: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * 发送域名安全检测通知
 */
function sendDomainSafetyNotification(PDO $pdo, int $dangerCount, int $warningCount): void
{
    try {
        // 引入 Webhook 模块
        require_once __DIR__ . '/../core/webhook.php';
        
        $webhook = new Webhook($pdo);
        
        // 获取危险域名列表
        $stmt = $pdo->prepare("
            SELECT domain, safety_status, safety_details 
            FROM jump_domains 
            WHERE safety_status IN ('danger', 'warning')
            AND last_check_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            LIMIT 10
        ");
        $stmt->execute();
        $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 构建通知内容
        $domainList = array_map(fn($d) => "• {$d['domain']} ({$d['safety_status']})", $domains);
        
        $message = "🔒 **域名安全检测报告**\n\n";
        $message .= "⚠️ 危险域名: {$dangerCount} 个\n";
        $message .= "⚡ 警告域名: {$warningCount} 个\n\n";
        
        if (!empty($domainList)) {
            $message .= "**问题域名列表:**\n" . implode("\n", $domainList);
        }
        
        // 发送到所有配置的 Webhook
        $webhook->sendToAll('domain_safety', [
            'title'        => '域名安全检测报告',
            'message'      => $message,
            'danger_count' => $dangerCount,
            'warning_count' => $warningCount,
            'domains'      => $domains,
            'check_time'   => date('Y-m-d H:i:s'),
        ]);
        
        echo date('Y-m-d H:i:s') . " - 已发送 Webhook 通知\n";
        
    } catch (Exception $e) {
        echo date('Y-m-d H:i:s') . " - 发送通知失败: " . $e->getMessage() . "\n";
    }
}
