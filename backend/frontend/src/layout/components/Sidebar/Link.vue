<template>
  <!-- 外部链接 -->
  <a v-if="isExternal(to)" :href="to" target="_blank" rel="noopener">
    <slot />
  </a>
  <!-- 内部路由 -->
  <router-link v-else :to="to">
    <slot />
  </router-link>
</template>

<script setup>
defineProps({
  to: {
    type: String,
    required: true
  }
})

const isExternal = (path) => {
  return /^(https?:|mailto:|tel:)/.test(path)
}
</script>
