<template>
  <div class="api-manager-page">
    <div class="page-header">
      <h2>API 管理</h2>
      <el-button type="primary" @click="showCreateDialog">
        <el-icon><Plus /></el-icon> 创建Token
      </el-button>
    </div>

    <!-- API Token 列表 -->
    <el-card>
      <template #header>
        <div style="display: flex; justify-content: space-between; align-items: center;">
          <span>API Token 列表</span>
          <el-button text @click="loadTokens">
            <el-icon><Refresh /></el-icon>
          </el-button>
        </div>
      </template>

      <el-table :data="tokens" v-loading="loading" border stripe>
        <el-table-column prop="name" label="名称" width="150" />
        <el-table-column label="Token" width="220">
          <template #default="{ row }">
            <div style="display: flex; align-items: center; gap: 5px;">
              <code style="font-size: 12px;">{{ row.token_display }}</code>
              <el-button link type="primary" size="small" @click="copyToken(row)">
                <el-icon><CopyDocument /></el-icon>
              </el-button>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="权限" width="180">
          <template #default="{ row }">
            <el-tag v-for="p in row.permissions" :key="p" size="small" style="margin: 2px;">
              {{ permissionLabels[p] || p }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="rate_limit" label="速率限制" width="100">
          <template #default="{ row }">
            {{ row.rate_limit }} 次/分
          </template>
        </el-table-column>
        <el-table-column prop="call_count" label="调用次数" width="90" />
        <el-table-column label="最后使用" width="150">
          <template #default="{ row }">
            {{ row.last_used_at || '从未' }}
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-switch 
              v-model="row.enabled" 
              :active-value="1" 
              :inactive-value="0"
              @change="toggleToken(row)"
            />
          </template>
        </el-table-column>
        <el-table-column label="过期时间" width="150">
          <template #default="{ row }">
            <span v-if="row.expires_at" :class="{ 'text-danger': isExpired(row.expires_at) }">
              {{ row.expires_at }}
            </span>
            <span v-else style="color: #67c23a;">永久</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="180" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="showLogsDialog(row)">日志</el-button>
            <el-button link type="warning" size="small" @click="regenerateToken(row)">重置</el-button>
            <el-button link type="primary" size="small" @click="editToken(row)">编辑</el-button>
            <el-popconfirm title="确定删除此Token？" @confirm="deleteToken(row.id)">
              <template #reference>
                <el-button link type="danger" size="small">删除</el-button>
              </template>
            </el-popconfirm>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- API 文档 -->
    <el-card style="margin-top: 20px;">
      <template #header>API 使用文档</template>
      <el-collapse v-model="activeDoc">
        <el-collapse-item title="认证方式" name="auth">
          <p>在请求头中添加 <code>X-API-Token</code>，或在请求体中包含 <code>api_token</code> 字段。</p>
          <pre class="code-block">curl -X POST "{{ apiBaseUrl }}?action=external_create_shortlink" \
  -H "Content-Type: application/json" \
  -H "X-API-Token: YOUR_TOKEN" \
  -d '{"url": "https://example.com"}'</pre>
        </el-collapse-item>
        
        <el-collapse-item title="创建短链接 (external_create_shortlink)" name="create">
          <p><strong>权限要求：</strong> shortlink_create</p>
          <p><strong>请求方式：</strong> POST</p>
          <p><strong>请求参数：</strong></p>
          <el-table :data="createShortlinkParams" size="small" border>
            <el-table-column prop="name" label="参数" width="120" />
            <el-table-column prop="type" label="类型" width="80" />
            <el-table-column prop="required" label="必填" width="60" />
            <el-table-column prop="desc" label="说明" />
          </el-table>
          <p style="margin-top: 10px;"><strong>响应示例：</strong></p>
          <pre class="code-block">{
  "success": true,
  "data": {
    "id": 1,
    "code": "abc123",
    "short_url": "https://your-domain.com/abc123",
    "target_url": "https://example.com"
  }
}</pre>
        </el-collapse-item>

        <el-collapse-item title="查询短链接 (external_get_shortlink)" name="get">
          <p><strong>权限要求：</strong> shortlink_stats</p>
          <p><strong>请求方式：</strong> GET/POST</p>
          <p><strong>请求参数：</strong> code (短码) 或 id (规则ID)</p>
          <pre class="code-block">GET {{ apiBaseUrl }}?action=external_get_shortlink&code=abc123
Header: X-API-Token: YOUR_TOKEN</pre>
          <p style="margin-top: 10px;"><strong>响应示例：</strong></p>
          <pre class="code-block">{
  "success": true,
  "data": {
    "id": 1,
    "code": "abc123",
    "target_url": "https://example.com",
    "total_clicks": 100,
    "unique_visitors": 80,
    "enabled": true,
    "created_at": "2026-02-10 12:00:00"
  }
}</pre>
        </el-collapse-item>

        <el-collapse-item title="列出短链接 (external_list_shortlinks)" name="list">
          <p><strong>权限要求：</strong> shortlink_stats</p>
          <p><strong>请求方式：</strong> GET/POST</p>
          <p><strong>请求参数：</strong> page (页码, 默认1), limit (每页数量, 默认20, 最大100)</p>
          <pre class="code-block">GET {{ apiBaseUrl }}?action=external_list_shortlinks&page=1&limit=20
Header: X-API-Token: YOUR_TOKEN</pre>
        </el-collapse-item>

        <el-collapse-item title="删除短链接 (external_delete_shortlink)" name="delete">
          <p><strong>权限要求：</strong> shortlink_delete</p>
          <p><strong>请求方式：</strong> POST</p>
          <p><strong>请求参数：</strong> code (短码) 或 id (规则ID)</p>
          <pre class="code-block">POST {{ apiBaseUrl }}?action=external_delete_shortlink
Header: X-API-Token: YOUR_TOKEN
Body: {"code": "abc123"}</pre>
        </el-collapse-item>
      </el-collapse>
    </el-card>

    <!-- 创建/编辑 Token 对话框 -->
    <el-dialog v-model="tokenDialogVisible" :title="editingToken ? '编辑Token' : '创建Token'" width="500px">
      <el-form :model="tokenForm" label-width="100px">
        <el-form-item label="名称" required>
          <el-input v-model="tokenForm.name" placeholder="如: 网站A接入" />
        </el-form-item>
        <el-form-item label="权限">
          <el-checkbox-group v-model="tokenForm.permissions">
            <el-checkbox label="shortlink_create">创建短链接</el-checkbox>
            <el-checkbox label="shortlink_stats">查询统计</el-checkbox>
            <el-checkbox label="shortlink_delete">删除短链接</el-checkbox>
          </el-checkbox-group>
        </el-form-item>
        <el-form-item label="速率限制">
          <el-input-number v-model="tokenForm.rate_limit" :min="1" :max="10000" />
          <span style="margin-left: 8px; color: #909399;">次/分钟</span>
        </el-form-item>
        <el-form-item label="过期时间">
          <el-date-picker
            v-model="tokenForm.expires_at"
            type="datetime"
            placeholder="留空表示永不过期"
            value-format="YYYY-MM-DD HH:mm:ss"
            style="width: 100%;"
          />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="tokenForm.note" type="textarea" :rows="2" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="tokenDialogVisible = false">取消</el-button>
        <el-button type="primary" @click="saveToken">保存</el-button>
      </template>
    </el-dialog>

    <!-- 新Token显示对话框 -->
    <el-dialog v-model="newTokenDialogVisible" title="Token创建成功" width="500px" :close-on-click-modal="false">
      <el-alert type="warning" :closable="false" style="margin-bottom: 15px;">
        <template #title>
          请立即复制此Token，关闭后将无法再次查看完整Token！
        </template>
      </el-alert>
      <el-input v-model="newTokenValue" readonly>
        <template #append>
          <el-button @click="copyNewToken">复制</el-button>
        </template>
      </el-input>
      <template #footer>
        <el-button type="primary" @click="newTokenDialogVisible = false">我已复制</el-button>
      </template>
    </el-dialog>

    <!-- 调用日志对话框 -->
    <el-dialog v-model="logsDialogVisible" :title="`API调用日志 - ${currentTokenName}`" width="800px">
      <el-table :data="apiLogs" v-loading="logsLoading" max-height="400" size="small">
        <el-table-column prop="created_at" label="时间" width="160" />
        <el-table-column prop="action" label="操作" width="140">
          <template #default="{ row }">
            <el-tag size="small">{{ row.action }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="ip" label="IP" width="130" />
        <el-table-column prop="response_code" label="状态码" width="80">
          <template #default="{ row }">
            <el-tag :type="row.response_code === 200 ? 'success' : 'danger'" size="small">
              {{ row.response_code }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="请求数据">
          <template #default="{ row }">
            <code style="font-size: 11px;">{{ JSON.stringify(row.request_data) }}</code>
          </template>
        </el-table-column>
      </el-table>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Plus, Refresh, CopyDocument } from '@element-plus/icons-vue'
import api from '../api'

const loading = ref(false)
const tokens = ref([])
const tokenDialogVisible = ref(false)
const newTokenDialogVisible = ref(false)
const newTokenValue = ref('')
const editingToken = ref(null)
const logsDialogVisible = ref(false)
const logsLoading = ref(false)
const apiLogs = ref([])
const currentTokenName = ref('')
const activeDoc = ref(['auth'])

const apiBaseUrl = computed(() => {
  return window.location.origin + '/api.php'
})

const permissionLabels = {
  'shortlink_create': '创建短链',
  'shortlink_stats': '查询统计',
  'shortlink_delete': '删除短链'
}

const createShortlinkParams = [
  { name: 'url', type: 'string', required: '是', desc: '目标URL' },
  { name: 'title', type: 'string', required: '否', desc: '短链接标题' },
  { name: 'note', type: 'string', required: '否', desc: '备注' },
  { name: 'domain_id', type: 'int', required: '否', desc: '使用的域名ID' },
  { name: 'expire_type', type: 'string', required: '否', desc: 'permanent/datetime/clicks' },
  { name: 'expire_at', type: 'datetime', required: '否', desc: '过期时间 (expire_type=datetime时)' },
  { name: 'max_clicks', type: 'int', required: '否', desc: '最大点击数 (expire_type=clicks时)' }
]

const tokenForm = reactive({
  name: '',
  permissions: ['shortlink_create', 'shortlink_stats'],
  rate_limit: 100,
  expires_at: null,
  note: ''
})

const resetForm = () => {
  tokenForm.name = ''
  tokenForm.permissions = ['shortlink_create', 'shortlink_stats']
  tokenForm.rate_limit = 100
  tokenForm.expires_at = null
  tokenForm.note = ''
  editingToken.value = null
}

const loadTokens = async () => {
  loading.value = true
  try {
    const res = await api.request({ action: 'api_token_list' })
    if (res.success) {
      tokens.value = res.data
    }
  } catch (e) {
    ElMessage.error('加载失败')
  }
  loading.value = false
}

const showCreateDialog = () => {
  resetForm()
  tokenDialogVisible.value = true
}

const editToken = (row) => {
  editingToken.value = row
  tokenForm.name = row.name
  tokenForm.permissions = row.permissions || []
  tokenForm.rate_limit = row.rate_limit
  tokenForm.expires_at = row.expires_at
  tokenForm.note = row.note || ''
  tokenDialogVisible.value = true
}

const saveToken = async () => {
  if (!tokenForm.name) {
    ElMessage.warning('请输入Token名称')
    return
  }

  try {
    if (editingToken.value) {
      await api.request({
        action: 'api_token_update',
        id: editingToken.value.id,
        ...tokenForm
      })
      ElMessage.success('更新成功')
    } else {
      const res = await api.request({
        action: 'api_token_create',
        ...tokenForm
      })
      if (res.success) {
        newTokenValue.value = res.data.token
        newTokenDialogVisible.value = true
      }
    }
    tokenDialogVisible.value = false
    loadTokens()
  } catch (e) {
    ElMessage.error('操作失败')
  }
}

const toggleToken = async (row) => {
  try {
    await api.request({
      action: 'api_token_update',
      id: row.id,
      enabled: row.enabled
    })
    ElMessage.success(row.enabled ? '已启用' : '已禁用')
  } catch (e) {
    ElMessage.error('操作失败')
  }
}

const deleteToken = async (id) => {
  try {
    await api.request({ action: 'api_token_delete', id })
    ElMessage.success('删除成功')
    loadTokens()
  } catch (e) {
    ElMessage.error('删除失败')
  }
}

const regenerateToken = async (row) => {
  try {
    const res = await api.request({ action: 'api_token_regenerate', id: row.id })
    if (res.success) {
      newTokenValue.value = res.data.token
      newTokenDialogVisible.value = true
      loadTokens()
    }
  } catch (e) {
    ElMessage.error('重置失败')
  }
}

const copyToken = async (row) => {
  ElMessage.info('为安全起见，请点击"重置"生成新Token后复制')
}

const copyNewToken = async () => {
  try {
    await navigator.clipboard.writeText(newTokenValue.value)
    ElMessage.success('已复制到剪贴板')
  } catch (e) {
    ElMessage.error('复制失败，请手动复制')
  }
}

const showLogsDialog = async (row) => {
  currentTokenName.value = row.name
  logsDialogVisible.value = true
  logsLoading.value = true
  
  try {
    const res = await api.request({ action: 'api_token_logs', token_id: row.id, limit: 100 })
    if (res.success) {
      apiLogs.value = res.data
    }
  } catch (e) {
    ElMessage.error('加载日志失败')
  }
  logsLoading.value = false
}

const isExpired = (date) => {
  return new Date(date) < new Date()
}

onMounted(() => {
  loadTokens()
})
</script>

<style scoped>
.api-manager-page {
  padding: 20px;
}

.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.page-header h2 {
  margin: 0;
}

.code-block {
  background: #f5f7fa;
  padding: 12px;
  border-radius: 4px;
  font-family: 'Monaco', 'Menlo', 'Consolas', monospace;
  font-size: 12px;
  overflow-x: auto;
  white-space: pre-wrap;
  word-break: break-all;
}

.text-danger {
  color: #f56c6c;
}

code {
  background: #f0f0f0;
  padding: 2px 6px;
  border-radius: 3px;
  font-family: 'Monaco', 'Menlo', 'Consolas', monospace;
}
</style>
