import StravaSyncButton from '@/components/StravaSyncButton';
import { type CatalogueEntry } from '@/lib/catalogue';

const RUNNING = { stravaPaused: false };

export default {
    name: 'StravaSyncButton',
    description:
        "The empty states' Strava call to action: connect when disconnected, reconnect when revoked, sync now when ready or after a failure, nothing while a sync runs. Sync now posts for real here.",
    usage: `<StravaSyncButton state={stravaSync.state} />`,
    states: [
        {
            name: 'disconnected',
            render: () => <StravaSyncButton state="disconnected" />,
        },
        {
            name: 'revoked',
            render: () => <StravaSyncButton state="revoked" />,
        },
        {
            name: 'ready',
            sharedProps: RUNNING,
            render: () => <StravaSyncButton state="ready" />,
        },
        {
            name: 'syncing',
            sharedProps: RUNNING,
            render: () => <StravaSyncButton state="syncing" />,
        },
        {
            name: 'ready, kill-switch off',
            sharedProps: { stravaPaused: true },
            render: () => <StravaSyncButton state="ready" />,
        },
    ],
} satisfies CatalogueEntry;
