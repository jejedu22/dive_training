<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AppIcon from '../components/AppIcon.vue'
import { get, post, put } from '../api'
import { useAuth } from '../stores/auth'
import { useToast } from '../stores/toast'
import type { Role, User } from '../types'

type Level = 'instructor' | 'referent' | 'admin'
const levels: { value: Level; label: string; help: string }[] = [
  { value: 'instructor', label: 'Encadrant', help: 'sections, séances, bouteilles' },
  { value: 'referent', label: 'Référent', help: '+ valide les réservations, gère créneaux et bassins' },
  { value: 'admin', label: 'Administrateur', help: '+ gère les comptes' },
]
const rolesOf = (l: Level): Role[] => (l === 'admin' ? ['ROLE_ADMIN'] : l === 'referent' ? ['ROLE_REFERENT'] : [])
const levelOf = (u: User): Level => (u.admin ? 'admin' : u.referent ? 'referent' : 'instructor')

const auth = useAuth(), toast = useToast()
const users = ref<User[]>([])
const showNew = ref(false)
const form = ref({ email: '', name: '', password: '', level: 'instructor' as Level })

const load = async () => {
  try { users.value = await get<User[]>('/users') } catch (e) { toast.error(e) }
}
onMounted(load)

async function run(fn: () => Promise<unknown>, ok: string) {
  try {
    await fn()
    toast.ok(ok)
  } catch (e) {
    toast.error(e)
  }
  await load()
}
const create = () => run(async () => {
  await post('/users', { email: form.value.email, name: form.value.name, password: form.value.password, roles: rolesOf(form.value.level) })
  form.value = { email: '', name: '', password: '', level: 'instructor' }
  showNew.value = false
}, 'Compte créé. Communiquez le mot de passe à la personne.')
const setLevel = (u: User, l: Level) => run(() => put(`/users/${u.id}`, { roles: rolesOf(l) }), `Rôle de ${u.name} modifié.`)
const toggle = (u: User) => run(() => put(`/users/${u.id}`, { active: !u.active }), u.active ? `${u.name} désactivé(e).` : `${u.name} réactivé(e).`)
function resetPassword(u: User) {
  const p = prompt(`Nouveau mot de passe pour ${u.name} (8 caractères minimum)`)
  if (p) run(() => put(`/users/${u.id}`, { password: p }), 'Mot de passe réinitialisé.')
}
</script>

<template>
  <div class="page-head">
    <div><h1>Comptes</h1><p>Seuls les encadrants ont un compte. Les élèves sont de simples fiches dans les sections.</p></div>
    <button v-if="!showNew" class="btn" @click="showNew = true"><AppIcon name="plus" /> Nouveau compte</button>
  </div>

  <form v-if="showNew" class="card" style="margin-bottom:1rem" @submit.prevent="create">
    <div class="card-head"><h2>Nouveau compte</h2><button type="button" class="btn btn-ghost btn-icon" @click="showNew = false"><AppIcon name="x" /></button></div>
    <div class="fields">
      <label class="field">Nom<input v-model="form.name" required /></label>
      <label class="field">E-mail<input v-model="form.email" type="email" autocapitalize="none" required /></label>
      <label class="field">Mot de passe initial<input v-model="form.password" type="text" minlength="8" placeholder="8 caractères min." required /></label>
      <label class="field">Rôle
        <select v-model="form.level"><option v-for="l in levels" :key="l.value" :value="l.value">{{ l.label }}</option></select>
      </label>
    </div>
    <p class="muted" style="font-size:.85rem">{{ levels.find((l) => l.value === form.level)?.label }} : {{ levels.find((l) => l.value === form.level)?.help }}</p>
    <div class="row"><button class="btn"><AppIcon name="check" /> Créer</button></div>
  </form>

  <div class="card">
    <div class="table-wrap">
      <table>
        <tr><th>Nom</th><th class="hide-mobile">E-mail</th><th>Rôle</th><th /></tr>
        <tr v-for="u in users" :key="u.id" :class="{ off: !u.active }">
          <td><strong>{{ u.name }}</strong> <span v-if="!u.active" class="chip chip-ko">désactivé</span><br class="only-mobile" /><small class="only-mobile">{{ u.email }}</small></td>
          <td class="hide-mobile">{{ u.email }}</td>
          <td>
            <select :value="levelOf(u)" :disabled="u.id === auth.user?.id" @change="setLevel(u, ($event.target as HTMLSelectElement).value as Level)">
              <option v-for="l in levels" :key="l.value" :value="l.value">{{ l.label }}</option>
            </select>
          </td>
          <td><div class="row" style="justify-content:flex-end">
            <button class="btn btn-secondary btn-sm" title="Réinitialiser le mot de passe" @click="resetPassword(u)"><AppIcon name="lock" :size="15" /> Mot de passe</button>
            <button v-if="u.id !== auth.user?.id" class="btn btn-sm" :class="u.active ? 'btn-danger' : 'btn-ok'" @click="toggle(u)">{{ u.active ? 'Désactiver' : 'Réactiver' }}</button>
          </div></td>
        </tr>
      </table>
    </div>
  </div>
</template>

<style scoped>
tr.off td { opacity: .55 }
/* Mobile : une carte par compte au lieu d'un tableau qui déborde */
@media (max-width: 760px) {
  table, tbody, tr, td { display: block; width: 100% }
  tr:has(th) { display: none }
  tr { padding: .7rem 0; border-bottom: 1px solid var(--line) }
  tr:last-child { border-bottom: 0 }
  td { border: 0; padding: .2rem 0 }
  td select { width: 100% }
  td .row { justify-content: flex-start !important }
}
</style>
