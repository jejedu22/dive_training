<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import AppIcon from '../components/AppIcon.vue'
import AppDialog from '../components/AppDialog.vue'
import TankDialog from '../components/TankDialog.vue'
import { get, put } from '../api'
import { useAuth } from '../stores/auth'
import { useToast } from '../stores/toast'
import { addDays, dayShort, mondayOf, time, today } from '../format'
import { chipStyle, textOn, tint } from '../colors'
import type { Planning, PlanningLane, PlanningOccurrence, Section } from '../types'

/**
 * Le planning d'occupation des lignes d'eau.
 * Une ligne du tableau par date de créneau, une colonne par ligne d'eau : la section qui l'occupe ce jour-là.
 * Référent : clic sur une case pour la changer ce jour-là. Encadrant : clic sur sa section pour les bouteilles.
 */
const auth = useAuth(), toast = useToast()
const mode = ref<'week' | 'month'>(auth.prefs.planningView)
const anchor = ref(today())
const onlyMine = ref(auth.prefs.onlyMine)
const data = ref<Planning | null>(null)
const sections = ref<Section[]>([])
const loading = ref(false)

// ---------- période
const period = computed(() => {
  if (mode.value === 'week') {
    const from = mondayOf(anchor.value)
    return { from, to: addDays(from, 7) }
  }
  const [y, m] = anchor.value.split('-').map(Number)
  const next = m === 12 ? `${y + 1}-01-01` : `${y}-${String(m + 1).padStart(2, '0')}-01`
  return { from: `${anchor.value.slice(0, 7)}-01`, to: next }
})
const periodLabel = computed(() => {
  if (mode.value === 'month') return new Date(`${period.value.from}T12:00:00`).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
  const f = (d: string) => new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
  return `Semaine du ${f(period.value.from)} au ${f(addDays(period.value.to, -1))}`
})
function shift(n: number) {
  if (mode.value === 'week') anchor.value = addDays(anchor.value, 7 * n)
  else {
    const [y, m] = anchor.value.split('-').map(Number)
    const d = new Date(y, m - 1 + n, 1)
    anchor.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`
  }
}
const isCurrent = computed(() => today() >= period.value.from && today() < period.value.to)

let seq = 0
async function load() {
  const s = ++seq
  loading.value = true
  const from = new Date(`${period.value.from}T00:00:00`).toISOString(), to = new Date(`${period.value.to}T00:00:00`).toISOString()
  try {
    const r = await get<Planning>(`/planning?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`)
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

// ---------- tableau
/** Colonnes : toutes les lignes ouvertes sur la période, regroupées par bassin. */
const columns = computed(() => {
  const pools: { poolId: number; pool: string; lanes: { laneId: number; lane: string }[] }[] = []
  for (const o of data.value?.occurrences ?? []) {
    for (const l of o.lanes) {
      let p = pools.find((x) => x.poolId === l.poolId)
      if (!p) pools.push((p = { poolId: l.poolId, pool: l.pool, lanes: [] }))
      if (!p.lanes.some((x) => x.laneId === l.laneId)) p.lanes.push({ laneId: l.laneId, lane: l.lane })
    }
  }
  return pools
})
const laneCount = computed(() => columns.value.reduce((n, p) => n + p.lanes.length, 0))
const isMine = (o: PlanningOccurrence) => o.lanes.some((l) => l.sections.some((x) => x.mine))
const rows = computed(() => (data.value?.occurrences ?? []).filter((o) => !onlyMine.value || isMine(o)))
const cell = (o: PlanningOccurrence, laneId: number) => o.lanes.find((l) => l.laneId === laneId) ?? null
const isPast = (o: PlanningOccurrence) => new Date(o.end) < new Date()
/** Sections présentes sur une occurrence. */
const sectionsOf = (o: PlanningOccurrence) => {
  const m = new Map<number, PlanningLane['sections'][number]>()
  for (const l of o.lanes) for (const x of l.sections) m.set(x.id, x)
  return [...m.values()]
}
/** Sections qui utilisent des bouteilles (colonne Bouteilles, « Mes lignes »). */
const tankSectionsOf = (o: PlanningOccurrence) => sectionsOf(o).filter((x) => x.usesTanks)
const legend = computed(() => {
  const m = new Map<number, { id: number; name: string; color: string }>()
  for (const o of data.value?.occurrences ?? []) for (const s of sectionsOf(o)) m.set(s.id, s)
  return [...m.values()].sort((a, b) => a.name.localeCompare(b.name))
})

// ---------- mes accès
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
  if (!l || o.cancelled) {
    if (auth.isReferent && o.cancelled) openEdit(o, null)
    return
  }
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
        <button role="tab" :aria-selected="mode === 'week'" :class="{ on: mode === 'week' }" @click="mode = 'week'">Semaine</button>
        <button role="tab" :aria-selected="mode === 'month'" :class="{ on: mode === 'month' }" @click="mode = 'month'">Mois</button>
      </div>
      <button class="btn btn-secondary btn-icon" :title="mode === 'week' ? 'Semaine précédente' : 'Mois précédent'" @click="shift(-1)"><AppIcon name="left" /></button>
      <button class="btn btn-secondary" :disabled="isCurrent" @click="anchor = today()">Aujourd'hui</button>
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
          <AppIcon name="tank" :size="15" /> {{ s.name }} : {{ x.o.tanks[s.id] ?? 0 }} bouteille{{ (x.o.tanks[s.id] ?? 0) > 1 ? 's' : '' }}
        </button>
      </span>
    </div>
    <p v-if="myAll.length > MY_MAX" class="muted small" style="margin:.4rem 0 0">+ {{ myAll.length - MY_MAX }} autre{{ myAll.length - MY_MAX > 1 ? 's' : '' }} date{{ myAll.length - MY_MAX > 1 ? 's' : '' }} sur la période, dans le tableau ci-dessous.</p>
  </section>

  <div class="row toolbar">
    <label class="check"><input v-model="onlyMine" type="checkbox" /> Seulement mes sections</label>
    <span class="spacer" />
    <span v-if="auth.isReferent" class="muted small">Cliquez sur une case pour la modifier ce jour-là · sur une date pour annuler le créneau</span>
    <span v-else class="muted small">Cliquez sur une case pour voir les bouteilles de la section (modifiables pour vos sections)</span>
  </div>

  <div v-if="loading && !data" class="card empty">Chargement…</div>
  <div v-else-if="data && !data.occurrences.length" class="card empty">
    <strong>Aucun créneau du club sur cette période</strong>
    <template v-if="auth.isReferent">Définissez les créneaux et leur plan habituel dans <router-link to="/slots">Créneaux</router-link>.</template>
  </div>
  <div v-else-if="data && !rows.length" class="card empty"><strong>Aucune ligne pour vos sections</strong>Décochez « Seulement mes sections » pour tout voir.</div>

  <div v-else-if="data" class="grid-wrap">
    <table class="grid">
      <thead>
        <tr class="pools">
          <th class="sticky" rowspan="2">Créneau</th>
          <th v-for="p in columns" :key="p.poolId" :colspan="p.lanes.length" class="pool">{{ p.pool }}</th>
          <th rowspan="2" class="tanks-h"><AppIcon name="tank" :size="15" /> Bouteilles</th>
        </tr>
        <tr class="lanes">
          <template v-for="p in columns" :key="'l' + p.poolId"><th v-for="l in p.lanes" :key="l.laneId">{{ l.lane }}</th></template>
        </tr>
      </thead>
      <tbody>
        <tr v-for="o in rows" :key="o.date + o.slotId" :class="{ past: isPast(o), cancelled: o.cancelled }">
          <th class="sticky when" :class="{ clickable: auth.isReferent }" @click="auth.isReferent && openEdit(o, null)">
            <span class="d cap">{{ dayShort(o.start) }}</span>
            <span class="t">{{ time(o.start) }}–{{ time(o.end) }}</span>
            <span v-for="x in o.exceptions" :key="x.id" class="flag" :title="`${x.label ?? 'Exception'} : ${x.rule}`">⚑ {{ x.label ?? 'modifié' }}</span>
          </th>
          <td v-if="o.cancelled" :colspan="laneCount + 1" class="cancel-cell">Créneau annulé</td>
          <template v-else>
            <template v-for="p in columns" :key="'c' + p.poolId">
              <td v-for="c in p.lanes" :key="c.laneId" class="cell"
                :class="{ closed: !cell(o, c.laneId), free: cell(o, c.laneId) && !cell(o, c.laneId)!.sections.length, exc: cell(o, c.laneId)?.exception, clickable: auth.isReferent || !!cell(o, c.laneId)?.sections.some((x) => x.usesTanks) }"
                :title="cell(o, c.laneId) ? `${c.lane} : ${cell(o, c.laneId)!.sections.map((x) => x.name).join(' + ') || 'libre'}${cell(o, c.laneId)!.exception ? ' (modifié ce jour-là)' : ''}` : `${c.lane} : fermée sur ce créneau`"
                @click="onCell(o, cell(o, c.laneId))">
                <template v-if="cell(o, c.laneId)">
                  <div v-if="cell(o, c.laneId)!.sections.length" class="parts">
                    <span v-for="x in cell(o, c.laneId)!.sections" :key="x.id" class="part" :class="{ own: x.mine }" :style="{ background: x.color, color: textOn(x.color) }"><span class="pn">{{ x.name }}</span></span>
                  </div>
                  <span v-else class="name">libre</span>
                  <span v-if="cell(o, c.laneId)!.exception" class="mark">⚑</span>
                </template>
              </td>
            </template>
            <td class="tanks">
              <button v-for="s in tankSectionsOf(o)" :key="s.id" class="tank-chip" :style="chipStyle(s.color)" :title="`Bouteilles ${s.name}`" @click="openTanks(o, s)">
                {{ s.name }} <strong>{{ o.tanks[s.id] ?? 0 }}</strong>
              </button>
              <span v-if="!tankSectionsOf(o).length" class="muted">—</span>
            </td>
          </template>
        </tr>
      </tbody>
    </table>
  </div>

  <p v-if="legend.length" class="legend">
    <span v-for="s in legend" :key="s.id" class="chip" :style="chipStyle(s.color)">{{ s.name }}</span>
    <span class="muted">· <span class="sw free" /> libre · <span class="sw closed" /> fermée sur ce créneau · ⚑ modifié ce jour-là</span>
  </p>

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

  <TankDialog v-if="tank.open" v-model="tank.open" :slot-id="tank.slotId" :date="tank.date" :section-id="tank.sectionId" :can-edit="tank.canEdit" :time="tank.time" @changed="load" />
</template>

<style scoped>
.cap::first-letter { text-transform: uppercase }
.small { font-size: .85rem }
.seg { display: inline-flex; border: 1px solid var(--primary-100); border-radius: 8px; overflow: hidden; background: #fff }
.seg button { border: 0; background: none; font: inherit; font-weight: 600; padding: .45rem .9rem; color: var(--primary); cursor: pointer; min-height: 38px }
.seg button.on { background: var(--primary); color: #fff }
.toolbar { margin: .25rem 0 .6rem }
.mine { margin-bottom: 1rem; border-color: #bfe3cc; background: linear-gradient(0deg, #fff, #f3fbf6) }
.mine h2 { display: flex; gap: .4rem; align-items: center; color: var(--ok) }
.mine-row { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem .8rem; padding: .35rem 0; border-top: 1px dashed #d6ecdf }
.mine-row:first-of-type { border-top: 0 }
.when { font-weight: 600; min-width: 170px }

.grid-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: var(--radius); background: #fff; box-shadow: var(--shadow) }
.grid { border-collapse: separate; border-spacing: 0; width: 100%; font-size: .85rem }
.grid th, .grid td { border-bottom: 1px solid var(--line); border-right: 1px solid var(--line); padding: 0 }
.grid thead th { background: #f7f9fb; color: var(--muted); font-weight: 600; text-align: center; padding: .35rem .3rem; text-transform: none; letter-spacing: 0; font-size: .8rem }
.grid thead .pool { background: var(--primary-50); color: var(--primary-600) }
.sticky { position: sticky; left: 0; z-index: 2; background: #fff; text-align: left }
thead .sticky { z-index: 3; background: #f7f9fb }
.when { padding: .4rem .6rem !important; min-width: 120px; display: table-cell; font-weight: 400; text-transform: none; letter-spacing: 0; font-size: .85rem }
.when .d { display: block; font-weight: 700; color: var(--text) }
.when .t { display: block; color: var(--muted); font-size: .78rem }
.when .flag { display: block; color: #8a6100; font-size: .72rem; font-weight: 600 }
.when.clickable { cursor: pointer }
.when.clickable:hover { background: var(--primary-50) }
.cell { height: 46px; min-width: 76px; text-align: center; vertical-align: middle; position: relative; font-weight: 600 }
.cell .name { display: block; padding: 0 .3rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 110px; margin: 0 auto }
.parts { position: absolute; inset: 0; display: flex; flex-direction: column } /* ligne partagée : une section en haut, une en bas */
.part { flex: 1; display: flex; align-items: center; min-width: 0; min-height: 0; padding: 0 .25rem; font-size: .74rem; line-height: 1.1 }
.pn { flex: 1; min-width: 0; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; text-align: center }
.part + .part { border-top: 2px solid #fff }
.parts .part:only-child { font-size: .85rem }
.part.own { box-shadow: inset 0 0 0 3px rgba(255,255,255,.7) } /* mes sections */
.cell.free { color: #9aa6b2; font-weight: 400; background: #fff }
.cell.closed { background: repeating-linear-gradient(135deg, #f4f6f8 0 6px, #eceff3 6px 12px) }
.cell.exc { outline: 2px dashed #e0a21a; outline-offset: -3px }
.cell .mark { position: absolute; top: 1px; right: 3px; font-size: .7rem; z-index: 1 }
.cell.clickable { cursor: pointer }
.cell.clickable:hover { filter: brightness(.93) }
.tanks { padding: .3rem .4rem !important; min-width: 120px }
.tank-chip { border: 0; border-radius: 999px; padding: .15rem .5rem; font: inherit; font-size: .75rem; font-weight: 600; cursor: pointer; margin: 1px; white-space: nowrap }
.tanks-h { min-width: 120px }
tr.past td, tr.past th.when { opacity: .55 }
tr.cancelled .when .d { text-decoration: line-through }
.cancel-cell { text-align: center; color: var(--ko); font-weight: 600; background: repeating-linear-gradient(135deg, #fff 0 8px, #fdeeee 8px 16px) }
.legend { font-size: .82rem; margin: .6rem 0 0; display: flex; flex-wrap: wrap; align-items: center; gap: .3rem .5rem }
.sw { display: inline-block; width: 14px; height: 10px; border-radius: 3px; vertical-align: -1px; border: 1px solid var(--line) }
.sw.free { background: #fff }
.sw.closed { background: repeating-linear-gradient(135deg, #f4f6f8 0 3px, #dfe4ea 3px 6px) }
.choices { display: flex; flex-wrap: wrap; gap: .4rem }
.choice { border: 2px solid var(--line); border-radius: 8px; padding: .45rem .8rem; cursor: pointer; font-weight: 600; background: #fff }
.choice.on { border-color: var(--primary); background: var(--primary-50) }
.choice { font: inherit }
@media (max-width: 760px) {
  .when { min-width: 92px }
  .cell { min-width: 62px; height: 42px; font-size: .75rem }
  .parts .part:only-child { font-size: .72rem }
}
</style>
