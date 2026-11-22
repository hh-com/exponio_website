import { defineConfig } from '@playwright/test'

/**
 * Browser-Tests (Barrierefreiheit mit axe, Tastaturbedienung).
 * Erwartet eine laufende Website mit Demodaten, z. B.:
 *   php bin/console app:demo --items=36 && php -S 127.0.0.1:8000 -t public
 * Adresse ändern: E2E_BASE_URL=http://… npm run test:e2e
 */
export default defineConfig({
  testDir: 'tests/e2e',
  timeout: 60_000,
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000',
    // axe wird als Skript eingefügt; die Content-Security-Policy der Seite würde das blockieren
    bypassCSP: true,
    launchOptions: { executablePath: process.env.PLAYWRIGHT_CHROMIUM_PATH || undefined },
  },
  projects: [
    { name: 'desktop', use: { viewport: { width: 1366, height: 900 } } },
    { name: 'mobile', use: { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } },
  ],
})
