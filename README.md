# 困King分发平台

## 🎯 项目简介

高性能、企业级的 IP 跳转管理系统，支持智能流量分发、反爬虫防护、域名安全检测等功能。

## ✨ V2.0 新特性

### 🔐 安全增强
- **登录失败锁定** - IP 连续失败 5 次后锁定 15 分钟
- **TOTP 双因素认证** - 兼容 Google Authenticator
- **操作审计日志** - 完整记录所有管理操作
- **多用户权限** - 支持 Admin/Operator/Viewer 三种角色
- **API Key 管理** - 支持细粒度权限控制和速率限制

### 🚀 性能优化
- **Redis 缓存** - 支持 APCu 降级，分布式部署友好
- **数据库读写分离** - 支持 Master-Slave 集群
- **Prometheus 监控** - 标准 `/metrics` 端点

### 📊 功能扩展
- **数据可视化大盘** - ECharts 图表展示
- **批量导入导出** - 支持 CSV/Excel/JSON
- **Webhook 通知** - 支持企业微信/钉钉/飞书/Slack
- **自动备份** - 支持阿里云OSS/腾讯云COS/AWS S3

### 🐳 DevOps
- **Docker 容器化** - 一键部署完整服务栈
- **蓝绿部署** - 零停机更新
- **Grafana 监控** - 预置监控大盘

## 📁 项目结构

```
IP管理器网页版后台/
├── backend/                    # 后端核心
│   ├── api/api.php            # API 入口
│   ├── core/                   # 核心模块
│   │   ├── database.php       # 数据库基础
│   │   ├── database_cluster.php # 读写分离
│   │   ├── security.php       # 安全模块
│   │   ├── audit.php          # 审计日志
│   │   ├── redis.php          # Redis 缓存
│   │   ├── webhook.php        # Webhook 通知
│   │   ├── backup.php         # 备份服务
│   │   ├── prometheus.php     # Prometheus 指标
│   │   └── import_export.php  # 导入导出
│   ├── cron/                   # 定时任务
│   ├── frontend/              # Vue 管理界面
│   ├── install.sql            # 数据库初始化
│   └── migrate_v2.sql         # V2.0 迁移脚本
├── public/                     # Web 入口
│   ├── index.php              # 跳转入口
│   ├── antibot.php            # 反爬验证
│   ├── health.php             # 健康检查
│   └── metrics.php            # Prometheus 端点
├── deploy/                     # 部署配置
│   ├── docker/                # Docker 配置
│   ├── blue-green.sh          # 蓝绿部署脚本
│   └── ...
├── Dockerfile                  # 镜像构建
├── docker-compose.yml          # 完整服务栈
└── .env.example               # 环境变量模板
```

## 🚀 快速开始

### Docker 部署（推荐）

```bash
# 1. 复制环境变量
cp .env.example .env

# 2. 修改配置（重要！）
vim .env  # 修改密码、密钥等

# 3. 启动服务
docker compose up -d

# 4. 访问管理后台
open http://localhost:80
```

### 手动部署

```bash
# 1. 安装依赖
cd backend/frontend && npm install

# 2. 构建前端
npm run build

# 3. 导入数据库
mysql -u root -p ip_manager < backend/install.sql
mysql -u root -p ip_manager < backend/migrate_v2.sql

# 4. 配置 Nginx（参考 config/*.conf）
```

### 开发环境

```bash
# Windows
deploy\start_dev.bat

# 或手动启动
php -S localhost:8080            # PHP 服务
cd backend/frontend && npm run dev  # Vue 开发
```

## 🔧 配置说明

### 环境变量

| 变量 | 说明 | 默认值 |
|------|------|--------|
| `ADMIN_USER` | 管理员用户名 | admin |
| `ADMIN_PASS` | 管理员密码 | admin123 |
| `JWT_SECRET` | JWT 密钥 | (必须修改) |
| `DB_HOST` | 数据库地址 | mysql |
| `REDIS_HOST` | Redis 地址 | redis |
| `BACKUP_CLOUD_PROVIDER` | 云存储类型 | (可选) |

### V2.0 数据库迁移

```bash
# 从 V1.x 升级
mysql -u root -p ip_manager < backend/migrate_v2.sql
```

## 📡 API 端点

### Prometheus 指标
```
GET /metrics.php
Authorization: Bearer <token>  # 可选
```

### 健康检查
```
GET /health.php
```

## 📊 监控告警

### Grafana 访问
- URL: `http://localhost:3000`
- 默认账号: admin/admin123

### Webhook 告警
支持推送到以下平台：
- 企业微信
- 钉钉（支持签名验证）
- 飞书
- Slack
- 自定义 HTTP

## 🔐 安全建议

1. **生产环境必须修改** `JWT_SECRET`
2. 启用 TOTP 双因素认证
3. 配置 IP 白名单访问管理后台
4. 定期备份并验证恢复流程
5. 启用 HTTPS

## 📝 更新日志

### V2.0.0 (2024-xx-xx)
- 新增：登录失败锁定机制
- 新增：TOTP 双因素认证
- 新增：操作审计日志
- 新增：Redis 缓存支持
- 新增：数据库读写分离
- 新增：Webhook 多平台通知
- 新增：自动备份（支持云存储）
- 新增：Prometheus 监控指标
- 新增：批量导入导出
- 新增：Docker 容器化部署
- 新增：蓝绿部署支持
- 新增：数据可视化大盘
- 优化：整体架构重构

## 📄 License

MIT License
4. 配置 Nginx 反向代理

## 高并发优化

本项目针对短链服务实现了完整的高并发解决方案：

### 架构特性

```
┌─────────────────────────────────────────────────────────────┐
│                      Nginx (限流层)                          │
│  - 令牌桶限流 (30r/s per IP)                                 │
│  - 全局限流 (5000r/s)                                        │
│  - 连接数限制                                                │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│                    PHP-FPM (高并发池)                        │
│  - 50个静态进程                                              │
│  - OPcache 字节码缓存                                        │
│  - APCu 用户数据缓存                                         │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│                   应用层 (多级保护)                          │
│                                                              │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐    │
│  │ 缓存层    │  │ 限流器   │  │ 熔断器   │  │ 消息队列 │    │
│  │          │  │          │  │          │  │          │    │
│  │- 内存缓存 │  │- 令牌桶  │  │- 失败阈值 │  │- 异步日志 │    │
│  │- APCu   │  │- 滑动窗口 │  │- 自动恢复 │  │- 批量写入 │    │
│  │- 布隆过滤│  │- 固定窗口 │  │- 降级处理 │  │- 死信队列 │    │
│  └──────────┘  └──────────┘  └──────────┘  └──────────┘    │
│                                                              │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│                     MySQL (数据层)                           │
│  - 索引优化                                                  │
│  - 连接池                                                    │
│  - 异步写入                                                  │
└─────────────────────────────────────────────────────────────┘
```

### 核心组件

| 组件 | 文件 | 功能 |
|------|------|------|
| 缓存服务 | `backend/core/cache.php` | 多级缓存、布隆过滤器、防穿透/击穿/雪崩 |
| 限流器 | `backend/core/rate_limiter.php` | 令牌桶、滑动窗口、漏桶限流 |
| 熔断器 | `backend/core/circuit_breaker.php` | 服务熔断、自动恢复 |
| 消息队列 | `backend/core/message_queue.php` | 异步任务、批量处理 |
| 高性能入口 | `public/s.php` | 优化的短链跳转入口 |
| 后台Worker | `public/worker.php` | 异步任务处理 |
| 缓存预热 | `public/warmup.php` | 系统启动预热 |
| 健康检查 | `public/health.php` | 系统监控接口 |

### 缓存策略

1. **防缓存穿透**：布隆过滤器 + 空值缓存
2. **防缓存击穿**：分布式锁 + 互斥加载
3. **防缓存雪崩**：随机TTL + 预热
4. **缓存一致性**：延迟双删

### 部署步骤

```bash
# 1. 运行数据库迁移
mysql -u root ip_manager < backend/migrations/high_concurrency.sql

# 2. 使用高性能Nginx配置
sudo cp config/nginx-highperf.conf /etc/nginx/sites-available/ip-manager
sudo nginx -t && sudo systemctl reload nginx

# 3. 配置PHP-FPM
sudo cp config/php-fpm-pool.conf /etc/php/8.2/fpm/pool.d/www.conf
sudo systemctl restart php8.2-fpm

# 4. 启用APCu扩展
sudo apt install php8.2-apcu
sudo systemctl restart php8.2-fpm

# 5. 预热缓存
php public/warmup.php

# 6. 启动后台Worker（定时任务）
echo "* * * * * php /var/www/ip-manager/public/worker.php" | crontab -
```

### 监控

访问 `/health.php` 查看系统状态：

```json
{
  "status": "healthy",
  "components": {
    "database": {"status": "healthy"},
    "cache": {"status": "healthy", "apcu_enabled": true},
    "rate_limiter": {"status": "healthy"},
    "circuit_breaker": {"status": "healthy"}
  },
  "metrics": {
    "cache_hit_rate": 95.5,
    "queue_pending": 0,
    "active_rules": 1000
  }
}
```

### 性能预期

| 指标 | 数值 |
|------|------|
| 单机QPS | 5000+ |
| 平均响应时间 | <10ms |
| P99响应时间 | <50ms |
| 缓存命中率 | >95% |

