<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError, get } from '../api'
import { useAuth } from '../stores/auth'
import type { Health } from '../types'

const email = ref(''), password = ref(''), error = ref(''), hints = ref<string[]>([]), loading = ref(false)
const auth = useAuth(), router = useRouter()

/** En cas d'échec autre qu'un mauvais mot de passe, on interroge /api/health pour dire précisément ce qui ne va pas. */
async function diagnose() {
  try {
    const h = await get<Health>('/health')
    if (h.hasUsers === false) hints.value.push("Aucun compte n'existe : définissez ADMIN_EMAIL et ADMIN_PASSWORD dans .env puis redémarrez le backend.")
  } catch (e) {
    if (e instanceof ApiError && e.fromApi) {
      hints.value.push('Le backend répond mais signale un problème (base de données ou clés JWT) : ouvrez /api/health.')
    } else {
      hints.value.push("L'API est injoignable : vérifiez que Traefik envoie bien /api vers le conteneur backend (ouvrez /api/health dans le navigateur).")
    }
  }
}

async function submit() {
  error.value = ''
  hints.value = []
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    router.push('/planning')
  } catch (e) {
    if (e instanceof ApiError && e.fromApi && e.status === 401) {
      error.value = e.message === 'Compte désactivé.' ? 'Ce compte est désactivé.' : 'E-mail ou mot de passe incorrect.'
    } else {
      error.value = e instanceof ApiError ? e.message : 'Impossible de joindre le serveur.'
    }
    await diagnose()
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <form class="card" style="max-width:380px;margin:4rem auto" @submit.prevent="submit">
    <h2>Connexion encadrant</h2>
    <div class="row"><input v-model="email" type="email" placeholder="E-mail" autocomplete="username" autocapitalize="none" required style="flex:1" /></div>
    <div class="row" style="margin-top:.5rem"><input v-model="password" type="password" placeholder="Mot de passe" autocomplete="current-password" required style="flex:1" /></div>
    <p v-if="error" class="error">{{ error }}</p>
    <ul v-if="hints.length" class="hints"><li v-for="h in hints" :key="h">{{ h }}</li></ul>
    <button style="margin-top:.8rem" :disabled="loading">{{ loading ? 'Connexion…' : 'Se connecter' }}</button>
  </form>
</template>

<style scoped>
.hints { font-size:.85rem; color:#5a4500; background:#fff6db; border:1px solid var(--warn); border-radius:4px; padding:.5rem .5rem .5rem 1.5rem }
</style>
