/** Couleurs des sections. */
export const PALETTE = ['#1f6fb5', '#c0392b', '#1e8449', '#7d3c98', '#d35400', '#0e8a96', '#8d5524', '#c2378f', '#3949ab', '#7a7d0f', '#00796b', '#5d6d7e']

const rgb = (hex: string) => {
  const n = parseInt(hex.replace('#', ''), 16)
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255]
}
/** Version transparente d'une couleur (fonds, rayures). */
export const tint = (hex: string, alpha: number) => `rgba(${rgb(hex).join(',')},${alpha})`
/** Texte lisible (blanc ou presque noir) sur un fond de cette couleur. */
export const textOn = (hex: string) => {
  const [r, g, b] = rgb(hex).map((v) => {
    const c = v / 255
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * r + 0.7152 * g + 0.0722 * b > 0.4 ? '#16212c' : '#ffffff'
}
/** Pastille de section : fond teinté, texte et bordure de la couleur. */
export const chipStyle = (hex: string) => ({ background: tint(hex, 0.13), color: hex, boxShadow: `inset 0 0 0 1px ${tint(hex, 0.35)}` })
/** Bloc de séance : plein si confirmée, rayé si en attente. */
export const eventStyle = (hex: string, status: string) =>
  status === 'PENDING'
    ? { background: `repeating-linear-gradient(135deg, ${hex} 0 7px, ${tint(hex, 0.7)} 7px 14px)`, color: textOn(hex), outline: `2px dashed ${hex}`, outlineOffset: '-2px' }
    : { background: hex, color: textOn(hex) }
