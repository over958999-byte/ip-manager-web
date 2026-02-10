<template>
  <div class="backups-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>备份管理</span>
          <div class="header-actions">
            <el-button type="primary" :icon="Plus" @click="handleCreateBackup" :loading="creating">
              创建备份
            </el-button>
          </div>
        </div>
      </template>

      <el-alert 
        title="备份说明" 
        type="info" 
        :closable="false"
        show-icon
        style="margin-bottom: 20px"
      >
        系统会在每天凌晨 2 点自动执行备份。您也可以手动创建备份。备份文件默认保留 7 天。
      </el-alert>

      <el-table :data="backups" v-loading="loading" stripe border>
        <el-table-column prop="filename" label="文件名" min-width="250" />
        <el-table-column prop="size" label="大小" width="120">
          <template #default="{ row }">
            {{ formatSize(row.size) }}
          </template>
        </el-table-column>
        <el-table-column prop="cloud_uploaded" label="云存储" width="100">
          <template #default="{ row }">
            <el-tag :type="row.cloud_uploaded ? 'success' : 'info'" size="small">
              {{ row.cloud_uploaded ? '已上传' : '本地' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" width="180" />
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" link size="small" @click="handleDownload(row)">下载</el-button>
            <el-button type="warning" link size="small" @click="handleRestore(row)">恢复</el-button>
            <el-button type="danger" link size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 备份配置 -->
    <el-card style="margin-top: 20px">
      <template #header>
        <span>备份配置</span>
      </template>

      <el-form :model="config" label-width="120px">
        <el-form-item label="本地保留天数">
          <el-input-number v-model="config.retention_days" :min="1" :max="365" />
          <span class="form-tip">天</span>
        </el-form-item>
        
        <el-divider content-position="left">云存储配置（可选）</el-divider>
        
        <el-form-item label="云存储提供商">
          <el-select v-model="config.cloud_provider" placeholder="不使用云存储" clearable style="width: 200px">
            <el-option label="阿里云 OSS" value="aliyun" />
            <el-option label="腾讯云 COS" value="tencent" />
            <el-option label="AWS S3" value="aws" />
          </el-select>
        </el-form-item>
        
        <template v-if="config.cloud_provider">
          <el-form-item label="Endpoint/Region">
            <el-input 
              v-model="config.cloud_endpoint" 
              :placeholder="getEndpointPlaceholder()"
              style="width: 300px"
            />
          </el-form-item>
          <el-form-item label="Bucket">
            <el-input v-model="config.cloud_bucket" placeholder="bucket-name" style="width: 300px" />
          </el-form-item>
          <el-form-item label="Access Key">
            <el-input v-model="config.cloud_access_key" placeholder="Access Key ID" style="width: 300px" />
          </el-form-item>
          <el-form-item label="Secret Key">
            <el-input 
              v-model="config.cloud_secret_key" 
              type="password" 
              placeholder="Secret Access Key" 
              style="width: 300px"
              show-password
            />
          </el-form-item>
        </template>
        
        <el-form-item>
          <el-button type="primary" @click="handleSaveConfig" :loading="savingConfig">
            保存配置
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { Plus } from '@element-plus/icons-vue'
import { 
  getBackupList, 
  createBackup, 
  restoreBackup, 
  downloadBackup, 
  deleteBackup 
} from '../api'
import api from '../api'
import { ElMessage, ElMessageBox } from 'element-plus'

// 数据
const backups = ref([])
const loading = ref(false)
const creating = ref(false)
const savingConfig = ref(false)

// 配置
const config = reactive({
  retention_days: 7,
  cloud_provider: '',
  cloud_endpoint: '',
  cloud_bucket: '',
  cloud_access_key: '',
  cloud_secret_key: ''
})

// 格式化大小
const formatSize = (bytes) => {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}

// 获取 Endpoint 提示
const getEndpointPlaceholder = () => {
  const map = {
    aliyun: 'oss-cn-shenzhen.aliyuncs.com',
    tencent: 'ap-guangzhou',
    aws: 'us-east-1'
  }
  return map[config.cloud_provider] || ''
}

// 加载备份列表
const loadBackups = async () => {
  loading.value = true
  try {
    const res = await getBackupList()
    backups.value = res.data?.list || []
  } catch (error) {
    ElMessage.error('加载失败')
  } finally {
    loading.value = false
  }
}

// 创建备份
const handleCreateBackup = async () => {
  creating.value = true
  try {
    const uploadToCloud = config.cloud_provider ? true : false
    await createBackup(uploadToCloud)
    ElMessage.success('备份创建成功')
    loadBackups()
  } catch (error) {
    ElMessage.error('备份创建失败: ' + (error.message || '未知错误'))
  } finally {
    creating.value = false
  }
}

// 下载备份
const handleDownload = async (row) => {
  try {
    const res = await downloadBackup(row.filename)
    
    const blob = new Blob([res.data], { type: 'application/octet-stream' })
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = row.filename
    link.click()
    window.URL.revokeObjectURL(url)
  } catch (error) {
    ElMessage.error('下载失败')
  }
}

// 恢复备份
const handleRestore = async (row) => {
  await ElMessageBox.confirm(
    `确定要从备份 "${row.filename}" 恢复数据吗？\n\n⚠️ 警告：此操作将覆盖当前数据库中的所有数据！`,
    '恢复备份',
    { 
      type: 'warning',
      confirmButtonText: '确定恢复',
      confirmButtonClass: 'el-button--danger'
    }
  )
  
  try {
    await restoreBackup(row.filename)
    ElMessage.success('数据恢复成功')
  } catch (error) {
    ElMessage.error('恢复失败: ' + (error.message || '未知错误'))
  }
}

// 删除备份
const handleDelete = async (row) => {
  await ElMessageBox.confirm(
    `确定要删除备份 "${row.filename}" 吗？此操作不可恢复。`,
    '删除备份',
    { type: 'warning' }
  )
  
  try {
    await deleteBackup(row.filename)
    ElMessage.success('删除成功')
    loadBackups()
  } catch (error) {
    ElMessage.error('删除失败')
  }
}

// 保存配置
const handleSaveConfig = async () => {
  savingConfig.value = true
  try {
    await api.request('save_backup_config', config)
    ElMessage.success('配置已保存')
  } catch (error) {
    ElMessage.error('保存失败')
  } finally {
    savingConfig.value = false
  }
}

// 加载配置
const loadConfig = async () => {
  try {
    const res = await api.request('get_backup_config')
    if (res.data) {
      Object.assign(config, res.data)
    }
  } catch (error) {
    // 忽略
  }
}

onMounted(() => {
  loadBackups()
  loadConfig()
})
</script>

<style scoped>
.backups-container {
  padding: 20px;
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.form-tip {
  margin-left: 10px;
  color: #909399;
}
</style>
