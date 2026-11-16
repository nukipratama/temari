import NextWeekCard, {
    type NextWeekOutlook,
} from '@/components/plan/NextWeekCard';
import { type CatalogueEntry } from '@/lib/catalogue';

const SET: NextWeekOutlook = {
    state: 'set',
    egregious: false,
    hot_days: [
        { date: '2026-11-17', session_type: 'easy', over_cap_minutes: 9 },
        { date: '2026-11-21', session_type: 'long', over_cap_minutes: 14 },
    ],
    drop: { date: '2026-11-26', session_type: 'tempo', time_trial: false },
    blocker: null,
};

export default {
    name: 'NextWeekCard',
    description:
        "Next week as this week's hot easy days leave it so far: the session it loses or would lose, the days that counted, and whether it can be brought back.",
    usage: `<NextWeekCard outlook={nextWeek} />`,
    states: [
        {
            name: 'set, can bring back',
            render: () => <NextWeekCard outlook={SET} />,
        },
        {
            name: 'set, locked near the race',
            render: () => (
                <NextWeekCard
                    outlook={{ ...SET, blocker: '2 weeks before race week' }}
                />
            ),
        },
        {
            name: 'at risk after one hot day',
            render: () => (
                <NextWeekCard
                    outlook={{
                        ...SET,
                        state: 'one_more',
                        hot_days: SET.hot_days.slice(0, 1),
                    }}
                />
            ),
        },
    ],
} satisfies CatalogueEntry;
