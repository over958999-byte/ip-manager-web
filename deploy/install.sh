#!/bin/bash

#===============================================================================
# IP管理器 - Linux一键部署脚本
# 支持系统: Ubuntu 20.04+, Debian 11+, CentOS 7+, Rocky Linux 8+
# 仓库地址: https://github.com/over958999-byte/ip-manager-web
# 
# 特性:
#   - 智能环境检测: 自动检测已安装的组件，跳过无需安装的部分
#   - 版本验证: 检测组件版本是否满足最低要求，不满足则重装
#   - 强制模式: 使用 -f 参数强制重新安装所有组件
#===============================================================================

set -e

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# 配置变量
INSTALL_DIR="/var/www/ip-manager"
REPO_URL="https://github.com/over958999-byte/ip-manager-web.git"
DB_NAME="ip_manager"
DB_USER="ip_manager"
DB_PASS=""
DOMAIN=""

# 版本要求
PHP_VERSION="8.2"
PHP_MIN_VERSION="8.0"
NODE_VERSION="20"
NODE_MIN_VERSION="18"
MYSQL_MIN_VERSION="5.7"

# 默认后台账号密码
ADMIN_USER="admin"
ADMIN_PASS="admin123"

# 环境检测标志
NEED_INSTALL_PHP=false
NEED_INSTALL_MYSQL=false
NEED_INSTALL_NGINX=false
NEED_INSTALL_NODE=false
FORCE_INSTALL=false

# 日志函数
log_info() {
    echo -e "${GREEN}[✓]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[!]${NC} $1"
}

log_error() {
    echo -e "${RED}[✗]${NC} $1"
}

log_step() {
    echo -e "${BLUE}[STEP]${NC} $1"
}

log_check() {
    echo -e "${CYAN}[CHECK]${NC} $1"
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

# ==================== 版本比较函数 ====================

# 比较版本号 (返回: 0=相等, 1=第一个大, 2=第一个小)
version_compare() {
    if [ "$1" = "$2" ]; then
        return 0
    fi
    local IFS=.
    local i ver1=($1) ver2=($2)
    for ((i=0; i<${#ver1[@]}; i++)); do
        if [ -z "${ver2[i]}" ]; then
            ver2[i]=0
        fi
        if ((10#${ver1[i]:-0} > 10#${ver2[i]:-0})); then
            return 1
        fi
        if ((10#${ver1[i]:-0} < 10#${ver2[i]:-0})); then
            return 2
        fi
    done
    return 0
}

# ==================== 环境检测函数 ====================

# 检测PHP
check_php() {
    log_check "检测PHP环境..."
    
    if command -v php &> /dev/null; then
        local php_ver=$(php -v 2>/dev/null | head -n1 | sed -n 's/.*PHP \([0-9]*\.[0-9]*\).*/\1/p')
        
        if [ -n "$php_ver" ]; then
            version_compare "$php_ver" "$PHP_MIN_VERSION"
            local result=$?
            
            if [ $result -eq 0 ] || [ $result -eq 1 ]; then
                log_info "PHP已安装: $php_ver (✓ 满足最低要求 $PHP_MIN_VERSION)"
                
                # 检查必要的PHP扩展
                local missing_ext=""
                for ext in mysqli pdo_mysql curl mbstring xml zip gd; do
                    if ! php -m 2>/dev/null | grep -qi "^$ext$"; then
                        missing_ext="$missing_ext $ext"
                    fi
                done
                
                if [ -n "$missing_ext" ]; then
                    log_warn "缺少PHP扩展:$missing_ext"
                    NEED_INSTALL_PHP=true
                    return
                fi
                
                # 检查PHP-FPM
                if ! systemctl is-active --quiet php*-fpm 2>/dev/null; then
                    log_warn "PHP-FPM未运行，将进行配置"
                fi
                
                return
            fi
        fi
        
        log_warn "PHP版本 $php_ver 低于最低要求 $PHP_MIN_VERSION，将重新安装"
        NEED_INSTALL_PHP=true
    else
        log_warn "PHP未安装"
        NEED_INSTALL_PHP=true
    fi
}

# 检测MySQL
check_mysql() {
    log_check "检测MySQL环境..."
    
    if command -v mysql &> /dev/null; then
        local mysql_ver=$(mysql --version 2>/dev/null | sed -n 's/.*\([0-9]\+\.[0-9]\+\).*/\1/p' | head -1)
        
        if [ -n "$mysql_ver" ]; then
            version_compare "$mysql_ver" "$MYSQL_MIN_VERSION"
            local result=$?
            
            if [ $result -eq 0 ] || [ $result -eq 1 ]; then
                log_info "MySQL已安装: $mysql_ver (✓ 满足最低要求 $MYSQL_MIN_VERSION)"
                
                # 检查MySQL服务是否运行
                if ! (systemctl is-active --quiet mysql 2>/dev/null || \
                      systemctl is-active --quiet mysqld 2>/dev/null || \
                      systemctl is-active --quiet mariadb 2>/dev/null); then
                    log_warn "MySQL服务未运行，将启动服务"
                fi
                return
            fi
        fi
        
        log_warn "MySQL版本 $mysql_ver 低于最低要求 $MYSQL_MIN_VERSION，将重新安装"
        NEED_INSTALL_MYSQL=true
    else
        log_warn "MySQL未安装"
        NEED_INSTALL_MYSQL=true
    fi
}

# 检测Nginx
check_nginx() {
    log_check "检测Nginx环境..."
    
    if command -v nginx &> /dev/null; then
        local nginx_ver=$(nginx -v 2>&1 | sed -n 's/.*nginx\/\([0-9.]*\).*/\1/p')
        [ -z "$nginx_ver" ] && nginx_ver="unknown"
        log_info "Nginx已安装: $nginx_ver (✓)"
        
        # 检查Nginx服务
        if ! systemctl is-active --quiet nginx 2>/dev/null; then
            log_warn "Nginx服务未运行，将启动服务"
        fi
    else
        log_warn "Nginx未安装"
        NEED_INSTALL_NGINX=true
    fi
}

# 检测Node.js
check_node() {
    log_check "检测Node.js环境..."
    
    if command -v node &> /dev/null; then
        local node_ver=$(node -v 2>/dev/null | sed 's/v//' | cut -d. -f1)
        
        if [ -n "$node_ver" ] && [ "$node_ver" -ge "$NODE_MIN_VERSION" ]; then
            local full_ver=$(node -v 2>/dev/null)
            log_info "Node.js已安装: $full_ver (✓ 满足最低要求 v$NODE_MIN_VERSION)"
            
            # 检查npm
            if ! command -v npm &> /dev/null; then
                log_warn "npm未安装，将重新安装Node.js"
                NEED_INSTALL_NODE=true
                return
            fi
            return
        fi
        
        log_warn "Node.js版本 v$node_ver 低于最低要求 v$NODE_MIN_VERSION，将重新安装"
        NEED_INSTALL_NODE=true
    else
        log_warn "Node.js未安装"
        NEED_INSTALL_NODE=true
    fi
}

# 环境检测总结
check_environment() {
    echo ""
    echo "============================================================"
    echo -e "${CYAN}🔍 环境检测${NC}"
    echo "============================================================"
    echo ""
    
    check_php
    check_mysql
    check_nginx
    check_node
    
    echo ""
    echo "------------------------------------------------------------"
    echo -e "${CYAN}📋 检测结果汇总${NC}"
    echo "------------------------------------------------------------"
    
    local need_install=false
    
    if $NEED_INSTALL_PHP; then
        echo -e "  PHP:      ${YELLOW}需要安装/更新${NC}"
        need_install=true
    else
        echo -e "  PHP:      ${GREEN}✓ 已就绪${NC}"
    fi
    
    if $NEED_INSTALL_MYSQL; then
        echo -e "  MySQL:    ${YELLOW}需要安装/更新${NC}"
        need_install=true
    else
        echo -e "  MySQL:    ${GREEN}✓ 已就绪${NC}"
    fi
    
    if $NEED_INSTALL_NGINX; then
        echo -e "  Nginx:    ${YELLOW}需要安装/更新${NC}"
        need_install=true
    else
        echo -e "  Nginx:    ${GREEN}✓ 已就绪${NC}"
    fi
    
    if $NEED_INSTALL_NODE; then
        echo -e "  Node.js:  ${YELLOW}需要安装/更新${NC}"
        need_install=true
    else
        echo -e "  Node.js:  ${GREEN}✓ 已就绪${NC}"
    fi
    
    echo "------------------------------------------------------------"
    
    if $need_install; then
        log_step "将安装/更新缺失的组件..."
    else
        log_info "所有环境组件已就绪，跳过环境安装步骤"
    fi
    
    echo ""
}

# 安装依赖 - Debian/Ubuntu
install_debian() {
    log_step "更新软件包列表..."
    apt-get update -y

    log_step "安装基础工具..."
    apt-get install -y curl wget git unzip software-properties-common gnupg2 lsb-release ca-certificates apt-transport-https

    # 安装PHP (如果需要)
    if $NEED_INSTALL_PHP; then
        log_step "添加PHP仓库..."
        if [ "$OS" = "ubuntu" ]; then
            add-apt-repository -y ppa:ondrej/php 2>/dev/null || true
        else
            wget -qO /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg 2>/dev/null || true
            echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list 2>/dev/null || true
        fi
        apt-get update -y

        log_step "安装PHP ${PHP_VERSION}..."
        apt-get install -y php${PHP_VERSION} php${PHP_VERSION}-fpm php${PHP_VERSION}-mysql php${PHP_VERSION}-curl \
            php${PHP_VERSION}-mbstring php${PHP_VERSION}-xml php${PHP_VERSION}-zip \
            php${PHP_VERSION}-gd php${PHP_VERSION}-intl php${PHP_VERSION}-bcmath
    else
        log_info "PHP已满足要求，跳过安装"
    fi

    # 安装MySQL (如果需要)
    if $NEED_INSTALL_MYSQL; then
        log_step "安装MySQL..."
        apt-get install -y mysql-server
    else
        log_info "MySQL已满足要求，跳过安装"
    fi

    # 安装Nginx (如果需要)
    if $NEED_INSTALL_NGINX; then
        log_step "安装Nginx..."
        apt-get install -y nginx
    else
        log_info "Nginx已满足要求，跳过安装"
    fi

    # 安装Node.js (如果需要)
    if $NEED_INSTALL_NODE; then
        log_step "安装Node.js ${NODE_VERSION}..."
        curl -fsSL https://deb.nodesource.com/setup_${NODE_VERSION}.x | bash -
        apt-get install -y nodejs
    else
        log_info "Node.js已满足要求，跳过安装"
    fi
}

# 安装依赖 - CentOS/RHEL/Rocky
install_centos() {
    log_step "更新软件包..."
    yum update -y

    log_step "安装基础工具..."
    yum install -y curl wget git unzip epel-release yum-utils

    # 安装PHP (如果需要)
    if $NEED_INSTALL_PHP; then
        log_step "添加PHP仓库..."
        if [ "$VERSION" -ge 8 ]; then
            dnf install -y https://rpms.remirepo.net/enterprise/remi-release-${VERSION}.rpm 2>/dev/null || true
            dnf module reset php -y 2>/dev/null || true
            dnf module enable php:remi-${PHP_VERSION} -y 2>/dev/null || true
        else
            yum install -y https://rpms.remirepo.net/enterprise/remi-release-7.rpm 2>/dev/null || true
            yum-config-manager --enable remi-php82 2>/dev/null || true
        fi

        log_step "安装PHP ${PHP_VERSION}..."
        if [ "$VERSION" -ge 8 ]; then
            dnf install -y php php-fpm php-mysqlnd php-curl php-mbstring \
                php-xml php-zip php-gd php-intl php-bcmath
        else
            yum install -y php php-fpm php-mysqlnd php-curl php-mbstring \
                php-xml php-zip php-gd php-intl php-bcmath
        fi
    else
        log_info "PHP已满足要求，跳过安装"
    fi

    # 安装MySQL (如果需要)
    if $NEED_INSTALL_MYSQL; then
        log_step "安装MySQL..."
        if [ "$VERSION" -ge 8 ]; then
            dnf install -y mysql-server
        else
            yum install -y mariadb-server mariadb
        fi
    else
        log_info "MySQL已满足要求，跳过安装"
    fi

    # 安装Nginx (如果需要)
    if $NEED_INSTALL_NGINX; then
        log_step "安装Nginx..."
        if [ "$VERSION" -ge 8 ]; then
            dnf install -y nginx
        else
            yum install -y nginx
        fi
    else
        log_info "Nginx已满足要求，跳过安装"
    fi

    # 安装Node.js (如果需要)
    if $NEED_INSTALL_NODE; then
        log_step "安装Node.js ${NODE_VERSION}..."
        curl -fsSL https://rpm.nodesource.com/setup_${NODE_VERSION}.x | bash -
        if [ "$VERSION" -ge 8 ]; then
            dnf install -y nodejs
        else
            yum install -y nodejs
        fi
    else
        log_info "Node.js已满足要求，跳过安装"
    fi
}

# 配置MySQL
configure_mysql() {
    log_step "配置MySQL..."

    # 启动MySQL
    systemctl start mysql 2>/dev/null || systemctl start mysqld 2>/dev/null || systemctl start mariadb 2>/dev/null || true
    systemctl enable mysql 2>/dev/null || systemctl enable mysqld 2>/dev/null || systemctl enable mariadb 2>/dev/null || true

    # 检查数据库是否已存在
    if mysql -e "USE ${DB_NAME}" 2>/dev/null; then
        log_info "数据库 ${DB_NAME} 已存在"
        
        # 尝试读取现有配置
        if [ -f "$INSTALL_DIR/backend/core/db_config.php" ]; then
            DB_PASS=$(grep "DB_PASS" "$INSTALL_DIR/backend/core/db_config.php" 2>/dev/null | grep -oP "'[^']+'" | tail -1 | tr -d "'" || echo "")
            if [ -n "$DB_PASS" ]; then
                log_info "使用现有数据库配置"
                return
            fi
        fi
    fi
    
    # 生成新密码
    DB_PASS=$(openssl rand -base64 12)

    # 创建数据库和用户
    log_info "创建数据库..."
    mysql -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';" 2>/dev/null || \
    mysql -e "CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';" 2>/dev/null || true
    mysql -e "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';" 2>/dev/null || true
    mysql -e "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';"
    mysql -e "FLUSH PRIVILEGES;"

    log_info "数据库 ${DB_NAME} 配置成功"
}

# 克隆或更新项目
clone_project() {
    log_step "获取项目代码..."

    if [ -d "$INSTALL_DIR/.git" ]; then
        log_info "项目已存在，执行更新..."
        cd "$INSTALL_DIR"
        
        # 备份配置文件
        if [ -f "backend/core/db_config.php" ]; then
            cp backend/core/db_config.php /tmp/db_config.php.bak 2>/dev/null || true
        fi
        
        git fetch origin 2>/dev/null || true
        git reset --hard origin/master 2>/dev/null || git reset --hard origin/main 2>/dev/null || true
        
        # 恢复配置文件
        if [ -f "/tmp/db_config.php.bak" ]; then
            cp /tmp/db_config.php.bak backend/core/db_config.php 2>/dev/null || true
        fi
        
        log_info "项目更新完成"
    else
        if [ -d "$INSTALL_DIR" ]; then
            log_warn "目录已存在但非Git仓库，正在备份..."
            mv "$INSTALL_DIR" "${INSTALL_DIR}_backup_$(date +%Y%m%d%H%M%S)"
        fi

        git clone "$REPO_URL" "$INSTALL_DIR"
        cd "$INSTALL_DIR"
        
        log_info "项目克隆完成"
    fi
}

# 导入数据库
import_database() {
    log_step "导入数据库结构..."

    cd "$INSTALL_DIR"
    
    # 检查表是否已存在
    if mysql -e "SELECT 1 FROM ${DB_NAME}.config LIMIT 1" 2>/dev/null; then
        log_info "数据库表已存在，跳过导入"
        return
    fi
    
    # 导入主数据库结构
    if [ -f "backend/database.sql" ]; then
        mysql "$DB_NAME" < backend/database.sql
    fi
    
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
    
    # 如果配置已存在且有效，跳过
    if [ -f "$INSTALL_DIR/backend/core/db_config.php" ]; then
        if grep -q "DB_PASS" "$INSTALL_DIR/backend/core/db_config.php" 2>/dev/null; then
            log_info "数据库配置文件已存在，跳过创建"
            return
        fi
    fi

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
    
    # 检查是否需要重新构建
    if [ -d "$INSTALL_DIR/public/admin" ] && [ -f "$INSTALL_DIR/public/admin/index.html" ]; then
        log_info "前端已构建，重新构建以确保最新..."
    fi
    
    # 安装依赖
    npm install
    
    # 构建生产版本
    npm run build
    
    # 复制构建产物到public目录
    if [ -d "dist" ]; then
        mkdir -p "$INSTALL_DIR/public/admin"
        cp -r dist/* "$INSTALL_DIR/public/admin/"
    elif [ -d "$INSTALL_DIR/dist" ]; then
        mkdir -p "$INSTALL_DIR/public/admin"
        cp -r "$INSTALL_DIR/dist"/* "$INSTALL_DIR/public/admin/"
    fi

    log_info "前端构建完成"
}

# 配置Nginx
configure_nginx() {
    log_step "配置Nginx..."

    # 获取PHP-FPM socket路径
    PHP_FPM_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"
    if [ ! -S "$PHP_FPM_SOCK" ]; then
        PHP_FPM_SOCK=$(find /run/php -name "*.sock" 2>/dev/null | head -1)
    fi
    if [ -z "$PHP_FPM_SOCK" ] || [ ! -S "$PHP_FPM_SOCK" ]; then
        PHP_FPM_SOCK="/var/run/php-fpm/www.sock"
    fi
    if [ ! -S "$PHP_FPM_SOCK" ]; then
        PHP_FPM_SOCK="127.0.0.1:9000"
        FASTCGI_PASS="fastcgi_pass ${PHP_FPM_SOCK};"
    else
        FASTCGI_PASS="fastcgi_pass unix:${PHP_FPM_SOCK};"
    fi

    # 创建sites-available目录（如果不存在）
    mkdir -p /etc/nginx/sites-available 2>/dev/null || true
    mkdir -p /etc/nginx/sites-enabled 2>/dev/null || true

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
    location ~ ^/api\.php {
        ${FASTCGI_PASS}
        fastcgi_param SCRIPT_FILENAME ${INSTALL_DIR}/backend/api/api.php;
        include fastcgi_params;
    }

    # 短链接跳转
    location ~ ^/j\.php {
        ${FASTCGI_PASS}
        fastcgi_param SCRIPT_FILENAME ${INSTALL_DIR}/j.php;
        include fastcgi_params;
    }

    # PHP处理
    location ~ \.php\$ {
        ${FASTCGI_PASS}
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

    # 创建软链接 (Debian/Ubuntu)
    if [ -d "/etc/nginx/sites-enabled" ]; then
        ln -sf /etc/nginx/sites-available/ip-manager /etc/nginx/sites-enabled/
        rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true
    fi
    
    # 复制到conf.d (CentOS/RHEL)
    if [ -d "/etc/nginx/conf.d" ] && [ ! -d "/etc/nginx/sites-enabled" ]; then
        cp /etc/nginx/sites-available/ip-manager /etc/nginx/conf.d/ip-manager.conf
    fi

    # 测试配置
    nginx -t

    log_info "Nginx配置完成"
}

# 配置Nginx (CentOS)
configure_nginx_centos() {
    log_step "配置Nginx (CentOS)..."

    # 获取PHP-FPM socket路径
    PHP_FPM_SOCK="/var/run/php-fpm/www.sock"
    if [ ! -S "$PHP_FPM_SOCK" ]; then
        PHP_FPM_SOCK=$(find /var/run/php-fpm -name "*.sock" 2>/dev/null | head -1)
    fi
    if [ -z "$PHP_FPM_SOCK" ] || [ ! -S "$PHP_FPM_SOCK" ]; then
        PHP_FPM_SOCK="127.0.0.1:9000"
        FASTCGI_PASS="fastcgi_pass ${PHP_FPM_SOCK};"
    else
        FASTCGI_PASS="fastcgi_pass unix:${PHP_FPM_SOCK};"
    fi

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

    location ~ ^/api\.php {
        ${FASTCGI_PASS}
        fastcgi_param SCRIPT_FILENAME ${INSTALL_DIR}/backend/api/api.php;
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
EOF

    nginx -t
    log_info "Nginx配置完成"
}

# 设置文件权限
set_permissions() {
    log_step "设置文件权限..."

    chown -R www-data:www-data "$INSTALL_DIR" 2>/dev/null || chown -R nginx:nginx "$INSTALL_DIR" 2>/dev/null || true
    chmod -R 755 "$INSTALL_DIR"
    chmod 600 "$INSTALL_DIR/backend/core/db_config.php" 2>/dev/null || true
    
    log_info "文件权限设置完成"
}

# 启动服务
start_services() {
    log_step "启动服务..."

    # PHP-FPM
    systemctl restart php${PHP_VERSION}-fpm 2>/dev/null || systemctl restart php-fpm 2>/dev/null || true
    systemctl enable php${PHP_VERSION}-fpm 2>/dev/null || systemctl enable php-fpm 2>/dev/null || true

    # Nginx
    systemctl restart nginx
    systemctl enable nginx

    log_info "所有服务已启动"
}

# 配置防火墙
configure_firewall() {
    log_step "配置防火墙..."

    if command -v ufw &> /dev/null; then
        ufw allow 80/tcp 2>/dev/null || true
        ufw allow 443/tcp 2>/dev/null || true
        log_info "UFW防火墙已配置"
    elif command -v firewall-cmd &> /dev/null; then
        firewall-cmd --permanent --add-service=http 2>/dev/null || true
        firewall-cmd --permanent --add-service=https 2>/dev/null || true
        firewall-cmd --reload 2>/dev/null || true
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
    echo "  -f, --force            强制重新安装所有组件（忽略环境检测）"
    echo "  -h, --help             显示帮助信息"
    echo ""
    echo "环境要求:"
    echo "  PHP     >= ${PHP_MIN_VERSION}"
    echo "  MySQL   >= ${MYSQL_MIN_VERSION}"
    echo "  Node.js >= v${NODE_MIN_VERSION}"
    echo "  Nginx   (任意版本)"
    echo ""
    echo "示例:"
    echo "  $0                     智能检测环境，仅安装缺失组件"
    echo "  $0 -d example.com      设置域名为example.com"
    echo "  $0 -f                  强制重新安装所有组件"
    echo "  $0 -f -d example.com   强制安装并设置域名"
}

# 解析参数
parse_args() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            -d|--domain)
                DOMAIN="$2"
                shift 2
                ;;
            -f|--force)
                FORCE_INSTALL=true
                NEED_INSTALL_PHP=true
                NEED_INSTALL_MYSQL=true
                NEED_INSTALL_NGINX=true
                NEED_INSTALL_NODE=true
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
}

# 主函数
main() {
    parse_args "$@"
    
    echo ""
    echo "============================================================"
    echo -e "${BLUE}IP管理器 - Linux一键部署脚本${NC}"
    echo -e "${CYAN}智能环境检测 | 版本验证 | 按需安装${NC}"
    echo "============================================================"
    echo ""

    check_root
    detect_os

    # 环境检测 (如果不是强制安装模式)
    if $FORCE_INSTALL; then
        echo ""
        log_warn "⚠ 强制安装模式：将重新安装所有组件"
        echo ""
    else
        check_environment
    fi

    # 根据系统类型执行安装
    case $OS in
        ubuntu|debian)
            # 如果有任何组件需要安装
            if $NEED_INSTALL_PHP || $NEED_INSTALL_MYSQL || $NEED_INSTALL_NGINX || $NEED_INSTALL_NODE; then
                install_debian
            fi
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
            # 如果有任何组件需要安装
            if $NEED_INSTALL_PHP || $NEED_INSTALL_MYSQL || $NEED_INSTALL_NGINX || $NEED_INSTALL_NODE; then
                install_centos
            fi
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
