import { computed, ref } from 'vue'
import { addDays, mondayOf, today } from '../format'

/** Période affichée du planning : semaine (lundi → dimanche) ou mois calendaire, avec navigation. */
export function usePeriod(initial: 'week' | 'month' = 'month') {
  const mode = ref<'week' | 'month'>(initial)
  const anchor = ref(today())

  const period = computed(() => {
    if (mode.value === 'week') {
      const from = mondayOf(anchor.value)
      return { from, to: addDays(from, 7) }
    }
    const [y, m] = anchor.value.split('-').map(Number)
    const next = m === 12 ? `${y + 1}-01-01` : `${y}-${String(m + 1).padStart(2, '0')}-01`
    return { from: `${anchor.value.slice(0, 7)}-01`, to: next }
  })
  const label = computed(() => {
    if (mode.value === 'month') return new Date(`${period.value.from}T12:00:00`).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' })
    const f = (d: string) => new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })
    return `Semaine du ${f(period.value.from)} au ${f(addDays(period.value.to, -1))}`
  })
  /** Bornes ISO (minuit local) et paramètres de requête ?from=…&to=… */
  const query = computed(() => {
    const from = new Date(`${period.value.from}T00:00:00`).toISOString(), to = new Date(`${period.value.to}T00:00:00`).toISOString()
    return `from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`
  })
  const isCurrent = computed(() => today() >= period.value.from && today() < period.value.to)
  function shift(n: number) {
    if (mode.value === 'week') anchor.value = addDays(anchor.value, 7 * n)
    else {
      const [y, m] = anchor.value.split('-').map(Number)
      const d = new Date(y, m - 1 + n, 1)
      anchor.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`
    }
  }
  const goToday = () => (anchor.value = today())
  /** Titre et nom de fichier pour l'export PDF. */
  const pdfTitle = computed(() => `Planning des lignes d'eau — ${label.value}`)
  const pdfName = computed(() => `planning-${period.value.from}.pdf`) // même nom que celui donné par le serveur
  return { mode, anchor, period, label, query, isCurrent, shift, goToday, pdfTitle, pdfName }
}
