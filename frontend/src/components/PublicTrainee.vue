<script setup lang="ts">
import AppIcon from './AppIcon.vue'
import { PLACE_LABELS, STATUS_LABELS, STATUS_SHORT } from '../composables/mft'
import { chipStyle } from '../colors'
import { dateFr } from '../format'
import type { MftCurriculum, SkillStatus, Trainee } from '../types'

/** Livret d'un stagiaire, consulté par lien (sans compte, lecture seule). */
const props = defineProps<{ fiche: Trainee & { curricula?: MftCurriculum[] }; downloading: boolean }>()
defineEmits<{ pdf: [enrolmentId?: number] }>()
// Intitulés des cursus, fournis avec le livret
const cur = (code: string): MftCurriculum | undefined => props.fiche.curricula?.find((c) => c.code === code)
const pct = (v: number, t: number) => Math.round((100 * v) / Math.max(1, t))
const statusOf = (e: Trainee['enrolments'][number], key: string): SkillStatus => e.competencies.find((c) => c.key === key)?.status ?? 'NT'
const comments = (e: Trainee['enrolments'][number]) => e.evaluations.filter((x) => x.comment).slice(0, 20)
const skillTitle = (c: MftCurriculum, key: string) => { for (const x of c.competencies) if (x.skills[key]) return x.skills[key]; return key }
</script>

<template>
  <div class="page-head">
    <div>
      <p class="brand"><img src="/favicon.png" alt="" width="20" height="20" /> Dive Training</p>
      <h1>Livret de {{ fiche.firstName }} {{ fiche.lastName }}</h1>
      <p class="chips"><span v-for="s in fiche.sections" :key="s.name" class="chip" :style="chipStyle(s.color)">{{ s.name }}</span>
        <span v-if="fiche.licence" class="muted">Licence {{ fiche.licence }}</span></p>
    </div>
    <button class="btn btn-secondary" :disabled="downloading" @click="$emit('pdf')"><AppIcon name="download" :size="16" /> PDF</button>
  </div>

  <p v-if="!fiche.enrolments.length" class="card empty">Aucun cursus en cours.</p>
  <section v-for="e in fiche.enrolments" :key="e.id" class="card enrolment">
    <h2>{{ e.name }} <small class="muted">{{ cur(e.curriculum)?.subtitle }}</small></h2>
    <p class="muted small">Début le {{ dateFr(e.startedAt) }}<template v-if="e.deadline && e.status === 'active'"> · {{ e.curriculum === 'INIT' ? 'livret valable jusqu\'au' : 'échéance' }} {{ dateFr(e.deadline) }}</template></p>
    <div class="progress"><span class="bar"><span :style="{ width: pct(e.progress.validated, e.progress.total) + '%' }" /></span>
      <strong>{{ e.progress.validated }}/{{ e.progress.total }}</strong> {{ e.curriculum === 'INIT' ? 'modules validés' : 'compétences validées' }}</div>
    <p v-for="q in e.qualifications.filter((x) => x.issued)" :key="q.code" class="ok"><AppIcon name="check" :size="15" /> {{ q.label }} — le {{ dateFr(q.issued!.date) }}</p>

    <template v-if="cur(e.curriculum)">
      <!-- Plongeur : compétences et éléments -->
      <div v-if="e.curriculum !== 'INIT'" class="comps">
        <div v-for="c in cur(e.curriculum)!.competencies" :key="c.key" class="comp">
          <div class="c-head"><span class="st" :class="statusOf(e, c.key)">{{ STATUS_SHORT[statusOf(e, c.key)] }}</span><strong>{{ c.title }}</strong></div>
          <ul>
            <li v-for="(label, k) in c.skills" :key="k"><span class="st" :class="e.skills[k]?.status ?? 'NT'">{{ STATUS_SHORT[e.skills[k]?.status ?? 'NT'] }}</span> {{ label }}
              <small v-if="e.skills[k]?.last" class="muted">{{ dateFr(e.skills[k].last!.date) }}, {{ PLACE_LABELS[e.skills[k].last!.place] }}</small></li>
          </ul>
        </div>
        <template v-if="comments(e).length">
          <h3>Observations des encadrants</h3>
          <p v-for="ev in comments(e)" :key="ev.id" class="small obs"><strong>{{ dateFr(ev.date) }}</strong> · {{ skillTitle(cur(e.curriculum)!, ev.skill) }} — « {{ ev.comment }} » <span class="muted">({{ ev.instructor }})</span></p>
        </template>
      </div>
      <!-- Initiateur : modules -->
      <div v-else class="comps">
        <div v-for="m in e.modules ?? []" :key="m.key" class="comp">
          <div class="c-head"><span class="st" :class="m.validated || m.exempted ? 'V' : m.counts.sessions ? 'ECA' : 'NT'">{{ m.validated ? 'Validé' : m.exempted ? 'Dispensé' : m.counts.sessions ? 'En cours' : 'NT' }}</span>
            <strong>{{ cur('INIT')!.modules!.find((x) => x.key === m.key)!.title }}</strong></div>
          <p class="small muted">{{ m.counts.complete }} séance(s) complète(s), {{ m.counts.validated }} validée(s)</p>
          <ul><li v-for="s in m.sessions" :key="s.id" class="small">{{ dateFr(s.date) }} · {{ s.level }} · {{ s.theme }} — {{ s.validated ? 'validée' : s.complete ? 'complète' : 'incomplète' }}</li></ul>
        </div>
      </div>
    </template>
    <p class="muted small legend">{{ Object.entries(STATUS_LABELS).map(([k, v]) => `${STATUS_SHORT[k as SkillStatus]} : ${v}`).join(' · ') }}</p>
  </section>
  <p class="muted small foot">Livret en lecture seule, transmis par le club.</p>
</template>

<style scoped>
.brand { display: flex; align-items: center; gap: .4rem; font-weight: 700; color: var(--primary); margin: 0 0 .3rem }
.chips { display: flex; flex-wrap: wrap; gap: .3rem; align-items: center }
.small { font-size: .85rem }
.ok { color: var(--ok); font-weight: 600; display: flex; align-items: center; gap: .3rem; margin: .3rem 0 }
.enrolment { margin-bottom: .75rem }
.enrolment h2 { margin: 0; font-size: 1.15rem } .enrolment h2 small { font-weight: 400; font-size: .85rem }
.progress { display: flex; align-items: center; gap: .5rem; margin: .4rem 0 }
.bar { width: 200px; max-width: 50%; height: 10px; background: #e5ebf0; border-radius: 99px; overflow: hidden }
.bar span { display: block; height: 100%; background: var(--ok) }
.comps { margin-top: .5rem }
.comp { border-top: 1px solid var(--line); padding: .5rem 0 }
.c-head { display: flex; gap: .5rem; align-items: center }
.comp ul { list-style: none; margin: .3rem 0 0; padding: 0 0 0 .3rem }
.comp li { display: flex; flex-wrap: wrap; gap: .1rem .4rem; align-items: center; padding: .15rem 0 }
.comp li small { flex-basis: 100%; padding-left: 3.4em }
.st { display: inline-flex; justify-content: center; min-width: 2.6em; padding: .1rem .4rem; border-radius: 6px; font-size: .72rem; font-weight: 700; background: #eef2f6; color: #6b7a89 }
.st.ECA { background: var(--warn-50); color: var(--warn) } .st.A { background: var(--primary-50); color: var(--primary) } .st.V { background: var(--ok); color: #fff }
h3 { font-size: .95rem; margin: .75rem 0 .3rem }
.obs { margin: .2rem 0 }
.legend { margin-top: .75rem }
.foot { text-align: center; margin-top: 1rem }
</style>
