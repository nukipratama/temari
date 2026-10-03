import BackLink from '@/components/ui/BackLink';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'BackLink',
    description:
        'The one back or breadcrumb link: an arrow and a mono label, so every way back reads the same.',
    usage: `<BackLink href="/history">history · log</BackLink>`,
    states: [
        {
            name: 'muted',
            render: () => <BackLink href="#">history · log</BackLink>,
        },
        {
            name: 'accent',
            render: () => (
                <BackLink href="#" tone="accent">
                    back to today
                </BackLink>
            ),
        },
    ],
} satisfies CatalogueEntry;
