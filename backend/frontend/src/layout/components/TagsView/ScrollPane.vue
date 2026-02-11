<template>
  <el-scrollbar ref="scrollContainer" :vertical="false" class="scroll-container" @wheel.prevent="handleScroll">
    <slot />
  </el-scrollbar>
</template>

<script setup>
import { ref, computed, getCurrentInstance } from 'vue'

const tagAndTagSpacing = 4

const scrollContainer = ref(null)

const scrollWrapper = computed(() => {
  return scrollContainer.value?.wrapRef
})

const emit = defineEmits(['scroll'])

const handleScroll = (e) => {
  const eventDelta = e.wheelDelta || -e.deltaY * 40
  const $scrollWrapper = scrollWrapper.value
  $scrollWrapper.scrollLeft = $scrollWrapper.scrollLeft + eventDelta / 4
}

const moveToTarget = (currentTag) => {
  const $container = scrollContainer.value.$el
  const $containerWidth = $container.offsetWidth
  const $scrollWrapper = scrollWrapper.value
  
  const tagList = $scrollWrapper.querySelectorAll('.tags-view-item')
  
  let firstTag = null
  let lastTag = null
  
  if (tagList.length > 0) {
    firstTag = tagList[0]
    lastTag = tagList[tagList.length - 1]
  }
  
  if (firstTag === currentTag) {
    $scrollWrapper.scrollLeft = 0
  } else if (lastTag === currentTag) {
    $scrollWrapper.scrollLeft = $scrollWrapper.scrollWidth - $containerWidth
  } else {
    const currentIndex = [...tagList].findIndex(item => item === currentTag)
    const prevTag = tagList[currentIndex - 1]
    const nextTag = tagList[currentIndex + 1]
    
    const afterNextTagOffsetLeft = nextTag.offsetLeft + nextTag.offsetWidth + tagAndTagSpacing
    const beforePrevTagOffsetLeft = prevTag.offsetLeft - tagAndTagSpacing
    
    if (afterNextTagOffsetLeft > $scrollWrapper.scrollLeft + $containerWidth) {
      $scrollWrapper.scrollLeft = afterNextTagOffsetLeft - $containerWidth
    } else if (beforePrevTagOffsetLeft < $scrollWrapper.scrollLeft) {
      $scrollWrapper.scrollLeft = beforePrevTagOffsetLeft
    }
  }
}

defineExpose({
  moveToTarget
})
</script>

<style lang="scss" scoped>
.scroll-container {
  white-space: nowrap;
  position: relative;
  overflow: hidden;
  width: 100%;

  :deep(.el-scrollbar__bar) {
    bottom: 0;
  }

  :deep(.el-scrollbar__wrap) {
    height: 49px;
  }
}
</style>
