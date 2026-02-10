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
          <span class="tab-title">
            域名池列表 ({{ domains.length }} 个) 
            <span v-if="serverIp" style="color: #909399; font-weight: normal;">服务器IP: {{ serverIp }}</span>
            <el-tag v-if="safetyStats.danger > 0" type="danger" size="small" style="margin-left: 12px;">{{ safetyStats.danger }} 危险</el-tag>
            <el-tag v-if="safetyStats.warning > 0" type="warning" size="small" style="margin-left: 4px;">{{ safetyStats.warning }} 警告</el-tag>
          </span>
          <div class="tab-actions">
            <el-button size="small" type="warning" @click="checkAllDomainsSafety" :loading="loading.safety">
              <el-icon><Shield /></el-icon> 安全检测
            </el-button>
            <el-button size="small" @click="checkDomainsResolve" :loading="loading.checking">
              <el-icon><Refresh /></el-icon> 检测解析
            </el-button>
            <el-button type="primary" size="small" @click="showDomainAddDialog">
              <el-icon><Plus /></el-icon> 添加域名
            </el-button>
          </div>
        </div>
        
        <el-row :gutter="20">
          <el-col :span="16">
            <el-table :data="domains" v-loading="loading.domain" stripe style="width: 100%">
              <el-table-column label="域名" min-width="180">
                <template #default="{ row }">
                  <span>{{ row.domain.replace(/^https?:\/\//, '') }}</span>
                  <el-tag v-if="row.is_default" type="success" size="small" style="margin-left: 8px;">默认</el-tag>
                </template>
              </el-table-column>
              <el-table-column prop="name" label="名称" width="100">
                <template #default="{ row }">
                  {{ row.name || '-' }}
                </template>
              </el-table-column>
              <el-table-column label="解析" width="100" align="center">
                <template #default="{ row }">
                  <template v-if="domainStatus[row.id]">
                    <el-tooltip v-if="domainStatus[row.id].status === 'ok'" content="已正确解析到本服务器" placement="top">
                      <el-tag type="success" size="small">✓ 正常</el-tag>
                    </el-tooltip>
                    <el-tooltip v-else-if="domainStatus[row.id].status === 'wrong_ip'" :content="'解析到: ' + (domainStatus[row.id].resolved_ips || []).join(', ')" placement="top">
                      <el-tag type="warning" size="small">⚠ IP不匹配</el-tag>
                    </el-tooltip>
                    <el-tooltip v-else content="域名未解析" placement="top">
                      <el-tag type="danger" size="small">✗ 未解析</el-tag>
                    </el-tooltip>
                  </template>
                  <span v-else style="color: #909399;">-</span>
                </template>
              </el-table-column>
              <el-table-column label="安全" width="100" align="center">
                <template #default="{ row }">
                  <el-tooltip v-if="row.safety_status === 'safe'" content="安全检测通过" placement="top">
                    <el-tag type="success" size="small">✓ 安全</el-tag>
                  </el-tooltip>
                  <el-tooltip v-else-if="row.safety_status === 'warning'" :content="getSafetyTooltip(row)" placement="top">
                    <el-tag type="warning" size="small">⚠ 警告</el-tag>
                  </el-tooltip>
                  <el-tooltip v-else-if="row.safety_status === 'danger'" :content="getSafetyTooltip(row)" placement="top">
                    <el-tag type="danger" size="small">✗ 危险</el-tag>
                  </el-tooltip>
                  <el-tooltip v-else content="点击安全检测按钮进行检测" placement="top">
                    <span style="color: #909399; cursor: pointer;" @click="checkSingleDomainSafety(row)">未检测</span>
                  </el-tooltip>
                </template>
              </el-table-column>
              <el-table-column label="启用" width="70" align="center">
                <template #default="{ row }">
                  <el-switch v-model="row.enabled" :active-value="1" :inactive-value="0" @change="toggleDomainEnabled(row)" />
                </template>
              </el-table-column>
              <el-table-column prop="use_count" label="使用" width="60" align="center" />
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

      <!-- 域名购买标签页 (Namemart) -->
      <el-tab-pane label="域名购买" name="namemart">
        <div class="tab-header">
          <span class="tab-title">Namemart 域名批量购买</span>
          <div class="tab-actions">
            <el-button size="small" @click="showNmConfigDialog">
              <el-icon><Setting /></el-icon> API配置
            </el-button>
            <el-button size="small" @click="showNmContactDialog" :disabled="!nmConfigured">
              <el-icon><User /></el-icon> 联系人
            </el-button>
          </div>
        </div>
        
        <el-row :gutter="20">
          <el-col :span="16">
            <el-alert v-if="!nmConfigured" type="warning" :closable="false" style="margin-bottom: 16px;">
              请先配置 Namemart API (Member ID 和 API Key)
            </el-alert>
            
            <!-- 域名查询区域 -->
            <el-card shadow="never" style="margin-bottom: 16px;">
              <template #header>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                  <span>批量查询域名</span>
                  <el-button type="primary" size="small" @click="nmCheckDomains" :loading="loading.nmCheck" :disabled="!nmConfigured">
                    查询可注册状态
                  </el-button>
                </div>
              </template>
              <el-input 
                v-model="nmQueryText" 
                type="textarea" 
                :rows="5" 
                placeholder="输入要查询的域名，每行一个（最多50个）&#10;例如：&#10;example.com&#10;test.net&#10;demo.org"
              />
            </el-card>
            
            <!-- 查询结果表格 -->
            <el-card shadow="never" v-if="nmCheckResults.length > 0">
              <template #header>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                  <span>查询结果 (可注册: {{ nmAvailableCount }} / {{ nmCheckResults.length }})</span>
                  <div>
                    <el-checkbox v-model="nmAddToCloudflare" :disabled="!cfConfigured" style="margin-right: 16px;">
                      购买后自动添加到 Cloudflare
                    </el-checkbox>
                    <el-button 
                      type="success" 
                      size="small" 
                      @click="nmRegisterSelected" 
                      :loading="loading.nmRegister"
                      :disabled="nmSelectedDomains.length === 0"
                    >
                      注册选中 ({{ nmSelectedDomains.length }})
                    </el-button>
                  </div>
                </div>
              </template>
              
              <el-table 
                :data="nmCheckResults" 
                @selection-change="handleNmSelectionChange"
                style="width: 100%"
                max-height="400"
              >
                <el-table-column type="selection" width="50" :selectable="row => row.available" />
                <el-table-column prop="domain" label="域名" min-width="200" />
                <el-table-column label="状态" width="100">
                  <template #default="{ row }">
                    <el-tag :type="row.available ? 'success' : (row.status === 1 ? 'info' : 'danger')" size="small">
                      {{ row.status_text }}
                    </el-tag>
                  </template>
                </el-table-column>
                <el-table-column label="价格" width="100">
                  <template #default="{ row }">
                    <span v-if="row.price" :class="{ 'premium-price': row.is_premium }">
                      {{ row.price_symbol }}{{ row.price }}
                      <el-tag v-if="row.is_premium" type="warning" size="small">溢价</el-tag>
                    </span>
                    <span v-else>-</span>
                  </template>
                </el-table-column>
                <el-table-column label="最低年限" width="80" align="center">
                  <template #default="{ row }">
                    {{ row.min_period || 1 }}年
                  </template>
                </el-table-column>
              </el-table>
            </el-card>
            
            <!-- 注册结果 -->
            <el-card shadow="never" v-if="nmRegisterResults.length > 0" style="margin-top: 16px;">
              <template #header>注册结果</template>
              <div style="max-height: 200px; overflow-y: auto;">
                <div v-for="(result, index) in nmRegisterResults" :key="index" style="margin-bottom: 8px; display: flex; align-items: center; gap: 8px;">
                  <el-tag :type="result.success ? 'success' : 'danger'" size="small">
                    {{ result.domain }}
                  </el-tag>
                  <span :style="{ color: result.success ? '#67c23a' : '#f56c6c', fontSize: '12px' }">
                    {{ result.message }}
                  </span>
                  <span v-if="result.cloudflare" style="font-size: 12px; color: #909399;">[+Cloudflare]</span>
                </div>
              </div>
            </el-card>
          </el-col>
          
          <el-col :span="8">
            <el-card shadow="never">
              <template #header>配置状态</template>
              <div class="nm-status">
                <p><strong>Namemart：</strong>
                  <el-tag :type="nmConfigured ? 'success' : 'danger'" size="small">
                    {{ nmConfigured ? '已配置' : '未配置' }}
                  </el-tag>
                </p>
                <p v-if="nmConfig.member_id"><strong>Member ID：</strong>{{ nmConfig.member_id }}</p>
                <p v-if="nmConfig.contact_id"><strong>联系人ID：</strong>{{ nmConfig.contact_id }}</p>
                <el-divider />
                <p><strong>Cloudflare：</strong>
                  <el-tag :type="cfConfigured ? 'success' : 'info'" size="small">
                    {{ cfConfigured ? '已配置' : '未配置' }}
                  </el-tag>
                </p>
                <p style="font-size: 12px; color: #909399;">
                  {{ cfConfigured ? '购买时可自动添加到 Cloudflare' : '配置后可自动添加域名到 Cloudflare' }}
                </p>
              </div>
            </el-card>
            <el-card shadow="never" style="margin-top: 16px;">
              <template #header>功能说明</template>
              <div class="tips">
                <p>• 批量查询域名注册状态</p>
                <p>• 显示域名价格（含溢价）</p>
                <p>• 勾选后批量注册购买</p>
                <p>• 可选自动添加到 Cloudflare</p>
                <p>• 自动设置 Cloudflare NS</p>
                <p>• 支持创建和管理联系人</p>
              </div>
            </el-card>
            <el-card shadow="never" style="margin-top: 16px;">
              <template #header>注册设置</template>
              <el-form label-width="80px" size="small">
                <el-form-item label="注册年限">
                  <el-select v-model="nmRegisterYears" style="width: 100%;">
                    <el-option v-for="y in 10" :key="y" :label="`${y}年`" :value="y" />
                  </el-select>
                </el-form-item>
                <el-form-item label="DNS1">
                  <el-input v-model="nmConfig.default_dns1" placeholder="ns1.domainnamedns.com" />
                </el-form-item>
                <el-form-item label="DNS2">
                  <el-input v-model="nmConfig.default_dns2" placeholder="ns2.domainnamedns.com" />
                </el-form-item>
              </el-form>
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

    <!-- Cloudflare 配置对话框 -->
    <el-dialog v-model="cfConfigDialog.visible" title="Cloudflare API 配置" width="500px">
      <el-form :model="cfConfigDialog.form" label-width="120px">
        <el-form-item label="API Token" required>
          <el-input v-model="cfConfigDialog.form.api_token" placeholder="Cloudflare API Token" show-password />
        </el-form-item>
        <el-form-item label="Account ID" required>
          <el-input v-model="cfConfigDialog.form.account_id" placeholder="Cloudflare Account ID" />
        </el-form-item>
      </el-form>
      <div class="tips" style="margin-top: 16px; padding: 12px; background: #f5f7fa; border-radius: 4px;">
        <p style="margin: 0 0 8px;"><strong>获取方式：</strong></p>
        <p style="margin: 0; font-size: 12px; color: #909399;">1. 登录 Cloudflare Dashboard</p>
        <p style="margin: 0; font-size: 12px; color: #909399;">2. 点击右上角头像 → My Profile → API Tokens</p>
        <p style="margin: 0; font-size: 12px; color: #909399;">3. 创建Token，选择 "Edit zone DNS" 模板</p>
        <p style="margin: 0; font-size: 12px; color: #909399;">4. Account ID 在域名概览页右侧可找到</p>
      </div>
      <template #footer>
        <el-button @click="cfConfigDialog.visible = false">取消</el-button>
        <el-button type="primary" @click="saveCfConfig" :loading="submitting">保存配置</el-button>
      </template>
    </el-dialog>

    <!-- Cloudflare 添加域名对话框 -->
    <el-dialog v-model="cfAddDialog.visible" title="添加域名到 Cloudflare" width="500px">
      <el-form :model="cfAddDialog.form" label-width="120px">
        <el-form-item label="域名" required>
          <el-input v-model="cfAddDialog.form.domain" placeholder="example.com（顶级域名）" />
        </el-form-item>
        <el-form-item label="开启HTTPS">
          <el-checkbox v-model="cfAddDialog.form.enable_https">自动开启 Full SSL 和始终 HTTPS</el-checkbox>
        </el-form-item>
        <el-form-item label="加入域名池">
          <el-checkbox v-model="cfAddDialog.form.add_to_pool">同时添加到本地域名池</el-checkbox>
        </el-form-item>
      </el-form>
      <div class="tips" style="margin-top: 12px; padding: 8px 12px; background: #f0f9eb; border-radius: 4px; font-size: 12px; color: #67c23a;">
        💡 服务器IP将自动获取，并自动添加 @ 和 www 两条A记录
      </div>
      <template #footer>
        <el-button @click="cfAddDialog.visible = false">取消</el-button>
        <el-button type="primary" @click="submitCfAddDomain" :loading="submitting">添加</el-button>
      </template>
    </el-dialog>

    <!-- Cloudflare 批量添加对话框 -->
    <el-dialog v-model="cfBatchDialog.visible" title="批量添加域名到 Cloudflare" width="600px">
      <el-form label-width="120px">
        <el-form-item label="域名列表" required>
          <el-input 
            v-model="cfBatchDialog.domains" 
            type="textarea" 
            :rows="8" 
            placeholder="每行一个顶级域名，例如：&#10;example.com&#10;test.com&#10;demo.org" 
          />
        </el-form-item>
        <el-form-item label="开启HTTPS">
          <el-checkbox v-model="cfBatchDialog.enable_https">自动开启 Full SSL 和始终 HTTPS</el-checkbox>
        </el-form-item>
        <el-form-item label="加入域名池">
          <el-checkbox v-model="cfBatchDialog.add_to_pool">同时添加到本地域名池</el-checkbox>
        </el-form-item>
      </el-form>
      <div class="tips" style="margin-top: 12px; padding: 8px 12px; background: #f0f9eb; border-radius: 4px; font-size: 12px; color: #67c23a;">
        💡 服务器IP将自动获取，并为每个域名自动添加 @ 和 www 两条A记录
      </div>
      
      <div v-if="cfBatchDialog.results.length > 0" style="margin-top: 16px;">
        <el-divider>添加结果</el-divider>
        <div style="max-height: 200px; overflow-y: auto;">
          <div v-for="(result, index) in cfBatchDialog.results" :key="index" style="margin-bottom: 8px;">
            <el-tag :type="result.success ? 'success' : 'danger'" size="small">
              {{ result.domain }}: {{ result.success ? '成功' : result.error }}
            </el-tag>
            <span v-if="result.nameservers" style="font-size: 12px; color: #909399; margin-left: 8px;">
              NS: {{ result.nameservers.join(', ') }}
            </span>
          </div>
        </div>
      </div>
      
      <template #footer>
        <el-button @click="cfBatchDialog.visible = false">关闭</el-button>
        <el-button type="primary" @click="submitCfBatchAdd" :loading="submitting">批量添加</el-button>
      </template>
    </el-dialog>

    <!-- Namemart 配置对话框 -->
    <el-dialog v-model="nmConfigDialog.visible" title="Namemart API 配置" width="550px">
      <el-form :model="nmConfigDialog.form" label-width="120px">
        <el-form-item label="Member ID" required>
          <el-input v-model="nmConfigDialog.form.member_id" placeholder="Namemart 会员ID" />
        </el-form-item>
        <el-form-item label="API Key" required>
          <el-input v-model="nmConfigDialog.form.api_key" placeholder="Namemart API Key" show-password />
        </el-form-item>
        <el-form-item label="联系人 ID">
          <el-input v-model="nmConfigDialog.form.contact_id" placeholder="注册域名使用的联系人ID（可稍后创建）" />
        </el-form-item>
        <el-form-item label="默认 DNS1">
          <el-input v-model="nmConfigDialog.form.default_dns1" placeholder="ns1.domainnamedns.com" />
        </el-form-item>
        <el-form-item label="默认 DNS2">
          <el-input v-model="nmConfigDialog.form.default_dns2" placeholder="ns2.domainnamedns.com" />
        </el-form-item>
      </el-form>
      <div class="tips" style="margin-top: 16px; padding: 12px; background: #f5f7fa; border-radius: 4px;">
        <p style="margin: 0 0 8px;"><strong>获取方式：</strong></p>
        <p style="margin: 0; font-size: 12px; color: #909399;">1. 登录 namemart.com</p>
        <p style="margin: 0; font-size: 12px; color: #909399;">2. 进入会员中心 → API管理</p>
        <p style="margin: 0; font-size: 12px; color: #909399;">3. 获取 Member ID 和 API Key</p>
      </div>
      <template #footer>
        <el-button @click="nmConfigDialog.visible = false">取消</el-button>
        <el-button type="primary" @click="saveNmConfig" :loading="submitting">保存配置</el-button>
      </template>
    </el-dialog>

    <!-- Namemart 联系人对话框 -->
    <el-dialog v-model="nmContactDialog.visible" :title="nmContactDialog.isCreate ? '创建联系人' : '联系人信息'" width="650px">
      <el-form :model="nmContactDialog.form" label-width="100px" size="small">
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="模板名称">
              <el-input v-model="nmContactDialog.form.template_name" placeholder="DefaultTemplate" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="类型">
              <el-select v-model="nmContactDialog.form.contact_type" style="width: 100%;">
                <el-option :value="0" label="个人" />
                <el-option :value="1" label="组织" />
              </el-select>
            </el-form-item>
          </el-col>
        </el-row>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="名字" required>
              <el-input v-model="nmContactDialog.form.first_name" placeholder="First Name (英文)" />
            </el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="姓氏" required>
              <el-input v-model="nmContactDialog.form.last_name" placeholder="Last Name (英文)" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item v-if="nmContactDialog.form.contact_type === 1" label="组织名称" required>
          <el-input v-model="nmContactDialog.form.org" placeholder="Organization Name" />
        </el-form-item>
        <el-row :gutter="16">
          <el-col :span="8">
            <el-form-item label="国家" required>
              <el-select v-model="nmContactDialog.form.country_code" style="width: 100%;">
                <el-option value="SG" label="新加坡 (SG)" />
                <el-option value="US" label="美国 (US)" />
                <el-option value="HK" label="香港 (HK)" />
                <el-option value="JP" label="日本 (JP)" />
                <el-option value="CN" label="中国 (CN)" />
              </el-select>
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="省份" required>
              <el-input v-model="nmContactDialog.form.province" placeholder="Province/State" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="城市" required>
              <el-input v-model="nmContactDialog.form.city" placeholder="City" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="街道地址" required>
          <el-input v-model="nmContactDialog.form.street" placeholder="Street Address (4-64字符)" />
        </el-form-item>
        <el-row :gutter="16">
          <el-col :span="8">
            <el-form-item label="邮编" required>
              <el-input v-model="nmContactDialog.form.post_code" placeholder="Post Code" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="电话区号" required>
              <el-input v-model="nmContactDialog.form.tel_area_code" placeholder="65" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="电话" required>
              <el-input v-model="nmContactDialog.form.tel" placeholder="+6512345678" />
            </el-form-item>
          </el-col>
        </el-row>
        <el-row :gutter="16">
          <el-col :span="8">
            <el-form-item label="传真区号">
              <el-input v-model="nmContactDialog.form.fax_area_code" placeholder="65" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="传真">
              <el-input v-model="nmContactDialog.form.fax" placeholder="+6512345678" />
            </el-form-item>
          </el-col>
          <el-col :span="8">
            <el-form-item label="邮箱" required>
              <el-input v-model="nmContactDialog.form.email" placeholder="email@example.com" />
            </el-form-item>
          </el-col>
        </el-row>
      </el-form>
      <template #footer>
        <el-button @click="nmContactDialog.visible = false">取消</el-button>
        <el-button v-if="nmContactDialog.isCreate" type="primary" @click="saveNmContact" :loading="submitting">创建联系人</el-button>
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
  deleteDomain as apiDeleteDomain,
  checkAllDomains,
  cfGetConfig,
  cfSaveConfig,
  cfListZones,
  cfAddDomain,
  cfBatchAddDomains,
  cfEnableHttps as apiCfEnableHttps,
  domainSafetyCheck,
  domainSafetyCheckAll,
  domainSafetyStats,
  nmGetConfig,
  nmSaveConfig,
  nmCheckDomains as apiNmCheckDomains,
  nmRegisterDomains,
  nmCreateContact,
  nmGetContactInfo
} from '../api'
import { Plus, Delete, Refresh, Setting, User, Upload } from '@element-plus/icons-vue'

const activeTab = ref('ip')
const loading = reactive({ ip: false, domain: false, checking: false, cf: false, safety: false, nmCheck: false, nmRegister: false })
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
const domainStatus = ref({})  // 域名解析状态 { id: { status, resolved_ips, is_resolved } }
const serverIp = ref('')
const safetyStats = reactive({ total: 0, safe: 0, warning: 0, danger: 0, unknown: 0 })
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
      domains.value = res.data || res.domains || []
      // 更新安全状态统计
      updateSafetyStats()
    }
  } finally {
    loading.domain = false
  }
}

// 更新安全状态统计
const updateSafetyStats = () => {
  safetyStats.total = domains.value.length
  safetyStats.safe = domains.value.filter(d => d.safety_status === 'safe').length
  safetyStats.warning = domains.value.filter(d => d.safety_status === 'warning').length
  safetyStats.danger = domains.value.filter(d => d.safety_status === 'danger').length
  safetyStats.unknown = domains.value.filter(d => !d.safety_status || d.safety_status === 'unknown').length
}

// 获取安全提示信息
const getSafetyTooltip = (row) => {
  if (!row.safety_detail) return '检测到安全风险'
  try {
    const detail = typeof row.safety_detail === 'string' ? JSON.parse(row.safety_detail) : row.safety_detail
    const dangers = detail.dangers || []
    const warnings = detail.warnings || []
    const all = [...dangers, ...warnings]
    return all.length > 0 ? all.join('; ') : '检测到安全风险'
  } catch {
    return '检测到安全风险'
  }
}

// 检测单个域名安全状态
const checkSingleDomainSafety = async (row) => {
  try {
    ElMessage.info(`正在检测 ${row.domain}...`)
    const res = await domainSafetyCheck(row.domain, row.id)
    if (res.success) {
      // 更新本地数据
      row.safety_status = res.status
      row.safety_detail = res.detail
      row.last_check_at = new Date().toISOString()
      updateSafetyStats()
      
      if (res.status === 'safe') {
        ElMessage.success(`${row.domain} 安全检测通过`)
      } else if (res.status === 'warning') {
        ElMessage.warning(`${row.domain} 存在安全警告`)
      } else {
        ElMessage.error(`${row.domain} 被标记为危险`)
      }
    } else {
      ElMessage.error(res.message || '检测失败')
    }
  } catch {
    ElMessage.error('检测失败')
  }
}

// 检测所有域名安全状态
const checkAllDomainsSafety = async () => {
  loading.safety = true
  try {
    ElMessage.info('正在检测所有域名安全状态，请稍候...')
    const res = await domainSafetyCheckAll()
    if (res.success) {
      const stats = res.stats || {}
      ElMessage.success(`检测完成：安全 ${stats.safe || 0}，警告 ${stats.warning || 0}，危险 ${stats.danger || 0}`)
      // 重新加载域名列表以获取最新状态
      await loadDomains()
    } else {
      ElMessage.error(res.message || '检测失败')
    }
  } catch {
    ElMessage.error('检测失败')
  } finally {
    loading.safety = false
  }
}

// 检测所有域名解析状态
const checkDomainsResolve = async () => {
  loading.checking = true
  try {
    const res = await checkAllDomains()
    if (res.success) {
      serverIp.value = res.server_ip || ''
      domainStatus.value = res.data || {}
      ElMessage.success('检测完成')
    } else {
      ElMessage.error(res.message || '检测失败')
    }
  } catch {
    ElMessage.error('检测失败')
  } finally {
    loading.checking = false
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

// ==================== Cloudflare 相关 ====================
const cfConfig = reactive({
  api_token: '',
  account_id: ''
})
const cfZones = ref([])
const cfConfigured = computed(() => cfConfig.api_token && cfConfig.account_id)

const cfConfigDialog = reactive({
  visible: false,
  form: { api_token: '', account_id: '' }
})

const cfAddDialog = reactive({
  visible: false,
  form: { domain: '', enable_https: true, add_to_pool: true }
})

const cfBatchDialog = reactive({
  visible: false,
  domains: '',
  enable_https: true,
  add_to_pool: true,
  results: []
})

const loadCfConfig = async () => {
  try {
    const res = await cfGetConfig()
    if (res.success && res.config) {
      Object.assign(cfConfig, res.config)
    }
  } catch {}
}

const loadCfZones = async () => {
  if (!cfConfigured.value) return
  loading.cf = true
  try {
    const res = await cfListZones()
    if (res.success) {
      cfZones.value = res.zones || []
    }
  } catch {
    ElMessage.error('获取域名列表失败')
  } finally {
    loading.cf = false
  }
}

const showCfConfigDialog = () => {
  cfConfigDialog.form = { ...cfConfig }
  cfConfigDialog.visible = true
}

const saveCfConfig = async () => {
  const { api_token, account_id } = cfConfigDialog.form
  if (!api_token || !account_id) {
    ElMessage.warning('请填写 API Token 和 Account ID')
    return
  }
  submitting.value = true
  try {
    const res = await cfSaveConfig(cfConfigDialog.form)
    if (res.success) {
      Object.assign(cfConfig, cfConfigDialog.form)
      cfConfigDialog.visible = false
      ElMessage.success('配置已保存')
      loadCfZones()
    } else {
      ElMessage.error(res.message || '保存失败')
    }
  } finally {
    submitting.value = false
  }
}

// ==================== Namemart 相关 ====================
const nmConfig = reactive({
  member_id: '',
  api_key: '',
  contact_id: '',
  default_dns1: 'ns1.domainnamedns.com',
  default_dns2: 'ns2.domainnamedns.com'
})
const nmConfigured = computed(() => nmConfig.member_id && nmConfig.api_key)
const nmQueryText = ref('')
const nmCheckResults = ref([])
const nmSelectedDomains = ref([])
const nmRegisterResults = ref([])
const nmRegisterYears = ref(1)
const nmAddToCloudflare = ref(false)

const nmAvailableCount = computed(() => nmCheckResults.value.filter(r => r.available).length)

const nmConfigDialog = reactive({
  visible: false,
  form: { member_id: '', api_key: '', contact_id: '', default_dns1: '', default_dns2: '' }
})

const nmContactDialog = reactive({
  visible: false,
  isCreate: false,
  form: {
    template_name: 'DefaultTemplate',
    contact_type: 0,
    first_name: '',
    last_name: '',
    org: '',
    country_code: 'SG',
    province: '',
    city: '',
    street: '',
    post_code: '',
    tel_area_code: '65',
    tel: '',
    fax_area_code: '65',
    fax: '',
    email: ''
  }
})

const loadNmConfig = async () => {
  try {
    const res = await nmGetConfig()
    if (res.success && res.config) {
      Object.assign(nmConfig, res.config)
    }
  } catch {}
}

const showNmConfigDialog = () => {
  nmConfigDialog.form = { ...nmConfig }
  nmConfigDialog.visible = true
}

const saveNmConfig = async () => {
  const { member_id, api_key } = nmConfigDialog.form
  if (!member_id || !api_key) {
    ElMessage.warning('请填写 Member ID 和 API Key')
    return
  }
  submitting.value = true
  try {
    const res = await nmSaveConfig(nmConfigDialog.form)
    if (res.success) {
      Object.assign(nmConfig, nmConfigDialog.form)
      nmConfigDialog.visible = false
      ElMessage.success('配置已保存')
    } else {
      ElMessage.error(res.message || '保存失败')
    }
  } finally {
    submitting.value = false
  }
}

const showNmContactDialog = async () => {
  // 如果有联系人 ID，尝试获取联系人信息
  if (nmConfig.contact_id) {
    try {
      const res = await nmGetContactInfo(nmConfig.contact_id)
      if (res.code === 1000 && res.data) {
        nmContactDialog.form = {
          template_name: res.data.template_name || 'DefaultTemplate',
          contact_type: res.data.contact_type || 0,
          first_name: res.data.first_name || '',
          last_name: res.data.last_name || '',
          org: res.data.org || '',
          country_code: 'SG',
          province: '',
          city: '',
          street: '',
          post_code: '',
          tel_area_code: res.data.tel_area_code || '65',
          tel: res.data.tel || '',
          fax_area_code: '65',
          fax: '',
          email: res.data.email || ''
        }
        nmContactDialog.isCreate = false
        nmContactDialog.visible = true
        return
      }
    } catch {}
  }
  // 没有联系人或获取失败，显示创建表单
  nmContactDialog.form = {
    template_name: 'DefaultTemplate',
    contact_type: 0,
    first_name: '',
    last_name: '',
    org: '',
    country_code: 'SG',
    province: 'Singapore',
    city: 'Singapore',
    street: '',
    post_code: '',
    tel_area_code: '65',
    tel: '',
    fax_area_code: '65',
    fax: '',
    email: ''
  }
  nmContactDialog.isCreate = true
  nmContactDialog.visible = true
}

const saveNmContact = async () => {
  const form = nmContactDialog.form
  const required = ['first_name', 'last_name', 'province', 'city', 'street', 'post_code', 'tel', 'email']
  for (const field of required) {
    if (!form[field]) {
      ElMessage.warning(`请填写 ${field}`)
      return
    }
  }
  submitting.value = true
  try {
    const res = await nmCreateContact(form)
    if (res.success) {
      nmConfig.contact_id = res.contact_id
      ElMessage.success(`联系人创建成功，ID: ${res.contact_id}`)
      nmContactDialog.visible = false
    } else {
      ElMessage.error(res.message || '创建失败')
    }
  } finally {
    submitting.value = false
  }
}

const nmCheckDomains = async () => {
  if (!nmQueryText.value.trim()) {
    ElMessage.warning('请输入要查询的域名')
    return
  }
  loading.nmCheck = true
  nmCheckResults.value = []
  nmSelectedDomains.value = []
  try {
    const res = await apiNmCheckDomains(nmQueryText.value)
    if (res.success) {
      nmCheckResults.value = res.results || []
      ElMessage.success(`查询完成：${res.available} 个可注册 / ${res.total} 个`)
    } else {
      ElMessage.error(res.message || '查询失败')
    }
  } finally {
    loading.nmCheck = false
  }
}

const handleNmSelectionChange = (selection) => {
  nmSelectedDomains.value = selection.map(item => item.domain)
}

const nmRegisterSelected = async () => {
  if (nmSelectedDomains.value.length === 0) {
    ElMessage.warning('请选择要注册的域名')
    return
  }
  if (!nmConfig.contact_id) {
    ElMessage.warning('请先创建联系人')
    showNmContactDialog()
    return
  }
  
  loading.nmRegister = true
  nmRegisterResults.value = []
  try {
    const res = await nmRegisterDomains({
      domains: nmSelectedDomains.value,
      years: nmRegisterYears.value,
      add_to_cloudflare: nmAddToCloudflare.value,
      dns1: nmConfig.default_dns1,
      dns2: nmConfig.default_dns2
    })
    if (res.success) {
      nmRegisterResults.value = res.results || []
      const summary = res.summary || {}
      ElMessage.success(`注册完成：${summary.success || 0} 成功，${summary.failed || 0} 失败`)
      // 清除已注册的域名
      nmCheckResults.value = nmCheckResults.value.filter(r => !nmSelectedDomains.value.includes(r.domain))
      nmSelectedDomains.value = []
    } else {
      ElMessage.error(res.message || '注册失败')
    }
  } finally {
    loading.nmRegister = false
  }
}

const showCfAddDialog = () => {
  cfAddDialog.form = { domain: '', server_ip: '', enable_https: true, add_to_pool: true }
  cfAddDialog.visible = true
}

const submitCfAddDomain = async () => {
  if (!cfAddDialog.form.domain.trim()) {
    ElMessage.warning('请输入域名')
    return
  }
  submitting.value = true
  try {
    const res = await cfAddDomain({
      domain: cfAddDialog.form.domain,
      enable_https: cfAddDialog.form.enable_https,
      add_to_pool: cfAddDialog.form.add_to_pool
    })
    if (res.success) {
      let msg = `域名 ${cfAddDialog.form.domain} 添加成功！`
      if (res.nameservers) {
        msg += `\n请将域名NS修改为：\n${res.nameservers.join('\n')}`
      }
      ElMessage.success({ message: msg, duration: 10000, showClose: true })
      cfAddDialog.visible = false
      loadCfZones()
      if (cfAddDialog.form.add_to_pool) {
        loadDomains()
      }
    } else {
      ElMessage.error(res.message || '添加失败')
    }
  } finally {
    submitting.value = false
  }
}

const showCfBatchDialog = () => {
  cfBatchDialog.domains = ''
  cfBatchDialog.enable_https = true
  cfBatchDialog.add_to_pool = true
  cfBatchDialog.results = []
  cfBatchDialog.visible = true
}

const submitCfBatchAdd = async () => {
  const domainList = cfBatchDialog.domains.split('\n').map(d => d.trim()).filter(d => d)
  if (domainList.length === 0) {
    ElMessage.warning('请输入至少一个域名')
    return
  }
  submitting.value = true
  cfBatchDialog.results = []
  try {
    const res = await cfBatchAddDomains({
      domains: domainList,
      enable_https: cfBatchDialog.enable_https,
      add_to_pool: cfBatchDialog.add_to_pool
    })
    if (res.success) {
      cfBatchDialog.results = res.results || []
      const successCount = cfBatchDialog.results.filter(r => r.success).length
      ElMessage.success(`批量添加完成：${successCount}/${domainList.length} 成功`)
      loadCfZones()
      if (cfBatchDialog.add_to_pool) {
        loadDomains()
      }
    } else {
      ElMessage.error(res.message || '批量添加失败')
    }
  } finally {
    submitting.value = false
  }
}

const cfEnableHttps = async (domain) => {
  try {
    const res = await apiCfEnableHttps(domain)
    if (res.success) {
      ElMessage.success(`已为 ${domain} 开启HTTPS`)
    } else {
      ElMessage.error(res.message || '操作失败')
    }
  } catch {
    ElMessage.error('操作失败')
  }
}

const cfAddToPool = async (domain) => {
  submitting.value = true
  try {
    const res = await apiAddDomain({
      domain: `https://${domain}`,
      name: `CF-${domain}`,
      is_default: 0
    })
    if (res.success) {
      ElMessage.success(`域名 ${domain} 已添加到域名池`)
      loadDomains()
    } else {
      ElMessage.error(res.message || '添加失败')
    }
  } finally {
    submitting.value = false
  }
}

// ==================== 初始化 ====================
onMounted(() => {
  loadIpPool()
  loadDomains()
  loadCfConfig()
  loadNmConfig()
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

.cf-status {
  font-size: 13px;
  line-height: 2;
}

.cf-status p {
  margin: 0;
  word-break: break-all;
}

.nm-status {
  font-size: 13px;
  line-height: 2;
}

.nm-status p {
  margin: 0;
  word-break: break-all;
}

.premium-price {
  color: #e6a23c;
  font-weight: bold;
}
</style>
