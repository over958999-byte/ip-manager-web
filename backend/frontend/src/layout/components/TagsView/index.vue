<template>
  <div id="tags-view-container" class="tags-view-container">
    <ScrollPane ref="scrollPane" class="tags-view-wrapper" @scroll="handleScroll">
      <router-link
        v-for="tag in visitedViews"
        :key="tag.path"
        :class="isActive(tag) ? 'active' : ''"
        :to="{ path: tag.path, query: tag.query, fullPath: tag.fullPath }"
        class="tags-view-item"
        @click.middle="!isAffix(tag) ? closeSelectedTag(tag) : ''"
        @contextmenu.prevent="openMenu(tag, $event)"
      >
        {{ tag.title }}
        <el-icon v-if="!isAffix(tag)" class="el-icon-close" @click.prevent.stop="closeSelectedTag(tag)">
          <Close />
        </el-icon>
      </router-link>
    </ScrollPane>
    
    <!-- 右键菜单 -->
    <ul v-show="visible" :style="{ left: left + 'px', top: top + 'px' }" class="contextmenu">
      <li @click="refreshSelectedTag(selectedTag)">
        <el-icon><Refresh /></el-icon> 刷新
      </li>
      <li v-if="!isAffix(selectedTag)" @click="closeSelectedTag(selectedTag)">
        <el-icon><Close /></el-icon> 关闭
      </li>
      <li @click="closeOthersTags">
        <el-icon><CircleClose /></el-icon> 关闭其他
      </li>
      <li @click="closeAllTags(selectedTag)">
        <el-icon><FolderDelete /></el-icon> 关闭全部
      </li>
    </ul>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTagsViewStore, usePermissionStore } from '@/stores'
import ScrollPane from './ScrollPane.vue'
import path from 'path-browserify'

const route = useRoute()
const router = useRouter()
const tagsViewStore = useTagsViewStore()
const permissionStore = usePermissionStore()

const visible = ref(false)
const top = ref(0)
const left = ref(0)
const selectedTag = ref({})
const affixTags = ref([])
const scrollPane = ref(null)

const visitedViews = computed(() => tagsViewStore.visitedViews)
const routes = computed(() => permissionStore.routes)

// 判断是否激活
const isActive = (tag) => {
  return tag.path === route.path
}

// 判断是否固定
const isAffix = (tag) => {
  return tag.affix
}

// 过滤固定标签
const filterAffixTags = (routes, basePath = '/') => {
  let tags = []
  routes.forEach((route) => {
    if (route.meta && route.meta.affix) {
      const tagPath = path.resolve(basePath, route.path)
      tags.push({
        fullPath: tagPath,
        path: tagPath,
        name: route.name,
        meta: { ...route.meta }
      })
    }
    if (route.children) {
      const tempTags = filterAffixTags(route.children, route.path)
      if (tempTags.length >= 1) {
        tags = [...tags, ...tempTags]
      }
    }
  })
  return tags
}

// 初始化标签
const initTags = () => {
  const affixTagsList = (affixTags.value = filterAffixTags(routes.value))
  for (const tag of affixTagsList) {
    if (tag.name) {
      tagsViewStore.addVisitedView(tag)
    }
  }
}

// 添加标签
const addTags = () => {
  const { name } = route
  if (name) {
    tagsViewStore.addView(route)
  }
  return false
}

// 移动到当前标签
const moveToCurrentTag = () => {
  nextTick(() => {
    for (const tag of visitedViews.value) {
      if (tag.path === route.path) {
        scrollPane.value?.moveToTarget(tag)
        if (tag.fullPath !== route.fullPath) {
          tagsViewStore.updateVisitedView(route)
        }
        break
      }
    }
  })
}

// 刷新选中标签
const refreshSelectedTag = (view) => {
  tagsViewStore.delCachedView(view)
  const { fullPath } = view
  nextTick(() => {
    router.replace({
      path: '/redirect' + fullPath
    })
  })
}

// 关闭选中标签
const closeSelectedTag = (view) => {
  tagsViewStore.delView(view).then(({ visitedViews }) => {
    if (isActive(view)) {
      toLastView(visitedViews, view)
    }
  })
}

// 关闭其他标签
const closeOthersTags = () => {
  router.push(selectedTag.value)
  tagsViewStore.delOthersViews(selectedTag.value)
}

// 关闭全部标签
const closeAllTags = (view) => {
  tagsViewStore.delAllViews().then(({ visitedViews }) => {
    if (affixTags.value.some((tag) => tag.path === view.path)) {
      return
    }
    toLastView(visitedViews, view)
  })
}

// 跳转到最后一个视图
const toLastView = (visitedViews, view) => {
  const latestView = visitedViews.slice(-1)[0]
  if (latestView) {
    router.push(latestView.fullPath)
  } else {
    // 如果没有视图则跳转首页
    if (view.name === 'Dashboard') {
      router.replace({ path: '/redirect' + view.fullPath })
    } else {
      router.push('/')
    }
  }
}

// 打开右键菜单
const openMenu = (tag, e) => {
  const menuMinWidth = 105
  const offsetLeft = e.currentTarget.getBoundingClientRect().left
  const offsetWidth = document.body.offsetWidth
  const maxLeft = offsetWidth - menuMinWidth
  const currentLeft = e.clientX + 15
  
  if (currentLeft > maxLeft) {
    left.value = maxLeft
  } else {
    left.value = currentLeft
  }
  
  top.value = e.clientY
  visible.value = true
  selectedTag.value = tag
}

// 关闭右键菜单
const closeMenu = () => {
  visible.value = false
}

// 滚动处理
const handleScroll = () => {
  closeMenu()
}

// 监听路由变化
watch(
  () => route.path,
  () => {
    addTags()
    moveToCurrentTag()
  }
)

watch(visible, (value) => {
  if (value) {
    document.body.addEventListener('click', closeMenu)
  } else {
    document.body.removeEventListener('click', closeMenu)
  }
})

onMounted(() => {
  initTags()
  addTags()
})
</script>

<style lang="scss" scoped>
.tags-view-container {
  height: 34px;
  width: 100%;
  background: #fff;
  border-bottom: 1px solid #d8dce5;
  box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.12), 0 0 3px 0 rgba(0, 0, 0, 0.04);

  .tags-view-wrapper {
    .tags-view-item {
      display: inline-block;
      position: relative;
      cursor: pointer;
      height: 26px;
      line-height: 26px;
      border: 1px solid #d8dce5;
      color: #495060;
      background: #fff;
      padding: 0 8px;
      font-size: 12px;
      margin-left: 5px;
      margin-top: 4px;

      &:first-of-type {
        margin-left: 15px;
      }

      &:last-of-type {
        margin-right: 15px;
      }

      &.active {
        background-color: #409eff;
        color: #fff;
        border-color: #409eff;

        &::before {
          content: '';
          background: #fff;
          display: inline-block;
          width: 8px;
          height: 8px;
          border-radius: 50%;
          position: relative;
          margin-right: 4px;
        }
      }

      .el-icon-close {
        width: 16px;
        height: 16px;
        border-radius: 50%;
        text-align: center;
        transition: all 0.3s cubic-bezier(0.645, 0.045, 0.355, 1);
        margin-left: 4px;
        font-size: 12px;
        vertical-align: middle;

        &:hover {
          background-color: #b4bccc;
          color: #fff;
        }
      }
    }
  }

  .contextmenu {
    margin: 0;
    background: #fff;
    z-index: 3000;
    position: absolute;
    list-style-type: none;
    padding: 5px 0;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 400;
    color: #333;
    box-shadow: 2px 2px 3px 0 rgba(0, 0, 0, 0.3);

    li {
      margin: 0;
      padding: 7px 16px;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 6px;

      &:hover {
        background: #eee;
      }
    }
  }
}
</style>
