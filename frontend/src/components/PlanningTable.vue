<script setup lang="ts">
import { computed } from 'vue'
import AppIcon from './AppIcon.vue'
import { dayShort, time } from '../format'
import { chipStyle, textOn } from '../colors'
import type { PlanningLane, PlanningOccurrence } from '../types'

/**
 * Tableau du planning : une ligne par date de créneau, une colonne par ligne d'eau (par bassin),
 * chaque case à la couleur de la (ou des) section(s). Utilisé par l'écran Planning et la page de partage.
 */
const props = withDefaults(defineProps<{
  occurrences: PlanningOccurrence[]      // lignes affichées
  columnsFrom?: PlanningOccurrence[]     // pour les colonnes et la légende (par défaut : occurrences)
  editable?: boolean                     // référent : cases et dates cliquables pour modifier
  showTanks?: boolean                    // colonne Bouteilles
}>(), { editable: false, showTanks: false })
const emit = defineEmits<{ cell: [o: PlanningOccurrence, lane: PlanningLane | null]; date: [o: PlanningOccurrence]; tanks: [o: PlanningOccurrence, s: PlanningLane['sections'][number]] }>()

const source = computed(() => props.columnsFrom ?? props.occurrences)
const columns = computed(() => {
  const pools: { poolId: number; pool: string; lanes: { laneId: number; lane: string }[] }[] = []
  for (const o of source.value) {
    for (const l of o.lanes) {
      let p = pools.find((x) => x.poolId === l.poolId)
      if (!p) pools.push((p = { poolId: l.poolId, pool: l.pool, lanes: [] }))
      if (!p.lanes.some((x) => x.laneId === l.laneId)) p.lanes.push({ laneId: l.laneId, lane: l.lane })
    }
  }
  return pools
})
const laneCount = computed(() => columns.value.reduce((n, p) => n + p.lanes.length, 0))
const cell = (o: PlanningOccurrence, laneId: number) => o.lanes.find((l) => l.laneId === laneId) ?? null
const isPast = (o: PlanningOccurrence) => new Date(o.end) < new Date()
const sectionsOf = (o: PlanningOccurrence) => {
  const m = new Map<number, PlanningLane['sections'][number]>()
  for (const l of o.lanes) for (const x of l.sections) m.set(x.id, x)
  return [...m.values()]
}
const tankSectionsOf = (o: PlanningOccurrence) => sectionsOf(o).filter((x) => x.usesTanks)
const legend = computed(() => {
  const m = new Map<number, { id: number; name: string; color: string }>()
  for (const o of source.value) for (const s of sectionsOf(o)) m.set(s.id, s)
  return [...m.values()].sort((a, b) => a.name.localeCompare(b.name))
})
const clickable = (l: PlanningLane | null) => !!l && (props.editable || (props.showTanks && l.sections.some((x) => x.usesTanks)))
const title = (c: { lane: string }, l: PlanningLane | null) =>
  l ? `${c.lane} : ${l.sections.map((x) => x.name).join(' + ') || 'libre'}${l.exception ? ' (modifié ce jour-là)' : ''}` : `${c.lane} : fermée sur ce créneau`
</script>

<template>
  <div class="grid-wrap">
    <table class="grid">
      <thead>
        <tr>
          <th class="sticky" rowspan="2">Créneau</th>
          <th v-for="p in columns" :key="p.poolId" :colspan="p.lanes.length" class="pool">{{ p.pool }}</th>
          <th v-if="showTanks" rowspan="2" class="tanks-h"><AppIcon name="tank" :size="15" /> Bouteilles</th>
        </tr>
        <tr>
          <template v-for="p in columns" :key="'l' + p.poolId"><th v-for="l in p.lanes" :key="l.laneId">{{ l.lane }}</th></template>
        </tr>
      </thead>
      <tbody>
        <tr v-for="o in occurrences" :key="o.date + o.slotId" :class="{ past: isPast(o), cancelled: o.cancelled }">
          <th class="sticky when" :class="{ clickable: editable }" @click="editable && emit('date', o)">
            <span class="d">{{ dayShort(o.start) }}</span>
            <span class="t">{{ time(o.start) }}–{{ time(o.end) }}</span>
            <span v-for="x in o.exceptions" :key="x.id" class="flag" :title="`${x.label ?? 'Exception'} : ${x.rule}`">⚑ {{ x.label ?? 'modifié' }}</span>
          </th>
          <td v-if="o.cancelled" :colspan="laneCount + (showTanks ? 1 : 0)" class="cancel-cell">Créneau annulé</td>
          <template v-else>
            <template v-for="p in columns" :key="'c' + p.poolId">
              <td v-for="c in p.lanes" :key="c.laneId" class="cell"
                :class="{ closed: !cell(o, c.laneId), free: cell(o, c.laneId) && !cell(o, c.laneId)!.sections.length, exc: cell(o, c.laneId)?.exception, clickable: clickable(cell(o, c.laneId)) }"
                :title="title(c, cell(o, c.laneId))" @click="clickable(cell(o, c.laneId)) && emit('cell', o, cell(o, c.laneId))">
                <template v-if="cell(o, c.laneId)">
                  <div v-if="cell(o, c.laneId)!.sections.length" class="parts">
                    <span v-for="x in cell(o, c.laneId)!.sections" :key="x.id" class="part" :class="{ own: x.mine }" :style="{ background: x.color, color: textOn(x.color) }"><span class="pn">{{ x.name }}</span></span>
                  </div>
                  <span v-else class="name">libre</span>
                  <span v-if="cell(o, c.laneId)!.exception" class="mark">⚑</span>
                </template>
              </td>
            </template>
            <td v-if="showTanks" class="tanks">
              <button v-for="s in tankSectionsOf(o)" :key="s.id" class="tank-chip" :style="chipStyle(s.color)" :title="`Bouteilles ${s.name}`" @click="emit('tanks', o, s)">
                {{ s.name }} <strong>{{ o.tanks?.[s.id] ?? 0 }}</strong>
              </button>
              <span v-if="!tankSectionsOf(o).length" class="muted">—</span>
            </td>
          </template>
        </tr>
      </tbody>
    </table>
  </div>
  <p v-if="legend.length" class="legend">
    <span v-for="s in legend" :key="s.id" class="chip" :style="chipStyle(s.color)">{{ s.name }}</span>
    <span class="muted">· <span class="sw free" /> libre · <span class="sw closed" /> fermée sur ce créneau · ⚑ modifié ce jour-là</span>
  </p>
</template>

<style scoped>
.grid-wrap { overflow-x: auto; border: 1px solid var(--line); border-radius: var(--radius); background: #fff; box-shadow: var(--shadow) }
.grid { border-collapse: separate; border-spacing: 0; width: 100%; font-size: .85rem }
.grid th, .grid td { border-bottom: 1px solid var(--line); border-right: 1px solid var(--line); padding: 0 }
.grid thead th { background: #f7f9fb; color: var(--muted); font-weight: 600; text-align: center; padding: .35rem .3rem; text-transform: none; letter-spacing: 0; font-size: .8rem }
.grid thead .pool { background: var(--primary-50); color: var(--primary-600) }
.sticky { position: sticky; left: 0; z-index: 2; background: #fff; text-align: left }
thead .sticky { z-index: 3; background: #f7f9fb }
.when { padding: .4rem .6rem !important; min-width: 120px; font-weight: 400; text-transform: none; letter-spacing: 0; font-size: .85rem }
.when .d { display: block; font-weight: 700; color: var(--text) }
.when .d::first-letter { text-transform: uppercase }
.when .t { display: block; color: var(--muted); font-size: .78rem }
.when .flag { display: block; color: #8a6100; font-size: .72rem; font-weight: 600 }
.when.clickable { cursor: pointer }
.when.clickable:hover { background: var(--primary-50) }
.cell { height: 46px; min-width: 76px; text-align: center; vertical-align: middle; position: relative; font-weight: 600 }
.cell .name { display: block; padding: 0 .3rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 110px; margin: 0 auto }
.parts { position: absolute; inset: 0; display: flex; flex-direction: column } /* ligne partagée : une section en haut, une en bas */
.part { flex: 1; display: flex; align-items: center; min-width: 0; min-height: 0; padding: 0 .25rem; font-size: .74rem; line-height: 1.1 }
.pn { flex: 1; min-width: 0; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; text-align: center }
.part + .part { border-top: 2px solid #fff }
.parts .part:only-child { font-size: .85rem }
.part.own { box-shadow: inset 0 0 0 3px rgba(255,255,255,.7) } /* mes sections */
.cell.free { color: #9aa6b2; font-weight: 400; background: #fff }
.cell.closed { background: repeating-linear-gradient(135deg, #f4f6f8 0 6px, #eceff3 6px 12px) }
.cell.exc { outline: 2px dashed #e0a21a; outline-offset: -3px }
.cell .mark { position: absolute; top: 1px; right: 3px; font-size: .7rem; z-index: 1 }
.cell.clickable { cursor: pointer }
.cell.clickable:hover { filter: brightness(.93) }
.tanks { padding: .3rem .4rem !important; min-width: 120px }
.tank-chip { border: 0; border-radius: 999px; padding: .15rem .5rem; font: inherit; font-size: .75rem; font-weight: 600; cursor: pointer; margin: 1px; white-space: nowrap }
.tanks-h { min-width: 120px }
tr.past td, tr.past th.when { opacity: .55 }
tr.cancelled .when .d { text-decoration: line-through }
.cancel-cell { text-align: center; color: var(--ko); font-weight: 600; background: repeating-linear-gradient(135deg, #fff 0 8px, #fdeeee 8px 16px) }
.legend { font-size: .82rem; margin: .6rem 0 0; display: flex; flex-wrap: wrap; align-items: center; gap: .3rem .5rem }
.sw { display: inline-block; width: 14px; height: 10px; border-radius: 3px; vertical-align: -1px; border: 1px solid var(--line) }
.sw.free { background: #fff }
.sw.closed { background: repeating-linear-gradient(135deg, #f4f6f8 0 3px, #dfe4ea 3px 6px) }
@media (max-width: 760px) {
  .when { min-width: 92px }
  .cell { min-width: 62px; height: 42px; font-size: .75rem }
  .parts .part:only-child { font-size: .72rem }
}
</style>
