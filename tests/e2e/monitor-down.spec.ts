import { expect, test } from '@playwright/test'

test('opens a down monitor and shows its ongoing incident', async ({ page }) => {
    await page.goto('/monitors')

    const downMonitor = page
        .getByRole('button', { name: /^View .* monitor details$/ })
        .filter({ hasText: 'Status: Down' })
        .first()

    await downMonitor.click()

    await expect(page.getByText(/^Down for /)).toBeVisible()
    await expect(page.getByRole('cell', { name: 'Ongoing', exact: true }).first()).toBeVisible()
})
