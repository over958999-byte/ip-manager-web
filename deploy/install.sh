#!/bin/bash

#===============================================================================
# IP管理器 - Linux一键部署脚本
# 支持系统: Ubuntu 20.04+, Debian 11+, CentOS 7+, Rocky Linux 8+
# 仓库地址: https://github.com/over958999-byte/ip-manager-web
#===============================================================================

set -e

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 配置变量
INSTALL_DIR="/var/www/ip-manager"
REPO_URL="https://github.com/over958999-byte/ip-manager-web.git"
DB_NAME="ip_manager"
DB_USER="ip_manager"
DB_PASS=$(openssl rand -base64 12)
DOMAIN=""
PHP_VERSION="8.2"
NODE_VERSION="20"

# 默认后台账号密码
ADMIN_USER="admin"
ADMIN_PASS="admin123"

# 日志函数
log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

log_step() {
    echo -e "${BLUE}[STEP]${NC} $1"
}

# 检测系统类型
detect_os() {
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS=$ID
        VERSION=$VERSION_ID
    elif [ -f /etc/redhat-release ]; then
        OS="centos"
        VERSION=$(cat /etc/redhat-release | grep -oE '[0-9]+' | head -1)
    else
        log_error "无法检测操作系统类型"
        exit 1
    fi
    log_info "检测到系统: $OS $VERSION"
}

# 检查root权限
check_root() {
    if [ "$EUID" -ne 0 ]; then
        log_error "请使用root用户运行此脚本"
        exit 1
    fi
}

# 安装依赖 - Debian/Ubuntu
install_debian() {
    log_step "更新软件包列表..."
    apt-get update -y

    log_step "安装基础工具..."
    apt-get install -y curl wget git unzip software-properties-common gnupg2 lsb-release ca-certificates apt-transport-https

    # 添加PHP仓库
    log_step "添加PHP仓库..."
    if [ "$OS" = "ubuntu" ]; then
        add-apt-repository -y ppa:ondrej/php
    else
        wget -qO /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg
        echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list
    fi
    apt-get update -y

    # 安装PHP
    log_step "安装PHP ${PHP_VERSION}..."
    apt-get install -y php${PHP_VERSION} php${PHP_VERSION}-fpm php${PHP_VERSION}-mysql php${PHP_VERSION}-curl \
        php${PHP_VERSION}-json php${PHP_VERSION}-mbstring php${PHP_VERSION}-xml php${PHP_VERSION}-zip \
        php${PHP_VERSION}-gd php${PHP_VERSION}-intl php${PHP_VERSION}-bcmath

    # 安装MySQL
    log_step "安装MySQL..."
    apt-get install -y mysql-server

    # 安装Nginx
    log_step "安装Nginx..."
    apt-get install -y nginx

    # 安装Node.js
    log_step "安装Node.js ${NODE_VERSION}..."
    curl -fsSL https://deb.nodesource.com/setup_${NODE_VERSION}.x | bash -
    apt-get install -y nodejs
}

# 安装依赖 - CentOS/RHEL/Rocky
install_centos() {
    log_step "更新软件包..."
    yum update -y

    log_step "安装基础工具..."
    yum install -y curl wget git unzip epel-release yum-utils

    # 添加PHP仓库
    log_step "添加PHP仓库..."
    if [ "$VERSION" -ge 8 ]; then
        dnf install -y https://rpms.remirepo.net/enterprise/remi-release-${VERSION}.rpm
        dnf module reset php -y
        dnf module enable php:remi-${PHP_VERSION} -y
    else
        yum install -y https://rpms.remirepo.net/enterprise/remi-release-7.rpm
        yum-config-manager --enable remi-php82
    fi

    # 安装PHP
    log_step "安装PHP ${PHP_VERSION}..."
    if [ "$VERSION" -ge 8 ]; then
        dnf install -y php php-fpm php-mysqlnd php-curl php-json php-mbstring \
            php-xml php-zip php-gd php-intl php-bcmath
    else
        yum install -y php php-fpm php-mysqlnd php-curl php-json php-mbstring \
            php-xml php-zip php-gd php-intl php-bcmath
    fi

    # 安装MySQL
    log_step "安装MySQL..."
    if [ "$VERSION" -ge 8 ]; then
        dnf install -y mysql-server
    else
        yum install -y mariadb-server mariadb
    fi

    # 安装Nginx
    log_step "安装Nginx..."
    if [ "$VERSION" -ge 8 ]; then
        dnf install -y nginx
    else
        yum install -y nginx
    fi

    # 安装Node.js
    log_step "安装Node.js ${NODE_VERSION}..."
    curl -fsSL https://rpm.nodesource.com/setup_${NODE_VERSION}.x | bash -
    if [ "$VERSION" -ge 8 ]; then
        dnf install -y nodejs
    else
        yum install -y nodejs
    fi
}

# 配置MySQL
configure_mysql() {
    log_step "配置MySQL..."

    # 启动MySQL
    systemctl start mysql 2>/dev/null || systemctl start mysqld 2>/dev/null || systemctl start mariadb 2>/dev/null
    systemctl enable mysql 2>/dev/null || systemctl enable mysqld 2>/dev/null || systemctl enable mariadb 2>/dev/null

    # 创建数据库和用户
    log_info "创建数据库..."
    mysql -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
    mysql -e "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';"
    mysql -e "FLUSH PRIVILEGES;"

    log_info "数据库 ${DB_NAME} 创建成功"
}

# 克隆项目
clone_project() {
    log_step "克隆项目代码..."

    if [ -d "$INSTALL_DIR" ]; then
        log_warn "目录已存在，正在备份..."
        mv "$INSTALL_DIR" "${INSTALL_DIR}_backup_$(date +%Y%m%d%H%M%S)"
    fi

    git clone "$REPO_URL" "$INSTALL_DIR"
    cd "$INSTALL_DIR"
    
    log_info "项目克隆完成"
}

# 导入数据库
import_database() {
    log_step "导入数据库结构..."

    cd "$INSTALL_DIR"
    
    # 导入主数据库结构
    mysql "$DB_NAME" < backend/database.sql
    
    # 导入初始配置
    if [ -f "backend/init_config.sql" ]; then
        mysql "$DB_NAME" < backend/init_config.sql
    fi
    
    # 导入短链接表
    if [ -f "backend/shortlink.sql" ]; then
        mysql "$DB_NAME" < backend/shortlink.sql
    fi

    log_info "数据库导入完成"
}

# 创建数据库配置文件
create_db_config() {
    log_step "创建数据库配置文件..."

    cat > "$INSTALL_DIR/backend/core/db_config.php" << EOF
<?php
// 数据库配置 - 由部署脚本自动生成
define('DB_HOST', 'localhost');
define('DB_NAME', '${DB_NAME}');
define('DB_USER', '${DB_USER}');
define('DB_PASS', '${DB_PASS}');
define('DB_CHARSET', 'utf8mb4');
EOF

    chmod 600 "$INSTALL_DIR/backend/core/db_config.php"
    log_info "数据库配置文件创建完成"
}

# 构建前端
build_frontend() {
    log_step "构建前端项目..."

    cd "$INSTALL_DIR/backend/frontend"
    
    # 安装依赖
    npm install
    
    # 构建生产版本
    npm run build
    
    # 复制构建产物到public目录
    if [ -d "dist" ]; then
        mkdir -p "$INSTALL_DIR/public/admin"
        cp -r dist/* "$INSTALL_DIR/public/admin/"
    fi

    log_info "前端构建完成"
}

# 配置Nginx
configure_nginx() {
    log_step "配置Nginx..."

    # 获取PHP-FPM socket路径
    PHP_FPM_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"
    if [ ! -S "$PHP_FPM_SOCK" ]; then
        PHP_FPM_SOCK="/var/run/php-fpm/www.sock"
    fi
    if [ ! -S "$PHP_FPM_SOCK" ]; then
        PHP_FPM_SOCK="127.0.0.1:9000"
    fi

    cat > /etc/nginx/sites-available/ip-manager << EOF
server {
    listen 80;
    server_name ${DOMAIN:-_};
    root ${INSTALL_DIR}/public;
    index index.php index.html;

    # 日志配置
    access_log /var/log/nginx/ip-manager.access.log;
    error_log /var/log/nginx/ip-manager.error.log;

    # 主站点 - IP跳转入口
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    # 管理后台前端
    location /admin {
        alias ${INSTALL_DIR}/public/admin;
        try_files \$uri \$uri/ /admin/index.html;
    }

    # 管理后台API
    location /api.php {
        alias ${INSTALL_DIR}/backend/api/api.php;
        fastcgi_pass unix:${PHP_FPM_SOCK};
        fastcgi_param SCRIPT_FILENAME ${INSTALL_DIR}/backend/api/api.php;
        include fastcgi_params;
    }

    # 短链接跳转
    location /j.php {
        alias ${INSTALL_DIR}/j.php;
        fastcgi_pass unix:${PHP_FPM_SOCK};
        fastcgi_param SCRIPT_FILENAME ${INSTALL_DIR}/j.php;
        include fastcgi_params;
    }

    # PHP处理
    location ~ \.php$ {
        fastcgi_pass unix:${PHP_FPM_SOCK};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_intercept_errors on;
    }

    # 禁止访问隐藏文件
    location ~ /\. {
        deny all;
    }

    # 禁止访问敏感目录
    location ~ ^/(backend|config|deploy|data)/ {
        deny all;
    }
}
EOF

    # 创建软链接
    ln -sf /etc/nginx/sites-available/ip-manager /etc/nginx/sites-enabled/

    # 移除默认配置
    rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true

    # 测试配置
    nginx -t

    log_info "Nginx配置完成"
}

# 配置Nginx (CentOS)
configure_nginx_centos() {
    log_step "配置Nginx (CentOS)..."

    PHP_FPM_SOCK="/var/run/php-fpm/www.sock"

    cat > /etc/nginx/conf.d/ip-manager.conf << EOF
server {
    listen 80;
    server_name ${DOMAIN:-_};
    root ${INSTALL_DIR}/public;
    index index.php index.html;

    access_log /var/log/nginx/ip-manager.access.log;
    error_log /var/log/nginx/ip-manager.error.log;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location /admin {
        alias ${INSTALL_DIR}/public/admin;
        try_files \$uri \$uri/ /admin/index.html;
    }

    location /api.php {
        alias ${INSTALL_DIR}/backend/api/api.php;
        fastcgi_pass unix:${PHP_FPM_SOCK};
        fastcgi_param SCRIPT_FILENAME ${INSTALL_DIR}/backend/api/api.php;
        include fastcgi_params;
    }

    location /j.php {
        alias ${INSTALL_DIR}/j.php;
        fastcgi_pass unix:${PHP_FPM_SOCK};
        fastcgi_param SCRIPT_FILENAME ${INSTALL_DIR}/j.php;
        include fastcgi_params;
    }

    location ~ \.php$ {
        fastcgi_pass unix:${PHP_FPM_SOCK};
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
EOF

    nginx -t
    log_info "Nginx配置完成"
}

# 设置文件权限
set_permissions() {
    log_step "设置文件权限..."

    chown -R www-data:www-data "$INSTALL_DIR" 2>/dev/null || chown -R nginx:nginx "$INSTALL_DIR"
    chmod -R 755 "$INSTALL_DIR"
    chmod -R 775 "$INSTALL_DIR/data" 2>/dev/null || true
    
    log_info "文件权限设置完成"
}

# 启动服务
start_services() {
    log_step "启动服务..."

    # PHP-FPM
    systemctl restart php${PHP_VERSION}-fpm 2>/dev/null || systemctl restart php-fpm
    systemctl enable php${PHP_VERSION}-fpm 2>/dev/null || systemctl enable php-fpm

    # Nginx
    systemctl restart nginx
    systemctl enable nginx

    log_info "所有服务已启动"
}

# 配置防火墙
configure_firewall() {
    log_step "配置防火墙..."

    if command -v ufw &> /dev/null; then
        ufw allow 80/tcp
        ufw allow 443/tcp
        log_info "UFW防火墙已配置"
    elif command -v firewall-cmd &> /dev/null; then
        firewall-cmd --permanent --add-service=http
        firewall-cmd --permanent --add-service=https
        firewall-cmd --reload
        log_info "Firewalld已配置"
    fi
}

# 打印安装信息
print_info() {
    # 获取服务器IP
    SERVER_IP=$(hostname -I | awk '{print $1}')
    SITE_HOST="${DOMAIN:-$SERVER_IP}"

    echo ""
    echo "============================================================"
    echo -e "${GREEN}  ✓ IP管理器部署完成！${NC}"
    echo "============================================================"
    echo ""
    echo -e "${YELLOW}【后台管理信息】${NC}"
    echo -e "  后台地址:   ${BLUE}http://${SITE_HOST}/admin${NC}"
    echo -e "  账号:       ${BLUE}${ADMIN_USER}${NC}"
    echo -e "  密码:       ${BLUE}${ADMIN_PASS}${NC}"
    echo ""
    echo -e "${YELLOW}【网站信息】${NC}"
    echo -e "  安装目录:   ${BLUE}${INSTALL_DIR}${NC}"
    echo -e "  网站地址:   ${BLUE}http://${SITE_HOST}${NC}"
    echo ""
    echo -e "${YELLOW}【数据库信息】${NC}"
    echo -e "  数据库名:   ${BLUE}${DB_NAME}${NC}"
    echo -e "  用户名:     ${BLUE}${DB_USER}${NC}"
    echo -e "  密码:       ${BLUE}${DB_PASS}${NC}"
    echo ""
    echo "============================================================"
    echo -e "${RED}⚠ 请登录后台后立即修改默认密码！${NC}"
    echo -e "${YELLOW}请妥善保存以上信息！${NC}"
    echo "============================================================"
    echo ""
    
    # 保存信息到文件
    cat > "$INSTALL_DIR/install_info.txt" << EOF
IP管理器安装信息
================
安装时间: $(date)

【后台管理信息】
  后台地址: http://${SITE_HOST}/admin
  账号: ${ADMIN_USER}
  密码: ${ADMIN_PASS}

【网站信息】
  安装目录: ${INSTALL_DIR}
  网站地址: http://${SITE_HOST}

【数据库信息】
  数据库名: ${DB_NAME}
  用户名: ${DB_USER}
  密码: ${DB_PASS}

⚠ 请登录后台后立即修改默认密码！
EOF
    chmod 600 "$INSTALL_DIR/install_info.txt"
    log_info "安装信息已保存到: ${INSTALL_DIR}/install_info.txt"
}

# 显示帮助
show_help() {
    echo "用法: $0 [选项]"
    echo ""
    echo "选项:"
    echo "  -d, --domain DOMAIN    设置域名"
    echo "  -h, --help            显示帮助信息"
    echo ""
    echo "示例:"
    echo "  $0                    使用默认配置安装"
    echo "  $0 -d example.com     设置域名为example.com"
}

# 解析参数
parse_args() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            -d|--domain)
                DOMAIN="$2"
                shift 2
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
}

# 主函数
main() {
    parse_args "$@"
    
    echo ""
    echo "============================================================"
    echo -e "${BLUE}IP管理器 - Linux一键部署脚本${NC}"
    echo "============================================================"
    echo ""

    check_root
    detect_os

    # 根据系统类型安装
    case $OS in
        ubuntu|debian)
            install_debian
            configure_mysql
            clone_project
            import_database
            create_db_config
            build_frontend
            configure_nginx
            set_permissions
            start_services
            configure_firewall
            ;;
        centos|rhel|rocky|almalinux)
            install_centos
            configure_mysql
            clone_project
            import_database
            create_db_config
            build_frontend
            configure_nginx_centos
            set_permissions
            start_services
            configure_firewall
            ;;
        *)
            log_error "不支持的操作系统: $OS"
            exit 1
            ;;
    esac

    print_info
}

# 运行主函数
main "$@"
