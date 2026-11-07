<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuth } from '../stores/auth'

const email = ref(''), password = ref(''), error = ref('')
const auth = useAuth(), router = useRouter()
async function submit() {
  error.value = ''
  try {
    await auth.login(email.value, password.value)
    router.push('/planning')
  } catch {
    error.value = 'Identifiants invalides'
  }
}
</script>

<template>
  <form class="card" style="max-width:360px;margin:4rem auto" @submit.prevent="submit">
    <h2>Connexion encadrant</h2>
    <div class="row"><input v-model="email" type="email" placeholder="E-mail" required style="flex:1" /></div>
    <div class="row" style="margin-top:.5rem"><input v-model="password" type="password" placeholder="Mot de passe" required style="flex:1" /></div>
    <p v-if="error" class="error">{{ error }}</p>
    <button style="margin-top:.8rem">Se connecter</button>
  </form>
</template>
