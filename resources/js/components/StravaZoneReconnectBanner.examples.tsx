import { RUNNER } from '@/components/catalogue/fixtures';
import StravaZoneReconnectBanner from '@/components/StravaZoneReconnectBanner';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'StravaZoneReconnectBanner',
    description:
        'A reconnect nudge for a Strava connection granted before the profile scope existed, so HR zones cannot sync. Dismissed for the browsing session only.',
    usage: `<StravaZoneReconnectBanner />`,
    states: [
        {
            name: 'scope missing',
            sharedProps: {
                stravaZoneScopeMissing: true,
                auth: { user: RUNNER },
            },
            render: () => <StravaZoneReconnectBanner />,
        },
        {
            name: 'scope granted',
            sharedProps: { stravaZoneScopeMissing: false },
            render: () => <StravaZoneReconnectBanner />,
        },
    ],
} satisfies CatalogueEntry;
