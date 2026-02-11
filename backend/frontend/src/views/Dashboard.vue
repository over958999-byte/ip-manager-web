<template>
  <div class="dashboard">
    <h2 style="margin-bottom: 20px;">仪表盘</h2>
    
    <!-- 统计卡片 -->
    <el-row :gutter="16" style="margin-bottom: 20px;">
      <el-col :span="4">
        <el-card class="stat-card" shadow="hover">
          <div class="stat-value" style="color: #409eff;">{{ stats.totalIps }}</div>
          <div class="stat-label">IP跳转数</div>
        </el-card>
      </el-col>
      <el-col :span="4">
        <el-card class="stat-card" shadow="hover">
          <div class="stat-value" style="color: #67c23a;">{{ stats.totalShortLinks }}</div>
          <div class="stat-label">短链数</div>
        </el-card>
      </el-col>
      <el-col :span="4">
        <el-card class="stat-card" shadow="hover">
          <div class="stat-value" style="color: #e6a23c;">{{ stats.totalClicks }}</div>
          <div class="stat-label">总点击量</div>
        </el-card>
      </el-col>
      <el-col :span="4">
        <el-card class="stat-card" shadow="hover">
          <div class="stat-value" style="color: #909399;">{{ stats.todayClicks }}</div>
          <div class="stat-label">今日点击</div>
        </el-card>
      </el-col>
      <el-col :span="4">
        <el-card class="stat-card" shadow="hover">
          <div class="stat-value" style="color: #f56c6c;">{{ stats.totalBlocked }}</div>
          <div class="stat-label">总拦截数</div>
        </el-card>
      </el-col>
      <el-col :span="4">
        <el-card class="stat-card" shadow="hover">
          <div class="stat-value" style="color: #e6a23c;">{{ stats.currentBlocked }}</div>
          <div class="stat-label">当前封禁</div>
        </el-card>
      </el-col>
    </el-row>

    <el-row :gutter="20">
      <!-- 跳转规则概览 -->
      <el-col :span="14">
        <el-card>
          <template #header>
            <div style="display: flex; justify-content: space-between; align-items: center;">
              <span>跳转规则概览</span>
              <el-button type="primary" size="small" @click="$router.push('/jump')">
                管理全部
              </el-button>
            </div>
          </template>
          <el-tabs v-model="activeTab">
            <el-tab-pane label="短链" name="shortlink">
              <el-table :data="recentShortLinks" style="width: 100%" v-loading="loading" max-height="280">
                <el-table-column prop="match_key" label="短码" width="100">
                  <template #default="{ row }">
                    <el-link type="primary" :href="row.jump_url" target="_blank">{{ row.match_key }}</el-link>
                  </template>
                </el-table-column>
                <el-table-column prop="target_url" label="目标URL" show-overflow-tooltip />
                <el-table-column label="状态" width="70">
                  <template #default="{ row }">
                    <el-tag :type="row.enabled ? 'success' : 'info'" size="small">
                      {{ row.enabled ? '启用' : '禁用' }}
                    </el-tag>
                  </template>
                </el-table-column>
                <el-table-column prop="click_count" label="点击" width="70" />
              </el-table>
              <el-empty v-if="recentShortLinks.length === 0" description="暂无短链" :image-size="60" />
            </el-tab-pane>
            <el-tab-pane label="IP跳转" name="ip">
              <el-table :data="recentIpRules" style="width: 100%" v-loading="loading" max-height="280">
                <el-table-column prop="match_key" label="IP地址" width="140" />
                <el-table-column prop="target_url" label="跳转URL" show-overflow-tooltip />
                <el-table-column label="状态" width="70">
                  <template #default="{ row }">
                    <el-tag :type="row.enabled ? 'success' : 'info'" size="small">
                      {{ row.enabled ? '启用' : '禁用' }}
                    </el-tag>
                  </template>
                </el-table-column>
                <el-table-column prop="click_count" label="点击" width="70" />
              </el-table>
              <el-empty v-if="recentIpRules.length === 0" description="暂无IP跳转" :image-size="60" />
            </el-tab-pane>
          </el-tabs>
        </el-card>
      </el-col>

      <!-- 拦截统计 -->
      <el-col :span="10">
        <el-card>
          <template #header>
            <div style="display: flex; justify-content: space-between; align-items: center;">
              <span>拦截统计</span>
              <el-button type="primary" size="small" @click="$router.push('/antibot')">
                详细管理
              </el-button>
            </div>
          </template>
          <div v-if="Object.keys(blockStats).length > 0">
            <div v-for="(count, reason) in blockStats" :key="reason" class="block-stat-item">
              <span class="reason-label">{{ reasonLabels[reason] || reason }}</span>
              <el-progress 
                :percentage="getPercentage(count)" 
                :stroke-width="16"
                :format="() => count"
              />
            </div>
          </div>
          <el-empty v-else description="暂无拦截记录" :image-size="80" />
        </el-card>

        <!-- 最近拦截日志 -->
        <el-card style="margin-top: 16px;">
          <template #header>最近拦截</template>
          <el-table :data="recentLogs" size="small" max-height="200">
            <el-table-column prop="time" label="时间" width="90">
              <template #default="{ row }">
                {{ formatTime(row.time) }}
              </template>
            </el-table-column>
            <el-table-column prop="ip" label="IP" width="120" />
            <el-table-column label="原因">
              <template #default="{ row }">
                <el-tag size="small" type="danger">
                  {{ reasonLabels[row.reason] || row.reason }}
                </el-tag>
              </template>
            </el-table-column>
          </el-table>
          <el-empty v-if="recentLogs.length === 0" description="暂无拦截" :image-size="60" />
        </el-card>
      </el-col>
    </el-row>
    
    <!-- 系统监控卡片 -->
    <el-row :gutter="20" style="margin-top: 20px;">
      <el-col :span="8">
        <el-card>
          <template #header>
            <div style="display: flex; justify-content: space-between; align-items: center;">
              <span>🗄️ 缓存状态</span>
              <el-tag :type="cacheStatus.enabled ? 'success' : 'info'" size="small">
                {{ cacheStatus.type || '未启用' }}
              </el-tag>
            </div>
          </template>
          <el-descriptions :column="1" border size="small">
            <el-descriptions-item label="缓存类型">{{ cacheStatus.type || '-' }}</el-descriptions-item>
            <el-descriptions-item label="命中率">
              <el-progress 
                :percentage="cacheStatus.hit_rate || 0" 
                :color="cacheStatus.hit_rate > 80 ? '#67c23a' : '#e6a23c'"
                :stroke-width="10"
              />
            </el-descriptions-item>
            <el-descriptions-item label="内存使用" v-if="cacheStatus.memory_used">
              {{ formatBytes(cacheStatus.memory_used) }} / {{ formatBytes(cacheStatus.memory_total) }}
            </el-descriptions-item>
            <el-descriptions-item label="Key 数量">{{ cacheStatus.keys || 0 }}</el-descriptions-item>
          </el-descriptions>
        </el-card>
      </el-col>
      
      <el-col :span="8">
        <el-card>
          <template #header>
            <div style="display: flex; justify-content: space-between; align-items: center;">
              <span>🗃️ 数据库状态</span>
              <el-tag :type="dbStatus.connected ? 'success' : 'danger'" size="small">
                {{ dbStatus.connected ? '正常' : '异常' }}
              </el-tag>
            </div>
          </template>
          <el-descriptions :column="1" border size="small">
            <el-descriptions-item label="连接池">{{ dbStatus.connections || 0 }} 个连接</el-descriptions-item>
            <el-descriptions-item label="查询数/分钟">{{ dbStatus.queries_per_min || 0 }}</el-descriptions-item>
            <el-descriptions-item label="慢查询">{{ dbStatus.slow_queries || 0 }}</el-descriptions-item>
            <el-descriptions-item label="数据库大小">{{ formatBytes(dbStatus.size || 0) }}</el-descriptions-item>
          </el-descriptions>
        </el-card>
      </el-col>
      
      <el-col :span="8">
        <el-card>
          <template #header>
            <div style="display: flex; justify-content: space-between; align-items: center;">
              <span>⚡ 系统性能</span>
              <el-button type="primary" link size="small" @click="refreshMetrics">
                <el-icon><Refresh /></el-icon> 刷新
              </el-button>
            </div>
          </template>
          <el-descriptions :column="1" border size="small">
            <el-descriptions-item label="平均响应时间">{{ systemMetrics.avg_response_time || 0 }}ms</el-descriptions-item>
            <el-descriptions-item label="请求数/分钟">{{ systemMetrics.requests_per_min || 0 }}</el-descriptions-item>
            <el-descriptions-item label="错误率">
              <el-tag :type="(systemMetrics.error_rate || 0) < 1 ? 'success' : 'danger'" size="small">
                {{ (systemMetrics.error_rate || 0).toFixed(2) }}%
              </el-tag>
            </el-descriptions-item>
            <el-descriptions-item label="服务运行时间">{{ systemMetrics.uptime || '-' }}</el-descriptions-item>
          </el-descriptions>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { Refresh } from '@element-plus/icons-vue'
import * as api from '../api'

const loading = ref(false)
const activeTab = ref('shortlink')
const stats = reactive({
  totalIps: 0,
  totalShortLinks: 0,
  totalClicks: 0,
  todayClicks: 0,
  totalBlocked: 0,
  currentBlocked: 0
})
const recentShortLinks = ref([])
const recentIpRules = ref([])
const blockStats = ref({})
const recentLogs = ref([])

// 系统监控数据
const cacheStatus = ref({
  enabled: false,
  type: null,
  hit_rate: 0,
  memory_used: 0,
  memory_total: 0,
  keys: 0
})

const dbStatus = ref({
  connected: false,
  connections: 0,
  queries_per_min: 0,
  slow_queries: 0,
  size: 0
})

const systemMetrics = ref({
  avg_response_time: 0,
  requests_per_min: 0,
  error_rate: 0,
  uptime: '-'
})

const reasonLabels = {
  'rate_limit': '频率限制',
  'ua_check': 'UA检测',
  'empty_ua': '空UA',
  'short_ua': 'UA过短',
  'invalid_ua': '无效UA',
  'known_bot': '已知爬虫',
  'header_check': '请求头检测',
  'missing_headers': '缺少请求头',
  'honeypot': '蜜罐陷阱',
  'behavior': '行为异常',
  'blacklisted': '黑名单',
  'bad_ip_database': '恶意IP库',
  'device_block': '设备限制',
  'country_block': '地区限制'
}

const getPercentage = (count) => {
  const total = stats.totalBlocked || 1
  return Math.min(Math.round((count / total) * 100), 100)
}

const formatTime = (timeStr) => {
  if (!timeStr) return ''
  // 只显示时间部分
  return timeStr.split(' ')[1] || timeStr
}

const formatBytes = (bytes) => {
  if (!bytes || bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}

const loadData = async () => {
  loading.value = true
  try {
    // 获取跳转规则统计
    const jumpStatsRes = await api.getJumpDashboardStats()
    if (jumpStatsRes.success) {
      const data = jumpStatsRes.data || {}
      stats.totalIps = data.ip_rules || 0
      stats.totalShortLinks = data.code_rules || 0
      stats.totalClicks = data.total_clicks || 0
      stats.todayClicks = data.today_clicks || 0
    }
    
    // 获取跳转规则列表
    const jumpRes = await api.getJumpRules({ page: 1, page_size: 10 })
    if (jumpRes.success) {
      // 确保 rules 是数组
      let rules = jumpRes.data?.items || jumpRes.data || []
      if (!Array.isArray(rules)) {
        rules = []
      }
      recentShortLinks.value = rules.filter(r => r.rule_type === 'code').slice(0, 6)
      recentIpRules.value = rules.filter(r => r.rule_type === 'ip').slice(0, 6)
    }

    // 获取反爬虫统计
    const antibotRes = await api.getAntibotStats()
    if (antibotRes.success && antibotRes.data) {
      stats.totalBlocked = antibotRes.data.stats?.total_blocked || 0
      stats.currentBlocked = antibotRes.data.stats?.currently_blocked || 0
      blockStats.value = antibotRes.data.stats?.by_reason || {}
      recentLogs.value = (antibotRes.data.stats?.recent_logs || []).slice(0, 5)
    }
    
    // 加载系统监控数据
    await loadSystemMetrics()
  } finally {
    loading.value = false
  }
}

const loadSystemMetrics = async () => {
  try {
    // 获取系统健康状态
    const healthRes = await api.getSystemHealth()
    if (healthRes.success && healthRes.data) {
      const data = healthRes.data
      
      // 缓存状态
      if (data.cache) {
        cacheStatus.value = {
          enabled: data.cache.enabled || false,
          type: data.cache.type || null,
          hit_rate: data.cache.hit_rate || 0,
          memory_used: data.cache.memory_used || 0,
          memory_total: data.cache.memory_total || 0,
          keys: data.cache.keys || 0
        }
      }
      
      // 数据库状态
      if (data.database) {
        dbStatus.value = {
          connected: data.database.connected || false,
          connections: data.database.connections || 0,
          queries_per_min: data.database.queries_per_min || 0,
          slow_queries: data.database.slow_queries || 0,
          size: data.database.size || 0
        }
      }
      
      // 系统性能
      if (data.metrics) {
        systemMetrics.value = {
          avg_response_time: data.metrics.avg_response_time || 0,
          requests_per_min: data.metrics.requests_per_min || 0,
          error_rate: data.metrics.error_rate || 0,
          uptime: data.metrics.uptime || data.uptime || '-'
        }
      } else {
        systemMetrics.value.uptime = data.uptime || '-'
      }
    }
  } catch (e) {
    console.warn('Failed to load system metrics:', e)
  }
}

const refreshMetrics = () => {
  loadSystemMetrics()
}

onMounted(() => {
  loadData()
})
</script>

<style scoped>
.stat-card {
  text-align: center;
}

.stat-value {
  font-size: 28px;
  font-weight: bold;
}

.stat-label {
  color: #909399;
  margin-top: 8px;
  font-size: 14px;
}

.block-stat-item {
  margin-bottom: 12px;
}

.reason-label {
  display: block;
  margin-bottom: 4px;
  font-size: 13px;
  color: #606266;
}
</style>
