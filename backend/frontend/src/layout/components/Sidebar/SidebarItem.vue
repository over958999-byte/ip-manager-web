<template>
  <div v-if="!item.hidden">
    <!-- 只有一个子路由或没有子路由时 -->
    <template v-if="hasOneShowingChild(item.children, item) && (!onlyOneChild.children || onlyOneChild.noShowingChildren) && !item.alwaysShow">
      <AppLink v-if="onlyOneChild.meta" :to="resolvePath(onlyOneChild.path)">
        <el-menu-item :index="resolvePath(onlyOneChild.path)" :class="{ 'submenu-title-noDropdown': !isNest }">
          <el-icon v-if="onlyOneChild.meta.icon || item.meta?.icon">
            <component :is="onlyOneChild.meta.icon || item.meta?.icon" />
          </el-icon>
          <template #title>
            <span>{{ onlyOneChild.meta.title }}</span>
          </template>
        </el-menu-item>
      </AppLink>
    </template>

    <!-- 多个子路由时显示子菜单 -->
    <el-sub-menu v-else :index="resolvePath(item.path)" popper-append-to-body>
      <template #title>
        <el-icon v-if="item.meta?.icon">
          <component :is="item.meta.icon" />
        </el-icon>
        <span>{{ item.meta?.title }}</span>
      </template>
      <SidebarItem
        v-for="child in item.children"
        :key="child.path"
        :is-nest="true"
        :item="child"
        :base-path="resolvePath(child.path)"
      />
    </el-sub-menu>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import path from 'path-browserify'
import AppLink from './Link.vue'

const props = defineProps({
  item: {
    type: Object,
    required: true
  },
  isNest: {
    type: Boolean,
    default: false
  },
  basePath: {
    type: String,
    default: ''
  }
})

const onlyOneChild = ref(null)

const hasOneShowingChild = (children = [], parent) => {
  const showingChildren = children.filter((item) => {
    if (item.hidden) {
      return false
    } else {
      // 暂存唯一子路由
      onlyOneChild.value = item
      return true
    }
  })

  // 只有一个子路由时，直接显示子路由
  if (showingChildren.length === 1) {
    return true
  }

  // 没有子路由时，显示父路由
  if (showingChildren.length === 0) {
    onlyOneChild.value = { ...parent, path: '', noShowingChildren: true }
    return true
  }

  return false
}

const resolvePath = (routePath) => {
  if (isExternal(routePath)) {
    return routePath
  }
  if (isExternal(props.basePath)) {
    return props.basePath
  }
  return path.resolve(props.basePath, routePath)
}

const isExternal = (path) => {
  return /^(https?:|mailto:|tel:)/.test(path)
}
</script>
