<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import { get } from '../api'
import { useAuth } from '../stores/auth'
import { useToast } from '../stores/toast'
import { STATUS_LABELS, STATUS_SHORT, useMft } from '../composables/mft'
import { dateFr, today } from '../format'
import type { Board, Section, SkillStatus } from '../types'

/** Tableau de section : stagiaires en cours × compétences (ou modules), pour repérer qui est prêt et ce qui reste à travailler. */
const auth = useAuth(), toast = useToast(), router = useRouter()
const { mft, load: loadMft } = useMft()
const sections = ref<Section[]>([])
const stored = (() => { try { return localStorage.getItem('board_curriculum') } catch { return null } })()
const curriculum = ref(stored ?? 'N1')
const sectionId = ref<number | ''>('')
const board = ref<Board | null>(null)
const filter = ref<'all' | 'ready' | 'issuable' | 'late'>('all')

onMounted(async () => {
  try {
    await loadMft()
    sections.value = await get<Section[]>('/sections')
    const mine = sections.value.find((s) => s.instructors.some((i) => i.id === auth.user?.id))
    sectionId.value = mine?.id ?? ''
  } catch (e) {
    toast.error(e)
  }
})
async function load() {
  try {
    board.value = await get<Board>(`/trainees/board?curriculum=${curriculum.value}${sectionId.value ? `&section=${sectionId.value}` : ''}`)
  } catch (e) {
    toast.error(e)
  }
}
watch([curriculum, sectionId], () => { try { localStorage.setItem('board_curriculum', curriculum.value) } catch { /* */ } load() }, { immediate: true })

const soon = (() => { const d = new Date(); d.setMonth(d.getMonth() + 3); return d.toISOString().slice(0, 10) })()
const rows = computed(() => (board.value?.rows ?? []).filter((r) =>
  filter.value === 'all' ? true
  : filter.value === 'ready' ? r.cells.some((c) => c.status === 'A')
  : filter.value === 'issuable' ? r.issuable.length > 0
  : !!r.deadline && r.deadline < soon))
const counts = computed(() => {
  const r = board.value?.rows ?? []
  return { all: r.length, ready: r.filter((x) => x.cells.some((c) => c.status === 'A')).length, issuable: r.filter((x) => x.issuable.length).length, late: r.filter((x) => !!x.deadline && x.deadline < soon).length }
})
/** Par colonne : combien de stagiaires n'ont pas encore travaillé la compétence (pour préparer les séances). */
const untouched = (i: number) => (board.value?.rows ?? []).filter((r) => r.cells[i].status === 'NT').length
const isTeaching = computed(() => curriculum.value === 'INIT')
</script>

<template>
  <router-link to="/stagiaires" class="back"><AppIcon name="left" :size="16" /> Stagiaires</router-link>
  <div class="page-head">
    <div><h1>Tableau de section</h1><p>Stagiaires en cours × {{ isTeaching ? 'modules' : 'compétences' }}. Cliquez sur une ligne pour ouvrir la fiche.</p></div>
  </div>

  <div class="card filters">
    <select v-model="curriculum" aria-label="Cursus"><option v-for="c in mft?.curricula ?? []" :key="c.code" :value="c.code">{{ c.name }}</option></select>
    <select v-model="sectionId" aria-label="Section"><option value="">Toutes les sections</option><option v-for="s in sections" :key="s.id" :value="s.id">{{ s.name }}</option></select>
    <div class="seg" role="tablist">
      <button :class="{ on: filter === 'all' }" @click="filter = 'all'">Tous ({{ counts.all }})</button>
      <button :class="{ on: filter === 'ready' }" :title="isTeaching ? 'Modules dont les séances suffisent : validation du tuteur attendue' : 'Compétences acquises, en attente de validation'" @click="filter = 'ready'">À valider ({{ counts.ready }})</button>
      <button :class="{ on: filter === 'issuable' }" @click="filter = 'issuable'">{{ isTeaching ? 'Prêts pour l\'examen' : 'Délivrables' }} ({{ counts.issuable }})</button>
      <button :class="{ on: filter === 'late' }" @click="filter = 'late'">Échéance &lt; 3 mois ({{ counts.late }})</button>
    </div>
  </div>

  <div v-if="board" class="card scroll">
    <table class="board">
      <thead>
        <tr>
          <th class="name">Stagiaire</th>
          <th v-for="(c, i) in board.columns" :key="c.key" class="col" :title="c.title"><span>{{ isTeaching ? `M${i + 1}` : `C${i + 1}` }}</span></th>
          <th class="num">Validé</th>
          <th class="num">Échéance</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="r in rows" :key="r.diverId" @click="router.push(`/stagiaires/${r.diverId}`)">
          <th class="name">{{ r.lastName }} {{ r.firstName }}<span v-if="r.issuable.length" class="chip chip-ok mini">{{ isTeaching ? 'examen' : r.issuable.join(', ') }}</span></th>
          <td v-for="(c, i) in r.cells" :key="c.key" :title="`${board.columns[i].title} : ${STATUS_LABELS[c.status]}${c.detail ? ` (${c.detail})` : ''}`"><span class="st" :class="c.status">{{ STATUS_SHORT[c.status] === 'Validé' ? 'V' : STATUS_SHORT[c.status] }}</span></td>
          <td class="num">{{ r.progress.validated }}/{{ r.progress.total }}</td>
          <td class="num" :class="{ warn: r.deadline && r.deadline < soon }">{{ r.deadline ? dateFr(r.deadline) : '—' }}</td>
        </tr>
        <tr v-if="!rows.length"><td :colspan="board.columns.length + 3" class="empty-row">Aucun stagiaire {{ filter === 'all' ? 'en cours dans ce cursus' : 'pour ce filtre' }}.</td></tr>
      </tbody>
      <tfoot v-if="board.rows.length && !isTeaching">
        <tr><th class="name small muted">Pas encore travaillé</th><td v-for="(c, i) in board.columns" :key="c.key" class="small muted">{{ untouched(i) || '' }}</td><td colspan="2" /></tr>
      </tfoot>
    </table>
  </div>

  <div v-if="board" class="card legend">
    <p class="small"><span v-for="s in (['NT', 'ECA', 'A', 'V'] as SkillStatus[])" :key="s" class="lg"><span class="st" :class="s">{{ s }}</span> {{ STATUS_LABELS[s] }}</span></p>
    <ol class="cols small">
      <li v-for="(c, i) in board.columns" :key="c.key"><strong class="code">{{ isTeaching ? 'M' : 'C' }}{{ i + 1 }}</strong> {{ c.title }}<span v-if="c.group" class="muted"> — {{ c.group }}</span></li>
    </ol>
    <p v-if="isTeaching" class="small muted">Module « Acquis » : séances suffisantes, validation du tuteur attendue. Aujourd'hui : {{ dateFr(today()) }}.</p>
  </div>
</template>

<style scoped>
.back { display: inline-flex; align-items: center; gap: .25rem; text-decoration: none; font-weight: 600; margin-bottom: .5rem }
.filters { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: .75rem; padding: .75rem; align-items: center }
.seg { display: inline-flex; flex-wrap: wrap; border: 1px solid var(--primary-100); border-radius: 8px; overflow: hidden }
.seg button { border: 0; background: var(--surface); font: inherit; font-size: .85rem; font-weight: 600; padding: .45rem .7rem; color: var(--primary); cursor: pointer; min-height: 38px }
.seg button.on { background: var(--primary); color: #fff }
.scroll { overflow-x: auto; padding: 0 }
.board { border-collapse: collapse; width: 100%; font-size: .88rem }
.board th, .board td { padding: .35rem .3rem; border-bottom: 1px solid var(--line); text-align: center; white-space: nowrap }
.board thead th { background: var(--bg); font-size: .78rem; color: var(--muted); position: sticky; top: 0 }
.board .name { text-align: left; position: sticky; left: 0; background: var(--surface); padding-left: .75rem; font-weight: 600; z-index: 1 }
.board thead .name { background: var(--bg); z-index: 2 }
.board tbody tr { cursor: pointer }
.board tbody tr:hover td, .board tbody tr:hover th { background: var(--primary-50) }
.col span { cursor: help }
.num { font-variant-numeric: tabular-nums; font-size: .82rem }
.warn { color: var(--warn); font-weight: 700 }
.mini { font-size: .68rem; margin-left: .4rem; padding: .05rem .4rem }
.st { display: inline-flex; justify-content: center; min-width: 2.3em; padding: .12rem .3rem; border-radius: 5px; font-size: .7rem; font-weight: 700; background: #eef2f6; color: #8494a3 }
.st.ECA { background: var(--warn-50); color: var(--warn) } .st.A { background: var(--primary-50); color: var(--primary); box-shadow: inset 0 0 0 1px var(--primary-100) } .st.V { background: var(--ok); color: #fff }
.empty-row { padding: 1.5rem; color: var(--muted) }
.legend { margin-top: .75rem }
.lg { display: inline-flex; align-items: center; gap: .3rem; margin-right: 1rem }
.cols { columns: 2 280px; margin: .5rem 0 0; padding: 0; list-style: none }
.cols li { padding: .1rem 0; break-inside: avoid }
.code { color: var(--primary); display: inline-block; min-width: 2.2em }
.small { font-size: .82rem }
</style>
