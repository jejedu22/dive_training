<script setup lang="ts">
import { computed, ref } from 'vue'
import AppIcon from './AppIcon.vue'
import AppDialog from './AppDialog.vue'
import { del, post } from '../api'
import { useAuth } from '../stores/auth'
import { useToast } from '../stores/toast'
import { dateFr, today } from '../format'
import type { Enrolment, MftCurriculum, MftModule, ModuleState, TeachingSessionEntry, Trainee } from '../types'

/**
 * Livret pédagogique Initiateur : trois modules évalués par séances (A, ECA, NT par item),
 * exigences pratiques du module 2, validation des modules par le tuteur (E4 ou E3-TSI), dispense du module 1.
 */
const props = defineProps<{ enrolment: Enrolment; cur: MftCurriculum; editable: boolean }>()
const emit = defineEmits<{ updated: [Trainee] }>()
const auth = useAuth(), toast = useToast()

const modules = computed(() => (props.cur.modules ?? []).map((def) => ({ def, state: props.enrolment.modules!.find((m) => m.key === def.key)! })))
const items = (m: MftModule) => Object.assign({}, ...Object.values(m.phases)) as Record<string, string>
const canUndo = computed(() => auth.isReferent || !!props.enrolment.canTutor)

async function act(fn: () => Promise<Trainee>, ok: string) {
  try {
    emit('updated', await fn())
    toast.ok(ok)
    return true
  } catch (e) {
    toast.error(e)
    return false
  }
}
const moduleAction = (m: MftModule, body: Record<string, unknown>, ok: string) => act(() => post<Trainee>(`/enrolments/${props.enrolment.id}/modules/${m.key}`, body), ok)
function validate(m: MftModule) {
  if (confirm(`Valider le module « ${m.title} » ? (tuteur de stage)`)) moduleAction(m, { action: 'validate', date: today() }, 'Module validé.')
}
function unvalidate(m: MftModule) {
  if (confirm('Annuler la validation de ce module ?')) moduleAction(m, { action: 'validate', undo: true }, 'Validation annulée.')
}
function exempt(m: MftModule, undo = false) {
  if (undo || confirm(`Accorder la dispense du module « ${m.title} » ?\n\n${m.exemptable}`)) moduleAction(m, { action: 'exempt', undo }, undo ? 'Dispense annulée.' : 'Dispense accordée.')
}
const requirement = (m: MftModule, key: string, done: boolean) =>
  moduleAction(m, { action: 'requirement', key, undo: !done, date: today() }, done ? 'Réussite enregistrée.' : 'Réussite retirée.')

// --- Séance
const open = ref(false)
const mod = ref<MftModule | null>(null)
const form = ref({ date: today(), level: 'N1', theme: '', items: {} as Record<string, 'A' | 'ECA' | 'NT'>, comment: '' })
const error = ref('')
function openSession(m: MftModule) {
  mod.value = m
  form.value = { date: today(), level: form.value.level || 'N1', theme: '', items: Object.fromEntries(Object.keys(items(m)).map((k) => [k, 'NT'])), comment: '' }
  error.value = ''
  open.value = true
}
const setAll = (v: 'A' | 'ECA' | 'NT') => { for (const k of Object.keys(form.value.items)) form.value.items[k] = v }
const formState = computed(() => {
  const v = Object.values(form.value.items)
  return !v.length ? '' : v.every((x) => x === 'A') ? 'validée' : v.includes('NT') ? 'incomplète' : 'complète'
})
async function save() {
  error.value = ''
  try {
    emit('updated', await post<Trainee>(`/enrolments/${props.enrolment.id}/sessions`, { module: mod.value!.key, ...form.value, theme: form.value.theme || null, comment: form.value.comment || null }))
    toast.ok(`Séance enregistrée (${formState.value}).`)
    open.value = false
  } catch (e) {
    error.value = (e as Error).message
  }
}
const canDelete = (s: TeachingSessionEntry, st: ModuleState) => !st.validated && (auth.isReferent || s.instructorId === auth.user?.id)
function remove(s: TeachingSessionEntry) {
  if (confirm(`Supprimer la séance du ${dateFr(s.date)} ?`)) act(() => del<Trainee>(`/sessions/${s.id}`), 'Séance supprimée.')
}
const expanded = ref<Record<string, boolean>>({})
const sessionLabel = (s: TeachingSessionEntry) => (s.validated ? 'Validée' : s.complete ? 'Complète' : 'Incomplète')
</script>

<template>
  <p class="legend small">Pour chaque séance, chaque item est noté <span class="st A">A</span> acquis, <span class="st ECA">ECA</span> en cours d'acquisition ou <span class="st NT">NT</span> non travaillé.
    Une séance est <strong>complète</strong> quand toutes ses phases sont réalisées, <strong>validée</strong> quand toutes sont acquises. Tuteur : {{ cur.tutor }}.</p>

  <article v-for="{ def, state } in modules" :key="def.key" class="card module" :class="{ done: state.validated || state.exempted }">
    <header>
      <div>
        <h3>{{ def.title }}</h3>
        <p v-if="def.minSessions > 1" class="small muted">Au moins {{ def.minSessions }} séances complètes dont {{ def.minValidated }} validées, sur des thèmes et des niveaux de plongeurs différents.</p>
        <p v-if="def.help" class="small muted">{{ def.help }}</p>
      </div>
      <span v-if="state.validated" class="chip chip-ok">Validé le {{ dateFr(state.validated.date) }}</span>
      <span v-else-if="state.exempted" class="chip chip-primary">Dispensé</span>
    </header>

    <div v-if="!state.exempted" class="counters">
      <span :class="{ ok: state.counts.complete >= def.minSessions }"><strong>{{ state.counts.complete }}</strong>/{{ def.minSessions }} complètes</span>
      <span :class="{ ok: state.counts.validated >= def.minValidated }"><strong>{{ state.counts.validated }}</strong>/{{ def.minValidated }} validées</span>
      <span v-if="def.distinct" :class="{ ok: state.counts.themes >= 2 && state.counts.levels >= 2 }">{{ state.counts.themes }} thème{{ state.counts.themes > 1 ? 's' : '' }} · {{ state.counts.levels }} niveau{{ state.counts.levels > 1 ? 'x' : '' }}</span>
      <span class="muted">{{ state.counts.sessions }} séance{{ state.counts.sessions > 1 ? 's' : '' }} au total</span>
    </div>

    <div v-if="Object.keys(def.requirements).length" class="reqs">
      <label v-for="(label, k) in def.requirements" :key="k" class="check">
        <input type="checkbox" :checked="!!state.requirements[k]" :disabled="!editable || !!state.validated" @change="requirement(def, k as string, ($event.target as HTMLInputElement).checked)" />
        {{ label }}<small v-if="state.requirements[k]" class="muted"> — le {{ dateFr(state.requirements[k].date) }} ({{ state.requirements[k].by }})</small>
      </label>
    </div>

    <p v-if="state.exempted" class="small muted">Dispense accordée le {{ dateFr(state.exempted.date) }} par {{ state.exempted.by }} (module MF1 validé).</p>
    <p v-else-if="state.validated" class="small ok">Validé par {{ state.validated.by }}.</p>

    <div v-if="editable" class="row actions">
      <button v-if="!state.validated && !state.exempted" class="btn btn-secondary btn-sm" @click="openSession(def)"><AppIcon name="plus" :size="15" /> Séance</button>
      <button v-if="!state.validated && !state.exempted" class="btn btn-ok btn-sm" :disabled="!!state.cannotValidate" :title="state.cannotValidate ?? ''" @click="validate(def)"><AppIcon name="check" :size="15" /> Valider le module</button>
      <button v-if="state.validated && canUndo" class="btn btn-ghost btn-sm" @click="unvalidate(def)">Annuler la validation</button>
      <button v-if="def.exemptable && !state.validated && !state.exempted && enrolment.canTutor" class="btn btn-ghost btn-sm" @click="exempt(def)">Dispense (MF1)</button>
      <button v-if="state.exempted && canUndo" class="btn btn-ghost btn-sm" @click="exempt(def, true)">Annuler la dispense</button>
    </div>
    <p v-if="editable && !state.validated && !state.exempted && state.cannotValidate" class="small muted why"><AppIcon name="info" :size="14" /> {{ state.cannotValidate }}</p>

    <div v-if="state.sessions.length" class="sessions">
      <div v-for="s in state.sessions" :key="s.id" class="session">
        <button class="s-head" @click="expanded[s.id] = !expanded[s.id]">
          <span class="chip" :class="s.validated ? 'chip-ok' : s.complete ? 'chip-primary' : ''">{{ sessionLabel(s) }}</span>
          <span class="s-main"><strong>{{ dateFr(s.date) }}</strong> · {{ s.level ?? '—' }}<template v-if="s.theme"> · {{ s.theme }}</template></span>
          <span class="small muted">{{ s.instructor }}</span>
          <AppIcon :name="expanded[s.id] ? 'up' : 'down'" :size="16" />
        </button>
        <div v-if="expanded[s.id]" class="s-body">
          <div v-for="(phase, name) in def.phases" :key="name" class="phase">
            <h4>{{ name }}</h4>
            <div v-for="(label, k) in phase" :key="k" class="item"><span class="st" :class="s.items[k] ?? 'NT'">{{ s.items[k] ?? 'NT' }}</span> {{ label }}</div>
          </div>
          <p v-if="s.comment" class="small comment">« {{ s.comment }} »</p>
          <button v-if="canDelete(s, state)" class="btn btn-ghost btn-sm" @click="remove(s)"><AppIcon name="trash" :size="14" /> Supprimer</button>
        </div>
      </div>
    </div>
  </article>

  <AppDialog v-model="open" :title="mod ? `Séance — ${mod.title}` : 'Séance'">
    <form v-if="mod" id="session-form" class="stack" style="gap:.75rem" @submit.prevent="save">
      <div class="fields">
        <label class="field">Date<input v-model="form.date" type="date" :max="today()" required /></label>
        <label class="field">Niveau travaillé
          <select v-model="form.level"><option v-for="l in cur.levelsTaught" :key="l" :value="l">{{ l }}</option></select>
        </label>
      </div>
      <label class="field">Thème travaillé<input v-model="form.theme" maxlength="160" placeholder="ex. Vidage de masque, accueil d'un groupe de N1" /></label>
      <div class="row all"><span class="small muted">Tout noter :</span>
        <button type="button" class="btn btn-ghost btn-sm" @click="setAll('A')">A</button>
        <button type="button" class="btn btn-ghost btn-sm" @click="setAll('ECA')">ECA</button>
        <button type="button" class="btn btn-ghost btn-sm" @click="setAll('NT')">NT</button>
      </div>
      <div v-for="(phase, name) in mod.phases" :key="name" class="phase">
        <h4>{{ name }}</h4>
        <div v-for="(label, k) in phase" :key="k" class="pick">
          <span>{{ label }}</span>
          <span class="tri" role="radiogroup" :aria-label="label">
            <button v-for="v in (['A', 'ECA', 'NT'] as const)" :key="v" type="button" role="radio" :aria-checked="form.items[k] === v" :class="[v, { on: form.items[k] === v }]" @click="form.items[k as string] = v">{{ v }}</button>
          </span>
        </div>
      </div>
      <p class="small" :class="formState === 'validée' ? 'ok' : 'muted'">Séance {{ formState }}.</p>
      <label class="field">Commentaire du formateur <small>(facultatif)</small><textarea v-model="form.comment" rows="2" maxlength="2000" /></label>
    </form>
    <div v-if="error" class="alert alert-ko" style="margin-top:.75rem"><AppIcon name="alert" /> <span>{{ error }}</span></div>
    <template #actions>
      <button type="button" class="btn btn-ghost" @click="open = false">Annuler</button>
      <button class="btn" form="session-form"><AppIcon name="check" /> Enregistrer</button>
    </template>
  </AppDialog>
</template>

<style scoped>
.small { font-size: .85rem }
.ok { color: var(--ok) }
.legend { color: var(--muted); margin: .25rem 0 .75rem; line-height: 1.7 }
.module { padding: .85rem 1rem; margin-bottom: .6rem; border-left: 4px solid #d5dde5 }
.module.done { border-left-color: var(--ok) }
.module header { display: flex; justify-content: space-between; gap: .75rem; align-items: flex-start }
.module h3 { margin: 0; font-size: 1.02rem }
.module header p { margin: .2rem 0 0 }
.counters { display: flex; flex-wrap: wrap; gap: .4rem 1rem; margin: .6rem 0; font-size: .9rem }
.counters .ok strong, .counters .ok { color: var(--ok) }
.reqs { display: flex; flex-direction: column; gap: .2rem; margin: .4rem 0 }
.check { display: flex; align-items: center; gap: .5rem; font-size: .92rem }
.actions { gap: .4rem; margin-top: .5rem }
.why { margin: .4rem 0 0 }
.sessions { margin-top: .6rem; border-top: 1px solid var(--line) }
.session { border-bottom: 1px dashed var(--line) }
.s-head { display: flex; align-items: center; gap: .6rem; width: 100%; background: none; border: 0; padding: .5rem .2rem; cursor: pointer; font: inherit; color: inherit; text-align: left }
.s-main { flex: 1 }
.s-body { padding: 0 .2rem .6rem }
.phase h4 { margin: .5rem 0 .25rem; font-size: .8rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted) }
.item { display: flex; align-items: center; gap: .5rem; padding: .15rem 0; font-size: .92rem }
.comment { font-style: italic }
.st { display: inline-flex; justify-content: center; min-width: 2.6em; padding: .1rem .35rem; border-radius: 6px; font-size: .72rem; font-weight: 700; background: #eef2f6; color: #6b7a89 }
.st.ECA { background: var(--warn-50); color: var(--warn) }
.st.A { background: var(--ok); color: #fff }
.all { gap: .25rem; align-items: center }
.pick { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .3rem 0; border-top: 1px dashed var(--line) }
.tri { display: inline-flex; border: 1px solid var(--line); border-radius: 8px; overflow: hidden; flex: none }
.tri button { border: 0; background: var(--surface); font: inherit; font-size: .8rem; font-weight: 700; padding: .45rem .6rem; min-width: 3.1em; cursor: pointer; color: var(--muted); min-height: 38px }
.tri button + button { border-left: 1px solid var(--line) }
.tri button.on.A { background: var(--ok); color: #fff }
.tri button.on.ECA { background: var(--warn-50); color: var(--warn) }
.tri button.on.NT { background: #e5ebf0; color: var(--text) }
</style>
