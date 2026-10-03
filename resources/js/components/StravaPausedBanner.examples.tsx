import StravaPausedBanner from '@/components/StravaPausedBanner';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'StravaPausedBanner',
    description:
        'The one explanation while the Strava kill-switch is off and every manual sync affordance is hidden. Driven by the shared stravaPaused prop.',
    usage: `<StravaPausedBanner />`,
    states: [
        {
            name: 'paused',
            sharedProps: { stravaPaused: true },
            render: () => <StravaPausedBanner />,
        },
        {
            name: 'running',
            sharedProps: { stravaPaused: false },
            render: () => <StravaPausedBanner />,
        },
    ],
} satisfies CatalogueEntry;
