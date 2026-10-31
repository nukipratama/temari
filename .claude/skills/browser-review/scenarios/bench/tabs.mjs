export const route = '/';

const tab = (name) => `nav[aria-label="Primary"] a:has-text("${name}")`;

export default async ({ measure }) => {
    for (const name of ['Plan', 'Trends', 'History', 'Today']) {
        await measure(`tab ${name}`, tab(name));
    }
};
