import UnavailableNote from '@/components/temari/UnavailableNote';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'UnavailableNote',
    description:
        'The quiet pill a failed narration block shows instead of its text.',
    usage: `<UnavailableNote size="sm" />`,
    states: [
        { name: 'default', render: () => <UnavailableNote /> },
        { name: 'sm', render: () => <UnavailableNote size="sm" /> },
        {
            name: 'custom message',
            render: () => (
                <UnavailableNote message="this will be written once temari is back." />
            ),
        },
    ],
} satisfies CatalogueEntry;
