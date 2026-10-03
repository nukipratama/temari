import HistoryHeader from '@/components/history/HistoryHeader';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'HistoryHeader',
    description:
        "History's top fold: the eyebrow with the lifetime count, the voice headline, and the feed and calendar switch.",
    usage: `<HistoryHeader active="feed" activityCount={totalActivities} />`,
    states: [
        {
            name: 'feed',
            render: () => <HistoryHeader active="feed" activityCount={126} />,
        },
        {
            name: 'calendar, count unknown',
            render: () => <HistoryHeader active="calendar" />,
        },
    ],
} satisfies CatalogueEntry;
