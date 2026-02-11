<template>
  <div class="navbar">
    <!-- 左侧 -->
    <div class="left-menu">
      <!-- 折叠按钮 -->
      <Hamburger :is-active="!sidebarCollapsed" @toggle-click="toggleSidebar" />
      
      <!-- 面包屑 -->
      <Breadcrumb class="breadcrumb-container" />
    </div>
    
    <!-- 右侧 -->
    <div class="right-menu">
      <!-- 搜索 -->
      <el-tooltip content="搜索" placement="bottom">
        <div class="right-menu-item hover-effect">
          <el-icon><Search /></el-icon>
        </div>
      </el-tooltip>
      
      <!-- 全屏 -->
      <el-tooltip :content="isFullscreen ? '退出全屏' : '全屏'" placement="bottom">
        <div class="right-menu-item hover-effect" @click="toggleFullscreen">
          <el-icon>
            <FullScreen v-if="!isFullscreen" />
            <Aim v-else />
          </el-icon>
        </div>
      </el-tooltip>
      
      <!-- 设置 -->
      <el-tooltip content="布局设置" placement="bottom">
        <div class="right-menu-item hover-effect" @click="showSettings">
          <el-icon><Setting /></el-icon>
        </div>
      </el-tooltip>
      
      <!-- 用户下拉菜单 -->
      <el-dropdown trigger="click" @command="handleCommand">
        <div class="avatar-container right-menu-item hover-effect">
          <el-avatar :size="30" :src="avatar" class="user-avatar">
            <el-icon><User /></el-icon>
          </el-avatar>
          <span class="user-name">{{ username }}</span>
          <el-icon class="el-icon--right"><CaretBottom /></el-icon>
        </div>
        <template #dropdown>
          <el-dropdown-menu>
            <router-link to="/">
              <el-dropdown-item>
                <el-icon><House /></el-icon> 首页
              </el-dropdown-item>
            </router-link>
            <el-dropdown-item divided command="logout">
              <el-icon><SwitchButton /></el-icon> 退出登录
            </el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessageBox, ElMessage } from 'element-plus'
import { useSettingsStore, useUserStore } from '@/stores'
import Hamburger from './Hamburger.vue'
import Breadcrumb from './Breadcrumb.vue'
import screenfull from 'screenfull'

const router = useRouter()
const settingsStore = useSettingsStore()
const userStore = useUserStore()

const sidebarCollapsed = computed(() => settingsStore.sidebarCollapsed)
const avatar = computed(() => userStore.avatar)
const username = computed(() => userStore.username || '管理员')

const isFullscreen = ref(false)

// 切换侧边栏
const toggleSidebar = () => {
  settingsStore.toggleSidebar()
}

// 全屏切换
const toggleFullscreen = () => {
  if (!screenfull.isEnabled) {
    ElMessage.warning('您的浏览器不支持全屏')
    return
  }
  screenfull.toggle()
}

// 监听全屏变化
const handleFullscreenChange = () => {
  isFullscreen.value = screenfull.isFullscreen
}

onMounted(() => {
  if (screenfull.isEnabled) {
    screenfull.on('change', handleFullscreenChange)
  }
})

onBeforeUnmount(() => {
  if (screenfull.isEnabled) {
    screenfull.off('change', handleFullscreenChange)
  }
})

// 显示设置面板
const showSettings = () => {
  settingsStore.toggleSettingsPanel()
}

// 处理下拉菜单命令
const handleCommand = async (command) => {
  if (command === 'logout') {
    try {
      await ElMessageBox.confirm('确定要退出登录吗？', '提示', {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        type: 'warning'
      })
      await userStore.logout()
    } catch {
      // 取消操作
    }
  }
}
</script>

<style lang="scss" scoped>
.navbar {
  height: 50px;
  overflow: hidden;
  position: relative;
  background: #fff;
  box-shadow: 0 1px 4px rgba(0, 21, 41, 0.08);
  display: flex;
  justify-content: space-between;
  align-items: center;

  .left-menu {
    display: flex;
    align-items: center;
  }

  .breadcrumb-container {
    margin-left: 15px;
  }

  .right-menu {
    display: flex;
    align-items: center;
    height: 100%;
    line-height: 50px;
    padding-right: 15px;

    &:focus {
      outline: none;
    }

    .right-menu-item {
      display: inline-flex;
      align-items: center;
      padding: 0 10px;
      height: 100%;
      font-size: 18px;
      color: #5a5e66;
      vertical-align: text-bottom;
      cursor: pointer;

      &.hover-effect:hover {
        background: rgba(0, 0, 0, 0.025);
      }
    }

    .avatar-container {
      .user-avatar {
        cursor: pointer;
        vertical-align: middle;
      }

      .user-name {
        margin-left: 8px;
        font-size: 14px;
        max-width: 100px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
    }
  }
}
</style>
