<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import UserAvatar from '../components/UserAvatar.vue'
import { get, put } from '../api'
import { useAuth } from '../stores/auth'
import { useToast } from '../stores/toast'
import { appBuild, isStandalone, openInstall, reloadLatest } from '../pwa'
import { relative } from '../format'
import { chipStyle } from '../colors'
import type { Preferences, Section } from '../types'

const auth = useAuth(), toast = useToast(), router = useRouter()
const AVATAR_COLORS = ['#0b6aa8', '#1e8449', '#7d3c98', '#c0392b', '#d35400', '#0e8a96', '#3949ab', '#8d5524', '#c2378f', '#16212c']
const role = computed(() => (auth.isAdmin ? 'Administrateur' : auth.isReferent ? 'Référent' : 'Encadrant'))
const roleHelp = computed(() =>
  auth.isAdmin ? 'Gère les comptes, planifie les créneaux, les lignes et les bassins.'
    : auth.isReferent ? 'Planifie les créneaux, les lignes et les bassins.'
      : 'Consulte le planning, gère ses sections et note les bouteilles.')

// --- Profil
const profile = ref({ name: '', email: '', phone: '', avatarColor: '' })
const emailPassword = ref('')
const savingProfile = ref(false)
const profileError = ref('')
function resetProfile() {
  const u = auth.user!
  profile.value = { name: u.name, email: u.email, phone: u.phone ?? '', avatarColor: u.avatarColor }
  emailPassword.value = ''
  profileError.value = ''
}
const emailChanged = computed(() => profile.value.email.trim().toLowerCase() !== auth.user?.email)
const profileDirty = computed(() => {
  const u = auth.user
  return !!u && (profile.value.name !== u.name || emailChanged.value || (profile.value.phone || null) !== u.phone || profile.value.avatarColor !== u.avatarColor)
})
async function saveProfile() {
  profileError.value = ''
  savingProfile.value = true
  try {
    await auth.updateProfile({
      name: profile.value.name, email: profile.value.email, phone: profile.value.phone || null, avatarColor: profile.value.avatarColor,
      ...(emailChanged.value ? { currentPassword: emailPassword.value } : {}),
    })
    resetProfile()
    toast.ok(emailChanged.value ? 'Profil enregistré. Utilisez votre nouvel e-mail pour vous connecter.' : 'Profil enregistré.')
  } catch (e) {
    profileError.value = (e as Error).message
  } finally {
    savingProfile.value = false
  }
}

// --- Préférences (enregistrées dès qu'on les change)
async function setPref<K extends keyof Preferences>(key: K, value: Preferences[K]) {
  try {
    await auth.updateProfile({ preferences: { [key]: value } })
    toast.ok('Préférence enregistrée.')
  } catch (e) {
    toast.error(e)
  }
}
const homePages: { value: Preferences['homePage']; label: string }[] = [
  { value: 'planning', label: 'Planning' }, { value: 'sections', label: 'Sections' },
]

// --- Mes sections
const mySections = ref<Section[]>([])

// --- Mot de passe
const pwd = ref({ currentPassword: '', newPassword: '', confirm: '' })
const showPwd = ref(false)
const pwdError = ref('')
const strength = computed(() => {
  const p = pwd.value.newPassword
  if (!p) return null
  const score = Number(p.length >= 8) + Number(p.length >= 12) + Number(/[A-Z]/.test(p) && /[a-z]/.test(p)) + Number(/\d/.test(p)) + Number(/[^A-Za-z0-9]/.test(p))
  return score <= 2 ? { text: 'faible', cls: 'ko', pct: 33 } : score <= 3 ? { text: 'moyen', cls: 'warn', pct: 66 } : { text: 'solide', cls: 'ok', pct: 100 }
})
async function savePassword() {
  pwdError.value = ''
  if (pwd.value.newPassword !== pwd.value.confirm) {
    pwdError.value = 'Les deux mots de passe ne correspondent pas.'
    return
  }
  try {
    await put('/me/password', { currentPassword: pwd.value.currentPassword, newPassword: pwd.value.newPassword })
    pwd.value = { currentPassword: '', newPassword: '', confirm: '' }
    toast.ok('Mot de passe modifié.')
  } catch (e) {
    pwdError.value = (e as Error).message
  }
}

function logout() {
  auth.logout()
  router.push('/login')
}

onMounted(async () => {
  resetProfile()
  try {
    mySections.value = (await get<Section[]>('/sections')).filter((s) => s.instructors.some((i) => i.id === auth.user?.id))
  } catch (e) {
    toast.error(e)
  }
})
</script>

<template>
  <div v-if="auth.user">
    <section class="card hero">
      <UserAvatar :name="profile.name || auth.user.name" :color="profile.avatarColor || auth.user.avatarColor" :size="64" />
      <div class="who">
        <h1>{{ auth.user.name }}</h1>
        <p class="muted">{{ auth.user.email }}<template v-if="auth.user.phone"> · {{ auth.user.phone }}</template></p>
        <p class="row" style="gap:.4rem;margin-top:.4rem">
          <span class="chip chip-primary" :title="roleHelp">{{ role }}</span>
          <span v-if="auth.user.createdAt" class="muted small">Membre depuis le {{ new Date(auth.user.createdAt).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }) }}</span>
        </p>
      </div>
    </section>

    <div class="grid-2" style="margin-top:1rem">
      <!-- Profil -->
      <form class="card" @submit.prevent="saveProfile">
        <div class="card-head"><div><h2>Profil</h2><p>Ces informations sont visibles des autres encadrants.</p></div></div>
        <div class="stack" style="gap:.75rem">
          <label class="field">Nom affiché<input v-model="profile.name" maxlength="120" required /></label>
          <label class="field">E-mail (identifiant de connexion)<input v-model="profile.email" type="email" autocapitalize="none" required /></label>
          <label v-if="emailChanged" class="field">Mot de passe actuel, pour confirmer le changement d'e-mail
            <input v-model="emailPassword" type="password" autocomplete="current-password" required /></label>
          <label class="field">Téléphone <small>(facultatif)</small><input v-model="profile.phone" type="tel" inputmode="tel" placeholder="06 12 34 56 78" /></label>
          <div class="field">Couleur de mon avatar
            <div class="row colors">
              <button v-for="c in AVATAR_COLORS" :key="c" type="button" class="color" :class="{ on: profile.avatarColor === c }" :style="{ background: c }" :title="c" @click="profile.avatarColor = c" />
              <label class="color custom" :class="{ on: !AVATAR_COLORS.includes(profile.avatarColor) }" :style="{ background: AVATAR_COLORS.includes(profile.avatarColor) ? '#fff' : profile.avatarColor }" title="Autre couleur">
                <input type="color" :value="profile.avatarColor" @input="profile.avatarColor = ($event.target as HTMLInputElement).value" />+
              </label>
            </div>
          </div>
        </div>
        <div v-if="profileError" class="alert alert-ko" style="margin-top:.75rem"><AppIcon name="alert" /> <span>{{ profileError }}</span></div>
        <div class="row" style="margin-top:1rem">
          <button class="btn" :disabled="!profileDirty || savingProfile"><AppIcon name="check" /> Enregistrer</button>
          <button v-if="profileDirty" type="button" class="btn btn-ghost" @click="resetProfile">Annuler</button>
        </div>
      </form>

      <!-- Préférences -->
      <section class="card">
        <div class="card-head"><div><h2>Préférences</h2><p>Enregistrées sur votre compte, sur tous vos appareils.</p></div></div>
        <div class="stack" style="gap:1rem">
          <div class="field">Page d'accueil
            <div class="toggle-group">
              <label v-for="h in homePages" :key="h.value" class="toggle" :class="{ on: auth.prefs.homePage === h.value }">
                <input type="radio" :checked="auth.prefs.homePage === h.value" @change="setPref('homePage', h.value)" />{{ h.label }}</label>
            </div>
          </div>
          <div class="field">Affichage du planning par défaut
            <div class="toggle-group">
              <label class="toggle" :class="{ on: auth.prefs.planningView === 'week' }"><input type="radio" :checked="auth.prefs.planningView === 'week'" @change="setPref('planningView', 'week')" />Semaine</label>
              <label class="toggle" :class="{ on: auth.prefs.planningView === 'month' }"><input type="radio" :checked="auth.prefs.planningView === 'month'" @change="setPref('planningView', 'month')" />Mois</label>
            </div>
          </div>
          <label class="check"><input type="checkbox" :checked="auth.prefs.onlyMine" @change="setPref('onlyMine', ($event.target as HTMLInputElement).checked)" />
            Planning : n'afficher que mes sections</label>
        </div>

        <h3 class="sub">Mes sections</h3>
        <div v-if="mySections.length" class="row">
          <router-link v-for="s in mySections" :key="s.id" to="/sections" class="chip" :style="chipStyle(s.color)">{{ s.name }}</router-link>
        </div>
        <p v-else class="muted small">Vous n'encadrez aucune section. Ajoutez-vous dans <router-link to="/sections">Sections</router-link> (« J'encadre cette section »).</p>
      </section>

      <!-- Mot de passe -->
      <form class="card" @submit.prevent="savePassword">
        <div class="card-head"><div><h2>Mot de passe</h2>
          <p v-if="auth.user.lastLoginAt">Dernière connexion : {{ relative(auth.user.lastLoginAt) }}</p></div></div>
        <div class="stack" style="gap:.75rem">
          <label class="field">Mot de passe actuel<input v-model="pwd.currentPassword" :type="showPwd ? 'text' : 'password'" autocomplete="current-password" required /></label>
          <label class="field">Nouveau mot de passe<input v-model="pwd.newPassword" :type="showPwd ? 'text' : 'password'" minlength="8" placeholder="8 caractères minimum" autocomplete="new-password" required /></label>
          <div v-if="strength" class="meter"><span :class="strength.cls" :style="{ width: `${strength.pct}%` }" /><small>Robustesse : {{ strength.text }}</small></div>
          <label class="field">Confirmation<input v-model="pwd.confirm" :type="showPwd ? 'text' : 'password'" autocomplete="new-password" required /></label>
          <label class="check"><input v-model="showPwd" type="checkbox" /> Afficher les mots de passe</label>
        </div>
        <div v-if="pwdError" class="alert alert-ko" style="margin-top:.75rem"><AppIcon name="alert" /> <span>{{ pwdError }}</span></div>
        <div class="row" style="margin-top:1rem"><button class="btn"><AppIcon name="lock" /> Changer le mot de passe</button></div>
      </form>

      <!-- Application -->
      <section class="card">
        <div class="card-head"><h2>Application</h2></div>
        <p v-if="isStandalone" class="muted row"><AppIcon name="check" :size="15" /> Installée sur cet appareil.</p>
        <button v-else class="btn btn-secondary" @click="openInstall"><AppIcon name="download" /> Installer l'application</button>
        <p class="muted small" style="margin:.9rem 0 .3rem">Version du {{ appBuild }} (UTC)</p>
        <div class="row">
          <button class="btn btn-ghost btn-sm" @click="reloadLatest">Charger la dernière version</button>
          <span class="spacer" />
          <button class="btn btn-secondary" @click="logout"><AppIcon name="logout" /> Déconnexion</button>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.hero { display: flex; align-items: center; gap: 1rem }
.hero h1 { font-size: 1.35rem }
.hero p { margin: .15rem 0 0 }
.small { font-size: .85rem }
.sub { margin: 1.4rem 0 .5rem; font-size: .88rem; color: var(--muted) }
.colors { gap: .35rem; margin-top: .2rem }
.color { width: 30px; height: 30px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 0 1px var(--line); cursor: pointer; padding: 0; position: relative }
.color.on { box-shadow: 0 0 0 2px var(--text) }
.color.custom { display: grid; place-items: center; font-weight: 700; color: var(--muted); overflow: hidden }
.color.custom input { position: absolute; inset: 0; opacity: 0; cursor: pointer; min-height: 0; padding: 0; border: 0 }
.meter { display: flex; flex-direction: column; gap: .2rem }
.meter span { display: block; height: 5px; border-radius: 3px; transition: width .2s }
.meter .ko { background: var(--ko) } .meter .warn { background: var(--warn) } .meter .ok { background: var(--ok) }
.meter small { color: var(--muted) }
</style>
