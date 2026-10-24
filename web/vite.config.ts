import { defineConfig, type Plugin } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'node:path';

// One id per build. Every open tab compares it with /version.json and reloads
// itself when a newer build is out (src/lib/versionCheck.ts): fixes reach the
// clinic without asking anyone to reload.
const buildId = process.env.VERCEL_GIT_COMMIT_SHA || String(Date.now());

function versionFile(): Plugin {
  return {
    name: 'elosar-version-file',
    apply: 'build',
    generateBundle() {
      this.emitFile({ type: 'asset', fileName: 'version.json', source: JSON.stringify({ build: buildId }) });
    },
  };
}

// Dev server runs on 5174 to avoid clashing with the backend's Vite (5173).
export default defineConfig({
  plugins: [react(), versionFile()],
  define: {
    __BUILD_ID__: JSON.stringify(buildId),
  },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    // Default 5174 (avoids the backend's Vite on 5173); honor PORT when set
    // so a managed preview server can pick a free port.
    port: Number(process.env.PORT) || 5174,
  },
  test: {
    exclude: [
      '**/node_modules/**',
      '**/dist/**',
      '**/.{idea,git,cache,output,temp}/**',
      '**/.worktrees/**',
    ],
  },
});
