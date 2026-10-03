import LastOpen from '@/components/narration/LastOpen';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'LastOpen',
    description:
        "When the athlete last opened the app, and whether that leaves them outside scheduled narration's window.",
    usage: `<LastOpen lastSeenAt={athlete.last_seen_at} away={athlete.away} isDemo={athlete.is_demo} />`,
    states: [
        {
            name: 'recently opened',
            render: () => (
                <LastOpen
                    lastSeenAt="2026-10-02T07:40:00+07:00"
                    away={false}
                    isDemo={false}
                />
            ),
        },
        {
            name: 'away',
            render: () => (
                <LastOpen
                    lastSeenAt="2026-09-12T19:05:00+07:00"
                    away
                    isDemo={false}
                />
            ),
        },
        {
            name: 'never opened',
            render: () => (
                <LastOpen lastSeenAt={null} away={false} isDemo={false} />
            ),
        },
        {
            name: 'demo',
            render: () => <LastOpen lastSeenAt={null} away={false} isDemo />,
        },
    ],
} satisfies CatalogueEntry;
