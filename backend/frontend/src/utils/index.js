/**
 * 通用工具函数
 */

/**
 * URL参数解析
 * @param {string} url
 * @returns {Object}
 */
export function parseQuery(url) {
  const search = decodeURIComponent(url.split('?')[1] || '').replace(/\+/g, ' ')
  if (!search) return {}
  
  const query = {}
  const items = search.split('&')
  
  for (const item of items) {
    const [key, value] = item.split('=')
    query[key] = value
  }
  
  return query
}

/**
 * 获取URL参数
 * @param {string} url
 * @param {string} key
 * @returns {string}
 */
export function getQueryParam(url, key) {
  const query = parseQuery(url)
  return query[key] || ''
}

/**
 * 对象转URL参数
 * @param {Object} obj
 * @returns {string}
 */
export function objectToQuery(obj) {
  if (!obj) return ''
  
  const pairs = []
  for (const key in obj) {
    if (Object.hasOwn(obj, key) && obj[key] !== undefined && obj[key] !== null) {
      pairs.push(`${encodeURIComponent(key)}=${encodeURIComponent(obj[key])}`)
    }
  }
  
  return pairs.join('&')
}

/**
 * 深拷贝
 * @param {*} source
 * @returns {*}
 */
export function deepClone(source) {
  if (!source || typeof source !== 'object') {
    return source
  }

  if (source instanceof Date) {
    return new Date(source.getTime())
  }

  if (Array.isArray(source)) {
    return source.map(item => deepClone(item))
  }

  const target = {}
  for (const key in source) {
    if (Object.hasOwn(source, key)) {
      target[key] = deepClone(source[key])
    }
  }
  
  return target
}

/**
 * 防抖函数
 * @param {Function} func
 * @param {number} wait
 * @param {boolean} immediate
 * @returns {Function}
 */
export function debounce(func, wait = 300, immediate = false) {
  let timeout
  
  return function executedFunction(...args) {
    const later = () => {
      timeout = null
      if (!immediate) func.apply(this, args)
    }
    
    const callNow = immediate && !timeout
    clearTimeout(timeout)
    timeout = setTimeout(later, wait)
    
    if (callNow) func.apply(this, args)
  }
}

/**
 * 节流函数
 * @param {Function} func
 * @param {number} limit
 * @returns {Function}
 */
export function throttle(func, limit = 300) {
  let inThrottle
  
  return function executedFunction(...args) {
    if (!inThrottle) {
      func.apply(this, args)
      inThrottle = true
      setTimeout(() => (inThrottle = false), limit)
    }
  }
}

/**
 * 格式化时间
 * @param {Date|string|number} time
 * @param {string} format
 * @returns {string}
 */
export function formatTime(time, format = 'YYYY-MM-DD HH:mm:ss') {
  if (!time) return ''
  
  const date = time instanceof Date ? time : new Date(time)
  
  if (isNaN(date.getTime())) return ''
  
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  const hours = String(date.getHours()).padStart(2, '0')
  const minutes = String(date.getMinutes()).padStart(2, '0')
  const seconds = String(date.getSeconds()).padStart(2, '0')
  
  return format
    .replace('YYYY', year)
    .replace('MM', month)
    .replace('DD', day)
    .replace('HH', hours)
    .replace('mm', minutes)
    .replace('ss', seconds)
}

/**
 * 相对时间
 * @param {Date|string|number} time
 * @returns {string}
 */
export function timeAgo(time) {
  if (!time) return ''
  
  const date = time instanceof Date ? time : new Date(time)
  const now = new Date()
  const diff = now.getTime() - date.getTime()
  
  const seconds = Math.floor(diff / 1000)
  const minutes = Math.floor(seconds / 60)
  const hours = Math.floor(minutes / 60)
  const days = Math.floor(hours / 24)
  const months = Math.floor(days / 30)
  const years = Math.floor(days / 365)
  
  if (years > 0) return `${years}年前`
  if (months > 0) return `${months}个月前`
  if (days > 0) return `${days}天前`
  if (hours > 0) return `${hours}小时前`
  if (minutes > 0) return `${minutes}分钟前`
  return '刚刚'
}

/**
 * 格式化文件大小
 * @param {number} bytes
 * @param {number} decimals
 * @returns {string}
 */
export function formatBytes(bytes, decimals = 2) {
  if (bytes === 0) return '0 B'
  
  const k = 1024
  const dm = decimals < 0 ? 0 : decimals
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB']
  
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`
}

/**
 * 格式化数字（千分位）
 * @param {number} num
 * @returns {string}
 */
export function formatNumber(num) {
  if (num === null || num === undefined) return '0'
  return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',')
}

/**
 * 复制到剪贴板
 * @param {string} text
 * @returns {Promise<boolean>}
 */
export async function copyToClipboard(text) {
  try {
    await navigator.clipboard.writeText(text)
    return true
  } catch {
    // 降级方案
    const textarea = document.createElement('textarea')
    textarea.value = text
    textarea.style.position = 'fixed'
    textarea.style.opacity = '0'
    document.body.appendChild(textarea)
    textarea.select()
    
    try {
      document.execCommand('copy')
      return true
    } catch {
      return false
    } finally {
      document.body.removeChild(textarea)
    }
  }
}

/**
 * 下载文件
 * @param {Blob|string} data
 * @param {string} filename
 */
export function downloadFile(data, filename) {
  const blob = data instanceof Blob ? data : new Blob([data])
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  
  link.href = url
  link.download = filename
  link.style.display = 'none'
  
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  
  URL.revokeObjectURL(url)
}

/**
 * 生成唯一ID
 * @returns {string}
 */
export function generateId() {
  return Date.now().toString(36) + Math.random().toString(36).substring(2)
}

/**
 * 获取数据类型
 * @param {*} value
 * @returns {string}
 */
export function getType(value) {
  return Object.prototype.toString.call(value).slice(8, -1).toLowerCase()
}

/**
 * 判断是否为空
 * @param {*} value
 * @returns {boolean}
 */
export function isEmpty(value) {
  if (value === null || value === undefined) return true
  if (typeof value === 'string') return value.trim() === ''
  if (Array.isArray(value)) return value.length === 0
  if (typeof value === 'object') return Object.keys(value).length === 0
  return false
}

/**
 * 首字母大写
 * @param {string} str
 * @returns {string}
 */
export function capitalize(str) {
  if (!str) return ''
  return str.charAt(0).toUpperCase() + str.slice(1)
}

/**
 * 驼峰转短横线
 * @param {string} str
 * @returns {string}
 */
export function kebabCase(str) {
  if (!str) return ''
  return str.replace(/([A-Z])/g, '-$1').toLowerCase().replace(/^-/, '')
}

/**
 * 短横线转驼峰
 * @param {string} str
 * @returns {string}
 */
export function camelCase(str) {
  if (!str) return ''
  return str.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase())
}

/**
 * 生成随机颜色
 * @returns {string}
 */
export function randomColor() {
  return '#' + Math.floor(Math.random() * 16777215).toString(16).padStart(6, '0')
}

/**
 * 树形数据转换
 * @param {Array} list
 * @param {Object} options
 * @returns {Array}
 */
export function listToTree(list, options = {}) {
  const { id = 'id', parentId = 'parentId', children = 'children' } = options
  
  const map = new Map()
  const result = []
  
  // 先建立映射
  for (const item of list) {
    map.set(item[id], { ...item, [children]: [] })
  }
  
  // 构建树形结构
  for (const item of list) {
    const node = map.get(item[id])
    const parent = map.get(item[parentId])
    
    if (parent) {
      parent[children].push(node)
    } else {
      result.push(node)
    }
  }
  
  return result
}

/**
 * 树形数据扁平化
 * @param {Array} tree
 * @param {string} childrenKey
 * @returns {Array}
 */
export function treeToList(tree, childrenKey = 'children') {
  const result = []
  
  const traverse = (nodes) => {
    for (const node of nodes) {
      const { [childrenKey]: children, ...rest } = node
      result.push(rest)
      if (children?.length) {
        traverse(children)
      }
    }
  }
  
  traverse(tree)
  return result
}
