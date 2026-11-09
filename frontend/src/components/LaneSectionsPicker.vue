<script setup lang="ts">
import { computed } from 'vue'
import { tint } from '../colors'
import type { Section } from '../types'

/**
 * Sections d'une ligne d'eau : une section, puis éventuellement une seconde qui partage la ligne.
 * v-model : liste d'identifiants (vide = libre), ou « same » si sameLabel est fourni (inchangée).
 */
const props = defineProps<{ sections: Section[]; sameLabel?: string }>()
const model = defineModel<number[] | 'same'>({ required: true })

const ids = computed(() => (model.value === 'same' ? [] : model.value))
const color = (id: number | undefined) => props.sections.find((s) => s.id === id)?.color
const styleOf = (id: number | undefined) => {
  const c = color(id)
  return c ? { borderColor: c, background: tint(c, 0.1), color: c, fontWeight: 600 } : {}
}
/** Premier choix : inchangée / libre / une section. */
const first = computed({
  get: () => (model.value === 'same' ? 'same' : ids.value[0] !== undefined ? String(ids.value[0]) : 'free'),
  set: (v: string) => {
    if (v === 'same') model.value = 'same'
    else if (v === 'free') model.value = []
    else model.value = [Number(v), ...ids.value.slice(1).filter((x) => x !== Number(v))]
  },
})
/** Second choix : la section qui partage la ligne. */
const second = computed({
  get: () => (ids.value[1] !== undefined ? String(ids.value[1]) : ''),
  set: (v: string) => { model.value = v ? [ids.value[0], Number(v)] : [ids.value[0]] },
})
</script>

<template>
  <span class="picker">
    <select v-model="first" :style="styleOf(ids[0])" aria-label="Section">
      <option v-if="sameLabel" value="same">{{ sameLabel }}</option>
      <option value="free">{{ sameLabel ? 'Libérée' : 'Libre' }}</option>
      <option v-for="s in sections" :key="s.id" :value="String(s.id)">{{ sameLabel ? '→ ' : '' }}{{ s.name }}</option>
    </select>
    <select v-if="ids.length" v-model="second" class="second" :style="styleOf(ids[1])" aria-label="Partagée avec">
      <option value="">+ partager…</option>
      <option v-for="s in sections.filter((x) => x.id !== ids[0])" :key="s.id" :value="String(s.id)">avec {{ s.name }}</option>
    </select>
  </span>
</template>

<style scoped>
.picker { display: inline-flex; flex-wrap: wrap; gap: .3rem; justify-content: flex-end }
.picker select { min-width: 140px }
.second { min-width: 120px; font-size: .9rem }
</style>
