<template>
  <div class="settings-page">
    <h2 style="margin-bottom: 20px;">系统设置</h2>

    <el-row :gutter="20">
      <el-col :span="12">
        <!-- 修改密码 -->
        <el-card>
          <template #header>修改密码</template>
          <el-form :model="passwordForm" :rules="passwordRules" ref="passwordFormRef" label-width="100px">
            <el-form-item label="原密码" prop="oldPassword">
              <el-input v-model="passwordForm.oldPassword" type="password" show-password />
            </el-form-item>
            <el-form-item label="新密码" prop="newPassword">
              <el-input v-model="passwordForm.newPassword" type="password" show-password />
            </el-form-item>
            <el-form-item label="确认密码" prop="confirmPassword">
              <el-input v-model="passwordForm.confirmPassword" type="password" show-password />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" @click="changePassword" :loading="submitting">
                修改密码
              </el-button>
            </el-form-item>
          </el-form>
        </el-card>

        <!-- 数据导出 -->
        <el-card style="margin-top: 20px;">
          <template #header>数据管理</template>
          <div style="margin-bottom: 16px;">
            <p style="color: #666; margin-bottom: 12px;">导出所有IP跳转配置数据</p>
            <el-button type="primary" @click="exportData">
              <el-icon><Download /></el-icon> 导出数据
            </el-button>
          </div>
          <el-divider />
          <div>
            <p style="color: #666; margin-bottom: 12px;">导入配置数据（会覆盖已存在的IP）</p>
            <el-upload
              :auto-upload="false"
              :show-file-list="false"
              accept=".json"
              :on-change="handleImportFile"
            >
              <el-button type="warning">
                <el-icon><Upload /></el-icon> 导入数据
              </el-button>
            </el-upload>
          </div>
        </el-card>
      </el-col>

      <el-col :span="12">
        <!-- 系统信息 -->
        <el-card>
          <template #header>系统信息</template>
          <el-descriptions :column="1" border>
            <el-descriptions-item label="系统版本">1.0.0</el-descriptions-item>
            <el-descriptions-item label="前端框架">Vue 3 + Element Plus</el-descriptions-item>
            <el-descriptions-item label="后端">PHP</el-descriptions-item>
            <el-descriptions-item label="当前时间">{{ currentTime }}</el-descriptions-item>
          </el-descriptions>
        </el-card>

        <!-- 快捷操作 -->
        <el-card style="margin-top: 20px;">
          <template #header>快捷操作</template>
          <el-button style="width: 100%; margin-bottom: 10px;" @click="clearAllStats">
            <el-icon><Delete /></el-icon> 清空所有统计数据
          </el-button>
          <el-button style="width: 100%;" type="danger" @click="logout">
            <el-icon><SwitchButton /></el-icon> 退出登录
          </el-button>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '../stores/user'
import { ElMessage, ElMessageBox } from 'element-plus'
import api from '../api'

const router = useRouter()
const userStore = useUserStore()
const passwordFormRef = ref(null)
const submitting = ref(false)
const currentTime = ref('')

const passwordForm = reactive({
  oldPassword: '',
  newPassword: '',
  confirmPassword: ''
})

const validateConfirmPassword = (rule, value, callback) => {
  if (value !== passwordForm.newPassword) {
    callback(new Error('两次输入的密码不一致'))
  } else {
    callback()
  }
}

const passwordRules = {
  oldPassword: [{ required: true, message: '请输入原密码', trigger: 'blur' }],
  newPassword: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 6, message: '密码至少6位', trigger: 'blur' }
  ],
  confirmPassword: [
    { required: true, message: '请确认密码', trigger: 'blur' },
    { validator: validateConfirmPassword, trigger: 'blur' }
  ]
}

const changePassword = async () => {
  if (!passwordFormRef.value) return
  await passwordFormRef.value.validate(async (valid) => {
    if (valid) {
      submitting.value = true
      try {
        const res = await api.changePassword(passwordForm.oldPassword, passwordForm.newPassword)
        if (res.success) {
          ElMessage.success('密码修改成功')
          passwordForm.oldPassword = ''
          passwordForm.newPassword = ''
          passwordForm.confirmPassword = ''
        } else {
          ElMessage.error(res.message)
        }
      } finally {
        submitting.value = false
      }
    }
  })
}

const exportData = async () => {
  try {
    const res = await api.exportData()
    if (res.success) {
      const dataStr = JSON.stringify(res.data, null, 2)
      const blob = new Blob([dataStr], { type: 'application/json' })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `ip_redirects_${new Date().toISOString().slice(0,10)}.json`
      a.click()
      URL.revokeObjectURL(url)
      ElMessage.success('导出成功')
    }
  } catch (error) {
    ElMessage.error('导出失败')
  }
}

const handleImportFile = async (file) => {
  try {
    const text = await file.raw.text()
    const data = JSON.parse(text)
    
    await ElMessageBox.confirm(
      `确定要导入数据吗？这将覆盖已存在的IP配置。`, 
      '确认导入',
      { type: 'warning' }
    )
    
    const res = await api.importData(data)
    if (res.success) {
      ElMessage.success(res.message)
    } else {
      ElMessage.error(res.message)
    }
  } catch (error) {
    if (error !== 'cancel') {
      ElMessage.error('导入失败，请检查文件格式')
    }
  }
}

const clearAllStats = async () => {
  await ElMessageBox.confirm('确定要清空所有统计数据吗？此操作不可恢复！', '警告', {
    type: 'warning'
  })
  const res = await api.clearStats('')
  if (res.success) {
    ElMessage.success('统计数据已清空')
  } else {
    ElMessage.error(res.message)
  }
}

const logout = async () => {
  await ElMessageBox.confirm('确定要退出登录吗？', '提示')
  await userStore.logout()
  router.push('/login')
}

let timer = null
onMounted(() => {
  const updateTime = () => {
    currentTime.value = new Date().toLocaleString('zh-CN')
  }
  updateTime()
  timer = setInterval(updateTime, 1000)
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
})
</script>

<style scoped>
.settings-page {
  padding: 0;
}
</style>
