<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuth } from './stores/auth'

const auth = useAuth()
const router = useRouter()
const online = ref(navigator.onLine)
const setOnline = () => (online.value = navigator.onLine)
onMounted(() => { addEventListener('online', setOnline); addEventListener('offline', setOnline) })
onUnmounted(() => { removeEventListener('online', setOnline); removeEventListener('offline', setOnline) })

function logout() {
  auth.logout()
  router.push('/login')
}
</script>

<template>
  <header v-if="auth.user" class="nav">
    <strong>🤿 Dive Training</strong>
    <router-link to="/planning">Planning</router-link>
    <router-link to="/sections">Sections</router-link>
    <router-link v-if="auth.isReferent" to="/pools">Bassins</router-link>
    <router-link v-if="auth.isAdmin" to="/users">Comptes</router-link>
    <span class="spacer" />
    <router-link to="/account">{{ auth.user.name }}<small>{{ auth.isAdmin ? ' (admin)' : auth.isReferent ? ' (référent)' : '' }}</small></router-link>
    <button @click="logout">Déconnexion</button>
  </header>
  <div v-if="!online" class="offline">Hors ligne — les données et réservations nécessitent une connexion.</div>
  <main><router-view /></main>
</template>
