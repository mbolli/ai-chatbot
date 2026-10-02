import { expect, test } from '@playwright/test';
import { lastAssistantMessage, messageInput, send, startChat, trackErrors, waitForConnection, waitForReply } from './helpers';

test('home page loads without console errors', async ({ page }) => {
    const errors = trackErrors(page);
    await page.goto('/');
    await waitForConnection(page);

    await expect(page.getByRole('heading', { name: 'How can I help you today?' })).toBeVisible();
    await expect(page.getByRole('main')).toBeVisible();
    await expect(page.getByRole('navigation', { name: 'Chat history' })).toBeVisible();
    await expect(messageInput(page)).toBeFocused();
    await expect(page.getByRole('button', { name: 'Send message' })).toBeDisabled();
    // A blank '' generating flag once kept the button disabled after typing
    await messageInput(page).pressSequentially('hi');
    await expect(page.getByRole('button', { name: 'Send message' })).toBeEnabled();
    expect(errors).toEqual([]);
});

test('a test command streams its reply into a new chat', async ({ page }) => {
    const errors = trackErrors(page);
    await startChat(page, '{help}');

    await expect(page.locator('#messages .message-user')).toHaveText(/\{help\}/);
    const reply = await waitForReply(page, 1);
    await expect(reply.getByRole('heading', { name: /Test Commands/ })).toBeVisible();
    await expect(messageInput(page)).toHaveValue('');
    expect(errors).toEqual([]);
});

test('follow-up messages append to the conversation', async ({ page }) => {
    await startChat(page, '{help}');
    await waitForReply(page, 1);

    await send(page, '{markdown}');
    const reply = await waitForReply(page, 2);
    await expect(page.locator('#messages .message-user')).toHaveCount(2);
    await expect(reply.getByRole('heading', { name: 'Code Block' })).toBeVisible();
    await expect(reply.getByRole('table')).toBeVisible();
});

test('code blocks scroll inside the message instead of widening the page', async ({ page }) => {
    await startChat(page, '{markdown}');
    const reply = await waitForReply(page, 1);

    await expect(reply.locator('pre')).toHaveCSS('overflow-x', 'auto');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    expect(overflow).toBeLessThanOrEqual(0);
});

test('empty input does not submit', async ({ page }) => {
    const errors = trackErrors(page);
    await startChat(page, '{help}');
    await waitForReply(page, 1);

    await messageInput(page).press('Enter');
    await expect(page.getByRole('button', { name: 'Stop generating' })).toBeHidden();
    await expect(page.locator('#messages .message-user')).toHaveCount(1);
    expect(errors).toEqual([]);
});

test('stop button ends a slow stream', async ({ page }) => {
    await startChat(page, '{help}');
    await waitForReply(page, 1);

    await send(page, '{slow}');
    const stop = page.getByRole('button', { name: 'Stop generating' });
    await expect(stop).toBeVisible();
    const text = lastAssistantMessage(page).locator('.message-text');
    await expect(text).toContainText('This is a');

    await stop.click();
    await expect(stop).toBeHidden();
    await expect(page.getByRole('button', { name: 'Send message' })).toBeVisible();

    // The final event appends the stop marker; after that the reply must not keep growing.
    await expect(text).toContainText('⏹');
    const stoppedAt = await text.innerText();
    await page.waitForTimeout(1500);
    await expect(text).toHaveText(stoppedAt);
    expect(stoppedAt).not.toContain('test the stop button now.');
});

test('the error command ends the stream and stores the error', async ({ page }) => {
    await startChat(page, '{error}');
    const reply = await waitForReply(page, 1);
    await expect(reply).toContainText('Starting response...');
    await expect(messageInput(page)).toBeEnabled();

    await page.reload();
    await expect(lastAssistantMessage(page)).toContainText('Simulated error');
});

test('the error command shows the error while streaming', async ({ page }) => {
    await startChat(page, '{error}');
    await waitForReply(page, 1);
    await expect(lastAssistantMessage(page)).toContainText('Simulated error', { timeout: 3000 });
});

test('streaming follows the reply until the user scrolls up', async ({ page }) => {
    const container = page.locator('#messages-container');
    const distanceFromBottom = () => container.evaluate((el) => el.scrollHeight - el.scrollTop - el.clientHeight);

    await startChat(page, '{longStream}');
    await waitForReply(page, 1);
    await expect.poll(distanceFromBottom).toBeLessThan(5);

    await send(page, '{longStream}');
    await expect(lastAssistantMessage(page).locator('.message-text')).toContainText('quick brown fox');
    await container.evaluate((el) => el.scrollTo({ top: 0 }));
    await page.waitForTimeout(1000);
    expect(await container.evaluate((el) => el.scrollTop)).toBe(0);

    await waitForReply(page, 2);
});

test('the auth dialog opens with focus in the form and closes on Escape', async ({ page }) => {
    await page.goto('/');
    await waitForConnection(page);

    await page.getByRole('button', { name: 'Save Chats' }).click();
    const dialog = page.getByRole('dialog', { name: 'Save Your Chats' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel('Email')).toBeFocused();

    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();

    await page.getByRole('button', { name: 'Already have an account? Sign in' }).click();
    await expect(page.getByRole('dialog', { name: 'Sign In' })).toBeVisible();
    await page.getByRole('dialog', { name: 'Sign In' }).getByRole('button', { name: 'Close' }).click();
    await expect(page.getByRole('dialog', { name: 'Sign In' })).toBeHidden();
});
