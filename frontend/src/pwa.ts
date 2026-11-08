import { ref } from 'vue'

/**
 * Installation de la PWA.
 * - Chrome / Edge / Android : le navigateur fournit une invite (beforeinstallprompt) qu'on déclenche au clic.
 * - Safari (iPhone/iPad/Mac), Firefox : pas d'invite programmable → on affiche la marche à suivre.
 * Le bouton reste visible tant que l'application n'est pas installée.
 */
interface InstallPromptEvent extends Event { prompt: () => Promise<void>; userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }> }

let deferred: InstallPromptEvent | null = null
export const canPrompt = ref(false)
export const isStandalone = ref(
  window.matchMedia('(display-mode: standalone)').matches || (navigator as unknown as { standalone?: boolean }).standalone === true,
)
export const installOpen = ref(false)

const ua = navigator.userAgent
export const isIos = /iphone|ipad|ipod/i.test(ua) || (/macintosh/i.test(ua) && navigator.maxTouchPoints > 1)
export const platform: 'ios' | 'android' | 'firefox' | 'safari-mac' | 'samsung' | 'desktop' =
  isIos ? 'ios'
    : /samsungbrowser/i.test(ua) ? 'samsung'
      : /android/i.test(ua) ? 'android'
        : /firefox/i.test(ua) ? 'firefox'
          : /safari/i.test(ua) && !/chrome|chromium|edg/i.test(ua) ? 'safari-mac'
            : 'desktop'

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault()
  deferred = e as InstallPromptEvent
  canPrompt.value = true
})
window.addEventListener('appinstalled', () => {
  deferred = null
  canPrompt.value = false
  isStandalone.value = true
  installOpen.value = false
})
window.matchMedia('(display-mode: standalone)').addEventListener?.('change', (e) => (isStandalone.value = e.matches))

/** Clic sur « Installer » : invite native si disponible, sinon fenêtre d'aide. */
export async function openInstall() {
  if (deferred) {
    await deferred.prompt()
    const { outcome } = await deferred.userChoice
    deferred = null
    canPrompt.value = false
    if (outcome === 'accepted') return
  }
  installOpen.value = true
}

/** Ce qui peut empêcher l'installation (affiché dans la fenêtre d'aide). */
export async function installChecks() {
  const reg = 'serviceWorker' in navigator ? await navigator.serviceWorker.getRegistration() : undefined
  return {
    https: window.isSecureContext,
    manifest: !!document.querySelector('link[rel="manifest"]'),
    serviceWorker: !!reg,
  }
}
