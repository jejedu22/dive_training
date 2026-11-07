<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { del, get, post } from '../api'
import type { Diver, Section } from '../types'

const sections = ref<Section[]>([])
const divers = ref<Diver[]>([])
const newSection = ref({ name: '', targetLevel: '', season: '' })
const newDiver = ref({ firstName: '', lastName: '', level: '' })
const pick = ref<Record<number, number>>({})

async function load() {
  ;[sections.value, divers.value] = await Promise.all([get<Section[]>('/sections'), get<Diver[]>('/divers')])
}
onMounted(load)

async function addSection() {
  await post('/sections', newSection.value)
  newSection.value = { name: '', targetLevel: '', season: '' }
  await load()
}
async function addDiver() {
  await post('/divers', newDiver.value)
  newDiver.value = { firstName: '', lastName: '', level: '' }
  await load()
}
async function attach(s: Section) {
  if (!pick.value[s.id]) return
  await post(`/sections/${s.id}/divers`, { diverId: pick.value[s.id] })
  await load()
}
async function detach(s: Section, d: Diver) {
  await del(`/sections/${s.id}/divers/${d.id}`)
  await load()
}
</script>

<template>
  <div class="row" style="align-items:flex-start">
    <form class="card" style="flex:1" @submit.prevent="addSection">
      <h3>Nouvelle section</h3>
      <div class="row">
        <input v-model="newSection.name" placeholder="Nom" required />
        <input v-model="newSection.targetLevel" placeholder="Niveau visé (N1…)" />
        <input v-model="newSection.season" placeholder="Saison (2026-2027)" />
        <button>Créer</button>
      </div>
    </form>
    <form class="card" style="flex:1" @submit.prevent="addDiver">
      <h3>Nouvel élève</h3>
      <div class="row">
        <input v-model="newDiver.firstName" placeholder="Prénom" required />
        <input v-model="newDiver.lastName" placeholder="Nom" required />
        <input v-model="newDiver.level" placeholder="Niveau" />
        <button>Ajouter</button>
      </div>
    </form>
  </div>

  <div v-for="s in sections" :key="s.id" class="card">
    <h3>{{ s.name }} <small>{{ s.targetLevel }} {{ s.season }}</small></h3>
    <p>Encadrants : {{ s.instructors.map((i) => i.name).join(', ') || '—' }}</p>
    <table>
      <tr v-for="d in s.divers" :key="d.id">
        <td>{{ d.lastName }} {{ d.firstName }}</td><td>{{ d.level }}</td>
        <td><button class="secondary" @click="detach(s, d)">Retirer</button></td>
      </tr>
    </table>
    <div class="row" style="margin-top:.5rem">
      <select v-model.number="pick[s.id]">
        <option :value="undefined">Ajouter un élève…</option>
        <option v-for="d in divers.filter((x) => !s.divers.some((y) => y.id === x.id))" :key="d.id" :value="d.id">{{ d.lastName }} {{ d.firstName }}</option>
      </select>
      <button @click="attach(s)">Ajouter</button>
    </div>
  </div>
</template>
