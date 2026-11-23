import { ref } from 'vue'
import { get } from '../api'
import type { Mft, MftCurriculum, SkillStatus } from '../types'

/** Référentiel MFT (chargé une fois). */
const mft = ref<Mft | null>(null)
let loading: Promise<Mft> | null = null

export function useMft() {
  const load = () => (loading ??= get<Mft>('/mft').then((m) => (mft.value = m)).catch((e) => { loading = null; throw e }))
  const curriculum = (code: string): MftCurriculum | undefined => mft.value?.curricula.find((c) => c.code === code)
  /** Après modification d'un cursus : rechargé à la prochaine utilisation. */
  const reset = () => { loading = null; mft.value = null }
  return { mft, load, curriculum, reset }
}

export const STATUS_LABELS: Record<SkillStatus, string> = { NT: 'Non travaillé', ECA: 'En cours d\'acquisition', A: 'Acquis', V: 'Validé' }
export const STATUS_SHORT: Record<SkillStatus, string> = { NT: 'NT', ECA: 'ECA', A: 'A', V: 'Validé' }
export const PLACE_LABELS = { pool: 'Piscine', pit: 'Fosse', natural: 'Milieu naturel' } as const
