import { expect, test as setup } from '@playwright/test'

const authFile = 'storage/e2e-results/.auth.json'

setup('authenticate', async ({ page }) => {
    await page.goto('/login')
    await page.getByLabel('Email').fill(process.env.E2E_EMAIL ?? 'admin@example.com')
    await page.getByLabel('Password').fill(process.env.E2E_PASSWORD ?? 'password')
    await page.getByRole('button', { name: 'Sign in' }).click()

    await expect(page).toHaveURL(/\/dashboard(?:\?.*)?$/)
    await page.context().storageState({ path: authFile })
})
