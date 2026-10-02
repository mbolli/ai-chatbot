import { expect, test } from '@playwright/test';
import { artifactPanel, startChat, trackErrors, waitForConnection, waitForReply } from './helpers';

test.beforeEach(async ({ page }) => {
    await page.route('https://cdn.jsdelivr.net/**', (route) => route.abort());
});

const horizontalOverflow = (page: import('@playwright/test').Page) =>
    page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);

test('mobile: the sidebar starts closed and opens as a drawer', async ({ page }) => {
    const errors = trackErrors(page);
    await page.goto('/');
    await waitForConnection(page);
    const history = page.getByRole('navigation', { name: 'Chat history' });

    await expect(history).toBeHidden();
    await expect(page.getByRole('heading', { name: 'How can I help you today?' })).toBeInViewport();
    expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);

    await page.getByRole('button', { name: 'Toggle sidebar' }).click();
    await expect(history).toBeVisible();
    await page.getByRole('button', { name: 'Close sidebar' }).click();
    await expect(history).toBeHidden();
    expect(errors).toEqual([]);
});

test('mobile: chat and markdown fit the screen', async ({ page }) => {
    await startChat(page, '{markdown}');
    await waitForReply(page, 1);
    expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
    await expect(page.getByRole('navigation', { name: 'Chat history' })).toBeHidden();
});

test('mobile: the artifact panel fits the screen', async ({ page }) => {
    await startChat(page, '{markdown}');
    await waitForReply(page, 1);

    await page.getByRole('textbox', { name: 'Message', exact: true }).fill('{artifact:sheet}');
    await page.getByRole('button', { name: 'Send message' }).click();
    await waitForReply(page, 2);

    const panel = artifactPanel(page);
    await expect(panel).toBeVisible();
    const box = await panel.boundingBox();
    expect(box?.width).toBe(page.viewportSize()?.width);
    const close = page.getByRole('button', { name: 'Close artifact panel' });
    await expect(close).toBeInViewport();
    await close.click();
    await expect(panel).toBeHidden();
});
