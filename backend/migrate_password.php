<?php
/**
 * 密码迁移脚本
 * 将明文密码迁移到安全的哈希存储
 * 
 * 使用方法: php migrate_password.php
 */

require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/security.php';

echo "=== 密码安全迁移工具 ===\n\n";

try {
    $db = Database::getInstance();
    $security = Security::getInstance();
    
    // 获取当前密码配置
    $plainPassword = $db->getConfig('admin_password', '');
    $hashedPassword = $db->getConfig('admin_password_hash', '');
    
    echo "检查当前密码配置...\n";
    
    if (!empty($hashedPassword)) {
        echo "✓ 密码已经是哈希存储格式\n";
        echo "  哈希值前缀: " . substr($hashedPassword, 0, 7) . "...\n";
        
        // 检查是否需要重新哈希
        if ($security->needsRehash($hashedPassword)) {
            echo "! 检测到旧的哈希算法，建议用户重新设置密码以使用更安全的算法\n";
        }
        
        echo "\n无需迁移。\n";
        exit(0);
    }
    
    if (empty($plainPassword)) {
        echo "! 未找到明文密码配置\n";
        echo "  将使用默认密码 'admin123' 创建哈希\n";
        $plainPassword = 'admin123';
    } else {
        echo "✓ 找到明文密码配置\n";
    }
    
    // 生成哈希
    echo "\n正在生成密码哈希...\n";
    $newHash = $security->hashPassword($plainPassword);
    
    // 保存哈希
    $db->setConfig('admin_password_hash', $newHash);
    echo "✓ 密码哈希已保存\n";
    
    // 验证
    $savedHash = $db->getConfig('admin_password_hash', '');
    if ($security->verifyPassword($plainPassword, $savedHash)) {
        echo "✓ 密码哈希验证通过\n";
    } else {
        echo "✗ 密码哈希验证失败！请检查数据库\n";
        exit(1);
    }
    
    // 询问是否清除明文密码
    if (!empty($plainPassword) && $plainPassword !== 'admin123') {
        echo "\n是否清除明文密码? (y/n): ";
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        if (trim($line) === 'y') {
            $db->setConfig('admin_password', '');
            echo "✓ 明文密码已清除\n";
        } else {
            echo "- 保留明文密码作为备份\n";
        }
        fclose($handle);
    }
    
    echo "\n=== 迁移完成 ===\n";
    echo "密码已安全存储。\n";
    echo "哈希算法: bcrypt (cost=12)\n";
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}
