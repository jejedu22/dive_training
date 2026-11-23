<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import AppDialog from '../components/AppDialog.vue'
import { get, post } from '../api'
import { useAuth } from '../stores/auth'
import { useToast } from '../stores/toast'
import { useMft } from '../composables/mft'
import { chipStyle } from '../colors'
import { dateFr, today } from '../format'
import type { EnrolmentSummary, Section, Trainee, TraineeSummary } from '../types'

/** Stagiaires et leur progression dans les cursus du MFT (N1, N2, N3). */
const auth = useAuth(), toast = useToast(), router = useRouter()
const { mft, load: loadMft } = useMft()
const trainees = ref<TraineeSummary[]>([])
const sections = ref<Section[]>([])
const loaded = ref(false)
const q = ref('')
const sectionFilter = ref<number | ''>('')
const curriculumFilter = ref('')
const archived = ref(false)

async function load() {
  try {
    ;[trainees.value, sections.value] = await Promise.all([get<TraineeSummary[]>(`/trainees${archived.value ? '?archived=1' : ''}`), get<Section[]>('/sections')])
  } catch (e) {
    toast.error(e)
  } finally {
    loaded.value = true
  }
}
onMounted(() => { load(); loadMft().catch(toast.error) })
watch(archived, load)

const mySections = computed(() => sections.value.filter((s) => auth.isReferent || s.instructors.some((i) => i.id === auth.user?.id)))
const filtered = computed(() => {
  const s = q.value.trim().toLowerCase()
  return trainees.value.filter((t) =>
    (!s || `${t.firstName} ${t.lastName}`.toLowerCase().includes(s) || `${t.lastName} ${t.firstName}`.toLowerCase().includes(s) || (t.licence ?? '').toLowerCase().includes(s))
    && (!sectionFilter.value || t.sections.some((x) => x.id === sectionFilter.value))
    && (!curriculumFilter.value || t.enrolments.some((e) => e.curriculum === curriculumFilter.value && e.status === 'active')))
})

const pct = (e: EnrolmentSummary) => Math.round((100 * e.progress.validated) / Math.max(1, e.progress.total))
/** Délai de 15 mois : alerte à moins de 3 mois de l'échéance. */
const late = (e: EnrolmentSummary) => !!e.deadline && e.status === 'active' && e.deadline < addMonths(today(), 3)
function addMonths(ymdStr: string, n: number) {
  const d = new Date(`${ymdStr}T12:00:00`)
  d.setMonth(d.getMonth() + n)
  return d.toISOString().slice(0, 10)
}
const statusLabel = { active: 'en cours', done: 'délivré', abandoned: 'abandonné' } as const

// --- Nouveau stagiaire
const createOpen = ref(false)
const form = ref({ firstName: '', lastName: '', licence: '', sectionId: 0 as number | 0, curriculum: 'N1', startedAt: today() })
const createError = ref('')
function openCreate() {
  form.value = { firstName: '', lastName: '', licence: '', sectionId: typeof sectionFilter.value === 'number' ? sectionFilter.value : (mySections.value[0]?.id ?? 0), curriculum: curriculumFilter.value || 'N1', startedAt: today() }
  createError.value = ''
  createOpen.value = true
}
async function create() {
  createError.value = ''
  try {
    const t = await post<Trainee>('/trainees', { ...form.value, sectionId: form.value.sectionId || null, curriculum: form.value.curriculum || null })
    createOpen.value = false
    toast.ok(`${t.firstName} ${t.lastName} ajouté(e).`)
    router.push(`/stagiaires/${t.id}`)
  } catch (e) {
    createError.value = (e as Error).message
  }
}
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Stagiaires</h1>
      <p>Suivi des compétences selon le Manuel de Formation Technique de la FFESSM (N1, N2, N3).</p>
    </div>
    <button v-if="mySections.length || auth.isReferent" class="btn" @click="openCreate"><AppIcon name="plus" /> Nouveau stagiaire</button>
  </div>

  <div class="card filters">
    <input v-model="q" type="search" placeholder="Rechercher un nom, une licence…" aria-label="Rechercher" />
    <select v-model="sectionFilter" aria-label="Section">
      <option value="">Toutes les sections</option>
      <option v-for="s in sections" :key="s.id" :value="s.id">{{ s.name }}</option>
    </select>
    <select v-model="curriculumFilter" aria-label="Cursus">
      <option value="">Tous les cursus</option>
      <option v-for="c in mft?.curricula ?? []" :key="c.code" :value="c.code">{{ c.name }} en cours</option>
    </select>
    <label class="check"><input v-model="archived" type="checkbox" /> Archivés</label>
  </div>

  <div class="list">
    <router-link v-for="t in filtered" :key="t.id" :to="`/stagiaires/${t.id}`" class="card trainee">
      <div class="who">
        <strong>{{ t.lastName }} {{ t.firstName }}</strong>
        <span class="chips">
          <span v-for="s in t.sections" :key="s.id" class="chip" :style="chipStyle(s.color)">{{ s.name }}</span>
          <span v-if="t.licence" class="muted small">Licence {{ t.licence }}</span>
        </span>
      </div>
      <div class="enrolments">
        <div v-for="e in t.enrolments" :key="e.id" class="enrolment" :class="e.status">
          <span class="name">{{ e.name }}</span>
          <template v-if="e.status === 'active'">
            <span class="bar" :title="`${e.progress.validated} compétence(s) validée(s) sur ${e.progress.total}`"><span :style="{ width: pct(e) + '%' }" /></span>
            <span class="small">{{ e.progress.validated }}/{{ e.progress.total }}</span>
            <span v-if="late(e)" class="chip chip-warn" :title="`Toutes les compétences doivent être acquises avant le ${dateFr(e.deadline!)}`">échéance {{ dateFr(e.deadline!) }}</span>
          </template>
          <span v-else class="chip" :class="e.status === 'done' ? 'chip-ok' : ''">{{ statusLabel[e.status] }}</span>
        </div>
        <span v-if="!t.enrolments.length" class="muted small">Aucun cursus</span>
      </div>
      <AppIcon name="right" class="go" />
    </router-link>
    <div v-if="loaded && !filtered.length" class="card empty">
      <strong>{{ trainees.length ? 'Aucun stagiaire ne correspond' : archived ? 'Aucun stagiaire archivé' : 'Aucun stagiaire pour l\'instant' }}</strong>
      <template v-if="!trainees.length && !archived">Ajoutez vos élèves avec « Nouveau stagiaire », ou depuis la page Sections.</template>
    </div>
  </div>

  <AppDialog v-model="createOpen" title="Nouveau stagiaire">
    <form id="trainee-form" class="stack" style="gap:.7rem" @submit.prevent="create">
      <div class="fields">
        <label class="field">Prénom<input v-model="form.firstName" required maxlength="80" /></label>
        <label class="field">Nom<input v-model="form.lastName" required maxlength="80" /></label>
      </div>
      <label class="field">N° de licence <small>(facultatif)</small><input v-model="form.licence" maxlength="30" /></label>
      <label class="field">Section
        <select v-model.number="form.sectionId" :required="!auth.isReferent">
          <option v-if="auth.isReferent" :value="0">— Aucune —</option>
          <option v-for="s in mySections" :key="s.id" :value="s.id">{{ s.name }}</option>
        </select>
      </label>
      <div class="fields">
        <label class="field">Cursus
          <select v-model="form.curriculum">
            <option value="">— Plus tard —</option>
            <option v-for="c in mft?.curricula ?? []" :key="c.code" :value="c.code">{{ c.name }}</option>
          </select>
        </label>
        <label v-if="form.curriculum" class="field">Début de la formation<input v-model="form.startedAt" type="date" required /></label>
      </div>
    </form>
    <div v-if="createError" class="alert alert-ko" style="margin-top:.75rem"><AppIcon name="alert" /> <span>{{ createError }}</span></div>
    <template #actions>
      <button type="button" class="btn btn-ghost" @click="createOpen = false">Annuler</button>
      <button class="btn" form="trainee-form"><AppIcon name="check" /> Créer</button>
    </template>
  </AppDialog>
</template>

<style scoped>
.filters { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1rem; padding: .75rem; align-items: center }
.filters input[type=search] { flex: 1; min-width: 200px }
.list { display: flex; flex-direction: column; gap: .5rem }
.trainee { display: flex; align-items: center; gap: 1rem; padding: .75rem 1rem; color: inherit; text-decoration: none }
.trainee:hover { box-shadow: 0 0 0 2px var(--primary-100) }
.who { flex: 1 1 40%; min-width: 0; display: flex; flex-direction: column; gap: .3rem }
.chips { display: flex; flex-wrap: wrap; gap: .3rem; align-items: center }
.enrolments { flex: 1 1 45%; display: flex; flex-direction: column; gap: .3rem }
.enrolment { display: flex; align-items: center; gap: .5rem; font-size: .9rem }
.enrolment.abandoned { opacity: .6 }
.name { font-weight: 600; min-width: 5.5em }
.bar { flex: 1; max-width: 180px; height: 8px; background: #e5ebf0; border-radius: 99px; overflow: hidden }
.bar span { display: block; height: 100%; background: var(--ok); border-radius: 99px }
.go { color: var(--muted); flex: none }
.small { font-size: .82rem }
@media (max-width: 760px) {
  .trainee { flex-wrap: wrap; gap: .5rem }
  .who, .enrolments { flex-basis: 100% }
  .go { display: none }
}
</style>
