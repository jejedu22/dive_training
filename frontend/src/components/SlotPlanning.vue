<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from './AppIcon.vue'
import { time } from '../format'
import type { CalendarData, SlotOccurrence } from '../types'

/**
 * Planning d'une journée, limité aux créneaux du club : une frise par créneau, avec seulement
 * ses lignes ouvertes. Les séances qui ne tiennent dans aucun créneau sont listées à part.
 */
const props = defineProps<{ data: CalendarData }>()
const emit = defineEmits<{ open: [sessionId: number] }>()
type Ev = CalendarData['events'][number]

const ms = (iso: string) => new Date(iso).getTime()
const occurrences = computed(() => [...(props.data.slots ?? [])].sort((a, b) => ms(a.start) - ms(b.start)))

/** Une séance est « dans un créneau » si sa ligne y est ouverte et qu'elle tient dans ses horaires. */
const inSlot = (e: Ev) => occurrences.value.some((o) => o.laneIds.includes(Number(e.resourceId)) && ms(o.start) <= ms(e.start) && ms(e.end) <= ms(o.end))
const exceptional = computed(() => props.data.events.filter((e) => !inSlot(e)).sort((a, b) => ms(a.start) - ms(b.start)))
const laneLabel = (id: string) => {
  const r = props.data.resources.find((x) => x.id === id)
  return r ? `${r.pool} · ${r.title}` : `Ligne ${id}`
}

function pools(o: SlotOccurrence) {
  const groups = new Map<string, CalendarData['resources']>()
  for (const r of props.data.resources) {
    if (!o.laneIds.includes(Number(r.id))) continue
    if (!groups.has(r.pool)) groups.set(r.pool, [])
    groups.get(r.pool)!.push(r)
  }
  return [...groups.entries()].map(([pool, lanes]) => ({ pool, lanes }))
}
const allocation = (o: SlotOccurrence, laneId: string) => o.allocations.find((a) => a.laneId === Number(laneId))?.section ?? null
const eventsIn = (o: SlotOccurrence, laneId: string) => props.data.events.filter((e) => e.resourceId === laneId && ms(e.start) < ms(o.end) && ms(e.end) > ms(o.start))

/** Graduations toutes les 30 minutes. */
function ticks(o: SlotOccurrence) {
  const out: string[] = []
  for (let t = ms(o.start); t < ms(o.end); t += 30 * 60_000) out.push(time(new Date(t).toISOString()))
  return out
}
function style(o: SlotOccurrence, e: { start: string; end: string }) {
  const lo = ms(o.start), span = ms(o.end) - lo
  const s = Math.max(ms(e.start), lo), t = Math.min(ms(e.end), lo + span)
  return { left: `${((s - lo) / span) * 100}%`, width: `${Math.max(((t - s) / span) * 100, 2)}%` }
}
const nowLeft = (o: SlotOccurrence) => {
  const now = Date.now()
  return now >= ms(o.start) && now <= ms(o.end) ? `${((now - ms(o.start)) / (ms(o.end) - ms(o.start))) * 100}%` : null
}
const statusText = (s: string) => (s === 'CONFIRMED' ? 'confirmée' : s === 'PENDING' ? 'en attente' : s)
</script>

<template>
  <div class="stack">
    <div v-if="!occurrences.length && !exceptional.length" class="card empty">
      <strong>Pas de créneau du club ce jour-là</strong>Utilisez les flèches pour aller au créneau précédent ou suivant.
    </div>

    <section v-for="o in occurrences" :key="o.start + o.slotId" class="card occ">
      <div class="card-head">
        <h2><AppIcon name="clock" :size="17" /> {{ time(o.start) }}–{{ time(o.end) }} <span class="muted label">{{ o.label }}</span></h2>
        <span class="chip">{{ o.laneIds.length }} ligne{{ o.laneIds.length > 1 ? 's' : '' }} ouverte{{ o.laneIds.length > 1 ? 's' : '' }}</span>
      </div>
      <div class="scroll">
        <div class="tl">
          <div class="row-tl head"><div class="lab" /><div class="cells"><span v-for="t in ticks(o)" :key="t">{{ t }}</span></div></div>
          <template v-for="g in pools(o)" :key="g.pool">
            <div class="pool"><span>{{ g.pool }}</span></div>
            <div v-for="r in g.lanes" :key="r.id" class="row-tl lane" :class="{ allocated: allocation(o, r.id) }">
              <div class="lab">
                <span>{{ r.title }}</span>
                <span v-if="allocation(o, r.id)" class="chip chip-ok sec">{{ allocation(o, r.id) }}</span>
                <span v-else class="chip chip-primary sec">libre</span>
              </div>
              <div class="cells" :style="{ backgroundSize: `${100 / ticks(o).length}% 100%` }">
                <div v-if="nowLeft(o)" class="now" :style="{ left: nowLeft(o)! }" />
                <button v-for="e in eventsIn(o, r.id)" :key="e.id" class="ev" :class="e.status" :style="style(o, e)" :title="`${e.title} (${statusText(e.status)})`" @click="emit('open', e.sessionId)">
                  {{ e.title }}
                </button>
              </div>
            </div>
          </template>
        </div>
      </div>
    </section>

    <section v-if="exceptional.length" class="card exc">
      <div class="card-head"><h2><AppIcon name="alert" :size="17" /> Séances exceptionnelles hors créneau</h2></div>
      <button v-for="e in exceptional" :key="e.id" class="exc-row" @click="emit('open', e.sessionId)">
        <strong>{{ time(e.start) }}–{{ time(e.end) }}</strong>
        <span>{{ e.title }}</span>
        <span class="muted">{{ laneLabel(e.resourceId) }}</span>
        <span class="chip" :class="e.status">{{ statusText(e.status) }}</span>
      </button>
    </section>
  </div>
</template>

<style scoped>
.card-head h2 { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap }
.label { font-weight: 500; font-size: .95rem }
.scroll { overflow-x: auto; margin: 0 -.25rem }
.tl { min-width: 560px; border: 1px solid var(--line); border-radius: 8px; overflow: hidden }
.row-tl { display: flex }
.lab { width: 170px; flex: none; padding: .4rem .6rem; font-size: .88rem; border-right: 1px solid var(--line); background: #fff;
  display: flex; align-items: center; justify-content: space-between; gap: .35rem; position: sticky; left: 0; z-index: 2 }
.sec { font-size: .7rem; padding: 0 .45rem; max-width: 95px; overflow: hidden; text-overflow: ellipsis }
.cells { position: relative; flex: 1; display: flex; min-height: 42px; background-image: linear-gradient(to right, var(--line) 1px, transparent 1px) }
.head .cells span { flex: 1; font-size: .72rem; padding: .3rem .3rem; color: var(--muted) }
.head .lab { background: #fafbfc }
.pool { background: var(--primary-50); color: var(--primary-600); padding: .28rem .6rem; font-weight: 600; font-size: .82rem; border-top: 1px solid var(--line) }
.pool span { position: sticky; left: .6rem }
.lane { border-top: 1px solid var(--line) }
.lane.allocated .cells { background-color: rgba(29,143,78,.05) }
.now { position: absolute; top: 0; bottom: 0; width: 2px; background: var(--ko); opacity: .6; z-index: 1 }
.ev { position: absolute; top: 5px; bottom: 5px; z-index: 1; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; font: inherit; font-size: .78rem; font-weight: 600;
  padding: 0 .45rem; border-radius: 6px; border: 0; color: #fff; cursor: pointer; text-align: left }
.ev.PENDING { background: #d38a00 } .ev.CONFIRMED { background: var(--ok) }
.exc { border-left: 4px solid var(--warn) }
.exc h2 { color: #7a4e00 }
.exc-row { display: flex; flex-wrap: wrap; align-items: center; gap: .3rem .8rem; width: 100%; padding: .55rem .25rem; border: 0; border-bottom: 1px solid var(--line);
  background: none; font: inherit; text-align: left; cursor: pointer; color: var(--text) }
.exc-row:last-child { border-bottom: 0 }
.exc-row:hover { background: var(--bg) }
@media (max-width: 760px) {
  .tl { min-width: 0 }
  .lab { width: 92px; flex-direction: column; align-items: flex-start; justify-content: center; gap: .1rem; padding: .3rem .45rem }
  .sec { max-width: 82px; font-size: .65rem }
  .head .cells span { font-size: .65rem; padding: .3rem .15rem }
  .ev { font-size: .7rem; padding: 0 .3rem }
}
</style>
