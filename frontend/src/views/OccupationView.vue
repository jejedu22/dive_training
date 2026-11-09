<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import { get } from '../api'
import { useAuth } from '../stores/auth'
import { useToast } from '../stores/toast'
import { addDays, dayLong, dayShort, mondayOf, range, time, today, ymd } from '../format'
import type { Occupancy, Occurrence, OccupancyLane } from '../types'
import { chipStyle, tint } from '../colors'

const auth = useAuth(), toast = useToast(), router = useRouter()
const week = ref(mondayOf(today()))
const data = ref<Occupancy | null>(null)
const loading = ref(false)
const onlyMine = ref(auth.prefs.onlyMine) // préférence du compte

async function load() {
  loading.value = true
  try {
    const { from, to } = range(week.value, 7)
    data.value = await get<Occupancy>(`/occupancy?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`)
  } catch (e) {
    toast.error(e)
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(week, load)

const weekLabel = computed(() => {
  const end = addDays(week.value, 6)
  const f = (d: string) => new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
  return `Semaine du ${f(week.value)} au ${f(end)}`
})
const isCurrentWeek = computed(() => week.value === mondayOf(today()))
const hasMySections = computed(() => (data.value?.mySectionIds.length ?? 0) > 0)

const isMine = (l: OccupancyLane) => !!l.allocation?.mine || l.reservations.some((r) => r.mine)
const isFree = (l: OccupancyLane) => !l.allocation && !l.reservations.some((r) => r.status !== 'REJECTED')
const isPast = (o: Occurrence) => new Date(o.end) < new Date()

/** Lignes regroupées par bassin, filtrées selon « mes accès ». */
function pools(o: Occurrence) {
  const groups = new Map<string, OccupancyLane[]>()
  for (const l of o.lanes) {
    if (onlyMine.value && !isMine(l)) continue
    if (!groups.has(l.pool)) groups.set(l.pool, [])
    groups.get(l.pool)!.push(l)
  }
  return [...groups.entries()].map(([pool, lanes]) => ({ pool, lanes }))
}
const visible = computed(() => (data.value?.occurrences ?? []).filter((o) => !onlyMine.value || (!o.cancelled && o.lanes.some(isMine))))

/** Résumé « mes accès » : lignes attribuées à mes sections, par créneau à venir. */
const myAccess = computed(() =>
  (data.value?.occurrences ?? [])
    .filter((o) => !isPast(o) && !o.cancelled)
    .map((o) => ({ o, lanes: o.lanes.filter((l) => l.allocation?.mine) }))
    .filter((x) => x.lanes.length),
)
const freeCount = (o: Occurrence) => o.lanes.filter(isFree).length

function book(o: Occurrence, l: OccupancyLane) {
  router.push({ path: '/planning', query: { date: ymd(new Date(o.start)), start: o.start, end: o.end, lane: String(l.laneId), section: l.allocation?.mine ? String(l.allocation.sectionId) : undefined } })
}
const canBook = (o: Occurrence, l: OccupancyLane) => !isPast(o) && !o.cancelled && (l.allocation ? l.allocation.mine : true) && !l.reservations.some((r) => r.mine)
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Lignes d'eau</h1>
      <p>Ce qui est ouvert au club, créneau par créneau, et ce à quoi vos sections ont accès.</p>
    </div>
    <div class="row">
      <button class="btn btn-secondary btn-icon" title="Semaine précédente" @click="week = addDays(week, -7)"><AppIcon name="left" /></button>
      <button class="btn btn-secondary" :disabled="isCurrentWeek" @click="week = mondayOf(today())">Cette semaine</button>
      <button class="btn btn-secondary btn-icon" title="Semaine suivante" @click="week = addDays(week, 7)"><AppIcon name="right" /></button>
    </div>
  </div>

  <div class="row toolbar">
    <strong>{{ weekLabel }}</strong>
    <span class="spacer" />
    <label class="check"><input v-model="onlyMine" type="checkbox" /> Seulement mes accès</label>
  </div>

  <div v-if="data && !hasMySections" class="alert" style="margin-bottom:1rem">
    <AppIcon name="info" /> <span>Vous n'encadrez encore aucune section. Ajoutez-vous comme encadrant dans <router-link to="/sections">Sections</router-link> pour voir vos lignes attribuées.</span>
  </div>

  <section v-if="myAccess.length" class="card mine-card">
    <div class="card-head"><h2><AppIcon name="star" :size="17" /> Mes accès</h2></div>
    <ul class="access">
      <li v-for="x in myAccess" :key="x.o.start + x.o.slotId">
        <span class="when">{{ dayShort(x.o.start) }} · {{ time(x.o.start) }}–{{ time(x.o.end) }}</span>
        <span class="row"><span v-for="l in x.lanes" :key="l.laneId" class="chip" :style="chipStyle(l.allocation!.color)">{{ l.pool }} · {{ l.lane }} — {{ l.allocation!.section }}</span></span>
      </li>
    </ul>
  </section>

  <p v-if="loading && !data" class="empty">Chargement…</p>
  <div v-else-if="data && !data.occurrences.length" class="card empty">
    <strong>Aucun créneau du club cette semaine</strong>
    <template v-if="auth.isReferent">Définissez les créneaux et les lignes ouvertes dans <router-link to="/slots">Créneaux</router-link>.</template>
    <template v-else>Les créneaux sont définis par un référent.</template>
  </div>
  <div v-else-if="data && !visible.length" class="card empty"><strong>Rien pour vos sections cette semaine</strong>Décochez « Seulement mes accès » pour tout voir.</div>

  <div class="stack">
    <article v-for="o in visible" :key="o.start + o.slotId" class="card occ" :class="{ past: isPast(o), cancelled: o.cancelled }">
      <div class="card-head">
        <div>
          <h2 class="cap">{{ dayLong(o.start) }}</h2>
          <p><AppIcon name="clock" :size="14" /> {{ time(o.start) }}–{{ time(o.end) }} · {{ o.label }}</p>
        </div>
        <span v-if="o.cancelled" class="chip chip-ko">Créneau annulé</span>
        <span v-else class="chip" :class="freeCount(o) ? 'chip-free' : ''">{{ freeCount(o) }} ligne{{ freeCount(o) > 1 ? 's' : '' }} libre{{ freeCount(o) > 1 ? 's' : '' }}</span>
      </div>
      <p v-if="o.exceptions.length" class="exc-line">
        <span v-for="x in o.exceptions" :key="x.id" class="chip chip-exc" :title="x.rule">⚑ {{ x.label ?? (x.cancelled ? 'Annulation' : 'Exception') }} · {{ x.rule }}</span>
      </p>
      <template v-if="!o.cancelled">
      <div v-for="g in pools(o)" :key="g.pool" class="pool">
        <h3>{{ g.pool }}</h3>
        <div class="lanes">
          <div v-for="l in g.lanes" :key="l.laneId" class="lane" :class="{ mine: isMine(l), taken: l.allocation && !l.allocation.mine, free: isFree(l) }"
            :style="l.allocation ? { background: tint(l.allocation.color, l.allocation.mine ? 0.09 : 0.05), borderColor: tint(l.allocation.color, l.allocation.mine ? 0.6 : 0.3), borderLeft: `4px solid ${l.allocation.color}` } : {}">
            <div class="lane-top">
              <strong>{{ l.lane }}</strong>
              <span v-if="l.allocation" class="chip" :style="chipStyle(l.allocation.color)"><AppIcon v-if="l.allocation.mine" name="star" :size="12" /> {{ l.allocation.section }}</span>
              <span v-else-if="isFree(l)" class="chip chip-free">Libre</span>
            </div>
            <span v-if="l.exception" class="exc-tag">⚑ ce jour-là</span>
            <router-link v-for="r in l.reservations" :key="r.id" :to="`/sessions/${r.sessionId}`" class="res">
              <span class="dot" :style="r.status === 'PENDING' ? { boxShadow: `inset 0 0 0 2px ${r.color}` } : { background: r.color }" :title="r.status === 'PENDING' ? 'en attente' : 'confirmée'" />{{ r.title }} <small>· {{ r.section }} · {{ time(r.start) }}–{{ time(r.end) }}{{ r.status === 'PENDING' ? ' · en attente' : '' }}</small>
            </router-link>
            <button v-if="canBook(o, l)" class="btn btn-secondary btn-sm book" @click="book(o, l)"><AppIcon name="plus" :size="15" /> Réserver</button>
          </div>
        </div>
      </div>
      </template>
    </article>
  </div>

  <p class="legend muted">
    ⚑ exception au plan habituel ce jour-là ·
    Chaque section a sa couleur (modifiable dans <router-link to="/sections">Sections</router-link>) ·
    <AppIcon name="star" :size="12" /> vos sections ·
    <span class="chip chip-free">Libre</span> sur demande, validée par un référent
  </p>
</template>

<style scoped>
.toolbar { margin-bottom: 1rem }
.cap::first-letter { text-transform: uppercase }
.card-head p { display: flex; align-items: center; gap: .3rem }
.mine-card { margin-bottom: 1rem; border-color: #bfe3cc; background: linear-gradient(0deg, #fff, #f3fbf6) }
.mine-card h2 { display: flex; gap: .4rem; align-items: center; color: var(--ok) }
.access { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .5rem }
.access li { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center }
.when { font-weight: 600; min-width: 190px }
.occ.past { opacity: .6 }
.occ.cancelled { background: #fafafa; border-style: dashed }
.occ.cancelled h2 { text-decoration: line-through; color: var(--muted) }
.exc-line { margin: -.25rem 0 .75rem; display: flex; flex-wrap: wrap; gap: .3rem }
.exc-tag { font-size: .72rem; color: #7a5400; font-weight: 600 }
.pool + .pool { margin-top: .9rem }
.pool h3 { font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); margin-bottom: .4rem }
.lanes { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: .6rem }
.lane { border: 1px solid var(--line); border-radius: 9px; padding: .6rem .7rem; display: flex; flex-direction: column; gap: .35rem; background: #fff }
.lane.mine { border-color: #9fd5b3; background: #f3fbf6 }
.lane.taken { background: #f7f9fb }
.lane.free { border-style: dashed; border-color: #c3ccd5 }
.lane-top { display: flex; justify-content: space-between; align-items: center; gap: .4rem }
.res { display: block; font-size: .87rem; color: var(--text); text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap }
.res:hover { color: var(--primary) }
.dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: .35rem; vertical-align: 0 }
.book { align-self: flex-start; margin-top: .15rem }
.legend { margin-top: 1.25rem; font-size: .85rem; line-height: 2 }
</style>
