/**
 * 系统配置
 */
export default {
  // 网站标题
  title: '困King分发平台',
  
  // 固定Header
  fixedHeader: true,
  
  // 显示侧边栏Logo
  sidebarLogo: true,
  
  // 显示面包屑
  showBreadcrumb: true,
  
  // 显示标签页
  tagsView: true,
  
  // 显示设置按钮
  showSettings: true,
  
  // 错误日志收集环境
  errorLog: ['production', 'development'],
  
  // 侧边栏主题 dark / light
  sidebarTheme: 'dark',
  
  // 显示全屏按钮
  showScreenfull: true,
  
  // 显示搜索
  showSearch: true,
  
  // 支持拼音搜索
  supportPinyinSearch: true,
  
  // Token 存储key
  tokenKey: 'ip_manager_token',
  
  // Token 前缀
  tokenPrefix: 'Bearer ',
  
  // 请求超时
  requestTimeout: 30000,
  
  // 布局配置
  layout: {
    // 侧边栏宽度
    sidebarWidth: '220px',
    // 侧边栏折叠宽度
    sidebarCollapsedWidth: '64px',
    // 头部高度
    headerHeight: '50px',
    // 标签页高度
    tagsViewHeight: '34px'
  }
}
