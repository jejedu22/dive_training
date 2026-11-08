import { defineStore } from 'pinia'
import { get, post, put, setToken, getToken } from '../api'
import type { Preferences, User } from '../types'

export const useAuth = defineStore('auth', {
  state: () => ({ user: null as User | null }),
  getters: {
    isReferent: (s) => !!s.user?.referent,
    isAdmin: (s) => !!s.user?.admin,
    /** Préférences de l'utilisateur (valeurs par défaut si non connecté). */
    prefs: (s): Preferences => s.user?.preferences ?? { homePage: 'occupation', planningView: 'day', onlyMine: false },
  },
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
    /** Met à jour le profil ou les préférences. Un changement d'e-mail renvoie un nouveau jeton de connexion. */
    async updateProfile(changes: Record<string, unknown>) {
      const u = await put<User & { token?: string }>('/me', changes)
      if (u.token) setToken(u.token)
      delete u.token
      this.user = u
      return u
    },
    logout() {
      setToken(null)
      this.user = null
    },
  },
})
