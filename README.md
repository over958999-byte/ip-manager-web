# IP管理器网页版

## 项目结构

```
IP管理器网页版后台/
│
├── backend/                    # 后端 - 管理后台
│   ├── api/                    # API接口
│   │   └── api.php            # 后台管理API
│   ├── core/                   # 核心类库
│   │   └── database.php       # 数据库操作类
│   ├── frontend/              # Vue前端（管理界面）
│   │   ├── src/               # 源代码
│   │   ├── package.json       # 依赖配置
│   │   └── vite.config.js     # Vite配置
│   ├── database.sql           # 数据库结构
│   └── init_config.sql        # 初始化配置
│
├── public/                     # 前端 - 用户访问入口
│   ├── index.php              # IP跳转主入口
│   ├── antibot.php            # 反爬虫防护系统
│   └── bad_ips.php            # 恶意IP数据库
│
├── config/                     # 配置文件
│   ├── config.json            # 主配置（已废弃，使用数据库）
│   ├── global.json            # 全局配置
│   └── *.conf                 # Nginx配置模板
│
├── data/                       # 数据文件
│   ├── antibot_data.json      # 反爬虫数据（已废弃）
│   ├── stats.json             # 统计数据（已废弃）
│   └── ip_list.txt            # IP列表
│
├── backup/                     # 备份文件
│   ├── api_old.php            # 旧API备份
│   ├── antibot_old.php        # 旧反爬虫备份
│   └── index_old.php          # 旧入口备份
│
├── deploy/                     # 部署相关
│   ├── start_dev.bat          # 开发环境启动脚本
│   ├── deploy.bat             # 部署脚本
│   ├── docker-compose-php.yml # Docker配置
│   └── iptest.php             # 测试脚本
│
└── dist/                       # 前端构建输出
```

## 概念说明

- **后端（backend）**：管理后台系统
  - 包括 Vue 管理界面和 PHP API
  - 用于管理员配置 IP 跳转规则、查看统计、管理反爬虫等

- **前端（public）**：用户访问入口
  - 处理用户的 IP 访问请求
  - 执行跳转逻辑和反爬虫检测
  - 这是真实用户访问的入口点

## 快速开始

### 开发环境

1. 双击 `deploy/start_dev.bat` 启动开发服务器

或手动启动：

```bash
# 启动 PHP 服务器（在项目根目录）
php -S localhost:8080

# 启动前端开发服务器（在 backend/frontend 目录）
cd backend/frontend
npm run dev
```

### 访问地址

- **管理后台**: http://localhost:3000
- **PHP 服务**: http://localhost:8080
- **用户入口**: http://localhost:8080/public/
- **默认密码**: admin123

## 数据库

项目使用 MySQL 8.4 存储数据。

```bash
# 导入数据库结构
mysql -u root ip_manager < backend/database.sql

# 导入初始配置
mysql -u root ip_manager < backend/init_config.sql
```

## 生产部署

1. 将 `public/` 目录配置为 Web 服务器根目录
2. 将 `backend/api/` 目录配置为后台 API 访问路径
3. 构建前端：`cd backend/frontend && npm run build`
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

