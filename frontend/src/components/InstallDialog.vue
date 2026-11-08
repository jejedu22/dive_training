<script setup lang="ts">
import { ref, watch } from 'vue'
import AppIcon from './AppIcon.vue'
import { installChecks, installOpen, platform } from '../pwa'

const checks = ref<{ https: boolean; manifest: boolean; serviceWorker: boolean } | null>(null)
watch(installOpen, async (open) => {
  if (open) checks.value = await installChecks()
})
const host = location.host
</script>

<template>
  <div v-if="installOpen" class="backdrop" @click.self="installOpen = false">
    <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="install-title">
      <div class="head">
        <img src="/pwa-192.png" alt="" width="48" height="48" />
        <div><h2 id="install-title">Installer Dive Training</h2><p class="muted">Une icône sur l'écran d'accueil, en plein écran, comme une application.</p></div>
        <button class="btn btn-ghost btn-icon" title="Fermer" @click="installOpen = false"><AppIcon name="x" /></button>
      </div>

      <div v-if="checks && !checks.https" class="alert alert-ko"><AppIcon name="alert" />
        <span>L'installation exige une connexion sécurisée (<strong>https://</strong>). Ouvrez l'application via son adresse HTTPS.</span></div>
      <div v-else-if="checks && (!checks.manifest || !checks.serviceWorker)" class="alert alert-warn"><AppIcon name="alert" />
        <span>Le navigateur ne propose pas encore l'installation (service worker {{ checks.serviceWorker ? 'actif' : 'pas encore actif' }}). Rechargez la page une fois, puis réessayez.</span></div>

      <ol v-if="platform === 'ios'" class="steps">
        <li>Ouvrez <strong>{{ host }}</strong> dans <strong>Safari</strong> (pas dans Chrome ni dans une autre app).</li>
        <li>Touchez le bouton <strong>Partager</strong> <span class="kbd">⬆︎</span> en bas de l'écran.</li>
        <li>Choisissez <strong>« Sur l'écran d'accueil »</strong>, puis <strong>Ajouter</strong>.</li>
      </ol>
      <ol v-else-if="platform === 'android' || platform === 'samsung'" class="steps">
        <li>Ouvrez le menu du navigateur <span class="kbd">⋮</span> {{ platform === 'samsung' ? '(ou ☰)' : '' }} en haut à droite.</li>
        <li>Choisissez <strong>« Installer l'application »</strong> ou <strong>« Ajouter à l'écran d'accueil »</strong>.</li>
        <li>Confirmez avec <strong>Installer</strong>.</li>
      </ol>
      <ol v-else-if="platform === 'safari-mac'" class="steps">
        <li>Dans Safari, menu <strong>Fichier</strong> (ou bouton Partager).</li>
        <li>Choisissez <strong>« Ajouter au Dock »</strong>.</li>
      </ol>
      <div v-else-if="platform === 'firefox'" class="steps">
        <p>Firefox sur ordinateur ne sait pas installer les applications web. Ouvrez <strong>{{ host }}</strong> avec <strong>Chrome</strong> ou <strong>Edge</strong>, ou installez-la depuis votre téléphone.</p>
        <p class="muted">Sur Android, Firefox propose « Installer » dans son menu <span class="kbd">⋮</span>.</p>
      </div>
      <ol v-else class="steps">
        <li>Cliquez sur l'icône d'installation <span class="kbd">⊕</span> à droite de la barre d'adresse,</li>
        <li>ou ouvrez le menu <span class="kbd">⋮</span> → <strong>« Installer Dive Training »</strong> (Chrome) / <strong>Applications → Installer</strong> (Edge).</li>
      </ol>

      <div class="row" style="justify-content:flex-end;margin-top:1rem"><button class="btn" @click="installOpen = false">J'ai compris</button></div>
    </div>
  </div>
</template>

<style scoped>
.backdrop { position: fixed; inset: 0; z-index: 60; background: rgba(10,20,30,.45); display: grid; place-items: center; padding: 1rem }
.dialog { background: #fff; border-radius: 14px; width: min(100%, 480px); padding: 1.25rem; box-shadow: 0 10px 40px rgba(0,0,0,.25); max-height: 90vh; overflow-y: auto }
.head { display: flex; gap: .8rem; align-items: flex-start; margin-bottom: 1rem }
.head img { border-radius: 12px; flex: none }
.head > div { flex: 1 }
.head p { margin: .2rem 0 0; font-size: .9rem }
.alert { margin-bottom: .9rem }
.steps { margin: 0; padding-left: 1.3rem; display: flex; flex-direction: column; gap: .55rem; line-height: 1.5 }
div.steps { padding-left: 0 }
div.steps p { margin: 0 0 .5rem }
.kbd { display: inline-block; min-width: 1.5em; text-align: center; padding: 0 .3rem; border: 1px solid var(--line); border-bottom-width: 2px; border-radius: 5px; background: #f7f9fb; font-size: .9em }
</style>
