export const route = '/history';

export default async ({ measure }) => {
    await measure('open run', 'a[data-run-morph]');
    await measure('back', 'header a[aria-label^="Back to"]');
};
