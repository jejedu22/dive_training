<script setup lang="ts">
import AppIcon from './AppIcon.vue'
import { useToast } from '../stores/toast'
const toast = useToast()
</script>

<template>
  <div class="toasts" aria-live="polite">
    <TransitionGroup name="t">
      <div v-for="t in toast.items" :key="t.id" class="toast" :class="t.kind" @click="toast.dismiss(t.id)">
        <AppIcon :name="t.kind === 'ok' ? 'check' : t.kind === 'error' ? 'alert' : 'info'" />
        <span>{{ t.text }}</span>
      </div>
    </TransitionGroup>
  </div>
</template>

<style scoped>
.toasts { position: fixed; z-index: 50; left: 50%; transform: translateX(-50%); bottom: 1rem; display: flex; flex-direction: column; gap: .5rem; width: min(92vw, 440px) }
.toast { display: flex; gap: .6rem; align-items: flex-start; padding: .7rem .9rem; border-radius: 10px; color: #fff; background: #1f2d3a; box-shadow: 0 6px 20px rgba(0,0,0,.18); cursor: pointer; font-size: .93rem }
.toast.ok { background: #17663a } .toast.error { background: #a32626 }
.t-enter-from, .t-leave-to { opacity: 0; transform: translateY(8px) }
.t-enter-active, .t-leave-active { transition: all .2s ease }
@media (max-width: 760px) { .toasts { bottom: calc(var(--tabbar-h) + .75rem) } }
</style>
