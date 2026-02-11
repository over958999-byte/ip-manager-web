import Cookies from 'js-cookie'
import settings from '@/settings'

const TokenKey = settings.tokenKey || 'ip_manager_token'

export function getToken() {
  return Cookies.get(TokenKey)
}

export function setToken(token) {
  return Cookies.set(TokenKey, token, { expires: 7 })
}

export function removeToken() {
  return Cookies.remove(TokenKey)
}

// 用户信息存储
const UserInfoKey = 'ip_manager_user_info'

export function getUserInfo() {
  const info = localStorage.getItem(UserInfoKey)
  return info ? JSON.parse(info) : null
}

export function setUserInfo(info) {
  return localStorage.setItem(UserInfoKey, JSON.stringify(info))
}

export function removeUserInfo() {
  return localStorage.removeItem(UserInfoKey)
}

// 记住登录
export function getRememberLogin() {
  return Cookies.get('remember_login') === 'true'
}

export function setRememberLogin(value) {
  return Cookies.set('remember_login', value ? 'true' : 'false', { expires: 30 })
}

export function getRememberedUsername() {
  return Cookies.get('remembered_username') || ''
}

export function setRememberedUsername(username) {
  return Cookies.set('remembered_username', username, { expires: 30 })
}

export function removeRememberedUsername() {
  return Cookies.remove('remembered_username')
}
