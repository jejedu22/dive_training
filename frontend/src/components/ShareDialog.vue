<script setup lang="ts">
import { ref, watch } from 'vue'
import AppDialog from './AppDialog.vue'
import AppIcon from './AppIcon.vue'
import { del, get, post } from '../api'
import { useToast } from '../stores/toast'
import { dateFr } from '../format'
import type { ShareLink } from '../types'

/** Liens de partage du planning (référents) : lecture seule, sans compte, sans élèves ni bouteilles. */
const open = defineModel<boolean>({ required: true })
const toast = useToast()
const links = ref<ShareLink[]>([])
const form = ref({ label: '', expiresAt: '' })
const creating = ref(false)

const urlOf = (l: ShareLink) => `${location.origin}/partage/${l.token}`
const load = async () => {
  try { links.value = await get<ShareLink[]>('/share-links') } catch (e) { toast.error(e) }
}
watch(open, (o) => o && load(), { immediate: true })

async function create() {
  creating.value = true
  try {
    const l = await post<ShareLink>('/share-links', { label: form.value.label || null, expiresAt: form.value.expiresAt || null })
    form.value = { label: '', expiresAt: '' }
    await load()
    await copy(l)
  } catch (e) {
    toast.error(e)
  } finally {
    creating.value = false
  }
}
async function copy(l: ShareLink) {
  try {
    await navigator.clipboard.writeText(urlOf(l))
    toast.ok('Lien copié dans le presse-papiers.')
  } catch {
    toast.show(`Copiez le lien : ${urlOf(l)}`, 'info')
  }
}
async function revoke(l: ShareLink) {
  if (!confirm(`Révoquer le lien « ${l.label ?? 'sans nom'} » ? Les personnes qui l'ont ne pourront plus voir le planning.`)) return
  try {
    await del(`/share-links/${l.id}`)
    toast.ok('Lien révoqué.')
    await load()
  } catch (e) {
    toast.error(e)
  }
}
const expired = (l: ShareLink) => !!l.expiresAt && l.expiresAt < new Date().toISOString().slice(0, 10)
</script>

<template>
  <AppDialog v-model="open" title="Partager le planning">
    <p class="muted small">Toute personne qui a le lien voit le planning des lignes d'eau, <strong>sans compte</strong> et en lecture seule (pas d'élèves ni de bouteilles), et peut le télécharger en PDF.</p>

    <form class="new" @submit.prevent="create">
      <div class="fields">
        <label class="field">Nom du lien <small>(facultatif)</small><input v-model="form.label" maxlength="120" placeholder="ex. Piscine municipale, Parents" /></label>
        <label class="field">Valable jusqu'au <small>(facultatif)</small><input v-model="form.expiresAt" type="date" /></label>
      </div>
      <button class="btn" :disabled="creating" style="margin-top:.6rem"><AppIcon name="plus" :size="16" /> Créer et copier le lien</button>
    </form>

    <h3 class="sub">Liens existants</h3>
    <p v-if="!links.length" class="muted small">Aucun lien pour l'instant.</p>
    <div v-for="l in links" :key="l.id" class="link" :class="{ expired: expired(l) }">
      <div class="info">
        <strong>{{ l.label ?? 'Lien sans nom' }}</strong>
        <small class="muted">créé {{ l.createdBy ? `par ${l.createdBy} ` : '' }}le {{ new Date(l.createdAt).toLocaleDateString('fr-FR') }}
          · {{ l.expiresAt ? (expired(l) ? `expiré le ${dateFr(l.expiresAt)}` : `jusqu'au ${dateFr(l.expiresAt)}`) : 'sans limite' }}</small>
        <code>{{ urlOf(l) }}</code>
      </div>
      <div class="row actions">
        <button class="btn btn-secondary btn-sm" @click="copy(l)">Copier</button>
        <a class="btn btn-ghost btn-sm" :href="urlOf(l)" target="_blank" rel="noopener">Ouvrir</a>
        <button class="btn btn-danger btn-sm" @click="revoke(l)">Révoquer</button>
      </div>
    </div>
    <template #actions><button class="btn" @click="open = false">Fermer</button></template>
  </AppDialog>
</template>

<style scoped>
.small { font-size: .85rem }
.new { padding: .75rem; border: 1px solid var(--line); border-radius: 10px; background: var(--bg); margin: .5rem 0 1rem }
.sub { font-size: .9rem; color: var(--muted); margin: 0 0 .4rem }
.link { display: flex; flex-wrap: wrap; justify-content: space-between; gap: .5rem; padding: .6rem 0; border-top: 1px solid var(--line) }
.link.expired { opacity: .55 }
.info { display: flex; flex-direction: column; gap: .15rem; min-width: 0; flex: 1 }
code { font-size: .75rem; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 100% }
.actions { align-self: center }
</style>
