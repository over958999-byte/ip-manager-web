<template>
  <div class="ip-pool-page">
    <div class="page-header">
      <h2>IP池管理</h2>
      <div>
        <el-button type="primary" @click="showAddDialog">
          <el-icon><Plus /></el-icon> 添加IP到池
        </el-button>
        <el-popconfirm title="确定清空IP池吗？" @confirm="clearPool">
          <template #reference>
            <el-button type="danger">
              <el-icon><Delete /></el-icon> 清空IP池
            </el-button>
          </template>
        </el-popconfirm>
      </div>
    </div>

    <el-row :gutter="20">
      <!-- IP池列表 -->
      <el-col :span="14">
        <el-card>
          <template #header>
            <div style="display: flex; justify-content: space-between; align-items: center;">
              <span>IP池列表 ({{ ipPool.length }} 个)</span>
              <el-button 
                type="primary" 
                size="small" 
                :disabled="selectedIps.length === 0"
                @click="showActivateDialog"
              >
                激活选中 ({{ selectedIps.length }})
              </el-button>
            </div>
          </template>
          <el-table 
            :data="paginatedIpPool" 
            v-loading="loading"
            @selection-change="handleSelectionChange"
            style="width: 100%"
          >
            <el-table-column type="selection" width="55" />
            <el-table-column prop="ip" label="IP地址" />
            <el-table-column label="操作" width="150">
              <template #default="{ row }">
                <el-button link type="primary" @click="activateSingle(row.ip)">激活</el-button>
                <el-popconfirm title="确定移除此IP吗？" @confirm="removeFromPool(row.ip)">
                  <template #reference>
                    <el-button link type="danger">移除</el-button>
                  </template>
                </el-popconfirm>
              </template>
            </el-table-column>
          </el-table>
          <div class="pagination-wrapper">
            <el-pagination
              v-model:current-page="currentPage"
              v-model:page-size="pageSize"
              :page-sizes="[10, 20, 50, 100]"
              :total="ipPool.length"
              layout="total, sizes, prev, pager, next, jumper"
              @size-change="handleSizeChange"
              @current-change="handlePageChange"
            />
          </div>
        </el-card>
      </el-col>

      <!-- 快捷操作 -->
      <el-col :span="10">
        <el-card>
          <template #header>快捷添加IP</template>
          <el-input
            v-model="quickAddIps"
            type="textarea"
            :rows="8"
            placeholder="输入IP地址，每行一个或用逗号/空格分隔"
          />
          <el-button 
            type="primary" 
            style="margin-top: 12px; width: 100%;"
            @click="quickAdd"
            :loading="submitting"
          >
            添加到IP池
          </el-button>
        </el-card>

        <el-card style="margin-top: 16px;">
          <template #header>IP池说明</template>
          <div class="tips">
            <p>• IP池用于存储备用IP地址</p>
            <p>• 从池中激活IP后，IP会移至跳转列表</p>
            <p>• 在跳转管理中可将IP退回池中</p>
            <p>• 支持批量导入和批量激活</p>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 添加对话框 -->
    <el-dialog v-model="addDialogVisible" title="添加IP到池" width="500px">
      <el-input
        v-model="addIpsText"
        type="textarea"
        :rows="8"
        placeholder="每行一个IP地址，支持换行、逗号、空格分隔"
      />
      <template #footer>
        <el-button @click="addDialogVisible = false">取消</el-button>
        <el-button type="primary" @click="submitAdd" :loading="submitting">添加</el-button>
      </template>
    </el-dialog>

    <!-- 激活对话框 -->
    <el-dialog v-model="activateDialogVisible" title="激活IP" width="500px">
      <el-form :model="activateForm" label-width="100px">
        <el-form-item label="选中IP">
          <el-tag v-for="ip in selectedIps" :key="ip" style="margin: 2px;">{{ ip }}</el-tag>
        </el-form-item>
        <el-form-item label="跳转URL" required>
          <el-input v-model="activateForm.url" placeholder="https://example.com" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="activateForm.note" placeholder="可选备注" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="activateDialogVisible = false">取消</el-button>
        <el-button type="primary" @click="submitActivate" :loading="submitting">激活</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../api'

const loading = ref(false)
const submitting = ref(false)
const addDialogVisible = ref(false)
const activateDialogVisible = ref(false)

const ipPool = ref([])
const selectedIps = ref([])
const quickAddIps = ref('')
const addIpsText = ref('')

// 分页相关
const currentPage = ref(1)
const pageSize = ref(20)

// 计算分页后的数据
const paginatedIpPool = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  const end = start + pageSize.value
  return ipPool.value.slice(start, end)
})

const handlePageChange = (page) => {
  currentPage.value = page
}

const handleSizeChange = (size) => {
  pageSize.value = size
  currentPage.value = 1
}

const activateForm = reactive({
  url: '',
  note: ''
})

const loadData = async () => {
  loading.value = true
  try {
    const res = await api.getIpPool()
    if (res.success) {
      ipPool.value = (res.ip_pool || []).map(ip => ({ ip }))
    }
  } finally {
    loading.value = false
  }
}

const handleSelectionChange = (selection) => {
  selectedIps.value = selection.map(item => item.ip)
}

const showAddDialog = () => {
  addIpsText.value = ''
  addDialogVisible.value = true
}

const submitAdd = async () => {
  if (!addIpsText.value.trim()) {
    ElMessage.warning('请输入IP地址')
    return
  }
  submitting.value = true
  try {
    const res = await api.addToPool(addIpsText.value)
    if (res.success) {
      ElMessage.success(res.message)
      addDialogVisible.value = false
      loadData()
    } else {
      ElMessage.error(res.message)
    }
  } finally {
    submitting.value = false
  }
}

const quickAdd = async () => {
  if (!quickAddIps.value.trim()) {
    ElMessage.warning('请输入IP地址')
    return
  }
  submitting.value = true
  try {
    const res = await api.addToPool(quickAddIps.value)
    if (res.success) {
      ElMessage.success(res.message)
      quickAddIps.value = ''
      loadData()
    } else {
      ElMessage.error(res.message)
    }
  } finally {
    submitting.value = false
  }
}

const removeFromPool = async (ip) => {
  const res = await api.removeFromPool([ip])
  if (res.success) {
    ElMessage.success('已移除')
    loadData()
  } else {
    ElMessage.error(res.message)
  }
}

const clearPool = async () => {
  const res = await api.clearPool()
  if (res.success) {
    ElMessage.success('IP池已清空')
    loadData()
  } else {
    ElMessage.error(res.message)
  }
}

const showActivateDialog = () => {
  activateForm.url = ''
  activateForm.note = ''
  activateDialogVisible.value = true
}

const activateSingle = (ip) => {
  selectedIps.value = [ip]
  showActivateDialog()
}

const submitActivate = async () => {
  if (!activateForm.url) {
    ElMessage.warning('请输入跳转URL')
    return
  }
  submitting.value = true
  try {
    const res = await api.activateFromPool({
      ips: selectedIps.value,
      url: activateForm.url,
      note: activateForm.note
    })
    if (res.success) {
      ElMessage.success(res.message)
      activateDialogVisible.value = false
      selectedIps.value = []
      loadData()
    } else {
      ElMessage.error(res.message)
    }
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  loadData()
})
</script>

<style scoped>
.ip-pool-page {
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

.tips {
  color: #666;
  font-size: 14px;
  line-height: 2;
}

.pagination-wrapper {
  margin-top: 16px;
  display: flex;
  justify-content: flex-end;
}
</style>
