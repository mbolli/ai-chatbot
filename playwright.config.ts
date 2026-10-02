import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { defineConfig, devices } from '@playwright/test';

// The suite starts its own Swoole server on a throwaway database (config/e2e.php),
// so it never touches data/db.sqlite. Only localhost test commands are sent, no AI calls.
const port = Number(process.env.E2E_PORT ?? 8094);
const dataDir = process.env.E2E_DATA_DIR ?? join(tmpdir(), `ai-chatbot-e2e-${port}`);
const phpIniScanDir = process.env.PHP_INI_SCAN_DIR ?? `${process.env.HOME}/.local/php-swoole/conf.d`;
const chromiumPath = process.env.PLAYWRIGHT_CHROMIUM_PATH;

export default defineConfig({
    testDir: 'tests/e2e',
    // The app runs a single Swoole worker and the canned test streams block it while they sleep,
    // so parallel tests would only time each other out.
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    timeout: 30_000,
    expect: { timeout: 10_000 },
    reporter: process.env.CI ? 'line' : 'list',
    use: {
        baseURL: `http://127.0.0.1:${port}`,
        trace: 'retain-on-failure',
        launchOptions: chromiumPath ? { executablePath: chromiumPath } : {},
    },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } }, testIgnore: /mobile\.spec\.ts/ },
        { name: 'mobile', use: { ...devices['Pixel 7'], viewport: { width: 390, height: 844 } }, testMatch: /mobile\.spec\.ts/ },
    ],
    webServer: {
        command: 'php tests/e2e/init-db.php && exec vendor/bin/laminas mezzio:swoole:start',
        url: `http://127.0.0.1:${port}/`,
        reuseExistingServer: false,
        timeout: 30_000,
        stdout: 'ignore',
        stderr: 'pipe',
        env: {
            PHP_INI_SCAN_DIR: phpIniScanDir,
            E2E_DATA_DIR: dataDir,
            E2E_PORT: String(port),
            APP_DEBUG: 'false',
        },
    },
});
