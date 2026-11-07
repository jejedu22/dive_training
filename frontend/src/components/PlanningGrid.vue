<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import type { CalendarData } from '../types'

const props = defineProps<{ data: CalendarData; day: string; startHour?: number; endHour?: number }>()
const emit = defineEmits<{ open: [sessionId: number] }>()

const from = computed(() => props.startHour ?? 7)
const to = computed(() => props.endHour ?? 23)
const hours = computed(() => Array.from({ length: to.value - from.value }, (_, i) => from.value + i))
const dayStart = computed(() => new Date(`${props.day}T00:00:00`).getTime())

function style(e: { start: string; end: string }) {
  const lo = dayStart.value + from.value * 3600_000
  const span = (to.value - from.value) * 3600_000
  const s = Math.max(new Date(e.start).getTime(), lo), t = Math.min(new Date(e.end).getTime(), lo + span)
  return { left: `${((s - lo) / span) * 100}%`, width: `${Math.max(((t - s) / span) * 100, 1)}%` }
}
const eventsOf = (id: string) => props.data.events.filter((e) => e.resourceId === id)
/** Créneaux où cette ligne est ouverte au club, avec la section éventuellement attribuée. */
const slotsOf = (laneId: number) =>
  (props.data.slots ?? [])
    .filter((s) => s.laneIds.includes(laneId))
    .map((s) => ({ ...s, section: s.allocations.find((a) => a.laneId === laneId)?.section ?? null }))
const pools = computed(() => [...new Set(props.data.resources.map((r) => r.pool))])
/** Sur petit écran, la grille défile horizontalement : on l'amène sur le premier créneau / la première séance du jour. */
const scroller = ref<HTMLElement | null>(null)
watch(() => [props.data, props.day], async () => {
  await nextTick()
  const el = scroller.value
  if (!el || el.scrollWidth <= el.clientWidth) return
  const times = [...(props.data.slots ?? []), ...props.data.events].map((x) => new Date(x.start).getTime())
  const lo = dayStart.value + from.value * 3600_000, span = (to.value - from.value) * 3600_000
  const first = times.length ? Math.min(...times) : Date.now()
  const ratio = Math.min(Math.max((first - lo) / span, 0), 1)
  const grid = el.firstElementChild as HTMLElement
  el.scrollLeft = Math.max(ratio * (grid.scrollWidth - 96) - 40, 0)
}, { immediate: true })

const nowLeft = computed(() => {
  const now = Date.now(), lo = dayStart.value + from.value * 3600_000, span = (to.value - from.value) * 3600_000
  return now >= lo && now <= lo + span ? `${((now - lo) / span) * 100}%` : null
})
</script>

<template>
  <div ref="scroller" class="grid-scroll">
    <div class="grid">
      <div class="head"><div class="label" /><div class="cells"><span v-for="h in hours" :key="h">{{ h }}h</span></div></div>
      <template v-for="pool in pools" :key="pool">
        <div class="pool"><span>{{ pool }}</span></div>
        <div v-for="r in data.resources.filter((x) => x.pool === pool)" :key="r.id" class="lane">
          <div class="label">{{ r.title }}</div>
          <div class="cells" :style="{ backgroundSize: `${100 / hours.length}% 100%` }">
            <div v-for="(s, i) in slotsOf(Number(r.id))" :key="`s${i}`" class="slot" :class="{ allocated: s.section }" :style="style(s)"
              :title="`${s.label}${s.section ? ' — attribuée à ' + s.section : ' — libre'}`">
              <span v-if="s.section">{{ s.section }}</span>
            </div>
            <div v-if="nowLeft" class="now" :style="{ left: nowLeft }" />
            <button v-for="e in eventsOf(r.id)" :key="e.id" class="ev" :class="e.status" :style="style(e)" :title="e.title" @click="emit('open', e.sessionId)">{{ e.title }}</button>
          </div>
        </div>
      </template>
      <p v-if="!data.resources.length" class="empty">Aucune ligne d'eau : un référent doit d'abord créer les bassins.</p>
    </div>
  </div>
</template>

<style scoped>
.grid-scroll { overflow-x: auto; border-radius: var(--radius); border: 1px solid var(--line); background: #fff; box-shadow: var(--shadow) }
.grid { min-width: 760px }
.head, .lane { display: flex }
.label { width: 96px; flex: none; padding: .45rem .6rem; font-size: .85rem; border-right: 1px solid var(--line); background: #fff; position: sticky; left: 0; z-index: 2 }
.cells { position: relative; flex: 1; display: flex; min-height: 40px; background-image: linear-gradient(to right, var(--line) 1px, transparent 1px) }
.head .cells span { flex: 1; font-size: .72rem; padding: .3rem .25rem; color: var(--muted) }
.pool { background: var(--primary-50); color: var(--primary-600); padding: .3rem .6rem; font-weight: 600; font-size: .85rem; border-top: 1px solid var(--line) }
.pool span { position: sticky; left: .6rem }
.lane { border-top: 1px solid var(--line) }
.slot { position: absolute; top: 0; bottom: 0; background: rgba(11,106,168,.07); border-left: 2px solid rgba(11,106,168,.35); border-right: 2px solid rgba(11,106,168,.35); pointer-events: none }
.slot.allocated { background: repeating-linear-gradient(135deg, rgba(29,143,78,.08) 0 8px, rgba(29,143,78,.13) 8px 16px); border-color: rgba(29,143,78,.45) }
.slot span { position: absolute; bottom: 1px; right: 4px; font-size: .65rem; color: var(--ok); font-weight: 600 }
.now { position: absolute; top: 0; bottom: 0; width: 2px; background: var(--ko); opacity: .6; z-index: 1 }
.ev { position: absolute; top: 5px; bottom: 5px; z-index: 1; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; font: inherit; font-size: .75rem; font-weight: 600;
  padding: 0 .4rem; border-radius: 5px; border: 0; color: #fff; cursor: pointer; text-align: left }
.ev.PENDING { background: #d38a00 } .ev.CONFIRMED { background: var(--ok) }
</style>
