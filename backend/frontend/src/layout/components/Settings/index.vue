<template>
  <el-drawer
    v-model="visible"
    title="系统设置"
    direction="rtl"
    size="260px"
  >
    <div class="drawer-item">
      <span>主题色</span>
      <el-color-picker v-model="theme" :predefine="predefineColors" @change="handleThemeChange" />
    </div>
    
    <el-divider />
    
    <div class="drawer-item">
      <span>固定Header</span>
      <el-switch v-model="fixedHeader" />
    </div>
    
    <div class="drawer-item">
      <span>显示Logo</span>
      <el-switch v-model="sidebarLogo" />
    </div>
    
    <div class="drawer-item">
      <span>显示标签页</span>
      <el-switch v-model="tagsView" />
    </div>
    
    <el-divider />
    
    <el-alert
      title="配置仅用于预览，不会被保存到服务器"
      type="warning"
      :closable="false"
    />
  </el-drawer>
</template>

<script setup>
import { computed } from 'vue'
import { useSettingsStore } from '@/stores'

const settingsStore = useSettingsStore()

const predefineColors = [
  '#409eff',
  '#304156',
  '#11a983',
  '#13c2c2',
  '#6959cd',
  '#f5222d'
]

const visible = computed({
  get: () => settingsStore.showSettingsPanel,
  set: (val) => settingsStore.changeSetting({ key: 'showSettingsPanel', value: val })
})

const theme = computed({
  get: () => settingsStore.theme,
  set: (val) => settingsStore.setTheme(val)
})

const fixedHeader = computed({
  get: () => settingsStore.fixedHeader,
  set: (val) => settingsStore.changeSetting({ key: 'fixedHeader', value: val })
})

const sidebarLogo = computed({
  get: () => settingsStore.sidebarLogo,
  set: (val) => settingsStore.changeSetting({ key: 'sidebarLogo', value: val })
})

const tagsView = computed({
  get: () => settingsStore.tagsView,
  set: (val) => settingsStore.changeSetting({ key: 'tagsView', value: val })
})

const handleThemeChange = (val) => {
  settingsStore.setTheme(val)
}
</script>

<style lang="scss" scoped>
.drawer-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 0;
  font-size: 14px;
  color: #606266;
}

.el-divider {
  margin: 12px 0;
}
</style>
