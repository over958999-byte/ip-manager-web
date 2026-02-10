<template>
  <div class="dashboard-container">
    <!-- 顶部统计卡片 -->
    <el-row :gutter="20" class="stat-cards">
      <el-col :span="6" v-for="card in statCards" :key="card.key">
        <el-card shadow="hover" :class="['stat-card', card.type]">
          <div class="stat-content">
            <div class="stat-icon">
              <el-icon :size="32"><component :is="card.icon" /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-value">{{ formatNumber(card.value) }}</div>
              <div class="stat-label">{{ card.label }}</div>
              <div class="stat-trend" v-if="card.trend !== undefined">
                <span :class="card.trend >= 0 ? 'up' : 'down'">
                  <el-icon><component :is="card.trend >= 0 ? 'ArrowUp' : 'ArrowDown'" /></el-icon>
                  {{ Math.abs(card.trend) }}%
                </span>
                <span class="trend-label">较昨日</span>
              </div>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 图表区域 -->
    <el-row :gutter="20" class="chart-row">
      <el-col :span="16">
        <el-card class="chart-card">
          <template #header>
            <div class="card-header">
              <span>访问趋势</span>
              <el-radio-group v-model="trendRange" size="small" @change="loadTrendData">
                <el-radio-button label="7d">7天</el-radio-button>
                <el-radio-button label="30d">30天</el-radio-button>
                <el-radio-button label="90d">90天</el-radio-button>
              </el-radio-group>
            </div>
          </template>
          <div ref="trendChartRef" class="chart-container"></div>
        </el-card>
      </el-col>
      <el-col :span="8">
        <el-card class="chart-card">
          <template #header>
            <span>设备分布</span>
          </template>
          <div ref="deviceChartRef" class="chart-container"></div>
        </el-card>
      </el-col>
    </el-row>

    <el-row :gutter="20" class="chart-row">
      <el-col :span="12">
        <el-card class="chart-card">
          <template #header>
            <span>地区分布 TOP10</span>
          </template>
          <div ref="regionChartRef" class="chart-container"></div>
        </el-card>
      </el-col>
      <el-col :span="12">
        <el-card class="chart-card">
          <template #header>
            <span>热门规则 TOP10</span>
          </template>
          <div ref="topRulesChartRef" class="chart-container"></div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 实时数据 -->
    <el-row :gutter="20" class="chart-row">
      <el-col :span="8">
        <el-card class="chart-card">
          <template #header>
            <div class="card-header">
              <span>系统状态</span>
              <el-tag :type="systemStatus.healthy ? 'success' : 'danger'" size="small">
                {{ systemStatus.healthy ? '正常' : '异常' }}
              </el-tag>
            </div>
          </template>
          <div class="system-status">
            <div class="status-item">
              <span class="status-label">CPU 使用率</span>
              <el-progress :percentage="systemStatus.cpu" :color="getProgressColor(systemStatus.cpu)" />
            </div>
            <div class="status-item">
              <span class="status-label">内存使用率</span>
              <el-progress :percentage="systemStatus.memory" :color="getProgressColor(systemStatus.memory)" />
            </div>
            <div class="status-item">
              <span class="status-label">磁盘使用率</span>
              <el-progress :percentage="systemStatus.disk" :color="getProgressColor(systemStatus.disk)" />
            </div>
            <div class="status-item">
              <span class="status-label">数据库连接</span>
              <span class="status-value">{{ systemStatus.dbConnections }}</span>
            </div>
            <div class="status-item">
              <span class="status-label">Redis 状态</span>
              <el-tag :type="systemStatus.redisOk ? 'success' : 'danger'" size="small">
                {{ systemStatus.redisOk ? '已连接' : '未连接' }}
              </el-tag>
            </div>
          </div>
        </el-card>
      </el-col>
      <el-col :span="16">
        <el-card class="chart-card">
          <template #header>
            <span>实时访问日志</span>
          </template>
          <el-table :data="realtimeLogs" height="280" size="small">
            <el-table-column prop="time" label="时间" width="150" />
            <el-table-column prop="ip" label="IP" width="130" />
            <el-table-column prop="rule" label="规则" />
            <el-table-column prop="device" label="设备" width="100">
              <template #default="{ row }">
                <el-tag size="small">{{ row.device }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="country" label="地区" width="100" />
          </el-table>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue'
import * as echarts from 'echarts'
import { 
  TrendCharts, 
  Monitor, 
  Link, 
  User, 
  ArrowUp, 
  ArrowDown 
} from '@element-plus/icons-vue'
import { getDashboardStats, getTrendData, getRealtimeLogs } from '../api'

// 统计数据
const stats = ref({
  todayClicks: 0,
  totalClicks: 0,
  activeRules: 0,
  activeDomains: 0,
  todayTrend: 0,
  weekTrend: 0
})

// 统计卡片
const statCards = computed(() => [
  {
    key: 'todayClicks',
    label: '今日访问',
    value: stats.value.todayClicks,
    icon: 'TrendCharts',
    type: 'primary',
    trend: stats.value.todayTrend
  },
  {
    key: 'totalClicks',
    label: '累计访问',
    value: stats.value.totalClicks,
    icon: 'Monitor',
    type: 'success'
  },
  {
    key: 'activeRules',
    label: '活跃规则',
    value: stats.value.activeRules,
    icon: 'Link',
    type: 'warning'
  },
  {
    key: 'activeDomains',
    label: '活跃域名',
    value: stats.value.activeDomains,
    icon: 'User',
    type: 'info'
  }
])

// 图表引用
const trendChartRef = ref(null)
const deviceChartRef = ref(null)
const regionChartRef = ref(null)
const topRulesChartRef = ref(null)

// 图表实例
let trendChart = null
let deviceChart = null
let regionChart = null
let topRulesChart = null

// 时间范围
const trendRange = ref('7d')

// 系统状态
const systemStatus = ref({
  healthy: true,
  cpu: 0,
  memory: 0,
  disk: 0,
  dbConnections: 0,
  redisOk: true
})

// 实时日志
const realtimeLogs = ref([])

// 定时器
let refreshTimer = null
let logsTimer = null

// 格式化数字
const formatNumber = (num) => {
  if (num >= 1000000) {
    return (num / 1000000).toFixed(1) + 'M'
  } else if (num >= 1000) {
    return (num / 1000).toFixed(1) + 'K'
  }
  return num.toString()
}

// 获取进度条颜色
const getProgressColor = (percentage) => {
  if (percentage < 60) return '#67c23a'
  if (percentage < 80) return '#e6a23c'
  return '#f56c6c'
}

// 初始化趋势图
const initTrendChart = () => {
  if (!trendChartRef.value) return
  trendChart = echarts.init(trendChartRef.value)
  
  const option = {
    tooltip: {
      trigger: 'axis',
      axisPointer: { type: 'cross' }
    },
    legend: {
      data: ['PV', 'UV']
    },
    grid: {
      left: '3%',
      right: '4%',
      bottom: '3%',
      containLabel: true
    },
    xAxis: {
      type: 'category',
      boundaryGap: false,
      data: []
    },
    yAxis: {
      type: 'value'
    },
    series: [
      {
        name: 'PV',
        type: 'line',
        smooth: true,
        areaStyle: { opacity: 0.3 },
        data: []
      },
      {
        name: 'UV',
        type: 'line',
        smooth: true,
        areaStyle: { opacity: 0.3 },
        data: []
      }
    ]
  }
  
  trendChart.setOption(option)
}

// 初始化设备分布图
const initDeviceChart = () => {
  if (!deviceChartRef.value) return
  deviceChart = echarts.init(deviceChartRef.value)
  
  const option = {
    tooltip: {
      trigger: 'item',
      formatter: '{b}: {c} ({d}%)'
    },
    legend: {
      orient: 'vertical',
      right: 10,
      top: 'center'
    },
    series: [
      {
        type: 'pie',
        radius: ['40%', '70%'],
        avoidLabelOverlap: false,
        itemStyle: {
          borderRadius: 10,
          borderColor: '#fff',
          borderWidth: 2
        },
        label: {
          show: false
        },
        emphasis: {
          label: {
            show: true,
            fontSize: 14,
            fontWeight: 'bold'
          }
        },
        data: []
      }
    ]
  }
  
  deviceChart.setOption(option)
}

// 初始化地区分布图
const initRegionChart = () => {
  if (!regionChartRef.value) return
  regionChart = echarts.init(regionChartRef.value)
  
  const option = {
    tooltip: {
      trigger: 'axis',
      axisPointer: { type: 'shadow' }
    },
    grid: {
      left: '3%',
      right: '4%',
      bottom: '3%',
      containLabel: true
    },
    xAxis: {
      type: 'value'
    },
    yAxis: {
      type: 'category',
      data: []
    },
    series: [
      {
        type: 'bar',
        data: [],
        itemStyle: {
          borderRadius: [0, 4, 4, 0],
          color: new echarts.graphic.LinearGradient(0, 0, 1, 0, [
            { offset: 0, color: '#409EFF' },
            { offset: 1, color: '#67C23A' }
          ])
        }
      }
    ]
  }
  
  regionChart.setOption(option)
}

// 初始化热门规则图
const initTopRulesChart = () => {
  if (!topRulesChartRef.value) return
  topRulesChart = echarts.init(topRulesChartRef.value)
  
  const option = {
    tooltip: {
      trigger: 'axis',
      axisPointer: { type: 'shadow' }
    },
    grid: {
      left: '3%',
      right: '4%',
      bottom: '3%',
      containLabel: true
    },
    xAxis: {
      type: 'value'
    },
    yAxis: {
      type: 'category',
      data: []
    },
    series: [
      {
        type: 'bar',
        data: [],
        itemStyle: {
          borderRadius: [0, 4, 4, 0],
          color: new echarts.graphic.LinearGradient(0, 0, 1, 0, [
            { offset: 0, color: '#E6A23C' },
            { offset: 1, color: '#F56C6C' }
          ])
        }
      }
    ]
  }
  
  topRulesChart.setOption(option)
}

// 加载统计数据
const loadStats = async () => {
  try {
    const res = await getDashboardStats()
    if (res.data) {
      stats.value = res.data
      
      // 更新设备分布图
      if (deviceChart && res.data.deviceStats) {
        deviceChart.setOption({
          series: [{
            data: res.data.deviceStats.map(item => ({
              name: item.name,
              value: item.value
            }))
          }]
        })
      }
      
      // 更新地区分布图
      if (regionChart && res.data.regionStats) {
        regionChart.setOption({
          yAxis: { data: res.data.regionStats.map(item => item.name).reverse() },
          series: [{ data: res.data.regionStats.map(item => item.value).reverse() }]
        })
      }
      
      // 更新热门规则图
      if (topRulesChart && res.data.topRules) {
        topRulesChart.setOption({
          yAxis: { data: res.data.topRules.map(item => item.name).reverse() },
          series: [{ data: res.data.topRules.map(item => item.value).reverse() }]
        })
      }
      
      // 更新系统状态
      if (res.data.systemStatus) {
        systemStatus.value = res.data.systemStatus
      }
    }
  } catch (error) {
    console.error('加载统计数据失败:', error)
  }
}

// 加载趋势数据
const loadTrendData = async () => {
  try {
    const res = await getTrendData(trendRange.value)
    if (res.data && trendChart) {
      trendChart.setOption({
        xAxis: { data: res.data.dates },
        series: [
          { data: res.data.pv },
          { data: res.data.uv }
        ]
      })
    }
  } catch (error) {
    console.error('加载趋势数据失败:', error)
  }
}

// 加载实时日志
const loadRealtimeLogs = async () => {
  try {
    const res = await getRealtimeLogs()
    if (res.data) {
      realtimeLogs.value = res.data
    }
  } catch (error) {
    console.error('加载实时日志失败:', error)
  }
}

// 窗口大小变化处理
const handleResize = () => {
  trendChart?.resize()
  deviceChart?.resize()
  regionChart?.resize()
  topRulesChart?.resize()
}

onMounted(() => {
  // 初始化图表
  initTrendChart()
  initDeviceChart()
  initRegionChart()
  initTopRulesChart()
  
  // 加载数据
  loadStats()
  loadTrendData()
  loadRealtimeLogs()
  
  // 定时刷新
  refreshTimer = setInterval(() => {
    loadStats()
  }, 60000) // 每分钟刷新统计
  
  logsTimer = setInterval(() => {
    loadRealtimeLogs()
  }, 5000) // 每5秒刷新日志
  
  // 监听窗口大小变化
  window.addEventListener('resize', handleResize)
})

onUnmounted(() => {
  // 清理定时器
  if (refreshTimer) clearInterval(refreshTimer)
  if (logsTimer) clearInterval(logsTimer)
  
  // 销毁图表
  trendChart?.dispose()
  deviceChart?.dispose()
  regionChart?.dispose()
  topRulesChart?.dispose()
  
  // 移除事件监听
  window.removeEventListener('resize', handleResize)
})
</script>

<style scoped>
.dashboard-container {
  padding: 20px;
}

.stat-cards {
  margin-bottom: 20px;
}

.stat-card {
  border-radius: 8px;
}

.stat-card.primary {
  border-left: 4px solid #409EFF;
}

.stat-card.success {
  border-left: 4px solid #67C23A;
}

.stat-card.warning {
  border-left: 4px solid #E6A23C;
}

.stat-card.info {
  border-left: 4px solid #909399;
}

.stat-content {
  display: flex;
  align-items: center;
}

.stat-icon {
  width: 60px;
  height: 60px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  margin-right: 15px;
}

.stat-info {
  flex: 1;
}

.stat-value {
  font-size: 28px;
  font-weight: bold;
  color: #303133;
}

.stat-label {
  font-size: 14px;
  color: #909399;
  margin-top: 4px;
}

.stat-trend {
  font-size: 12px;
  margin-top: 4px;
}

.stat-trend .up {
  color: #67C23A;
}

.stat-trend .down {
  color: #F56C6C;
}

.trend-label {
  color: #909399;
  margin-left: 4px;
}

.chart-row {
  margin-bottom: 20px;
}

.chart-card {
  border-radius: 8px;
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.chart-container {
  height: 300px;
}

.system-status {
  padding: 10px 0;
}

.status-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 15px;
}

.status-item:last-child {
  margin-bottom: 0;
}

.status-label {
  font-size: 14px;
  color: #606266;
  width: 100px;
}

.status-item :deep(.el-progress) {
  flex: 1;
  margin-left: 10px;
}

.status-value {
  font-size: 14px;
  font-weight: bold;
  color: #409EFF;
}
</style>
