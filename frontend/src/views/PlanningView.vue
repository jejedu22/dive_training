<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import PlanningGrid from '../components/PlanningGrid.vue'
import { get, post } from '../api'
import { useAuth } from '../stores/auth'
import { useToast } from '../stores/toast'
import { addDays, dateTime, dayLong, time, toLocalInput, today } from '../format'
import type { CalendarData, Pool, Reservation, Section, SlotOccurrence } from '../types'

const auth = useAuth(), toast = useToast(), router = useRouter(), route = useRoute()
const day = ref(typeof route.query.date === 'string' ? route.query.date : today())
const data = ref<CalendarData>({ resources: [], events: [], slots: [] })
const pools = ref<Pool[]>([])
const sections = ref<Section[]>([])
const pending = ref<Reservation[]>([])
const formError = ref('')
const saving = ref(false)

const form = ref({ sectionId: 0, title: '', start: '', end: '', laneIds: [] as number[], force: false })
const slotIndex = ref(-1)
const daySlots = computed(() => data.value.slots ?? [])

async function load() {
  const from = new Date(`${day.value}T00:00:00`), to = new Date(from.getTime() + 86400_000)
  try {
    data.value = await get<CalendarData>(`/calendar?from=${encodeURIComponent(from.toISOString())}&to=${encodeURIComponent(to.toISOString())}`)
    if (auth.isReferent) pending.value = await get<Reservation[]>('/reservations?status=PENDING')
  } catch (e) {
    toast.error(e)
  }
}

/** Sections proposées : d'abord celles que j'encadre. */
const sortedSections = computed(() => {
  const mine = (s: Section) => s.instructors.some((i) => i.id === auth.user?.id)
  return [...sections.value].sort((a, b) => Number(mine(b)) - Number(mine(a)) || a.name.localeCompare(b.name))
})
const slotLabel = (o: SlotOccurrence) => `${o.label} · ${time(o.start)}–${time(o.end)}`

/** Lignes cochables : celles ouvertes sur le créneau choisi (toutes en horaires libres). */
const visiblePools = computed(() => {
  const o = daySlots.value[slotIndex.value]
  if (!o) return pools.value
  return pools.value.map((p) => ({ ...p, lanes: p.lanes.filter((l) => o.laneIds.includes(l.id)) })).filter((p) => p.lanes.length)
})
/** Statut d'une ligne sur le créneau choisi (pour aider au choix). */
function laneHint(laneId: number): { text: string; cls: string } | null {
  const o = daySlots.value[slotIndex.value]
  if (!o) return null
  const a = o.allocations.find((x) => x.laneId === laneId)
  if (!a) return { text: 'libre', cls: 'chip-primary' }
  return a.sectionId === form.value.sectionId ? { text: a.section, cls: 'chip-ok' } : { text: a.section, cls: '' }
}

function useSlot(i: number) {
  slotIndex.value = i
  const o = daySlots.value[i]
  if (!o) return
  form.value.start = toLocalInput(o.start)
  form.value.end = toLocalInput(o.end)
  form.value.laneIds = form.value.laneIds.filter((id) => o.laneIds.includes(id))
}
function resetForm() {
  slotIndex.value = -1
  if (daySlots.value.length) useSlot(0)
  else {
    form.value.start = `${day.value}T19:00`
    form.value.end = `${day.value}T20:30`
  }
}
/** Préremplissage depuis « Réserver » de la page Lignes d'eau. */
function applyQuery() {
  const q = route.query
  if (typeof q.start === 'string') {
    const i = daySlots.value.findIndex((o) => new Date(o.start).getTime() === new Date(q.start as string).getTime())
    if (i >= 0) useSlot(i)
  }
  if (typeof q.lane === 'string') form.value.laneIds = [Number(q.lane)]
  if (typeof q.section === 'string') form.value.sectionId = Number(q.section)
  else if (!form.value.sectionId) {
    const mine = sortedSections.value.find((s) => s.instructors.some((x) => x.id === auth.user?.id))
    if (mine) form.value.sectionId = mine.id
  }
  if (q.lane) document.getElementById('new-session')?.scrollIntoView({ behavior: 'smooth' })
}

onMounted(async () => {
  try {
    ;[pools.value, sections.value] = await Promise.all([get<Pool[]>('/pools'), get<Section[]>('/sections')])
  } catch (e) {
    toast.error(e)
  }
  await load()
  resetForm()
  applyQuery()
})
watch(day, async () => {
  await load()
  resetForm()
})

async function create() {
  formError.value = ''
  saving.value = true
  try {
    const s = await post<{ id: number; reservations: Reservation[] }>('/sessions', {
      ...form.value, start: new Date(form.value.start).toISOString(), end: new Date(form.value.end).toISOString(),
    })
    const confirmed = s.reservations.every((r) => r.status === 'CONFIRMED')
    toast.ok(confirmed ? 'Séance créée : lignes confirmées.' : 'Séance créée : en attente de validation par un référent.')
    router.push(`/sessions/${s.id}`)
  } catch (e) {
    formError.value = (e as Error).message
  } finally {
    saving.value = false
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
const canCreate = computed(() => form.value.sectionId && form.value.title.trim() && form.value.laneIds.length && form.value.start && form.value.end)
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

  <form id="new-session" class="card" style="margin-top:1rem" @submit.prevent="create">
    <div class="card-head">
      <div><h2>Nouvelle séance</h2><p>Les lignes attribuées à la section sont confirmées directement ; les autres attendent un référent.</p></div>
    </div>

    <div v-if="!sections.length" class="alert alert-warn"><AppIcon name="info" /> <span>Créez d'abord une section dans <router-link to="/sections">Sections</router-link>.</span></div>

    <div class="fields">
      <label class="field">Section
        <select v-model.number="form.sectionId" required>
          <option :value="0" disabled>Choisir…</option>
          <option v-for="s in sortedSections" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
      </label>
      <label class="field">Titre<input v-model="form.title" placeholder="ex. Technique palmage" required /></label>
    </div>

    <div class="block">
      <div class="block-title">Horaire</div>
      <div v-if="daySlots.length" class="toggle-group">
        <label v-for="(o, i) in daySlots" :key="i" class="toggle" :class="{ on: slotIndex === i }"><input type="radio" :checked="slotIndex === i" @change="useSlot(i)" />{{ slotLabel(o) }}</label>
        <label class="toggle" :class="{ on: slotIndex === -1 }"><input type="radio" :checked="slotIndex === -1" @change="slotIndex = -1" />Horaires libres</label>
      </div>
      <p v-else class="muted" style="margin:.25rem 0">Pas de créneau club ce jour-là.</p>
      <div v-if="slotIndex === -1" class="fields" style="margin-top:.6rem">
        <label class="field">Début<input v-model="form.start" type="datetime-local" required /></label>
        <label class="field">Fin<input v-model="form.end" type="datetime-local" required /></label>
      </div>
    </div>

    <div class="block">
      <div class="block-title">Lignes d'eau</div>
      <p v-if="!visiblePools.length" class="muted">Aucune ligne ouverte sur ce créneau.</p>
      <div v-for="p in visiblePools" :key="p.id" class="lane-pick">
        <span class="pool-name">{{ p.name }}</span>
        <label v-for="l in p.lanes" :key="l.id" class="toggle" :class="{ on: form.laneIds.includes(l.id) }">
          <input v-model="form.laneIds" type="checkbox" :value="l.id" />{{ l.name }}
          <span v-if="laneHint(l.id)" class="chip hint" :class="laneHint(l.id)!.cls">{{ laneHint(l.id)!.text }}</span>
        </label>
      </div>
    </div>

    <label v-if="auth.isReferent" class="check" style="margin-top:.75rem"><input v-model="form.force" type="checkbox" /> Passer outre les créneaux et attributions (référent)</label>
    <div v-if="formError" class="alert alert-ko" style="margin-top:.75rem"><AppIcon name="alert" /> <span>{{ formError }}</span></div>
    <div class="row" style="margin-top:1rem"><button class="btn" :disabled="!canCreate || saving"><AppIcon name="plus" /> Créer la séance</button></div>
  </form>
</template>

<style scoped>
.cap::first-letter { text-transform: uppercase }
.legend { font-size: .82rem; margin: .5rem 0 0; display: flex; flex-wrap: wrap; align-items: center; gap: .3rem .5rem }
.sw { display: inline-block; width: 14px; height: 10px; border-radius: 3px }
.sw.confirmed { background: var(--ok) } .sw.pending { background: #d38a00 }
.sw.slot { background: rgba(11,106,168,.15); border: 1px solid rgba(11,106,168,.4) }
.sw.alloc { background: rgba(29,143,78,.2); border: 1px solid rgba(29,143,78,.5) }
.block { margin-top: 1rem }
.block-title { font-size: .88rem; color: var(--muted); font-weight: 600; margin-bottom: .4rem }
.lane-pick { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem; margin-bottom: .4rem }
.pool-name { font-weight: 600; min-width: 110px; font-size: .9rem }
.toggle .hint { margin-left: .35rem; font-size: .7rem; padding: 0 .4rem }
.toggle.on .hint { background: rgba(255,255,255,.25); color: #fff }
</style>
