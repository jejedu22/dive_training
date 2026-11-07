<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { get, post } from '../api'
import type { Pool } from '../types'

const pools = ref<Pool[]>([])
const form = ref({ name: '', lanes: 4 })
const laneName = ref<Record<number, string>>({})
const load = async () => (pools.value = await get<Pool[]>('/pools'))
onMounted(load)

async function create() {
  await post('/pools', { name: form.value.name, lanes: Array.from({ length: form.value.lanes }, (_, i) => ({ name: `Ligne ${i + 1}` })) })
  form.value = { name: '', lanes: 4 }
  await load()
}
async function addLane(p: Pool) {
  if (!laneName.value[p.id]) return
  await post(`/pools/${p.id}/lanes`, { name: laneName.value[p.id] })
  laneName.value[p.id] = ''
  await load()
}
</script>

<template>
  <form class="card" @submit.prevent="create">
    <h3>Nouveau bassin</h3>
    <div class="row">
      <input v-model="form.name" placeholder="Nom (Fosse, Grand bassin…)" required />
      <label>Lignes d'eau <input v-model.number="form.lanes" type="number" min="1" max="20" style="width:4rem" /></label>
      <button>Créer</button>
    </div>
  </form>
  <div v-for="p in pools" :key="p.id" class="card">
    <h3>{{ p.name }}</h3>
    <p>{{ p.lanes.map((l) => l.name).join(' · ') }}</p>
    <div class="row"><input v-model="laneName[p.id]" placeholder="Nouvelle ligne" /><button @click="addLane(p)">Ajouter une ligne</button></div>
  </div>
</template>
