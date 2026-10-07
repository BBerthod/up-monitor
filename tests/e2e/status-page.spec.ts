import { expect, test } from '@playwright/test'

test.use({ storageState: { cookies: [], origins: [] } })

test('shows public status, 90-day history, keyboard tooltip and canonical URL', async ({ page }) => {
    const slug = process.env.E2E_STATUS_SLUG ?? 'demo-status'

    await page.goto(`/status/${slug}`)

    await expect(
        page.getByText(/^(All systems operational|Partial outage|Major outage|Degraded)/).first(),
    ).toBeVisible()

    const firstTimeline = page.getByRole('group', { name: /^Daily uptime of / }).first()
    const cells = firstTimeline.getByRole('button')
    await expect(cells).toHaveCount(90)

    await cells.last().focus()
    await expect(page.getByRole('tooltip')).toBeVisible()

    await expect(page.locator('link[rel="canonical"]')).toHaveCount(1)
})
