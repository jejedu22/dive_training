import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'
import type { Plugin } from 'vite'

/**
 * En mode développement uniquement : /sw.js « autodestructeur ».
 * Un navigateur qui a installé le service worker de la version de production (/sw.js) le garde
 * tant qu'il ne trouve pas de /sw.js valide ; il afficherait indéfiniment l'ancienne application.
 * Ce script le remplace, vide les caches, se désinscrit et recharge les onglets sur la version à jour.
 */
const SELF_DESTROYING_SW = `
self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (event) => event.waitUntil((async () => {
  for (const key of await caches.keys()) await caches.delete(key)
  await self.registration.unregister()
  for (const client of await self.clients.matchAll({ type: 'window' })) client.navigate(client.url)
})()))
`
const devSwKillSwitch = (): Plugin => ({
  name: 'dev-sw-kill-switch',
  apply: 'serve',
  configureServer(server) {
    server.middlewares.use('/sw.js', (_req, res) => {
      res.setHeader('Content-Type', 'application/javascript')
      res.setHeader('Cache-Control', 'no-cache')
      res.end(SELF_DESTROYING_SW)
    })
  },
})

// En dev derrière Traefik, /api est routé vers le backend. En dev « nu » : proxy vers Symfony.
// DOMAIN (fourni par docker-compose) doit être autorisé, sinon Vite bloque la requête.
const allowedHosts = ['localhost', '.localhost', ...(process.env.DOMAIN ? [process.env.DOMAIN] : [])]

// Version affichée dans l'application (date de construction) pour savoir quelle version tourne
const BUILD = new Date().toISOString().slice(0, 16).replace('T', ' ')

export default defineConfig({
  define: { __APP_BUILD__: JSON.stringify(BUILD) },
  plugins: [
    devSwKillSwitch(),
    vue(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['favicon.png', 'apple-touch-icon.png'],
      manifest: {
        name: 'Dive Training',
        short_name: 'Dive',
        description: 'Formation des plongeurs : créneaux du club, occupation des lignes d\'eau, sections et bouteilles.',
        lang: 'fr',
        theme_color: '#0b6aa8',
        background_color: '#f4f7fa',
        display: 'standalone',
        start_url: '/occupation',
        scope: '/',
        shortcuts: [
          { name: 'Lignes d\'eau', url: '/occupation', icons: [{ src: 'pwa-192.png', sizes: '192x192' }] },
          { name: 'Planning', url: '/planning', icons: [{ src: 'pwa-192.png', sizes: '192x192' }] },
        ],
        icons: [
          { src: 'pwa-192.png', sizes: '192x192', type: 'image/png' },
          { src: 'pwa-512.png', sizes: '512x512', type: 'image/png' },
          { src: 'pwa-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      workbox: {
        // Application « shell » en cache ; l'API n'est jamais mise en cache (données et jetons personnels).
        navigateFallback: '/index.html',
        navigateFallbackDenylist: [/^\/api\//],
        runtimeCaching: [{ urlPattern: /^\/api\//, handler: 'NetworkOnly' }],
        cleanupOutdatedCaches: true,
      },
      // Aussi en mode développement (docker-compose.dev.yml), pour pouvoir installer l'app quel que soit le mode
      devOptions: { enabled: true, navigateFallbackAllowlist: [/^\/$/] },
    }),
  ],
  server: { port: 5173, allowedHosts, proxy: { '/api': process.env.API_PROXY ?? 'http://localhost:8000' } },
})
