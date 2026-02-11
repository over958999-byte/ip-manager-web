/**
 * 表单验证规则
 */

/**
 * 验证邮箱
 * @param {string} email
 * @returns {boolean}
 */
export function isEmail(email) {
  const reg = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/
  return reg.test(email)
}

/**
 * 验证手机号
 * @param {string} phone
 * @returns {boolean}
 */
export function isPhone(phone) {
  const reg = /^1[3-9]\d{9}$/
  return reg.test(phone)
}

/**
 * 验证URL
 * @param {string} url
 * @returns {boolean}
 */
export function isURL(url) {
  const reg = /^(https?|ftp):\/\/[^\s/$.?#].[^\s]*$/i
  return reg.test(url)
}

/**
 * 验证域名
 * @param {string} domain
 * @returns {boolean}
 */
export function isDomain(domain) {
  const reg = /^(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.[A-Za-z]{2,})+$/
  return reg.test(domain)
}

/**
 * 验证IP地址
 * @param {string} ip
 * @returns {boolean}
 */
export function isIP(ip) {
  const ipv4Reg = /^(\d{1,3}\.){3}\d{1,3}$/
  const ipv6Reg = /^([0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}$/
  
  if (ipv4Reg.test(ip)) {
    const parts = ip.split('.')
    return parts.every(part => parseInt(part) <= 255)
  }
  
  return ipv6Reg.test(ip)
}

/**
 * 验证CIDR
 * @param {string} cidr
 * @returns {boolean}
 */
export function isCIDR(cidr) {
  const [ip, prefix] = cidr.split('/')
  if (!isIP(ip)) return false
  
  const prefixNum = parseInt(prefix)
  return prefixNum >= 0 && prefixNum <= 32
}

/**
 * 验证端口号
 * @param {string|number} port
 * @returns {boolean}
 */
export function isPort(port) {
  const num = parseInt(port)
  return Number.isInteger(num) && num >= 0 && num <= 65535
}

/**
 * 验证用户名
 * @param {string} username
 * @returns {boolean}
 */
export function isUsername(username) {
  const reg = /^[a-zA-Z][a-zA-Z0-9_]{2,19}$/
  return reg.test(username)
}

/**
 * 验证密码强度
 * @param {string} password
 * @returns {Object}
 */
export function checkPasswordStrength(password) {
  const result = {
    valid: false,
    strength: 0,
    message: ''
  }
  
  if (!password) {
    result.message = '密码不能为空'
    return result
  }
  
  if (password.length < 8) {
    result.message = '密码长度至少8位'
    return result
  }
  
  // 计算强度
  let strength = 0
  if (password.length >= 8) strength++
  if (password.length >= 12) strength++
  if (/[a-z]/.test(password)) strength++
  if (/[A-Z]/.test(password)) strength++
  if (/[0-9]/.test(password)) strength++
  if (/[!@#$%^&*()_+\-=[\]{};':"\\|,.<>/?]/.test(password)) strength++
  
  result.strength = Math.min(strength, 4)
  result.valid = strength >= 3
  
  const messages = ['弱', '弱', '中', '强', '非常强']
  result.message = messages[result.strength]
  
  return result
}

/**
 * 验证身份证号
 * @param {string} idCard
 * @returns {boolean}
 */
export function isIdCard(idCard) {
  const reg = /(^\d{15}$)|(^\d{18}$)|(^\d{17}(\d|X|x)$)/
  return reg.test(idCard)
}

/**
 * 验证纯数字
 * @param {string} str
 * @returns {boolean}
 */
export function isNumber(str) {
  return /^\d+$/.test(str)
}

/**
 * 验证纯字母
 * @param {string} str
 * @returns {boolean}
 */
export function isAlpha(str) {
  return /^[a-zA-Z]+$/.test(str)
}

/**
 * 验证字母数字
 * @param {string} str
 * @returns {boolean}
 */
export function isAlphanumeric(str) {
  return /^[a-zA-Z0-9]+$/.test(str)
}

/**
 * 验证JSON字符串
 * @param {string} str
 * @returns {boolean}
 */
export function isJSON(str) {
  try {
    JSON.parse(str)
    return true
  } catch {
    return false
  }
}

// Element Plus 表单验证规则
export const formRules = {
  required: (message = '此项为必填项') => ({
    required: true,
    message,
    trigger: 'blur'
  }),
  
  email: (message = '请输入正确的邮箱地址') => ({
    validator: (rule, value, callback) => {
      if (!value || isEmail(value)) {
        callback()
      } else {
        callback(new Error(message))
      }
    },
    trigger: 'blur'
  }),
  
  phone: (message = '请输入正确的手机号') => ({
    validator: (rule, value, callback) => {
      if (!value || isPhone(value)) {
        callback()
      } else {
        callback(new Error(message))
      }
    },
    trigger: 'blur'
  }),
  
  url: (message = '请输入正确的URL') => ({
    validator: (rule, value, callback) => {
      if (!value || isURL(value)) {
        callback()
      } else {
        callback(new Error(message))
      }
    },
    trigger: 'blur'
  }),
  
  domain: (message = '请输入正确的域名') => ({
    validator: (rule, value, callback) => {
      if (!value || isDomain(value)) {
        callback()
      } else {
        callback(new Error(message))
      }
    },
    trigger: 'blur'
  }),
  
  ip: (message = '请输入正确的IP地址') => ({
    validator: (rule, value, callback) => {
      if (!value || isIP(value)) {
        callback()
      } else {
        callback(new Error(message))
      }
    },
    trigger: 'blur'
  }),
  
  port: (message = '请输入正确的端口号 (0-65535)') => ({
    validator: (rule, value, callback) => {
      if (!value || isPort(value)) {
        callback()
      } else {
        callback(new Error(message))
      }
    },
    trigger: 'blur'
  }),
  
  username: (message = '用户名以字母开头，3-20位字母数字下划线') => ({
    validator: (rule, value, callback) => {
      if (!value || isUsername(value)) {
        callback()
      } else {
        callback(new Error(message))
      }
    },
    trigger: 'blur'
  }),
  
  password: (message = '密码至少8位，包含字母和数字') => ({
    validator: (rule, value, callback) => {
      const result = checkPasswordStrength(value)
      if (!value || result.valid) {
        callback()
      } else {
        callback(new Error(result.message || message))
      }
    },
    trigger: 'blur'
  }),
  
  min: (min, message) => ({
    min,
    message: message || `长度不能少于${min}个字符`,
    trigger: 'blur'
  }),
  
  max: (max, message) => ({
    max,
    message: message || `长度不能超过${max}个字符`,
    trigger: 'blur'
  }),
  
  range: (min, max, message) => ({
    min,
    max,
    message: message || `长度应在${min}到${max}个字符之间`,
    trigger: 'blur'
  }),
  
  pattern: (pattern, message) => ({
    pattern,
    message,
    trigger: 'blur'
  })
}
