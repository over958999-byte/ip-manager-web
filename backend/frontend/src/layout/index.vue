<template>
  <div :class="classObj" class="app-wrapper">
    <!-- 移动端遮罩 -->
    <div v-if="device === 'mobile' && sidebar.opened" class="drawer-bg" @click="handleClickOutside" />
    
    <!-- 侧边栏 -->
    <Sidebar class="sidebar-container" />
    
    <!-- 主内容区 -->
    <div class="main-container" :class="{ 'has-tags-view': tagsView }">
      <div :class="{ 'fixed-header': fixedHeader }">
        <!-- 头部导航 -->
        <Navbar />
        <!-- 标签页 -->
        <TagsView v-if="tagsView" />
      </div>
      
      <!-- 主要内容 -->
      <AppMain />
    </div>
    
    <!-- 设置面板 -->
    <Settings v-if="showSettings" />
  </div>
</template>

<script setup>
import { computed, watchEffect, onMounted, onBeforeUnmount } from 'vue'
import { useSettingsStore, useTagsViewStore } from '@/stores'
import Sidebar from './components/Sidebar/index.vue'
import Navbar from './components/Navbar.vue'
import TagsView from './components/TagsView/index.vue'
import AppMain from './components/AppMain.vue'
import Settings from './components/Settings/index.vue'

const settingsStore = useSettingsStore()

// 计算属性
const sidebar = computed(() => ({
  opened: !settingsStore.sidebarCollapsed
}))

const device = computed(() => settingsStore.device)
const fixedHeader = computed(() => settingsStore.fixedHeader)
const tagsView = computed(() => settingsStore.tagsView)
const showSettings = computed(() => settingsStore.showSettings)

const classObj = computed(() => ({
  hideSidebar: settingsStore.sidebarCollapsed,
  openSidebar: !settingsStore.sidebarCollapsed,
  withoutAnimation: false,
  mobile: device.value === 'mobile'
}))

// 点击遮罩关闭侧边栏
const handleClickOutside = () => {
  settingsStore.closeSidebar()
}

// 响应式处理
const { body } = document
const WIDTH = 992 // Bootstrap lg断点

const isMobile = () => {
  const rect = body.getBoundingClientRect()
  return rect.width - 1 < WIDTH
}

const resizeHandler = () => {
  if (!document.hidden) {
    const mobile = isMobile()
    settingsStore.setDevice(mobile ? 'mobile' : 'desktop')
    
    if (mobile) {
      settingsStore.closeSidebar()
    }
  }
}

onMounted(() => {
  const mobile = isMobile()
  if (mobile) {
    settingsStore.setDevice('mobile')
    settingsStore.closeSidebar()
  }
  
  window.addEventListener('resize', resizeHandler)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', resizeHandler)
})
</script>

<style lang="scss" scoped>
@import '@/styles/variables.scss';

.app-wrapper {
  position: relative;
  height: 100%;
  width: 100%;

  &::after {
    display: table;
    clear: both;
    content: '';
  }
}

.drawer-bg {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.3);
  z-index: 999;
}

.main-container {
  min-height: 100%;
  transition: margin-left $transition-duration;
  margin-left: $sidebar-width;
  position: relative;

  &.has-tags-view {
    .app-main {
      min-height: calc(100vh - #{$header-height} - #{$tags-view-height});
    }
  }
}

.fixed-header {
  position: fixed;
  top: 0;
  right: 0;
  z-index: 9;
  width: calc(100% - #{$sidebar-width});
  transition: width $transition-duration;
}

.hideSidebar {
  .main-container {
    margin-left: $sidebar-collapsed-width;
  }

  .fixed-header {
    width: calc(100% - #{$sidebar-collapsed-width});
  }
}

// 移动端
.mobile {
  .main-container {
    margin-left: 0;
  }

  .fixed-header {
    width: 100%;
  }

  &.openSidebar {
    position: fixed;
    top: 0;
  }
}

.withoutAnimation {
  .main-container,
  .sidebar-container {
    transition: none;
  }
}
</style>
