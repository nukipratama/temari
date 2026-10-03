import AiOutageBanner from '@/components/AiOutageBanner';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'AiOutageBanner',
    description:
        'Shown above narrated content while narration is globally paused, so a quiet pipeline reads as Temari resting. Driven by the shared aiPaused prop.',
    usage: `<AiOutageBanner />`,
    states: [
        {
            name: 'paused',
            sharedProps: { aiPaused: true },
            render: () => <AiOutageBanner />,
        },
        {
            name: 'running',
            sharedProps: { aiPaused: false },
            render: () => <AiOutageBanner />,
        },
    ],
} satisfies CatalogueEntry;
