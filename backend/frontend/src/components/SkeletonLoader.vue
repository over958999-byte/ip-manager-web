<template>
  <div class="skeleton-wrapper">
    <!-- 卡片骨架屏 -->
    <template v-if="type === 'card'">
      <div class="skeleton-card" v-for="n in count" :key="n">
        <div class="skeleton-header">
          <div class="skeleton-avatar skeleton-animate"></div>
          <div class="skeleton-title-group">
            <div class="skeleton-title skeleton-animate"></div>
            <div class="skeleton-subtitle skeleton-animate"></div>
          </div>
        </div>
        <div class="skeleton-content">
          <div class="skeleton-line skeleton-animate" style="width: 100%"></div>
          <div class="skeleton-line skeleton-animate" style="width: 80%"></div>
          <div class="skeleton-line skeleton-animate" style="width: 60%"></div>
        </div>
      </div>
    </template>

    <!-- 表格骨架屏 -->
    <template v-else-if="type === 'table'">
      <div class="skeleton-table">
        <div class="skeleton-table-header">
          <div class="skeleton-cell skeleton-animate" v-for="n in columns" :key="n"></div>
        </div>
        <div class="skeleton-table-row" v-for="row in rows" :key="row">
          <div class="skeleton-cell skeleton-animate" v-for="n in columns" :key="n"></div>
        </div>
      </div>
    </template>

    <!-- 统计卡片骨架屏 -->
    <template v-else-if="type === 'stats'">
      <div class="skeleton-stats">
        <div class="skeleton-stat-card" v-for="n in count" :key="n">
          <div class="skeleton-stat-icon skeleton-animate"></div>
          <div class="skeleton-stat-content">
            <div class="skeleton-stat-value skeleton-animate"></div>
            <div class="skeleton-stat-label skeleton-animate"></div>
          </div>
        </div>
      </div>
    </template>

    <!-- 列表骨架屏 -->
    <template v-else-if="type === 'list'">
      <div class="skeleton-list">
        <div class="skeleton-list-item" v-for="n in count" :key="n">
          <div class="skeleton-list-avatar skeleton-animate"></div>
          <div class="skeleton-list-content">
            <div class="skeleton-line skeleton-animate" style="width: 60%"></div>
            <div class="skeleton-line skeleton-animate" style="width: 40%"></div>
          </div>
        </div>
      </div>
    </template>

    <!-- 图表骨架屏 -->
    <template v-else-if="type === 'chart'">
      <div class="skeleton-chart">
        <div class="skeleton-chart-title skeleton-animate"></div>
        <div class="skeleton-chart-body skeleton-animate"></div>
      </div>
    </template>

    <!-- 默认文本骨架屏 -->
    <template v-else>
      <div class="skeleton-text" v-for="n in count" :key="n">
        <div class="skeleton-line skeleton-animate" :style="{ width: getRandomWidth() }"></div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
interface Props {
  type?: 'card' | 'table' | 'stats' | 'list' | 'chart' | 'text'
  count?: number
  rows?: number
  columns?: number
}

const props = withDefaults(defineProps<Props>(), {
  type: 'text',
  count: 3,
  rows: 5,
  columns: 4,
})

function getRandomWidth(): string {
  const widths = ['100%', '90%', '80%', '70%', '60%']
  return widths[Math.floor(Math.random() * widths.length)]
}
</script>

<style scoped lang="scss">
.skeleton-wrapper {
  width: 100%;
}

// 动画
.skeleton-animate {
  background: linear-gradient(
    90deg,
    #f0f0f0 25%,
    #e0e0e0 50%,
    #f0f0f0 75%
  );
  background-size: 200% 100%;
  animation: skeleton-loading 1.5s infinite;
}

@keyframes skeleton-loading {
  0% {
    background-position: 200% 0;
  }
  100% {
    background-position: -200% 0;
  }
}

// 基础元素
.skeleton-line {
  height: 16px;
  border-radius: 4px;
  margin-bottom: 12px;
}

.skeleton-avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
}

// 卡片骨架
.skeleton-card {
  background: #fff;
  border-radius: 8px;
  padding: 20px;
  margin-bottom: 16px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);

  .skeleton-header {
    display: flex;
    align-items: center;
    margin-bottom: 16px;
  }

  .skeleton-title-group {
    flex: 1;
    margin-left: 12px;
  }

  .skeleton-title {
    height: 20px;
    width: 120px;
    border-radius: 4px;
    margin-bottom: 8px;
  }

  .skeleton-subtitle {
    height: 14px;
    width: 80px;
    border-radius: 4px;
  }
}

// 表格骨架
.skeleton-table {
  background: #fff;
  border-radius: 8px;
  overflow: hidden;

  .skeleton-table-header {
    display: flex;
    padding: 16px;
    background: #fafafa;
    border-bottom: 1px solid #f0f0f0;
  }

  .skeleton-table-row {
    display: flex;
    padding: 16px;
    border-bottom: 1px solid #f0f0f0;

    &:last-child {
      border-bottom: none;
    }
  }

  .skeleton-cell {
    flex: 1;
    height: 20px;
    margin-right: 16px;
    border-radius: 4px;

    &:last-child {
      margin-right: 0;
    }
  }
}

// 统计卡片骨架
.skeleton-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;

  .skeleton-stat-card {
    background: #fff;
    border-radius: 8px;
    padding: 20px;
    display: flex;
    align-items: center;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
  }

  .skeleton-stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 8px;
    margin-right: 16px;
  }

  .skeleton-stat-content {
    flex: 1;
  }

  .skeleton-stat-value {
    height: 28px;
    width: 80px;
    border-radius: 4px;
    margin-bottom: 8px;
  }

  .skeleton-stat-label {
    height: 14px;
    width: 60px;
    border-radius: 4px;
  }
}

// 列表骨架
.skeleton-list {
  .skeleton-list-item {
    display: flex;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #f0f0f0;

    &:last-child {
      border-bottom: none;
    }
  }

  .skeleton-list-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    margin-right: 12px;
  }

  .skeleton-list-content {
    flex: 1;

    .skeleton-line {
      margin-bottom: 8px;

      &:last-child {
        margin-bottom: 0;
      }
    }
  }
}

// 图表骨架
.skeleton-chart {
  background: #fff;
  border-radius: 8px;
  padding: 20px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);

  .skeleton-chart-title {
    height: 24px;
    width: 150px;
    border-radius: 4px;
    margin-bottom: 20px;
  }

  .skeleton-chart-body {
    height: 300px;
    border-radius: 8px;
  }
}

// 暗色模式
:root.dark {
  .skeleton-animate {
    background: linear-gradient(
      90deg,
      #2a2a2a 25%,
      #3a3a3a 50%,
      #2a2a2a 75%
    );
    background-size: 200% 100%;
  }

  .skeleton-card,
  .skeleton-stat-card,
  .skeleton-chart {
    background: #1a1a1a;
  }

  .skeleton-table {
    background: #1a1a1a;

    .skeleton-table-header {
      background: #252525;
    }

    .skeleton-table-row {
      border-color: #333;
    }
  }
}
</style>
