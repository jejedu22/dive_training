<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { del, get, post, put } from '../api'
import { useAuth } from '../stores/auth'
import type { ClubSlot, Pool } from '../types'

const DAYS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim']
const auth = useAuth()
const slots = ref<ClubSlot[]>([])
const pools = ref<Pool[]>([])
const error = ref(''), msg = ref('')

const empty = () => ({ id: 0, label: '', weekdays: [] as number[], startTime: '20:00', endTime: '22:00', poolId: null as number | null, validFrom: '', validUntil: '' })
const form = ref(empty())
const editing = computed(() => form.value.id !== 0)

async function load() {
  ;[slots.value, pools.value] = await Promise.all([get<ClubSlot[]>('/slots'), get<Pool[]>('/pools')])
}
onMounted(load)

function edit(s: ClubSlot) {
  form.value = { id: s.id, label: s.label ?? '', weekdays: [...s.weekdays], startTime: s.startTime, endTime: s.endTime, poolId: s.poolId, validFrom: s.validFrom ?? '', validUntil: s.validUntil ?? '' }
  msg.value = error.value = ''
}

async function save() {
  error.value = msg.value = ''
  const { id, ...body } = form.value
  try {
    if (id) await put(`/slots/${id}`, { ...body, validFrom: body.validFrom || null, validUntil: body.validUntil || null })
    else await post('/slots', { ...body, validFrom: body.validFrom || null, validUntil: body.validUntil || null })
    msg.value = id ? 'Créneau modifié.' : 'Créneau ajouté.'
    form.value = empty()
    await load()
  } catch (e) {
    error.value = (e as Error).message
  }
}

async function remove(s: ClubSlot) {
  if (!confirm(`Supprimer le créneau « ${s.description} » ? Les séances existantes ne sont pas supprimées.`)) return
  await del(`/slots/${s.id}`)
  await load()
}
const fmtDate = (d: string | null) => (d ? new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR') : '')
</script>

<template>
  <h2>Créneaux du club</h2>
  <p>
    Heures pendant lesquelles le club dispose de la piscine. Les séances doivent s'y inscrire
    <template v-if="auth.isReferent"> (un référent peut forcer une séance hors créneau)</template>.
    Sans aucun créneau défini, les séances sont libres.
  </p>

  <div class="card">
    <p v-if="!slots.length">Aucun créneau défini.</p>
    <table v-else>
      <tr><th>Créneau</th><th>Jours</th><th>Horaire</th><th>Bassin</th><th>Validité</th><th v-if="auth.isReferent" /></tr>
      <tr v-for="s in slots" :key="s.id">
        <td>{{ s.label ?? '—' }}</td>
        <td>{{ s.weekdays.map((d) => DAYS[d - 1]).join(', ') }}</td>
        <td>{{ s.startTime }} – {{ s.endTime }}</td>
        <td>{{ s.pool ?? 'Tous' }}</td>
        <td>
          <template v-if="s.validFrom || s.validUntil">{{ s.validFrom ? `du ${fmtDate(s.validFrom)}` : '' }} {{ s.validUntil ? `au ${fmtDate(s.validUntil)}` : '' }}</template>
          <template v-else>toute l'année</template>
        </td>
        <td v-if="auth.isReferent" class="row">
          <button class="secondary" @click="edit(s)">Modifier</button>
          <button class="danger" @click="remove(s)">Supprimer</button>
        </td>
      </tr>
    </table>
  </div>

  <form v-if="auth.isReferent" class="card" @submit.prevent="save">
    <h3>{{ editing ? 'Modifier le créneau' : 'Nouveau créneau' }}</h3>
    <div class="row">
      <input v-model="form.label" placeholder="Nom (ex. Entraînement)" />
      <span class="days">
        <label v-for="(d, i) in DAYS" :key="d" :class="{ on: form.weekdays.includes(i + 1) }">
          <input v-model="form.weekdays" type="checkbox" :value="i + 1" />{{ d }}
        </label>
      </span>
    </div>
    <div class="row" style="margin-top:.5rem">
      <label>De <input v-model="form.startTime" type="time" required /></label>
      <label>à <input v-model="form.endTime" type="time" required /></label>
      <select v-model="form.poolId">
        <option :value="null">Tous les bassins</option>
        <option v-for="p in pools" :key="p.id" :value="p.id">{{ p.name }}</option>
      </select>
    </div>
    <div class="row" style="margin-top:.5rem">
      <label>Valable du <input v-model="form.validFrom" type="date" /></label>
      <label>au <input v-model="form.validUntil" type="date" /></label>
      <small>(laisser vide = sans limite, ex. toute la saison)</small>
    </div>
    <div class="row" style="margin-top:.8rem">
      <button :disabled="!form.weekdays.length">{{ editing ? 'Enregistrer' : 'Ajouter' }}</button>
      <button v-if="editing" type="button" class="secondary" @click="form = empty()">Annuler</button>
    </div>
    <p v-if="msg" style="color:var(--ok)">{{ msg }}</p>
    <p v-if="error" class="error">{{ error }}</p>
  </form>
</template>

<style scoped>
.days { display:inline-flex; gap:.25rem; flex-wrap:wrap }
.days label { border:1px solid var(--line); border-radius:4px; padding:.3rem .5rem; cursor:pointer; user-select:none; background:#fff }
.days label.on { background:var(--blue); color:#fff; border-color:var(--blue) }
.days input { display:none }
</style>
