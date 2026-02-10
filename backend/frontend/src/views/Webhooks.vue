<template>
  <div class="webhooks-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>Webhook 通知管理</span>
          <el-button type="primary" :icon="Plus" @click="handleAdd">添加 Webhook</el-button>
        </div>
      </template>

      <el-table :data="webhooks" v-loading="loading" stripe border>
        <el-table-column prop="name" label="名称" width="150" />
        <el-table-column prop="platform" label="平台" width="120">
          <template #default="{ row }">
            <el-tag>{{ getPlatformLabel(row.platform) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="url" label="URL" min-width="200" show-overflow-tooltip />
        <el-table-column prop="enabled" label="状态" width="100">
          <template #default="{ row }">
            <el-switch 
              v-model="row.enabled" 
              :active-value="1" 
              :inactive-value="0"
              @change="handleToggle(row)"
            />
          </template>
        </el-table-column>
        <el-table-column prop="success_count" label="成功/失败" width="120">
          <template #default="{ row }">
            <span class="success-count">{{ row.success_count || 0 }}</span> / 
            <span class="failure-count">{{ row.failure_count || 0 }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="last_triggered_at" label="最后触发" width="180">
          <template #default="{ row }">
            {{ row.last_triggered_at || '-' }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" link size="small" @click="handleTest(row)">测试</el-button>
            <el-button type="primary" link size="small" @click="handleEdit(row)">编辑</el-button>
            <el-button type="primary" link size="small" @click="handleLogs(row)">日志</el-button>
            <el-button type="danger" link size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 编辑对话框 -->
    <el-dialog 
      v-model="dialogVisible" 
      :title="isEdit ? '编辑 Webhook' : '添加 Webhook'"
      width="600px"
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="100px">
        <el-form-item label="名称" prop="name">
          <el-input v-model="form.name" placeholder="Webhook 名称" />
        </el-form-item>
        <el-form-item label="平台" prop="platform">
          <el-select v-model="form.platform" placeholder="选择平台" style="width: 100%">
            <el-option label="企业微信" value="wecom" />
            <el-option label="钉钉" value="dingtalk" />
            <el-option label="飞书" value="feishu" />
            <el-option label="Slack" value="slack" />
            <el-option label="自定义" value="custom" />
          </el-select>
        </el-form-item>
        <el-form-item label="Webhook URL" prop="url">
          <el-input v-model="form.url" placeholder="https://..." />
        </el-form-item>
        <el-form-item label="签名密钥" v-if="form.platform === 'dingtalk'">
          <el-input v-model="form.secret" placeholder="钉钉签名密钥（可选）" />
        </el-form-item>
        <el-form-item label="告警级别">
          <el-checkbox-group v-model="form.alert_levels">
            <el-checkbox label="info">信息</el-checkbox>
            <el-checkbox label="warning">警告</el-checkbox>
            <el-checkbox label="error">错误</el-checkbox>
            <el-checkbox label="critical">严重</el-checkbox>
          </el-checkbox-group>
        </el-form-item>
        <el-form-item label="订阅事件">
          <el-checkbox-group v-model="form.events">
            <el-checkbox label="login_failed">登录失败</el-checkbox>
            <el-checkbox label="ip_locked">IP封锁</el-checkbox>
            <el-checkbox label="system_error">系统错误</el-checkbox>
            <el-checkbox label="domain_unsafe">域名异常</el-checkbox>
            <el-checkbox label="backup_complete">备份完成</el-checkbox>
          </el-checkbox-group>
        </el-form-item>
        <el-form-item label="启用">
          <el-switch v-model="form.enabled" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" @click="handleSubmit" :loading="submitting">确定</el-button>
      </template>
    </el-dialog>

    <!-- 日志对话框 -->
    <el-dialog v-model="logsVisible" title="发送日志" width="900px">
      <el-table :data="webhookLogs" max-height="400">
        <el-table-column prop="created_at" label="时间" width="180" />
        <el-table-column prop="event_type" label="事件" width="120" />
        <el-table-column prop="success" label="结果" width="80">
          <template #default="{ row }">
            <el-tag :type="row.success ? 'success' : 'danger'" size="small">
              {{ row.success ? '成功' : '失败' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="response_code" label="状态码" width="100" />
        <el-table-column prop="duration_ms" label="耗时" width="100">
          <template #default="{ row }">
            {{ row.duration_ms }}ms
          </template>
        </el-table-column>
        <el-table-column prop="error_message" label="错误信息" show-overflow-tooltip />
      </el-table>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { Plus } from '@element-plus/icons-vue'
import { 
  getWebhooks, 
  createWebhook, 
  updateWebhook, 
  deleteWebhook, 
  testWebhook,
  getWebhookLogs 
} from '@/api'
import { ElMessage, ElMessageBox } from 'element-plus'

// 数据
const webhooks = ref([])
const webhookLogs = ref([])
const loading = ref(false)
const submitting = ref(false)
const dialogVisible = ref(false)
const logsVisible = ref(false)
const isEdit = ref(false)
const formRef = ref(null)

// 表单
const defaultForm = {
  id: null,
  name: '',
  platform: 'wecom',
  url: '',
  secret: '',
  alert_levels: ['error', 'critical'],
  events: ['system_error', 'domain_unsafe'],
  enabled: 1
}

const form = reactive({ ...defaultForm })

// 验证规则
const rules = {
  name: [{ required: true, message: '请输入名称', trigger: 'blur' }],
  platform: [{ required: true, message: '请选择平台', trigger: 'change' }],
  url: [
    { required: true, message: '请输入 Webhook URL', trigger: 'blur' },
    { type: 'url', message: '请输入有效的 URL', trigger: 'blur' }
  ]
}

// 平台标签
const getPlatformLabel = (platform) => {
  const map = {
    wecom: '企业微信',
    dingtalk: '钉钉',
    feishu: '飞书',
    slack: 'Slack',
    custom: '自定义'
  }
  return map[platform] || platform
}

// 加载列表
const loadWebhooks = async () => {
  loading.value = true
  try {
    const res = await getWebhooks()
    webhooks.value = res.data?.list || []
  } catch (error) {
    ElMessage.error('加载失败')
  } finally {
    loading.value = false
  }
}

// 添加
const handleAdd = () => {
  isEdit.value = false
  Object.assign(form, defaultForm)
  dialogVisible.value = true
}

// 编辑
const handleEdit = (row) => {
  isEdit.value = true
  Object.assign(form, {
    ...row,
    alert_levels: row.alert_levels || ['error', 'critical'],
    events: row.events || []
  })
  dialogVisible.value = true
}

// 提交
const handleSubmit = async () => {
  await formRef.value?.validate()
  
  submitting.value = true
  try {
    const data = {
      ...form,
      alert_levels: JSON.stringify(form.alert_levels),
      events: JSON.stringify(form.events)
    }
    
    if (isEdit.value) {
      await updateWebhook(form.id, data)
      ElMessage.success('更新成功')
    } else {
      await createWebhook(data)
      ElMessage.success('创建成功')
    }
    
    dialogVisible.value = false
    loadWebhooks()
  } catch (error) {
    ElMessage.error(error.message || '操作失败')
  } finally {
    submitting.value = false
  }
}

// 切换状态
const handleToggle = async (row) => {
  try {
    await updateWebhook(row.id, { enabled: row.enabled })
    ElMessage.success('状态已更新')
  } catch (error) {
    row.enabled = row.enabled ? 0 : 1
    ElMessage.error('更新失败')
  }
}

// 测试
const handleTest = async (row) => {
  try {
    await testWebhook(row.id)
    ElMessage.success('测试消息已发送')
    loadWebhooks()
  } catch (error) {
    ElMessage.error('发送失败: ' + (error.message || '未知错误'))
  }
}

// 删除
const handleDelete = async (row) => {
  await ElMessageBox.confirm('确定要删除这个 Webhook 吗？', '提示', {
    type: 'warning'
  })
  
  try {
    await deleteWebhook(row.id)
    ElMessage.success('删除成功')
    loadWebhooks()
  } catch (error) {
    ElMessage.error('删除失败')
  }
}

// 查看日志
const handleLogs = async (row) => {
  try {
    const res = await getWebhookLogs(row.id)
    webhookLogs.value = res.data?.list || []
    logsVisible.value = true
  } catch (error) {
    ElMessage.error('加载日志失败')
  }
}

onMounted(() => {
  loadWebhooks()
})
</script>

<style scoped>
.webhooks-container {
  padding: 20px;
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.success-count {
  color: #67c23a;
  font-weight: bold;
}

.failure-count {
  color: #f56c6c;
  font-weight: bold;
}
</style>
