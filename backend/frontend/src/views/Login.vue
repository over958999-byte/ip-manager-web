<template>
  <div class="login-container">
    <!-- 动态背景 -->
    <div class="bg-animation">
      <div class="stars"></div>
      <div class="stars2"></div>
      <div class="stars3"></div>
    </div>
    
    <div class="login-wrapper">
      <!-- 左侧装饰 -->
      <div class="login-banner">
        <div class="banner-content">
          <div class="logo-icon">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
              <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
              <path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" stroke="currentColor" stroke-width="2"/>
            </svg>
          </div>
          <h1>困King</h1>
          <p class="banner-desc">流量分发高效平台</p>
          <div class="features">
            <div class="feature-item">
              <span class="feature-icon">🚀</span>
              <span>高性能跳转</span>
            </div>
            <div class="feature-item">
              <span class="feature-icon">🛡️</span>
              <span>反爬虫防护</span>
            </div>
            <div class="feature-item">
              <span class="feature-icon">📊</span>
              <span>数据可视化</span>
            </div>
          </div>
        </div>
      </div>
      
      <!-- 右侧登录表单 -->
      <div class="login-box">
        <div class="login-header">
          <h2>欢迎回来</h2>
          <p>请登录您的账户</p>
        </div>
        
        <el-form 
          ref="formRef" 
          :model="form" 
          :rules="rules" 
          @submit.prevent="handleLogin"
          class="login-form"
        >
          <el-form-item prop="username">
            <el-input
              v-model="form.username"
              placeholder="用户名"
              size="large"
              class="custom-input"
            >
              <template #prefix>
                <el-icon class="input-icon"><User /></el-icon>
              </template>
            </el-input>
          </el-form-item>
          
          <el-form-item prop="password">
            <el-input
              v-model="form.password"
              type="password"
              placeholder="密码"
              size="large"
              show-password
              class="custom-input"
              @keyup.enter="handleLogin"
            >
              <template #prefix>
                <el-icon class="input-icon"><Lock /></el-icon>
              </template>
            </el-input>
          </el-form-item>
          
          <!-- TOTP 验证码输入框 -->
          <el-form-item v-if="requireTotp" prop="totpCode">
            <el-input
              v-model="form.totpCode"
              placeholder="双因素认证码 (6位数字)"
              size="large"
              maxlength="6"
              class="custom-input totp-input"
              @keyup.enter="handleLogin"
            >
              <template #prefix>
                <el-icon class="input-icon"><Key /></el-icon>
              </template>
            </el-input>
            <div class="totp-hint">请打开 Authenticator App 输入6位验证码</div>
          </el-form-item>
          
          <div class="form-options">
            <el-checkbox v-model="form.remember">记住我</el-checkbox>
            <a href="javascript:;" class="forgot-link">忘记密码？</a>
          </div>
          
          <el-form-item>
            <el-button 
              type="primary" 
              size="large" 
              class="login-btn"
              :loading="loading"
              @click="handleLogin"
            >
              <span v-if="!loading">登 录</span>
              <span v-else>登录中...</span>
            </el-button>
          </el-form-item>
        </el-form>
        
        <div class="login-footer">
          <p>© 2026 困King · 安全登录</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '../stores/user'
import { ElMessage } from 'element-plus'
import { User, Lock, Key } from '@element-plus/icons-vue'

const router = useRouter()
const userStore = useUserStore()
const formRef = ref(null)
const loading = ref(false)
const requireTotp = ref(false)

const form = reactive({
  username: '',
  password: '',
  totpCode: '',
  remember: false
})

const rules = {
  username: [
    { required: true, message: '请输入用户名', trigger: 'blur' }
  ],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' }
  ]
}

// 页面加载时恢复保存的用户名密码
onMounted(() => {
  const savedRemember = localStorage.getItem('remember_me')
  if (savedRemember === 'true') {
    form.remember = true
    form.username = localStorage.getItem('saved_username') || ''
    form.password = localStorage.getItem('saved_password') || ''
  }
})

const handleLogin = async () => {
  if (!formRef.value) return
  
  await formRef.value.validate(async (valid) => {
    if (valid) {
      loading.value = true
      try {
        const res = await userStore.login(form.username, form.password, form.totpCode, form.remember)
        if (res.success) {
          // 检查是否需要 TOTP 验证
          if (res.data?.require_totp) {
            requireTotp.value = true
            ElMessage.warning('请输入双因素认证码')
          } else {
            // 保存或清除用户名密码
            if (form.remember) {
              localStorage.setItem('remember_me', 'true')
              localStorage.setItem('saved_username', form.username)
              localStorage.setItem('saved_password', form.password)
            } else {
              localStorage.removeItem('remember_me')
              localStorage.removeItem('saved_username')
              localStorage.removeItem('saved_password')
            }
            ElMessage.success('登录成功，欢迎回来！')
            router.push('/dashboard')
          }
        } else {
          ElMessage.error(res.message || '用户名或密码错误')
        }
      } catch (error) {
        ElMessage.error('登录失败，请稍后重试')
      } finally {
        loading.value = false
      }
    }
  })
}
</script>

<style scoped>
.login-container {
  position: relative;
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 100vh;
  background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
  overflow: hidden;
}

/* 星空动画背景 */
.bg-animation {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  overflow: hidden;
}

.stars, .stars2, .stars3 {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: transparent;
}

.stars {
  background-image: 
    radial-gradient(2px 2px at 20px 30px, #fff, transparent),
    radial-gradient(2px 2px at 40px 70px, rgba(255,255,255,0.8), transparent),
    radial-gradient(1px 1px at 90px 40px, #fff, transparent),
    radial-gradient(2px 2px at 160px 120px, rgba(255,255,255,0.9), transparent),
    radial-gradient(1px 1px at 230px 80px, #fff, transparent);
  background-size: 250px 250px;
  animation: twinkle 5s ease-in-out infinite;
}

.stars2 {
  background-image: 
    radial-gradient(1px 1px at 50px 100px, #fff, transparent),
    radial-gradient(2px 2px at 100px 50px, rgba(255,255,255,0.7), transparent),
    radial-gradient(1px 1px at 150px 150px, #fff, transparent);
  background-size: 200px 200px;
  animation: twinkle 7s ease-in-out infinite;
  animation-delay: 1s;
}

.stars3 {
  background-image: 
    radial-gradient(1px 1px at 80px 130px, rgba(255,255,255,0.6), transparent),
    radial-gradient(2px 2px at 180px 80px, #fff, transparent);
  background-size: 300px 300px;
  animation: twinkle 9s ease-in-out infinite;
  animation-delay: 2s;
}

@keyframes twinkle {
  0%, 100% { opacity: 0.5; }
  50% { opacity: 1; }
}

.login-wrapper {
  position: relative;
  z-index: 10;
  display: flex;
  width: 900px;
  min-height: 520px;
  background: rgba(255, 255, 255, 0.1);
  backdrop-filter: blur(20px);
  border-radius: 24px;
  box-shadow: 
    0 25px 50px -12px rgba(0, 0, 0, 0.5),
    inset 0 1px 0 rgba(255, 255, 255, 0.1);
  overflow: hidden;
  border: 1px solid rgba(255, 255, 255, 0.1);
}

/* 左侧装饰 */
.login-banner {
  flex: 1;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  padding: 50px 40px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  position: relative;
  overflow: hidden;
}

.login-banner::before {
  content: '';
  position: absolute;
  top: -50%;
  right: -50%;
  width: 100%;
  height: 100%;
  background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
}

.banner-content {
  position: relative;
  z-index: 1;
  color: #fff;
}

.logo-icon {
  width: 70px;
  height: 70px;
  margin-bottom: 24px;
  color: #fff;
}

.logo-icon svg {
  width: 100%;
  height: 100%;
}

.banner-content h1 {
  font-size: 32px;
  font-weight: 700;
  margin-bottom: 12px;
  letter-spacing: 2px;
}

.banner-desc {
  font-size: 16px;
  opacity: 0.9;
  margin-bottom: 40px;
}

.features {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.feature-item {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 14px;
  opacity: 0.9;
  padding: 12px 16px;
  background: rgba(255, 255, 255, 0.1);
  border-radius: 12px;
  transition: all 0.3s ease;
}

.feature-item:hover {
  background: rgba(255, 255, 255, 0.2);
  transform: translateX(5px);
}

.feature-icon {
  font-size: 20px;
}

/* 右侧登录表单 */
.login-box {
  flex: 1;
  padding: 50px 40px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  background: rgba(255, 255, 255, 0.95);
}

.login-header {
  margin-bottom: 36px;
}

.login-header h2 {
  font-size: 28px;
  font-weight: 700;
  color: #1a1a2e;
  margin-bottom: 8px;
}

.login-header p {
  color: #666;
  font-size: 14px;
}

.login-form {
  width: 100%;
}

.custom-input :deep(.el-input__wrapper) {
  padding: 0 16px;
  height: 50px;
  border-radius: 12px;
  background: #f5f7fa;
  box-shadow: none;
  border: 2px solid transparent;
  transition: all 0.3s ease;
}

.custom-input :deep(.el-input__wrapper:hover) {
  background: #fff;
  border-color: #667eea;
}

.custom-input :deep(.el-input__wrapper.is-focus) {
  background: #fff;
  border-color: #667eea;
  box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
}

.input-icon {
  font-size: 18px;
  color: #999;
}

.form-options {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
}

.forgot-link {
  color: #667eea;
  font-size: 13px;
  text-decoration: none;
  transition: color 0.3s;
}

.forgot-link:hover {
  color: #764ba2;
}

.login-btn {
  width: 100%;
  height: 50px;
  font-size: 16px;
  font-weight: 600;
  border-radius: 12px;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  border: none;
  transition: all 0.3s ease;
  letter-spacing: 4px;
}

.login-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
}

.login-btn:active {
  transform: translateY(0);
}

/* TOTP 输入框样式 */
.totp-input :deep(.el-input__inner) {
  letter-spacing: 8px;
  font-size: 20px;
  text-align: center;
  font-family: 'Courier New', monospace;
}

.totp-hint {
  font-size: 12px;
  color: rgba(255, 255, 255, 0.6);
  margin-top: 8px;
  text-align: center;
}

.login-footer {
  margin-top: 32px;
  text-align: center;
}

.login-footer p {
  font-size: 12px;
  color: #999;
}

/* 响应式 */
@media (max-width: 768px) {
  .login-wrapper {
    flex-direction: column;
    width: 90%;
    max-width: 400px;
    min-height: auto;
  }
  
  .login-banner {
    padding: 30px;
  }
  
  .banner-content h1 {
    font-size: 24px;
  }
  
  .features {
    display: none;
  }
  
  .login-box {
    padding: 30px;
  }
}
</style>
