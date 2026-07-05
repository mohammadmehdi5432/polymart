import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'path';

export default defineConfig({
  plugins: [react()],
  root: '.',
  base: './',
  build: {
    outDir: 'build',
    emptyOutDir: true,
    manifest: true,
    // Single classic script for WordPress admin (no import.meta / type=module).
    cssCodeSplit: false,
    modulePreload: false,
    target: 'es2018',
    rollupOptions: {
      input: resolve(__dirname, 'src/index.jsx'),
      output: {
        format: 'iife',
        inlineDynamicImports: true,
        entryFileNames: 'assets/[name].js',
        assetFileNames: (assetInfo) => {
          if (assetInfo.name && assetInfo.name.endsWith('.css')) {
            return 'assets/index.css';
          }
          return 'assets/[name][extname]';
        },
      },
    },
  },
  server: {
    port: 5173,
    strictPort: true,
  },
});
