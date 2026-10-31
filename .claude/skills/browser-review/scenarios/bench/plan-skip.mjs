export const route = '/plan';

const SKIP = 'button:text-is("skip")';
const RESTORE = 'button:text-is("restore")';
const TOAST = '[role="status"][aria-live="polite"] > *';

async function selectEditableDay(page) {
    const days = page.locator('[role="tab"]');
    for (let i = (await days.count()) - 1; i >= 0; i--) {
        await days.nth(i).click();
        if (await page.locator(`${SKIP}, ${RESTORE}`).first().isVisible()) return;
    }
    throw new Error('no day in the current week can be skipped');
}

export default async ({ page, measure }) => {
    for (const _ of [1, 2]) {
        await selectEditableDay(page);
        const skipped = await page.locator(RESTORE).first().isVisible();
        await measure(skipped ? 'restore' : 'skip', skipped ? RESTORE : SKIP, { until: TOAST });
        await page.locator(TOAST).first().waitFor({ state: 'detached', timeout: 15000 });
    }
};
