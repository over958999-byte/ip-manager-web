<template>
  <div class="sidebar-logo-container" :class="{ collapse: collapse }">
    <router-link key="collapse" class="sidebar-logo-link" to="/">
      <img v-if="logo" :src="logo" class="sidebar-logo" alt="logo" />
      <h1 v-if="!collapse" class="sidebar-title">{{ title }}</h1>
    </router-link>
  </div>
</template>

<script setup>
import { ref } from 'vue'

defineProps({
  collapse: {
    type: Boolean,
    required: true
  }
})

const title = ref('困King分发')
const logo = ref('') // 可以设置logo图片URL
</script>

<style lang="scss" scoped>
.sidebar-logo-container {
  position: relative;
  width: 100%;
  height: 50px;
  line-height: 50px;
  background: #2b2f3a;
  text-align: center;
  overflow: hidden;

  & .sidebar-logo-link {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    width: 100%;

    & .sidebar-logo {
      width: 32px;
      height: 32px;
      vertical-align: middle;
    }

    & .sidebar-title {
      display: inline-block;
      margin: 0;
      margin-left: 12px;
      color: #fff;
      font-weight: 600;
      line-height: 50px;
      font-size: 16px;
      vertical-align: middle;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
  }

  &.collapse {
    .sidebar-logo {
      margin-right: 0;
    }
  }
}
</style>
