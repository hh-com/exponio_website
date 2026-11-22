import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

// Assets landen in public/build; Symfony liest .vite/manifest.json (src/Twig/Vite.php)
export default defineConfig({
  base: '/build/',
  plugins: [vue(), tailwindcss()],
  publicDir: false,
  build: {
    outDir: 'public/build',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: {
        app: 'assets/app.ts',
        admin: 'assets/admin.ts',
      },
    },
  },
  server: {
    // "npm run dev" + VITE_DEV_SERVER=http://localhost:5173 in .env.local
    port: 5173,
    strictPort: true,
    cors: true,
    origin: 'http://localhost:5173',
  },
})
