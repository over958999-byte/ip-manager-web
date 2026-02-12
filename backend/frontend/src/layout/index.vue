<template>
  <el-container class="layout-container">
    <!-- 侧边栏 -->
    <el-aside :width="isCollapse ? '64px' : '220px'" class="sidebar">
      <div class="logo-container" :class="{ collapsed: isCollapse }">
        <span v-if="!isCollapse">🌐 IP管理后台</span>
        <span v-else>IP</span>
      </div>
      <el-menu
        :default-active="$route.path"
        :collapse="isCollapse"
        :collapse-transition="false"
        router
        background-color="transparent"
      >
        <el-menu-item index="/dashboard">
          <el-icon><Odometer /></el-icon>
          <template #title>仪表盘</template>
        </el-menu-item>
        <el-menu-item index="/data-dashboard">
          <el-icon><DataAnalysis /></el-icon>
          <template #title>数据大盘</template>
        </el-menu-item>
        <el-menu-item index="/jump-rules">
          <el-icon><Promotion /></el-icon>
          <template #title>跳转管理</template>
        </el-menu-item>
        <el-menu-item index="/resource-pool">
          <el-icon><Coin /></el-icon>
          <template #title>资源池</template>
        </el-menu-item>
        <el-menu-item index="/antibot">
          <el-icon><Shield /></el-icon>
          <template #title>反爬虫管理</template>
        </el-menu-item>
        <el-menu-item index="/api-manager">
          <el-icon><Connection /></el-icon>
          <template #title>API管理</template>
        </el-menu-item>
        
        <!-- 系统管理分组 -->
        <el-sub-menu index="system">
          <template #title>
            <el-icon><Tools /></el-icon>
            <span>系统管理</span>
          </template>
          <el-menu-item index="/users">
            <el-icon><User /></el-icon>
            <template #title>用户管理</template>
          </el-menu-item>
          <el-menu-item index="/webhooks">
            <el-icon><Bell /></el-icon>
            <template #title>Webhook通知</template>
          </el-menu-item>
          <el-menu-item index="/backups">
            <el-icon><FolderOpened /></el-icon>
            <template #title>备份管理</template>
          </el-menu-item>
          <el-menu-item index="/audit-logs">
            <el-icon><Document /></el-icon>
            <template #title>审计日志</template>
          </el-menu-item>
          <el-menu-item index="/settings">
            <el-icon><Setting /></el-icon>
            <template #title>系统设置</template>
          </el-menu-item>
        </el-sub-menu>
      </el-menu>
      
      <!-- 底部信息面板 -->
      <div class="sidebar-footer" v-if="!isCollapse">
        <div class="info-panel">
          <!-- 在线管理员 -->
          <div class="online-stats">
            <div class="stat-item full-width">
              <div class="stat-icon admin-icon">👑</div>
              <div class="stat-info">
                <div class="stat-label">在线管理员:</div>
                <div class="stat-value">{{ onlineStats.admins }}</div>
              </div>
            </div>
          </div>
          
          <!-- 按钮行 -->
          <div class="action-row">
            <el-button size="small" @click="fetchVersionInfo">
              <el-icon><Refresh /></el-icon> 更新
            </el-button>
          </div>
          
          <!-- 版本信息 -->
          <div class="version-info-box">
            <div class="version-row">
              <span class="version-label">当前版本</span>
              <span class="version-value">
                {{ versionInfo.current }}
                <el-tag v-if="versionInfo.currentCommit" size="small" type="info">{{ versionInfo.currentCommit }}</el-tag>
              </span>
            </div>
            <div class="version-row">
              <span class="version-label">最新版本</span>
              <span class="version-value" :class="{ 'has-update': versionInfo.hasUpdate }">
                {{ versionInfo.latest }}
                <el-tag v-if="versionInfo.latestCommit" size="small" :type="versionInfo.hasUpdate ? 'danger' : 'success'">{{ versionInfo.latestCommit }}</el-tag>
              </span>
            </div>
          </div>
        </div>
      </div>
      <div class="sidebar-footer-collapsed" v-else>
        <el-tooltip :content="`v${versionInfo.current}`" placement="right">
          <el-badge :is-dot="versionInfo.hasUpdate" class="version-badge">
            <el-icon><InfoFilled /></el-icon>
          </el-badge>
        </el-tooltip>
      </div>
    </el-aside>

    <!-- 主内容区 -->
    <el-container>
      <!-- 头部 -->
      <el-header class="header" height="60px">
        <div class="header-left">
          <el-icon 
            class="collapse-btn" 
            @click="isCollapse = !isCollapse"
            style="cursor: pointer; font-size: 20px;"
          >
            <Fold v-if="!isCollapse" />
            <Expand v-else />
          </el-icon>
        </div>
        <div class="header-right">
          <!-- 当前登录用户 -->
          <div class="user-info">
            <el-icon><User /></el-icon>
            <span>当前登录: {{ userStore.username || 'admin' }}</span>
          </div>
          
          <!-- 通知铃铛 -->
          <el-badge :value="0" :hidden="true" class="header-icon">
            <el-icon size="20"><Bell /></el-icon>
          </el-badge>
          
          <!-- 项目配置按钮 -->
          <el-tooltip content="项目配置" placement="bottom">
            <div class="config-btn" @click="configDrawer = true">
              <el-icon size="20"><Setting /></el-icon>
            </div>
          </el-tooltip>
          
          <!-- 用户下拉菜单 -->
          <el-dropdown @command="handleCommand">
            <span class="el-dropdown-link" style="cursor: pointer; display: flex; align-items: center;">
              <el-avatar :size="32" style="background: #409eff; margin-right: 8px;">
                <el-icon><User /></el-icon>
              </el-avatar>
              <el-icon class="el-icon--right"><ArrowDown /></el-icon>
            </span>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="settings">
                  <el-icon><Setting /></el-icon> 系统设置
                </el-dropdown-item>
                <el-dropdown-item divided command="logout">
                  <el-icon><SwitchButton /></el-icon> 退出登录
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>
      </el-header>

      <!-- 主要内容 -->
      <el-main class="main-content">
        <router-view v-slot="{ Component }">
          <transition name="fade" mode="out-in">
            <component :is="Component" />
          </transition>
        </router-view>
      </el-main>
    </el-container>
  </el-container>
  
  <!-- 项目配置抽屉 -->
  <el-drawer v-model="configDrawer" title="项目配置" size="300px">
    <!-- 主题 -->
    <div class="config-section">
      <div class="section-title">
        <span class="section-icon">🌙</span>
        <span>主题</span>
      </div>
      <div class="theme-switch">
        <span>深色模式</span>
        <el-switch v-model="settings.darkMode" @change="toggleDarkMode" />
      </div>
    </div>
    
    <!-- 导航栏模式 -->
    <div class="config-section">
      <div class="section-title">
        <span class="section-icon">📐</span>
        <span>导航栏模式</span>
      </div>
      <div class="layout-options">
        <div 
          class="layout-option" 
          :class="{ active: settings.layout === 'vertical' }"
          @click="settings.layout = 'vertical'"
        >
          <div class="layout-preview vertical">
            <div class="preview-sidebar"></div>
            <div class="preview-content"></div>
          </div>
          <span>垂直布局</span>
        </div>
        <div 
          class="layout-option" 
          :class="{ active: settings.layout === 'horizontal' }"
          @click="settings.layout = 'horizontal'"
        >
          <div class="layout-preview horizontal">
            <div class="preview-header"></div>
            <div class="preview-body"></div>
          </div>
          <span>水平布局</span>
        </div>
      </div>
    </div>
    
    <!-- 主题色 -->
    <div class="config-section">
      <div class="section-title">
        <span class="section-icon">🎨</span>
        <span>主题色</span>
      </div>
      <div class="color-options">
        <div 
          v-for="color in themeColors" 
          :key="color.name"
          class="color-option"
          :class="{ active: settings.themeColor === color.value }"
          @click="changeThemeColor(color.value)"
        >
          <div class="color-circle" :style="{ background: color.value }"></div>
          <span>{{ color.name }}</span>
        </div>
      </div>
    </div>
    
    <!-- 界面显示 -->
    <div class="config-section">
      <div class="section-title">
        <span class="section-icon">⚙️</span>
        <span>界面显示</span>
      </div>
      <div class="display-options">
        <div class="display-item">
          <div class="display-info">
            <span class="display-label">灰色模式</span>
            <span class="display-desc">调整页面为灰度模式</span>
          </div>
          <el-switch v-model="settings.grayMode" @change="toggleGrayMode" />
        </div>
        <div class="display-item">
          <div class="display-info">
            <span class="display-label">色弱模式</span>
            <span class="display-desc">适合色弱用户的显示模式</span>
          </div>
          <el-switch v-model="settings.colorWeak" @change="toggleColorWeak" />
        </div>
        <div class="display-item">
          <div class="display-info">
            <span class="display-label">侧边栏Logo</span>
            <span class="display-desc">显示侧边栏Logo标识</span>
          </div>
          <el-switch v-model="settings.showLogo" />
        </div>
        <div class="display-item">
          <div class="display-info">
            <span class="display-label">固定Header</span>
            <span class="display-desc">固定顶部导航栏</span>
          </div>
          <el-switch v-model="settings.fixedHeader" />
        </div>
      </div>
    </div>
  </el-drawer>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '../stores/user'
import { ElMessageBox } from 'element-plus'
import { checkUpdate } from '../api'
import { Refresh, Bell } from '@element-plus/icons-vue'

const router = useRouter()
const userStore = useUserStore()
const isCollapse = ref(false)

// 项目配置抽屉
const configDrawer = ref(false)

// 版本信息
const versionInfo = ref({
  current: '...',
  currentCommit: '',
  latest: '...',
  latestCommit: '',
  hasUpdate: false
})

// 在线统计
const onlineStats = ref({
  admins: 1
})

// 主题色选项
const themeColors = [
  { name: 'Default', value: '#1b2a47' },
  { name: 'Light', value: '#ffffff' },
  { name: 'Dusk', value: '#f5222d' },
  { name: 'Volcano', value: '#fa541c' },
  { name: 'Yellow', value: '#faad14' },
  { name: 'MingQing', value: '#13c2c2' },
  { name: 'AuroraGreen', value: '#52c41a' },
  { name: 'Pink', value: '#eb2f96' },
  { name: 'SaucePurple', value: '#722ed1' },
  { name: 'Blue', value: '#409eff' }
]

// 界面设置
const settings = ref({
  darkMode: false,
  layout: 'vertical',
  themeColor: '#409eff',
  grayMode: false,
  colorWeak: false,
  showLogo: true,
  fixedHeader: true
})

// 切换深色模式
const toggleDarkMode = (val) => {
  if (val) {
    document.documentElement.classList.add('dark')
  } else {
    document.documentElement.classList.remove('dark')
  }
  localStorage.setItem('darkMode', val)
}

// 切换灰色模式
const toggleGrayMode = (val) => {
  if (val) {
    document.documentElement.style.filter = 'grayscale(100%)'
  } else {
    document.documentElement.style.filter = ''
  }
  localStorage.setItem('grayMode', val)
}

// 切换色弱模式
const toggleColorWeak = (val) => {
  if (val) {
    document.documentElement.style.filter = 'invert(80%)'
  } else {
    document.documentElement.style.filter = ''
  }
  localStorage.setItem('colorWeak', val)
}

// 切换主题色
const changeThemeColor = (color) => {
  settings.value.themeColor = color
  document.documentElement.style.setProperty('--el-color-primary', color)
  localStorage.setItem('themeColor', color)
}

// 加载保存的设置
const loadSettings = () => {
  const savedDarkMode = localStorage.getItem('darkMode') === 'true'
  const savedGrayMode = localStorage.getItem('grayMode') === 'true'
  const savedColorWeak = localStorage.getItem('colorWeak') === 'true'
  const savedThemeColor = localStorage.getItem('themeColor')
  
  if (savedDarkMode) {
    settings.value.darkMode = true
    document.documentElement.classList.add('dark')
  }
  if (savedGrayMode) {
    settings.value.grayMode = true
    document.documentElement.style.filter = 'grayscale(100%)'
  }
  if (savedColorWeak) {
    settings.value.colorWeak = true
    document.documentElement.style.filter = 'invert(80%)'
  }
  if (savedThemeColor) {
    settings.value.themeColor = savedThemeColor
    document.documentElement.style.setProperty('--el-color-primary', savedThemeColor)
  }
}

// 获取版本信息
const fetchVersionInfo = async () => {
  try {
    const updateRes = await checkUpdate()
    if (updateRes.success && updateRes.data) {
      versionInfo.value.current = updateRes.data.current_version || '1.0.0'
      versionInfo.value.currentCommit = updateRes.data.local_version || ''
      versionInfo.value.latest = updateRes.data.current_version || '1.0.0'
      versionInfo.value.latestCommit = updateRes.data.remote_version || ''
      versionInfo.value.hasUpdate = updateRes.data.has_update || false
    }
  } catch (e) {
    console.error('获取版本信息失败:', e)
  }
}

onMounted(() => {
  loadSettings()
  fetchVersionInfo()
})

const handleCommand = async (command) => {
  if (command === 'logout') {
    await ElMessageBox.confirm('确定要退出登录吗？', '提示', {
      type: 'warning'
    })
    await userStore.logout()
    router.push('/login')
  } else if (command === 'settings') {
    router.push('/settings')
  }
}
</script>

<style scoped>
.layout-container {
  height: 100vh;
}

.sidebar {
  display: flex;
  flex-direction: column;
}

.sidebar > .el-menu {
  flex: 1;
  overflow-y: auto;
}

/* 底部信息面板 */
.sidebar-footer {
  padding: 10px;
  border-top: 1px solid rgba(255, 255, 255, 0.1);
}

.info-panel {
  background: linear-gradient(135deg, #e8f4fc 0%, #f5f9fc 100%);
  border-radius: 8px;
  padding: 12px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

/* 在线统计 */
.online-stats {
  display: flex;
  gap: 8px;
  margin-bottom: 10px;
}

.stat-item {
  flex: 1;
  display: flex;
  align-items: center;
  background: #fff;
  border-radius: 6px;
  padding: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}

.stat-item.full-width {
  justify-content: center;
}

.stat-icon {
  font-size: 24px;
  margin-right: 6px;
}

.stat-info {
  display: flex;
  flex-direction: column;
}

.stat-label {
  font-size: 11px;
  color: #666;
}

.stat-value {
  font-size: 16px;
  font-weight: bold;
  color: #333;
}

/* 按钮行 */
.action-row {
  display: flex;
  justify-content: center;
  margin-bottom: 10px;
}

.action-row .el-button {
  flex: 1;
}

/* 版本信息盒子 */
.version-info-box {
  background: #fff;
  border-radius: 6px;
  padding: 8px 10px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}

.version-info-box .version-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 12px;
  padding: 4px 0;
}

.version-info-box .version-row:first-child {
  border-bottom: 1px dashed #eee;
  padding-bottom: 6px;
  margin-bottom: 2px;
}

.version-info-box .version-label {
  color: #888;
}

.version-info-box .version-value {
  color: #409eff;
  font-weight: 500;
  display: flex;
  align-items: center;
  gap: 4px;
}

.version-info-box .version-value.has-update {
  color: #f56c6c;
}

/* 折叠状态 */
.sidebar-footer-collapsed {
  padding: 12px 0;
  text-align: center;
  border-top: 1px solid rgba(255, 255, 255, 0.1);
}

.version-badge {
  cursor: pointer;
  color: rgba(255, 255, 255, 0.6);
  font-size: 18px;
}

.version-badge:hover {
  color: #409eff;
}

/* Header 样式 */
.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 1px solid #eee;
}

.header-left {
  display: flex;
  align-items: center;
}

.header-right {
  display: flex;
  align-items: center;
  gap: 16px;
}

.user-info {
  display: flex;
  align-items: center;
  gap: 6px;
  color: #666;
  font-size: 14px;
}

.header-icon {
  cursor: pointer;
  color: #666;
  transition: color 0.3s;
}

.header-icon:hover {
  color: #409eff;
}

.config-btn {
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 6px;
  cursor: pointer;
  color: #666;
  border: 1px solid #ddd;
  transition: all 0.3s;
}

.config-btn:hover {
  color: #409eff;
  border-color: #409eff;
  background: #ecf5ff;
}

.collapse-btn:hover {
  color: #409eff;
}

/* 项目配置抽屉样式 */
.config-section {
  margin-bottom: 24px;
}

.section-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 15px;
  font-weight: 500;
  color: #409eff;
  margin-bottom: 12px;
  padding: 8px 12px;
  background: #ecf5ff;
  border-radius: 6px;
}

.section-icon {
  font-size: 18px;
}

.theme-switch {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 12px;
}

/* 布局选项 */
.layout-options {
  display: flex;
  gap: 12px;
}

.layout-option {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 8px;
  border: 2px solid #eee;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.3s;
}

.layout-option:hover {
  border-color: #409eff;
}

.layout-option.active {
  border-color: #409eff;
  background: #ecf5ff;
}

.layout-preview {
  width: 60px;
  height: 45px;
  border-radius: 4px;
  overflow: hidden;
  margin-bottom: 6px;
  border: 1px solid #ddd;
}

.layout-preview.vertical {
  display: flex;
}

.layout-preview.vertical .preview-sidebar {
  width: 15px;
  background: #409eff;
}

.layout-preview.vertical .preview-content {
  flex: 1;
  background: #f5f5f5;
}

.layout-preview.horizontal .preview-header {
  height: 10px;
  background: #409eff;
}

.layout-preview.horizontal .preview-body {
  flex: 1;
  background: #f5f5f5;
}

.layout-option span {
  font-size: 12px;
  color: #666;
}

/* 主题色选项 */
.color-options {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}

.color-option {
  display: flex;
  flex-direction: column;
  align-items: center;
  cursor: pointer;
}

.color-circle {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  margin-bottom: 4px;
  border: 2px solid transparent;
  transition: all 0.3s;
}

.color-option:hover .color-circle {
  transform: scale(1.1);
}

.color-option.active .color-circle {
  border-color: #333;
  box-shadow: 0 0 0 2px #fff, 0 0 0 4px currentColor;
}

.color-option span {
  font-size: 11px;
  color: #666;
}

/* 界面显示选项 */
.display-options {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.display-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 0;
  border-bottom: 1px solid #f0f0f0;
}

.display-item:last-child {
  border-bottom: none;
}

.display-info {
  display: flex;
  flex-direction: column;
}

.display-label {
  font-size: 14px;
  color: #333;
}

.display-desc {
  font-size: 12px;
  color: #999;
}

/* 过渡动画 */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
