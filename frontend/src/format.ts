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

/** « aujourd'hui à 10:24 », « hier à 18:02 », « il y a 3 jours », sinon la date. */
export const relative = (iso: string | null) => {
  if (!iso) return 'jamais'
  const d = new Date(iso), now = new Date()
  const days = Math.round((new Date(ymd(now)).getTime() - new Date(ymd(d)).getTime()) / 86400_000)
  const t = d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
  if (days === 0) return `aujourd'hui à ${t}`
  if (days === 1) return `hier à ${t}`
  if (days < 7) return `il y a ${days} jours`
  return d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}

/** Mot de passe aléatoire lisible (sans caractères ambigus : 0/O, 1/l/I). */
export function generatePassword(length = 12) {
  const chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'
  const bytes = crypto.getRandomValues(new Uint32Array(length))
  return Array.from(bytes, (b) => chars[b % chars.length]).join('')
}

/** Initiales (2 lettres) d'un nom : « Marie Dupont » -> « MD ». */
export const initials = (name: string) =>
  name.trim().split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]!.toUpperCase()).join('') || '?'
