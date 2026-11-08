<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import { put } from '../api'
import { useAuth } from '../stores/auth'
import { useToast } from '../stores/toast'
import { isStandalone, openInstall } from '../pwa'

const auth = useAuth(), toast = useToast(), router = useRouter()
const form = ref({ currentPassword: '', newPassword: '', confirm: '' })
const error = ref('')

async function submit() {
  error.value = ''
  if (form.value.newPassword !== form.value.confirm) {
    error.value = 'Les deux mots de passe ne correspondent pas.'
    return
  }
  try {
    await put('/me/password', { currentPassword: form.value.currentPassword, newPassword: form.value.newPassword })
    form.value = { currentPassword: '', newPassword: '', confirm: '' }
    toast.ok('Mot de passe modifié.')
  } catch (e) {
    error.value = (e as Error).message
  }
}
function logout() {
  auth.logout()
  router.push('/login')
}
</script>

<template>
  <div class="page-head"><div><h1>Mon compte</h1></div></div>
  <div class="grid-2">
    <section v-if="auth.user" class="card">
      <div class="profile">
        <span class="avatar">{{ auth.user.name.slice(0, 1).toUpperCase() }}</span>
        <div><h2>{{ auth.user.name }}</h2><p class="muted">{{ auth.user.email }}</p></div>
      </div>
      <p><span class="chip chip-primary">{{ auth.isAdmin ? 'Administrateur' : auth.isReferent ? 'Référent' : 'Encadrant' }}</span></p>
      <div class="block">
        <h3>Application</h3>
        <p v-if="isStandalone" class="muted"><AppIcon name="check" :size="15" /> Installée sur cet appareil.</p>
        <button v-else class="btn btn-secondary" @click="openInstall"><AppIcon name="download" /> Installer l'application</button>
      </div>
      <button class="btn btn-secondary" style="margin-top:1rem" @click="logout"><AppIcon name="logout" /> Déconnexion</button>
    </section>

    <form class="card" @submit.prevent="submit">
      <div class="card-head"><h2>Changer mon mot de passe</h2></div>
      <div class="stack" style="gap:.75rem">
        <label class="field">Mot de passe actuel<input v-model="form.currentPassword" type="password" autocomplete="current-password" required /></label>
        <label class="field">Nouveau mot de passe<input v-model="form.newPassword" type="password" minlength="8" placeholder="8 caractères minimum" autocomplete="new-password" required /></label>
        <label class="field">Confirmation<input v-model="form.confirm" type="password" autocomplete="new-password" required /></label>
      </div>
      <div v-if="error" class="alert alert-ko" style="margin-top:.75rem"><AppIcon name="alert" /> <span>{{ error }}</span></div>
      <div class="row" style="margin-top:1rem"><button class="btn"><AppIcon name="check" /> Enregistrer</button></div>
    </form>
  </div>
</template>

<style scoped>
.profile { display: flex; gap: .8rem; align-items: center; margin-bottom: .75rem }
.profile p { margin: 0 }
.avatar { width: 48px; height: 48px; border-radius: 50%; background: var(--primary); color: #fff; display: grid; place-items: center; font-weight: 700; font-size: 1.2rem }
.block { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--line) }
.block h3 { margin-bottom: .5rem }
.block p { display: flex; align-items: center; gap: .3rem }
</style>
