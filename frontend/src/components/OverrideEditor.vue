<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import AppDialog from './AppDialog.vue'
import AppIcon from './AppIcon.vue'
import { post, put } from '../api'
import { useToast } from '../stores/toast'
import type { ClubSlot, Override, Pool, Section } from '../types'
import LaneSectionsPicker from './LaneSectionsPicker.vue'

/**
 * Création / modification d'une exception au plan d'occupation d'un créneau.
 * Pour chaque ligne ouverte : « inchangée » (plan habituel), « libre », ou une section.
 */
const props = defineProps<{ slot: ClubSlot; pools: Pool[]; sections: Section[]; override: Override | null }>()
const open = defineModel<boolean>({ required: true })
const emit = defineEmits<{ saved: [] }>()
const toast = useToast()

const DAYS = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche']
const NTH = [{ v: 1, l: '1er' }, { v: 2, l: '2e' }, { v: 3, l: '3e' }, { v: 4, l: '4e' }, { v: -1, l: 'dernier' }]
const UNCHANGED = 'same', FREE = 'free'

const form = ref({ label: '', kind: 'monthly' as 'monthly' | 'date', nth: 1, weekday: 1, date: '', validFrom: '', validUntil: '', cancelled: false })
const choice = ref<Record<number, number[] | 'same'>>({}) // laneId -> 'same' (inchangée) ou sections (vide = libérée)
const error = ref('')
const saving = ref(false)

// immediate : le composant peut être créé alors que la fenêtre est déjà ouverte
watch(open, (o) => {
  if (!o) return
  const x = props.override
  error.value = ''
  form.value = x
    ? { label: x.label ?? '', kind: x.kind, nth: x.nth ?? 1, weekday: x.weekday ?? props.slot.weekdays[0], date: x.date ?? '', validFrom: x.validFrom ?? '', validUntil: x.validUntil ?? '', cancelled: x.cancelled }
    : { label: '', kind: 'monthly', nth: 1, weekday: props.slot.weekdays[0], date: '', validFrom: '', validUntil: '', cancelled: false }
  choice.value = Object.fromEntries(props.slot.availableLaneIds.map((id) => {
    const rows = x?.allocations.filter((y) => y.laneId === id) ?? []
    return [id, rows.length ? rows.map((r) => r.sectionId).filter((v): v is number => v !== null) : UNCHANGED]
  }))
}, { immediate: true })

/** Plan habituel d'une ligne, pour l'option « Inchangée (…) ». */
const baseLabel = (laneId: number) => {
  const names = props.slot.allocations.filter((a) => a.laneId === laneId).map((a) => a.section)
  return `Inchangée (${names.length ? names.join(' + ') : 'libre'})`
}
const lanesByPool = computed(() =>
  props.pools.map((p) => ({ pool: p, lanes: p.lanes.filter((l) => props.slot.availableLaneIds.includes(l.id)) })).filter((g) => g.lanes.length))
/** « Tout le bassin → » : applique un choix à toutes les lignes d'un bassin. */
function setPool(p: Pool, v: string) {
  if (!v) return
  for (const l of p.lanes) {
    if (props.slot.availableLaneIds.includes(l.id)) choice.value[l.id] = v === UNCHANGED ? UNCHANGED : v === FREE ? [] : [Number(v)]
  }
}
const changes = computed(() => Object.entries(choice.value).filter(([, v]) => v !== UNCHANGED) as [string, number[]][])
const slotDays = computed(() => props.slot.weekdays.map((d) => ({ v: d, l: DAYS[d - 1] })))

async function save() {
  error.value = ''
  saving.value = true
  const f = form.value
  const body = {
    label: f.label || null, kind: f.kind, cancelled: f.cancelled,
    ...(f.kind === 'date' ? { date: f.date } : { nth: f.nth, weekday: f.weekday, validFrom: f.validFrom || null, validUntil: f.validUntil || null }),
    allocations: f.cancelled ? [] : changes.value.map(([laneId, ids]) => ({ laneId: Number(laneId), sectionIds: ids })),
  }
  try {
    const r = props.override
      ? await put<Override>(`/overrides/${props.override.id}`, body)
      : await post<Override>(`/slots/${props.slot.id}/overrides`, body)
    toast.ok(`Exception enregistrée (${r.rule}).`)
    open.value = false
    emit('saved')
  } catch (e) {
    error.value = (e as Error).message
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <AppDialog v-model="open" :title="override ? 'Modifier l\'exception' : `Exception — ${slot.label ?? 'créneau'} ${slot.startTime}–${slot.endTime}`">
    <form id="override-form" class="stack" style="gap:.85rem" @submit.prevent="save">
      <label class="field">Nom <small>(facultatif)</small><input v-model="form.label" placeholder="ex. Apnée en fosse, Piscine fermée" maxlength="120" /></label>

      <div class="field">Quand
        <div class="toggle-group">
          <label class="toggle" :class="{ on: form.kind === 'monthly' }"><input v-model="form.kind" type="radio" value="monthly" />Chaque mois</label>
          <label class="toggle" :class="{ on: form.kind === 'date' }"><input v-model="form.kind" type="radio" value="date" />Une date</label>
        </div>
      </div>
      <div v-if="form.kind === 'monthly'" class="row">
        <span>Le</span>
        <select v-model.number="form.nth" aria-label="Rang"><option v-for="n in NTH" :key="n.v" :value="n.v">{{ n.l }}</option></select>
        <select v-model.number="form.weekday" aria-label="Jour"><option v-for="d in slotDays" :key="d.v" :value="d.v">{{ d.l }}</option></select>
        <span>de chaque mois</span>
      </div>
      <div v-if="form.kind === 'monthly'" class="fields">
        <label class="field">À partir du <small>(facultatif)</small><input v-model="form.validFrom" type="date" /></label>
        <label class="field">Jusqu'au <small>(facultatif)</small><input v-model="form.validUntil" type="date" /></label>
      </div>
      <label v-else class="field">Date<input v-model="form.date" type="date" required /></label>

      <label class="check"><input v-model="form.cancelled" type="checkbox" /> Créneau annulé ce jour-là (piscine fermée, compétition…)</label>

      <div v-if="!form.cancelled">
        <div class="field" style="margin-bottom:.4rem">Lignes d'eau ce jour-là</div>
        <div v-for="g in lanesByPool" :key="g.pool.id" class="pool">
          <div class="pool-head">
            <strong>{{ g.pool.name }}</strong>
            <select class="all" aria-label="Tout le bassin" @change="setPool(g.pool, ($event.target as HTMLSelectElement).value); ($event.target as HTMLSelectElement).value = ''">
              <option value="">Tout le bassin →</option>
              <option :value="UNCHANGED">plan habituel</option><option :value="FREE">libre</option>
              <option v-for="s in sections" :key="s.id" :value="String(s.id)">{{ s.name }}</option>
            </select>
          </div>
          <div v-for="l in g.lanes" :key="l.id" class="lane">
            <span>{{ l.name }}</span>
            <LaneSectionsPicker v-model="choice[l.id]" :sections="sections" :same-label="baseLabel(l.id)" />
          </div>
        </div>
      </div>
      <p class="muted small"><AppIcon name="info" :size="14" /> Pour un changement ponctuel sur une seule date, vous pouvez aussi cliquer directement sur une case du planning.</p>
    </form>
    <div v-if="error" class="alert alert-ko" style="margin-top:.75rem"><AppIcon name="alert" /> <span>{{ error }}</span></div>
    <template #actions>
      <button type="button" class="btn btn-ghost" @click="open = false">Annuler</button>
      <button class="btn" form="override-form" :disabled="saving || (!form.cancelled && !changes.length)"><AppIcon name="check" /> Enregistrer</button>
    </template>
  </AppDialog>
</template>

<style scoped>
.pool { margin-bottom: .6rem }
.pool-head { display: flex; justify-content: space-between; align-items: center; gap: .5rem; margin-bottom: .3rem }
.pool-head .all { font-size: .85rem; min-height: 32px; padding: .2rem .4rem }
.lane { display: flex; justify-content: space-between; align-items: center; gap: .5rem; padding: .2rem 0 }

.small { font-size: .85rem }
</style>
