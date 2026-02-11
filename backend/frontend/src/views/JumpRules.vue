<template>
  <div class="jump-rules-page">
    <!-- 顶部统计卡片 -->
    <el-row :gutter="20" class="stats-row">
      <el-col :span="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-content">
            <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%)">
              <el-icon><Link /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-value">{{ stats.total_rules }}</div>
              <div class="stat-label">总规则数</div>
            </div>
          </div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-content">
            <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%)">
              <el-icon><View /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-value">{{ stats.today_clicks }}</div>
              <div class="stat-label">今日点击</div>
            </div>
          </div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-content">
            <div class="stat-icon" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%)">
              <el-icon><TrendCharts /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-value">{{ stats.total_clicks }}</div>
              <div class="stat-label">总点击量</div>
            </div>
          </div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-content">
            <div class="stat-icon" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)">
              <el-icon><CircleCheck /></el-icon>
            </div>
            <div class="stat-info">
              <div class="stat-value">{{ stats.active_rules }}</div>
              <div class="stat-label">活跃规则</div>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 工具栏 -->
    <el-card class="toolbar-card">
      <div class="toolbar">
        <div class="toolbar-left">
          <el-button type="primary" @click="showCreateDialog('code')">
            <el-icon><Promotion /></el-icon>创建短链
          </el-button>
          <el-button type="success" @click="showCreateDialog('ip')">
            <el-icon><Monitor /></el-icon>添加IP跳转
          </el-button>
          <el-button @click="showBatchDialog">
            <el-icon><DocumentAdd /></el-icon>批量添加
          </el-button>
        </div>
        <div class="toolbar-right">
          <el-select v-model="filters.rule_type" placeholder="规则类型" clearable style="width: 120px" @change="loadData">
            <el-option label="全部类型" value="" />
            <el-option label="短链跳转" value="code" />
            <el-option label="IP跳转" value="ip" />
          </el-select>
          <el-select v-model="filters.group_tag" placeholder="分组" clearable style="width: 120px" @change="loadData">
            <el-option label="全部分组" value="" />
            <el-option v-for="g in groups" :key="g.tag" :label="g.name" :value="g.tag" />
          </el-select>
          <el-input v-model="filters.search" placeholder="搜索..." clearable style="width: 200px" @keyup.enter="loadData">
            <template #prefix><el-icon><Search /></el-icon></template>
          </el-input>
          <el-button @click="loadData"><el-icon><Refresh /></el-icon></el-button>
        </div>
      </div>
    </el-card>

    <!-- 规则列表 -->
    <el-card class="list-card">
      <el-table :data="rules" v-loading="loading" stripe style="width: 100%">
        <!-- 类型 -->
        <el-table-column label="类型" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="row.rule_type === 'code' ? 'primary' : 'success'" size="small">
              {{ row.rule_type === 'code' ? '短链' : 'IP' }}
            </el-tag>
          </template>
        </el-table-column>

        <!-- 匹配标识 -->
        <el-table-column label="匹配标识" min-width="180">
          <template #default="{ row }">
            <div class="match-key-cell">
              <div class="key-value">
                <el-link v-if="row.rule_type === 'code'" type="primary" :href="row.jump_url" target="_blank">
                  {{ row.match_key }}
                </el-link>
                <span v-else class="ip-text">{{ row.match_key }}</span>
                <el-button link size="small" @click="copyText(row.jump_url)">
                  <el-icon><CopyDocument /></el-icon>
                </el-button>
              </div>
              <div class="jump-url text-muted" :title="row.jump_url">{{ row.jump_url }}</div>
            </div>
          </template>
        </el-table-column>

        <!-- 目标URL -->
        <el-table-column label="目标URL" min-width="200">
          <template #default="{ row }">
            <div class="url-cell">
              <el-link :href="row.target_url" target="_blank" :title="row.target_url">
                {{ truncateUrl(row.target_url) }}
              </el-link>
              <div v-if="row.title || row.note" class="meta-info">
                <el-tag v-if="row.title" size="small" type="info">{{ row.title }}</el-tag>
                <span v-if="row.note" class="note-text">{{ row.note }}</span>
              </div>
            </div>
          </template>
        </el-table-column>

        <!-- 分组 -->
        <el-table-column label="分组" width="100">
          <template #default="{ row }">
            <el-tag size="small">{{ getGroupName(row.group_tag) }}</el-tag>
          </template>
        </el-table-column>

        <!-- 点击/UV -->
        <el-table-column label="点击/UV" width="100" align="center">
          <template #default="{ row }">
            <span class="clicks">{{ row.total_clicks }}</span>
            <span class="separator">/</span>
            <span class="uv">{{ row.unique_visitors }}</span>
          </template>
        </el-table-column>

        <!-- 状态 -->
        <el-table-column label="状态" width="80" align="center">
          <template #default="{ row }">
            <el-switch v-model="row.enabled" @change="toggleRule(row)" />
          </template>
        </el-table-column>

        <!-- 限制条件 -->
        <el-table-column label="限制" width="120">
          <template #default="{ row }">
            <template v-if="row.rule_type === 'ip'">
              <el-tag v-if="row.block_desktop" type="danger" size="small" style="margin: 1px;">禁PC</el-tag>
              <el-tag v-if="row.block_ios" type="warning" size="small" style="margin: 1px;">禁iOS</el-tag>
              <el-tag v-if="row.block_android" type="info" size="small" style="margin: 1px;">禁安卓</el-tag>
              <el-tag v-if="row.country_whitelist_enabled" type="success" size="small" style="margin: 1px;">地区限制</el-tag>
              <span v-if="!row.block_desktop && !row.block_ios && !row.block_android && !row.country_whitelist_enabled" class="text-muted">无</span>
            </template>
            <template v-else>
              <el-tag v-if="row.expire_type === 'permanent'" type="success" size="small">永久</el-tag>
              <el-tag v-else-if="row.expire_type === 'datetime'" type="warning" size="small" :title="row.expire_at">
                {{ formatDate(row.expire_at) }}
              </el-tag>
              <el-tag v-else-if="row.expire_type === 'clicks'" type="info" size="small">
                {{ row.total_clicks }}/{{ row.max_clicks }}
              </el-tag>
            </template>
          </template>
        </el-table-column>

        <!-- 创建时间 -->
        <el-table-column label="创建时间" width="160">
          <template #default="{ row }">{{ formatDateTime(row.created_at) }}</template>
        </el-table-column>

        <!-- 操作 -->
        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="showStatsDialog(row)">
              <el-icon><DataAnalysis /></el-icon>
            </el-button>
            <el-button link type="primary" size="small" @click="showEditDialog(row)">
              <el-icon><Edit /></el-icon>
            </el-button>
            <el-popconfirm title="确定删除此规则吗？" @confirm="deleteRule(row)">
              <template #reference>
                <el-button link type="danger" size="small">
                  <el-icon><Delete /></el-icon>
                </el-button>
              </template>
            </el-popconfirm>
          </template>
        </el-table-column>
      </el-table>

      <!-- 分页 -->
      <div class="pagination-wrapper">
        <el-pagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.pageSize"
          :page-sizes="[10, 20, 50, 100]"
          :total="pagination.total"
          layout="total, sizes, prev, pager, next, jumper"
          @size-change="loadData"
          @current-change="loadData"
        />
      </div>
    </el-card>

    <!-- 创建/编辑对话框 -->
    <el-dialog v-model="formDialog.visible" :title="formDialog.isEdit ? '编辑规则' : (formDialog.type === 'code' ? '创建短链' : '添加IP跳转')" width="600px">
      <el-form :model="formDialog.form" :rules="formRules" ref="formRef" label-width="100px">
        <!-- 匹配标识 -->
        <el-form-item :label="formDialog.type === 'code' ? '短码' : 'IP地址'" prop="match_key">
          <el-input 
            v-model="formDialog.form.match_key" 
            :placeholder="formDialog.type === 'code' ? '留空自动生成，或输入自定义短码' : '如: 192.168.1.1'"
            :disabled="formDialog.isEdit"
          />
        </el-form-item>

        <!-- 目标URL -->
        <el-form-item label="目标URL" prop="target_url">
          <el-input v-model="formDialog.form.target_url" placeholder="https://example.com" />
        </el-form-item>

        <!-- 标题/备注 -->
        <el-form-item label="标题">
          <el-input v-model="formDialog.form.title" placeholder="可选，便于识别" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="formDialog.form.note" placeholder="可选备注" />
        </el-form-item>

        <!-- 分组 -->
        <el-form-item label="分组">
          <el-select v-model="formDialog.form.group_tag" style="width: 100%">
            <el-option v-for="g in groups" :key="g.tag" :label="g.name" :value="g.tag" />
          </el-select>
        </el-form-item>

        <!-- 短链特有选项：域名选择 -->
        <template v-if="formDialog.type === 'code'">
          <el-form-item label="使用域名">
            <el-select v-model="formDialog.form.domain_id" placeholder="选择域名" style="width: 100%">
              <el-option v-for="d in domains" :key="d.id" :label="d.domain.replace(/^https?:\/\//, '') + (d.name ? ' (' + d.name + ')' : '') + (d.is_default ? ' [默认]' : '')" :value="d.id" />
            </el-select>
          </el-form-item>
        </template>

        <!-- 设备限制和国家白名单 (所有类型通用) -->
        <el-divider content-position="left">访问限制</el-divider>
        <el-form-item label="禁止设备">
          <el-checkbox v-model="formDialog.form.block_desktop">禁止PC</el-checkbox>
          <el-checkbox v-model="formDialog.form.block_ios">禁止iOS</el-checkbox>
          <el-checkbox v-model="formDialog.form.block_android">禁止Android</el-checkbox>
        </el-form-item>
        <el-form-item label="国家白名单">
          <el-switch v-model="formDialog.form.country_whitelist_enabled" />
        </el-form-item>
        <el-form-item v-if="formDialog.form.country_whitelist_enabled" label="允许国家">
          <el-select v-model="formDialog.form.country_whitelist" multiple placeholder="选择允许的国家" style="width: 100%">
            <el-option v-for="c in countries" :key="c.code" :label="c.name" :value="c.code" />
          </el-select>
        </el-form-item>

        <!-- 短链特有选项 -->
        <template v-if="formDialog.type === 'code'">
          <el-divider content-position="left">过期设置</el-divider>
          <el-form-item label="过期类型">
            <el-radio-group v-model="formDialog.form.expire_type">
              <el-radio value="permanent">永久有效</el-radio>
              <el-radio value="datetime">指定时间</el-radio>
              <el-radio value="clicks">点击次数</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item v-if="formDialog.form.expire_type === 'datetime'" label="过期时间">
            <el-date-picker v-model="formDialog.form.expire_at" type="datetime" placeholder="选择过期时间" style="width: 100%" />
          </el-form-item>
          <el-form-item v-if="formDialog.form.expire_type === 'clicks'" label="最大点击">
            <el-input-number v-model="formDialog.form.max_clicks" :min="1" :max="999999" />
          </el-form-item>
        </template>
      </el-form>
      <template #footer>
        <el-button @click="formDialog.visible = false">取消</el-button>
        <el-button type="primary" @click="submitForm" :loading="formDialog.loading">确定</el-button>
      </template>
    </el-dialog>

    <!-- 批量添加对话框 -->
    <el-dialog v-model="batchDialog.visible" title="批量添加" width="600px">
      <el-form :model="batchDialog.form" label-width="100px">
        <el-form-item label="规则类型">
          <el-radio-group v-model="batchDialog.form.type">
            <el-radio value="code">短链</el-radio>
            <el-radio value="ip">IP跳转</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="batchDialog.form.type === 'code'" label="使用域名">
          <el-select v-model="batchDialog.form.domain_id" placeholder="选择域名" style="width: 100%">
            <el-option v-for="d in domains" :key="d.id" :label="d.domain.replace(/^https?:\/\//, '') + (d.name ? ' (' + d.name + ')' : '')" :value="d.id" />
          </el-select>
        </el-form-item>
        <el-form-item :label="batchDialog.form.type === 'code' ? '原始URL' : 'IP列表'">
          <el-input 
            v-model="batchDialog.form.items" 
            type="textarea" 
            :rows="8" 
            :placeholder="batchDialog.form.type === 'code' ? '每行一个URL' : '每行一个IP'"
          />
        </el-form-item>
        <el-form-item v-if="batchDialog.form.type === 'ip'" label="统一跳转URL">
          <el-input v-model="batchDialog.form.target_url" placeholder="所有IP都跳转到这个URL" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="batchDialog.visible = false">取消</el-button>
        <el-button type="primary" @click="submitBatch" :loading="batchDialog.loading">添加</el-button>
      </template>
    </el-dialog>

    <!-- 统计对话框 -->
    <el-dialog v-model="statsDialog.visible" :title="'统计详情 - ' + (statsDialog.rule?.match_key || '')" width="800px">
      <div v-loading="statsDialog.loading">
        <!-- 概览 -->
        <el-row :gutter="20" style="margin-bottom: 20px;">
          <el-col :span="8">
            <el-statistic title="总点击量" :value="statsDialog.data?.total_clicks || 0" />
          </el-col>
          <el-col :span="8">
            <el-statistic title="独立访客" :value="statsDialog.data?.unique_visitors || 0" />
          </el-col>
          <el-col :span="8">
            <el-statistic title="最后访问" :value="statsDialog.data?.last_access_at || '无'" />
          </el-col>
        </el-row>

        <el-tabs>
          <el-tab-pane label="访问趋势">
            <div ref="chartRef" style="height: 300px;"></div>
          </el-tab-pane>
          <el-tab-pane label="设备分布">
            <el-table :data="statsDialog.data?.devices || []" stripe>
              <el-table-column prop="device_type" label="设备类型" />
              <el-table-column prop="count" label="访问次数" />
            </el-table>
          </el-tab-pane>
          <el-tab-pane label="地区分布">
            <el-table :data="statsDialog.data?.countries || []" stripe>
              <el-table-column prop="country" label="国家/地区" />
              <el-table-column prop="count" label="访问次数" />
            </el-table>
          </el-tab-pane>
          <el-tab-pane label="最近访问">
            <el-table :data="statsDialog.data?.recent_visits || []" stripe max-height="300">
              <el-table-column prop="visitor_ip" label="访客IP" width="130" />
              <el-table-column prop="country" label="地区" width="100" />
              <el-table-column prop="device_type" label="设备" width="80" />
              <el-table-column prop="browser" label="浏览器" width="80" />
              <el-table-column prop="visited_at" label="访问时间" width="160" />
            </el-table>
          </el-tab-pane>
        </el-tabs>
      </div>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, nextTick } from 'vue'
import { ElMessage } from 'element-plus'
import * as api from '../api'

// 数据
const loading = ref(false)
const rules = ref([])
const groups = ref([])
const domains = ref([])
const stats = reactive({
  total_rules: 0,
  active_rules: 0,
  total_clicks: 0,
  today_clicks: 0
})
const filters = reactive({
  rule_type: '',
  group_tag: '',
  search: ''
})
const pagination = reactive({
  page: 1,
  pageSize: 20,
  total: 0
})

// 国家列表
const countries = [
  { code: 'CN', name: '中国' },
  { code: 'US', name: '美国' },
  { code: 'JP', name: '日本' },
  { code: 'KR', name: '韩国' },
  { code: 'GB', name: '英国' },
  { code: 'DE', name: '德国' },
  { code: 'FR', name: '法国' },
  { code: 'SG', name: '新加坡' },
  { code: 'HK', name: '香港' },
  { code: 'TW', name: '台湾' }
]

// 表单对话框
const formRef = ref()
const formDialog = reactive({
  visible: false,
  isEdit: false,
  type: 'code',
  loading: false,
  form: getDefaultForm()
})

const formRules = {
  target_url: [{ required: true, message: '请输入目标URL', trigger: 'blur' }],
  match_key: []
}

function getDefaultForm() {
  // 获取默认域名
  const defaultDomain = domains.value.find(d => d.is_default)
  return {
    id: null,
    match_key: '',
    target_url: '',
    title: '',
    note: '',
    group_tag: 'default',
    domain_id: defaultDomain?.id || null,
    block_desktop: false,
    block_ios: false,
    block_android: false,
    country_whitelist_enabled: false,
    country_whitelist: [],
    expire_type: 'permanent',
    expire_at: null,
    max_clicks: 100
  }
}

// 批量对话框
const batchDialog = reactive({
  visible: false,
  loading: false,
  form: {
    type: 'code',
    items: '',
    target_url: '',
    domain_id: null
  }
})

// 统计对话框
const chartRef = ref()
const statsDialog = reactive({
  visible: false,
  loading: false,
  rule: null,
  data: null
})

// 加载数据
async function loadData() {
  loading.value = true
  try {
    const res = await api.getJumpRules({
      ...filters,
      page: pagination.page,
      page_size: pagination.pageSize
    })
    if (res.success) {
      // 后端 paginate 返回的数据结构: { items: [...], total: ..., page: ..., limit: ..., pages: ... }
      const data = res.data || {}
      rules.value = Array.isArray(data) ? data : (data.items || [])
      pagination.total = data.total ?? res.total ?? 0
    }
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
}

async function loadGroups() {
  try {
    const res = await api.getJumpGroups()
    if (res.success) {
      groups.value = res.data || []
    }
  } catch (e) {
    console.error(e)
  }
}

async function loadDomains() {
  try {
    const res = await api.getDomains()
    if (res.success) {
      domains.value = res.data || []
    }
  } catch (e) {
    console.error(e)
  }
}

async function loadStats() {
  try {
    const res = await api.getJumpDashboardStats()
    if (res.success) {
      Object.assign(stats, res.data)
    }
  } catch (e) {
    console.error(e)
  }
}

// 创建/编辑
function showCreateDialog(type) {
  formDialog.isEdit = false
  formDialog.type = type
  formDialog.form = getDefaultForm()
  formDialog.form.group_tag = type === 'ip' ? 'ip' : 'shortlink'
  formDialog.visible = true
}

function showEditDialog(row) {
  formDialog.isEdit = true
  formDialog.type = row.rule_type
  formDialog.form = { ...row }
  formDialog.visible = true
}

async function submitForm() {
  try {
    await formRef.value.validate()
  } catch {
    return
  }

  formDialog.loading = true
  try {
    let res
    if (formDialog.isEdit) {
      res = await api.updateJumpRule(formDialog.form.id, formDialog.form)
    } else {
      res = await api.createJumpRule(formDialog.type, formDialog.form)
    }

    if (res.success) {
      ElMessage.success(formDialog.isEdit ? '更新成功' : '创建成功')
      formDialog.visible = false
      loadData()
      loadStats()
    } else {
      ElMessage.error(res.message || '操作失败')
    }
  } catch (e) {
    ElMessage.error('请求失败')
  } finally {
    formDialog.loading = false
  }
}

// 批量添加
function showBatchDialog() {
  const defaultDomain = domains.value.find(d => d.is_default)
  batchDialog.form = { type: 'code', items: '', target_url: '', domain_id: defaultDomain?.id || null }
  batchDialog.visible = true
}

async function submitBatch() {
  const items = batchDialog.form.items.split('\n').filter(s => s.trim())
  if (items.length === 0) {
    ElMessage.warning('请输入内容')
    return
  }

  batchDialog.loading = true
  try {
    const res = await api.batchCreateJumpRules(batchDialog.form.type, items, batchDialog.form.target_url, batchDialog.form.domain_id)
    if (res.success) {
      ElMessage.success(`成功添加 ${res.created} 条，失败 ${res.failed} 条`)
      batchDialog.visible = false
      loadData()
      loadStats()
    } else {
      ElMessage.error(res.message || '添加失败')
    }
  } catch (e) {
    ElMessage.error('请求失败')
  } finally {
    batchDialog.loading = false
  }
}

// 切换状态
async function toggleRule(row) {
  try {
    const res = await api.toggleJumpRule(row.id)
    if (res.success) {
      row.enabled = res.enabled
    } else {
      row.enabled = !row.enabled
      ElMessage.error(res.message || '操作失败')
    }
  } catch (e) {
    row.enabled = !row.enabled
    ElMessage.error('请求失败')
  }
}

// 删除
async function deleteRule(row) {
  try {
    const res = await api.deleteJumpRule(row.id)
    if (res.success) {
      ElMessage.success('删除成功')
      loadData()
      loadStats()
    } else {
      ElMessage.error(res.message || '删除失败')
    }
  } catch (e) {
    ElMessage.error('请求失败')
  }
}

// 统计
async function showStatsDialog(row) {
  statsDialog.rule = row
  statsDialog.visible = true
  statsDialog.loading = true
  
  try {
    const res = await api.getJumpRuleStats(row.id)
    if (res.success) {
      statsDialog.data = res.data
      // 绘制图表
      nextTick(() => {
        drawChart()
      })
    }
  } catch (e) {
    console.error(e)
  } finally {
    statsDialog.loading = false
  }
}

function drawChart() {
  if (!chartRef.value || !statsDialog.data?.daily) return
  
  // 简单的文本展示，如需图表可集成 ECharts
  const daily = statsDialog.data.daily
  if (daily.length === 0) {
    chartRef.value.innerHTML = '<div style="text-align:center;color:#999;padding:100px;">暂无数据</div>'
    return
  }
  
  let html = '<table style="width:100%;border-collapse:collapse;">'
  html += '<tr><th style="border:1px solid #eee;padding:8px;">日期</th><th style="border:1px solid #eee;padding:8px;">点击</th><th style="border:1px solid #eee;padding:8px;">UV</th></tr>'
  daily.forEach(d => {
    html += `<tr><td style="border:1px solid #eee;padding:8px;">${d.stat_date}</td><td style="border:1px solid #eee;padding:8px;">${d.clicks}</td><td style="border:1px solid #eee;padding:8px;">${d.unique_visitors}</td></tr>`
  })
  html += '</table>'
  chartRef.value.innerHTML = html
}

// 工具函数
function getGroupName(tag) {
  const g = groups.value.find(g => g.tag === tag)
  return g ? g.name : tag
}

function truncateUrl(url) {
  return url && url.length > 50 ? url.substring(0, 50) + '...' : url
}

function formatDate(str) {
  if (!str) return ''
  return str.split(' ')[0]
}

function formatDateTime(str) {
  if (!str) return ''
  return str.replace('T', ' ').substring(0, 19)
}

function copyText(text) {
  // 优先使用 clipboard API
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(() => {
      ElMessage.success('已复制')
    }).catch(() => {
      fallbackCopy(text)
    })
  } else {
    fallbackCopy(text)
  }
}

// 兼容性复制方法（支持 HTTP）
function fallbackCopy(text) {
  const textarea = document.createElement('textarea')
  textarea.value = text
  textarea.style.position = 'fixed'
  textarea.style.left = '-9999px'
  document.body.appendChild(textarea)
  textarea.select()
  try {
    document.execCommand('copy')
    ElMessage.success('已复制')
  } catch (e) {
    ElMessage.error('复制失败')
  }
  document.body.removeChild(textarea)
}

// 初始化
onMounted(() => {
  loadDomains()
  loadGroups()
  loadStats()
  loadData()
})
</script>

<style scoped>
.jump-rules-page {
  padding: 0;
}

.stats-row {
  margin-bottom: 20px;
}

.stat-card {
  border-radius: 8px;
}

.stat-content {
  display: flex;
  align-items: center;
}

.stat-icon {
  width: 56px;
  height: 56px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-right: 16px;
}

.stat-icon .el-icon {
  font-size: 28px;
  color: white;
}

.stat-info {
  flex: 1;
}

.stat-value {
  font-size: 28px;
  font-weight: 600;
  color: #303133;
  line-height: 1.2;
}

.stat-label {
  font-size: 14px;
  color: #909399;
  margin-top: 4px;
}

.toolbar-card {
  margin-bottom: 20px;
}

.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
}

.toolbar-left, .toolbar-right {
  display: flex;
  align-items: center;
  gap: 10px;
}

.list-card {
  border-radius: 8px;
}

.match-key-cell .key-value {
  display: flex;
  align-items: center;
  gap: 4px;
}

.match-key-cell .ip-text {
  font-family: monospace;
  font-weight: 500;
}

.match-key-cell .jump-url {
  font-size: 12px;
  margin-top: 2px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 200px;
}

.url-cell .meta-info {
  margin-top: 4px;
  display: flex;
  align-items: center;
  gap: 6px;
}

.note-text {
  font-size: 12px;
  color: #909399;
}

.text-muted {
  color: #909399;
}

.clicks {
  color: #409eff;
  font-weight: 500;
}

.uv {
  color: #67c23a;
  font-weight: 500;
}

.separator {
  color: #dcdfe6;
  margin: 0 2px;
}

.pagination-wrapper {
  margin-top: 20px;
  display: flex;
  justify-content: flex-end;
}
</style>
