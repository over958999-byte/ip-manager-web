<template>
  <div class="error-page">
    <div class="error-code">
      <span>4</span>
      <span class="zero">0</span>
      <span>4</span>
    </div>
    <div class="error-content">
      <h2>抱歉，页面走丢了</h2>
      <p>您访问的页面不存在或已被移除</p>
      <div class="error-actions">
        <el-button type="primary" @click="goHome">
          <el-icon><HomeFilled /></el-icon>
          返回首页
        </el-button>
        <el-button @click="goBack">
          <el-icon><Back /></el-icon>
          返回上页
        </el-button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { useRouter } from 'vue-router'
import { HomeFilled, Back } from '@element-plus/icons-vue'

const router = useRouter()

const goHome = () => {
  router.push('/')
}

const goBack = () => {
  router.go(-1)
}
</script>

<style lang="scss" scoped>
.error-page {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.error-code {
  display: flex;
  align-items: center;
  margin-bottom: 40px;

  span {
    font-size: 160px;
    font-weight: 700;
    color: rgba(255, 255, 255, 0.9);
    text-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
    animation: float 3s ease-in-out infinite;

    &:nth-child(2) {
      animation-delay: 0.2s;
    }

    &:nth-child(3) {
      animation-delay: 0.4s;
    }
  }

  .zero {
    color: rgba(255, 255, 255, 0.5);
  }
}

.error-content {
  text-align: center;
  color: white;

  h2 {
    font-size: 32px;
    margin-bottom: 16px;
    font-weight: 500;
  }

  p {
    font-size: 16px;
    opacity: 0.8;
    margin-bottom: 32px;
  }
}

.error-actions {
  display: flex;
  gap: 16px;
  justify-content: center;

  .el-button {
    padding: 12px 24px;
    font-size: 14px;
  }
}

@keyframes float {
  0%, 100% {
    transform: translateY(0);
  }
  50% {
    transform: translateY(-20px);
  }
}
</style>
