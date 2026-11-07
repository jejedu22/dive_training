<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { get, post } from '../api'
import { useAuth } from '../stores/auth'
import PlanningGrid from '../components/PlanningGrid.vue'
import type { CalendarData, Pool, Reservation, Section, SlotOccurrence } from '../types'

const auth = useAuth(), router = useRouter()
const today = new Date()
const day = ref(`${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`)
const data = ref<CalendarData>({ resources: [], events: [], slots: [] })
const pools = ref<Pool[]>([])
const sections = ref<Section[]>([])
const pending = ref<Reservation[]>([])
const error = ref('')

const form = ref({ sectionId: 0, title: '', start: '', end: '', laneIds: [] as number[], force: false })

// « 2026-12-07T20:00:00+01:00 » -> valeur locale pour <input type="datetime-local">
const toLocalInput = (iso: string) => {
  const d = new Date(iso)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`
}
const hhmm = (iso: string) => new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
const slotLabel = (o: SlotOccurrence) => `${o.label} ${hhmm(o.start)}–${hhmm(o.end)}${o.pool ? ` (${o.pool})` : ''}`
const daySlots = computed(() => data.value.slots ?? [])
const slotIndex = ref(-1)

/** Applique un créneau du club au formulaire (horaires + lignes compatibles). */
function useSlot(i: number) {
  slotIndex.value = i
  const o = daySlots.value[i]
  if (!o) return
  form.value.start = toLocalInput(o.start)
  form.value.end = toLocalInput(o.end)
  if (o.poolId !== null) {
    const allowed = new Set(pools.value.find((p) => p.id === o.poolId)?.lanes.map((l) => l.id) ?? [])
    form.value.laneIds = form.value.laneIds.filter((id) => allowed.has(id))
  }
}
/** Lignes cochables : si un créneau limité à un bassin est choisi, seulement ce bassin. */
const visiblePools = computed(() => {
  const o = daySlots.value[slotIndex.value]
  return o && o.poolId !== null ? pools.value.filter((p) => p.id === o.poolId) : pools.value
})
/** Positionne le formulaire sur le jour affiché : 1er créneau du club s'il y en a, sinon 19:00–20:30. */
function resetFormTimes() {
  slotIndex.value = -1
  if (daySlots.value.length) useSlot(0)
  else {
    form.value.start = `${day.value}T19:00`
    form.value.end = `${day.value}T20:30`
  }
}

async function load() {
  const from = new Date(`${day.value}T00:00:00`), to = new Date(from.getTime() + 86400_000)
  data.value = await get<CalendarData>(`/calendar?from=${encodeURIComponent(from.toISOString())}&to=${encodeURIComponent(to.toISOString())}`)
  if (auth.isReferent) pending.value = await get<Reservation[]>('/reservations?status=PENDING')
}
onMounted(async () => {
  ;[pools.value, sections.value] = await Promise.all([get<Pool[]>('/pools'), get<Section[]>('/sections')])
  await load()
  resetFormTimes()
})
watch(day, async () => {
  await load()
  resetFormTimes()
})

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
    <span><span class="badge CONFIRMED">confirmée</span> <span class="badge PENDING">en attente</span> <span class="badge slot-badge">créneau club</span></span>
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
      <input v-model="form.start" type="datetime-local" required @input="slotIndex = -1" />
      <input v-model="form.end" type="datetime-local" required @input="slotIndex = -1" />
    </div>
    <div class="row" style="margin-top:.5rem">
      <template v-if="daySlots.length">
        <strong>Créneau du club :</strong>
        <label v-for="(o, i) in daySlots" :key="i" class="slot-choice" :class="{ on: slotIndex === i }">
          <input type="radio" :checked="slotIndex === i" @change="useSlot(i)" /> {{ slotLabel(o) }}
        </label>
        <label class="slot-choice" :class="{ on: slotIndex === -1 }">
          <input type="radio" :checked="slotIndex === -1" @change="slotIndex = -1" /> Horaires libres
        </label>
      </template>
      <em v-else-if="data.slots !== undefined">Pas de créneau club ce jour-là.</em>
    </div>
    <div v-for="p in visiblePools" :key="p.id" class="row" style="margin-top:.5rem">
      <strong>{{ p.name }}</strong>
      <label v-for="l in p.lanes" :key="l.id"><input v-model="form.laneIds" type="checkbox" :value="l.id" /> {{ l.name }}</label>
    </div>
    <label v-if="auth.isReferent" style="display:block;margin-top:.5rem">
      <input v-model="form.force" type="checkbox" /> Autoriser hors des créneaux du club (référent)
    </label>
    <button style="margin-top:.8rem" :disabled="!canCreate">Demander la réservation</button>
  </form>
</template>

<style scoped>
.slot-choice { border:1px solid var(--line); border-radius:4px; padding:.25rem .5rem; cursor:pointer; background:#fff }
.slot-choice.on { border-color:var(--blue); background:#e8f1f8 }
.slot-choice input { margin:0 .25rem 0 0 }
.slot-badge { background:rgba(11,106,168,.15); color:var(--blue); border:1px solid rgba(11,106,168,.5) }
</style>
