import AiCatchingUpBanner from '@/components/AiCatchingUpBanner';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'AiCatchingUpBanner',
    description:
        'App-shell reassurance while a synced run still waits on its narration. Driven by the shared aiCatchingUp prop; never shown alongside AiOutageBanner.',
    usage: `<AiCatchingUpBanner />`,
    states: [
        {
            name: 'catching up',
            sharedProps: { aiCatchingUp: true },
            render: () => <AiCatchingUpBanner />,
        },
        {
            name: 'caught up',
            sharedProps: { aiCatchingUp: false },
            render: () => <AiCatchingUpBanner />,
        },
    ],
} satisfies CatalogueEntry;
