import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// En dev derrière Traefik, /api est routé vers le backend. En dev « nu » : proxy vers Symfony.
// DOMAIN (fourni par docker-compose) doit être autorisé, sinon Vite bloque la requête.
const allowedHosts = ['localhost', '.localhost', ...(process.env.DOMAIN ? [process.env.DOMAIN] : [])]

export default defineConfig({
  plugins: [vue()],
  server: { port: 5173, allowedHosts, proxy: { '/api': process.env.API_PROXY ?? 'http://localhost:8000' } },
})
