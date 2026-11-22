import { expect, test } from '@playwright/test'
import AxeBuilder from '@axe-core/playwright'

const WCAG = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']

async function firstItemUrl(page: import('@playwright/test').Page, catalog: string): Promise<string> {
  await page.goto(catalog)
  return (await page.locator('article h3 a').first().getAttribute('href')) ?? catalog
}

test.describe('Barrierefreiheit (WCAG 2.2 AA)', () => {
  for (const url of ['/de/', '/de/katalog', '/de/laender', '/de/themen', '/de/kontakt', '/de/sprachen', '/de/merkliste', '/en/', '/en/catalog']) {
    test(`keine Verstöße auf ${url}`, async ({ page }) => {
      await page.goto(url)
      const result = await new AxeBuilder({ page }).withTags(WCAG).analyze()
      expect(result.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([])
    })
  }

  test('keine Verstöße auf einer Objektseite', async ({ page }) => {
    await page.goto(await firstItemUrl(page, '/de/katalog'))
    const result = await new AxeBuilder({ page }).withTags(WCAG).analyze()
    expect(result.violations.map((v) => v.id)).toEqual([])
  })
})

test.describe('Tastaturbedienung', () => {
  test('Katalog-Menü öffnet und schließt per Tastatur', async ({ page, isMobile }) => {
    test.skip(isMobile, 'Desktop-Navigation')
    await page.goto('/de/')
    const toggle = page.locator('[aria-controls="nav-catalog"]')
    await toggle.focus()
    await page.keyboard.press('Enter')
    await expect(toggle).toHaveAttribute('aria-expanded', 'true')
    await expect(page.locator('#nav-catalog')).toBeVisible()
    await page.keyboard.press('Tab')
    await expect(page.locator('#nav-catalog a').first()).toBeFocused()
    await page.keyboard.press('Escape')
    await expect(toggle).toHaveAttribute('aria-expanded', 'false')
    await expect(toggle).toBeFocused()
  })

  test('Mobilmenü hält den Fokus und schließt mit Esc', async ({ page, isMobile }) => {
    test.skip(!isMobile, 'Mobilmenü')
    await page.goto('/de/')
    const button = page.getByRole('button', { name: 'Menü' })
    await button.click()
    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()
    for (let i = 0; i < 25; i++) {
      await page.keyboard.press('Tab')
      expect(await dialog.evaluate((el) => el.contains(document.activeElement))).toBe(true)
    }
    await page.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(button).toBeFocused()
  })

  test('Galerie: Lightbox per Tastatur, Fokus zurück nach Esc', async ({ page }) => {
    await page.goto(await firstItemUrl(page, '/de/katalog'))
    const stage = page.locator('[data-island="item-gallery"] figure > button').first()
    await stage.focus()
    await page.keyboard.press('Enter')
    const dialog = page.getByRole('dialog')
    await expect(dialog).toBeVisible()
    await page.keyboard.press('Escape')
    await expect(dialog).toBeHidden()
    await expect(stage).toBeFocused()
  })
})
