<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import SlotPlanning from '../components/SlotPlanning.vue'
import SessionForm from '../components/SessionForm.vue'
import { get, post } from '../api'
import { useAuth } from '../stores/auth'
import { useToast } from '../stores/toast'
import { addDays, dateTime, dayLong, range, time, today, ymd } from '../format'
import type { CalendarData, Pool, Reservation, Section, SlotOccurrence } from '../types'

const auth = useAuth(), toast = useToast(), router = useRouter(), route = useRoute()
const day = ref(typeof route.query.date === 'string' ? route.query.date : today())
const searching = ref(false)
const data = ref<CalendarData>({ resources: [], events: [], slots: [] })
const pools = ref<Pool[]>([])
const sections = ref<Section[]>([])
const pending = ref<Reservation[]>([])
async function load() {
  const from = new Date(`${day.value}T00:00:00`), to = new Date(from.getTime() + 86400_000)
  try {
    data.value = await get<CalendarData>(`/calendar?from=${encodeURIComponent(from.toISOString())}&to=${encodeURIComponent(to.toISOString())}`)
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
  if (typeof route.query.date !== 'string') await jump(1, true)
  await load()
})
watch(day, load)

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
      <p class="cap">{{ dayLong(`${day}T12:00:00`) }}</p>
    </div>
    <div class="row">
      <button class="btn btn-secondary btn-icon" title="Créneau précédent" :disabled="searching" @click="jump(-1)"><AppIcon name="left" /></button>
      <input v-model="day" type="date" aria-label="Jour" />
      <button class="btn btn-secondary btn-icon" title="Créneau suivant" :disabled="searching" @click="jump(1)"><AppIcon name="right" /></button>
      <button class="btn btn-secondary" :disabled="searching" @click="jump(1, true)">Prochain créneau</button>
      <a href="#new-session" class="btn"><AppIcon name="plus" /> Nouvelle séance</a>
    </div>
  </div>

  <SlotPlanning :data="data" @open="(id) => router.push(`/sessions/${id}`)" />
  <p class="legend muted">
    <span class="sw confirmed" /> confirmée <span class="sw pending" /> en attente
    <span class="sw alloc" /> ligne attribuée à une section
  </p>

  <section v-if="auth.isReferent && pending.length" class="card" style="margin-top:1rem">
    <div class="card-head"><h2>Demandes à valider <span class="chip chip-warn">{{ pending.length }}</span></h2></div>
    <div class="table-wrap">
      <table>
        <tr><th>Séance</th><th>Ligne</th><th>Quand</th><th class="hide-mobile">Demandé par</th><th /></tr>
        <tr v-for="r in pending" :key="r.id">
          <td><router-link :to="`/sessions/${r.sessionId}`">{{ r.title }}</router-link><br /><small>{{ r.section }}</small></td>
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
.sw.confirmed { background: var(--ok) } .sw.pending { background: #d38a00 }
.sw.alloc { background: rgba(29,143,78,.2); border: 1px solid rgba(29,143,78,.5) }
</style>
