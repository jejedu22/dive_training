<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import SlotPlanning from '../components/SlotPlanning.vue'
import MonthPlanning from '../components/MonthPlanning.vue'
import SessionForm from '../components/SessionForm.vue'
import { get, post } from '../api'
import { useAuth } from '../stores/auth'
import { useToast } from '../stores/toast'
import { addDays, dateTime, dayLong, range, time, today, ymd } from '../format'
import type { CalendarData, Pool, Reservation, Section, SlotOccurrence } from '../types'
import { chipStyle } from '../colors'

const auth = useAuth(), toast = useToast(), router = useRouter(), route = useRoute()
const day = ref(typeof route.query.date === 'string' ? route.query.date : today())
/** Vue « jour » (frises par créneau) ou « mois » (calendrier). Le choix est mémorisé sur l'appareil. */
const MODE_KEY = 'dive_planning_mode'
const readMode = (): 'day' | 'month' => {
  if (route.query.date) return 'day'
  try { return localStorage.getItem(MODE_KEY) === 'month' ? 'month' : 'day' } catch { return 'day' }
}
const mode = ref<'day' | 'month'>(readMode())
const month = ref(day.value.slice(0, 7))
const shiftMonth = (n: number) => {
  const [y, m] = month.value.split('-').map(Number)
  const d = new Date(y, m - 1 + n, 1)
  month.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}
const monthLabel = computed(() => new Date(`${month.value}-15T12:00:00`).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' }))
function pickDay(d: string) {
  day.value = d
  mode.value = 'day'
}
const searching = ref(false)
const data = ref<CalendarData>({ resources: [], events: [], slots: [] })
const pools = ref<Pool[]>([])
const sections = ref<Section[]>([])
const pending = ref<Reservation[]>([])
/** Numéro du dernier chargement : une réponse plus ancienne (ex. le jour, arrivée après le mois) est ignorée. */
let loadSeq = 0
async function load() {
  const seq = ++loadSeq
  const from = mode.value === 'month' ? new Date(`${month.value}-01T00:00:00`) : new Date(`${day.value}T00:00:00`)
  const to = mode.value === 'month' ? new Date(from.getFullYear(), from.getMonth() + 1, 1) : new Date(from.getTime() + 86400_000)
  try {
    const result = await get<CalendarData>(`/calendar?from=${encodeURIComponent(from.toISOString())}&to=${encodeURIComponent(to.toISOString())}`)
    if (seq !== loadSeq) return
    data.value = result
    if (auth.isReferent) pending.value = await get<Reservation[]>('/reservations?status=PENDING')
  } catch (e) {
    toast.error(e)
  }
}
onMounted(async () => {
  try {
    ;[pools.value, sections.value] = await Promise.all([get<Pool[]>('/pools'), get<Section[]>('/sections')])
  } catch (e) {
    toast.error(e)
  }
  // Sans date demandée : ouvrir sur le prochain jour de créneau (aujourd'hui s'il y en a un)
  if (typeof route.query.date !== 'string' && mode.value === 'day') await jump(1, true)
  if (!loadSeq) await load() // sinon déjà déclenché par le changement de jour
})
watch(day, () => mode.value === 'day' && load())
watch(month, () => mode.value === 'month' && load())
watch(mode, (m) => {
  try { localStorage.setItem(MODE_KEY, m) } catch { /* stockage indisponible : on ignore */ }
  if (m === 'month') month.value = day.value.slice(0, 7)
  load()
})

/**
 * Jour du créneau suivant (dir = 1) ou précédent (dir = -1), en cherchant sur 4 semaines
 * (pas 31 jours : avec un changement d'heure, la période dépasserait la limite de l'API).
 * fromToday : premier jour à partir d'aujourd'hui qui a un créneau (aujourd'hui compris).
 */
const SEARCH_DAYS = 28
async function jump(dir: 1 | -1, fromToday = false) {
  searching.value = true
  try {
    const base = fromToday ? today() : day.value
    const fromYmd = dir === 1 ? (fromToday ? base : addDays(base, 1)) : addDays(base, -SEARCH_DAYS)
    const { from, to } = range(fromYmd, SEARCH_DAYS)
    const occ = await get<SlotOccurrence[]>(`/slots/occurrences?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`)
    const days = [...new Set(occ.map((o) => ymd(new Date(o.start))))].filter((d) => (dir === 1 ? d >= fromYmd : d < base))
    const target = dir === 1 ? days[0] : days.at(-1)
    if (target) day.value = target
    else if (!fromToday) toast.show(`Aucun créneau ${dir === 1 ? 'dans les 4 prochaines semaines' : 'dans les 4 semaines précédentes'}.`, 'info')
  } catch (e) {
    toast.error(e)
  } finally {
    searching.value = false
  }
}

/** Sections visibles sur la période affichée (séances et lignes attribuées), pour la légende des couleurs. */
const daySections = computed(() => {
  const m = new Map<number, { id: number; name: string; color: string }>()
  for (const e of data.value.events) m.set(e.sectionId, { id: e.sectionId, name: e.section, color: e.color })
  for (const o of data.value.slots ?? []) for (const a of o.allocations) m.set(a.sectionId, { id: a.sectionId, name: a.section, color: a.color })
  return [...m.values()].sort((a, b) => a.name.localeCompare(b.name))
})

async function decide(r: Reservation, action: 'confirm' | 'reject') {
  try {
    await post(`/reservations/${r.id}/${action}`)
    toast.ok(action === 'confirm' ? 'Réservation validée.' : 'Réservation refusée.')
    await load()
  } catch (e) {
    toast.error(e)
  }
}
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Planning</h1>
      <p class="cap">{{ mode === 'day' ? dayLong(`${day}T12:00:00`) : monthLabel }}</p>
    </div>
    <div class="row">
      <div class="seg" role="tablist" aria-label="Affichage">
        <button role="tab" :aria-selected="mode === 'day'" :class="{ on: mode === 'day' }" @click="mode = 'day'">Jour</button>
        <button role="tab" :aria-selected="mode === 'month'" :class="{ on: mode === 'month' }" @click="mode = 'month'">Mois</button>
      </div>
      <template v-if="mode === 'day'">
        <button class="btn btn-secondary btn-icon" title="Créneau précédent" :disabled="searching" @click="jump(-1)"><AppIcon name="left" /></button>
        <input v-model="day" type="date" aria-label="Jour" />
        <button class="btn btn-secondary btn-icon" title="Créneau suivant" :disabled="searching" @click="jump(1)"><AppIcon name="right" /></button>
        <button class="btn btn-secondary" :disabled="searching" @click="jump(1, true)">Prochain créneau</button>
      </template>
      <template v-else>
        <button class="btn btn-secondary btn-icon" title="Mois précédent" @click="shiftMonth(-1)"><AppIcon name="left" /></button>
        <input v-model="month" type="month" aria-label="Mois" />
        <button class="btn btn-secondary btn-icon" title="Mois suivant" @click="shiftMonth(1)"><AppIcon name="right" /></button>
        <button class="btn btn-secondary" :disabled="month === today().slice(0, 7)" @click="month = today().slice(0, 7)">Ce mois</button>
      </template>
      <a href="#new-session" class="btn"><AppIcon name="plus" /> Nouvelle séance</a>
    </div>
  </div>

  <SlotPlanning v-if="mode === 'day'" :data="data" @open="(id) => router.push(`/sessions/${id}`)" />
  <MonthPlanning v-else :data="data" :month="month" @pick="pickDay" @open="(id) => router.push(`/sessions/${id}`)" />
  <p v-if="daySections.length" class="legend">
    <span v-for="sec in daySections" :key="sec.id" class="chip" :style="chipStyle(sec.color)"><span class="dot" :style="{ background: sec.color }" />{{ sec.name }}</span>
    <span class="muted">· plein = confirmée · <span class="sw striped" /> rayé = en attente de validation<template v-if="mode === 'month'"> · ⚠ hors créneau · cliquez sur un jour pour le détail</template></span>
  </p>

  <section v-if="auth.isReferent && pending.length" class="card" style="margin-top:1rem">
    <div class="card-head"><h2>Demandes à valider <span class="chip chip-warn">{{ pending.length }}</span></h2></div>
    <div class="table-wrap">
      <table>
        <tr><th>Séance</th><th>Ligne</th><th>Quand</th><th class="hide-mobile">Demandé par</th><th /></tr>
        <tr v-for="r in pending" :key="r.id">
          <td><router-link :to="`/sessions/${r.sessionId}`">{{ r.title }}</router-link><br /><span class="chip" :style="chipStyle(r.color)">{{ r.section }}</span></td>
          <td>{{ r.pool }} · {{ r.lane.name }}</td>
          <td>{{ dateTime(r.start) }}–{{ time(r.end) }}</td>
          <td class="hide-mobile">{{ r.requestedBy }}</td>
          <td><div class="row" style="justify-content:flex-end">
            <button class="btn btn-ok btn-sm" @click="decide(r, 'confirm')"><AppIcon name="check" :size="15" /> Valider</button>
            <button class="btn btn-danger btn-sm" @click="decide(r, 'reject')"><AppIcon name="x" :size="15" /> Refuser</button>
          </div></td>
        </tr>
      </table>
    </div>
  </section>

  <SessionForm :sections="sections" :pools="pools" style="margin-top:1rem" @created="load" />
</template>

<style scoped>
.cap::first-letter { text-transform: uppercase }
.legend { font-size: .82rem; margin: .5rem 0 0; display: flex; flex-wrap: wrap; align-items: center; gap: .3rem .5rem }
.sw { display: inline-block; width: 14px; height: 10px; border-radius: 3px }
.sw.striped { background: repeating-linear-gradient(135deg, #5d6d7e 0 4px, rgba(93,109,126,.5) 4px 8px); vertical-align: -1px }
.dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block }
.seg { display: inline-flex; border: 1px solid var(--primary-100); border-radius: 8px; overflow: hidden; background: #fff }
.seg button { border: 0; background: none; font: inherit; font-weight: 600; padding: .45rem .9rem; color: var(--primary); cursor: pointer; min-height: 38px }
.seg button.on { background: var(--primary); color: #fff }
</style>
