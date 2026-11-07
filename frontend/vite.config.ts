import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// En dev derrière Traefik, /api est routé vers le backend. En dev « nu » : proxy vers Symfony.
export default defineConfig({
  plugins: [vue()],
  server: { port: 5173, proxy: { '/api': process.env.API_PROXY ?? 'http://localhost:8000' } },
})
