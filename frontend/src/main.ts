import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { createRouter, createWebHistory } from 'vue-router'
import App from './App.vue'
import { getToken } from './api'
import { useAuth } from './stores/auth'
import './style.css'
import './pwa'

const router = createRouter({
  history: createWebHistory(),
  scrollBehavior: () => ({ top: 0 }),
  routes: [
    { path: '/login', component: () => import('./views/LoginView.vue'), meta: { public: true, title: 'Connexion' } },
    { path: '/', component: { render: () => null } }, // redirigé vers la page d'accueil choisie (voir beforeEach)
    { path: '/occupation', component: () => import('./views/OccupationView.vue'), meta: { title: 'Lignes d\'eau' } },
    { path: '/planning', component: () => import('./views/PlanningView.vue'), meta: { title: 'Planning' } },
    { path: '/sections', component: () => import('./views/SectionsView.vue'), meta: { title: 'Sections' } },
    { path: '/sessions/:id', component: () => import('./views/SessionView.vue'), props: true, meta: { title: 'Séance' } },
    { path: '/slots', component: () => import('./views/SlotsView.vue'), meta: { title: 'Créneaux' } },
    { path: '/pools', component: () => import('./views/PoolsView.vue'), meta: { referent: true, title: 'Bassins' } },
    { path: '/users', component: () => import('./views/UsersView.vue'), meta: { admin: true, title: 'Comptes' } },
    { path: '/account', component: () => import('./views/AccountView.vue'), meta: { title: 'Mon compte' } },
    { path: '/:pathMatch(.*)*', redirect: '/occupation' },
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
  if (to.path === '/') return `/${auth.prefs.homePage}` // page d'accueil choisie dans « Mon compte »
  if (to.meta.admin && !auth.isAdmin) return '/occupation'
  if (to.meta.referent && !auth.isReferent) return '/occupation'
  return true
})
router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} · Dive Training` : 'Dive Training'
})

app.mount('#app')
