<?php
// 后台任务处理器
// 用于处理消息队列中的异步任务
// 
// 运行方式:
// - 定时任务: 每分钟执行一次 (crontab -e 添加定时任务)
// - 后台守护: nohup php worker.php daemon &

require_once __DIR__ . '/../backend/core/database.php';
require_once __DIR__ . '/../backend/core/message_queue.php';

$queue = MessageQueue::getInstance();
$db = Database::getInstance();
$pdo = $db->getConnection();

/**
 * 处理访问日志
 */
function processAccessLogs(): int {
    global $queue, $pdo;
    
    return $queue->process('access_log', function($data) use ($pdo) {
        $stmt = $pdo->prepare("
            INSERT INTO jump_logs (rule_id, visitor_ip, device_type, user_agent, referer, created_at)
            VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?))
        ");
        $stmt->execute([
            $data['rule_id'],
            $data['ip'],
            $data['device'],
            $data['ua'],
            $data['referer'] ?? '',
            (int)$data['time'],
        ]);
    }, 100);
}

/**
 * 同步点击统计
 */
function syncClickStats(): int {
    global $queue, $pdo;
    
    return $queue->process('click_sync', function($data) use ($pdo) {
        $stmt = $pdo->prepare("
            UPDATE jump_rules SET total_clicks = total_clicks + ? WHERE id = ?
        ");
        $stmt->execute([$data['clicks'], $data['rule_id']]);
    }, 50);
}

/**
 * 更新UV统计
 */
function updateUvStats(): void {
    global $pdo;
    
    // 统计今日UV
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT rule_id, COUNT(DISTINCT visitor_ip) as uv
        FROM jump_logs
        WHERE DATE(created_at) = ?
        GROUP BY rule_id
    ");
    $stmt->execute([$today]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $update = $pdo->prepare("
            INSERT INTO jump_uv (rule_id, date, uv_count)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE uv_count = ?
        ");
        $update->execute([$row['rule_id'], $today, $row['uv'], $row['uv']]);
    }
}

/**
 * 清理过期数据
 */
function cleanupExpiredData(): void {
    global $pdo;
    
    // 清理30天前的日志
    $pdo->exec("DELETE FROM jump_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    
    // 清理90天前的UV统计
    $pdo->exec("DELETE FROM jump_uv WHERE date < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    
    // 清理IP国家缓存（7天未更新的）
    $pdo->exec("DELETE FROM ip_country_cache WHERE updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
}

/**
 * 预热缓存
 */
function warmupCache(): int {
    global $pdo;
    
    require_once __DIR__ . '/../backend/core/cache.php';
    $cache = CacheService::getInstance();
    
    // 获取热门短链
    $stmt = $pdo->query("
        SELECT * FROM jump_rules 
        WHERE rule_type = 'code' AND enabled = 1 
        ORDER BY total_clicks DESC 
        LIMIT 1000
    ");
    
    $count = 0;
    while ($rule = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $cache->set("rule:code:{$rule['match_key']}", $rule, 600);
        $cache->bloomAdd("rule:code:{$rule['match_key']}");
        $count++;
    }
    
    return $count;
}

// ==================== 主入口 ====================

$mode = $argv[1] ?? 'once';
$startTime = time();
$maxRuntime = 300; // 最多运行5分钟

echo "[" . date('Y-m-d H:i:s') . "] Worker started in {$mode} mode\n";

do {
    // 处理访问日志
    $logCount = processAccessLogs();
    if ($logCount > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] Processed {$logCount} access logs\n";
    }
    
    // 同步点击统计
    $clickCount = syncClickStats();
    if ($clickCount > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] Synced {$clickCount} click stats\n";
    }
    
    // 每分钟更新UV
    if (time() % 60 < 5) {
        updateUvStats();
    }
    
    // 每小时清理和预热
    if (date('i') === '00' && date('s') < '10') {
        cleanupExpiredData();
        $warmCount = warmupCache();
        echo "[" . date('Y-m-d H:i:s') . "] Cache warmup: {$warmCount} rules\n";
    }
    
    // 守护模式下持续运行
    if ($mode === 'daemon') {
        sleep(1);
    }
    
} while ($mode === 'daemon' && (time() - $startTime) < $maxRuntime);

echo "[" . date('Y-m-d H:i:s') . "] Worker finished\n";
