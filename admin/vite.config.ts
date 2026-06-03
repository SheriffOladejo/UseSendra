import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  base: '/logistics/admin/',
  plugins: [react(), tailwindcss()],
  server: {
    port: 5174,
    proxy: {
      '/logistics/api': {
        target: 'https://usesendra.com',
        changeOrigin: true,
        secure: true,
      },
    },
  },
})
