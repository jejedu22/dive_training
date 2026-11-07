import { ref } from 'vue'

/** Installation PWA : bouton « Installer » quand le navigateur le permet, aide pour iOS (Safari n'a pas d'invite). */
interface InstallPromptEvent extends Event { prompt: () => Promise<void>; userChoice: Promise<{ outcome: string }> }

let deferred: InstallPromptEvent | null = null
export const canInstall = ref(false)
export const isStandalone = ref(window.matchMedia('(display-mode: standalone)').matches || (navigator as unknown as { standalone?: boolean }).standalone === true)
export const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent)

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault()
  deferred = e as InstallPromptEvent
  canInstall.value = true
})
window.addEventListener('appinstalled', () => {
  canInstall.value = false
  isStandalone.value = true
})

export async function install() {
  if (!deferred) return
  await deferred.prompt()
  await deferred.userChoice
  deferred = null
  canInstall.value = false
}
