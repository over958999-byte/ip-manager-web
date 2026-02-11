import { defineStore } from 'pinia'

const useTagsViewStore = defineStore('tagsView', {
  state: () => ({
    // 已访问的视图
    visitedViews: [],
    // 缓存的视图名称
    cachedViews: []
  }),
  
  actions: {
    // 添加视图
    addView(view) {
      this.addVisitedView(view)
      this.addCachedView(view)
    },
    
    // 添加已访问视图
    addVisitedView(view) {
      if (this.visitedViews.some(v => v.path === view.path)) return
      this.visitedViews.push({
        name: view.name,
        path: view.path,
        fullPath: view.fullPath || view.path,
        title: view.meta?.title || 'no-name',
        icon: view.meta?.icon,
        affix: view.meta?.affix || false
      })
    },
    
    // 添加缓存视图
    addCachedView(view) {
      if (this.cachedViews.includes(view.name)) return
      if (view.meta?.noCache !== true) {
        this.cachedViews.push(view.name)
      }
    },
    
    // 删除视图
    delView(view) {
      return new Promise(resolve => {
        this.delVisitedView(view)
        this.delCachedView(view)
        resolve({
          visitedViews: [...this.visitedViews],
          cachedViews: [...this.cachedViews]
        })
      })
    },
    
    // 删除已访问视图
    delVisitedView(view) {
      const index = this.visitedViews.findIndex(v => v.path === view.path)
      if (index > -1) {
        this.visitedViews.splice(index, 1)
      }
    },
    
    // 删除缓存视图
    delCachedView(view) {
      const index = this.cachedViews.indexOf(view.name)
      if (index > -1) {
        this.cachedViews.splice(index, 1)
      }
    },
    
    // 删除其他视图
    delOthersViews(view) {
      return new Promise(resolve => {
        this.visitedViews = this.visitedViews.filter(v => {
          return v.affix || v.path === view.path
        })
        this.cachedViews = this.cachedViews.filter(name => name === view.name)
        resolve({
          visitedViews: [...this.visitedViews],
          cachedViews: [...this.cachedViews]
        })
      })
    },
    
    // 删除所有视图
    delAllViews() {
      return new Promise(resolve => {
        // 保留固定标签
        this.visitedViews = this.visitedViews.filter(tag => tag.affix)
        this.cachedViews = []
        resolve({
          visitedViews: [...this.visitedViews],
          cachedViews: [...this.cachedViews]
        })
      })
    },
    
    // 删除左侧视图
    delLeftViews(view) {
      return new Promise(resolve => {
        const currentIndex = this.visitedViews.findIndex(v => v.path === view.path)
        if (currentIndex === -1) return
        
        this.visitedViews = this.visitedViews.filter((item, index) => {
          if (index >= currentIndex || item.affix) {
            return true
          }
          const cacheIndex = this.cachedViews.indexOf(item.name)
          if (cacheIndex > -1) {
            this.cachedViews.splice(cacheIndex, 1)
          }
          return false
        })
        
        resolve({
          visitedViews: [...this.visitedViews]
        })
      })
    },
    
    // 删除右侧视图
    delRightViews(view) {
      return new Promise(resolve => {
        const currentIndex = this.visitedViews.findIndex(v => v.path === view.path)
        if (currentIndex === -1) return
        
        this.visitedViews = this.visitedViews.filter((item, index) => {
          if (index <= currentIndex || item.affix) {
            return true
          }
          const cacheIndex = this.cachedViews.indexOf(item.name)
          if (cacheIndex > -1) {
            this.cachedViews.splice(cacheIndex, 1)
          }
          return false
        })
        
        resolve({
          visitedViews: [...this.visitedViews]
        })
      })
    },
    
    // 更新已访问视图
    updateVisitedView(view) {
      for (let v of this.visitedViews) {
        if (v.path === view.path) {
          v = Object.assign(v, view)
          break
        }
      }
    }
  }
})

export default useTagsViewStore
