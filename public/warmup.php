<?php
/**
 * 缓存预热脚本
 * 用于系统启动时预加载热点数据到缓存
 * 
 * 运行方式:
 * php /var/www/ip-manager/public/warmup.php
 */

require_once __DIR__ . '/../backend/core/database.php';
require_once __DIR__ . '/../backend/core/cache.php';

$cache = CacheService::getInstance();
$db = Database::getInstance();
$pdo = $db->getConnection();

echo "=== 缓存预热开始 ===\n\n";

// 1. 预热短链规则
echo "1. 预热短链规则...\n";
$stmt = $pdo->query("
    SELECT * FROM jump_rules 
    WHERE rule_type = 'code' AND enabled = 1 
    ORDER BY total_clicks DESC 
    LIMIT 5000
");

$codeCount = 0;
while ($rule = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $rule['enabled'] = (bool)$rule['enabled'];
    $rule['block_desktop'] = (bool)$rule['block_desktop'];
    $rule['block_ios'] = (bool)$rule['block_ios'];
    $rule['block_android'] = (bool)$rule['block_android'];
    $rule['country_whitelist_enabled'] = (bool)$rule['country_whitelist_enabled'];
    if ($rule['country_whitelist']) {
        $rule['country_whitelist'] = json_decode($rule['country_whitelist'], true) ?? [];
    }
    
    $cache->set("rule:code:{$rule['match_key']}", $rule, 600);
    $cache->bloomAdd("rule:code:{$rule['match_key']}");
    $codeCount++;
}
echo "   - 已预热 {$codeCount} 条短链规则\n";

// 2. 预热IP规则
echo "2. 预热IP规则...\n";
$stmt = $pdo->query("
    SELECT * FROM jump_rules 
    WHERE rule_type = 'ip' AND enabled = 1
");

$ipCount = 0;
while ($rule = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $rule['enabled'] = (bool)$rule['enabled'];
    $rule['block_desktop'] = (bool)$rule['block_desktop'];
    $rule['block_ios'] = (bool)$rule['block_ios'];
    $rule['block_android'] = (bool)$rule['block_android'];
    $rule['country_whitelist_enabled'] = (bool)$rule['country_whitelist_enabled'];
    if ($rule['country_whitelist']) {
        $rule['country_whitelist'] = json_decode($rule['country_whitelist'], true) ?? [];
    }
    
    $cache->set("rule:ip:{$rule['match_key']}", $rule, 600);
    $cache->bloomAdd("rule:ip:{$rule['match_key']}");
    $ipCount++;
}
echo "   - 已预热 {$ipCount} 条IP规则\n";

// 3. 预热域名列表
echo "3. 预热域名列表...\n";
$domains = $pdo->query("SELECT * FROM jump_domains WHERE enabled = 1")->fetchAll(PDO::FETCH_ASSOC);
$cache->set("domains:enabled", $domains, 600);
echo "   - 已预热 " . count($domains) . " 个域名\n";

// 4. 预热分组列表
echo "4. 预热分组列表...\n";
$groups = $pdo->query("SELECT * FROM jump_groups ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
$cache->set("groups:all", $groups, 600);
echo "   - 已预热 " . count($groups) . " 个分组\n";

// 5. 预热常见IP地理位置
echo "5. 预热IP地理位置缓存...\n";
$stmt = $pdo->query("
    SELECT ip, country_code FROM ip_country_cache 
    ORDER BY updated_at DESC 
    LIMIT 10000
");

$geoCount = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $cache->set("geo:{$row['ip']}", $row['country_code'], 3600);
    $geoCount++;
}
echo "   - 已预热 {$geoCount} 条地理位置缓存\n";

// 输出统计
echo "\n=== 缓存预热完成 ===\n";
$stats = $cache->getStats();
echo "内存缓存条目: {$stats['memory_items']}\n";
echo "布隆过滤器条目: {$stats['bloom_items']}\n";
echo "APCu状态: " . ($stats['apcu_enabled'] ? "已启用" : "未启用") . "\n";

if ($stats['apcu_enabled'] && $stats['apcu_info']) {
    $info = $stats['apcu_info'];
    echo "APCu使用内存: " . round($info['mem_size'] / 1024 / 1024, 2) . " MB\n";
    echo "APCu缓存命中: {$info['num_hits']}\n";
    echo "APCu缓存未命中: {$info['num_misses']}\n";
}
