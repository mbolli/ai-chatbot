import { expect, type Response, test } from '@playwright/test';
import { startChat, waitForConnection, waitForReply } from './helpers';

const isAction = (res: Response, name: string) => res.request().method() === 'POST' && new URL(res.url()).pathname === `/_action/${name}` && res.ok();

test('the model choice is stored per chat', async ({ page }) => {
    const selector = page.getByRole('combobox', { name: 'Select AI model' });

    const first = await startChat(page, '{help}');
    await waitForReply(page, 1);
    const defaultModel = await selector.inputValue();
    const options = await selector.locator('option:not([disabled])').evaluateAll((els) => els.map((el) => (el as HTMLOptionElement).value));
    const otherModel = options.find((value) => value !== defaultModel);
    expect(otherModel, 'needs a second selectable model').toBeDefined();

    const saved = page.waitForResponse((res) => isAction(res, 'model'));
    await selector.selectOption(otherModel as string);
    await saved;

    await page.reload();
    await waitForConnection(page);
    await expect(selector).toHaveValue(otherModel as string);

    await startChat(page, '{help}');
    await waitForReply(page, 1);
    await expect(selector).toHaveValue(defaultModel);

    await page.goto(`/chat/${first}`);
    await expect(selector).toHaveValue(otherModel as string);
});

test('the chat visibility is stored', async ({ page }) => {
    await startChat(page, '{help}');
    await waitForReply(page, 1);
    const visibility = page.getByRole('combobox', { name: 'Chat visibility' });
    await expect(visibility).toHaveValue('private');

    const saved = page.waitForResponse((res) => res.url().includes('/_action/visibility') && res.ok());
    await visibility.selectOption('public');
    await saved;

    await page.reload();
    await expect(visibility).toHaveValue('public');
});
