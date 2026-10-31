export const route = '/';

const figure = '[aria-label^="Long-term load"]';

export default async ({ page, measure }) => {
    await page.evaluate((selector) => {
        const observer = new MutationObserver(() => {
            const target = document.querySelector(selector);
            if (!target) return;
            target.scrollIntoView({ block: 'center' });
            observer.disconnect();
        });
        observer.observe(document.documentElement, { subtree: true, childList: true });
    }, figure);
    await measure('tab Trends', 'nav[aria-label="Primary"] a:has-text("Trends")', { until: `${figure} canvas` });
};
