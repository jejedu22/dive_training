<script setup lang="ts">
import { ref } from 'vue'
import { put } from '../api'
import { useAuth } from '../stores/auth'

const auth = useAuth()
const form = ref({ currentPassword: '', newPassword: '', confirm: '' })
const msg = ref(''), error = ref('')

async function submit() {
  msg.value = error.value = ''
  if (form.value.newPassword !== form.value.confirm) {
    error.value = 'Les deux mots de passe ne correspondent pas.'
    return
  }
  try {
    await put('/me/password', { currentPassword: form.value.currentPassword, newPassword: form.value.newPassword })
    form.value = { currentPassword: '', newPassword: '', confirm: '' }
    msg.value = 'Mot de passe modifié.'
  } catch (e) {
    error.value = (e as Error).message
  }
}
</script>

<template>
  <div class="card" v-if="auth.user">
    <h2>Mon compte</h2>
    <p>{{ auth.user.name }} — {{ auth.user.email }}</p>
    <p>Rôle : {{ auth.isAdmin ? 'administrateur' : auth.isReferent ? 'référent' : 'encadrant' }}</p>
  </div>
  <form class="card" @submit.prevent="submit">
    <h3>Changer mon mot de passe</h3>
    <div class="row">
      <input v-model="form.currentPassword" type="password" placeholder="Mot de passe actuel" autocomplete="current-password" required />
      <input v-model="form.newPassword" type="password" placeholder="Nouveau (8 caractères min.)" minlength="8" autocomplete="new-password" required />
      <input v-model="form.confirm" type="password" placeholder="Confirmation" autocomplete="new-password" required />
      <button>Enregistrer</button>
    </div>
    <p v-if="msg" style="color:var(--ok)">{{ msg }}</p>
    <p v-if="error" class="error">{{ error }}</p>
  </form>
</template>
