<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import AppIcon from '../components/AppIcon.vue'
import AppDialog from '../components/AppDialog.vue'
import InitiatorBook from '../components/InitiatorBook.vue'
import { del, get, post, put } from '../api'
import { useAuth } from '../stores/auth'
import { useToast } from '../stores/toast'
import { PLACE_LABELS, STATUS_LABELS, STATUS_SHORT, useMft } from '../composables/mft'
import { chipStyle } from '../colors'
import { dateFr, today } from '../format'
import type { Enrolment, EvaluationEntry, MftCompetency, Place, SkillStatus, Trainee } from '../types'

/** Fiche de suivi d'un stagiaire : grille des compétences du MFT, évaluations, prérequis et délivrance. */
const props = defineProps<{ id: number }>()
const auth = useAuth(), toast = useToast()
const { mft, load: loadMft, curriculum } = useMft()
const t = ref<Trainee | null>(null)
const error = ref('')
const tab = ref<number | null>(null)

async function load() {
  try {
    await loadMft()
    t.value = await get<Trainee>(`/trainees/${props.id}`)
    if (!t.value.enrolments.some((e) => e.id === tab.value)) tab.value = (t.value.enrolments.find((e) => e.status === 'active') ?? t.value.enrolments[0])?.id ?? null
    document.title = `${t.value.firstName} ${t.value.lastName} · Dive Training`
  } catch (e) {
    error.value = (e as Error).message
  }
}
onMounted(load)
watch(() => props.id, load)

const enrolment = computed(() => t.value?.enrolments.find((e) => e.id === tab.value) ?? null)
const cur = computed(() => (enrolment.value ? curriculum(enrolment.value.curriculum) : undefined))
const teaching = computed(() => cur.value?.type === 'teaching')
const editable = computed(() => !!t.value?.canEvaluate && enrolment.value?.status === 'active')
/** Compétences regroupées comme sur la fiche fédérale (communes, spécifiques PA20…). */
const groups = computed(() => {
  const out: { name: string | null; competencies: MftCompetency[] }[] = []
  for (const c of cur.value?.competencies ?? []) {
    const last = out[out.length - 1]
    if (last && last.name === c.group) last.competencies.push(c)
    else out.push({ name: c.group, competencies: [c] })
  }
  return out
})
const compStatus = (key: string) => enrolment.value?.competencies.find((c) => c.key === key)?.status ?? 'NT'
const skill = (key: string) => enrolment.value!.skills[key]
const notEnrolled = computed(() => (mft.value?.curricula ?? []).filter((c) => !t.value?.enrolments.some((e) => e.curriculum === c.code)))
const skillLabel = (key: string) => {
  for (const c of cur.value?.competencies ?? []) if (c.skills[key]) return `${c.title} — ${c.skills[key]}`
  return key
}
const pct = (e: Enrolment) => Math.round((100 * e.progress.validated) / Math.max(1, e.progress.total))
const placeText = (ev: EvaluationEntry) => [PLACE_LABELS[ev.place], ev.site, ev.depth != null ? `${ev.depth} m` : null].filter(Boolean).join(' · ')

async function run(fn: () => Promise<Trainee>, ok?: string) {
  try {
    t.value = await fn()
    if (ok) toast.ok(ok)
    return true
  } catch (e) {
    toast.error(e)
    return false
  }
}

// --- Inscription, prérequis, statut du suivi
const enrolOpen = ref(false)
const enrolForm = ref({ curriculum: '', startedAt: today() })
function openEnrol() {
  enrolForm.value = { curriculum: notEnrolled.value[0]?.code ?? '', startedAt: today() }
  enrolOpen.value = true
}
async function enrol() {
  if (await run(() => post<Trainee>(`/trainees/${props.id}/enrolments`, enrolForm.value), 'Cursus ajouté.')) {
    tab.value = t.value!.enrolments.find((e) => e.curriculum === enrolForm.value.curriculum)?.id ?? tab.value
    enrolOpen.value = false
  }
}
const togglePrereq = (label: string, done: boolean) => run(() => put<Trainee>(`/enrolments/${enrolment.value!.id}`, { prerequisites: { [label]: done } }))
const stage = ref({ date: '', ctr: '' })
watch(enrolment, (e) => { stage.value = { date: e?.dossier?.stageInitial?.date ?? '', ctr: e?.dossier?.stageInitial?.ctr ?? '' } }, { immediate: true })
const saveStage = () => run(() => put<Trainee>(`/enrolments/${enrolment.value!.id}`, { dossier: { stageInitial: stage.value.date ? stage.value : null } }), 'Stage initial enregistré.')
function setStatus(status: 'active' | 'abandoned') {
  if (status === 'abandoned' && !confirm(`Marquer le ${enrolment.value!.name} comme abandonné ? Les évaluations sont conservées et le suivi pourra être repris.`)) return
  run(() => put<Trainee>(`/enrolments/${enrolment.value!.id}`, { status }), status === 'active' ? 'Suivi repris.' : 'Suivi marqué abandonné.')
}
function removeEnrolment() {
  if (!confirm(`Supprimer l'inscription au ${enrolment.value!.name} ?`)) return
  run(() => del<Trainee>(`/enrolments/${enrolment.value!.id}`), 'Inscription supprimée.').then(() => (tab.value = t.value?.enrolments[0]?.id ?? null))
}

// --- Délivrance
function issue(code: string, label: string) {
  if (teaching.value && !confirm(`Enregistrer la réussite de ${t.value!.firstName} ${t.value!.lastName} à l'examen initiateur (CTR), à la date d'aujourd'hui ?`)) return
  if (!teaching.value && !confirm(`Délivrer « ${label} » à ${t.value!.firstName} ${t.value!.lastName} à la date d'aujourd'hui ?`)) return
  run(() => post<Trainee>(`/enrolments/${enrolment.value!.id}/issue`, { qualification: code, date: today() }), `${label} délivré(e).`)
}
function unissue(code: string, label: string) {
  if (!confirm(`Annuler la délivrance de « ${label} » ?`)) return
  run(() => del<Trainee>(`/enrolments/${enrolment.value!.id}/issue/${code}`), 'Délivrance annulée.')
}

// --- Fiche (nom, licence, archivage)
const editOpen = ref(false)
const editForm = ref({ firstName: '', lastName: '', licence: '' })
function openEdit() {
  editForm.value = { firstName: t.value!.firstName, lastName: t.value!.lastName, licence: t.value!.licence ?? '' }
  editOpen.value = true
}
async function saveEdit() {
  if (await run(() => put<Trainee>(`/trainees/${props.id}`, editForm.value), 'Fiche modifiée.')) editOpen.value = false
}
const toggleArchive = () => run(() => put<Trainee>(`/trainees/${props.id}`, { archived: !t.value!.archived }), t.value!.archived ? 'Stagiaire réactivé(e).' : 'Stagiaire archivé(e).')

// --- Évaluation
const evalOpen = ref(false)
const evalComp = ref<MftCompetency | null>(null)
const evalForm = ref({ skills: [] as string[], status: 'ECA' as Exclude<SkillStatus, 'NT'>, date: today(), place: 'pool' as Place, site: '', depth: '' as number | '', comment: '' })
const evalError = ref('')
const lastPlace = ref<{ place: Place; site: string; depth: number | '' }>({ place: 'pool', site: '', depth: '' })
function openEval(c: MftCompetency, skillKey?: string) {
  evalComp.value = c
  const keys = skillKey ? [skillKey] : Object.keys(c.skills)
  evalForm.value = { skills: keys, status: 'ECA', date: today(), ...lastPlace.value, comment: '' }
  if (cur.value?.naturalOnly && !c.theory && evalForm.value.place !== 'natural') evalForm.value.place = 'natural'
  evalError.value = ''
  evalOpen.value = true
}
/** Raison pour laquelle « Validé » est impossible pour la sélection (null = possible). */
const validateBlocked = computed(() => {
  if (!enrolment.value) return null
  for (const k of evalForm.value.skills) {
    const v = skill(k).validate
    const why = evalForm.value.place === 'natural' ? v.natural : v.pool
    if (why) return why
  }
  return null
})
watch(validateBlocked, (why) => { if (why && evalForm.value.status === 'V') evalForm.value.status = 'A' })
async function saveEval() {
  evalError.value = ''
  if (!evalForm.value.skills.length) { evalError.value = 'Cochez au moins un élément.'; return }
  try {
    const f = evalForm.value
    t.value = await post<Trainee>(`/enrolments/${enrolment.value!.id}/evaluations`, {
      skills: f.skills, status: f.status, date: f.date, place: f.place, site: f.site || null, depth: f.depth === '' ? null : f.depth, comment: f.comment || null,
    })
    lastPlace.value = { place: f.place, site: f.site, depth: f.depth }
    toast.ok(f.skills.length > 1 ? `${f.skills.length} éléments évalués.` : 'Évaluation enregistrée.')
    evalOpen.value = false
  } catch (e) {
    evalError.value = (e as Error).message
  }
}
const canDelete = (ev: EvaluationEntry) => auth.isReferent || ev.instructorId === auth.user?.id
function removeEval(ev: EvaluationEntry) {
  if (!confirm(`Supprimer l'évaluation « ${STATUS_LABELS[ev.status]} » du ${dateFr(ev.date)} (${skillLabel(ev.skill)}) ?`)) return
  run(() => del<Trainee>(`/evaluations/${ev.id}`), 'Évaluation supprimée.')
}
const historyOpen = ref(false)
const statuses: Exclude<SkillStatus, 'NT'>[] = ['ECA', 'A', 'V']
</script>

<template>
  <router-link to="/stagiaires" class="back"><AppIcon name="left" :size="16" /> Stagiaires</router-link>
  <div v-if="error" class="card empty"><strong>Fiche indisponible</strong>{{ error }}</div>
  <p v-else-if="!t" class="empty">Chargement…</p>
  <template v-else>
    <div class="page-head">
      <div>
        <h1>{{ t.firstName }} {{ t.lastName }} <span v-if="t.archived" class="chip">archivé</span></h1>
        <p class="chips">
          <span v-for="s in t.sections" :key="s.id" class="chip" :style="chipStyle(s.color)">{{ s.name }}</span>
          <span v-if="!t.sections.length" class="muted">Aucune section</span>
          <span v-if="t.licence" class="muted">· Licence {{ t.licence }}</span>
        </p>
      </div>
      <div v-if="t.canEvaluate" class="row">
        <button v-if="notEnrolled.length" class="btn btn-secondary" @click="openEnrol"><AppIcon name="plus" :size="16" /> Cursus</button>
        <button class="btn btn-secondary btn-icon" title="Modifier la fiche" @click="openEdit"><AppIcon name="edit" /></button>
      </div>
    </div>

    <div v-if="!t.enrolments.length" class="card empty">
      <strong>Aucun cursus</strong>
      <template v-if="t.canEvaluate">Inscrivez le stagiaire à un cursus (N1, N2, N3) pour commencer son suivi.
        <button class="btn" style="margin-top:.75rem" @click="openEnrol"><AppIcon name="plus" /> Inscrire à un cursus</button></template>
    </div>

    <template v-else>
      <div v-if="t.enrolments.length > 1" class="tabs" role="tablist">
        <button v-for="e in t.enrolments" :key="e.id" role="tab" :class="{ on: e.id === tab }" @click="tab = e.id">
          {{ e.name }} <small v-if="e.status !== 'active'">({{ e.status === 'done' ? 'délivré' : 'abandonné' }})</small>
        </button>
      </div>

      <template v-if="enrolment && cur">
        <section class="card summary">
          <div class="sum-main">
            <h2>{{ cur.name }} <small class="muted">{{ cur.subtitle }}</small></h2>
            <p class="muted small">{{ enrolment.version }} · début le {{ dateFr(enrolment.startedAt) }}
              <template v-if="enrolment.firstValidatedAt"> · {{ teaching ? 'stage initial le' : '1re compétence validée le' }} {{ dateFr(enrolment.firstValidatedAt) }}</template></p>
            <div class="progress">
              <span class="bar"><span :style="{ width: pct(enrolment) + '%' }" /></span>
              <strong>{{ enrolment.progress.validated }}/{{ enrolment.progress.total }}</strong> {{ teaching ? 'modules validés' : 'compétences validées' }}
              <span class="muted">· {{ enrolment.progress.started }} commencée{{ enrolment.progress.started > 1 ? 's' : '' }}</span>
            </div>
            <p v-if="enrolment.deadline && enrolment.status === 'active'" class="small" :class="{ warn: enrolment.deadline < today() }">
              <AppIcon name="clock" :size="14" />
              <template v-if="teaching"> Livret pédagogique valable jusqu'au <strong>{{ dateFr(enrolment.deadline) }}</strong> (3 ans après le stage initial).</template>
              <template v-else> Toutes les compétences doivent être acquises avant le <strong>{{ dateFr(enrolment.deadline) }}</strong> ({{ cur.delayMonths }} mois après la première).</template>
            </p>
          </div>
          <div class="sum-actions">
            <span v-if="enrolment.status === 'done'" class="chip chip-ok">Délivré</span>
            <span v-else-if="enrolment.status === 'abandoned'" class="chip">Abandonné</span>
            <template v-if="t.canEvaluate && enrolment.status !== 'done'">
              <button v-if="enrolment.status === 'active'" class="btn btn-ghost btn-sm" @click="setStatus('abandoned')">Marquer abandonné</button>
              <button v-else class="btn btn-secondary btn-sm" @click="setStatus('active')">Reprendre le suivi</button>
              <button v-if="auth.isReferent && !enrolment.evaluations.length && !(teaching && enrolment.progress.started)" class="btn btn-danger btn-sm" @click="removeEnrolment">Supprimer</button>
            </template>
          </div>
        </section>

        <div class="two">
          <section class="card">
            <h3>{{ teaching ? 'Dossier' : 'Prérequis' }}</h3>
            <form v-if="teaching" class="stage" @submit.prevent="saveStage">
              <label class="field">Stage initial le<input v-model="stage.date" type="date" :disabled="!t.canEvaluate" /></label>
              <label class="field">Organisé par (CTR)<input v-model="stage.ctr" maxlength="80" :disabled="!t.canEvaluate" placeholder="ex. CTR Est" /></label>
              <button v-if="t.canEvaluate" class="btn btn-secondary btn-sm">Enregistrer</button>
            </form>
            <label v-for="p in enrolment.prerequisites" :key="p.label" class="check">
              <input type="checkbox" :checked="p.done" :disabled="!t.canEvaluate" @change="togglePrereq(p.label, ($event.target as HTMLInputElement).checked)" /> {{ p.label }}
            </label>
            <ul class="notes">
              <li v-for="n in cur.notes" :key="n">{{ n }}</li>
              <li v-if="teaching">Modules validés par le tuteur : <strong>{{ cur.tutor }}</strong>. Résultat de l'examen enregistré par un référent.</li>
              <li v-else>Validation : encadrant <strong>{{ cur.validateLevel }}</strong> minimum<template v-if="cur.naturalOnly">, en milieu naturel</template>. Délivrance : <strong>{{ cur.issueLevel }}</strong> minimum<template v-if="cur.presidentMayIssue"> ou président du club</template>.</li>
            </ul>
          </section>
          <section class="card">
            <h3>{{ teaching ? 'Examen' : 'Délivrance' }}</h3>
            <div v-for="q in enrolment.qualifications" :key="q.code" class="qual">
              <div>
                <strong>{{ q.label }}</strong>
                <p v-if="q.issued" class="small ok">{{ teaching ? 'Réussi le' : 'Délivré le' }} {{ dateFr(q.issued.date) }} ({{ teaching ? 'enregistré par' : 'par' }} {{ q.issued.by }})</p>
                <p v-else-if="q.cannotIssue" class="small muted">{{ q.cannotIssue }}</p>
                <p v-else class="small ok">{{ teaching ? 'Modules validés : le stagiaire peut se présenter à l\'examen.' : 'Toutes les compétences sont validées.' }}</p>
              </div>
              <button v-if="q.issued && auth.isReferent" class="btn btn-ghost btn-sm" @click="unissue(q.code, q.label)">Annuler</button>
              <button v-else-if="!q.issued" class="btn btn-ok btn-sm" :disabled="!!q.cannotIssue" :title="q.cannotIssue ?? ''" @click="issue(q.code, q.label)"><AppIcon name="check" :size="15" /> {{ teaching ? 'Examen réussi' : 'Délivrer' }}</button>
            </div>
          </section>
        </div>

        <InitiatorBook v-if="teaching" :enrolment="enrolment" :cur="cur" :editable="editable" @updated="t = $event" />
        <template v-else>
        <p class="legend small">
          <span v-for="s in (['NT', 'ECA', 'A', 'V'] as SkillStatus[])" :key="s"><span class="st" :class="s">{{ STATUS_SHORT[s] }}</span> {{ STATUS_LABELS[s] }}</span>
        </p>

        <section v-for="g in groups" :key="g.name ?? 'all'" class="group">
          <h3 v-if="g.name" class="group-title">{{ g.name }}</h3>
          <article v-for="c in g.competencies" :key="c.key" class="card comp" :class="'c-' + compStatus(c.key)">
            <header>
              <div>
                <h4>{{ c.title }}</h4>
                <span class="tags">
                  <span v-if="c.final" class="tag" title="À valider spécifiquement et en fin de formation">fin de formation</span>
                  <span v-if="c.theory" class="tag">connaissances · oral, situation ou écrit</span>
                  <span v-if="c.natural && !cur.naturalOnly" class="tag">milieu naturel</span>
                  <span v-if="cur.qualifications && Object.keys(cur.qualifications).length > 1" class="tag q">{{ c.quals.filter((x) => x !== cur!.code).join(' · ') || cur.code }}</span>
                </span>
              </div>
              <span class="st big" :class="compStatus(c.key)">{{ STATUS_SHORT[compStatus(c.key)] }}</span>
            </header>
            <ul class="skills">
              <li v-for="(label, k) in c.skills" :key="k" :class="{ clickable: editable }" @click="editable && openEval(c, k as string)">
                <span class="st" :class="skill(k as string).status">{{ STATUS_SHORT[skill(k as string).status] }}</span>
                <span class="label">{{ label }}</span>
                <span v-if="skill(k as string).last" class="last small muted">{{ dateFr(skill(k as string).last!.date) }} · {{ PLACE_LABELS[skill(k as string).last!.place] }} · {{ skill(k as string).last!.instructor }}</span>
              </li>
            </ul>
            <button v-if="editable && Object.keys(c.skills).length > 1" class="btn btn-ghost btn-sm all" @click="openEval(c)">Évaluer toute la compétence</button>
          </article>
        </section>

        <section class="card history">
          <button class="history-toggle" @click="historyOpen = !historyOpen">
            <h3>Historique des évaluations ({{ enrolment.evaluations.length }})</h3><AppIcon :name="historyOpen ? 'up' : 'down'" />
          </button>
          <ul v-if="historyOpen" class="evals">
            <li v-for="ev in enrolment.evaluations" :key="ev.id">
              <span class="st" :class="ev.status">{{ STATUS_SHORT[ev.status] }}</span>
              <div class="ev-body">
                <strong>{{ skillLabel(ev.skill) }}</strong>
                <span class="small muted">{{ dateFr(ev.date) }} · {{ placeText(ev) }} · {{ ev.instructor }}</span>
                <span v-if="ev.comment" class="small comment">« {{ ev.comment }} »</span>
              </div>
              <button v-if="canDelete(ev) && enrolment.status !== 'done'" class="btn btn-ghost btn-icon btn-sm" title="Supprimer" @click="removeEval(ev)"><AppIcon name="trash" :size="15" /></button>
            </li>
            <li v-if="!enrolment.evaluations.length" class="muted">Aucune évaluation.</li>
          </ul>
        </section>
        </template>
      </template>
    </template>

    <p class="muted small foot">Référentiel : intitulés du Manuel de Formation Technique et du livret pédagogique Initiateur FFESSM. Contenus détaillés et modalités d'évaluation : se référer au manuel officiel.</p>

    <!-- Évaluer -->
    <AppDialog v-model="evalOpen" :title="evalComp?.title ?? 'Évaluer'">
      <form v-if="evalComp" id="eval-form" class="stack" style="gap:.75rem" @submit.prevent="saveEval">
        <fieldset class="skills-pick">
          <legend class="small muted">Élément{{ Object.keys(evalComp.skills).length > 1 ? 's' : '' }}</legend>
          <label v-for="(label, k) in evalComp.skills" :key="k" class="check">
            <input v-model="evalForm.skills" type="checkbox" :value="k" /> {{ label }}
            <span class="st" :class="skill(k as string).status">{{ STATUS_SHORT[skill(k as string).status] }}</span>
          </label>
        </fieldset>
        <div class="seg" role="radiogroup" aria-label="Résultat">
          <button v-for="s in statuses" :key="s" type="button" role="radio" :aria-checked="evalForm.status === s" :class="[s, { on: evalForm.status === s }]"
            :disabled="s === 'V' && !!validateBlocked" @click="evalForm.status = s">{{ STATUS_LABELS[s] }}</button>
        </div>
        <p v-if="validateBlocked" class="small muted"><AppIcon name="info" :size="14" /> « Validé » indisponible : {{ validateBlocked }}</p>
        <div class="fields">
          <label class="field">Date<input v-model="evalForm.date" type="date" :max="today()" required /></label>
          <label class="field">Lieu
            <select v-model="evalForm.place"><option v-for="(l, p) in PLACE_LABELS" :key="p" :value="p">{{ l }}</option></select>
          </label>
        </div>
        <div v-if="evalForm.place !== 'pool'" class="fields">
          <label class="field">Site <small>(facultatif)</small><input v-model="evalForm.site" maxlength="120" :placeholder="evalForm.place === 'natural' ? 'ex. Carrière de …, mer' : 'ex. Fosse de …'" /></label>
          <label class="field">Profondeur max. <small>(m)</small><input v-model.number="evalForm.depth" type="number" min="0" max="120" /></label>
        </div>
        <label class="field">Commentaire <small>(facultatif)</small><textarea v-model="evalForm.comment" rows="2" maxlength="2000" placeholder="ex. vidage de masque sans stress, stabilisation encore fragile" /></label>
      </form>
      <div v-if="evalError" class="alert alert-ko" style="margin-top:.75rem"><AppIcon name="alert" /> <span>{{ evalError }}</span></div>
      <template #actions>
        <button type="button" class="btn btn-ghost" @click="evalOpen = false">Annuler</button>
        <button class="btn" form="eval-form"><AppIcon name="check" /> Enregistrer</button>
      </template>
    </AppDialog>

    <!-- Inscrire à un cursus -->
    <AppDialog v-model="enrolOpen" title="Inscrire à un cursus">
      <form id="enrol-form" class="stack" style="gap:.7rem" @submit.prevent="enrol">
        <label class="field">Cursus
          <select v-model="enrolForm.curriculum" required><option v-for="c in notEnrolled" :key="c.code" :value="c.code">{{ c.name }} — {{ c.subtitle }}</option></select>
        </label>
        <label class="field">Début de la formation<input v-model="enrolForm.startedAt" type="date" required /></label>
      </form>
      <template #actions>
        <button type="button" class="btn btn-ghost" @click="enrolOpen = false">Annuler</button>
        <button class="btn" form="enrol-form"><AppIcon name="check" /> Inscrire</button>
      </template>
    </AppDialog>

    <!-- Fiche -->
    <AppDialog v-model="editOpen" title="Fiche du stagiaire">
      <form id="edit-form" class="stack" style="gap:.7rem" @submit.prevent="saveEdit">
        <div class="fields">
          <label class="field">Prénom<input v-model="editForm.firstName" required maxlength="80" /></label>
          <label class="field">Nom<input v-model="editForm.lastName" required maxlength="80" /></label>
        </div>
        <label class="field">N° de licence<input v-model="editForm.licence" maxlength="30" /></label>
        <p class="muted small">Les sections du stagiaire se gèrent depuis la page Sections.</p>
      </form>
      <template #actions>
        <button type="button" class="btn btn-ghost" @click="editOpen = false; toggleArchive()">{{ t.archived ? 'Réactiver' : 'Archiver' }}</button>
        <button class="btn" form="edit-form"><AppIcon name="check" /> Enregistrer</button>
      </template>
    </AppDialog>
  </template>
</template>

<style scoped>
.back { display: inline-flex; align-items: center; gap: .25rem; text-decoration: none; font-weight: 600; margin-bottom: .5rem }
.chips { display: flex; flex-wrap: wrap; gap: .3rem; align-items: center; margin: .3rem 0 0 }
.small { font-size: .85rem }
.tabs { display: flex; gap: .3rem; margin-bottom: .75rem; overflow-x: auto }
.tabs button { border: 1px solid var(--primary-100); background: var(--surface); color: var(--primary); font: inherit; font-weight: 600; padding: .45rem .9rem; border-radius: 999px; cursor: pointer; white-space: nowrap }
.tabs button.on { background: var(--primary); color: #fff; border-color: var(--primary) }
.summary { display: flex; flex-wrap: wrap; gap: 1rem; justify-content: space-between; margin-bottom: .75rem }
.summary h2 { margin: 0; font-size: 1.2rem } .summary h2 small { font-weight: 400; font-size: .85rem }
.summary p { margin: .25rem 0 }
.sum-main { flex: 1; min-width: 260px }
.sum-actions { display: flex; flex-wrap: wrap; gap: .4rem; align-items: flex-start }
.progress { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin: .5rem 0 .25rem }
.bar { width: 200px; max-width: 50%; height: 10px; background: #e5ebf0; border-radius: 99px; overflow: hidden }
.bar span { display: block; height: 100%; background: var(--ok) }
.warn { color: var(--warn) }
.ok { color: var(--ok) }
.two { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: .75rem; margin-bottom: .75rem }
.two h3, .history h3 { margin: 0 0 .5rem; font-size: 1rem }
.check { display: flex; align-items: center; gap: .5rem; padding: .2rem 0 }
.stage { display: grid; grid-template-columns: 1fr 1fr auto; gap: .5rem; align-items: end; margin-bottom: .5rem }
@media (max-width: 500px) { .stage { grid-template-columns: 1fr } }
.notes { margin: .5rem 0 0; padding-left: 1.1rem; color: var(--muted); font-size: .85rem }
.qual { display: flex; justify-content: space-between; align-items: center; gap: .75rem; padding: .45rem 0; border-top: 1px solid var(--line) }
.qual:first-of-type { border-top: 0 }
.qual p { margin: .1rem 0 0 }
.legend { display: flex; flex-wrap: wrap; gap: .4rem 1rem; color: var(--muted); margin: .25rem 0 .75rem }
.legend > span { display: inline-flex; align-items: center; gap: .35rem }

/* Statuts : NT gris, ECA orange, A bleu, V vert */
.st { display: inline-flex; align-items: center; justify-content: center; min-width: 2.6em; padding: .1rem .4rem; border-radius: 6px; font-size: .72rem; font-weight: 700; background: #eef2f6; color: #6b7a89; flex: none }
.st.ECA { background: var(--warn-50); color: var(--warn) }
.st.A { background: var(--primary-50); color: var(--primary) }
.st.V { background: var(--ok); color: #fff }
.st.big { font-size: .8rem; padding: .2rem .55rem }

.group { margin-bottom: .75rem }
.group-title { font-size: .85rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); margin: 1rem 0 .4rem }
.comp { padding: .75rem 1rem; margin-bottom: .5rem; border-left: 4px solid #d5dde5 }
.comp.c-ECA { border-left-color: #f0c36b } .comp.c-A { border-left-color: var(--primary-100) } .comp.c-V { border-left-color: var(--ok) }
.comp header { display: flex; justify-content: space-between; align-items: flex-start; gap: .75rem }
.comp h4 { margin: 0; font-size: 1rem }
.tags { display: flex; flex-wrap: wrap; gap: .3rem; margin-top: .25rem }
.tag { font-size: .7rem; font-weight: 600; padding: .05rem .45rem; border-radius: 99px; background: var(--warn-50); color: #7a4e00 }
.tag.q { background: var(--primary-50); color: var(--primary) }
.skills { list-style: none; margin: .5rem 0 0; padding: 0 }
.skills li { display: flex; align-items: center; gap: .6rem; padding: .4rem .3rem; border-top: 1px dashed var(--line); border-radius: 6px }
.skills li.clickable { cursor: pointer } .skills li.clickable:hover { background: var(--primary-50) }
.skills .label { flex: 1 }
.last { text-align: right }
.all { margin-top: .3rem }
.history { margin-top: 1rem }
.history-toggle { display: flex; justify-content: space-between; align-items: center; width: 100%; background: none; border: 0; padding: 0; cursor: pointer; color: inherit; font: inherit }
.history-toggle h3 { margin: 0 }
.evals { list-style: none; padding: 0; margin: .75rem 0 0 }
.evals li { display: flex; gap: .6rem; align-items: flex-start; padding: .5rem 0; border-top: 1px solid var(--line) }
.ev-body { flex: 1; display: flex; flex-direction: column; gap: .1rem }
.comment { font-style: italic }
.foot { margin-top: 1.25rem; text-align: center }
.skills-pick { border: 0; padding: 0; margin: 0 }
.skills-pick .check { justify-content: flex-start }
.skills-pick .st { margin-left: auto }
.seg { display: grid; grid-template-columns: repeat(3, 1fr); border: 1px solid var(--line); border-radius: 10px; overflow: hidden }
.seg button { border: 0; background: var(--surface); font: inherit; font-weight: 600; padding: .6rem .4rem; cursor: pointer; color: var(--muted); min-height: 44px }
.seg button + button { border-left: 1px solid var(--line) }
.seg button.on.ECA { background: var(--warn-50); color: var(--warn) }
.seg button.on.A { background: var(--primary); color: #fff }
.seg button.on.V { background: var(--ok); color: #fff }
.seg button:disabled { opacity: .45; cursor: not-allowed }
@media (max-width: 760px) {
  .skills li { flex-wrap: wrap }
  .last { flex-basis: 100%; text-align: left; padding-left: 3.2em }
}
</style>
