<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { get, post, put } from '../api'
import { useAuth } from '../stores/auth'
import type { Role, User } from '../types'

type Level = 'instructor' | 'referent' | 'admin'
const levels: { value: Level; label: string }[] = [
  { value: 'instructor', label: 'Encadrant' },
  { value: 'referent', label: 'Référent (valide les réservations)' },
  { value: 'admin', label: 'Administrateur (gère les comptes)' },
]
const rolesOf = (l: Level): Role[] => (l === 'admin' ? ['ROLE_ADMIN'] : l === 'referent' ? ['ROLE_REFERENT'] : [])
const levelOf = (u: User): Level => (u.admin ? 'admin' : u.referent ? 'referent' : 'instructor')

const auth = useAuth()
const users = ref<User[]>([])
const form = ref({ email: '', name: '', password: '', level: 'instructor' as Level })
const error = ref(''), msg = ref('')

const load = async () => (users.value = await get<User[]>('/users'))
onMounted(load)

async function run(fn: () => Promise<unknown>, ok: string) {
  error.value = msg.value = ''
  try {
    await fn()
    msg.value = ok
    await load()
  } catch (e) {
    error.value = (e as Error).message
    await load()
  }
}
const create = () =>
  run(async () => {
    await post('/users', { email: form.value.email, name: form.value.name, password: form.value.password, roles: rolesOf(form.value.level) })
    form.value = { email: '', name: '', password: '', level: 'instructor' }
  }, 'Compte créé.')
const setLevel = (u: User, l: Level) => run(() => put(`/users/${u.id}`, { roles: rolesOf(l) }), 'Rôle modifié.')
const toggle = (u: User) => run(() => put(`/users/${u.id}`, { active: !u.active }), u.active ? 'Compte désactivé.' : 'Compte réactivé.')
function resetPassword(u: User) {
  const p = prompt(`Nouveau mot de passe pour ${u.name} (8 caractères min.)`)
  if (p) run(() => put(`/users/${u.id}`, { password: p }), 'Mot de passe réinitialisé.')
}
</script>

<template>
  <form class="card" @submit.prevent="create">
    <h3>Nouvel encadrant</h3>
    <div class="row">
      <input v-model="form.name" placeholder="Nom" required />
      <input v-model="form.email" type="email" placeholder="E-mail" autocapitalize="none" required />
      <input v-model="form.password" type="text" placeholder="Mot de passe initial (8 min.)" minlength="8" required />
      <select v-model="form.level"><option v-for="l in levels" :key="l.value" :value="l.value">{{ l.label }}</option></select>
      <button>Créer</button>
    </div>
  </form>
  <p v-if="msg" style="color:var(--ok)">{{ msg }}</p>
  <p v-if="error" class="error">{{ error }}</p>

  <div class="card">
    <table>
      <tr><th>Nom</th><th>E-mail</th><th>Rôle</th><th>État</th><th /></tr>
      <tr v-for="u in users" :key="u.id" :style="{ opacity: u.active ? 1 : 0.5 }">
        <td>{{ u.name }}</td>
        <td>{{ u.email }}</td>
        <td>
          <select :value="levelOf(u)" @change="setLevel(u, ($event.target as HTMLSelectElement).value as Level)">
            <option v-for="l in levels" :key="l.value" :value="l.value">{{ l.label.split(' (')[0] }}</option>
          </select>
        </td>
        <td>{{ u.active ? 'actif' : 'désactivé' }}</td>
        <td class="row">
          <button class="secondary" @click="resetPassword(u)">Mot de passe</button>
          <button v-if="u.id !== auth.user?.id" :class="u.active ? 'danger' : 'ok'" @click="toggle(u)">{{ u.active ? 'Désactiver' : 'Réactiver' }}</button>
        </td>
      </tr>
    </table>
  </div>
</template>
