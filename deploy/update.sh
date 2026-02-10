#!/bin/bash

#===============================================================================
# IP管理器 - 更新脚本
# 用于从GitHub仓库拉取最新代码并重新部署
# 
# 功能:
#   - 自动备份和恢复配置
#   - 数据库迁移
#   - 前端重新编译
#   - 安全设置更新
#   - 缓存预热
#   - 健康检查
#===============================================================================

set -e

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# 配置
INSTALL_DIR="/var/www/ip-manager"
BACKUP_DIR="/tmp/ip-manager-backup-$(date +%Y%m%d%H%M%S)"

log_info() {
    echo -e "${GREEN}[✓]${NC} $1"
}

log_step() {
    echo -e "${BLUE}[STEP]${NC} $1"
}

log_error() {
    echo -e "${RED}[✗]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[!]${NC} $1"
}

log_check() {
    echo -e "${CYAN}[CHECK]${NC} $1"
}

# 显示帮助
show_help() {
    echo "用法: $0 [选项]"
    echo ""
    echo "选项:"
    echo "  -f, --force       强制更新（跳过确认）"
    echo "  -s, --skip-build  跳过前端编译"
    echo "  -n, --no-cache    跳过缓存预热"
    echo "  -h, --help        显示帮助信息"
    echo ""
}

# 解析参数
FORCE_UPDATE=false
SKIP_BUILD=false
SKIP_CACHE=false

while [[ $# -gt 0 ]]; do
    case $1 in
        -f|--force)
            FORCE_UPDATE=true
            shift
            ;;
        -s|--skip-build)
            SKIP_BUILD=true
            shift
            ;;
        -n|--no-cache)
            SKIP_CACHE=true
            shift
            ;;
        -h|--help)
            show_help
            exit 0
            ;;
        *)
            log_error "未知参数: $1"
            show_help
            exit 1
            ;;
    esac
done

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
echo -e "${CYAN}安全更新 | 缓存预热 | 健康检查${NC}"
echo "=============================================="
echo ""

cd "$INSTALL_DIR"

# 获取当前版本信息
CURRENT_COMMIT=$(git rev-parse --short HEAD 2>/dev/null || echo "unknown")
log_info "当前版本: $CURRENT_COMMIT"

# 检查远程更新
git fetch origin 2>/dev/null || true
REMOTE_COMMIT=$(git rev-parse --short origin/master 2>/dev/null || git rev-parse --short origin/main 2>/dev/null || echo "unknown")
log_info "远程版本: $REMOTE_COMMIT"

if [ "$CURRENT_COMMIT" = "$REMOTE_COMMIT" ] && [ "$FORCE_UPDATE" = false ]; then
    log_info "已是最新版本，无需更新"
    echo ""
    read -p "是否强制更新? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 0
    fi
fi

# 备份配置文件
log_step "备份配置文件..."
mkdir -p "$BACKUP_DIR"
cp -f backend/core/db_config.php "$BACKUP_DIR/db_config.php" 2>/dev/null || true
cp -rf logs "$BACKUP_DIR/logs" 2>/dev/null || true
cp -f install_info.txt "$BACKUP_DIR/install_info.txt" 2>/dev/null || true
log_info "配置已备份到: $BACKUP_DIR"

# 拉取最新代码
log_step "拉取最新代码..."
git fetch origin
git reset --hard origin/master 2>/dev/null || git reset --hard origin/main

# 恢复配置文件
log_step "恢复配置文件..."
cp -f "$BACKUP_DIR/db_config.php" backend/core/db_config.php 2>/dev/null || true
mkdir -p logs
cp -rf "$BACKUP_DIR/logs"/* logs/ 2>/dev/null || true

NEW_COMMIT=$(git rev-parse --short HEAD 2>/dev/null || echo "unknown")
log_info "已更新到版本: $NEW_COMMIT"

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
    
    # 检查安全相关配置
    log_info "检查安全配置..."
    mysql "$DB_NAME" -e "
    INSERT IGNORE INTO config (\`key\`, \`value\`) VALUES ('admin_password_hash', '');
    INSERT INTO config (\`key\`, \`value\`) VALUES ('csrf_enabled', 'false')
    ON DUPLICATE KEY UPDATE \`value\` = \`value\`;
    INSERT INTO config (\`key\`, \`value\`) VALUES ('warmup_secret_key', '$(openssl rand -hex 16)')
    ON DUPLICATE KEY UPDATE \`value\` = \`value\`;
    INSERT INTO config (\`key\`, \`value\`) VALUES ('security_config', '{\"session_lifetime\":1800,\"max_login_attempts\":5,\"lockout_duration\":900}')
    ON DUPLICATE KEY UPDATE \`value\` = \`value\`;
    " 2>/dev/null || true
fi

# 检查并创建日志目录
log_step "检查日志目录..."
mkdir -p "$INSTALL_DIR/logs"
chown www-data:www-data "$INSTALL_DIR/logs" 2>/dev/null || chown nginx:nginx "$INSTALL_DIR/logs" 2>/dev/null || true
chmod 755 "$INSTALL_DIR/logs"
log_info "日志目录已就绪"

# 重新构建前端
if [ "$SKIP_BUILD" = true ]; then
    log_info "跳过前端编译 (--skip-build)"
else
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
    log_warn "未检测到 Node.js 环境，跳过前端编译"
    log_info "请手动安装 Node.js 后执行: cd backend/frontend && npm install && npm run build"
fi

cd "$INSTALL_DIR"
fi

# 设置权限
log_step "设置文件权限..."
chown -R www-data:www-data "$INSTALL_DIR" 2>/dev/null || chown -R nginx:nginx "$INSTALL_DIR"

# 检查Nginx配置是否支持HTTPS和验证端点
log_step "检查Nginx配置..."
NGINX_CONF=""
if [ -f "/etc/nginx/sites-enabled/ip-manager" ]; then
    NGINX_CONF="/etc/nginx/sites-enabled/ip-manager"
elif [ -f "/etc/nginx/conf.d/ip-manager.conf" ]; then
    NGINX_CONF="/etc/nginx/conf.d/ip-manager.conf"
fi

NGINX_NEEDS_UPDATE=false

if [ -n "$NGINX_CONF" ]; then
    # 检查是否启用HTTPS
    if ! grep -q "listen 443 ssl" "$NGINX_CONF"; then
        log_warn "Nginx配置未启用HTTPS"
        NGINX_NEEDS_UPDATE=true
    fi
    
    # 检查是否有验证端点 (用于Cloudflare域名解析验证)
    if ! grep -q "_verify_server" "$NGINX_CONF"; then
        log_warn "Nginx配置缺少验证端点 _verify_server"
        NGINX_NEEDS_UPDATE=true
    fi
    
    # 检查是否有短链接路由
    if ! grep -q "s.php" "$NGINX_CONF"; then
        log_warn "Nginx配置缺少短链接路由"
        NGINX_NEEDS_UPDATE=true
    fi
    
    if [ "$NGINX_NEEDS_UPDATE" = true ]; then
        log_info "更新Nginx配置..."
        
        # 检测PHP-FPM socket路径
        PHP_FPM_SOCK=$(find /run/php -name "*.sock" 2>/dev/null | head -1)
        if [ -z "$PHP_FPM_SOCK" ]; then
            PHP_FPM_SOCK=$(find /var/run/php-fpm -name "*.sock" 2>/dev/null | head -1)
        fi
        if [ -n "$PHP_FPM_SOCK" ]; then
            FASTCGI_PASS="fastcgi_pass unix:${PHP_FPM_SOCK};"
        else
            FASTCGI_PASS="fastcgi_pass 127.0.0.1:9000;"
        fi
        
        cat > "$NGINX_CONF" << NGINXEOF
server {
    listen 80;
    listen 443 ssl;
    server_name _;
    root ${INSTALL_DIR}/public;
    index index.php index.html;

    ssl_certificate /etc/nginx/ssl/server.crt;
    ssl_certificate_key /etc/nginx/ssl/server.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;

    access_log /var/log/nginx/ip-manager.access.log;
    error_log /var/log/nginx/ip-manager.error.log;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location /admin {
        alias ${INSTALL_DIR}/dist;
        try_files \$uri \$uri/ /admin/index.html;
    }

    location ~ ^/api\.php {
        ${FASTCGI_PASS}
        fastcgi_param SCRIPT_FILENAME ${INSTALL_DIR}/backend/api/api.php;
        include fastcgi_params;
    }

    # 服务器验证端点（用于Cloudflare等CDN环境下验证域名解析）
    location = /_verify_server {
        ${FASTCGI_PASS}
        fastcgi_param SCRIPT_FILENAME ${INSTALL_DIR}/public/verify.php;
        include fastcgi_params;
    }

    # 短链接跳转 (4-10位字母数字)
    location ~ "^/([a-zA-Z0-9]{4,10})\$" {
        ${FASTCGI_PASS}
        fastcgi_param SCRIPT_FILENAME ${INSTALL_DIR}/public/s.php;
        fastcgi_param QUERY_STRING code=\$1;
        include fastcgi_params;
    }

    location ~ ^/j\.php {
        ${FASTCGI_PASS}
        fastcgi_param SCRIPT_FILENAME ${INSTALL_DIR}/j.php;
        include fastcgi_params;
    }

    location ~ \.php\$ {
        ${FASTCGI_PASS}
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\. {
        deny all;
    }

    location ~ ^/(backend|config|deploy|data)/ {
        deny all;
    }
}
NGINXEOF
        log_info "Nginx配置已更新"
    else
        log_info "Nginx配置已是最新"
    fi
else
    log_warn "未找到Nginx配置文件，建议重新运行 install.sh"
fi

# 重启服务
log_step "重启服务..."
systemctl restart php*-fpm 2>/dev/null || systemctl restart php-fpm
systemctl restart nginx

# 缓存预热
if [ "$SKIP_CACHE" = true ]; then
    log_info "跳过缓存预热 (--no-cache)"
else
    log_step "执行缓存预热..."
    if [ -f "$INSTALL_DIR/public/warmup.php" ]; then
        php "$INSTALL_DIR/public/warmup.php" --quick 2>/dev/null && log_info "缓存预热完成" || log_warn "缓存预热失败"
    else
        log_warn "预热脚本不存在，跳过缓存预热"
    fi
    
    # 确保定时预热任务存在
    log_info "检查定时预热任务..."
    CRON_JOB="*/5 * * * * php $INSTALL_DIR/public/warmup.php --quick > /dev/null 2>&1"
    if ! crontab -l 2>/dev/null | grep -q "warmup.php"; then
        (crontab -l 2>/dev/null; echo "$CRON_JOB") | crontab -
        log_info "定时预热任务已添加"
    else
        log_info "定时预热任务已存在"
    fi
fi

# 健康检查
log_step "执行健康检查..."
sleep 2

# 检查服务状态
HEALTH_OK=true

if systemctl is-active --quiet php*-fpm 2>/dev/null || systemctl is-active --quiet php-fpm 2>/dev/null; then
    log_info "PHP-FPM: 运行中 ✓"
else
    log_warn "PHP-FPM: 未运行"
    HEALTH_OK=false
fi

if systemctl is-active --quiet nginx; then
    log_info "Nginx: 运行中 ✓"
else
    log_warn "Nginx: 未运行"
    HEALTH_OK=false
fi

if systemctl is-active --quiet mysql 2>/dev/null || systemctl is-active --quiet mysqld 2>/dev/null || systemctl is-active --quiet mariadb 2>/dev/null; then
    log_info "MySQL: 运行中 ✓"
else
    log_warn "MySQL: 未运行"
    HEALTH_OK=false
fi

# 调用健康检查端点
if [ -f "$INSTALL_DIR/public/health.php" ]; then
    HEALTH_RESULT=$(php "$INSTALL_DIR/public/health.php" 2>/dev/null | head -c 500 || echo '{}')
    if echo "$HEALTH_RESULT" | grep -q '"status":"healthy"'; then
        log_info "系统健康检查: 通过 ✓"
    elif echo "$HEALTH_RESULT" | grep -q '"status":"degraded"'; then
        log_warn "系统健康检查: 部分降级"
    else
        log_warn "系统健康检查: 无法确定状态"
    fi
fi

# 获取服务器IP
SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}' || echo "localhost")

echo ""
echo "=============================================="
echo -e "${GREEN}✓ 更新完成！${NC}"
echo "=============================================="
echo ""
echo -e "  版本: ${CYAN}$CURRENT_COMMIT${NC} → ${GREEN}$NEW_COMMIT${NC}"
echo -e "  后台: ${BLUE}http://${SERVER_IP}/admin${NC}"
echo -e "  健康: ${BLUE}http://${SERVER_IP}/health.php${NC}"
echo ""
if [ "$HEALTH_OK" = true ]; then
    echo -e "${GREEN}所有服务运行正常${NC}"
else
    echo -e "${YELLOW}部分服务可能需要检查${NC}"
fi
echo ""
echo -e "备份位置: ${BLUE}$BACKUP_DIR${NC}"
echo "=============================================="
echo ""
