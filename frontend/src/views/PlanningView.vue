<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { get, post } from '../api'
import { useAuth } from '../stores/auth'
import PlanningGrid from '../components/PlanningGrid.vue'
import type { CalendarData, Pool, Reservation, Section } from '../types'

const auth = useAuth(), router = useRouter()
const today = new Date()
const day = ref(`${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`)
const data = ref<CalendarData>({ resources: [], events: [] })
const pools = ref<Pool[]>([])
const sections = ref<Section[]>([])
const pending = ref<Reservation[]>([])
const error = ref('')

const form = ref({ sectionId: 0, title: '', start: '', end: '', laneIds: [] as number[] })

async function load() {
  const from = new Date(`${day.value}T00:00:00`), to = new Date(from.getTime() + 86400_000)
  data.value = await get<CalendarData>(`/calendar?from=${encodeURIComponent(from.toISOString())}&to=${encodeURIComponent(to.toISOString())}`)
  if (auth.isReferent) pending.value = await get<Reservation[]>('/reservations?status=PENDING')
}
onMounted(async () => {
  ;[pools.value, sections.value] = await Promise.all([get<Pool[]>('/pools'), get<Section[]>('/sections')])
  form.value.start = `${day.value}T19:00`
  form.value.end = `${day.value}T20:30`
  await load()
})
watch(day, load)

const shift = (n: number) => {
  const d = new Date(`${day.value}T12:00:00`)
  d.setDate(d.getDate() + n)
  day.value = d.toISOString().slice(0, 10)
}

async function create() {
  error.value = ''
  try {
    const s = await post<{ id: number }>('/sessions', {
      ...form.value, start: new Date(form.value.start).toISOString(), end: new Date(form.value.end).toISOString(),
    })
    router.push(`/sessions/${s.id}`)
  } catch (e) {
    error.value = (e as Error).message
  }
}
async function decide(r: Reservation, action: 'confirm' | 'reject') {
  error.value = ''
  try {
    await post(`/reservations/${r.id}/${action}`)
    await load()
  } catch (e) {
    error.value = (e as Error).message
  }
}
const fmt = (s: string) => new Date(s).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })
const canCreate = computed(() => form.value.sectionId && form.value.title && form.value.laneIds.length)
</script>

<template>
  <div class="row" style="margin-bottom:.8rem">
    <button class="secondary" @click="shift(-1)">◀</button>
    <input v-model="day" type="date" />
    <button class="secondary" @click="shift(1)">▶</button>
    <span><span class="badge CONFIRMED">confirmée</span> <span class="badge PENDING">en attente</span></span>
  </div>
  <PlanningGrid :data="data" :day="day" @open="(id) => router.push(`/sessions/${id}`)" />

  <p v-if="error" class="error">{{ error }}</p>

  <section v-if="auth.isReferent && pending.length" class="card" style="margin-top:1rem">
    <h3>Demandes à valider</h3>
    <table>
      <tr v-for="r in pending" :key="r.id">
        <td>{{ r.title }} — {{ r.section }}</td><td>{{ r.pool }} / {{ r.lane.name }}</td>
        <td>{{ fmt(r.start) }} → {{ fmt(r.end) }}</td><td>{{ r.requestedBy }}</td>
        <td class="row"><button class="ok" @click="decide(r, 'confirm')">Valider</button><button class="danger" @click="decide(r, 'reject')">Refuser</button></td>
      </tr>
    </table>
  </section>

  <form class="card" style="margin-top:1rem" @submit.prevent="create">
    <h3>Nouvelle séance</h3>
    <div class="row">
      <select v-model.number="form.sectionId" required>
        <option :value="0" disabled>Section…</option>
        <option v-for="s in sections" :key="s.id" :value="s.id">{{ s.name }}</option>
      </select>
      <input v-model="form.title" placeholder="Titre" required />
      <input v-model="form.start" type="datetime-local" required />
      <input v-model="form.end" type="datetime-local" required />
    </div>
    <div v-for="p in pools" :key="p.id" class="row" style="margin-top:.5rem">
      <strong>{{ p.name }}</strong>
      <label v-for="l in p.lanes" :key="l.id"><input v-model="form.laneIds" type="checkbox" :value="l.id" /> {{ l.name }}</label>
    </div>
    <button style="margin-top:.8rem" :disabled="!canCreate">Demander la réservation</button>
  </form>
</template>
