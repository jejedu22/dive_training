<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue'
import AppIcon from '../components/AppIcon.vue'
import PlanningTable from '../components/PlanningTable.vue'
import PublicTrainee from '../components/PublicTrainee.vue'
import { usePeriod } from '../composables/period'
import type { Planning, Trainee } from '../types'

/** Lien de partage : planning du club, ou livret d'un stagiaire (envoyé à la demande). Sans compte, en lecture seule. */
const props = defineProps<{ token: string }>()
const { mode, anchor, label, query, isCurrent, shift, goToday, pdfTitle, pdfName } = usePeriod('month')
const info = ref<{ label: string | null; kind: 'planning' | 'trainee'; name: string | null } | null>(null)
const trainee = ref<Trainee | null>(null)
const data = ref<Planning | null>(null)
const error = ref('')
/** Lien protégé : code demandé (needCode), message d'erreur du dernier essai. */
const needCode = ref(false)
const codeInput = ref('')
const codeError = ref('')
const checking = ref(false)
const downloading = ref(false)

// Le code reste dans l'onglet (sessionStorage) le temps de la visite, jamais dans l'adresse.
const CODE_KEY = `share_code:${props.token}`
const readCode = () => { try { return sessionStorage.getItem(CODE_KEY) ?? '' } catch { return '' } }
const saveCode = (c: string) => { try { c ? sessionStorage.setItem(CODE_KEY, c) : sessionStorage.removeItem(CODE_KEY) } catch { /* navigation privée */ } }
let code = readCode()

class CodeError extends Error {}
async function request(path: string): Promise<Response> {
  const r = await fetch(`/api/public/${encodeURIComponent(props.token)}${path}`, { headers: code ? { 'X-Share-Code': code } : {}, cache: 'no-store' })
  if (r.ok) return r
  const j = await r.json().catch(() => ({}))
  if (r.status === 401) throw new CodeError(code ? (j.message ?? 'Code d\'accès incorrect.') : '')
  if (r.status === 429) throw new CodeError(j.message ?? 'Trop d\'essais, réessayez plus tard.')
  throw new Error(j.message ?? `Erreur ${r.status}`)
}
const fetchJson = async <T,>(path: string) => (await request(path)).json() as Promise<T>

function onError(e: unknown) {
  if (e instanceof CodeError) {
    saveCode('')
    code = ''
    info.value = null
    needCode.value = true
    codeError.value = e.message
  } else {
    error.value = (e as Error).message
  }
}
let seq = 0
async function load() {
  const s = ++seq
  try {
    const r = await fetchJson<Planning>(`/planning?${query.value}`)
    if (s === seq) data.value = r
  } catch (e) {
    onError(e)
  }
}
async function open() {
  try {
    info.value = await fetchJson('')
    needCode.value = false
    codeError.value = ''
    saveCode(code)
    if (info.value!.kind === 'trainee') {
      document.title = `Livret de ${info.value!.name} · Dive Training`
      trainee.value = await fetchJson<Trainee>('/trainee')
      return
    }
    document.title = `Planning des lignes d'eau${info.value?.label ? ` · ${info.value.label}` : ''}`
    await load()
  } catch (e) {
    onError(e)
  }
}
async function submitCode() {
  if (!codeInput.value.trim()) return
  code = codeInput.value.trim()
  checking.value = true
  await open()
  checking.value = false
  if (!needCode.value) codeInput.value = ''
}

// Page privée : pas d'indexation par les moteurs de recherche, pas de fuite de l'adresse (Referer).
const metas: HTMLMetaElement[] = []
onMounted(() => {
  for (const [name, content] of [['robots', 'noindex, nofollow'], ['referrer', 'no-referrer']]) {
    metas.push(document.head.appendChild(Object.assign(document.createElement('meta'), { name, content })))
  }
  open()
})
onUnmounted(() => metas.forEach((m) => m.remove()))
watch([mode, anchor], () => info.value?.kind === 'planning' && load())

async function downloadPdf(enrolmentId?: number) {
  downloading.value = true
  try {
    const isTrainee = info.value?.kind === 'trainee'
    const r = await request(isTrainee ? `/trainee/pdf${enrolmentId ? `?enrolment=${enrolmentId}` : ''}` : `/pdf?${query.value}&title=${encodeURIComponent(pdfTitle.value)}`)
    const url = URL.createObjectURL(await r.blob())
    const a = Object.assign(document.createElement('a'), { href: url, download: isTrainee ? `livret-${(info.value!.name ?? 'stagiaire').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-')}.pdf` : pdfName.value })
    document.body.appendChild(a)
    a.click()
    a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 10_000)
  } catch (e) {
    onError(e)
  } finally {
    downloading.value = false
  }
}
</script>

<template>
  <div class="public">
    <div v-if="error" class="card empty"><strong>Lien indisponible</strong>{{ error }}</div>
    <form v-else-if="needCode" class="card lock" @submit.prevent="submitCode">
      <p class="brand"><img src="/favicon.png" alt="" width="20" height="20" /> Dive Training</p>
      <div class="lock-icon" aria-hidden="true">🔒</div>
      <h1>Accès protégé</h1>
      <p class="muted">Saisissez le code d'accès communiqué par le club.</p>
      <input v-model="codeInput" class="code" autocomplete="off" autocapitalize="off" spellcheck="false" inputmode="text" aria-label="Code d'accès" placeholder="Code d'accès" autofocus />
      <p v-if="codeError" class="err" role="alert">{{ codeError }}</p>
      <button class="btn" :disabled="checking || !codeInput.trim()">{{ checking ? 'Vérification…' : 'Afficher le planning' }}</button>
    </form>
    <PublicTrainee v-else-if="info?.kind === 'trainee' && trainee" :fiche="trainee" :downloading="downloading" @pdf="downloadPdf" />
    <template v-else-if="info && info.kind === 'planning'">
      <div class="page-head">
        <div>
          <p class="brand"><img src="/favicon.png" alt="" width="20" height="20" /> Dive Training</p>
          <h1>Planning des lignes d'eau</h1>
          <p class="cap">{{ label }}<template v-if="info.label"> · {{ info.label }}</template></p>
        </div>
        <div class="row">
          <div class="seg" role="tablist" aria-label="Période">
            <button role="tab" :class="{ on: mode === 'month' }" @click="mode = 'month'">Mois</button>
            <button role="tab" :class="{ on: mode === 'week' }" @click="mode = 'week'">Semaine</button>
          </div>
          <button class="btn btn-secondary btn-icon" title="Précédent" @click="shift(-1)"><AppIcon name="left" /></button>
          <button class="btn btn-secondary" :disabled="isCurrent" @click="goToday">Aujourd'hui</button>
          <button class="btn btn-secondary btn-icon" title="Suivant" @click="shift(1)"><AppIcon name="right" /></button>
          <button class="btn btn-secondary" :disabled="downloading" @click="downloadPdf()"><AppIcon name="download" :size="16" /> PDF</button>
        </div>
      </div>
      <div v-if="data && !data.occurrences.length" class="card empty"><strong>Aucun créneau du club sur cette période</strong></div>
      <PlanningTable v-else-if="data" :occurrences="data.occurrences" />
      <p class="muted small foot">Planning en lecture seule, partagé par le club.</p>
    </template>
    <p v-else class="empty">Chargement…</p>
  </div>
</template>

<style scoped>
.brand { display: flex; align-items: center; gap: .4rem; font-weight: 700; color: var(--primary); margin: 0 0 .3rem }
.cap::first-letter { text-transform: uppercase }
.seg { display: inline-flex; border: 1px solid var(--primary-100); border-radius: 8px; overflow: hidden; background: #fff }
.seg button { border: 0; background: none; font: inherit; font-weight: 600; padding: .45rem .9rem; color: var(--primary); cursor: pointer; min-height: 38px }
.seg button.on { background: var(--primary); color: #fff }
.small { font-size: .85rem }
.foot { margin-top: 1rem; text-align: center }
.lock { max-width: 380px; margin: 12vh auto 0; display: flex; flex-direction: column; align-items: center; gap: .6rem; text-align: center; padding: 1.5rem }
.lock h1 { margin: 0; font-size: 1.3rem }
.lock p { margin: 0 }
.lock-icon { font-size: 2.2rem }
.lock .code { width: 100%; text-align: center; font-size: 1.2rem; letter-spacing: .15em; padding: .6rem }
.lock .btn { width: 100% }
.err { color: #b42318; font-size: .9rem }
</style>
