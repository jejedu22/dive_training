<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import AppDialog from './AppDialog.vue'
import AppIcon from './AppIcon.vue'
import { post } from '../api'
import { useToast } from '../stores/toast'
import { PLACE_LABELS, STATUS_LABELS } from '../composables/mft'
import { today } from '../format'
import type { MftCurriculum, Place } from '../types'

/**
 * Évaluation groupée : un ou plusieurs éléments de une ou plusieurs compétences, même résultat pour tous les stagiaires
 * sélectionnés. Les règles du MFT sont vérifiées pour chacun ; ceux qui sont refusés sont listés avec la raison.
 */
const open = defineModel<boolean>({ required: true })
const props = defineProps<{ cur: MftCurriculum; trainees: { enrolmentId: number; name: string }[] }>()
const emit = defineEmits<{ saved: [okIds: number[]] }>()
const toast = useToast()

type Result = { enrolmentId: number; diverId?: number; name: string | null; ok: boolean; count: number; error: string | null }
const skills = ref<string[]>([])
const expanded = ref<Record<string, boolean>>({})
const form = ref({ status: 'A' as 'ECA' | 'A' | 'V', date: today(), place: 'pool' as Place, site: '', depth: '' as number | '', comment: '' })
const results = ref<Result[] | null>(null)
const busy = ref(false)
const error = ref('')
watch(open, (o) => {
  if (!o) return
  skills.value = []
  expanded.value = {}
  results.value = null
  error.value = ''
  form.value = { ...form.value, status: 'A', date: today(), comment: '', place: props.cur.naturalOnly ? 'natural' : form.value.place }
})

const compState = (keys: string[]) => {
  const n = keys.filter((k) => skills.value.includes(k)).length
  return n === 0 ? 'none' : n === keys.length ? 'all' : 'some'
}
function toggleComp(keys: string[]) {
  skills.value = compState(keys) === 'all' ? skills.value.filter((k) => !keys.includes(k)) : [...new Set([...skills.value, ...keys])]
}
const chosenComps = computed(() => props.cur.competencies.filter((c) => Object.keys(c.skills).some((k) => skills.value.includes(k))))
const naturalNeeded = computed(() => form.value.status === 'V' && form.value.place !== 'natural' && chosenComps.value.some((c) => !c.theory && (props.cur.naturalOnly || c.natural)))

async function save() {
  error.value = ''
  if (!skills.value.length) { error.value = 'Choisissez au moins une compétence.'; return }
  busy.value = true
  try {
    const f = form.value
    const r = await post<{ results: Result[]; saved: number }>('/evaluations/bulk', {
      enrolmentIds: props.trainees.map((t) => t.enrolmentId), skills: skills.value, status: f.status, date: f.date, place: f.place,
      site: f.site || null, depth: f.depth === '' ? null : f.depth, comment: f.comment || null,
    })
    results.value = r.results
    if (r.saved) toast.ok(`Enregistré pour ${r.saved} stagiaire${r.saved > 1 ? 's' : ''}.`)
    emit('saved', r.results.filter((x) => x.ok).map((x) => x.enrolmentId))
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    busy.value = false
  }
}
const refused = computed(() => results.value?.filter((r) => !r.ok) ?? [])
</script>

<template>
  <AppDialog v-model="open" :title="`Évaluer ${trainees.length} stagiaire${trainees.length > 1 ? 's' : ''} — ${cur.name}`">
    <template v-if="!results">
      <p class="who small muted">{{ trainees.map((t) => t.name).join(', ') }}</p>
      <form id="bulk-form" class="stack" style="gap:.75rem" @submit.prevent="save">
        <fieldset class="comps">
          <legend class="small muted">Compétences travaillées <small>(une ou plusieurs ; tous leurs éléments sont cochés, dépliez pour en choisir)</small></legend>
          <div v-for="c in cur.competencies" :key="c.key" class="comp">
            <div class="c-row">
              <label class="check">
                <input type="checkbox" :checked="compState(Object.keys(c.skills)) === 'all'" :indeterminate="compState(Object.keys(c.skills)) === 'some'" @change="toggleComp(Object.keys(c.skills))" />
                {{ c.title }}
              </label>
              <button v-if="Object.keys(c.skills).length > 1" type="button" class="btn btn-ghost btn-icon btn-sm" :title="expanded[c.key] ? 'Replier' : 'Choisir les éléments'" @click="expanded[c.key] = !expanded[c.key]">
                <AppIcon :name="expanded[c.key] ? 'up' : 'down'" :size="15" />
              </button>
            </div>
            <div v-if="expanded[c.key]" class="skills">
              <label v-for="(label, k) in c.skills" :key="k" class="check small"><input v-model="skills" type="checkbox" :value="k" /> {{ label }}</label>
            </div>
          </div>
        </fieldset>
        <div class="seg" role="radiogroup" aria-label="Résultat">
          <button v-for="s in (['ECA', 'A', 'V'] as const)" :key="s" type="button" role="radio" :aria-checked="form.status === s" :class="[s, { on: form.status === s }]" @click="form.status = s">{{ STATUS_LABELS[s] }}</button>
        </div>
        <p v-if="form.status === 'V'" class="small muted"><AppIcon name="info" :size="14" /> Les règles de validation sont vérifiées pour chaque stagiaire (niveau d'encadrement, lieu, compétences de fin de formation).</p>
        <p v-if="naturalNeeded" class="small warn"><AppIcon name="alert" :size="14" /> Ces compétences se valident en milieu naturel : changez le lieu, ou choisissez « Acquis ».</p>
        <div class="fields">
          <label class="field">Date<input v-model="form.date" type="date" :max="today()" required /></label>
          <label class="field">Lieu<select v-model="form.place"><option v-for="(l, p) in PLACE_LABELS" :key="p" :value="p">{{ l }}</option></select></label>
        </div>
        <div v-if="form.place !== 'pool'" class="fields">
          <label class="field">Site <small>(facultatif)</small><input v-model="form.site" maxlength="120" /></label>
          <label class="field">Profondeur max. <small>(m)</small><input v-model.number="form.depth" type="number" min="0" max="120" /></label>
        </div>
        <label class="field">Commentaire commun <small>(facultatif)</small><textarea v-model="form.comment" rows="2" maxlength="2000" placeholder="ex. séance fosse du mardi" /></label>
      </form>
      <div v-if="error" class="alert alert-ko" style="margin-top:.75rem"><AppIcon name="alert" /> <span>{{ error }}</span></div>
    </template>

    <template v-else>
      <p><strong>{{ results.length - refused.length }}</strong> stagiaire{{ results.length - refused.length > 1 ? 's' : '' }} sur {{ results.length }} évalué{{ results.length - refused.length > 1 ? 's' : '' }}
        ({{ skills.length }} élément{{ skills.length > 1 ? 's' : '' }}, « {{ STATUS_LABELS[form.status] }} »).</p>
      <ul class="results">
        <li v-for="r in results" :key="r.enrolmentId" :class="{ ko: !r.ok }">
          <AppIcon :name="r.ok ? 'check' : 'x'" :size="16" />
          <div><strong>{{ r.name }}</strong><small v-if="!r.ok" class="err">{{ r.error }}</small></div>
          <router-link v-if="!r.ok && r.diverId" :to="`/stagiaires/${r.diverId}`" class="small">Ouvrir la fiche</router-link>
        </li>
      </ul>
      <p v-if="refused.length" class="small muted">Rien n'a été enregistré pour les stagiaires refusés. Ils restent sélectionnés dans le tableau.</p>
    </template>

    <template #actions>
      <template v-if="!results">
        <button type="button" class="btn btn-ghost" @click="open = false">Annuler</button>
        <button class="btn" form="bulk-form" :disabled="busy || !skills.length"><AppIcon name="check" /> Enregistrer pour {{ trainees.length }}</button>
      </template>
      <button v-else class="btn" @click="open = false">Fermer</button>
    </template>
  </AppDialog>
</template>

<style scoped>
.small { font-size: .85rem }
.who { margin: 0 0 .6rem }
.comps { border: 1px solid var(--line); border-radius: 10px; padding: .4rem .7rem; margin: 0; max-height: 44vh; overflow-y: auto }
.comps legend { padding: 0 .3rem }
.comp { border-bottom: 1px dashed var(--line) }
.comp:last-child { border-bottom: 0 }
.c-row { display: flex; align-items: center; justify-content: space-between; gap: .5rem; min-height: 40px }
.check { display: flex; align-items: center; gap: .5rem }
.skills { display: flex; flex-direction: column; gap: .3rem; padding: 0 0 .5rem 1.7rem }
.seg { display: grid; grid-template-columns: repeat(3, 1fr); border: 1px solid var(--line); border-radius: 10px; overflow: hidden }
.seg button { border: 0; background: var(--surface); font: inherit; font-weight: 600; padding: .6rem .4rem; cursor: pointer; color: var(--muted); min-height: 44px }
.seg button + button { border-left: 1px solid var(--line) }
.seg button.on.ECA { background: var(--warn-50); color: var(--warn) }
.seg button.on.A { background: var(--primary); color: #fff }
.seg button.on.V { background: var(--ok); color: #fff }
.warn { color: var(--warn) }
.results { list-style: none; padding: 0; margin: .5rem 0 }
.results li { display: flex; align-items: center; gap: .6rem; padding: .45rem 0; border-top: 1px solid var(--line); color: var(--ok) }
.results li.ko { color: var(--ko) }
.results li div { flex: 1; display: flex; flex-direction: column; color: var(--text) }
.err { color: var(--ko) }
</style>
