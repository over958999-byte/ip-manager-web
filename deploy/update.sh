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

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
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

# 检查并生成SSL证书 (用于Cloudflare Full模式)
log_step "检查SSL证书..."
if [ ! -f "/etc/nginx/ssl/server.crt" ] || [ ! -f "/etc/nginx/ssl/server.key" ]; then
    log_info "生成自签名SSL证书..."
    mkdir -p /etc/nginx/ssl
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
        -keyout /etc/nginx/ssl/server.key \
        -out /etc/nginx/ssl/server.crt \
        -subj "/CN=localhost/O=IP Manager/C=US" 2>/dev/null
    chmod 600 /etc/nginx/ssl/server.key
    chmod 644 /etc/nginx/ssl/server.crt
    log_info "SSL证书生成完成"
else
    log_info "SSL证书已存在，跳过生成"
fi

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
    
    # 检查 cf_domains 表是否存在 (Cloudflare域名管理)
    if ! mysql -e "SELECT 1 FROM ${DB_NAME}.cf_domains LIMIT 1" 2>/dev/null; then
        log_info "创建 cf_domains 表..."
        mysql "$DB_NAME" -e "
        CREATE TABLE IF NOT EXISTS cf_domains (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            domain VARCHAR(255) NOT NULL,
            zone_id VARCHAR(50) DEFAULT NULL,
            status ENUM('pending', 'active', 'moved', 'deleted') DEFAULT 'pending',
            ns_servers TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_domain (domain)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        "
    fi
fi

# 重新构建前端
log_step "重新构建前端..."
cd "$INSTALL_DIR/backend/frontend"

# 检查 node 是否可用
if command -v node &> /dev/null && command -v npm &> /dev/null; then
    log_info "Node.js 版本: $(node -v)"
    log_info "npm 版本: $(npm -v)"
    
    # 安装依赖
    if [ ! -d "node_modules" ]; then
        log_info "安装依赖..."
        npm install
    fi
    
    # 编译
    log_info "编译前端..."
    npm run build
    
    # 编译产物会自动输出到 ../../dist (即 $INSTALL_DIR/dist)
    if [ -d "$INSTALL_DIR/dist" ]; then
        log_info "前端编译成功！"
    else
        log_error "前端编译失败，dist 目录不存在"
    fi
else
    log_error "未检测到 Node.js 环境，跳过前端编译"
    log_info "请手动安装 Node.js 后执行: cd backend/frontend && npm install && npm run build"
fi

cd "$INSTALL_DIR"

# 设置权限
log_step "设置文件权限..."
chown -R www-data:www-data "$INSTALL_DIR" 2>/dev/null || chown -R nginx:nginx "$INSTALL_DIR"

# 检查Nginx配置是否支持HTTPS
log_step "检查Nginx配置..."
if ! grep -q "listen 443 ssl" /etc/nginx/sites-enabled/ip-manager 2>/dev/null && \
   ! grep -q "listen 443 ssl" /etc/nginx/conf.d/ip-manager.conf 2>/dev/null; then
    log_warn "Nginx配置未启用HTTPS，建议重新运行 install.sh 更新配置"
fi

# 重启服务
log_step "重启服务..."
systemctl restart php*-fpm 2>/dev/null || systemctl restart php-fpm
systemctl restart nginx

echo ""
echo "=============================================="
echo -e "${GREEN}更新完成！${NC}"
echo "=============================================="
echo ""
