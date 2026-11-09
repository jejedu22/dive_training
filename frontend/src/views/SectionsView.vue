<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AppIcon from '../components/AppIcon.vue'
import { del, get, post, put } from '../api'
import { PALETTE } from '../colors'
import { useAuth } from '../stores/auth'
import { useToast } from '../stores/toast'
import type { Diver, Instructor, Section } from '../types'

const auth = useAuth(), toast = useToast()
const sections = ref<Section[]>([])
const divers = ref<Diver[]>([])
const instructors = ref<Instructor[]>([])
const showNew = ref(false)
const newSection = ref({ name: '', targetLevel: '', season: '', usesTanks: true })
const newDiver = ref<Record<number, { firstName: string; lastName: string; level: string }>>({})
const pickDiver = ref<Record<number, number | undefined>>({})
const pickInstructor = ref<Record<number, number | undefined>>({})
const open = ref<Record<number, boolean>>({})

async function load() {
  try {
    ;[sections.value, divers.value, instructors.value] = await Promise.all([get<Section[]>('/sections'), get<Diver[]>('/divers'), get<Instructor[]>('/instructors')])
  } catch (e) {
    toast.error(e)
  }
}
onMounted(load)

const isMine = (s: Section) => s.instructors.some((i) => i.id === auth.user?.id)
const sorted = computed(() => [...sections.value].sort((a, b) => Number(isMine(b)) - Number(isMine(a)) || a.name.localeCompare(b.name)))

async function run(fn: () => Promise<unknown>, ok: string) {
  try {
    await fn()
    toast.ok(ok)
    await load()
  } catch (e) {
    toast.error(e)
  }
}
const addSection = () => run(async () => {
  await post('/sections', newSection.value)
  newSection.value = { name: '', targetLevel: '', season: '', usesTanks: true }
  showNew.value = false
}, 'Section créée. Vous en êtes encadrant.')
const attach = (s: Section) => pickDiver.value[s.id] && run(() => post(`/sections/${s.id}/divers`, { diverId: pickDiver.value[s.id] }).then(() => (pickDiver.value[s.id] = undefined)), 'Élève ajouté.')
const detach = (s: Section, d: Diver) => run(() => del(`/sections/${s.id}/divers/${d.id}`), `${d.firstName} retiré(e) de la section.`)
const addInstructor = (s: Section) => pickInstructor.value[s.id] && run(() => post(`/sections/${s.id}/instructors`, { userId: pickInstructor.value[s.id] }).then(() => (pickInstructor.value[s.id] = undefined)), 'Encadrant ajouté.')
const removeInstructor = (s: Section, id: number) => run(() => del(`/sections/${s.id}/instructors/${id}`), 'Encadrant retiré.')
const setColor = (s: Section, color: string) => color !== s.color && run(() => put(`/sections/${s.id}`, { color }), `Couleur de ${s.name} modifiée.`)
// --- Renommer / modifier une section (nom, niveau visé, saison)
const editing = ref<Record<number, { name: string; targetLevel: string; season: string; usesTanks: boolean }>>({})
const startEdit = (s: Section) => (editing.value[s.id] = { name: s.name, targetLevel: s.targetLevel ?? '', season: s.season ?? '', usesTanks: s.usesTanks })
const cancelEdit = (s: Section) => delete editing.value[s.id]
async function saveEdit(s: Section) {
  const f = editing.value[s.id]
  if (!f?.name.trim()) return
  try {
    await put(`/sections/${s.id}`, f)
    toast.ok(f.name.trim() !== s.name ? `Section renommée « ${f.name.trim()} ».` : 'Section modifiée.')
    delete editing.value[s.id]
    await load()
  } catch (e) {
    toast.error(e)
  }
}
const joinSection = (s: Section) => run(() => post(`/sections/${s.id}/instructors`, { userId: auth.user!.id }), `Vous encadrez maintenant ${s.name}.`)

function diverForm(s: Section) {
  return (newDiver.value[s.id] ??= { firstName: '', lastName: '', level: '' })
}
const createDiver = (s: Section) => run(async () => {
  const f = diverForm(s)
  const d = await post<Diver>('/divers', f)
  await post(`/sections/${s.id}/divers`, { diverId: d.id })
  newDiver.value[s.id] = { firstName: '', lastName: '', level: '' }
}, 'Élève créé et ajouté.')
</script>

<template>
  <div class="page-head">
    <div><h1>Sections</h1><p>Groupes de formation, leurs encadrants et leurs élèves.</p></div>
    <button v-if="!showNew" class="btn" @click="showNew = true"><AppIcon name="plus" /> Nouvelle section</button>
  </div>

  <form v-if="showNew" class="card" style="margin-bottom:1rem" @submit.prevent="addSection">
    <div class="card-head"><h2>Nouvelle section</h2><button type="button" class="btn btn-ghost btn-icon" @click="showNew = false"><AppIcon name="x" /></button></div>
    <div class="fields">
      <label class="field">Nom<input v-model="newSection.name" placeholder="ex. Niveau 1 – jeudi" required /></label>
      <label class="field">Niveau visé<input v-model="newSection.targetLevel" placeholder="N1, N2, Apnée…" /></label>
      <label class="field">Saison<input v-model="newSection.season" placeholder="2026-2027" /></label>
    </div>
    <label class="check" style="margin-top:.75rem"><input v-model="newSection.usesTanks" type="checkbox" /> Cette section utilise des bouteilles (suivi « qui fait bouteille » dans le planning)</label>
    <div class="row" style="margin-top:1rem"><button class="btn"><AppIcon name="check" /> Créer</button></div>
  </form>

  <div v-if="!sections.length && !showNew" class="card empty"><strong>Aucune section</strong>Créez votre première section de formation.</div>

  <div class="stack">
    <article v-for="s in sorted" :key="s.id" class="card" :style="{ borderLeftColor: s.color }">
      <form v-if="editing[s.id]" class="edit" @submit.prevent="saveEdit(s)" @keydown.esc="cancelEdit(s)">
        <div class="fields">
          <label class="field">Nom<input v-model="editing[s.id].name" maxlength="120" required autofocus /></label>
          <label class="field">Niveau visé<input v-model="editing[s.id].targetLevel" maxlength="40" placeholder="N1, N2, Apnée…" /></label>
          <label class="field">Saison<input v-model="editing[s.id].season" maxlength="20" placeholder="2026-2027" /></label>
        </div>
        <label class="check" style="margin-top:.6rem"><input v-model="editing[s.id].usesTanks" type="checkbox" /> Cette section utilise des bouteilles</label>
        <div class="row" style="margin-top:.6rem">
          <button class="btn btn-sm" :disabled="!editing[s.id].name.trim()"><AppIcon name="check" :size="15" /> Enregistrer</button>
          <button type="button" class="btn btn-ghost btn-sm" @click="cancelEdit(s)">Annuler</button>
        </div>
      </form>
      <div v-else class="card-head">
        <div>
          <h2><span class="swatch" :style="{ background: s.color }" />{{ s.name }}
            <button class="btn btn-ghost btn-icon btn-sm" title="Renommer la section" @click="startEdit(s)"><AppIcon name="edit" :size="15" /></button>
            <span v-if="isMine(s)" class="chip chip-ok"><AppIcon name="star" :size="12" /> ma section</span>
            <span v-if="!s.usesTanks" class="chip" title="Pas de suivi des bouteilles dans le planning">sans bouteilles</span></h2>
          <p>{{ [s.targetLevel, s.season].filter(Boolean).join(' · ') || '—' }} · {{ s.divers.length }} élève{{ s.divers.length > 1 ? 's' : '' }}</p>
        </div>
        <div class="row">
          <button v-if="!isMine(s)" class="btn btn-secondary btn-sm" @click="joinSection(s)"><AppIcon name="plus" :size="15" /> J'encadre cette section</button>
          <button class="btn btn-ghost btn-sm" @click="open[s.id] = !open[s.id]">{{ open[s.id] ? 'Replier' : 'Gérer' }}</button>
        </div>
      </div>

      <div class="row">
        <span class="muted" style="font-size:.88rem">Encadrants :</span>
        <span v-for="i in s.instructors" :key="i.id" class="chip">{{ i.name }}
          <button v-if="open[s.id]" class="x" :title="`Retirer ${i.name}`" @click="removeInstructor(s, i.id)"><AppIcon name="x" :size="12" /></button></span>
        <span v-if="!s.instructors.length" class="muted">aucun</span>
      </div>

      <template v-if="open[s.id]">
        <div class="row colors">
          <span class="muted" style="font-size:.88rem">Couleur sur le planning :</span>
          <button v-for="c in PALETTE" :key="c" class="color" :class="{ on: c === s.color }" :style="{ background: c }" :title="c" @click="setColor(s, c)" />
          <label class="color custom" :class="{ on: !PALETTE.includes(s.color) }" :style="{ background: PALETTE.includes(s.color) ? '#fff' : s.color }" title="Autre couleur">
            <input type="color" :value="s.color" @change="setColor(s, ($event.target as HTMLInputElement).value)" />+
          </label>
        </div>
        <div class="row" style="margin-top:.6rem">
          <select v-model.number="pickInstructor[s.id]">
            <option :value="undefined">Ajouter un encadrant…</option>
            <option v-for="i in instructors.filter((x) => !s.instructors.some((y) => y.id === x.id))" :key="i.id" :value="i.id">{{ i.name }}</option>
          </select>
          <button class="btn btn-secondary btn-sm" :disabled="!pickInstructor[s.id]" @click="addInstructor(s)">Ajouter</button>
        </div>

        <h3 class="sub">Élèves</h3>
        <div v-if="s.divers.length" class="divers">
          <div v-for="d in s.divers" :key="d.id" class="diver">
            <span><strong>{{ d.lastName }}</strong> {{ d.firstName }} <small v-if="d.level">· {{ d.level }}</small></span>
            <button class="btn btn-ghost btn-icon btn-sm" :title="`Retirer ${d.firstName}`" @click="detach(s, d)"><AppIcon name="x" :size="15" /></button>
          </div>
        </div>
        <p v-else class="muted">Aucun élève.</p>

        <div class="row" style="margin-top:.75rem">
          <select v-model.number="pickDiver[s.id]">
            <option :value="undefined">Élève existant…</option>
            <option v-for="d in divers.filter((x) => !s.divers.some((y) => y.id === x.id))" :key="d.id" :value="d.id">{{ d.lastName }} {{ d.firstName }}</option>
          </select>
          <button class="btn btn-secondary btn-sm" :disabled="!pickDiver[s.id]" @click="attach(s)">Ajouter</button>
        </div>
        <form class="row" style="margin-top:.5rem" @submit.prevent="createDiver(s)">
          <input v-model="diverForm(s).firstName" placeholder="Prénom" required />
          <input v-model="diverForm(s).lastName" placeholder="Nom" required />
          <input v-model="diverForm(s).level" placeholder="Niveau" style="width:110px" />
          <button class="btn btn-secondary btn-sm"><AppIcon name="plus" :size="15" /> Nouvel élève</button>
        </form>
      </template>
    </article>
  </div>
</template>

<style scoped>
.card { border-left: 5px solid var(--line) }
.edit { margin-bottom: .75rem; padding-bottom: .75rem; border-bottom: 1px solid var(--line) }
.swatch { width: 14px; height: 14px; border-radius: 4px; display: inline-block; flex: none }
.colors { margin-bottom: .6rem; gap: .35rem }
.color { width: 28px; height: 28px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 0 1px var(--line); cursor: pointer; padding: 0; position: relative }
.color.on { box-shadow: 0 0 0 2px var(--text) }
.color.custom { display: grid; place-items: center; font-weight: 700; color: var(--muted); overflow: hidden }
.color.custom input { position: absolute; inset: 0; opacity: 0; cursor: pointer; min-height: 0; padding: 0; border: 0 }
.card-head h2 { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap }
.sub { margin: 1rem 0 .4rem; font-size: .88rem; color: var(--muted) }
.divers { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: .25rem .75rem }
.diver { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed var(--line); padding: .15rem 0 }
.x { border: 0; background: none; padding: 0; margin-left: .15rem; cursor: pointer; color: inherit; display: inline-flex }
</style>
