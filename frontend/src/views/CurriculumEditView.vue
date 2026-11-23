<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { onBeforeRouteLeave, useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import { del, get, put } from '../api'
import { useToast } from '../stores/toast'
import { useMft } from '../composables/mft'
import type { CurriculumDetail, InstructorLevel, MftCurriculum } from '../types'

/**
 * Modification d'un cursus (administrateurs). Les clés des éléments sont conservées : les évaluations restent rattachées.
 * Un élément évalué, ou un module qui a des séances, ne peut pas être supprimé (le bouton est grisé).
 */
const props = defineProps<{ code: string }>()
const toast = useToast(), router = useRouter()
const { reset: resetMft } = useMft()

type Item = { key?: string; label: string }
type Comp = { key?: string; title: string; group: string; quals: string[]; final: boolean; theory: boolean; natural: boolean; skills: Item[] }
type Mod = { key?: string; title: string; minSessions: number; minValidated: number; distinct: boolean; exemptable: string; help: string; phases: { name: string; items: Item[] }[]; requirements: Item[] }
interface Form {
  name: string; subtitle: string; version: string; validateLevel: InstructorLevel; issueLevel: InstructorLevel; presidentMayIssue: boolean; naturalOnly: boolean; delayMonths: number
  qualifications: { code: string; label: string }[]; prerequisites: string; notes: string; tutor: string; levelsTaught: string
  competencies: Comp[]; modules: Mod[]
}
const detail = ref<CurriculumDetail | null>(null)
const form = ref<Form | null>(null)
const saved = ref('')
const error = ref('')
const saving = ref(false)
const LEVELS: InstructorLevel[] = ['E1', 'E2', 'E3', 'E4']
const LOCKED = ['Stage initial réalisé (14 h, encadré par un E4)', 'RIFA Plongée']

function toForm(c: MftCurriculum): Form {
  return {
    name: c.name, subtitle: c.subtitle, version: c.version, validateLevel: c.validateLevel, issueLevel: c.issueLevel,
    presidentMayIssue: c.presidentMayIssue, naturalOnly: c.naturalOnly, delayMonths: c.delayMonths,
    qualifications: Object.entries(c.qualifications).map(([code, label]) => ({ code, label })),
    prerequisites: c.prerequisites.join('\n'), notes: c.notes.join('\n'), tutor: c.tutor ?? '', levelsTaught: (c.levelsTaught ?? []).join(', '),
    competencies: c.competencies.map((x) => ({ key: x.key, title: x.title, group: x.group ?? '', quals: [...x.quals], final: x.final, theory: x.theory, natural: x.natural,
      skills: Object.entries(x.skills).map(([key, label]) => ({ key, label })) })),
    modules: (c.modules ?? []).map((m) => ({ key: m.key, title: m.title, minSessions: m.minSessions, minValidated: m.minValidated, distinct: m.distinct, exemptable: m.exemptable ?? '', help: m.help ?? '',
      phases: Object.entries(m.phases).map(([name, items]) => ({ name, items: Object.entries(items).map(([key, label]) => ({ key, label })) })),
      requirements: Object.entries(m.requirements).map(([key, label]) => ({ key, label })) })),
  }
}
function toPayload(f: Form) {
  const lines = (s: string) => s.split('\n').map((x) => x.trim()).filter(Boolean)
  return {
    ...f, prerequisites: lines(f.prerequisites), notes: lines(f.notes),
    levelsTaught: f.levelsTaught.split(',').map((x) => x.trim()).filter(Boolean),
    qualifications: Object.fromEntries(f.qualifications.filter((q) => q.code.trim()).map((q) => [q.code.trim().toUpperCase(), q.label])),
    competencies: f.competencies.map((c) => ({ ...c, group: c.group || null })),
    modules: f.modules.map((m) => ({ ...m, exemptable: m.exemptable || null, help: m.help || null })),
  }
}
function apply(d: CurriculumDetail) {
  detail.value = d
  form.value = toForm(d.definition)
  saved.value = JSON.stringify(form.value)
}
async function load() {
  try { apply(await get<CurriculumDetail>(`/curricula/${props.code}`)) } catch (e) { error.value = (e as Error).message }
}
onMounted(load)
watch(() => props.code, load)

const dirty = computed(() => !!form.value && JSON.stringify(form.value) !== saved.value)
const teaching = computed(() => detail.value?.type === 'teaching')
const used = (key?: string) => (key ? detail.value?.usage.skills[key] ?? 0 : 0)
const compUsed = (c: Comp) => c.skills.reduce((n, s) => n + used(s.key), 0)
const modUsed = (m: Mod) => (m.key ? detail.value?.usage.modules[m.key] !== undefined : false)
const reqUsed = (m: Mod, r: Item) => !!(m.key && r.key && detail.value?.usage.requirements[`${m.key}.${r.key}`])
const qualCodes = computed(() => form.value?.qualifications.map((q) => q.code.trim().toUpperCase()).filter(Boolean) ?? [])
const groups = computed(() => [...new Set(form.value?.competencies.map((c) => c.group).filter(Boolean))])

function move<T>(list: T[], i: number, d: number) {
  const j = i + d
  if (j < 0 || j >= list.length) return
  ;[list[i], list[j]] = [list[j], list[i]]
}
const addComp = () => form.value!.competencies.push({ title: '', group: '', quals: [props.code], final: false, theory: false, natural: false, skills: [{ label: '' }] })
const addModule = () => form.value!.modules.push({ title: '', minSessions: 1, minValidated: 1, distinct: false, exemptable: '', help: '', phases: [{ name: 'Phase', items: [{ label: '' }] }], requirements: [] })

async function save() {
  error.value = ''
  saving.value = true
  try {
    apply(await put<CurriculumDetail>(`/curricula/${props.code}`, toPayload(form.value!)))
    resetMft()
    toast.ok('Cursus enregistré.')
  } catch (e) {
    error.value = (e as Error).message
    toast.error(e)
  } finally {
    saving.value = false
  }
}
async function resetOrDelete() {
  const club = detail.value!.origin === 'club'
  if (!confirm(club ? `Supprimer le cursus « ${detail.value!.name} » ?` : 'Revenir aux valeurs du MFT ? Toutes les modifications du club sur ce cursus seront perdues.')) return
  try {
    const r = await del<CurriculumDetail | undefined>(`/curricula/${props.code}`)
    resetMft()
    if (r) { apply(r); toast.ok('Valeurs du MFT rétablies.') } else { saved.value = JSON.stringify(form.value); toast.ok('Cursus supprimé.'); router.push('/cursus') }
  } catch (e) {
    toast.error(e)
  }
}
const beforeUnload = (e: BeforeUnloadEvent) => { if (dirty.value) e.preventDefault() }
onMounted(() => addEventListener('beforeunload', beforeUnload))
onBeforeUnmount(() => removeEventListener('beforeunload', beforeUnload))
onBeforeRouteLeave(() => !dirty.value || confirm('Quitter sans enregistrer les modifications ?'))
</script>

<template>
  <router-link to="/cursus" class="back"><AppIcon name="left" :size="16" /> Cursus</router-link>
  <div v-if="error && !form" class="card empty"><strong>Cursus indisponible</strong>{{ error }}</div>
  <p v-else-if="!form || !detail" class="empty">Chargement…</p>
  <template v-else>
    <div class="page-head">
      <div>
        <h1>{{ detail.code }} — {{ form.name || 'Sans nom' }}</h1>
        <p>{{ detail.usage.enrolments }} suivi{{ detail.usage.enrolments > 1 ? 's' : '' }}.
          <template v-if="detail.origin === 'mft'">Valeurs du MFT (non modifiées).</template>
          <template v-else-if="detail.updatedAt">Modifié le {{ new Date(detail.updatedAt).toLocaleDateString('fr-FR') }}<template v-if="detail.updatedBy"> par {{ detail.updatedBy }}</template>.</template>
          Les modifications s'appliquent à tous les stagiaires de ce cursus.</p>
      </div>
      <button v-if="detail.origin !== 'mft'" class="btn btn-ghost" @click="resetOrDelete">{{ detail.origin === 'club' ? 'Supprimer le cursus' : 'Revenir aux valeurs du MFT' }}</button>
    </div>

    <form class="stack" @submit.prevent="save">
      <section class="card">
        <h2>Général</h2>
        <div class="fields">
          <label class="field">Nom<input v-model="form.name" required maxlength="60" /></label>
          <label class="field">Référentiel / version<input v-model="form.version" maxlength="40" placeholder="ex. MFT décembre 2025" /></label>
        </div>
        <label class="field">Sous-titre<input v-model="form.subtitle" maxlength="160" /></label>
        <div class="fields">
          <label class="field">Valider : encadrant minimum<select v-model="form.validateLevel"><option v-for="l in LEVELS" :key="l">{{ l }}</option></select></label>
          <label class="field">Délivrer : encadrant minimum<select v-model="form.issueLevel"><option v-for="l in LEVELS" :key="l">{{ l }}</option></select></label>
          <label class="field">{{ teaching ? 'Validité du livret (mois)' : 'Délai pour tout acquérir (mois)' }}<input v-model.number="form.delayMonths" type="number" min="1" max="120" /></label>
        </div>
        <label class="check"><input v-model="form.presidentMayIssue" type="checkbox" /> Le président du club peut délivrer</label>
        <label v-if="!teaching" class="check"><input v-model="form.naturalOnly" type="checkbox" /> Validation en milieu naturel uniquement (hors connaissances théoriques)</label>
        <template v-if="teaching">
          <label class="field">Tuteur (texte affiché)<input v-model="form.tutor" maxlength="120" /></label>
          <label class="field">Niveaux travaillés <small>(séparés par des virgules)</small><input v-model="form.levelsTaught" /></label>
        </template>
      </section>

      <section class="card">
        <h2>Qualifications délivrées</h2>
        <p class="muted small">La qualification <strong>{{ code }}</strong> correspond au brevet : sa délivrance clôt le suivi. Les autres (ex. PA20, PE40) se délivrent séparément.</p>
        <div v-for="(q, i) in form.qualifications" :key="i" class="row line">
          <input v-model="q.code" maxlength="10" class="code-in" :disabled="q.code === code" placeholder="Code" />
          <input v-model="q.label" maxlength="80" required class="grow" placeholder="Nom" />
          <button type="button" class="btn btn-ghost btn-icon btn-sm" title="Supprimer" :disabled="q.code === code" @click="form.qualifications.splice(i, 1)"><AppIcon name="trash" :size="15" /></button>
        </div>
        <button type="button" class="btn btn-ghost btn-sm" @click="form.qualifications.push({ code: '', label: '' })"><AppIcon name="plus" :size="15" /> Qualification</button>
      </section>

      <section class="card">
        <h2>{{ teaching ? 'Dossier' : 'Prérequis' }} et remarques</h2>
        <div class="fields">
          <label class="field">{{ teaching ? 'Pièces du dossier' : 'Prérequis' }} <small>(un par ligne)</small><textarea v-model="form.prerequisites" rows="5" /></label>
          <label class="field">Remarques affichées sur la fiche <small>(une par ligne)</small><textarea v-model="form.notes" rows="5" /></label>
        </div>
        <p v-if="teaching" class="muted small">« {{ LOCKED.join(' » et « ') }} » sont utilisés par les règles de l'examen : ne les modifiez pas.</p>
        <p class="muted small">Renommer un prérequis décoche ce prérequis chez les stagiaires.</p>
      </section>

      <!-- Compétences (cursus plongeur) -->
      <section v-if="!teaching" class="stack">
        <h2 class="section-title">Compétences et éléments évalués</h2>
        <datalist id="groups"><option v-for="g in groups" :key="g" :value="g" /></datalist>
        <article v-for="(c, i) in form.competencies" :key="c.key ?? `new${i}`" class="card comp">
          <div class="row comp-head">
            <span class="num">C{{ i + 1 }}</span>
            <input v-model="c.title" required maxlength="120" class="grow title" placeholder="Intitulé de la compétence" />
            <button type="button" class="btn btn-ghost btn-icon btn-sm" title="Monter" @click="move(form.competencies, i, -1)"><AppIcon name="up" :size="15" /></button>
            <button type="button" class="btn btn-ghost btn-icon btn-sm" title="Descendre" @click="move(form.competencies, i, 1)"><AppIcon name="down" :size="15" /></button>
            <button type="button" class="btn btn-ghost btn-icon btn-sm" :disabled="compUsed(c) > 0" :title="compUsed(c) ? `Évaluée ${compUsed(c)} fois : suppression impossible` : 'Supprimer la compétence'" @click="form.competencies.splice(i, 1)"><AppIcon name="trash" :size="15" /></button>
          </div>
          <div class="row opts">
            <input v-model="c.group" list="groups" maxlength="60" placeholder="Groupe (ex. Spécifiques PA20)" class="group" />
            <span class="quals"><label v-for="q in qualCodes" :key="q" class="check"><input v-model="c.quals" type="checkbox" :value="q" /> {{ q }}</label></span>
            <label class="check" title="À valider spécifiquement et en fin de formation"><input v-model="c.final" type="checkbox" /> fin de formation</label>
            <label class="check" title="Connaissances : pas de contrainte de lieu"><input v-model="c.theory" type="checkbox" /> théorie</label>
            <label class="check"><input v-model="c.natural" type="checkbox" /> milieu naturel</label>
          </div>
          <div v-for="(s, j) in c.skills" :key="s.key ?? `n${j}`" class="row line skill">
            <input v-model="s.label" required maxlength="160" class="grow" placeholder="Élément évalué" />
            <small v-if="used(s.key)" class="muted used">{{ used(s.key) }} éval.</small>
            <button type="button" class="btn btn-ghost btn-icon btn-sm" title="Monter" @click="move(c.skills, j, -1)"><AppIcon name="up" :size="14" /></button>
            <button type="button" class="btn btn-ghost btn-icon btn-sm" :disabled="used(s.key) > 0 || c.skills.length === 1" :title="used(s.key) ? 'Élément évalué : suppression impossible' : 'Supprimer'" @click="c.skills.splice(j, 1)"><AppIcon name="x" :size="14" /></button>
          </div>
          <button type="button" class="btn btn-ghost btn-sm" @click="c.skills.push({ label: '' })"><AppIcon name="plus" :size="14" /> Élément</button>
        </article>
        <button type="button" class="btn btn-secondary" @click="addComp"><AppIcon name="plus" /> Compétence</button>
      </section>

      <!-- Modules (formation initiateur) -->
      <section v-else class="stack">
        <h2 class="section-title">Modules</h2>
        <article v-for="(m, i) in form.modules" :key="m.key ?? `new${i}`" class="card comp">
          <div class="row comp-head">
            <span class="num">M{{ i + 1 }}</span>
            <input v-model="m.title" required maxlength="120" class="grow title" placeholder="Intitulé du module" />
            <button type="button" class="btn btn-ghost btn-icon btn-sm" title="Monter" @click="move(form.modules, i, -1)"><AppIcon name="up" :size="15" /></button>
            <button type="button" class="btn btn-ghost btn-icon btn-sm" :disabled="modUsed(m)" :title="modUsed(m) ? 'Module avec des séances : suppression impossible' : 'Supprimer le module'" @click="form.modules.splice(i, 1)"><AppIcon name="trash" :size="15" /></button>
          </div>
          <div class="fields">
            <label class="field">Séances complètes minimum<input v-model.number="m.minSessions" type="number" min="0" max="50" /></label>
            <label class="field">dont validées<input v-model.number="m.minValidated" type="number" min="0" max="50" /></label>
          </div>
          <label class="check"><input v-model="m.distinct" type="checkbox" /> Thèmes et niveaux de plongeurs différents exigés</label>
          <label class="field">Dispense possible <small>(texte ; vide = pas de dispense)</small><input v-model="m.exemptable" maxlength="300" /></label>
          <div v-for="(p, j) in m.phases" :key="j" class="phase">
            <div class="row line"><input v-model="p.name" required maxlength="40" class="phase-name" placeholder="Phase" />
              <button type="button" class="btn btn-ghost btn-icon btn-sm" title="Supprimer la phase" :disabled="m.phases.length === 1" @click="m.phases.splice(j, 1)"><AppIcon name="x" :size="14" /></button></div>
            <div v-for="(it, k) in p.items" :key="it.key ?? `n${k}`" class="row line skill">
              <input v-model="it.label" required maxlength="160" class="grow" placeholder="Item noté A / ECA / NT" />
              <button type="button" class="btn btn-ghost btn-icon btn-sm" :disabled="p.items.length === 1" title="Supprimer" @click="p.items.splice(k, 1)"><AppIcon name="x" :size="14" /></button>
            </div>
            <button type="button" class="btn btn-ghost btn-sm" @click="p.items.push({ label: '' })"><AppIcon name="plus" :size="14" /> Item</button>
          </div>
          <button type="button" class="btn btn-ghost btn-sm" @click="m.phases.push({ name: '', items: [{ label: '' }] })"><AppIcon name="plus" :size="14" /> Phase</button>
          <h4>Exigences pratiques</h4>
          <div v-for="(r, k) in m.requirements" :key="r.key ?? `r${k}`" class="row line skill">
            <input v-model="r.label" required maxlength="160" class="grow" />
            <button type="button" class="btn btn-ghost btn-icon btn-sm" :disabled="reqUsed(m, r)" :title="reqUsed(m, r) ? 'Exigence déjà enregistrée' : 'Supprimer'" @click="m.requirements.splice(k, 1)"><AppIcon name="x" :size="14" /></button>
          </div>
          <button type="button" class="btn btn-ghost btn-sm" @click="m.requirements.push({ label: '' })"><AppIcon name="plus" :size="14" /> Exigence</button>
          <p class="muted small">Ajouter un item rend « incomplètes » les séances déjà saisies qui ne l'ont pas noté.</p>
        </article>
        <button type="button" class="btn btn-secondary" @click="addModule"><AppIcon name="plus" /> Module</button>
      </section>

      <div class="savebar">
        <span v-if="error" class="error small">{{ error }}</span>
        <span v-else-if="dirty" class="muted small">Modifications non enregistrées</span>
        <span class="spacer" />
        <button type="button" class="btn btn-ghost" :disabled="!dirty" @click="form = JSON.parse(saved)">Annuler les modifications</button>
        <button class="btn" :disabled="saving || !dirty"><AppIcon name="check" /> Enregistrer</button>
      </div>
    </form>
  </template>
</template>

<style scoped>
.back { display: inline-flex; align-items: center; gap: .25rem; text-decoration: none; font-weight: 600; margin-bottom: .5rem }
h2 { font-size: 1.05rem; margin: 0 0 .6rem }
.section-title { margin: .5rem 0 0 }
.card { display: flex; flex-direction: column; gap: .6rem }
.card > .btn, .phase > .btn, .stack > .btn { align-self: flex-start }
.small { font-size: .85rem }
.check { display: inline-flex; align-items: center; gap: .4rem; font-size: .9rem; white-space: nowrap }
.line { flex-wrap: nowrap; gap: .4rem; align-items: center }
.grow { flex: 1; min-width: 0 }
.code-in { width: 7em }
.comp { padding: .75rem .9rem }
.comp-head { flex-wrap: nowrap; gap: .35rem }
.num { font-weight: 800; color: var(--primary); min-width: 2.4em }
.title { font-weight: 600 }
.opts { gap: .4rem .9rem; align-items: center }
.group { width: 15em }
.quals { display: inline-flex; flex-wrap: wrap; gap: .2rem .7rem; padding: .2rem .5rem; border: 1px dashed var(--line); border-radius: 8px }
.skill { padding-left: 2.6em }
.used { white-space: nowrap }
.phase { border-left: 3px solid var(--primary-100); padding-left: .6rem; display: flex; flex-direction: column; gap: .35rem }
.phase-name { font-weight: 600; width: 14em }
h4 { margin: .4rem 0 0; font-size: .9rem }
.savebar { position: sticky; bottom: 0; z-index: 5; display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; background: var(--surface); border: 1px solid var(--line); border-radius: 12px; padding: .6rem .8rem; box-shadow: 0 -4px 14px rgba(0,0,0,.06) }
.spacer { flex: 1 }
@media (max-width: 760px) {
  .savebar { bottom: calc(var(--tabbar-h) + .25rem) }
  .skill { padding-left: 0 }
  .group { width: 100% }
}
</style>
