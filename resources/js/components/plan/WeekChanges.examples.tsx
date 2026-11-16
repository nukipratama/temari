import { planDay } from '@/components/catalogue/fixtures';
import WeekChanges from '@/components/plan/WeekChanges';
import { type CatalogueEntry } from '@/lib/catalogue';

const DAYS = [
    planDay({
        id: 1,
        date: '2026-11-17',
        distance_km: 7,
        asked_km: 8,
    }),
    planDay({
        id: 2,
        date: '2026-11-19',
        dropped_from: { session_type: 'tempo', time_trial: false },
        override: {
            kind: 'weekly_drop',
            state: 'locked',
            blocker: '2 weeks before race week',
            reason: 'one less quality session.',
        },
    }),
    planDay({
        id: 3,
        date: '2026-11-21',
        session_type: 'long',
        prescription_budget: {
            cap_minutes: 60,
            left_minutes: 0,
            needed_minutes: 12,
        },
        prescription_reason:
            'easy because the weekly hard-minute budget is spent',
        override: {
            kind: 'budget_drop',
            state: 'offered',
            label: 'bring it back',
            reason: 'this week has 0 of its 60 hard minutes left.',
        },
    }),
];

export default {
    name: 'WeekChanges',
    description:
        'Every day the plan changed this week, as was → now with whether it can come back or the rule that locks it. Each row opens its day.',
    usage: `<WeekChanges days={days} onSelect={selectDate} />`,
    states: [
        {
            name: 'trim, locked drop, offered bring-back',
            render: () => <WeekChanges days={DAYS} onSelect={() => {}} />,
        },
    ],
} satisfies CatalogueEntry;
