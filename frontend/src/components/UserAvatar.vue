<script setup lang="ts">
import { computed } from 'vue'
import { initials } from '../format'
import { textOn } from '../colors'

const props = withDefaults(defineProps<{ name: string; color?: string; size?: number }>(), { color: '#0b6aa8', size: 32 })
const style = computed(() => ({
  width: `${props.size}px`, height: `${props.size}px`, fontSize: `${Math.round(props.size * 0.4)}px`,
  background: props.color, color: textOn(props.color),
}))
</script>

<template>
  <span class="avatar" :style="style" aria-hidden="true">{{ initials(name) }}</span>
</template>

<style scoped>
.avatar { border-radius: 50%; display: inline-grid; place-items: center; font-weight: 700; flex: none; letter-spacing: .02em; box-shadow: 0 0 0 2px rgba(255,255,255,.6) }
</style>
