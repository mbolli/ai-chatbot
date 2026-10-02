import { expect, type Locator, type Page } from '@playwright/test';

/** Collects console errors and uncaught exceptions for the lifetime of the page. */
export function trackErrors(page: Page): string[] {
    const errors: string[] = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error') errors.push(msg.text());
    });
    page.on('pageerror', (err) => errors.push(err.message));
    return errors;
}

/** Waits until the /updates SSE stream is subscribed; events emitted before that are lost. */
export async function waitForConnection(page: Page): Promise<void> {
    await expect(page.locator('#connection-status[data-connected="true"]')).toBeAttached();
}

export function messageInput(page: Page): Locator {
    return page.getByRole('textbox', { name: 'Message', exact: true });
}

export async function send(page: Page, text: string): Promise<void> {
    await messageInput(page).fill(text);
    await messageInput(page).press('Enter');
}

/** Starts a new chat from the home page and waits for the redirect to it. */
export async function startChat(page: Page, command: string): Promise<string> {
    await page.goto('/');
    await waitForConnection(page);
    await send(page, command);
    await page.waitForURL(/\/chat\/[a-f0-9-]+$/);
    await waitForConnection(page);
    return new URL(page.url()).pathname.split('/').pop() as string;
}

export function lastAssistantMessage(page: Page): Locator {
    return page.locator('#messages .message-assistant').last();
}

/** A finished assistant message has its action bar (copy, votes) rendered. */
export async function waitForReply(page: Page, count?: number): Promise<Locator> {
    const messages = page.locator('#messages .message-assistant');
    if (count !== undefined) await expect(messages).toHaveCount(count);
    const reply = messages.last();
    await expect(reply.getByRole('button', { name: 'Copy message' })).toBeAttached();
    await expect(page.getByRole('button', { name: 'Stop generating' })).toBeHidden();
    return reply;
}

export function artifactPanel(page: Page): Locator {
    // artifact-content.php replaces this element without its role and label, so match by id.
    return page.locator('#artifact-content');
}
