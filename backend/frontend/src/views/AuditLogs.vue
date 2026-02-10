<template>
  <div class="audit-logs-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>操作审计日志</span>
          <div class="header-actions">
            <el-button type="primary" :icon="Download" @click="handleExport">导出</el-button>
          </div>
        </div>
      </template>

      <!-- 筛选条件 -->
      <el-form :inline="true" :model="filters" class="filter-form">
        <el-form-item label="操作类型">
          <el-select v-model="filters.action" placeholder="全部" clearable style="width: 150px">
            <el-option label="登录" value="login" />
            <el-option label="登出" value="logout" />
            <el-option label="创建" value="create" />
            <el-option label="更新" value="update" />
            <el-option label="删除" value="delete" />
            <el-option label="导入" value="import" />
            <el-option label="导出" value="export" />
            <el-option label="配置" value="config" />
          </el-select>
        </el-form-item>
        <el-form-item label="资源类型">
          <el-select v-model="filters.resource_type" placeholder="全部" clearable style="width: 150px">
            <el-option label="用户" value="user" />
            <el-option label="规则" value="rule" />
            <el-option label="域名" value="domain" />
            <el-option label="短链" value="shortlink" />
            <el-option label="配置" value="config" />
            <el-option label="API Key" value="api_key" />
          </el-select>
        </el-form-item>
        <el-form-item label="用户">
          <el-input v-model="filters.username" placeholder="用户名" clearable style="width: 150px" />
        </el-form-item>
        <el-form-item label="IP地址">
          <el-input v-model="filters.ip" placeholder="IP地址" clearable style="width: 150px" />
        </el-form-item>
        <el-form-item label="时间范围">
          <el-date-picker
            v-model="filters.dateRange"
            type="daterange"
            range-separator="至"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            format="YYYY-MM-DD"
            value-format="YYYY-MM-DD"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :icon="Search" @click="loadLogs">搜索</el-button>
          <el-button :icon="Refresh" @click="resetFilters">重置</el-button>
        </el-form-item>
      </el-form>

      <!-- 日志表格 -->
      <el-table 
        :data="logs" 
        v-loading="loading"
        stripe
        border
        style="width: 100%"
        @sort-change="handleSortChange"
      >
        <el-table-column prop="created_at" label="时间" width="180" sortable="custom">
          <template #default="{ row }">
            {{ formatTime(row.created_at) }}
          </template>
        </el-table-column>
        <el-table-column prop="username" label="用户" width="120" />
        <el-table-column prop="action" label="操作" width="100">
          <template #default="{ row }">
            <el-tag :type="getActionType(row.action)" size="small">
              {{ getActionLabel(row.action) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="resource_type" label="资源类型" width="100">
          <template #default="{ row }">
            {{ getResourceLabel(row.resource_type) }}
          </template>
        </el-table-column>
        <el-table-column prop="resource_id" label="资源ID" width="150" show-overflow-tooltip />
        <el-table-column prop="ip_address" label="IP地址" width="140" />
        <el-table-column prop="result" label="结果" width="80">
          <template #default="{ row }">
            <el-tag :type="row.result === 'success' ? 'success' : 'danger'" size="small">
              {{ row.result === 'success' ? '成功' : '失败' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="详情" min-width="200">
          <template #default="{ row }">
            <el-button 
              v-if="row.old_value || row.new_value" 
              type="primary" 
              link 
              size="small"
              @click="showDetail(row)"
            >
              查看变更
            </el-button>
            <span v-else-if="row.error_message" class="error-msg">{{ row.error_message }}</span>
            <span v-else>-</span>
          </template>
        </el-table-column>
      </el-table>

      <!-- 分页 -->
      <div class="pagination-wrapper">
        <el-pagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.pageSize"
          :page-sizes="[20, 50, 100, 200]"
          :total="pagination.total"
          layout="total, sizes, prev, pager, next, jumper"
          @size-change="loadLogs"
          @current-change="loadLogs"
        />
      </div>
    </el-card>

    <!-- 详情对话框 -->
    <el-dialog v-model="detailVisible" title="变更详情" width="800px">
      <el-descriptions :column="2" border>
        <el-descriptions-item label="操作时间">{{ formatTime(currentLog.created_at) }}</el-descriptions-item>
        <el-descriptions-item label="操作用户">{{ currentLog.username }}</el-descriptions-item>
        <el-descriptions-item label="操作类型">{{ getActionLabel(currentLog.action) }}</el-descriptions-item>
        <el-descriptions-item label="IP地址">{{ currentLog.ip_address }}</el-descriptions-item>
      </el-descriptions>
      
      <el-divider />
      
      <el-row :gutter="20">
        <el-col :span="12">
          <div class="diff-title">变更前</div>
          <pre class="diff-content old">{{ formatJson(currentLog.old_value) || '无' }}</pre>
        </el-col>
        <el-col :span="12">
          <div class="diff-title">变更后</div>
          <pre class="diff-content new">{{ formatJson(currentLog.new_value) || '无' }}</pre>
        </el-col>
      </el-row>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { Search, Refresh, Download } from '@element-plus/icons-vue'
import { getAuditLogs, exportAuditLogs } from '@/api'
import { ElMessage } from 'element-plus'

// 数据
const logs = ref([])
const loading = ref(false)
const detailVisible = ref(false)
const currentLog = ref({})

// 筛选条件
const filters = reactive({
  action: '',
  resource_type: '',
  username: '',
  ip: '',
  dateRange: []
})

// 分页
const pagination = reactive({
  page: 1,
  pageSize: 20,
  total: 0
})

// 排序
const sort = reactive({
  field: 'created_at',
  order: 'desc'
})

// 操作类型映射
const actionMap = {
  login: { label: '登录', type: 'success' },
  logout: { label: '登出', type: 'info' },
  create: { label: '创建', type: 'primary' },
  update: { label: '更新', type: 'warning' },
  delete: { label: '删除', type: 'danger' },
  import: { label: '导入', type: 'primary' },
  export: { label: '导出', type: 'info' },
  config: { label: '配置', type: 'warning' },
  security: { label: '安全', type: 'danger' }
}

// 资源类型映射
const resourceMap = {
  user: '用户',
  rule: '规则',
  domain: '域名',
  shortlink: '短链',
  config: '配置',
  api_key: 'API Key',
  webhook: 'Webhook'
}

// 获取操作标签
const getActionLabel = (action) => actionMap[action]?.label || action
const getActionType = (action) => actionMap[action]?.type || 'info'
const getResourceLabel = (type) => resourceMap[type] || type

// 格式化时间
const formatTime = (time) => {
  if (!time) return '-'
  return new Date(time).toLocaleString('zh-CN')
}

// 格式化JSON
const formatJson = (data) => {
  if (!data) return null
  try {
    const obj = typeof data === 'string' ? JSON.parse(data) : data
    return JSON.stringify(obj, null, 2)
  } catch {
    return data
  }
}

// 加载日志
const loadLogs = async () => {
  loading.value = true
  try {
    const params = {
      page: pagination.page,
      page_size: pagination.pageSize,
      sort_field: sort.field,
      sort_order: sort.order
    }
    
    if (filters.action) params.action = filters.action
    if (filters.resource_type) params.resource_type = filters.resource_type
    if (filters.username) params.username = filters.username
    if (filters.ip) params.ip = filters.ip
    if (filters.dateRange?.length === 2) {
      params.start_date = filters.dateRange[0]
      params.end_date = filters.dateRange[1]
    }
    
    const res = await getAuditLogs(params)
    if (res.data) {
      logs.value = res.data.list || []
      pagination.total = res.data.total || 0
    }
  } catch (error) {
    ElMessage.error('加载日志失败')
  } finally {
    loading.value = false
  }
}

// 重置筛选
const resetFilters = () => {
  filters.action = ''
  filters.resource_type = ''
  filters.username = ''
  filters.ip = ''
  filters.dateRange = []
  pagination.page = 1
  loadLogs()
}

// 排序变更
const handleSortChange = ({ prop, order }) => {
  sort.field = prop
  sort.order = order === 'ascending' ? 'asc' : 'desc'
  loadLogs()
}

// 显示详情
const showDetail = (row) => {
  currentLog.value = row
  detailVisible.value = true
}

// 导出
const handleExport = async () => {
  try {
    const params = { ...filters }
    if (filters.dateRange?.length === 2) {
      params.start_date = filters.dateRange[0]
      params.end_date = filters.dateRange[1]
    }
    delete params.dateRange
    
    const res = await exportAuditLogs(params)
    
    // 下载文件
    const blob = new Blob([res.data], { type: 'text/csv' })
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `audit_logs_${new Date().toISOString().split('T')[0]}.csv`
    link.click()
    window.URL.revokeObjectURL(url)
    
    ElMessage.success('导出成功')
  } catch (error) {
    ElMessage.error('导出失败')
  }
}

onMounted(() => {
  loadLogs()
})
</script>

<style scoped>
.audit-logs-container {
  padding: 20px;
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.filter-form {
  margin-bottom: 20px;
}

.pagination-wrapper {
  margin-top: 20px;
  display: flex;
  justify-content: flex-end;
}

.error-msg {
  color: #f56c6c;
  font-size: 12px;
}

.diff-title {
  font-weight: bold;
  margin-bottom: 10px;
  color: #303133;
}

.diff-content {
  padding: 15px;
  border-radius: 4px;
  font-size: 12px;
  line-height: 1.6;
  max-height: 400px;
  overflow: auto;
  white-space: pre-wrap;
  word-break: break-all;
}

.diff-content.old {
  background: #fef0f0;
  border: 1px solid #fbc4c4;
}

.diff-content.new {
  background: #f0f9eb;
  border: 1px solid #c2e7b0;
}
</style>
