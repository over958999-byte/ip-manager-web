#!/bin/sh
set -e

echo "=========================================="
echo "困King分发平台 - 容器启动"
echo "=========================================="

# 等待MySQL就绪
echo "等待MySQL就绪..."
max_tries=30
counter=0
until mysql -h"${DB_HOST:-mysql}" -u"${DB_USER:-root}" -p"${DB_PASS:-}" -e "SELECT 1" > /dev/null 2>&1; do
    counter=$((counter + 1))
    if [ $counter -gt $max_tries ]; then
        echo "错误: MySQL连接超时"
        exit 1
    fi
    echo "等待MySQL... ($counter/$max_tries)"
    sleep 2
done
echo "MySQL已就绪"

# 等待Redis就绪
if [ -n "${REDIS_HOST}" ]; then
    echo "等待Redis就绪..."
    counter=0
    until redis-cli -h "${REDIS_HOST}" -p "${REDIS_PORT:-6379}" ${REDIS_PASSWORD:+-a $REDIS_PASSWORD} ping > /dev/null 2>&1; do
        counter=$((counter + 1))
        if [ $counter -gt $max_tries ]; then
            echo "警告: Redis连接超时，将使用APCu作为备用缓存"
            break
        fi
        echo "等待Redis... ($counter/$max_tries)"
        sleep 2
    done
    echo "Redis已就绪"
fi

# 创建必要的目录
mkdir -p /var/log/nginx
mkdir -p /var/log/php
mkdir -p /var/backups/ip-manager

# 设置权限
chown -R www-data:www-data /var/www/html
chown -R www-data:www-data /var/backups/ip-manager

# 生成配置文件
echo "生成配置文件..."
cat > /var/www/html/backend/config.php << EOF
<?php
// 自动生成的配置文件
define('DB_HOST', '${DB_HOST:-mysql}');
define('DB_NAME', '${DB_NAME:-ip_manager}');
define('DB_USER', '${DB_USER:-root}');
define('DB_PASS', '${DB_PASS:-}');

define('REDIS_HOST', '${REDIS_HOST:-redis}');
define('REDIS_PORT', ${REDIS_PORT:-6379});
define('REDIS_PASSWORD', '${REDIS_PASSWORD:-}');

define('APP_ENV', '${APP_ENV:-production}');
define('APP_DEBUG', ${APP_DEBUG:-false});

define('ADMIN_USER', '${ADMIN_USER:-admin}');
define('ADMIN_PASS', '${ADMIN_PASS:-admin}');
define('JWT_SECRET', '${JWT_SECRET:-change-me-in-production}');

define('APP_VERSION', '2.0.0');
EOF

echo "配置文件已生成"

# 优化PHP OPcache（生产环境）
if [ "${APP_ENV}" = "production" ]; then
    echo "启用PHP OPcache预热..."
    php /var/www/html/public/warmup.php 2>/dev/null || true
fi

echo "=========================================="
echo "启动服务..."
echo "=========================================="

# 执行传入的命令
exec "$@"
