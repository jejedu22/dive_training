import { defineStore } from 'pinia'
import { get, post, setToken, getToken } from '../api'
import type { User } from '../types'

export const useAuth = defineStore('auth', {
  state: () => ({ user: null as User | null }),
  getters: { isReferent: (s) => !!s.user?.referent, isAdmin: (s) => !!s.user?.admin },
  actions: {
    async login(email: string, password: string) {
      const { token } = await post<{ token: string }>('/login_check', { email: email.trim().toLowerCase(), password })
      setToken(token)
      await this.fetchMe()
    },
    async fetchMe() {
      if (getToken() && !this.user) this.user = await get<User>('/me')
    },
    setUser(u: User) {
      this.user = u
    },
    logout() {
      setToken(null)
      this.user = null
    },
  },
})
