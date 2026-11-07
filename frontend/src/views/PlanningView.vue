<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import PlanningGrid from '../components/PlanningGrid.vue'
import SessionForm from '../components/SessionForm.vue'
import { get, post } from '../api'
import { useAuth } from '../stores/auth'
import { useToast } from '../stores/toast'
import { addDays, dateTime, dayLong, time, today } from '../format'
import type { CalendarData, Pool, Reservation, Section } from '../types'

const auth = useAuth(), toast = useToast(), router = useRouter(), route = useRoute()
const day = ref(typeof route.query.date === 'string' ? route.query.date : today())
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
  await load()
})
watch(day, load)

async function decide(r: Reservation, action: 'confirm' | 'reject') {
  try {
    await post(`/reservations/${r.id}/${action}`)
    toast.ok(action === 'confirm' ? 'Réservation validée.' : 'Réservation refusée.')
    await load()
  } catch (e) {
    toast.error(e)
  }
}
const isToday = computed(() => day.value === today())
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Planning</h1>
      <p class="cap">{{ dayLong(`${day}T12:00:00`) }}</p>
    </div>
    <div class="row">
      <button class="btn btn-secondary btn-icon" title="Jour précédent" @click="day = addDays(day, -1)"><AppIcon name="left" /></button>
      <input v-model="day" type="date" aria-label="Jour" />
      <button class="btn btn-secondary btn-icon" title="Jour suivant" @click="day = addDays(day, 1)"><AppIcon name="right" /></button>
      <button class="btn btn-secondary" :disabled="isToday" @click="day = today()">Aujourd'hui</button>
      <a href="#new-session" class="btn"><AppIcon name="plus" /> Nouvelle séance</a>
    </div>
  </div>

  <PlanningGrid :data="data" :day="day" @open="(id) => router.push(`/sessions/${id}`)" />
  <p class="legend muted">
    <span class="sw confirmed" /> confirmée <span class="sw pending" /> en attente
    <span class="sw slot" /> créneau club (ligne libre) <span class="sw alloc" /> ligne attribuée à une section
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
.sw.slot { background: rgba(11,106,168,.15); border: 1px solid rgba(11,106,168,.4) }
.sw.alloc { background: rgba(29,143,78,.2); border: 1px solid rgba(29,143,78,.5) }
</style>
