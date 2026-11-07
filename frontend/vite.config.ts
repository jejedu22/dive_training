import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'

// En dev derrière Traefik, /api est routé vers le backend. En dev « nu » : proxy vers Symfony.
// DOMAIN (fourni par docker-compose) doit être autorisé, sinon Vite bloque la requête.
const allowedHosts = ['localhost', '.localhost', ...(process.env.DOMAIN ? [process.env.DOMAIN] : [])]

export default defineConfig({
  plugins: [
    vue(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['favicon.png', 'apple-touch-icon.png'],
      manifest: {
        name: 'Dive Training',
        short_name: 'Dive',
        description: 'Gestion de la formation de plongeurs : sections, planning des lignes d\'eau, bouteilles.',
        lang: 'fr',
        theme_color: '#0b6aa8',
        background_color: '#f4f7fa',
        display: 'standalone',
        start_url: '/planning',
        scope: '/',
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
      devOptions: { enabled: false },
    }),
  ],
  server: { port: 5173, allowedHosts, proxy: { '/api': process.env.API_PROXY ?? 'http://localhost:8000' } },
})
