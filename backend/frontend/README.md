# 困King 分发平台 - 前端

基于 Vue 3 + Vite + Element Plus 的现代化后台管理系统，采用 Vue-Element-Admin 完整版架构。

## 🚀 特性

- **Vue 3** - 使用 Composition API 和 `<script setup>` 语法
- **Vite** - 极速的开发体验和构建速度
- **Element Plus** - 企业级 UI 组件库
- **Pinia** - 新一代状态管理工具
- **权限管理** - 角色权限和动态路由
- **标签页导航** - 支持多页签和页面缓存
- **响应式布局** - 适配桌面和移动端
- **主题定制** - 支持主题色切换

## 📦 项目结构

```
src/
├── api/              # API 接口
├── components/       # 全局组件
├── directives/       # 全局指令
├── icons/            # SVG 图标
├── layout/           # 布局组件
│   └── components/   # 布局子组件
│       ├── Sidebar/  # 侧边栏
│       ├── TagsView/ # 标签页
│       └── Settings/ # 设置面板
├── router/           # 路由配置
├── stores/           # Pinia 状态管理
│   ├── user.js       # 用户状态
│   ├── permission.js # 权限状态
│   ├── settings.js   # 设置状态
│   └── tagsView.js   # 标签页状态
├── styles/           # 全局样式
├── utils/            # 工具函数
│   ├── auth.js       # 认证相关
│   ├── validate.js   # 验证规则
│   └── index.js      # 通用工具
└── views/            # 页面视图
```

## 🔧 开发

```bash
# 安装依赖
npm install

# 启动开发服务器
npm run dev

# 构建生产版本
npm run build

# 预览构建结果
npm run preview

# 代码检查
npm run lint
```

## 🔐 权限控制

### 路由权限

在路由配置中使用 `meta.roles` 控制访问权限：

```javascript
{
  path: '/admin',
  meta: { roles: ['admin'] }
}
```

### 指令权限

使用 `v-permission` 指令控制元素显示：

```vue
<el-button v-permission="['admin']">管理员操作</el-button>
```

### 函数权限

使用权限检查函数：

```javascript
import { hasPermission, hasRole } from '@/directives/permission'

if (hasRole('admin')) {
  // 管理员操作
}
```

## 🎨 主题定制

在 `src/styles/variables.scss` 中修改 SCSS 变量：

```scss
$primary-color: #409eff;
$sidebar-bg-color: #304156;
```

## 📝 环境变量

- `.env.development` - 开发环境配置
- `.env.production` - 生产环境配置

| 变量 | 说明 |
|------|------|
| VITE_APP_TITLE | 应用标题 |
| VITE_API_URL | API 地址 |
| VITE_APP_BASE_URL | 基础路径 |

## 📄 License

MIT
