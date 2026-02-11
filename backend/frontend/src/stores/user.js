import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '../api'

export const useUserStore = defineStore('user', () => {
  const isLoggedIn = ref(false)
  const username = ref('')

  async function login(user, password, totpCode = '') {
    const res = await api.login(user, password, totpCode)
    if (res.success && !res.data?.require_totp) {
      isLoggedIn.value = true
      username.value = user || 'admin'
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
      isLoggedIn.value = res.logged_in
      if (res.logged_in && res.username) {
        username.value = res.username
      }
      return res.logged_in
    } catch (e) {
      isLoggedIn.value = false
      return false
    }
  }

  return {
    isLoggedIn,
    username,
    login,
    logout,
    checkLogin
  }
})
