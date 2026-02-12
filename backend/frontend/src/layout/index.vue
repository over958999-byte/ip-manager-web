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
      
      <!-- 底部版本信息 -->
      <div class="sidebar-footer" v-if="!isCollapse">
        <div class="version-info">
          <div class="version-row">
            <span class="version-label">当前版本</span>
            <span class="version-value">{{ versionInfo.current || '...' }}</span>
          </div>
          <div class="version-row">
            <span class="version-label">最新版本</span>
            <span class="version-value" :class="{ 'has-update': versionInfo.hasUpdate }">
              {{ versionInfo.latest || '...' }}
              <el-tag v-if="versionInfo.hasUpdate" type="danger" size="small" effect="dark" style="margin-left: 4px;">NEW</el-tag>
            </span>
          </div>
        </div>
      </div>
      <div class="sidebar-footer-collapsed" v-else>
        <el-tooltip :content="`v${versionInfo.current}${versionInfo.hasUpdate ? ' (有更新)' : ''}`" placement="right">
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
import { getSystemInfo, checkUpdate } from '../api'

const router = useRouter()
const userStore = useUserStore()
const isCollapse = ref(false)

// 版本信息
const versionInfo = ref({
  current: '',
  latest: '',
  hasUpdate: false
})

// 获取版本信息
const fetchVersionInfo = async () => {
  try {
    // 获取当前版本
    const sysRes = await getSystemInfo()
    if (sysRes.success && sysRes.data) {
      versionInfo.value.current = sysRes.data.system_version || '1.0.0'
    }
    
    // 检查更新获取最新版本
    const updateRes = await checkUpdate()
    if (updateRes.success && updateRes.data) {
      versionInfo.value.latest = updateRes.data.latest_version || versionInfo.value.current
      versionInfo.value.hasUpdate = updateRes.data.has_update || false
    }
  } catch (e) {
    console.error('获取版本信息失败:', e)
    versionInfo.value.current = '1.0.0'
    versionInfo.value.latest = '1.0.0'
  }
}

onMounted(() => {
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

/* 底部版本信息 - 展开状态 */
.sidebar-footer {
  padding: 12px 16px;
  border-top: 1px solid rgba(255, 255, 255, 0.1);
  background: rgba(0, 0, 0, 0.2);
}

.version-info {
  font-size: 12px;
}

.version-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 4px 0;
}

.version-label {
  color: rgba(255, 255, 255, 0.5);
}

.version-value {
  color: rgba(255, 255, 255, 0.8);
  font-family: 'Monaco', 'Menlo', monospace;
}

.version-value.has-update {
  color: #f56c6c;
}

/* 底部版本信息 - 折叠状态 */
.sidebar-footer-collapsed {
  padding: 12px 0;
  text-align: center;
  border-top: 1px solid rgba(255, 255, 255, 0.1);
  background: rgba(0, 0, 0, 0.2);
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
