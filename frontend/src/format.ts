/** Formats de dates en français et conversions pour les champs de saisie. */
const p = (n: number) => String(n).padStart(2, '0')

export const ymd = (d: Date) => `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
export const today = () => ymd(new Date())
export const time = (iso: string) => new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
export const dayLong = (iso: string) => new Date(iso).toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })
export const dayShort = (iso: string) => new Date(iso).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' })
export const dateTime = (iso: string) => new Date(iso).toLocaleString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
export const dateFr = (ymdStr: string) => new Date(`${ymdStr}T12:00:00`).toLocaleDateString('fr-FR')

/** ISO -> valeur d'un <input type="datetime-local"> (heure locale). */
export const toLocalInput = (iso: string) => {
  const d = new Date(iso)
  return `${ymd(d)}T${p(d.getHours())}:${p(d.getMinutes())}`
}
/** Lundi de la semaine qui contient la date (AAAA-MM-JJ). */
export const mondayOf = (ymdStr: string) => {
  const d = new Date(`${ymdStr}T12:00:00`)
  d.setDate(d.getDate() - ((d.getDay() + 6) % 7))
  return ymd(d)
}
export const addDays = (ymdStr: string, n: number) => {
  const d = new Date(`${ymdStr}T12:00:00`)
  d.setDate(d.getDate() + n)
  return ymd(d)
}
/** Bornes ISO d'une plage de jours locaux [from, from + days[. */
export const range = (fromYmd: string, days: number) => ({
  from: new Date(`${fromYmd}T00:00:00`).toISOString(),
  to: new Date(`${addDays(fromYmd, days)}T00:00:00`).toISOString(),
})
export const WEEKDAYS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim']
