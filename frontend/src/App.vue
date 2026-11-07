<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useAuth } from './stores/auth'

const auth = useAuth()
const router = useRouter()
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
    <span class="spacer" />
    <span>{{ auth.user.name }}<small v-if="auth.user.referent"> (référent)</small></span>
    <button @click="logout">Déconnexion</button>
  </header>
  <main><router-view /></main>
</template>
