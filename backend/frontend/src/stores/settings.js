import { defineStore } from 'pinia'

export const useSettingsStore = defineStore('settings', {
  state: () => ({
    // 主题
    theme: '#409eff',
    
    // 固定Header
    fixedHeader: true,
    
    // 显示侧边栏Logo
    sidebarLogo: true,
    
    // 显示标签页
    tagsView: true,
    
    // 显示设置按钮
    showSettings: true,
    
    // 侧边栏主题 dark / light
    sidebarTheme: 'dark',
    
    // 是否折叠侧边栏
    sidebarCollapsed: false,
    
    // 设备类型
    device: 'desktop',
    
    // 显示右侧设置面板
    showSettingsPanel: false
  }),
  
  getters: {
    isMobile: (state) => state.device === 'mobile'
  },
  
  actions: {
    // 切换设置
    changeSetting(payload) {
      const { key, value } = payload
      if (Object.prototype.hasOwnProperty.call(this, key)) {
        this[key] = value
      }
    },
    
    // 切换侧边栏
    toggleSidebar() {
      this.sidebarCollapsed = !this.sidebarCollapsed
    },
    
    // 关闭侧边栏
    closeSidebar() {
      this.sidebarCollapsed = true
    },
    
    // 打开侧边栏
    openSidebar() {
      this.sidebarCollapsed = false
    },
    
    // 设置设备类型
    setDevice(device) {
      this.device = device
    },
    
    // 切换设置面板
    toggleSettingsPanel() {
      this.showSettingsPanel = !this.showSettingsPanel
    },
    
    // 设置主题色
    setTheme(theme) {
      this.theme = theme
      // 更新CSS变量
      document.documentElement.style.setProperty('--el-color-primary', theme)
    }
  },
  
  persist: {
    key: 'ip_manager_settings',
    storage: localStorage,
    paths: ['theme', 'fixedHeader', 'sidebarLogo', 'tagsView', 'showSettings', 'sidebarTheme', 'sidebarCollapsed']
  }
})

export default useSettingsStore
