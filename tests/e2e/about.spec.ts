import { expect, test } from '@playwright/test';
import { trackErrors, waitForConnection } from './helpers';

test('the about dialog opens from the header and closes with Escape and the close button', async ({ page }) => {
    const errors = trackErrors(page);
    await page.goto('/');
    await waitForConnection(page);

    const open = page.getByRole('banner').getByRole('button', { name: 'About this project' });
    const dialog = page.getByRole('dialog', { name: 'About this chatbot' });
    await expect(dialog).toBeHidden();

    await open.click();
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText('A port of the Vercel AI Chatbot');
    await expect(dialog.getByRole('heading', { name: 'Author' })).toBeVisible();
    await expect(dialog).toContainText('Michael Bolli');
    await expect(dialog.getByRole('heading', { name: 'Technology' })).toBeVisible();
    await expect(dialog.getByRole('listitem').filter({ hasText: 'php-via' })).toBeVisible();
    await expect(dialog.getByRole('link', { name: 'Source code' })).toHaveAttribute('href', 'https://github.com/mbolli/ai-chatbot');

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();

    // Reopening proves Escape reset $_aboutOpen
    await open.click();
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Close' }).click();
    await expect(dialog).toBeHidden();

    await open.click();
    await expect(dialog).toBeVisible();
    expect(errors).toEqual([]);
});
