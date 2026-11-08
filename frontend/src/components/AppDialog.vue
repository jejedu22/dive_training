<script setup lang="ts">
import AppIcon from './AppIcon.vue'

/** Fenêtre modale simple : <AppDialog v-model="open" title="…">contenu<template #actions>…</template></AppDialog> */
defineProps<{ title: string }>()
const open = defineModel<boolean>({ required: true })
</script>

<template>
  <div v-if="open" class="backdrop" @click.self="open = false" @keydown.esc="open = false">
    <div class="dialog" role="dialog" aria-modal="true" :aria-label="title">
      <div class="head">
        <h2>{{ title }}</h2>
        <button type="button" class="btn btn-ghost btn-icon" title="Fermer" @click="open = false"><AppIcon name="x" /></button>
      </div>
      <slot />
      <div v-if="$slots.actions" class="actions"><slot name="actions" /></div>
    </div>
  </div>
</template>

<style scoped>
.backdrop { position: fixed; inset: 0; z-index: 60; background: rgba(10,20,30,.45); display: grid; place-items: center; padding: 1rem }
.dialog { background: #fff; border-radius: 14px; width: min(100%, 460px); padding: 1.2rem; box-shadow: 0 10px 40px rgba(0,0,0,.25); max-height: 90vh; overflow-y: auto }
.head { display: flex; justify-content: space-between; align-items: center; gap: .5rem; margin-bottom: .9rem }
.actions { display: flex; justify-content: flex-end; gap: .5rem; margin-top: 1.1rem; flex-wrap: wrap }
</style>
