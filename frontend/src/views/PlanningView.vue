<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import AppIcon from '../components/AppIcon.vue'
import AppDialog from '../components/AppDialog.vue'
import TankDialog from '../components/TankDialog.vue'
import PlanningTable from '../components/PlanningTable.vue'
import ShareDialog from '../components/ShareDialog.vue'
import { download, get, put } from '../api'
import { useAuth } from '../stores/auth'
import { useToast } from '../stores/toast'
import { dayShort, time } from '../format'
import { chipStyle, textOn, tint } from '../colors'
import { usePeriod } from '../composables/period'
import type { Planning, PlanningLane, PlanningOccurrence, Section } from '../types'

/**
 * Le planning d'occupation des lignes d'eau.
 * Référent : clic sur une case pour la changer ce jour-là. Encadrant : clic sur sa section pour les bouteilles.
 */
const auth = useAuth(), toast = useToast()
const { mode, anchor, label: periodLabel, query, isCurrent, shift, goToday, pdfTitle, pdfName } = usePeriod(auth.prefs.planningView)
const onlyMine = ref(auth.prefs.onlyMine)
const data = ref<Planning | null>(null)
const sections = ref<Section[]>([])
const loading = ref(false)
const shareOpen = ref(false)
const exporting = ref(false)

let seq = 0
async function load() {
  const s = ++seq
  loading.value = true
  try {
    const r = await get<Planning>(`/planning?${query.value}`)
    if (s === seq) data.value = r
  } catch (e) {
    toast.error(e)
  } finally {
    if (s === seq) loading.value = false
  }
}
onMounted(async () => {
  await load()
  try { sections.value = await get<Section[]>('/sections') } catch { /* liste utile seulement aux référents */ }
})
watch([mode, anchor], load)

async function exportPdf() {
  exporting.value = true
  try {
    await download(`/planning/pdf?${query.value}&title=${encodeURIComponent(pdfTitle.value)}`, pdfName.value)
  } catch (e) {
    toast.error(e)
  } finally {
    exporting.value = false
  }
}

const isMine = (o: PlanningOccurrence) => o.lanes.some((l) => l.sections.some((x) => x.mine))
const rows = computed(() => (data.value?.occurrences ?? []).filter((o) => !onlyMine.value || isMine(o)))
const isPast = (o: PlanningOccurrence) => new Date(o.end) < new Date()
const tankSectionsOf = (o: PlanningOccurrence) => {
  const m = new Map<number, PlanningLane['sections'][number]>()
  for (const l of o.lanes) for (const x of l.sections) if (x.usesTanks) m.set(x.id, x)
  return [...m.values()]
}

// ---------- mes lignes
const myAll = computed(() =>
  (data.value?.occurrences ?? []).filter((o) => !o.cancelled && !isPast(o) && isMine(o))
    .map((o) => ({ o, lanes: o.lanes.filter((l) => l.sections.some((x) => x.mine)) })))
const MY_MAX = 3 // les prochaines dates seulement : le tableau reste visible
const myNext = computed(() => myAll.value.slice(0, MY_MAX))

// ---------- bouteilles
const tank = ref({ open: false, slotId: 0, date: '', sectionId: 0, canEdit: false, time: '' })
function openTanks(o: PlanningOccurrence, s: { id: number; mine: boolean }) {
  tank.value = { open: true, slotId: o.slotId, date: o.date, sectionId: s.id, canEdit: auth.isReferent || s.mine, time: `${time(o.start)}–${time(o.end)}` }
}

// ---------- modification ponctuelle (référents)
const edit = ref({ open: false, o: null as PlanningOccurrence | null, lane: null as PlanningLane | null, mode: 'set' as 'set' | 'same', ids: [] as number[], wholePool: false })
const MAX_SHARE = 2 // sections au plus sur une ligne
function onCell(o: PlanningOccurrence, l: PlanningLane | null) {
  if (!l) return
  if (auth.isReferent) openEdit(o, l)
  else {
    const withTanks = l.sections.filter((x) => x.usesTanks)
    if (withTanks.length) openTanks(o, withTanks.find((x) => x.mine) ?? withTanks[0]) // ma section d'abord
  }
}
/** Coche / décoche une section dans la fenêtre (au plus MAX_SHARE). */
function toggleSection(id: number) {
  const e = edit.value
  e.mode = 'set'
  e.ids = e.ids.includes(id) ? e.ids.filter((x) => x !== id) : e.ids.length < MAX_SHARE ? [...e.ids, id] : [e.ids[1], id]
}
function openEdit(o: PlanningOccurrence, l: PlanningLane | null) {
  edit.value = { open: true, o, lane: l, mode: 'set', ids: l?.sections.map((x) => x.id) ?? [], wholePool: false }
}
async function saveCell() {
  const { o, lane, mode, ids, wholePool } = edit.value
  if (!o || !lane) return
  const lanes = wholePool ? o.lanes.filter((x) => x.poolId === lane.poolId) : [lane]
  const sectionIds = mode === 'same' ? 'same' : ids
  try {
    await put(`/slots/${o.slotId}/dates/${o.date}`, { allocations: lanes.map((x) => ({ laneId: x.laneId, sectionIds })) })
    toast.ok(`${dayShort(o.start)} : ${wholePool ? lane.pool : lane.lane} modifiée${wholePool ? '' : ''}.`)
    edit.value.open = false
    await load()
  } catch (e) {
    toast.error(e)
  }
}
async function setCancelled(o: PlanningOccurrence, cancelled: boolean) {
  if (cancelled && !confirm(`Annuler le créneau du ${dayShort(o.start)} (${time(o.start)}–${time(o.end)}) ?`)) return
  try {
    await put(`/slots/${o.slotId}/dates/${o.date}`, { cancelled })
    toast.ok(cancelled ? 'Créneau annulé ce jour-là.' : 'Créneau rétabli.')
    edit.value.open = false
    await load()
  } catch (e) {
    toast.error(e)
  }
}
const editTitle = computed(() => {
  const { o, lane } = edit.value
  return o ? `${dayShort(o.start)} · ${time(o.start)}–${time(o.end)}${lane ? ` — ${lane.pool} · ${lane.lane}` : ''}` : ''
})
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Planning des lignes d'eau</h1>
      <p class="cap">{{ periodLabel }}</p>
    </div>
    <div class="row">
      <div class="seg" role="tablist" aria-label="Période">
        <button role="tab" :aria-selected="mode === 'month'" :class="{ on: mode === 'month' }" @click="mode = 'month'">Mois</button>
        <button role="tab" :aria-selected="mode === 'week'" :class="{ on: mode === 'week' }" @click="mode = 'week'">Semaine</button>
      </div>
      <button class="btn btn-secondary btn-icon" :title="mode === 'week' ? 'Semaine précédente' : 'Mois précédent'" @click="shift(-1)"><AppIcon name="left" /></button>
      <button class="btn btn-secondary" :disabled="isCurrent" @click="goToday">Aujourd'hui</button>
      <button class="btn btn-secondary btn-icon" :title="mode === 'week' ? 'Semaine suivante' : 'Mois suivant'" @click="shift(1)"><AppIcon name="right" /></button>
    </div>
  </div>

  <section v-if="myNext.length" class="card mine">
    <div class="card-head"><h2><AppIcon name="star" :size="17" /> Mes lignes</h2></div>
    <div v-for="x in myNext" :key="x.o.date + x.o.slotId" class="mine-row">
      <span class="when">{{ dayShort(x.o.start) }} · {{ time(x.o.start) }}–{{ time(x.o.end) }}</span>
      <span class="row" style="gap:.3rem">
        <span v-for="l in x.lanes" :key="l.laneId" class="chip" :style="chipStyle(l.sections.find((y) => y.mine)!.color)">{{ l.pool }} · {{ l.lane }}<template v-if="l.sections.length > 1"> (partagée)</template></span>
      </span>
      <span class="row" style="gap:.3rem">
        <button v-for="s in tankSectionsOf(x.o).filter((s) => s.mine)" :key="s.id" class="btn btn-secondary btn-sm" @click="openTanks(x.o, s)">
          <AppIcon name="tank" :size="15" /> {{ s.name }} : {{ x.o.tanks?.[s.id] ?? 0 }} bouteille{{ (x.o.tanks?.[s.id] ?? 0) > 1 ? 's' : '' }}
        </button>
      </span>
    </div>
    <p v-if="myAll.length > MY_MAX" class="muted small" style="margin:.4rem 0 0">+ {{ myAll.length - MY_MAX }} autre{{ myAll.length - MY_MAX > 1 ? 's' : '' }} date{{ myAll.length - MY_MAX > 1 ? 's' : '' }} sur la période, dans le tableau ci-dessous.</p>
  </section>

  <div class="row toolbar">
    <label class="check"><input v-model="onlyMine" type="checkbox" /> Seulement mes sections</label>
    <span class="spacer" />
    <button class="btn btn-secondary btn-sm" :disabled="exporting || !data?.occurrences.length" @click="exportPdf"><AppIcon name="download" :size="15" /> {{ exporting ? 'Export…' : 'PDF' }}</button>
    <button v-if="auth.isReferent" class="btn btn-secondary btn-sm" @click="shareOpen = true"><AppIcon name="users" :size="15" /> Partager</button>
  </div>
  <p class="muted small hint">
    <template v-if="auth.isReferent">Cliquez sur une case pour la modifier ce jour-là · sur une date pour annuler le créneau.</template>
    <template v-else>Cliquez sur une case pour voir les bouteilles de la section (modifiables pour vos sections).</template>
  </p>

  <div v-if="loading && !data" class="card empty">Chargement…</div>
  <div v-else-if="data && !data.occurrences.length" class="card empty">
    <strong>Aucun créneau du club sur cette période</strong>
    <template v-if="auth.isReferent">Définissez les créneaux et leur plan habituel dans <router-link to="/slots">Créneaux</router-link>.</template>
  </div>
  <div v-else-if="data && !rows.length" class="card empty"><strong>Aucune ligne pour vos sections</strong>Décochez « Seulement mes sections » pour tout voir.</div>
  <PlanningTable v-else-if="data" :occurrences="rows" :columns-from="data.occurrences" :editable="auth.isReferent" show-tanks
    @cell="onCell" @date="(o) => openEdit(o, null)" @tanks="openTanks" />

  <!-- Modification d'une case / d'un créneau à une date (référents) -->
  <AppDialog v-model="edit.open" :title="editTitle">
    <template v-if="edit.o && edit.lane">
      <p class="muted small">Ce changement ne vaut que pour ce jour-là. Pour un changement régulier, modifiez le plan habituel ou ajoutez une règle dans <router-link to="/slots" @click="edit.open = false">Créneaux</router-link>.</p>
      <p class="small" style="margin:0 0 .4rem">Choisissez une section, ou deux pour <strong>partager la ligne</strong>.</p>
      <div class="choices">
        <button v-for="s in sections" :key="s.id" type="button" class="choice" :class="{ on: edit.mode === 'set' && edit.ids.includes(s.id) }"
          :style="edit.mode === 'set' && edit.ids.includes(s.id) ? { background: s.color, color: textOn(s.color), borderColor: s.color } : { borderColor: tint(s.color, 0.5) }"
          @click="toggleSection(s.id)">{{ s.name }}</button>
        <button type="button" class="choice" :class="{ on: edit.mode === 'set' && !edit.ids.length }" @click="edit.mode = 'set'; edit.ids = []">Libre</button>
        <button v-if="edit.lane.exception" type="button" class="choice" :class="{ on: edit.mode === 'same' }" @click="edit.mode = 'same'">Comme d'habitude</button>
      </div>
      <p class="muted small" style="margin:.5rem 0 0">
        {{ edit.mode === 'same' ? 'Retour au plan habituel.' : edit.ids.length ? `Ce jour-là : ${sections.filter((x) => edit.ids.includes(x.id)).map((x) => x.name).join(' + ')}` : 'Ce jour-là : ligne libre.' }}
      </p>
      <label class="check" style="margin-top:.8rem"><input v-model="edit.wholePool" type="checkbox" /> Appliquer à tout le bassin « {{ edit.lane.pool }} » ce jour-là</label>
    </template>
    <p v-else-if="edit.o?.cancelled">Ce créneau est annulé ce jour-là.</p>
    <p v-else-if="edit.o">Annuler ce créneau ce jour-là (piscine fermée, compétition…) ? Les lignes ne seront plus affichées.</p>
    <template #actions>
      <button v-if="edit.o && !edit.o.cancelled" class="btn btn-danger" @click="setCancelled(edit.o!, true)"><AppIcon name="x" :size="16" /> Annuler le créneau ce jour-là</button>
      <button v-if="edit.o?.cancelled" class="btn btn-ok" @click="setCancelled(edit.o!, false)"><AppIcon name="check" :size="16" /> Rétablir le créneau</button>
      <span class="spacer" />
      <button class="btn btn-ghost" @click="edit.open = false">Fermer</button>
      <button v-if="edit.lane" class="btn" @click="saveCell"><AppIcon name="check" /> Enregistrer</button>
    </template>
  </AppDialog>

  <ShareDialog v-if="auth.isReferent" v-model="shareOpen" />
  <TankDialog v-if="tank.open" v-model="tank.open" :slot-id="tank.slotId" :date="tank.date" :section-id="tank.sectionId" :can-edit="tank.canEdit" :time="tank.time" @changed="load" />
</template>

<style scoped>
.cap::first-letter { text-transform: uppercase }
.small { font-size: .85rem }
.hint { margin: 0 0 .6rem }
.seg { display: inline-flex; border: 1px solid var(--primary-100); border-radius: 8px; overflow: hidden; background: #fff }
.seg button { border: 0; background: none; font: inherit; font-weight: 600; padding: .45rem .9rem; color: var(--primary); cursor: pointer; min-height: 38px }
.seg button.on { background: var(--primary); color: #fff }
.toolbar { margin: .25rem 0 .4rem }
.mine { margin-bottom: 1rem; border-color: #bfe3cc; background: linear-gradient(0deg, #fff, #f3fbf6) }
.mine h2 { display: flex; gap: .4rem; align-items: center; color: var(--ok) }
.mine-row { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem .8rem; padding: .35rem 0; border-top: 1px dashed #d6ecdf }
.mine-row:first-of-type { border-top: 0 }
.mine-row .when { font-weight: 600; min-width: 170px }
.choices { display: flex; flex-wrap: wrap; gap: .4rem }
.choice { border: 2px solid var(--line); border-radius: 8px; padding: .45rem .8rem; cursor: pointer; font-weight: 600; background: #fff; font: inherit }
.choice.on { border-color: var(--primary); background: var(--primary-50) }
</style>
