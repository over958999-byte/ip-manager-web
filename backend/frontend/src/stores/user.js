import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '../api'

export const useUserStore = defineStore('user', () => {
  const isLoggedIn = ref(false)
  const username = ref('')
  const role = ref('admin') // 单用户系统，登录用户即为管理员

  async function login(user, password, totpCode = '', remember = false) {
    const res = await api.login(user, password, totpCode, remember)
    if (res.success && !res.data?.require_totp) {
      isLoggedIn.value = true
      username.value = user || 'admin'
      role.value = 'admin'
    }
    return res
  }

  async function logout() {
    await api.logout()
    isLoggedIn.value = false
    username.value = ''
  }

  async function checkLogin() {
    try {
      const res = await api.checkLogin()
      const loggedIn = res.data?.logged_in || res.logged_in || false
      isLoggedIn.value = loggedIn
      if (loggedIn) {
        username.value = res.data?.username || res.username || 'admin'
        role.value = 'admin' // 登录成功即为管理员
      }
      return loggedIn
    } catch (e) {
      isLoggedIn.value = false
      return false
    }
  }

  return {
    isLoggedIn,
    username,
    role,
    login,
    logout,
    checkLogin
  }
})
