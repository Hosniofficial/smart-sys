import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path'

export default defineConfig({
  base: '/',

  plugins: [vue()],

  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'src'),
      // Use the runtime-only build (no template compiler) — saves ~15 KB gzipped.
      // All templates are pre-compiled by Vite at build time.
      'vue': 'vue/dist/vue.esm-bundler.js',
    },
    dedupe: ['vue'],
  },

  // Pre-bundle these for faster dev cold-start
  optimizeDeps: {
    include: ['vue', 'vue-router', 'pinia', 'axios'],
  },

  build: {
    // Warn when a chunk exceeds 500 KB (before gzip)
    chunkSizeWarningLimit: 500,

    rollupOptions: {
      output: {
        /**
         * Manual chunk splitting strategy:
         *
         * Goal: keep the initial JS payload small by separating heavy
         * libraries that are only needed on specific pages.
         *
         * Chunks:
         *  vendor-core    — Vue ecosystem (always needed)
         *  vendor-ui      — Icon libraries: Lucide Vue Next + Font Awesome (always needed)
         *  vendor-charts  — chart.js + vue-chartjs (reports pages only)
         *  vendor-excel   — exceljs (export pages only — was bloating OpeningBalance)
         *  vendor-utils   — axios, pinia, dayjs, uuid, etc.
         */
        manualChunks(id) {
          // ── Heavy libraries — split first ──────────────────────────────
          if (id.includes('node_modules/exceljs') ||
              id.includes('node_modules/jszip')   ||
              id.includes('node_modules/archiver')) {
            return 'vendor-excel';
          }

          if (id.includes('node_modules/chart.js') ||
              id.includes('node_modules/vue-chartjs')) {
            return 'vendor-charts';
          }

          // ── Vue core ────────────────────────────────────────────────────
          if (id.includes('node_modules/vue/') ||
              id.includes('node_modules/@vue/') ||
              id.includes('node_modules/vue-router') ||
              id.includes('node_modules/pinia')) {
            return 'vendor-core';
          }

          // ── UI libraries ────────────────────────────────────────────────
          if (id.includes('node_modules/lucide-vue-next') ||
              id.includes('node_modules/@fortawesome')) {
            return 'vendor-ui';
          }

          // ── Utilities ───────────────────────────────────────────────────
          if (id.includes('node_modules/axios')   ||
              id.includes('node_modules/dayjs')   ||
              id.includes('node_modules/uuid')    ||
              id.includes('node_modules/mitt')    ||
              id.includes('node_modules/lodash')) {
            return 'vendor-utils';
          }

          // Everything else in node_modules → vendor-misc
          if (id.includes('node_modules/')) {
            return 'vendor-misc';
          }
        },
      },
    },
  },

  server: {
    host: true,
    strictPort: true,
    cors: true,
    // Fix HMR WebSocket connection when accessing via localhost
    // The default HMR config uses the server host which can fail
    // when host:true binds to 0.0.0.0 but browser connects via localhost.
    hmr: {
      host: 'localhost',
      port: 5173,
    },
  },
});
