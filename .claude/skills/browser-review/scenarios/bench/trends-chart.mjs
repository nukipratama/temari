export const route = '/';

const chart = '[aria-label^="Long-term load"] canvas';

export default async ({ page, measure }) => {
    const scrolled = page
        .waitForURL('**/trends')
        .then(() => page.waitForTimeout(100))
        .then(() => page.mouse.wheel(0, 800));
    await measure('tab Trends', 'nav[aria-label="Primary"] a:has-text("Trends")', { until: chart });
    await scrolled;
};
