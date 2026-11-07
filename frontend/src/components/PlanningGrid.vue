<script setup lang="ts">
import { computed } from 'vue'
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
// Créneaux du club applicables à une ligne : tous bassins, ou le bassin de la ligne
const slotsOf = (poolId: number) => (props.data.slots ?? []).filter((s) => s.poolId === null || s.poolId === poolId)
const pools = computed(() => [...new Set(props.data.resources.map((r) => r.pool))])
</script>

<template>
  <div class="grid">
    <div class="head"><div class="label" /><div class="cells"><span v-for="h in hours" :key="h">{{ h }}h</span></div></div>
    <template v-for="pool in pools" :key="pool">
      <div class="pool">{{ pool }}</div>
      <div v-for="r in data.resources.filter((x) => x.pool === pool)" :key="r.id" class="lane">
        <div class="label">{{ r.title }}</div>
        <div class="cells" :style="{ backgroundSize: `${100 / hours.length}% 100%` }">
          <div v-for="(s, i) in slotsOf(r.poolId)" :key="`s${i}`" class="slot" :style="style(s)" :title="`${s.label} (créneau club)`" />
          <button
            v-for="e in eventsOf(r.id)" :key="e.id" class="ev" :class="e.status" :style="style(e)"
            :title="e.title" @click="emit('open', e.sessionId)"
          >{{ e.title }}</button>
        </div>
      </div>
    </template>
    <p v-if="!data.resources.length">Aucune ligne d'eau : demandez à un référent de créer un bassin.</p>
  </div>
</template>

<style scoped>
.grid { background:#fff; border:1px solid var(--line); border-radius:6px; overflow:hidden }
.head, .lane { display:flex }
.label { width:110px; flex:none; padding:.4rem; font-size:.85rem; border-right:1px solid var(--line) }
.cells { position:relative; flex:1; display:flex; min-height:34px;
  background-image:linear-gradient(to right, var(--line) 1px, transparent 1px) }
.head .cells span { flex:1; font-size:.75rem; padding:.2rem; color:#667 }
.pool { background:#e8f1f8; padding:.25rem .5rem; font-weight:600; border-top:1px solid var(--line) }
.lane { border-top:1px solid var(--line) }
.slot { position:absolute; top:0; bottom:0; background:rgba(11,106,168,.12); border-left:2px solid rgba(11,106,168,.5); border-right:2px solid rgba(11,106,168,.5); pointer-events:none }
.ev { position:absolute; top:3px; bottom:3px; overflow:hidden; white-space:nowrap; text-overflow:ellipsis;
  font-size:.75rem; padding:0 .3rem; border-radius:3px }
.ev.PENDING { background:var(--warn) } .ev.CONFIRMED { background:var(--ok) }
</style>
