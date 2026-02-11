/**
 * 用户类型定义
 */
export interface User {
  id: number
  username: string
  role: 'admin' | 'operator' | 'viewer'
  email?: string
  totp_enabled: boolean
  last_login_at?: string
  created_at: string
}

/**
 * 登录表单
 */
export interface LoginForm {
  username: string
  password: string
  totp_code?: string
  remember?: boolean
}

/**
 * 登录响应
 */
export interface LoginResponse {
  success: boolean
  data: {
    token: string
    user: User
    expires_at: string
  }
  error?: string
}

/**
 * API 响应基础类型
 */
export interface ApiResponse<T = any> {
  success: boolean
  data?: T
  error?: string
  message?: string
}

/**
 * 分页响应
 */
export interface PaginatedResponse<T> {
  success: boolean
  data: {
    items: T[]
    total: number
    page: number
    per_page: number
    total_pages: number
  }
}
