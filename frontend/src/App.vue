<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from './components/AppIcon.vue'
import ToastHost from './components/ToastHost.vue'
import InstallDialog from './components/InstallDialog.vue'
import UserAvatar from './components/UserAvatar.vue'
import { useAuth } from './stores/auth'
import { isStandalone, openInstall } from './pwa'

const auth = useAuth()
const router = useRouter()
const route = useRoute()

const online = ref(navigator.onLine)
const setOnline = () => (online.value = navigator.onLine)
onMounted(() => { addEventListener('online', setOnline); addEventListener('offline', setOnline) })
onUnmounted(() => { removeEventListener('online', setOnline); removeEventListener('offline', setOnline) })

const nav = computed(() => [
  { to: '/occupation', label: 'Lignes d\'eau', short: 'Lignes', icon: 'waves' },
  { to: '/planning', label: 'Planning', short: 'Planning', icon: 'calendar' },
  { to: '/sections', label: 'Sections', short: 'Sections', icon: 'users' },
  { to: '/slots', label: 'Créneaux', short: 'Créneaux', icon: 'clock' },
  ...(auth.isReferent ? [{ to: '/pools', label: 'Bassins', short: 'Bassins', icon: 'pool' }] : []),
  ...(auth.isAdmin ? [{ to: '/users', label: 'Comptes', short: 'Comptes', icon: 'shield' }] : []),
])
// Sur mobile : 4 onglets principaux + « Plus »
const mobileMain = computed(() => nav.value.slice(0, 4))
const moreOpen = ref(false)
const moreActive = computed(() => !mobileMain.value.some((n) => route.path.startsWith(n.to)))

function logout() {
  auth.logout()
  moreOpen.value = false
  router.push('/login')
}
</script>

<template>
  <template v-if="auth.user">
    <header class="app-header">
      <div class="inner">
        <router-link to="/occupation" class="brand"><img src="/favicon.png" alt="" width="26" height="26" /> Dive Training</router-link>
        <nav class="desktop-nav hide-mobile">
          <router-link v-for="n in nav" :key="n.to" :to="n.to"><AppIcon :name="n.icon" :size="17" />{{ n.label }}</router-link>
        </nav>
        <span class="spacer" />
        <router-link to="/account" class="me hide-mobile" :title="auth.user.email">
          <UserAvatar :name="auth.user.name" :color="auth.user.avatarColor" />
          <span>{{ auth.user.name }}<small>{{ auth.isAdmin ? 'Administrateur' : auth.isReferent ? 'Référent' : 'Encadrant' }}</small></span>
        </router-link>
        <button class="btn btn-ghost btn-icon hide-mobile" title="Déconnexion" @click="logout"><AppIcon name="logout" /></button>
      </div>
    </header>
    <div v-if="!online" class="offline"><AppIcon name="alert" :size="16" /> Hors ligne — les données ne peuvent pas être chargées.</div>

    <nav class="tabbar only-mobile">
      <router-link v-for="n in mobileMain" :key="n.to" :to="n.to" @click="moreOpen = false"><AppIcon :name="n.icon" :size="22" /><span>{{ n.short }}</span></router-link>
      <button :class="{ active: moreActive || moreOpen }" @click="moreOpen = !moreOpen"><AppIcon name="more" :size="22" /><span>Plus</span></button>
    </nav>
    <div v-if="moreOpen" class="sheet-backdrop only-mobile" @click="moreOpen = false">
      <div class="sheet" @click.stop>
        <div class="sheet-user"><UserAvatar :name="auth.user.name" :color="auth.user.avatarColor" />
          <div><strong>{{ auth.user.name }}</strong><br /><small>{{ auth.user.email }}</small></div></div>
        <router-link v-for="n in nav.slice(4)" :key="n.to" :to="n.to" @click="moreOpen = false"><AppIcon :name="n.icon" />{{ n.label }}</router-link>
        <button v-if="!isStandalone" @click="moreOpen = false; openInstall()"><AppIcon name="download" />Installer l'application</button>
        <router-link to="/account" @click="moreOpen = false"><AppIcon name="user" />Mon compte</router-link>
        <button @click="logout"><AppIcon name="logout" />Déconnexion</button>
      </div>
    </div>
  </template>

  <main :class="{ bare: !auth.user }"><router-view /></main>
  <ToastHost />
  <InstallDialog />
</template>

<style scoped>
.app-header { position: sticky; top: 0; z-index: 20; background: var(--primary); color: #fff; box-shadow: 0 2px 6px rgba(0,0,0,.12) }
.inner { max-width: 1180px; margin: 0 auto; height: var(--header-h); padding: 0 1rem; display: flex; align-items: center; gap: 1rem }
.brand { display: flex; align-items: center; gap: .5rem; color: #fff; text-decoration: none; font-weight: 700; font-size: 1.05rem; white-space: nowrap }
.brand img { border-radius: 6px }
.desktop-nav { display: flex; gap: .15rem; overflow-x: auto }
.desktop-nav a { display: flex; align-items: center; gap: .4rem; color: #d3e6f5; text-decoration: none; padding: .45rem .7rem; border-radius: 8px; font-weight: 500; white-space: nowrap }
.desktop-nav a:hover { background: rgba(255,255,255,.1); color: #fff }
.desktop-nav a.router-link-active { background: rgba(255,255,255,.18); color: #fff }
.me { display: flex; align-items: center; gap: .55rem; color: #fff; text-decoration: none; line-height: 1.15 }
.me small { display: block; color: #bcd9ef; font-size: .75rem }
.app-header .btn-ghost { color: #d3e6f5 } .app-header .btn-ghost:hover { background: rgba(255,255,255,.12); color: #fff }
.offline { display: flex; justify-content: center; align-items: center; gap: .4rem; background: var(--warn-50); color: #7a4e00; padding: .4rem; font-size: .9rem }

.tabbar { position: fixed; z-index: 30; bottom: 0; left: 0; right: 0; height: var(--tabbar-h); padding-bottom: env(safe-area-inset-bottom);
  background: #fff; border-top: 1px solid var(--line); display: grid; grid-template-columns: repeat(5, 1fr) }
.tabbar a, .tabbar button { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; color: var(--muted);
  text-decoration: none; font: inherit; font-size: .7rem; background: none; border: 0; cursor: pointer }
.tabbar a.router-link-active, .tabbar button.active { color: var(--primary); font-weight: 600 }
.sheet-backdrop { position: fixed; inset: 0; z-index: 25; background: rgba(10,20,30,.35) }
.sheet { position: absolute; left: 0; right: 0; bottom: var(--tabbar-h); background: #fff; border-radius: 14px 14px 0 0; padding: .5rem 0 .75rem; box-shadow: 0 -6px 20px rgba(0,0,0,.12) }
.sheet a, .sheet button { display: flex; align-items: center; gap: .75rem; padding: .85rem 1.25rem; color: var(--text); text-decoration: none; font: inherit; background: none; border: 0; width: 100%; cursor: pointer }
.sheet-user { display: flex; gap: .75rem; align-items: center; padding: .75rem 1.25rem; border-bottom: 1px solid var(--line); margin-bottom: .25rem }
main.bare { padding-bottom: 2rem }
@media (max-width: 760px) { .inner { gap: .5rem } }
</style>
