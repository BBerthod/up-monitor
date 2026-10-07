import { expect, test } from '@playwright/test'

test('shows the global state and Inbox navigation', async ({ page }) => {
    await page.goto('/dashboard')

    await expect(page.getByTestId('global-state')).toHaveText(/^(All .* up|\d+ monitors? down)/)
    await expect(page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: /^Inbox/ })).toBeVisible()
})
