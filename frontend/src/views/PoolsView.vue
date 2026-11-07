<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AppIcon from '../components/AppIcon.vue'
import { get, post } from '../api'
import { useToast } from '../stores/toast'
import type { Pool } from '../types'

const toast = useToast()
const pools = ref<Pool[]>([])
const form = ref({ name: '', lanes: 4 })
const laneName = ref<Record<number, string>>({})
const load = async () => {
  try { pools.value = await get<Pool[]>('/pools') } catch (e) { toast.error(e) }
}
onMounted(load)

async function create() {
  try {
    await post('/pools', { name: form.value.name, lanes: Array.from({ length: form.value.lanes }, (_, i) => ({ name: `Ligne ${i + 1}` })) })
    toast.ok(`Bassin « ${form.value.name} » créé.`)
    form.value = { name: '', lanes: 4 }
    await load()
  } catch (e) { toast.error(e) }
}
async function addLane(p: Pool) {
  if (!laneName.value[p.id]) return
  try {
    await post(`/pools/${p.id}/lanes`, { name: laneName.value[p.id] })
    laneName.value[p.id] = ''
    toast.ok('Ligne ajoutée.')
    await load()
  } catch (e) { toast.error(e) }
}
</script>

<template>
  <div class="page-head"><div><h1>Bassins</h1><p>Bassins et lignes d'eau de la piscine. Les lignes ouvertes au club se choisissent dans <router-link to="/slots">Créneaux</router-link>.</p></div></div>

  <form class="card" style="margin-bottom:1rem" @submit.prevent="create">
    <div class="card-head"><h2>Nouveau bassin</h2></div>
    <div class="fields">
      <label class="field">Nom<input v-model="form.name" placeholder="Fosse, Grand bassin…" required /></label>
      <label class="field">Nombre de lignes d'eau<input v-model.number="form.lanes" type="number" min="1" max="20" /></label>
    </div>
    <div class="row" style="margin-top:1rem"><button class="btn"><AppIcon name="plus" /> Créer</button></div>
  </form>

  <div class="grid-2">
    <article v-for="p in pools" :key="p.id" class="card">
      <div class="card-head"><h2><AppIcon name="pool" :size="17" /> {{ p.name }}</h2><span class="chip">{{ p.lanes.length }} lignes</span></div>
      <div class="row"><span v-for="l in p.lanes" :key="l.id" class="chip chip-primary">{{ l.name }}</span></div>
      <form class="row" style="margin-top:.75rem" @submit.prevent="addLane(p)">
        <input v-model="laneName[p.id]" placeholder="Nouvelle ligne" />
        <button class="btn btn-secondary btn-sm" :disabled="!laneName[p.id]"><AppIcon name="plus" :size="15" /> Ajouter</button>
      </form>
    </article>
  </div>
  <div v-if="!pools.length" class="card empty"><strong>Aucun bassin</strong>Commencez par créer la fosse et ses lignes d'eau.</div>
</template>

<style scoped>
.card-head h2 { display: flex; align-items: center; gap: .4rem }
</style>
