import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// Entries load as <script type="module"> from /modules/ljpccalendarmodule/build/ (Http/Assets.php adds ?v=<mtime>),
// so entry and CSS names are stable; shared chunks carry a content hash. CI rebuilds and fails when Public/build
// differs from the commit, so the build must stay deterministic.
export default defineConfig({
  plugins: [vue()],
  base: './',
  build: {
    outDir: '../../Public/build',
    emptyOutDir: true,
    target: 'es2022',
    cssCodeSplit: true,
    rollupOptions: {
      input: {
        calendar: 'src/calendar/main.ts',
        conversation: 'src/conversation/main.ts',
        invite: 'src/invite/main.ts',
        settings: 'src/settings/main.ts',
      },
      output: {
        entryFileNames: '[name].js',
        chunkFileNames: 'chunks/[name]-[hash].js',
        assetFileNames: '[name][extname]',
      },
    },
  },
})
