<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AppIcon from '../components/AppIcon.vue'
import AppDialog from '../components/AppDialog.vue'
import UserAvatar from '../components/UserAvatar.vue'
import { get, post, put } from '../api'
import { useAuth } from '../stores/auth'
import { useToast } from '../stores/toast'
import { generatePassword, relative } from '../format'
import { chipStyle } from '../colors'
import type { Role, User } from '../types'

type Level = 'instructor' | 'referent' | 'admin'
const levels: { value: Level; label: string; help: string }[] = [
  { value: 'instructor', label: 'Encadrant', help: 'sections, séances, bouteilles' },
  { value: 'referent', label: 'Référent', help: '+ valide les réservations, gère créneaux et bassins' },
  { value: 'admin', label: 'Administrateur', help: '+ gère les comptes' },
]
const rolesOf = (l: Level): Role[] => (l === 'admin' ? ['ROLE_ADMIN'] : l === 'referent' ? ['ROLE_REFERENT'] : [])
const levelOf = (u: User): Level => (u.admin ? 'admin' : u.referent ? 'referent' : 'instructor')
const levelLabel = (u: User) => levels.find((l) => l.value === levelOf(u))!.label

const auth = useAuth(), toast = useToast()
const users = ref<User[]>([])
const q = ref('')
const roleFilter = ref<'' | Level>('')
const stateFilter = ref<'active' | 'inactive' | ''>('active')

const load = async () => {
  try { users.value = await get<User[]>('/users') } catch (e) { toast.error(e) }
}
onMounted(load)

const filtered = computed(() => {
  const s = q.value.trim().toLowerCase()
  return users.value.filter((u) =>
    (!s || u.name.toLowerCase().includes(s) || u.email.includes(s) || (u.phone ?? '').includes(s))
    && (!roleFilter.value || levelOf(u) === roleFilter.value)
    && (!stateFilter.value || (stateFilter.value === 'active') === u.active))
})
const counts = computed(() => ({ total: users.value.length, active: users.value.filter((u) => u.active).length }))

async function copy(text: string, what = 'Copié') {
  try {
    await navigator.clipboard.writeText(text)
    toast.ok(`${what} dans le presse-papiers.`)
  } catch {
    toast.show('Copie impossible : sélectionnez le texte et copiez-le.', 'info')
  }
}
const credentials = (email: string, password: string) =>
  `Dive Training — ${location.origin}\nIdentifiant : ${email}\nMot de passe : ${password}\n(à changer dans « Mon compte » après la première connexion)`

// --- Création
const createOpen = ref(false)
const form = ref({ name: '', email: '', phone: '', password: '', level: 'instructor' as Level })
const createError = ref('')
const created = ref<{ email: string; password: string } | null>(null)
function openCreate() {
  form.value = { name: '', email: '', phone: '', password: generatePassword(), level: 'instructor' }
  createError.value = ''
  created.value = null
  createOpen.value = true
}
async function create() {
  createError.value = ''
  try {
    const u = await post<User>('/users', { email: form.value.email, name: form.value.name, password: form.value.password, roles: rolesOf(form.value.level) })
    if (form.value.phone) await put(`/users/${u.id}`, { phone: form.value.phone })
    created.value = { email: u.email, password: form.value.password }
    await load()
  } catch (e) {
    createError.value = (e as Error).message
  }
}

// --- Modification
const editOpen = ref(false)
const edit = ref({ id: 0, name: '', email: '', phone: '', level: 'instructor' as Level })
const editError = ref('')
function openEdit(u: User) {
  edit.value = { id: u.id, name: u.name, email: u.email, phone: u.phone ?? '', level: levelOf(u) }
  editError.value = ''
  editOpen.value = true
}
async function saveEdit() {
  editError.value = ''
  const self = edit.value.id === auth.user?.id
  try {
    await put(`/users/${edit.value.id}`, {
      name: edit.value.name, phone: edit.value.phone || null, ...(self ? {} : { email: edit.value.email, roles: rolesOf(edit.value.level) }),
    })
    toast.ok('Compte modifié.')
    editOpen.value = false
    if (self) {
      auth.user = null // recharge son propre profil (nom, téléphone) affiché dans l'en-tête
      await auth.fetchMe().catch(() => undefined)
    }
    await load()
  } catch (e) {
    editError.value = (e as Error).message
  }
}

// --- Mot de passe
const pwdOpen = ref(false)
const pwdUser = ref<User | null>(null)
const newPassword = ref('')
const pwdDone = ref(false)
function openPassword(u: User) {
  pwdUser.value = u
  newPassword.value = generatePassword()
  pwdDone.value = false
  pwdOpen.value = true
}
async function savePassword() {
  try {
    await put(`/users/${pwdUser.value!.id}`, { password: newPassword.value })
    pwdDone.value = true
    toast.ok('Mot de passe réinitialisé.')
  } catch (e) {
    toast.error(e)
  }
}

async function toggle(u: User) {
  if (u.active && !confirm(`Désactiver le compte de ${u.name} ? Il ne pourra plus se connecter (ses séances sont conservées).`)) return
  try {
    await put(`/users/${u.id}`, { active: !u.active })
    toast.ok(u.active ? `${u.name} désactivé(e).` : `${u.name} réactivé(e).`)
  } catch (e) {
    toast.error(e)
  }
  await load()
}
</script>

<template>
  <div class="page-head">
    <div><h1>Comptes</h1><p>{{ counts.active }} compte{{ counts.active > 1 ? 's' : '' }} actif{{ counts.active > 1 ? 's' : '' }} sur {{ counts.total }}. Seuls les encadrants ont un compte ; les élèves sont des fiches dans les sections.</p></div>
    <button class="btn" @click="openCreate"><AppIcon name="plus" /> Nouveau compte</button>
  </div>

  <div class="card filters">
    <input v-model="q" type="search" placeholder="Rechercher un nom, un e-mail, un téléphone…" aria-label="Rechercher" />
    <select v-model="roleFilter" aria-label="Rôle">
      <option value="">Tous les rôles</option>
      <option v-for="l in levels" :key="l.value" :value="l.value">{{ l.label }}s</option>
    </select>
    <select v-model="stateFilter" aria-label="État">
      <option value="active">Actifs</option><option value="inactive">Désactivés</option><option value="">Tous</option>
    </select>
  </div>

  <div class="stack" style="gap:.6rem">
    <article v-for="u in filtered" :key="u.id" class="card user" :class="{ off: !u.active }">
      <UserAvatar :name="u.name" :color="u.avatarColor" :size="42" />
      <div class="info">
        <div class="row" style="gap:.4rem">
          <strong>{{ u.name }}</strong>
          <span class="chip" :class="u.admin ? 'chip-primary' : u.referent ? 'chip-ok' : ''">{{ levelLabel(u) }}</span>
          <span v-if="!u.active" class="chip chip-ko">désactivé</span>
          <span v-if="u.id === auth.user?.id" class="chip">vous</span>
        </div>
        <p class="muted line">{{ u.email }}<template v-if="u.phone"> · {{ u.phone }}</template></p>
        <p class="line">
          <span v-for="s in u.sections" :key="s.id" class="chip" :style="chipStyle(s.color)">{{ s.name }}</span>
          <span v-if="!u.sections?.length" class="muted">Aucune section</span>
          <span class="muted"> · dernière connexion : {{ relative(u.lastLoginAt) }}</span>
        </p>
      </div>
      <div class="actions">
        <button class="btn btn-secondary btn-sm" @click="openEdit(u)"><AppIcon name="edit" :size="15" /> Modifier</button>
        <button class="btn btn-secondary btn-sm" @click="openPassword(u)"><AppIcon name="lock" :size="15" /> Mot de passe</button>
        <button v-if="u.id !== auth.user?.id" class="btn btn-sm" :class="u.active ? 'btn-danger' : 'btn-ok'" @click="toggle(u)">{{ u.active ? 'Désactiver' : 'Réactiver' }}</button>
      </div>
    </article>
    <div v-if="!filtered.length" class="card empty"><strong>Aucun compte</strong>ne correspond à la recherche.</div>
  </div>

  <!-- Création -->
  <AppDialog v-model="createOpen" :title="created ? 'Compte créé' : 'Nouveau compte'">
    <template v-if="!created">
      <form id="create-form" class="stack" style="gap:.7rem" @submit.prevent="create">
        <label class="field">Nom<input v-model="form.name" required /></label>
        <label class="field">E-mail<input v-model="form.email" type="email" autocapitalize="none" required /></label>
        <label class="field">Téléphone <small>(facultatif)</small><input v-model="form.phone" type="tel" /></label>
        <label class="field">Rôle
          <select v-model="form.level"><option v-for="l in levels" :key="l.value" :value="l.value">{{ l.label }} — {{ l.help }}</option></select>
        </label>
        <div class="field">Mot de passe initial
          <div class="row" style="flex-wrap:nowrap">
            <input v-model="form.password" minlength="8" required class="mono" style="flex:1" />
            <button type="button" class="btn btn-secondary btn-icon" title="Générer" @click="form.password = generatePassword()"><AppIcon name="star" /></button>
          </div>
        </div>
      </form>
      <div v-if="createError" class="alert alert-ko" style="margin-top:.75rem"><AppIcon name="alert" /> <span>{{ createError }}</span></div>
    </template>
    <template v-else>
      <p>Transmettez ces identifiants à la personne. Elle pourra changer son mot de passe dans « Mon compte ».</p>
      <pre class="creds">{{ credentials(created.email, created.password) }}</pre>
    </template>
    <template #actions>
      <template v-if="!created">
        <button type="button" class="btn btn-ghost" @click="createOpen = false">Annuler</button>
        <button class="btn" form="create-form"><AppIcon name="check" /> Créer</button>
      </template>
      <template v-else>
        <button class="btn btn-secondary" @click="copy(credentials(created.email, created.password), 'Identifiants copiés')"><AppIcon name="download" /> Copier</button>
        <button class="btn" @click="createOpen = false">Terminer</button>
      </template>
    </template>
  </AppDialog>

  <!-- Modification -->
  <AppDialog v-model="editOpen" title="Modifier le compte">
    <form id="edit-form" class="stack" style="gap:.7rem" @submit.prevent="saveEdit">
      <label class="field">Nom<input v-model="edit.name" required /></label>
      <label class="field">E-mail (identifiant de connexion)<input v-model="edit.email" type="email" autocapitalize="none" required :disabled="edit.id === auth.user?.id" /></label>
      <p v-if="edit.id === auth.user?.id" class="muted small">Votre propre e-mail se change dans <router-link to="/account" @click="editOpen = false">Mon compte</router-link>.</p>
      <label class="field">Téléphone<input v-model="edit.phone" type="tel" /></label>
      <label class="field">Rôle
        <select v-model="edit.level" :disabled="edit.id === auth.user?.id"><option v-for="l in levels" :key="l.value" :value="l.value">{{ l.label }} — {{ l.help }}</option></select>
      </label>
      <p v-if="edit.id === auth.user?.id" class="muted small">Vous ne pouvez pas changer votre propre rôle.</p>
    </form>
    <div v-if="editError" class="alert alert-ko" style="margin-top:.75rem"><AppIcon name="alert" /> <span>{{ editError }}</span></div>
    <template #actions>
      <button type="button" class="btn btn-ghost" @click="editOpen = false">Annuler</button>
      <button class="btn" form="edit-form"><AppIcon name="check" /> Enregistrer</button>
    </template>
  </AppDialog>

  <!-- Mot de passe -->
  <AppDialog v-model="pwdOpen" :title="`Mot de passe de ${pwdUser?.name ?? ''}`">
    <template v-if="!pwdDone">
      <p class="muted">Le mot de passe actuel sera remplacé. Ses sessions ouvertes restent actives.</p>
      <div class="row" style="flex-wrap:nowrap">
        <input v-model="newPassword" minlength="8" class="mono" style="flex:1" aria-label="Nouveau mot de passe" />
        <button type="button" class="btn btn-secondary btn-icon" title="Générer" @click="newPassword = generatePassword()"><AppIcon name="star" /></button>
      </div>
    </template>
    <template v-else>
      <p>Nouveau mot de passe enregistré. Transmettez-le à {{ pwdUser?.name }} :</p>
      <pre class="creds">{{ credentials(pwdUser!.email, newPassword) }}</pre>
    </template>
    <template #actions>
      <template v-if="!pwdDone">
        <button class="btn btn-ghost" @click="pwdOpen = false">Annuler</button>
        <button class="btn" :disabled="newPassword.length < 8" @click="savePassword"><AppIcon name="lock" /> Remplacer</button>
      </template>
      <template v-else>
        <button class="btn btn-secondary" @click="copy(credentials(pwdUser!.email, newPassword), 'Identifiants copiés')"><AppIcon name="download" /> Copier</button>
        <button class="btn" @click="pwdOpen = false">Terminer</button>
      </template>
    </template>
  </AppDialog>
</template>

<style scoped>
.filters { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1rem; padding: .75rem }
.filters input { flex: 1; min-width: 220px }
.user { display: flex; align-items: center; gap: .9rem; padding: .8rem 1rem }
.user.off { opacity: .6 }
.info { flex: 1; min-width: 0 }
.line { margin: .25rem 0 0; font-size: .88rem; display: flex; flex-wrap: wrap; gap: .3rem; align-items: center }
.actions { display: flex; flex-wrap: wrap; gap: .35rem; justify-content: flex-end }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: .03em }
.creds { background: var(--bg); border: 1px solid var(--line); border-radius: 8px; padding: .75rem; white-space: pre-wrap; font-size: .85rem; user-select: all }
.small { font-size: .85rem }
@media (max-width: 760px) {
  .user { flex-wrap: wrap; align-items: flex-start }
  .actions { width: 100%; justify-content: flex-start }
}
</style>
