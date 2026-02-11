<template>
  <div :class="{ 'has-logo': showLogo }" class="sidebar-container">
    <!-- Logo -->
    <Logo v-if="showLogo" :collapse="isCollapse" />
    
    <!-- 菜单 -->
    <el-scrollbar wrap-class="scrollbar-wrapper">
      <el-menu
        :default-active="activeMenu"
        :collapse="isCollapse"
        :unique-opened="true"
        :collapse-transition="false"
        mode="vertical"
        :background-color="variables.sidebarBgColor"
        :text-color="variables.sidebarTextColor"
        :active-text-color="variables.sidebarActiveTextColor"
      >
        <SidebarItem
          v-for="route in routes"
          :key="route.path"
          :item="route"
          :base-path="route.path"
        />
      </el-menu>
    </el-scrollbar>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useSettingsStore, usePermissionStore } from '@/stores'
import Logo from './Logo.vue'
import SidebarItem from './SidebarItem.vue'

// 变量
const variables = {
  sidebarBgColor: '#304156',
  sidebarTextColor: '#bfcbd9',
  sidebarActiveTextColor: '#409eff'
}

const route = useRoute()
const settingsStore = useSettingsStore()
const permissionStore = usePermissionStore()

const routes = computed(() => permissionStore.sidebarRoutes)
const showLogo = computed(() => settingsStore.sidebarLogo)
const isCollapse = computed(() => settingsStore.sidebarCollapsed)

const activeMenu = computed(() => {
  const { meta, path } = route
  // 如果设置了activeMenu则使用
  if (meta.activeMenu) {
    return meta.activeMenu
  }
  return path
})
</script>
