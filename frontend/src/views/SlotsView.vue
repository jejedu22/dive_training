<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AppIcon from '../components/AppIcon.vue'
import { del, get, post, put } from '../api'
import { useAuth } from '../stores/auth'
import { useToast } from '../stores/toast'
import { WEEKDAYS, dateFr } from '../format'
import type { ClubSlot, Pool, Section } from '../types'

const auth = useAuth(), toast = useToast()
const slots = ref<ClubSlot[]>([])
const pools = ref<Pool[]>([])
const sections = ref<Section[]>([])

const empty = () => ({ id: 0, label: '', weekdays: [] as number[], startTime: '20:00', endTime: '22:00', allLanes: true, laneIds: [] as number[], validFrom: '', validUntil: '' })
const form = ref(empty())
const showForm = ref(false)
const formError = ref('')
/** Plan d'occupation en cours d'édition : slotId -> { laneId: sectionId } */
const plans = ref<Record<number, Record<number, number>>>({})

const allLaneIds = computed(() => pools.value.flatMap((p) => p.lanes.map((l) => l.id)))
const laneName = (id: number) => {
  for (const p of pools.value) for (const l of p.lanes) if (l.id === id) return `${p.name} · ${l.name}`
  return `#${id}`
}

async function load() {
  try {
    ;[slots.value, pools.value, sections.value] = await Promise.all([get<ClubSlot[]>('/slots'), get<Pool[]>('/pools'), get<Section[]>('/sections')])
    plans.value = Object.fromEntries(slots.value.map((s) => [s.id, Object.fromEntries(s.allocations.map((a) => [a.laneId, a.sectionId]))]))
  } catch (e) {
    toast.error(e)
  }
}
onMounted(load)

function openNew() {
  form.value = empty()
  formError.value = ''
  showForm.value = true
}
function edit(s: ClubSlot) {
  form.value = {
    id: s.id, label: s.label ?? '', weekdays: [...s.weekdays], startTime: s.startTime, endTime: s.endTime,
    allLanes: s.laneIds.length === 0 && s.poolId === null, laneIds: [...s.availableLaneIds],
    validFrom: s.validFrom ?? '', validUntil: s.validUntil ?? '',
  }
  formError.value = ''
  showForm.value = true
  setTimeout(() => document.getElementById('slot-form')?.scrollIntoView({ behavior: 'smooth' }))
}
function togglePool(p: Pool) {
  const ids = p.lanes.map((l) => l.id)
  const all = ids.every((id) => form.value.laneIds.includes(id))
  form.value.laneIds = all ? form.value.laneIds.filter((id) => !ids.includes(id)) : [...new Set([...form.value.laneIds, ...ids])]
}

async function save() {
  formError.value = ''
  const f = form.value
  if (!f.allLanes && !f.laneIds.length) {
    formError.value = 'Cochez au moins une ligne d\'eau, ou choisissez « Toutes les lignes ».'
    return
  }
  const body = {
    label: f.label, weekdays: f.weekdays, startTime: f.startTime, endTime: f.endTime, poolId: null,
    laneIds: f.allLanes ? [] : f.laneIds, validFrom: f.validFrom || null, validUntil: f.validUntil || null,
  }
  try {
    if (f.id) await put(`/slots/${f.id}`, body)
    else await post('/slots', body)
    toast.ok(f.id ? 'Créneau modifié.' : 'Créneau ajouté.')
    showForm.value = false
    await load()
  } catch (e) {
    formError.value = (e as Error).message
  }
}
async function remove(s: ClubSlot) {
  if (!confirm(`Supprimer le créneau « ${s.description} » ?\nLes séances déjà créées sont conservées.`)) return
  try {
    await del(`/slots/${s.id}`)
    toast.ok('Créneau supprimé.')
    await load()
  } catch (e) {
    toast.error(e)
  }
}
async function savePlan(s: ClubSlot) {
  const plan = plans.value[s.id] ?? {}
  try {
    await put(`/slots/${s.id}/allocations`, { allocations: s.availableLaneIds.map((laneId) => ({ laneId, sectionId: plan[laneId] || null })) })
    toast.ok('Plan d\'occupation enregistré.')
    await load()
  } catch (e) {
    toast.error(e)
  }
}
const planDirty = (s: ClubSlot) => {
  const plan = plans.value[s.id] ?? {}
  const saved = Object.fromEntries(s.allocations.map((a) => [a.laneId, a.sectionId]))
  return s.availableLaneIds.some((id) => (plan[id] || 0) !== (saved[id] || 0))
}
/** Lignes ouvertes d'un créneau regroupées par bassin (pour l'affichage). */
const lanesByPool = (s: ClubSlot) =>
  pools.value.map((p) => ({ pool: p, lanes: p.lanes.filter((l) => s.availableLaneIds.includes(l.id)) })).filter((g) => g.lanes.length)
const validity = (s: ClubSlot) =>
  s.validFrom || s.validUntil ? [s.validFrom && `du ${dateFr(s.validFrom)}`, s.validUntil && `au ${dateFr(s.validUntil)}`].filter(Boolean).join(' ') : 'Toute l\'année'
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Créneaux du club</h1>
      <p>Quand le club a la piscine, quelles lignes d'eau sont ouvertes, et à quelle section chacune est attribuée.</p>
    </div>
    <button v-if="auth.isReferent && !showForm" class="btn" @click="openNew"><AppIcon name="plus" /> Nouveau créneau</button>
  </div>

  <form v-if="showForm" id="slot-form" class="card form-card" @submit.prevent="save">
    <div class="card-head"><h2>{{ form.id ? 'Modifier le créneau' : 'Nouveau créneau' }}</h2>
      <button type="button" class="btn btn-ghost btn-icon" title="Fermer" @click="showForm = false"><AppIcon name="x" /></button></div>

    <div class="fields">
      <label class="field">Nom<input v-model="form.label" placeholder="ex. Entraînement adultes" /></label>
      <label class="field">De<input v-model="form.startTime" type="time" required /></label>
      <label class="field">À<input v-model="form.endTime" type="time" required /></label>
    </div>
    <div class="block">
      <div class="block-title">Jours</div>
      <div class="toggle-group">
        <label v-for="(d, i) in WEEKDAYS" :key="d" class="toggle" :class="{ on: form.weekdays.includes(i + 1) }"><input v-model="form.weekdays" type="checkbox" :value="i + 1" />{{ d }}</label>
      </div>
    </div>
    <div class="block">
      <div class="block-title">Lignes d'eau ouvertes au club</div>
      <div class="toggle-group">
        <label class="toggle" :class="{ on: form.allLanes }"><input v-model="form.allLanes" type="radio" :value="true" />Toutes les lignes</label>
        <label class="toggle" :class="{ on: !form.allLanes }"><input v-model="form.allLanes" type="radio" :value="false" @change="form.laneIds.length || (form.laneIds = [...allLaneIds])" />Choisir les lignes</label>
      </div>
      <div v-if="!form.allLanes" class="lane-choice">
        <div v-for="p in pools" :key="p.id" class="lane-pick">
          <button type="button" class="btn btn-ghost btn-sm pool-name" @click="togglePool(p)">{{ p.name }}</button>
          <label v-for="l in p.lanes" :key="l.id" class="toggle" :class="{ on: form.laneIds.includes(l.id) }"><input v-model="form.laneIds" type="checkbox" :value="l.id" />{{ l.name }}</label>
        </div>
        <p v-if="!pools.length" class="muted">Aucun bassin : créez-les dans <router-link to="/pools">Bassins</router-link>.</p>
      </div>
    </div>
    <div class="fields block">
      <label class="field">Valable à partir du<input v-model="form.validFrom" type="date" /></label>
      <label class="field">Jusqu'au<input v-model="form.validUntil" type="date" /></label>
    </div>
    <p class="muted" style="font-size:.85rem;margin:.4rem 0 0">Dates facultatives (ex. la saison). Vide = sans limite.</p>
    <div v-if="formError" class="alert alert-ko" style="margin-top:.75rem"><AppIcon name="alert" /> <span>{{ formError }}</span></div>
    <div class="row" style="margin-top:1rem">
      <button class="btn" :disabled="!form.weekdays.length"><AppIcon name="check" /> {{ form.id ? 'Enregistrer' : 'Ajouter' }}</button>
      <button type="button" class="btn btn-secondary" @click="showForm = false">Annuler</button>
    </div>
  </form>

  <div v-if="!slots.length && !showForm" class="card empty">
    <strong>Aucun créneau défini</strong>
    Tant qu'il n'y a pas de créneau, les séances peuvent être planifiées à toute heure.
  </div>

  <div class="stack">
    <article v-for="s in slots" :key="s.id" class="card">
      <div class="card-head">
        <div>
          <h2>{{ s.label ?? 'Créneau club' }}</h2>
          <p class="row" style="gap:.35rem;margin-top:.35rem">
            <span v-for="d in s.weekdays" :key="d" class="chip chip-primary">{{ WEEKDAYS[d - 1] }}</span>
            <strong>{{ s.startTime }}–{{ s.endTime }}</strong> · <span>{{ validity(s) }}</span>
          </p>
        </div>
        <div v-if="auth.isReferent" class="row">
          <button class="btn btn-secondary btn-sm" @click="edit(s)"><AppIcon name="edit" :size="15" /> Modifier</button>
          <button class="btn btn-danger btn-sm btn-icon" title="Supprimer" @click="remove(s)"><AppIcon name="trash" :size="15" /></button>
        </div>
      </div>

      <div class="block-title">Plan d'occupation <small>— {{ s.availableLaneIds.length }} ligne{{ s.availableLaneIds.length > 1 ? 's' : '' }} ouverte{{ s.availableLaneIds.length > 1 ? 's' : '' }}</small></div>
      <p v-if="!s.availableLaneIds.length" class="muted">Aucune ligne ouverte.</p>
      <div class="plan">
        <template v-for="g in lanesByPool(s)" :key="g.pool.id">
          <div class="plan-pool">{{ g.pool.name }}</div>
          <div v-for="l in g.lanes" :key="l.id" class="plan-row">
            <span>{{ l.name }}</span>
            <select v-if="auth.isReferent && plans[s.id]" v-model.number="plans[s.id][l.id]" :class="{ set: plans[s.id][l.id] }">
              <option :value="undefined">Libre (sur demande)</option>
              <option v-for="sec in sections" :key="sec.id" :value="sec.id">{{ sec.name }}</option>
            </select>
            <span v-else-if="s.allocations.find((a) => a.laneId === l.id)" class="chip chip-ok">{{ s.allocations.find((a) => a.laneId === l.id)!.section }}</span>
            <span v-else class="chip chip-primary">Libre</span>
          </div>
        </template>
      </div>
      <div v-if="auth.isReferent && planDirty(s)" class="row" style="margin-top:.75rem">
        <button class="btn btn-ok" @click="savePlan(s)"><AppIcon name="check" /> Enregistrer le plan</button>
        <button class="btn btn-secondary" @click="load">Annuler</button>
      </div>
    </article>
  </div>
  <p v-if="slots.length" class="muted" style="font-size:.85rem;margin-top:1rem">
    Une ligne attribuée à une section lui est réservée à chaque occurrence du créneau : ses encadrants y créent leurs séances sans validation.
    Une ligne libre reste disponible sur demande, validée par un référent.
  </p>
</template>

<style scoped>
.form-card { margin-bottom: 1rem; border-color: var(--primary-100) }
.block { margin-top: 1rem }
.block-title { font-size: .88rem; color: var(--muted); font-weight: 600; margin-bottom: .45rem }
.lane-choice { margin-top: .6rem; display: flex; flex-direction: column; gap: .35rem }
.lane-pick { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem }
.pool-name { min-width: 120px; justify-content: flex-start; color: var(--text) }
.plan { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: .35rem .9rem }
.plan-pool { grid-column: 1 / -1; font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); margin-top: .35rem }
.plan-row { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .3rem 0; border-bottom: 1px dashed var(--line) }
.plan-row select { min-width: 150px }
.plan-row select.set { border-color: #9fd5b3; background: #f3fbf6; color: var(--ok); font-weight: 600 }
</style>
