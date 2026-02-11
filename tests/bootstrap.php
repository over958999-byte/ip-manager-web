<?php
/**
 * PHPUnit 测试引导文件
 */

// 定义测试环境
define('TESTING', true);

// 错误报告
error_reporting(E_ALL);
ini_set('display_errors', '1');

// 加载核心模块
require_once __DIR__ . '/../backend/core/utils.php';
require_once __DIR__ . '/../backend/core/container.php';
require_once __DIR__ . '/../backend/core/lru_cache.php';
require_once __DIR__ . '/../backend/core/security_enhanced.php';

// 测试辅助函数
function createTestDatabase(): void {
    // 在测试环境中创建测试数据库
}

function cleanTestDatabase(): void {
    // 清理测试数据
}
