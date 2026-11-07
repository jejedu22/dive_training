<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import { del, get, post, put } from '../api'
import { useAuth } from '../stores/auth'
import { useToast } from '../stores/toast'
import { dayLong, time } from '../format'
import type { DiveSession, Reservation } from '../types'

const props = defineProps<{ id: string }>()
const auth = useAuth(), toast = useToast(), router = useRouter()
const session = ref<DiveSession | null>(null)

const load = async () => {
  try {
    session.value = await get<DiveSession>(`/sessions/${props.id}`)
  } catch (e) {
    toast.error(e)
  }
}
onMounted(load)

async function toggleTank(diverId: number, usesTank: boolean) {
  try {
    session.value = await put<DiveSession>(`/sessions/${props.id}/participants/${diverId}`, { usesTank })
  } catch (e) {
    toast.error(e)
  }
}
async function allTanks(value: boolean) {
  for (const p of session.value?.participants ?? []) if (p.usesTank !== value) await toggleTank(p.diver.id, value)
}
async function decide(r: Reservation, action: 'confirm' | 'reject') {
  try {
    await post(`/reservations/${r.id}/${action}`)
    toast.ok(action === 'confirm' ? 'Réservation validée.' : 'Réservation refusée.')
    await load()
  } catch (e) {
    toast.error(e)
  }
}
async function remove() {
  if (!confirm('Supprimer cette séance et ses réservations ?')) return
  try {
    await del(`/sessions/${props.id}`)
    toast.ok('Séance supprimée.')
    router.push('/planning')
  } catch (e) {
    toast.error(e)
  }
}
const statusLabel = (r: Reservation) => (r.status === 'CONFIRMED' ? (r.planned ? 'Attribuée (plan)' : 'Confirmée') : r.status === 'PENDING' ? 'En attente' : 'Refusée')
const total = computed(() => session.value?.participants?.length ?? 0)
const date = computed(() => session.value?.start.slice(0, 10))
</script>

<template>
  <div v-if="session">
    <div class="page-head">
      <div>
        <router-link :to="{ path: '/planning', query: { date } }" class="back"><AppIcon name="left" :size="16" /> Planning</router-link>
        <h1>{{ session.title }}</h1>
        <p class="cap">{{ session.section }} · {{ dayLong(session.start) }} · {{ time(session.start) }}–{{ time(session.end) }}</p>
      </div>
      <button class="btn btn-danger" @click="remove"><AppIcon name="trash" :size="16" /> Supprimer</button>
    </div>

    <div class="grid-2">
      <section class="card">
        <div class="card-head"><h2>Lignes d'eau</h2></div>
        <div v-for="r in session.reservations" :key="r.id" class="res">
          <div><strong>{{ r.pool }} · {{ r.lane.name }}</strong><br /><small v-if="r.validatedBy">par {{ r.validatedBy }}</small></div>
          <div class="row">
            <span class="chip" :class="r.status">{{ statusLabel(r) }}</span>
            <template v-if="auth.isReferent && r.status === 'PENDING'">
              <button class="btn btn-ok btn-sm" @click="decide(r, 'confirm')"><AppIcon name="check" :size="15" /></button>
              <button class="btn btn-danger btn-sm" @click="decide(r, 'reject')"><AppIcon name="x" :size="15" /></button>
            </template>
          </div>
        </div>
      </section>

      <section class="card">
        <div class="card-head">
          <div><h2>Bouteilles</h2><p><strong class="big">{{ session.tankCount }}</strong> / {{ total }} élève{{ total > 1 ? 's' : '' }} avec bouteille</p></div>
          <div v-if="total" class="row">
            <button class="btn btn-secondary btn-sm" @click="allTanks(true)">Tous</button>
            <button class="btn btn-ghost btn-sm" @click="allTanks(false)">Aucun</button>
          </div>
        </div>
        <p v-if="!total" class="muted">Aucun élève dans la section.</p>
        <label v-for="p in session.participants" :key="p.diver.id" class="tank" :class="{ on: p.usesTank }">
          <input type="checkbox" :checked="p.usesTank" @change="toggleTank(p.diver.id, ($event.target as HTMLInputElement).checked)" />
          <AppIcon name="tank" :size="18" />
          <span><strong>{{ p.diver.lastName }}</strong> {{ p.diver.firstName }} <small v-if="p.diver.level">· {{ p.diver.level }}</small></span>
        </label>
      </section>
    </div>
  </div>
</template>

<style scoped>
.back { display: inline-flex; align-items: center; gap: .2rem; font-size: .88rem; text-decoration: none; margin-bottom: .3rem }
.cap::first-letter { text-transform: uppercase }
.res { display: flex; justify-content: space-between; align-items: center; gap: .5rem; padding: .55rem 0; border-bottom: 1px solid var(--line) }
.res:last-child { border-bottom: 0 }
.big { font-size: 1.3rem; color: var(--primary) }
.tank { display: flex; align-items: center; gap: .6rem; padding: .55rem .6rem; border-radius: 8px; cursor: pointer; color: var(--muted) }
.tank:hover { background: var(--bg) }
.tank.on { color: var(--primary); background: var(--primary-50) }
.tank.on span { color: var(--text) }
.tank input { width: 20px; height: 20px; min-height: auto; accent-color: var(--primary) }
</style>
