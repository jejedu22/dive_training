<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from './AppIcon.vue'
import { addDays, mondayOf, time, today, ymd } from '../format'
import { eventStyle } from '../colors'
import type { CalendarData } from '../types'

/**
 * Planning du mois : une case par jour avec les créneaux du club et les séances
 * (couleur de la section ; rayée = en attente ; ⚠ = hors créneau). Clic sur un jour : vue du jour.
 */
const props = defineProps<{ data: CalendarData; month: string }>()
const emit = defineEmits<{ pick: [day: string]; open: [sessionId: number] }>()
const MAX = 4
const ms = (iso: string) => new Date(iso).getTime()

/** Séances regroupées (une séance réservant plusieurs lignes n'apparaît qu'une fois). */
const sessions = computed(() => {
  const m = new Map<number, { sessionId: number; title: string; section: string; color: string; status: string; start: string; end: string; lanes: number; exceptional: boolean }>()
  for (const e of props.data.events) {
    const inSlot = (props.data.slots ?? []).some((o) => o.laneIds.includes(Number(e.resourceId)) && ms(o.start) <= ms(e.start) && ms(e.end) <= ms(o.end))
    const cur = m.get(e.sessionId)
    if (cur) {
      cur.lanes++
      if (e.status === 'PENDING') cur.status = 'PENDING'
      if (!inSlot) cur.exceptional = true
    } else {
      m.set(e.sessionId, {
        sessionId: e.sessionId, title: e.title.replace(` — ${e.section}`, ''), section: e.section, color: e.color,
        status: e.status, start: e.start, end: e.end, lanes: 1, exceptional: !inSlot,
      })
    }
  }
  return [...m.values()].sort((a, b) => ms(a.start) - ms(b.start))
})

const weeks = computed(() => {
  const first = `${props.month}-01`
  const last = addDays(`${nextMonth(props.month)}-01`, -1)
  const out: string[][] = []
  // du lundi de la 1re semaine au dimanche de la dernière
  for (let d = mondayOf(first); d <= last || out.at(-1)!.length !== 7; d = addDays(d, 1)) {
    if (!out.length || out.at(-1)!.length === 7) out.push([])
    out.at(-1)!.push(d)
  }
  return out
})
function nextMonth(m: string) {
  const [y, mo] = m.split('-').map(Number)
  return mo === 12 ? `${y + 1}-01` : `${y}-${String(mo + 1).padStart(2, '0')}`
}
const inMonth = (d: string) => d.startsWith(props.month)
const slotsOf = (d: string) => (props.data.slots ?? []).filter((o) => ymd(new Date(o.start)) === d)
const sessionsOf = (d: string) => sessions.value.filter((s) => ymd(new Date(s.start)) === d)
const slotTimes = (d: string) => [...new Set(slotsOf(d).map((o) => `${time(o.start)}–${time(o.end)}`))].join(' · ')
const DAYS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim']
const todayYmd = today()
</script>

<template>
  <div class="month card">
    <div class="head"><span v-for="d in DAYS" :key="d">{{ d }}</span></div>
    <div v-for="(w, i) in weeks" :key="i" class="week">
      <div v-for="d in w" :key="d" class="day"
        :class="{ out: !inMonth(d), slot: inMonth(d) && slotsOf(d).length, today: d === todayYmd, busy: inMonth(d) && sessionsOf(d).length }"
        role="button" tabindex="0" :title="inMonth(d) ? 'Voir le planning du jour' : ''"
        @click="inMonth(d) && emit('pick', d)" @keydown.enter="inMonth(d) && emit('pick', d)">
        <template v-if="inMonth(d)">
          <div class="num">{{ Number(d.slice(8)) }}</div>
          <div v-if="slotsOf(d).length" class="slots"><AppIcon name="clock" :size="11" /> {{ slotTimes(d) }}</div>
          <div class="evs">
            <button v-for="s in sessionsOf(d).slice(0, MAX)" :key="s.sessionId" class="ev" :style="eventStyle(s.color, s.status)"
              :title="`${time(s.start)}–${time(s.end)} · ${s.title} · ${s.section}${s.lanes > 1 ? ` · ${s.lanes} lignes` : ''}${s.status === 'PENDING' ? ' · en attente' : ''}${s.exceptional ? ' · hors créneau' : ''}`"
              @click.stop="emit('open', s.sessionId)">
              <span class="t">{{ s.exceptional ? '⚠ ' : '' }}{{ time(s.start) }} {{ s.title }}</span>
            </button>
            <span v-if="sessionsOf(d).length > MAX" class="more">+{{ sessionsOf(d).length - MAX }}</span>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.month { padding: 0; overflow: hidden }
.head, .week { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)) }
.head span { padding: .45rem .5rem; font-size: .75rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .03em; border-bottom: 1px solid var(--line) }
.day { min-height: 108px; border-right: 1px solid var(--line); border-bottom: 1px solid var(--line); padding: .3rem .35rem; display: flex; flex-direction: column; gap: .2rem; cursor: pointer; min-width: 0; background: #fbfcfd }
.day:nth-child(7) { border-right: 0 }
.week:last-child .day { border-bottom: 0 }
.day.out { background: #f4f6f8; cursor: default }
.day.slot { background: #fff }
.day:not(.out):hover { background: var(--primary-50) }
.num { font-size: .82rem; font-weight: 600; color: var(--muted); width: 1.6rem; height: 1.6rem; display: grid; place-items: center; border-radius: 50% }
.day.slot .num { color: var(--text) }
.day.today .num { background: var(--primary); color: #fff }
.slots { font-size: .68rem; color: var(--primary-600); display: flex; align-items: center; gap: .2rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis }
.evs { display: flex; flex-direction: column; gap: 2px; min-width: 0 }
.ev { border: 0; border-radius: 4px; padding: 1px .3rem; font: inherit; font-size: .7rem; font-weight: 600; text-align: left; cursor: pointer; min-width: 0 }
.ev .t { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis }
.more { font-size: .7rem; color: var(--muted); font-weight: 600; padding-left: .2rem }
/* Mobile : les séances deviennent des pastilles de couleur */
@media (max-width: 760px) {
  .day { min-height: 68px; padding: .2rem }
  .slots { display: none }
  .day.slot::after { content: ''; display: block; height: 3px; border-radius: 2px; background: var(--primary-100); margin-top: auto }
  .evs { flex-direction: row; flex-wrap: wrap; gap: 3px }
  .ev { width: 12px; height: 12px; padding: 0; border-radius: 50% }
  .ev .t { display: none }
  .num { font-size: .78rem; width: 1.4rem; height: 1.4rem }
}
</style>
