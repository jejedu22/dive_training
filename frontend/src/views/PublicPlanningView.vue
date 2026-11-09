<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import AppIcon from '../components/AppIcon.vue'
import PlanningTable from '../components/PlanningTable.vue'
import { usePeriod } from '../composables/period'
import type { Planning } from '../types'

/** Planning partagé par lien : sans compte, en lecture seule. */
const props = defineProps<{ token: string }>()
const { mode, anchor, label, query, isCurrent, shift, goToday, pdfTitle, pdfName } = usePeriod('month')
const info = ref<{ label: string | null } | null>(null)
const data = ref<Planning | null>(null)
const error = ref('')

async function fetchJson<T>(path: string): Promise<T> {
  const r = await fetch(`/api/public/${encodeURIComponent(props.token)}${path}`)
  const j = await r.json().catch(() => ({}))
  if (!r.ok) throw new Error(j.message ?? `Erreur ${r.status}`)
  return j as T
}
let seq = 0
async function load() {
  const s = ++seq
  try {
    const r = await fetchJson<Planning>(`/planning?${query.value}`)
    if (s === seq) data.value = r
  } catch (e) {
    error.value = (e as Error).message
  }
}
onMounted(async () => {
  try {
    info.value = await fetchJson('')
    document.title = `Planning des lignes d'eau${info.value?.label ? ` · ${info.value.label}` : ''}`
    await load()
  } catch (e) {
    error.value = (e as Error).message
  }
})
watch([mode, anchor], load)
const pdfUrl = computed(() => `/api/public/${encodeURIComponent(props.token)}/pdf?${query.value}&title=${encodeURIComponent(pdfTitle.value)}`)
</script>

<template>
  <div class="public">
    <div v-if="error" class="card empty"><strong>Lien indisponible</strong>{{ error }}</div>
    <template v-else-if="info">
      <div class="page-head">
        <div>
          <p class="brand"><img src="/favicon.png" alt="" width="20" height="20" /> Dive Training</p>
          <h1>Planning des lignes d'eau</h1>
          <p class="cap">{{ label }}<template v-if="info.label"> · {{ info.label }}</template></p>
        </div>
        <div class="row">
          <div class="seg" role="tablist" aria-label="Période">
            <button role="tab" :class="{ on: mode === 'month' }" @click="mode = 'month'">Mois</button>
            <button role="tab" :class="{ on: mode === 'week' }" @click="mode = 'week'">Semaine</button>
          </div>
          <button class="btn btn-secondary btn-icon" title="Précédent" @click="shift(-1)"><AppIcon name="left" /></button>
          <button class="btn btn-secondary" :disabled="isCurrent" @click="goToday">Aujourd'hui</button>
          <button class="btn btn-secondary btn-icon" title="Suivant" @click="shift(1)"><AppIcon name="right" /></button>
          <a class="btn btn-secondary" :href="pdfUrl" :download="pdfName"><AppIcon name="download" :size="16" /> PDF</a>
        </div>
      </div>
      <div v-if="data && !data.occurrences.length" class="card empty"><strong>Aucun créneau du club sur cette période</strong></div>
      <PlanningTable v-else-if="data" :occurrences="data.occurrences" />
      <p class="muted small foot">Planning en lecture seule, partagé par le club.</p>
    </template>
    <p v-else class="empty">Chargement…</p>
  </div>
</template>

<style scoped>
.brand { display: flex; align-items: center; gap: .4rem; font-weight: 700; color: var(--primary); margin: 0 0 .3rem }
.cap::first-letter { text-transform: uppercase }
.seg { display: inline-flex; border: 1px solid var(--primary-100); border-radius: 8px; overflow: hidden; background: #fff }
.seg button { border: 0; background: none; font: inherit; font-weight: 600; padding: .45rem .9rem; color: var(--primary); cursor: pointer; min-height: 38px }
.seg button.on { background: var(--primary); color: #fff }
.small { font-size: .85rem }
.foot { margin-top: 1rem; text-align: center }
</style>
