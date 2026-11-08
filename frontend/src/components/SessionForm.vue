<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppIcon from './AppIcon.vue'
import { get, post } from '../api'
import { useAuth } from '../stores/auth'
import { useToast } from '../stores/toast'
import { addDays, dayShort, range, time, today } from '../format'
import type { ClubSlot, Occupancy, Occurrence, OccupancyLane, Pool, Reservation, Section } from '../types'

/**
 * Création d'une séance : on choisit la section, puis un créneau du club parmi ceux où elle a accès
 * à au moins une ligne, puis les lignes. Pas d'horaires libres, sauf séance exceptionnelle (référent)
 * ou si le club n'a défini aucun créneau.
 */
const props = defineProps<{ sections: Section[]; pools: Pool[] }>()
const emit = defineEmits<{ created: [] }>()
const auth = useAuth(), toast = useToast(), router = useRouter(), route = useRoute()

const WEEKS = 4
const occurrences = ref<Occurrence[]>([])
const loadedUntil = ref(today())
const hasSlots = ref<boolean | null>(null)
const loading = ref(false)

const sectionId = ref(0)
const title = ref('')
const key = ref('')            // créneau choisi : start|slotId
const laneIds = ref<number[]>([])
const exceptional = ref(false) // hors créneau (référent) ou aucun créneau défini
const freeStart = ref(`${today()}T19:00`)
const freeEnd = ref(`${today()}T20:30`)
const error = ref('')
const saving = ref(false)

const keyOf = (o: Occurrence) => `${o.start}|${o.slotId}`
const selected = computed(() => occurrences.value.find((o) => keyOf(o) === key.value) ?? null)
const freeMode = computed(() => exceptional.value || hasSlots.value === false)

async function fetchRange(fromYmd: string, days: number) {
  const { from, to } = range(fromYmd, days)
  const data = await get<Occupancy>(`/occupancy?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`)
  const now = Date.now()
  return data.occurrences.filter((o) => new Date(o.end).getTime() > now)
}
async function load() {
  loading.value = true
  try {
    const [slots, occ] = await Promise.all([get<ClubSlot[]>('/slots'), fetchRange(today(), WEEKS * 7)])
    hasSlots.value = slots.length > 0
    occurrences.value = occ
    loadedUntil.value = addDays(today(), WEEKS * 7)
  } catch (e) {
    toast.error(e)
  } finally {
    loading.value = false
  }
}
async function loadMore() {
  loading.value = true
  try {
    occurrences.value.push(...(await fetchRange(loadedUntil.value, WEEKS * 7)))
    loadedUntil.value = addDays(loadedUntil.value, WEEKS * 7)
  } catch (e) {
    toast.error(e)
  } finally {
    loading.value = false
  }
}

/** Sections proposées : d'abord celles que j'encadre. */
const sortedSections = computed(() => {
  const mine = (s: Section) => s.instructors.some((i) => i.id === auth.user?.id)
  return [...props.sections].sort((a, b) => Number(mine(b)) - Number(mine(a)) || a.name.localeCompare(b.name))
})

type LaneState = 'mine' | 'free' | 'pending' | 'taken' | 'booked'
/** Accès de la section choisie à une ligne sur un créneau. */
function laneState(l: OccupancyLane): { state: LaneState; text: string } {
  if (l.reservations.some((r) => r.sectionId === sectionId.value)) return { state: 'booked', text: 'déjà réservée par la section' }
  if (l.allocation && l.allocation.sectionId !== sectionId.value) return { state: 'taken', text: l.allocation.section }
  const confirmed = l.reservations.find((r) => r.status === 'CONFIRMED')
  if (confirmed) return { state: 'booked', text: `occupée · ${confirmed.section}` }
  if (l.allocation) return { state: 'mine', text: 'attribuée' }
  if (l.reservations.length) return { state: 'pending', text: `demandée · ${l.reservations[0].section}` }
  return { state: 'free', text: 'libre' }
}
const usable = (l: OccupancyLane) => ['mine', 'free', 'pending'].includes(laneState(l).state)
const counts = (o: Occurrence) => ({
  mine: o.lanes.filter((l) => laneState(l).state === 'mine').length,
  free: o.lanes.filter((l) => ['free', 'pending'].includes(laneState(l).state)).length,
})
/** Créneaux où la section a au moins une ligne utilisable, groupés par jour. */
const available = computed(() => (sectionId.value ? occurrences.value.filter((o) => o.lanes.some(usable)) : []))
const byDay = computed(() => {
  const groups: { day: string; items: Occurrence[] }[] = []
  for (const o of available.value) {
    const d = dayShort(o.start)
    if (groups.at(-1)?.day !== d) groups.push({ day: d, items: [] })
    groups.at(-1)!.items.push(o)
  }
  return groups
})
/** Lignes du créneau choisi, par bassin. */
const lanesByPool = computed(() => {
  const o = selected.value
  if (!o) return []
  const groups = new Map<string, OccupancyLane[]>()
  for (const l of o.lanes) {
    if (!groups.has(l.pool)) groups.set(l.pool, [])
    groups.get(l.pool)!.push(l)
  }
  return [...groups.entries()].map(([pool, lanes]) => ({ pool, lanes }))
})

function choose(o: Occurrence) {
  key.value = keyOf(o)
  // présélection : les lignes attribuées à la section
  laneIds.value = o.lanes.filter((l) => laneState(l).state === 'mine').map((l) => l.laneId)
}
watch(sectionId, () => {
  if (selected.value && !selected.value.lanes.some(usable)) key.value = ''
  if (selected.value) choose(selected.value)
})
watch(exceptional, (v) => {
  if (v) laneIds.value = []
})

/** Préremplissage depuis « Réserver » (page Lignes d'eau) : ?section=&start=&lane= */
function applyQuery() {
  const q = route.query
  if (typeof q.section === 'string') sectionId.value = Number(q.section)
  else {
    const mine = sortedSections.value.find((s) => s.instructors.some((x) => x.id === auth.user?.id))
    if (mine) sectionId.value = mine.id
  }
  if (typeof q.start === 'string') {
    const o = occurrences.value.find((x) => new Date(x.start).getTime() === new Date(q.start as string).getTime())
    if (o) {
      choose(o)
      if (typeof q.lane === 'string') laneIds.value = [Number(q.lane)]
    }
    setTimeout(() => document.getElementById('new-session')?.scrollIntoView({ behavior: 'smooth' }), 50)
  }
}
onMounted(async () => {
  await load()
  applyQuery()
})
watch(() => props.sections, () => sectionId.value || applyQuery())

const canCreate = computed(() =>
  sectionId.value && title.value.trim() && laneIds.value.length && (freeMode.value ? freeStart.value && freeEnd.value : selected.value))

async function create() {
  error.value = ''
  saving.value = true
  const [start, end] = freeMode.value
    ? [new Date(freeStart.value).toISOString(), new Date(freeEnd.value).toISOString()]
    : [selected.value!.start, selected.value!.end]
  try {
    const s = await post<{ id: number; reservations: Reservation[] }>('/sessions', {
      sectionId: sectionId.value, title: title.value, start, end, laneIds: laneIds.value, force: exceptional.value,
    })
    const confirmed = s.reservations.every((r) => r.status === 'CONFIRMED')
    toast.ok(confirmed ? 'Séance créée : lignes confirmées.' : 'Séance créée : en attente de validation par un référent.')
    emit('created')
    router.push(`/sessions/${s.id}`)
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <form id="new-session" class="card" @submit.prevent="create">
    <div class="card-head">
      <div><h2>Nouvelle séance</h2><p>Choisissez la section puis un créneau du club où elle a accès à des lignes d'eau.</p></div>
    </div>

    <div v-if="!sections.length" class="alert alert-warn"><AppIcon name="info" /> <span>Créez d'abord une section dans <router-link to="/sections">Sections</router-link>.</span></div>

    <div class="fields">
      <label class="field">1. Section
        <select v-model.number="sectionId" required>
          <option :value="0" disabled>Choisir…</option>
          <option v-for="s in sortedSections" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
      </label>
      <label class="field">Titre de la séance<input v-model="title" placeholder="ex. Technique palmage" required /></label>
    </div>

    <!-- 2. Créneau -->
    <div class="block">
      <div class="block-title">2. Créneau</div>
      <div v-if="hasSlots === false" class="alert"><AppIcon name="info" /> <span>Le club n'a défini aucun créneau : indiquez les horaires.</span></div>
      <template v-else-if="!exceptional">
        <p v-if="!sectionId" class="muted">Choisissez d'abord la section.</p>
        <p v-else-if="loading && !occurrences.length" class="muted">Chargement des créneaux…</p>
        <div v-else-if="!available.length" class="alert alert-warn"><AppIcon name="alert" />
          <span>Aucun créneau avec une ligne disponible pour cette section d'ici le {{ new Date(`${loadedUntil}T12:00:00`).toLocaleDateString('fr-FR') }}.</span></div>
        <div v-else class="days">
          <div v-for="g in byDay" :key="g.day" class="day">
            <div class="day-name">{{ g.day }}</div>
            <div class="slots">
              <label v-for="o in g.items" :key="keyOf(o)" class="slot" :class="{ on: key === keyOf(o) }">
                <input type="radio" name="slot" :checked="key === keyOf(o)" @change="choose(o)" />
                <strong>{{ time(o.start) }}–{{ time(o.end) }}</strong>
                <span class="muted">{{ o.label }}</span>
                <span class="row counts">
                  <span v-if="counts(o).mine" class="chip chip-ok">{{ counts(o).mine }} attribuée{{ counts(o).mine > 1 ? 's' : '' }}</span>
                  <span v-if="counts(o).free" class="chip chip-free">{{ counts(o).free }} libre{{ counts(o).free > 1 ? 's' : '' }}</span>
                </span>
              </label>
            </div>
          </div>
        </div>
        <button v-if="sectionId && hasSlots" type="button" class="btn btn-ghost btn-sm" style="margin-top:.5rem" :disabled="loading" @click="loadMore">
          Voir les {{ WEEKS }} semaines suivantes
        </button>
      </template>
      <div v-if="freeMode" class="fields" style="margin-top:.5rem">
        <label class="field">Début<input v-model="freeStart" type="datetime-local" required /></label>
        <label class="field">Fin<input v-model="freeEnd" type="datetime-local" required /></label>
      </div>
      <label v-if="auth.isReferent && hasSlots" class="check" style="margin-top:.6rem">
        <input v-model="exceptional" type="checkbox" /> Séance exceptionnelle hors créneau (référent)
      </label>
    </div>

    <!-- 3. Lignes -->
    <div class="block">
      <div class="block-title">3. Lignes d'eau</div>
      <template v-if="freeMode">
        <div v-for="p in pools" :key="p.id" class="lane-pick">
          <span class="pool-name">{{ p.name }}</span>
          <label v-for="l in p.lanes" :key="l.id" class="toggle" :class="{ on: laneIds.includes(l.id) }"><input v-model="laneIds" type="checkbox" :value="l.id" />{{ l.name }}</label>
        </div>
      </template>
      <p v-else-if="!selected" class="muted">Choisissez un créneau.</p>
      <template v-else>
        <div v-for="g in lanesByPool" :key="g.pool" class="lane-pick">
          <span class="pool-name">{{ g.pool }}</span>
          <label v-for="l in g.lanes" :key="l.laneId" class="toggle" :class="{ on: laneIds.includes(l.laneId), off: !usable(l) }" :title="laneState(l).text">
            <input v-model="laneIds" type="checkbox" :value="l.laneId" :disabled="!usable(l)" />{{ l.lane }}
            <span class="chip hint" :class="{ 'chip-ok': laneState(l).state === 'mine', 'chip-free': laneState(l).state === 'free', 'chip-warn': laneState(l).state === 'pending' }">{{ laneState(l).text }}</span>
          </label>
        </div>
        <p class="muted note">Lignes attribuées : confirmées directement. Lignes libres : validées par un référent.</p>
      </template>
    </div>

    <div v-if="error" class="alert alert-ko" style="margin-top:.75rem"><AppIcon name="alert" /> <span>{{ error }}</span></div>
    <div class="row" style="margin-top:1rem"><button class="btn" :disabled="!canCreate || saving"><AppIcon name="plus" /> Créer la séance</button></div>
  </form>
</template>

<style scoped>
.block { margin-top: 1.1rem }
.block-title { font-size: .88rem; color: var(--muted); font-weight: 600; margin-bottom: .5rem }
.days { display: flex; flex-direction: column; gap: .5rem; max-height: 340px; overflow-y: auto; padding-right: .25rem }
.day { display: flex; gap: .75rem; align-items: flex-start }
.day-name { width: 105px; flex: none; font-weight: 600; padding-top: .55rem; text-transform: capitalize }
.slots { display: flex; flex-wrap: wrap; gap: .4rem; flex: 1 }
.slot { display: flex; flex-wrap: wrap; align-items: center; gap: .25rem .5rem; border: 1px solid var(--line); border-radius: 9px; padding: .45rem .7rem; cursor: pointer; background: #fff }
.slot:hover { border-color: var(--primary-100); background: var(--primary-50) }
.slot.on { border-color: var(--primary); background: var(--primary-50); box-shadow: 0 0 0 1px var(--primary) }
.slot input { display: none }
.counts { gap: .25rem }
.lane-pick { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem; margin-bottom: .45rem }
.pool-name { font-weight: 600; min-width: 110px; font-size: .9rem }
.toggle .hint { margin-left: .35rem; font-size: .7rem; padding: 0 .4rem }
.toggle.on .hint { background: rgba(255,255,255,.25); color: #fff }
.toggle.off { opacity: .55; cursor: not-allowed; background: #f5f7f9 }
.note { font-size: .82rem; margin: .25rem 0 0 }
@media (max-width: 760px) { .day { flex-direction: column; gap: .3rem } .day-name { padding-top: 0 } .days { max-height: none } }
</style>
