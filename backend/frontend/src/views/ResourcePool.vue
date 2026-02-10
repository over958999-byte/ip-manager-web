<template>
  <div class="resource-pool-page">
    <div class="page-header">
      <h2>资源池管理</h2>
    </div>

    <el-tabs v-model="activeTab" type="border-card">
      <!-- IP池标签页 -->
      <el-tab-pane label="IP池" name="ip">
        <div class="tab-header">
          <span class="tab-title">IP池列表 ({{ ipPool.length }} 个)</span>
          <div class="tab-actions">
            <el-button type="primary" size="small" @click="showIpAddDialog">
              <el-icon><Plus /></el-icon> 添加IP
            </el-button>
            <el-button 
              type="success" 
              size="small" 
              :disabled="selectedIps.length === 0"
              @click="showIpActivateDialog"
            >
              激活选中 ({{ selectedIps.length }})
            </el-button>
            <el-popconfirm title="确定清空IP池吗？" @confirm="clearIpPool">
              <template #reference>
                <el-button type="danger" size="small">
                  <el-icon><Delete /></el-icon> 清空
                </el-button>
              </template>
            </el-popconfirm>
          </div>
        </div>
        
        <el-row :gutter="20">
          <el-col :span="16">
            <el-table 
              :data="paginatedIpPool" 
              v-loading="loading.ip"
              @selection-change="handleIpSelectionChange"
              style="width: 100%"
            >
              <el-table-column type="selection" width="55" />
              <el-table-column prop="ip" label="IP地址" />
              <el-table-column prop="created_at" label="添加时间" width="180" />
              <el-table-column label="操作" width="150">
                <template #default="{ row }">
                  <el-button link type="primary" @click="activateIpSingle(row.ip)">激活</el-button>
                  <el-popconfirm title="确定移除此IP吗？" @confirm="removeIpFromPool(row.ip)">
                    <template #reference>
                      <el-button link type="danger">移除</el-button>
                    </template>
                  </el-popconfirm>
                </template>
              </el-table-column>
            </el-table>
            <div class="pagination-wrapper">
              <el-pagination
                v-model:current-page="ipPagination.page"
                v-model:page-size="ipPagination.size"
                :page-sizes="[10, 20, 50, 100]"
                :total="ipPool.length"
                layout="total, sizes, prev, pager, next"
              />
            </div>
          </el-col>
          
          <el-col :span="8">
            <el-card shadow="never">
              <template #header>快捷添加IP</template>
              <el-input
                v-model="quickAddIps"
                type="textarea"
                :rows="6"
                placeholder="输入IP地址，每行一个或用逗号/空格分隔"
              />
              <el-button 
                type="primary" 
                style="margin-top: 12px; width: 100%;"
                @click="quickAddIp"
                :loading="submitting"
              >
                添加到IP池
              </el-button>
            </el-card>
            <el-card shadow="never" style="margin-top: 16px;">
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
      </el-tab-pane>

      <!-- 域名池标签页 -->
      <el-tab-pane label="域名池" name="domain">
        <div class="tab-header">
          <span class="tab-title">域名池列表 ({{ domains.length }} 个)</span>
          <div class="tab-actions">
            <el-button type="primary" size="small" @click="showDomainAddDialog">
              <el-icon><Plus /></el-icon> 添加域名
            </el-button>
          </div>
        </div>
        
        <el-row :gutter="20">
          <el-col :span="16">
            <el-table :data="domains" v-loading="loading.domain" stripe style="width: 100%">
              <el-table-column label="域名" min-width="200">
                <template #default="{ row }">
                  <span>{{ row.domain.replace(/^https?:\/\//, '') }}</span>
                  <el-tag v-if="row.is_default" type="success" size="small" style="margin-left: 8px;">默认</el-tag>
                </template>
              </el-table-column>
              <el-table-column prop="name" label="名称" width="120">
                <template #default="{ row }">
                  {{ row.name || '-' }}
                </template>
              </el-table-column>
              <el-table-column label="状态" width="80" align="center">
                <template #default="{ row }">
                  <el-switch v-model="row.enabled" :active-value="1" :inactive-value="0" @change="toggleDomainEnabled(row)" />
                </template>
              </el-table-column>
              <el-table-column prop="use_count" label="使用数" width="80" align="center" />
              <el-table-column prop="created_at" label="添加时间" width="180" />
              <el-table-column label="操作" width="150" align="center">
                <template #default="{ row }">
                  <el-button v-if="!row.is_default" link type="primary" size="small" @click="setDefaultDomain(row)">设默认</el-button>
                  <el-button link type="warning" size="small" @click="editDomain(row)">编辑</el-button>
                  <el-popconfirm v-if="!row.is_default" title="确定删除此域名吗？" @confirm="deleteDomainAction(row)">
                    <template #reference>
                      <el-button link type="danger" size="small">删除</el-button>
                    </template>
                  </el-popconfirm>
                </template>
              </el-table-column>
            </el-table>
          </el-col>
          
          <el-col :span="8">
            <el-card shadow="never">
              <template #header>快捷添加域名</template>
              <el-form :model="domainForm" label-width="70px" size="small">
                <el-form-item label="域名">
                  <el-input v-model="domainForm.domain" placeholder="如: s.example.com" />
                </el-form-item>
                <el-form-item label="名称">
                  <el-input v-model="domainForm.name" placeholder="可选，便于识别" />
                </el-form-item>
                <el-form-item label="设默认">
                  <el-checkbox v-model="domainForm.is_default" />
                </el-form-item>
                <el-button 
                  type="primary" 
                  style="width: 100%;"
                  @click="quickAddDomain"
                  :loading="submitting"
                >
                  添加到域名池
                </el-button>
              </el-form>
            </el-card>
            <el-card shadow="never" style="margin-top: 16px;">
              <template #header>域名池说明</template>
              <div class="tips">
                <p>• 域名池用于短链服务</p>
                <p>• 创建短链时可选择使用的域名</p>
                <p>• 默认域名会在新建短链时自动选中</p>
                <p>• 域名需要在DNS解析到服务器IP</p>
                <p>• 域名自动添加HTTPS前缀</p>
              </div>
            </el-card>
          </el-col>
        </el-row>
      </el-tab-pane>
    </el-tabs>

    <!-- IP激活对话框 -->
    <el-dialog v-model="ipActivateDialog.visible" title="激活IP" width="500px">
      <el-form :model="ipActivateDialog.form" label-width="100px">
        <el-form-item label="选中IP">
          <div style="max-height: 150px; overflow-y: auto;">
            <el-tag v-for="ip in selectedIps" :key="ip" style="margin: 2px;">{{ ip }}</el-tag>
          </div>
        </el-form-item>
        <el-form-item label="跳转URL" required>
          <el-input v-model="ipActivateDialog.form.url" placeholder="https://example.com" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="ipActivateDialog.form.note" placeholder="可选备注" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="ipActivateDialog.visible = false">取消</el-button>
        <el-button type="primary" @click="submitIpActivate" :loading="submitting">激活</el-button>
      </template>
    </el-dialog>

    <!-- IP添加对话框 -->
    <el-dialog v-model="ipAddDialog.visible" title="添加IP到池" width="500px">
      <el-input
        v-model="ipAddDialog.text"
        type="textarea"
        :rows="8"
        placeholder="每行一个IP地址，支持换行、逗号、空格分隔"
      />
      <template #footer>
        <el-button @click="ipAddDialog.visible = false">取消</el-button>
        <el-button type="primary" @click="submitIpAdd" :loading="submitting">添加</el-button>
      </template>
    </el-dialog>

    <!-- 域名编辑对话框 -->
    <el-dialog v-model="domainEditDialog.visible" :title="domainEditDialog.isEdit ? '编辑域名' : '添加域名'" width="450px">
      <el-form :model="domainEditDialog.form" label-width="80px">
        <el-form-item label="域名" required>
          <el-input v-model="domainEditDialog.form.domain" placeholder="如: s.example.com" :disabled="domainEditDialog.isEdit" />
        </el-form-item>
        <el-form-item label="名称">
          <el-input v-model="domainEditDialog.form.name" placeholder="可选，便于识别" />
        </el-form-item>
        <el-form-item label="设为默认">
          <el-checkbox v-model="domainEditDialog.form.is_default" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="domainEditDialog.visible = false">取消</el-button>
        <el-button type="primary" @click="submitDomainEdit" :loading="submitting">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import api, { 
  getDomains as fetchDomains, 
  addDomain as apiAddDomain, 
  updateDomain as apiUpdateDomain, 
  deleteDomain as apiDeleteDomain 
} from '../api'

const activeTab = ref('ip')
const loading = reactive({ ip: false, domain: false })
const submitting = ref(false)

// ==================== IP池相关 ====================
const ipPool = ref([])
const selectedIps = ref([])
const quickAddIps = ref('')
const ipPagination = reactive({ page: 1, size: 20 })

const paginatedIpPool = computed(() => {
  const start = (ipPagination.page - 1) * ipPagination.size
  const end = start + ipPagination.size
  return ipPool.value.slice(start, end)
})

const ipAddDialog = reactive({ visible: false, text: '' })
const ipActivateDialog = reactive({
  visible: false,
  form: { url: '', note: '' }
})

const loadIpPool = async () => {
  loading.ip = true
  try {
    const res = await api.getIpPool()
    if (res.success) {
      ipPool.value = (res.ip_pool || []).map(ip => typeof ip === 'string' ? { ip, created_at: '' } : ip)
    }
  } finally {
    loading.ip = false
  }
}

const handleIpSelectionChange = (selection) => {
  selectedIps.value = selection.map(item => item.ip)
}

const showIpAddDialog = () => {
  ipAddDialog.text = ''
  ipAddDialog.visible = true
}

const submitIpAdd = async () => {
  if (!ipAddDialog.text.trim()) {
    ElMessage.warning('请输入IP地址')
    return
  }
  submitting.value = true
  try {
    const res = await api.addToPool(ipAddDialog.text)
    if (res.success) {
      ElMessage.success(res.message)
      ipAddDialog.visible = false
      loadIpPool()
    } else {
      ElMessage.error(res.message)
    }
  } finally {
    submitting.value = false
  }
}

const quickAddIp = async () => {
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
      loadIpPool()
    } else {
      ElMessage.error(res.message)
    }
  } finally {
    submitting.value = false
  }
}

const removeIpFromPool = async (ip) => {
  const res = await api.removeFromPool([ip])
  if (res.success) {
    ElMessage.success('已移除')
    loadIpPool()
  } else {
    ElMessage.error(res.message)
  }
}

const clearIpPool = async () => {
  const res = await api.clearPool()
  if (res.success) {
    ElMessage.success('IP池已清空')
    loadIpPool()
  } else {
    ElMessage.error(res.message)
  }
}

const showIpActivateDialog = () => {
  ipActivateDialog.form.url = ''
  ipActivateDialog.form.note = ''
  ipActivateDialog.visible = true
}

const activateIpSingle = (ip) => {
  selectedIps.value = [ip]
  showIpActivateDialog()
}

const submitIpActivate = async () => {
  if (!ipActivateDialog.form.url) {
    ElMessage.warning('请输入跳转URL')
    return
  }
  submitting.value = true
  try {
    const res = await api.activateFromPool({
      ips: selectedIps.value,
      url: ipActivateDialog.form.url,
      note: ipActivateDialog.form.note
    })
    if (res.success) {
      ElMessage.success(res.message)
      ipActivateDialog.visible = false
      selectedIps.value = []
      loadIpPool()
    } else {
      ElMessage.error(res.message)
    }
  } finally {
    submitting.value = false
  }
}

// ==================== 域名池相关 ====================
const domains = ref([])
const domainForm = reactive({ domain: '', name: '', is_default: false })
const domainEditDialog = reactive({
  visible: false,
  isEdit: false,
  form: { id: null, domain: '', name: '', is_default: false }
})

const loadDomains = async () => {
  loading.domain = true
  try {
    const res = await fetchDomains()
    if (res.success) {
      domains.value = res.domains || []
    }
  } finally {
    loading.domain = false
  }
}

const showDomainAddDialog = () => {
  domainEditDialog.isEdit = false
  domainEditDialog.form = { id: null, domain: '', name: '', is_default: false }
  domainEditDialog.visible = true
}

const editDomain = (row) => {
  domainEditDialog.isEdit = true
  domainEditDialog.form = {
    id: row.id,
    domain: row.domain,
    name: row.name || '',
    is_default: !!row.is_default
  }
  domainEditDialog.visible = true
}

const quickAddDomain = async () => {
  if (!domainForm.domain.trim()) {
    ElMessage.warning('请输入域名')
    return
  }
  submitting.value = true
  try {
    const res = await apiAddDomain({
      domain: domainForm.domain,
      name: domainForm.name,
      is_default: domainForm.is_default ? 1 : 0
    })
    if (res.success) {
      ElMessage.success('域名添加成功')
      domainForm.domain = ''
      domainForm.name = ''
      domainForm.is_default = false
      loadDomains()
    } else {
      ElMessage.error(res.message)
    }
  } finally {
    submitting.value = false
  }
}

const submitDomainEdit = async () => {
  if (!domainEditDialog.form.domain.trim()) {
    ElMessage.warning('请输入域名')
    return
  }
  submitting.value = true
  try {
    let res
    if (domainEditDialog.isEdit) {
      res = await apiUpdateDomain(domainEditDialog.form.id, {
        name: domainEditDialog.form.name,
        is_default: domainEditDialog.form.is_default ? 1 : 0
      })
    } else {
      res = await apiAddDomain({
        domain: domainEditDialog.form.domain,
        name: domainEditDialog.form.name,
        is_default: domainEditDialog.form.is_default ? 1 : 0
      })
    }
    if (res.success) {
      ElMessage.success(domainEditDialog.isEdit ? '域名更新成功' : '域名添加成功')
      domainEditDialog.visible = false
      loadDomains()
    } else {
      ElMessage.error(res.message)
    }
  } finally {
    submitting.value = false
  }
}

const toggleDomainEnabled = async (row) => {
  try {
    const res = await apiUpdateDomain(row.id, { enabled: row.enabled })
    if (res.success) {
      ElMessage.success(row.enabled ? '已启用' : '已禁用')
    } else {
      row.enabled = row.enabled ? 0 : 1
      ElMessage.error(res.message)
    }
  } catch {
    row.enabled = row.enabled ? 0 : 1
  }
}

const setDefaultDomain = async (row) => {
  try {
    const res = await apiUpdateDomain(row.id, { is_default: 1 })
    if (res.success) {
      ElMessage.success('已设为默认域名')
      loadDomains()
    } else {
      ElMessage.error(res.message)
    }
  } catch {}
}

const deleteDomainAction = async (row) => {
  try {
    const res = await apiDeleteDomain(row.id)
    if (res.success) {
      ElMessage.success('域名已删除')
      loadDomains()
    } else {
      ElMessage.error(res.message)
    }
  } catch {}
}

// ==================== 初始化 ====================
onMounted(() => {
  loadIpPool()
  loadDomains()
})
</script>

<style scoped>
.resource-pool-page {
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

.tab-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

.tab-title {
  font-size: 14px;
  color: #606266;
}

.tab-actions {
  display: flex;
  gap: 8px;
}

.tips {
  color: #666;
  font-size: 13px;
  line-height: 1.8;
}

.tips p {
  margin: 0;
}

.pagination-wrapper {
  margin-top: 16px;
  display: flex;
  justify-content: flex-end;
}
</style>
