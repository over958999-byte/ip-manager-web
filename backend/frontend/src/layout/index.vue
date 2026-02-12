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
          <!-- 在线统计 -->
          <div class="online-stats">
            <div class="stat-item">
              <div class="stat-icon user-icon">👤</div>
              <div class="stat-info">
                <div class="stat-label">在线用户:</div>
                <div class="stat-value">{{ onlineStats.users }}</div>
              </div>
            </div>
            <div class="stat-item">
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
          
          <!-- 版本号 -->
          <div class="version-row">
            <span class="version-icon">ℹ️</span>
            <span class="version-text">当前版本: </span>
            <span class="version-number">{{ versionInfo.current }}</span>
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
          <el-dropdown @command="handleCommand">
            <span class="el-dropdown-link" style="cursor: pointer; display: flex; align-items: center;">
              <el-avatar :size="32" style="background: #409eff; margin-right: 8px;">
                <el-icon><User /></el-icon>
              </el-avatar>
              管理员
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
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '../stores/user'
import { ElMessageBox } from 'element-plus'
import { checkUpdate } from '../api'
import { Refresh } from '@element-plus/icons-vue'

const router = useRouter()
const userStore = useUserStore()
const isCollapse = ref(false)

// 版本信息
const versionInfo = ref({
  current: '...',
  hasUpdate: false
})

// 在线统计
const onlineStats = ref({
  users: 0,
  admins: 1
})

// 获取版本信息
const fetchVersionInfo = async () => {
  try {
    const updateRes = await checkUpdate()
    if (updateRes.success && updateRes.data) {
      versionInfo.value.current = updateRes.data.current_version || '1.0.0'
      versionInfo.value.hasUpdate = updateRes.data.has_update || false
    }
  } catch (e) {
    console.error('获取版本信息失败:', e)
  }
}

onMounted(() => {
  fetchVersionInfo()
})
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

/* 版本行 */
.version-row {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  color: #666;
  padding-top: 6px;
  border-top: 1px dashed #ddd;
}

.version-icon {
  margin-right: 4px;
}

.version-text {
  color: #888;
}

.version-number {
  color: #409eff;
  font-weight: 500;
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

.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.header-left {
  display: flex;
  align-items: center;
}

.header-right {
  display: flex;
  align-items: center;
}

.collapse-btn:hover {
  color: #409eff;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
