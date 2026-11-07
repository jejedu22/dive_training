import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import App from './App.vue'
import { getToken } from './api'
import { useAuth } from './stores/auth'
import './style.css'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', component: () => import('./views/LoginView.vue'), meta: { public: true } },
    { path: '/', redirect: '/planning' },
    { path: '/planning', component: () => import('./views/PlanningView.vue') },
    { path: '/sections', component: () => import('./views/SectionsView.vue') },
    { path: '/sessions/:id', component: () => import('./views/SessionView.vue'), props: true },
    { path: '/pools', component: () => import('./views/PoolsView.vue'), meta: { referent: true } },
    { path: '/slots', component: () => import('./views/SlotsView.vue') },
    { path: '/users', component: () => import('./views/UsersView.vue'), meta: { admin: true } },
    { path: '/account', component: () => import('./views/AccountView.vue') },
  ],
})

const pinia = createPinia()
const app = createApp(App).use(pinia).use(router)

router.beforeEach(async (to) => {
  if (to.meta.public) return true
  if (!getToken()) return '/login'
  const auth = useAuth(pinia)
  try {
    await auth.fetchMe()
  } catch {
    auth.logout()
    return '/login'
  }
  if (to.meta.admin && !auth.isAdmin) return '/planning'
  if (to.meta.referent && !auth.isReferent) return '/planning'
  return true
})

app.mount('#app')
