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
    router.push('/occupation')
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
  <div class="login">
    <div class="brand"><img src="/pwa-192.png" alt="" width="72" height="72" /><h1>Dive Training</h1><p>Formation des plongeurs · planning des lignes d'eau</p></div>
    <form class="card" @submit.prevent="submit">
      <h2>Connexion encadrant</h2>
      <label class="field">E-mail<input v-model="email" type="email" autocomplete="username" autocapitalize="none" inputmode="email" required /></label>
      <label class="field">Mot de passe<input v-model="password" type="password" autocomplete="current-password" required /></label>
      <p v-if="error" class="alert alert-ko">{{ error }}</p>
      <ul v-if="hints.length" class="hints"><li v-for="h in hints" :key="h">{{ h }}</li></ul>
      <button class="btn" :disabled="loading">{{ loading ? 'Connexion…' : 'Se connecter' }}</button>
    </form>
    <p class="muted foot">Pas de compte ? Demandez-le à un administrateur du club.</p>
  </div>
</template>

<style scoped>
.login { max-width: 380px; margin: 6vh auto 0 }
.brand { text-align: center; margin-bottom: 1.25rem }
.brand img { border-radius: 18px; box-shadow: var(--shadow) }
.brand h1 { margin-top: .6rem }
.brand p { color: var(--muted); margin: .25rem 0 0 }
form { display: flex; flex-direction: column; gap: .85rem; padding: 1.4rem }
form .btn { width: 100%; margin-top: .25rem; min-height: 44px }
.alert { margin: 0 }
.hints { font-size: .85rem; color: #6a4800; background: var(--warn-50); border-radius: 8px; padding: .6rem .6rem .6rem 1.6rem; margin: 0 }
.foot { text-align: center; font-size: .88rem }
</style>
