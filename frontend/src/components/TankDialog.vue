<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import AppDialog from './AppDialog.vue'
import AppIcon from './AppIcon.vue'
import { get, put } from '../api'
import { useToast } from '../stores/toast'
import { dayLong } from '../format'
import { chipStyle } from '../colors'
import type { PresenceList } from '../types'

/** Qui fait bouteille, pour une section, un créneau, une date. */
const props = defineProps<{ slotId: number; date: string; sectionId: number; canEdit: boolean; time: string }>()
const open = defineModel<boolean>({ required: true })
const emit = defineEmits<{ changed: [] }>()
const toast = useToast()
const list = ref<PresenceList | null>(null)

watch(open, async (o) => {
  if (!o) return
  list.value = null
  try {
    list.value = await get<PresenceList>(`/presences?slotId=${props.slotId}&date=${props.date}&sectionId=${props.sectionId}`)
  } catch (e) {
    toast.error(e)
  }
}, { immediate: true })

async function toggle(diverId: number, usesTank: boolean) {
  try {
    list.value = await put<PresenceList>('/presences', { slotId: props.slotId, date: props.date, sectionId: props.sectionId, diverId, usesTank })
    emit('changed')
  } catch (e) {
    toast.error(e)
  }
}
async function all(value: boolean) {
  for (const p of list.value?.divers ?? []) if (p.usesTank !== value) await toggle(p.diver.id, value)
}
const title = computed(() => `Bouteilles — ${dayLong(`${props.date}T12:00:00`)} ${props.time}`)
</script>

<template>
  <AppDialog v-model="open" :title="title">
    <p v-if="!list" class="muted">Chargement…</p>
    <template v-else>
      <div class="row head">
        <span class="chip" :style="chipStyle(list.section.color)">{{ list.section.name }}</span>
        <strong class="count">{{ list.tankCount }}</strong> / {{ list.divers.length }} avec bouteille
        <span class="spacer" />
        <template v-if="canEdit && list.divers.length">
          <button class="btn btn-ghost btn-sm" @click="all(true)">Tous</button>
          <button class="btn btn-ghost btn-sm" @click="all(false)">Aucun</button>
        </template>
      </div>
      <p v-if="!list.divers.length" class="muted">Aucun élève dans la section. Ajoutez-les dans <router-link to="/sections" @click="open = false">Sections</router-link>.</p>
      <label v-for="p in list.divers" :key="p.diver.id" class="tank" :class="{ on: p.usesTank, ro: !canEdit }">
        <input type="checkbox" :checked="p.usesTank" :disabled="!canEdit" @change="toggle(p.diver.id, ($event.target as HTMLInputElement).checked)" />
        <AppIcon name="tank" :size="18" />
        <span><strong>{{ p.diver.lastName }}</strong> {{ p.diver.firstName }} <small v-if="p.diver.level" class="muted">· {{ p.diver.level }}</small></span>
      </label>
      <p v-if="!canEdit" class="muted small">Seuls les encadrants de la section et les référents peuvent modifier.</p>
    </template>
    <template #actions><button class="btn" @click="open = false">Fermer</button></template>
  </AppDialog>
</template>

<style scoped>
.head { margin-bottom: .5rem }
.count { font-size: 1.2rem; color: var(--primary) }
.tank { display: flex; align-items: center; gap: .6rem; padding: .5rem .55rem; border-radius: 8px; cursor: pointer; color: var(--muted) }
.tank:hover { background: var(--bg) }
.tank.on { color: var(--primary); background: var(--primary-50) }
.tank.on span { color: var(--text) }
.tank.ro { cursor: default }
.tank input { width: 20px; height: 20px; min-height: auto; accent-color: var(--primary) }
.small { font-size: .85rem }
</style>
