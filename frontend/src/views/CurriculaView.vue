<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppIcon from '../components/AppIcon.vue'
import AppDialog from '../components/AppDialog.vue'
import { get, post } from '../api'
import { useToast } from '../stores/toast'
import { useMft } from '../composables/mft'
import type { CurriculumMeta } from '../types'

/** Cursus (référentiel) : modification réservée aux administrateurs. */
const toast = useToast(), router = useRouter()
const { reset: resetMft } = useMft()
const list = ref<CurriculumMeta[]>([])
const load = async () => {
  try { list.value = await get<CurriculumMeta[]>('/curricula') } catch (e) { toast.error(e) }
}
onMounted(load)

const origin = { mft: 'Valeurs du MFT', modified: 'Modifié par le club', club: 'Cursus du club' } as const

const createOpen = ref(false)
const form = ref({ code: '', name: '', copyFrom: 'N1' })
const error = ref('')
async function create() {
  error.value = ''
  try {
    const c = await post<CurriculumMeta>('/curricula', form.value)
    resetMft()
    createOpen.value = false
    router.push(`/cursus/${c.code}`)
  } catch (e) {
    error.value = (e as Error).message
  }
}
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Cursus</h1>
      <p>Compétences, éléments évalués et règles de chaque cursus. Par défaut, les intitulés des manuels FFESSM ; vous pouvez les adapter, créer un cursus du club ou revenir aux valeurs du manuel.</p>
    </div>
    <button class="btn" @click="form = { code: '', name: '', copyFrom: 'N1' }; error = ''; createOpen = true"><AppIcon name="plus" /> Nouveau cursus</button>
  </div>

  <div class="list">
    <router-link v-for="c in list" :key="c.code" :to="`/cursus/${c.code}`" class="card item">
      <span class="code">{{ c.code }}</span>
      <div class="info">
        <strong>{{ c.name }}</strong>
        <small class="muted">{{ c.version }} · {{ c.type === 'teaching' ? 'séances et modules' : 'compétences' }} · {{ c.enrolments }} suivi{{ c.enrolments > 1 ? 's' : '' }}</small>
        <small v-if="c.updatedAt" class="muted">Modifié le {{ new Date(c.updatedAt).toLocaleDateString('fr-FR') }}<template v-if="c.updatedBy"> par {{ c.updatedBy }}</template></small>
      </div>
      <span class="chip" :class="c.origin === 'mft' ? '' : c.origin === 'modified' ? 'chip-warn' : 'chip-primary'">{{ origin[c.origin] }}</span>
      <AppIcon name="right" class="muted" />
    </router-link>
  </div>

  <AppDialog v-model="createOpen" title="Nouveau cursus">
    <form id="cur-form" class="stack" style="gap:.7rem" @submit.prevent="create">
      <div class="fields">
        <label class="field">Code<input v-model="form.code" required maxlength="10" placeholder="ex. N4, NITROX" style="text-transform:uppercase" /></label>
        <label class="field">Nom<input v-model="form.name" required maxlength="60" placeholder="ex. Guide de palanquée" /></label>
      </div>
      <label class="field">Partir de
        <select v-model="form.copyFrom"><option v-for="c in list" :key="c.code" :value="c.code">{{ c.name }} ({{ c.code }})</option></select>
      </label>
      <p class="muted small">Le nouveau cursus reprend les compétences et les règles du cursus choisi ; vous l'adaptez ensuite.</p>
    </form>
    <div v-if="error" class="alert alert-ko" style="margin-top:.75rem"><AppIcon name="alert" /> <span>{{ error }}</span></div>
    <template #actions>
      <button type="button" class="btn btn-ghost" @click="createOpen = false">Annuler</button>
      <button class="btn" form="cur-form"><AppIcon name="check" /> Créer</button>
    </template>
  </AppDialog>
</template>

<style scoped>
.list { display: flex; flex-direction: column; gap: .5rem }
.item { display: flex; align-items: center; gap: .9rem; padding: .8rem 1rem; color: inherit; text-decoration: none }
.item:hover { box-shadow: 0 0 0 2px var(--primary-100) }
.code { font-weight: 800; color: var(--primary); min-width: 3.5em; font-size: 1.05rem }
.info { flex: 1; display: flex; flex-direction: column; gap: .1rem; min-width: 0 }
.small { font-size: .85rem }
@media (max-width: 600px) { .item { flex-wrap: wrap } }
</style>
