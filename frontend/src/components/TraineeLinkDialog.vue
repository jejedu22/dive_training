<script setup lang="ts">
import { ref, watch } from 'vue'
import AppDialog from './AppDialog.vue'
import AppIcon from './AppIcon.vue'
import { del, get, post } from '../api'
import { useToast } from '../stores/toast'
import { dateFr, relative } from '../format'
import type { ShareLink } from '../types'

/** Lien vers le livret d'un stagiaire, créé à la demande : lecture seule, durée limitée, code facultatif, révocable. */
const open = defineModel<boolean>({ required: true })
const props = defineProps<{ diverId: number; name: string }>()
const toast = useToast()
const links = ref<ShareLink[]>([])
const form = ref({ days: 30, code: '' })
const busy = ref(false)

const urlOf = (l: ShareLink) => `${location.origin}/partage/${l.token}`
const load = async () => {
  try { links.value = await get<ShareLink[]>(`/trainees/${props.diverId}/links`) } catch (e) { toast.error(e) }
}
watch(open, (o) => { if (o) { form.value = { days: 30, code: '' }; load() } }, { immediate: true })
const expired = (l: ShareLink) => !!l.expiresAt && l.expiresAt < new Date().toISOString().slice(0, 10)

function randomCode() {
  form.value.code = String(crypto.getRandomValues(new Uint32Array(1))[0] % 1_000_000).padStart(6, '0')
}
async function copy(l: ShareLink, code?: string) {
  const text = `Livret de formation de ${props.name} : ${urlOf(l)}` + (code ? `\nCode d'accès : ${code}` : '')
  try {
    await navigator.clipboard.writeText(text)
    toast.ok(code ? 'Lien et code copiés : envoyez-les au stagiaire.' : 'Lien copié dans le presse-papiers.')
  } catch {
    toast.show(`Copiez le lien : ${urlOf(l)}`, 'info')
  }
}
async function create() {
  busy.value = true
  try {
    const code = form.value.code.trim()
    const l = await post<ShareLink>(`/trainees/${props.diverId}/links`, { days: form.value.days, code: code || null })
    await load()
    await copy(l, code)
    form.value.code = ''
  } catch (e) {
    toast.error(e)
  } finally {
    busy.value = false
  }
}
async function revoke(l: ShareLink) {
  if (!confirm('Révoquer ce lien ? Le stagiaire ne pourra plus consulter son livret avec.')) return
  try {
    await del(`/share-links/${l.id}`)
    toast.ok('Lien révoqué.')
    await load()
  } catch (e) {
    toast.error(e)
  }
}
</script>

<template>
  <AppDialog v-model="open" :title="`Envoyer le livret de ${name}`">
    <p class="muted small">Le stagiaire consulte sa progression, les observations des encadrants et peut télécharger son livret en PDF — <strong>sans compte</strong>, en lecture seule, et rien d'autre du club.</p>
    <form class="new" @submit.prevent="create">
      <div class="fields">
        <label class="field">Valable
          <select v-model.number="form.days"><option :value="7">7 jours</option><option :value="30">30 jours</option><option :value="90">3 mois</option><option :value="365">1 an</option></select>
        </label>
        <label class="field">Code d'accès <small>(facultatif)</small>
          <span class="code-row"><input v-model="form.code" minlength="4" maxlength="64" autocomplete="off" placeholder="ex. 482913" />
            <button type="button" class="btn btn-ghost btn-sm" @click="randomCode">Générer</button></span>
        </label>
      </div>
      <button class="btn" :disabled="busy" style="margin-top:.6rem"><AppIcon name="plus" :size="16" /> Créer et copier le lien</button>
      <p class="muted small hint">Envoyez le lien par SMS, WhatsApp ou e-mail ; s'il y a un code, il est copié avec le lien.</p>
    </form>

    <h3 class="sub">Liens envoyés</h3>
    <p v-if="!links.length" class="muted small">Aucun lien pour l'instant.</p>
    <div v-for="l in links" :key="l.id" class="link" :class="{ expired: expired(l) }">
      <div class="info">
        <small>Créé {{ l.createdBy ? `par ${l.createdBy} ` : '' }}le {{ new Date(l.createdAt).toLocaleDateString('fr-FR') }}
          · {{ expired(l) ? `expiré le ${dateFr(l.expiresAt!)}` : `valable jusqu'au ${dateFr(l.expiresAt!)}` }}<template v-if="l.hasCode"> · 🔒 code</template></small>
        <small class="muted">{{ l.openCount ? `Ouvert ${l.openCount} fois, dernière fois ${relative(l.lastOpenedAt)}` : 'Jamais ouvert' }}</small>
      </div>
      <div class="row actions">
        <button v-if="!expired(l)" class="btn btn-secondary btn-sm" @click="copy(l)">Copier</button>
        <button class="btn btn-danger btn-sm" @click="revoke(l)">Révoquer</button>
      </div>
    </div>
    <template #actions><button class="btn" @click="open = false">Fermer</button></template>
  </AppDialog>
</template>

<style scoped>
.small { font-size: .85rem }
.new { padding: .75rem; border: 1px solid var(--line); border-radius: 10px; background: var(--bg); margin: .5rem 0 1rem }
.code-row { display: flex; width: 100%; gap: .4rem } .code-row input { flex: 1; min-width: 0 }
.hint { margin: .4rem 0 0 }
.sub { font-size: .9rem; color: var(--muted); margin: 0 0 .4rem }
.link { display: flex; flex-wrap: wrap; justify-content: space-between; gap: .5rem; padding: .6rem 0; border-top: 1px solid var(--line) }
.link.expired { opacity: .55 }
.info { display: flex; flex-direction: column; gap: .15rem; flex: 1 1 100% }
</style>
