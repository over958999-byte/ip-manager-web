# 困King分发平台 - Docker镜像
# 多阶段构建，优化镜像大小

# ============================================
# 阶段1: 前端构建
# ============================================
FROM node:25-alpine AS frontend-builder

WORKDIR /app/frontend

# 复制前端项目文件
COPY backend/frontend/package*.json ./

# 安装依赖
RUN npm ci --only=production

# 复制源代码
COPY backend/frontend/ ./

# 构建生产版本
RUN npm run build

# ============================================
# 阶段2: PHP运行环境
# ============================================
FROM php:8.2-fpm-alpine AS production

# 安装系统依赖
RUN apk add --no-cache \
    nginx \
    supervisor \
    mysql-client \
    curl \
    zip \
    unzip \
    git \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    redis

# 安装PHP扩展
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mysqli \
        mbstring \
        zip \
        intl \
        opcache \
        gd \
        bcmath \
        pcntl

# 安装Redis扩展
RUN pecl install redis apcu \
    && docker-php-ext-enable redis apcu

# 创建目录结构
RUN mkdir -p /var/www/html \
    && mkdir -p /var/log/nginx \
    && mkdir -p /var/log/php \
    && mkdir -p /var/log/supervisor \
    && mkdir -p /var/backups/ip-manager \
    && mkdir -p /run/nginx

# 复制PHP配置
COPY deploy/docker/php.ini /usr/local/etc/php/php.ini
COPY deploy/docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

# 复制Nginx配置
COPY deploy/docker/nginx.conf /etc/nginx/nginx.conf
COPY deploy/docker/nginx-site.conf /etc/nginx/http.d/default.conf

# 复制Supervisor配置
COPY deploy/docker/supervisord.conf /etc/supervisord.conf

# 复制后端代码
COPY backend/ /var/www/html/backend/
COPY public/ /var/www/html/public/

# 从前端构建阶段复制静态文件
COPY --from=frontend-builder /app/frontend/dist /var/www/html/public/static

# 设置权限
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 777 /var/backups/ip-manager

# 复制启动脚本
COPY deploy/docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# 健康检查
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/health.php || exit 1

# 暴露端口
EXPOSE 80

# 设置工作目录
WORKDIR /var/www/html

# 启动命令
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
