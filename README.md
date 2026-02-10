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
