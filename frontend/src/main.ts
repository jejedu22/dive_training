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
    // planning partagé par lien : sans compte
    { path: '/partage/:token', component: () => import('./views/PublicPlanningView.vue'), props: true, meta: { public: true, title: 'Planning partagé' } },
    { path: '/', component: { render: () => null } }, // redirigé vers la page d'accueil choisie (voir beforeEach)
    { path: '/planning', component: () => import('./views/PlanningView.vue'), meta: { title: 'Planning' } },
    { path: '/occupation', redirect: '/planning' }, // ancienne page « Lignes d'eau »
    { path: '/sections', component: () => import('./views/SectionsView.vue'), meta: { title: 'Sections' } },
    { path: '/slots', component: () => import('./views/SlotsView.vue'), meta: { title: 'Créneaux' } },
    { path: '/pools', component: () => import('./views/PoolsView.vue'), meta: { referent: true, title: 'Bassins' } },
    { path: '/users', component: () => import('./views/UsersView.vue'), meta: { admin: true, title: 'Comptes' } },
    { path: '/account', component: () => import('./views/AccountView.vue'), meta: { title: 'Mon compte' } },
    { path: '/:pathMatch(.*)*', redirect: '/planning' },
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
  if (to.meta.admin && !auth.isAdmin) return '/planning'
  if (to.meta.referent && !auth.isReferent) return '/planning'
  return true
})
router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} · Dive Training` : 'Dive Training'
})

app.mount('#app')
