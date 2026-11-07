<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { del, get, post, put } from '../api'
import { useAuth } from '../stores/auth'
import type { DiveSession, Reservation } from '../types'

const props = defineProps<{ id: string }>()
const auth = useAuth(), router = useRouter()
const session = ref<DiveSession | null>(null)
const error = ref('')

const load = async () => (session.value = await get<DiveSession>(`/sessions/${props.id}`))
onMounted(load)

async function toggleTank(diverId: number, usesTank: boolean) {
  session.value = await put<DiveSession>(`/sessions/${props.id}/participants/${diverId}`, { usesTank })
}
async function decide(r: Reservation, action: 'confirm' | 'reject') {
  error.value = ''
  try {
    await post(`/reservations/${r.id}/${action}`)
    await load()
  } catch (e) {
    error.value = (e as Error).message
  }
}
async function remove() {
  if (!confirm('Supprimer cette séance et ses réservations ?')) return
  await del(`/sessions/${props.id}`)
  router.push('/planning')
}
const fmt = (s: string) => new Date(s).toLocaleString('fr-FR', { dateStyle: 'full', timeStyle: 'short' })
</script>

<template>
  <div v-if="session">
    <h2>{{ session.title }} <small>— {{ session.section }}</small></h2>
    <p>{{ fmt(session.start) }} → {{ new Date(session.end).toLocaleTimeString('fr-FR', { timeStyle: 'short' }) }}</p>
    <p v-if="error" class="error">{{ error }}</p>

    <div class="card">
      <h3>Lignes d'eau</h3>
      <table>
        <tr v-for="r in session.reservations" :key="r.id">
          <td>{{ r.pool }} / {{ r.lane.name }}</td>
          <td><span class="badge" :class="r.status">{{ r.status }}</span></td>
          <td v-if="auth.isReferent && r.status === 'PENDING'" class="row">
            <button class="ok" @click="decide(r, 'confirm')">Valider</button><button class="danger" @click="decide(r, 'reject')">Refuser</button>
          </td>
          <td v-else-if="r.validatedBy">par {{ r.validatedBy }}</td>
        </tr>
      </table>
    </div>

    <div class="card">
      <h3>Élèves — {{ session.tankCount }} avec bouteille / {{ session.participants?.length }}</h3>
      <table>
        <tr><th>Élève</th><th>Niveau</th><th>Bouteille</th></tr>
        <tr v-for="p in session.participants" :key="p.diver.id">
          <td>{{ p.diver.lastName }} {{ p.diver.firstName }}</td><td>{{ p.diver.level }}</td>
          <td><input type="checkbox" :checked="p.usesTank" @change="toggleTank(p.diver.id, ($event.target as HTMLInputElement).checked)" /></td>
        </tr>
      </table>
    </div>
    <button class="danger" @click="remove">Supprimer la séance</button>
  </div>
</template>
