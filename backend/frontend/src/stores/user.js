import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '../api'

export const useUserStore = defineStore('user', () => {
  const isLoggedIn = ref(false)

  async function login(password) {
    const res = await api.login(password)
    if (res.success) {
      isLoggedIn.value = true
    }
    return res
  }

  async function logout() {
    await api.logout()
    isLoggedIn.value = false
  }

  async function checkLogin() {
    try {
      const res = await api.checkLogin()
      isLoggedIn.value = res.logged_in
      return res.logged_in
    } catch (e) {
      isLoggedIn.value = false
      return false
    }
  }

  return {
    isLoggedIn,
    login,
    logout,
    checkLogin
  }
})
