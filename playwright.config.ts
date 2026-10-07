import { defineConfig, devices } from '@playwright/test'

const authFile = 'storage/e2e-results/.auth.json'

export default defineConfig({
    testDir: 'tests/e2e',
    retries: 0,
    reporter: 'list',
    outputDir: 'storage/e2e-results',
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8095',
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'setup',
            testMatch: '**/*.setup.ts',
        },
        {
            name: 'chromium',
            testMatch: '**/*.spec.ts',
            use: {
                ...devices['Desktop Chrome'],
                storageState: authFile,
            },
            dependencies: ['setup'],
        },
    ],
})
