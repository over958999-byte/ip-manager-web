#!/bin/bash

#===============================================================================
# IP管理器 - 更新脚本
# 用于从GitHub仓库拉取最新代码并重新部署
#===============================================================================

set -e

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# 配置
INSTALL_DIR="/var/www/ip-manager"

log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_step() {
    echo -e "${BLUE}[STEP]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# 检查root权限
if [ "$EUID" -ne 0 ]; then
    log_error "请使用root用户运行此脚本"
    exit 1
fi

# 检查安装目录
if [ ! -d "$INSTALL_DIR" ]; then
    log_error "安装目录不存在: $INSTALL_DIR"
    exit 1
fi

echo ""
echo "=============================================="
echo -e "${BLUE}IP管理器 - 更新脚本${NC}"
echo "=============================================="
echo ""

cd "$INSTALL_DIR"

# 备份配置文件
log_step "备份配置文件..."
cp -f backend/core/db_config.php /tmp/db_config.php.bak 2>/dev/null || true

# 拉取最新代码
log_step "拉取最新代码..."
git fetch origin
git reset --hard origin/master

# 恢复配置文件
log_step "恢复配置文件..."
cp -f /tmp/db_config.php.bak backend/core/db_config.php 2>/dev/null || true

# 运行数据库迁移
log_step "检查数据库迁移..."
if [ -f "backend/core/db_config.php" ]; then
    # 从配置文件提取数据库信息
    DB_NAME=$(grep "DB_NAME" backend/core/db_config.php | sed "s/.*'\\([^']*\\)'.*/\\1/")
    
    # 检查 jump_rules 表是否存在
    if ! mysql -e "SELECT 1 FROM ${DB_NAME}.jump_rules LIMIT 1" 2>/dev/null; then
        log_info "运行跳转规则迁移..."
        if [ -f "backend/migrations/merge_jump_rules.sql" ]; then
            mysql "$DB_NAME" < backend/migrations/merge_jump_rules.sql
        fi
    fi
    
    # 检查 jump_domains 表是否存在
    if ! mysql -e "SELECT 1 FROM ${DB_NAME}.jump_domains LIMIT 1" 2>/dev/null; then
        log_info "创建 jump_domains 表..."
        mysql "$DB_NAME" -e "
        CREATE TABLE IF NOT EXISTS jump_domains (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            domain VARCHAR(255) NOT NULL,
            name VARCHAR(100) DEFAULT '',
            is_default TINYINT(1) DEFAULT 0,
            enabled TINYINT(1) DEFAULT 1,
            use_count INT UNSIGNED DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_domain (domain)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        "
    fi
    
    # 检查 jump_rules.domain_id 字段是否存在
    if ! mysql -e "SELECT domain_id FROM ${DB_NAME}.jump_rules LIMIT 1" 2>/dev/null; then
        log_info "添加 domain_id 字段..."
        mysql "$DB_NAME" -e "ALTER TABLE jump_rules ADD COLUMN domain_id INT UNSIGNED DEFAULT NULL AFTER group_tag;"
    fi
fi

# 重新构建前端
log_step "重新构建前端..."
cd "$INSTALL_DIR/backend/frontend"
npm install
npm run build

# 复制构建产物
if [ -d "dist" ]; then
    mkdir -p "$INSTALL_DIR/public/admin"
    cp -r dist/* "$INSTALL_DIR/public/admin/"
fi

# 设置权限
log_step "设置文件权限..."
chown -R www-data:www-data "$INSTALL_DIR" 2>/dev/null || chown -R nginx:nginx "$INSTALL_DIR"

# 重启服务
log_step "重启服务..."
systemctl restart php*-fpm 2>/dev/null || systemctl restart php-fpm
systemctl restart nginx

echo ""
echo "=============================================="
echo -e "${GREEN}更新完成！${NC}"
echo "=============================================="
echo ""
