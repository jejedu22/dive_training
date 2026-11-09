<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AppIcon from '../components/AppIcon.vue'
import { del, get, post, put } from '../api'
import { useToast } from '../stores/toast'
import type { Lane, Pool } from '../types'

const toast = useToast()
const pools = ref<Pool[]>([])
const showNew = ref(false)
const form = ref({ name: '', lanes: 4 })
const editing = ref<number | null>(null)          // bassin en cours de modification
const poolName = ref('')
const newLane = ref({ name: '', maxDepth: '' as string | number })

const load = async () => {
  try { pools.value = await get<Pool[]>('/pools') } catch (e) { toast.error(e) }
  // nom proposé pour la prochaine ligne du bassin en cours de modification
  const p = pools.value.find((x) => x.id === editing.value)
  if (p && (!newLane.value.name || /^Ligne \d+$/.test(newLane.value.name))) newLane.value.name = `Ligne ${p.lanes.length + 1}`
}
onMounted(load)

async function run(fn: () => Promise<unknown>, ok?: string) {
  try {
    await fn()
    if (ok) toast.ok(ok)
  } catch (e) {
    toast.error(e)
  }
  await load()
}
const depthText = (l: Lane) => (l.maxDepth !== null ? `${String(l.maxDepth).replace('.', ',')} m` : '')

async function create() {
  await run(() => post('/pools', { name: form.value.name, lanes: Array.from({ length: form.value.lanes }, (_, i) => ({ name: `Ligne ${i + 1}` })) }),
    `Bassin « ${form.value.name} » créé.`)
  form.value = { name: '', lanes: 4 }
  showNew.value = false
}

function startEdit(p: Pool) {
  editing.value = p.id
  poolName.value = p.name
  newLane.value = { name: `Ligne ${p.lanes.length + 1}`, maxDepth: '' }
}
const renamePool = (p: Pool) => poolName.value.trim() && poolName.value !== p.name && run(() => put(`/pools/${p.id}`, { name: poolName.value }), 'Bassin renommé.')
const saveLane = (l: Lane, changes: Partial<{ name: string; maxDepth: string | number | null }>) =>
  run(() => put(`/lanes/${l.id}`, { ...changes, ...('maxDepth' in changes ? { maxDepth: changes.maxDepth === '' ? null : changes.maxDepth } : {}) }), 'Ligne modifiée.')
function move(p: Pool, index: number, dir: -1 | 1) {
  const ids = p.lanes.map((l) => l.id)
  ;[ids[index], ids[index + dir]] = [ids[index + dir], ids[index]]
  run(() => put(`/pools/${p.id}/lanes/order`, { laneIds: ids }))
}
async function addLane(p: Pool) {
  if (!newLane.value.name.trim()) return
  const body = { name: newLane.value.name, maxDepth: newLane.value.maxDepth === '' ? null : newLane.value.maxDepth }
  newLane.value = { name: '', maxDepth: '' }
  await run(() => post(`/pools/${p.id}/lanes`, body), 'Ligne ajoutée.') // load() propose ensuite le nom suivant
}
function removeLane(l: Lane) {
  if (!confirm(`Supprimer « ${l.name} » ?\nElle disparaît des créneaux et du plan d'occupation, ainsi que ses réservations passées.`)) return
  run(() => del(`/lanes/${l.id}`), 'Ligne supprimée.')
}
function removePool(p: Pool) {
  if (!confirm(`Supprimer le bassin « ${p.name} » et ses ${p.lanes.length} lignes ?\nCette action est définitive.`)) return
  editing.value = null
  run(() => del(`/pools/${p.id}`), 'Bassin supprimé.')
}
</script>

<template>
  <div class="page-head">
    <div><h1>Bassins</h1><p>Bassins et lignes d'eau de la piscine. Les lignes ouvertes au club se choisissent dans <router-link to="/slots">Créneaux</router-link>.</p></div>
    <button v-if="!showNew" class="btn" @click="showNew = true"><AppIcon name="plus" /> Nouveau bassin</button>
  </div>

  <form v-if="showNew" class="card" style="margin-bottom:1rem" @submit.prevent="create">
    <div class="card-head"><h2>Nouveau bassin</h2><button type="button" class="btn btn-ghost btn-icon" title="Fermer" @click="showNew = false"><AppIcon name="x" /></button></div>
    <div class="fields">
      <label class="field">Nom<input v-model="form.name" placeholder="Fosse, Grand bassin…" required /></label>
      <label class="field">Nombre de lignes d'eau<input v-model.number="form.lanes" type="number" min="1" max="20" /></label>
    </div>
    <p class="muted small">Les lignes pourront être renommées, réordonnées et complétées ensuite.</p>
    <div class="row"><button class="btn"><AppIcon name="check" /> Créer</button></div>
  </form>

  <div class="stack">
    <article v-for="p in pools" :key="p.id" class="card" :class="{ editing: editing === p.id }">
      <!-- Lecture -->
      <template v-if="editing !== p.id">
        <div class="card-head">
          <h2><AppIcon name="pool" :size="17" /> {{ p.name }} <span class="chip">{{ p.lanes.length }} ligne{{ p.lanes.length > 1 ? 's' : '' }}</span></h2>
          <button class="btn btn-secondary btn-sm" @click="startEdit(p)"><AppIcon name="edit" :size="15" /> Modifier</button>
        </div>
        <div class="row">
          <span v-for="l in p.lanes" :key="l.id" class="chip chip-primary">{{ l.name }}<small v-if="l.maxDepth !== null"> · {{ depthText(l) }}</small></span>
          <span v-if="!p.lanes.length" class="muted">Aucune ligne.</span>
        </div>
      </template>

      <!-- Modification -->
      <template v-else>
        <div class="card-head">
          <label class="field grow">Nom du bassin
            <input v-model="poolName" required @change="renamePool(p)" @keydown.enter.prevent="($event.target as HTMLInputElement).blur()" />
          </label>
          <button class="btn btn-ok" @click="editing = null"><AppIcon name="check" /> Terminer</button>
        </div>

        <div class="lanes">
          <div class="lane head"><span /><span>Ligne</span><span>Profondeur max.</span><span /></div>
          <div v-for="(l, i) in p.lanes" :key="l.id" class="lane">
            <span class="order">
              <button class="btn btn-ghost btn-icon btn-sm" title="Monter" :disabled="i === 0" @click="move(p, i, -1)"><AppIcon name="up" :size="16" /></button>
              <button class="btn btn-ghost btn-icon btn-sm" title="Descendre" :disabled="i === p.lanes.length - 1" @click="move(p, i, 1)"><AppIcon name="down" :size="16" /></button>
            </span>
            <input :value="l.name" aria-label="Nom de la ligne" maxlength="60"
              @change="saveLane(l, { name: ($event.target as HTMLInputElement).value })" @keydown.enter.prevent="($event.target as HTMLInputElement).blur()" />
            <span class="depth">
              <input :value="l.maxDepth ?? ''" type="number" min="0" max="100" step="0.1" inputmode="decimal" placeholder="—" aria-label="Profondeur en mètres"
                @change="saveLane(l, { maxDepth: ($event.target as HTMLInputElement).value })" /> m
            </span>
            <button class="btn btn-danger btn-icon btn-sm" :title="`Supprimer ${l.name}`" @click="removeLane(l)"><AppIcon name="trash" :size="15" /></button>
          </div>
          <form class="lane add" @submit.prevent="addLane(p)">
            <span />
            <input v-model="newLane.name" placeholder="Nouvelle ligne" maxlength="60" aria-label="Nom de la nouvelle ligne" />
            <span class="depth"><input v-model="newLane.maxDepth" type="number" min="0" max="100" step="0.1" inputmode="decimal" placeholder="—" aria-label="Profondeur" /> m</span>
            <button class="btn btn-secondary btn-icon btn-sm" title="Ajouter la ligne" :disabled="!newLane.name.trim()"><AppIcon name="plus" :size="16" /></button>
          </form>
        </div>
        <p class="muted small">Chaque modification est enregistrée immédiatement. Une ligne qui a des réservations à venir ne peut pas être supprimée.</p>
        <div class="row" style="justify-content:flex-end"><button class="btn btn-danger btn-sm" @click="removePool(p)"><AppIcon name="trash" :size="15" /> Supprimer le bassin</button></div>
      </template>
    </article>
  </div>
  <div v-if="!pools.length && !showNew" class="card empty"><strong>Aucun bassin</strong>Commencez par créer la fosse et ses lignes d'eau.</div>
</template>

<style scoped>
.card-head h2 { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap }
.card.editing { border-color: var(--primary-100); box-shadow: 0 0 0 2px var(--primary-50) }
.grow { flex: 1; max-width: 420px }
.small { font-size: .85rem }
.lanes { display: flex; flex-direction: column; gap: .35rem; margin: .5rem 0 .75rem }
.lane { display: grid; grid-template-columns: 76px minmax(0, 1fr) 150px 40px; gap: .5rem; align-items: center }
.lane.head span { font-size: .78rem; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: .03em }
.lane.add { padding-top: .35rem; border-top: 1px dashed var(--line); margin-top: .2rem }
.lane input { width: 100% }
.order { display: flex; gap: 2px }
.depth { display: flex; align-items: center; gap: .3rem; color: var(--muted) }
.depth input { width: 90px }
@media (max-width: 760px) {
  .lane { grid-template-columns: 64px minmax(0, 1fr) 40px; }
  .lane .depth { grid-column: 2; }
  .lane.head { display: none }
}
</style>
