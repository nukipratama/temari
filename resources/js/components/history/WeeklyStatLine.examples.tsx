import { weeklySnapshot } from '@/components/catalogue/fixtures';
import WeeklyStatLine from '@/components/history/WeeklyStatLine';
import { type CatalogueEntry } from '@/lib/catalogue';

export default {
    name: 'WeeklyStatLine',
    description:
        "A week's numbers as one mono line under its narration. Tapping a word explains it inline; a metric past its alarm opens by default.",
    usage: `<WeeklyStatLine snapshot={week} />`,
    states: [
        {
            name: 'every metric, none flagged',
            render: () => <WeeklyStatLine snapshot={weeklySnapshot()} />,
        },
        {
            name: 'flagged variety and drift',
            render: () => (
                <WeeklyStatLine
                    snapshot={weeklySnapshot({
                        monotony: 1.8,
                        avg_decoupling_v2: 13.4,
                    })}
                />
            ),
        },
        {
            name: 'no heart rate',
            render: () => (
                <WeeklyStatLine
                    snapshot={weeklySnapshot({
                        atl_7d: null,
                        monotony: null,
                        avg_decoupling_v2: null,
                        form_status: null,
                    })}
                />
            ),
        },
    ],
} satisfies CatalogueEntry;
