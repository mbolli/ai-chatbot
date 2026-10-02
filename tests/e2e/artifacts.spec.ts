import { expect, type Locator, test } from '@playwright/test';
import { artifactPanel, startChat, waitForReply } from './helpers';

const cases = [
    { command: '{artifact:code}', title: 'Hello World', content: (panel: Locator) => panel.locator('#artifact-code-content'), text: 'def greet' },
    { command: '{artifact:text}', title: 'Sample Text Document', content: (panel: Locator) => panel.getByRole('heading', { name: 'Welcome to the Artifact Panel' }), text: 'Welcome' },
    { command: '{artifact:sheet}', title: 'Sample Spreadsheet', content: (panel: Locator) => panel.getByRole('table'), text: 'Alice' },
];

test.beforeEach(async ({ page }) => {
    // The Python artifact lazy-loads Pyodide from a CDN; keep the suite offline.
    await page.route('https://cdn.jsdelivr.net/**', (route) => route.abort());
});

for (const { command, title, content, text } of cases) {
    test(`${command} opens, closes and reopens the artifact panel`, async ({ page }) => {
        await startChat(page, command);
        const reply = await waitForReply(page, 1);
        const panel = artifactPanel(page);

        await expect(panel).toBeVisible();
        await expect(page.locator('#artifact-title')).toHaveText(title);
        await expect(content(panel)).toContainText(text);
        const closeButton = page.getByRole('button', { name: 'Close artifact panel' });
        await expect(closeButton).toBeInViewport();

        await closeButton.click();
        await expect(panel).toBeHidden();

        await reply.getByRole('button', { name: `Open artifact: ${title}` }).click();
        await expect(panel).toBeVisible();
        await expect(content(panel)).toContainText(text);

        await page.keyboard.press('Escape');
        await expect(panel).toBeHidden();
    });
}
