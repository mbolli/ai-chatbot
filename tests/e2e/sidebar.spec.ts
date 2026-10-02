import { expect, test } from '@playwright/test';
import { messageInput, startChat, waitForConnection, waitForReply } from './helpers';

test('every chat started from the home page gets its own reply', async ({ page }) => {
    // Regression: the sidebar loop used to overwrite $chat, so later chats posted /generate to an older one.
    const first = await startChat(page, '{help}');
    await waitForReply(page, 1);
    const second = await startChat(page, '{markdown}');
    expect(second).not.toBe(first);
    const reply = await waitForReply(page, 1);
    await expect(reply.getByRole('heading', { name: 'Code Block' })).toBeVisible();
});

test('the new chat button opens an empty chat', async ({ page }) => {
    await startChat(page, '{help}');
    await waitForReply(page, 1);

    // Untitled chats in the history are also named "New Chat"
    await page.getByTitle('New Chat (Ctrl+K)').click();
    await page.waitForURL((url) => url.pathname === '/');
    await expect(page.getByRole('heading', { name: 'How can I help you today?' })).toBeVisible();
    await expect(messageInput(page)).toBeVisible();
});

test('chat history navigates between chats', async ({ page }) => {
    const first = await startChat(page, '{help}');
    await waitForReply(page, 1);
    const second = await startChat(page, '{markdown}');
    await waitForReply(page, 1);

    const history = page.getByRole('navigation', { name: 'Chat history' });
    await expect(history.getByRole('link')).toHaveCount(2);
    await expect(history.locator(`a[href="/chat/${second}"]`)).toHaveAttribute('aria-current', 'page');

    await history.locator(`a[href="/chat/${first}"]`).click();
    await page.waitForURL(`**/chat/${first}`);
    await waitForConnection(page);
    await expect(page.locator('#messages .message-user')).toHaveText(/\{help\}/);
    await expect(history.locator(`a[href="/chat/${first}"]`)).toHaveAttribute('aria-current', 'page');
});

test('deleting a chat removes it from the history', async ({ page }) => {
    const first = await startChat(page, '{help}');
    await waitForReply(page, 1);
    const second = await startChat(page, '{help}');
    await waitForReply(page, 1);

    page.once('dialog', (dialog) => dialog.accept());
    const history = page.getByRole('navigation', { name: 'Chat history' });
    await history.locator(`#chat-link-${first}`).getByRole('button', { name: /Delete chat/ }).click();
    await expect(history.locator(`#chat-link-${first}`)).toHaveCount(0);
    await expect(history.locator(`#chat-link-${second}`)).toBeVisible();

    // Deleting the open chat leaves it for the home page.
    page.once('dialog', (dialog) => dialog.accept());
    await history.locator(`#chat-link-${second}`).getByRole('button', { name: /Delete chat/ }).click();
    await page.waitForURL((url) => url.pathname === '/');
    await expect(page.getByText('No conversations yet')).toBeVisible();
});

test('the sidebar toggles from the header', async ({ page }) => {
    await page.goto('/');
    await waitForConnection(page);
    const history = page.getByRole('navigation', { name: 'Chat history' });
    const toggle = page.getByRole('button', { name: 'Toggle sidebar' });

    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await toggle.click();
    await expect(history).toBeHidden();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await toggle.click();
    await expect(history).toBeVisible();
});
