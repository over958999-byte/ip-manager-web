<template>
  <div class="antibot-page">
    <div class="page-header">
      <h2>反爬虫管理</h2>
      <el-button @click="loadData">
        <el-icon><Refresh /></el-icon> 刷新
      </el-button>
    </div>

    <!-- 统计卡片 -->
    <el-row :gutter="20" style="margin-bottom: 20px;">
      <el-col :span="6">
        <el-card class="stat-card" shadow="hover">
          <div class="stat-value" style="color: #f56c6c;">{{ stats.total_blocked || 0 }}</div>
          <div class="stat-label">总拦截数</div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card class="stat-card" shadow="hover">
          <div class="stat-value" style="color: #e6a23c;">{{ stats.currently_blocked || 0 }}</div>
          <div class="stat-label">当前封禁IP</div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card class="stat-card" shadow="hover">
          <div class="stat-value" style="color: #909399;">{{ stats.blacklist_count || 0 }}</div>
          <div class="stat-label">永久黑名单</div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card class="stat-card" shadow="hover">
          <div class="stat-value" style="color: #409eff;">{{ badIpStats.total || 0 }}</div>
          <div class="stat-label">恶意IP库</div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 按原因统计 -->
    <el-card style="margin-bottom: 20px;" v-if="Object.keys(stats.by_reason || {}).length > 0">
      <template #header>拦截原因统计</template>
      <el-row :gutter="10">
        <el-col :span="4" v-for="(count, reason) in stats.by_reason" :key="reason">
          <el-statistic :title="reasonLabels[reason] || reason" :value="count" />
        </el-col>
      </el-row>
    </el-card>

    <el-row :gutter="20">
      <!-- 左侧：封禁列表和日志 -->
      <el-col :span="14">
        <!-- 当前封禁IP -->
        <el-card>
          <template #header>
            <div style="display: flex; justify-content: space-between; align-items: center;">
              <span>临时封禁IP ({{ blockedList.length }})</span>
              <el-popconfirm title="确定清空所有封禁吗？" @confirm="clearAllBlocks">
                <template #reference>
                  <el-button type="danger" size="small" :disabled="blockedList.length === 0">清空全部</el-button>
                </template>
              </el-popconfirm>
            </div>
          </template>
          <el-table :data="blockedList" v-loading="loading" max-height="250" size="small">
            <el-table-column prop="ip" label="IP地址" width="140" />
            <el-table-column prop="since" label="封禁时间" width="160" />
            <el-table-column label="剩余时间" width="100">
              <template #default="{ row }">
                {{ formatRemaining(row.remaining) }}
              </template>
            </el-table-column>
            <el-table-column label="操作" width="80">
              <template #default="{ row }">
                <el-button link type="primary" @click="unblockIp(row.ip)">解封</el-button>
              </template>
            </el-table-column>
          </el-table>
          <el-empty v-if="blockedList.length === 0" description="暂无封禁IP" :image-size="60" />
        </el-card>

        <!-- 拦截日志 -->
        <el-card style="margin-top: 16px;">
          <template #header>
            <div style="display: flex; justify-content: space-between; align-items: center;">
              <span>拦截日志 (最近100条)</span>
              <el-popconfirm title="确定重置统计数据吗？" @confirm="resetStats">
                <template #reference>
                  <el-button type="warning" size="small">重置统计</el-button>
                </template>
              </el-popconfirm>
            </div>
          </template>
          <el-table :data="paginatedLogs" max-height="350" size="small">
            <el-table-column prop="time" label="时间" width="160" />
            <el-table-column prop="ip" label="访客IP" width="130" />
            <el-table-column prop="target_ip" label="目标IP" width="130" />
            <el-table-column label="原因" width="120">
              <template #default="{ row }">
                <el-tag :type="getReasonTagType(row.reason)" size="small">
                  {{ reasonLabels[row.reason] || row.reason }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="detail" label="详情" show-overflow-tooltip />
          </el-table>
          <el-empty v-if="logs.length === 0" description="暂无拦截记录" :image-size="60" />
          <div class="pagination-wrapper" v-if="logs.length > 0">
            <el-pagination
              v-model:current-page="logPage"
              :page-size="logPageSize"
              :total="logs.length"
              layout="total, prev, pager, next"
              small
            />
          </div>
        </el-card>

        <!-- 黑白名单 -->
        <el-row :gutter="20" style="margin-top: 16px;">
          <el-col :span="12">
            <el-card>
              <template #header>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                  <span>IP黑名单 ({{ (config.ip_blacklist || []).length }})</span>
                  <el-button type="primary" size="small" @click="showAddBlacklistDialog">添加</el-button>
                </div>
              </template>
              <div class="tag-container">
                <el-tag 
                  v-for="ip in (config.ip_blacklist || [])" 
                  :key="ip"
                  closable
                  @close="removeBlacklist(ip)"
                  style="margin: 4px;"
                >
                  {{ ip }}
                </el-tag>
              </div>
              <el-empty v-if="!(config.ip_blacklist || []).length" description="暂无黑名单IP" :image-size="50" />
            </el-card>
          </el-col>
          <el-col :span="12">
            <el-card>
              <template #header>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                  <span>IP白名单 ({{ (config.ip_whitelist || []).length }})</span>
                  <el-button type="primary" size="small" @click="showAddWhitelistDialog">添加</el-button>
                </div>
              </template>
              <div class="tag-container">
                <el-tag 
                  v-for="ip in (config.ip_whitelist || [])" 
                  :key="ip"
                  type="success"
                  closable
                  @close="removeWhitelist(ip)"
                  style="margin: 4px;"
                >
                  {{ ip }}
                </el-tag>
              </div>
              <el-empty v-if="!(config.ip_whitelist || []).length" description="暂无白名单IP" :image-size="50" />
            </el-card>
          </el-col>
        </el-row>
      </el-col>

      <!-- 右侧：配置面板 -->
      <el-col :span="10">
        <el-card>
          <template #header>反爬虫配置</template>
          <el-form :model="config" label-width="140px" size="small">
            <el-form-item label="启用反爬虫">
              <el-switch v-model="config.enabled" @change="saveConfig" />
            </el-form-item>
            
            <el-divider content-position="left">频率限制</el-divider>
            <el-form-item label="启用">
              <el-switch v-model="configRateLimit.enabled" @change="saveConfig" />
            </el-form-item>
            <el-form-item label="最大请求数">
              <el-input-number 
                v-model="configRateLimit.max_requests" 
                :min="1" 
                :max="1000"
                @change="saveConfig"
              />
              <span style="margin-left: 8px; color: #909399;">次</span>
            </el-form-item>
            <el-form-item label="时间窗口">
              <el-input-number 
                v-model="configRateLimit.time_window" 
                :min="1" 
                :max="3600"
                @change="saveConfig"
              />
              <span style="margin-left: 8px; color: #909399;">秒</span>
            </el-form-item>
            <el-form-item label="封禁时长">
              <el-input-number 
                v-model="configRateLimit.block_duration" 
                :min="60" 
                :max="86400"
                :step="60"
                @change="saveConfig"
              />
              <span style="margin-left: 8px; color: #909399;">秒</span>
            </el-form-item>

            <el-divider content-position="left">UA检测</el-divider>
            <el-form-item label="启用">
              <el-switch v-model="configUaCheck.enabled" @change="saveConfig" />
            </el-form-item>
            <el-form-item label="拦截空UA">
              <el-switch v-model="configUaCheck.block_empty_ua" @change="saveConfig" />
            </el-form-item>
            <el-form-item label="拦截已知爬虫">
              <el-switch v-model="configUaCheck.block_known_bots" @change="saveConfig" />
            </el-form-item>

            <el-divider content-position="left">高级检测</el-divider>
            <el-form-item label="请求头检测">
              <el-switch v-model="configHeaderCheck.enabled" @change="saveConfig" />
            </el-form-item>
            <el-form-item label="蜜罐陷阱">
              <el-switch v-model="configHoneypot.enabled" @change="saveConfig" />
            </el-form-item>
            <el-form-item label="行为分析">
              <el-switch v-model="configBehaviorCheck.enabled" @change="saveConfig" />
            </el-form-item>
            <el-form-item label="恶意IP库">
              <el-switch v-model="configBadIpDatabase.enabled" @change="saveConfig" />
            </el-form-item>

            <el-divider content-position="left">自动黑名单</el-divider>
            <el-form-item label="启用">
              <el-switch v-model="configAutoBlacklist.enabled" @change="saveConfig" />
            </el-form-item>
            <el-form-item label="触发阈值">
              <el-input-number 
                v-model="configAutoBlacklist.max_blocks" 
                :min="1" 
                :max="100"
                @change="saveConfig"
              />
              <span style="margin-left: 8px; color: #909399;">次拦截后拉黑</span>
            </el-form-item>
          </el-form>
        </el-card>

        <!-- 恶意IP库信息 -->
        <el-card style="margin-top: 16px;">
          <template #header>恶意IP库统计</template>
          <el-descriptions :column="1" size="small">
            <el-descriptions-item label="总IP段数">{{ badIpStats.total || 0 }}</el-descriptions-item>
            <el-descriptions-item label="恶意IP段">{{ badIpStats.malicious || 0 }}</el-descriptions-item>
            <el-descriptions-item label="数据中心IP">{{ badIpStats.datacenter || 0 }}</el-descriptions-item>
          </el-descriptions>
        </el-card>
      </el-col>
    </el-row>

    <!-- 添加黑名单对话框 -->
    <el-dialog v-model="blacklistDialogVisible" title="添加黑名单IP" width="400px">
      <el-input v-model="newBlacklistIp" placeholder="输入IP地址" />
      <template #footer>
        <el-button @click="blacklistDialogVisible = false">取消</el-button>
        <el-button type="primary" @click="addBlacklist">添加</el-button>
      </template>
    </el-dialog>

    <!-- 添加白名单对话框 -->
    <el-dialog v-model="whitelistDialogVisible" title="添加白名单IP" width="400px">
      <el-input v-model="newWhitelistIp" placeholder="输入IP地址" />
      <template #footer>
        <el-button @click="whitelistDialogVisible = false">取消</el-button>
        <el-button type="primary" @click="addWhitelist">添加</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../api'

const loading = ref(false)
const stats = ref({})
const blockedList = ref([])
const logs = ref([])
const config = reactive({
  enabled: true,
  rate_limit: {},
  ua_check: {},
  header_check: {},
  honeypot: {},
  behavior_check: {},
  bad_ip_database: {},
  auto_blacklist: {},
  ip_blacklist: [],
  ip_whitelist: []
})
const badIpStats = ref({})

// 分页
const logPage = ref(1)
const logPageSize = 20

const paginatedLogs = computed(() => {
  const start = (logPage.value - 1) * logPageSize
  return logs.value.slice(start, start + logPageSize)
})

// 配置子项的计算属性
const configRateLimit = computed(() => config.rate_limit || {})
const configUaCheck = computed(() => config.ua_check || {})
const configHeaderCheck = computed(() => config.header_check || {})
const configHoneypot = computed(() => config.honeypot || {})
const configBehaviorCheck = computed(() => config.behavior_check || {})
const configBadIpDatabase = computed(() => config.bad_ip_database || {})
const configAutoBlacklist = computed(() => config.auto_blacklist || {})

const blacklistDialogVisible = ref(false)
const whitelistDialogVisible = ref(false)
const newBlacklistIp = ref('')
const newWhitelistIp = ref('')

// 原因标签
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
  'suspicious_behavior': '可疑行为',
  'blacklisted': '黑名单',
  'blacklist_add': '加入黑名单',
  'bad_ip_database': '恶意IP库',
  'datacenter_ip': '数据中心IP',
  'known_bot_ip': '爬虫IP',
  'rate_blocked': '频率封禁',
  'device_block': '设备限制',
  'country_block': '地区限制'
}

const getReasonTagType = (reason) => {
  const types = {
    'rate_limit': 'danger',
    'ua_check': 'warning',
    'honeypot': 'danger',
    'blacklisted': 'danger',
    'bad_ip_database': 'danger',
    'device_block': 'info',
    'country_block': 'info'
  }
  return types[reason] || 'warning'
}

const formatRemaining = (seconds) => {
  if (seconds < 60) return `${seconds}秒`
  if (seconds < 3600) return `${Math.floor(seconds / 60)}分钟`
  return `${Math.floor(seconds / 3600)}小时`
}

const loadData = async () => {
  loading.value = true
  try {
    const res = await api.getAntibotStats()
    if (res.success) {
      stats.value = res.stats || {}
      blockedList.value = res.blocked_list || []
      logs.value = res.stats?.recent_logs || []
      badIpStats.value = res.bad_ip_stats || {}
      
      // 合并配置
      const serverConfig = res.config || {}
      Object.assign(config, serverConfig)
      config.rate_limit = serverConfig.rate_limit || {}
      config.ua_check = serverConfig.ua_check || {}
      config.header_check = serverConfig.header_check || {}
      config.honeypot = serverConfig.honeypot || {}
      config.behavior_check = serverConfig.behavior_check || {}
      config.bad_ip_database = serverConfig.bad_ip_database || {}
      config.auto_blacklist = serverConfig.auto_blacklist || {}
    }
  } finally {
    loading.value = false
  }
}

const saveConfig = async () => {
  const res = await api.updateAntibotConfig(config)
  if (res.success) {
    ElMessage.success('配置已保存')
  } else {
    ElMessage.error(res.message)
  }
}

const unblockIp = async (ip) => {
  const res = await api.antibotUnblock(ip)
  if (res.success) {
    ElMessage.success('已解封')
    loadData()
  } else {
    ElMessage.error(res.message)
  }
}

const clearAllBlocks = async () => {
  const res = await api.antibotClearBlocks()
  if (res.success) {
    ElMessage.success('已清空所有封禁')
    loadData()
  } else {
    ElMessage.error(res.message)
  }
}

const resetStats = async () => {
  const res = await api.antibotResetStats()
  if (res.success) {
    ElMessage.success('统计已重置')
    loadData()
  } else {
    ElMessage.error(res.message)
  }
}

const showAddBlacklistDialog = () => {
  newBlacklistIp.value = ''
  blacklistDialogVisible.value = true
}

const showAddWhitelistDialog = () => {
  newWhitelistIp.value = ''
  whitelistDialogVisible.value = true
}

const addBlacklist = async () => {
  if (!newBlacklistIp.value) {
    ElMessage.warning('请输入IP地址')
    return
  }
  const res = await api.antibotAddBlacklist(newBlacklistIp.value)
  if (res.success) {
    ElMessage.success('已添加到黑名单')
    blacklistDialogVisible.value = false
    loadData()
  } else {
    ElMessage.error(res.message)
  }
}

const addWhitelist = async () => {
  if (!newWhitelistIp.value) {
    ElMessage.warning('请输入IP地址')
    return
  }
  const res = await api.antibotAddWhitelist(newWhitelistIp.value)
  if (res.success) {
    ElMessage.success('已添加到白名单')
    whitelistDialogVisible.value = false
    loadData()
  } else {
    ElMessage.error(res.message)
  }
}

const removeBlacklist = async (ip) => {
  const res = await api.antibotRemoveBlacklist(ip)
  if (res.success) {
    ElMessage.success('已移除')
    loadData()
  }
}

const removeWhitelist = async (ip) => {
  const res = await api.antibotRemoveWhitelist(ip)
  if (res.success) {
    ElMessage.success('已移除')
    loadData()
  }
}

onMounted(() => {
  loadData()
})
</script>

<style scoped>
.antibot-page {
  padding: 0;
}

.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

.page-header h2 {
  margin: 0;
}

.stat-card {
  text-align: center;
}

.stat-value {
  font-size: 32px;
  font-weight: bold;
}

.stat-label {
  color: #909399;
  margin-top: 8px;
}

.tag-container {
  max-height: 150px;
  overflow-y: auto;
}

.pagination-wrapper {
  margin-top: 12px;
  display: flex;
  justify-content: flex-end;
}
</style>
