import type { SeasonSummaryWeek } from '@/lib/plan';

import SeasonHeaderCard from '@/components/plan/SeasonHeaderCard';
import { type CatalogueEntry } from '@/lib/catalogue';

function week(overrides: Partial<SeasonSummaryWeek>): SeasonSummaryWeek {
    return {
        week_start: '2026-10-05',
        phase: 'base',
        zone: 'block',
        type: 'history',
        planned_km: 30,
        actual_km: null,
        sessions: 4,
        ...overrides,
    };
}

const RACE_SEASON: SeasonSummaryWeek[] = [
    week({ week_start: '2026-09-21', phase: 'base' }),
    week({ week_start: '2026-09-28', phase: 'base' }),
    week({ week_start: '2026-10-05', phase: 'deload', type: 'current' }),
    week({ week_start: '2026-10-12', phase: 'build', type: 'lookahead' }),
    week({ week_start: '2026-10-19', phase: 'build', type: 'lookahead' }),
    week({ week_start: '2026-10-26', phase: 'peak', type: 'lookahead' }),
    week({ week_start: '2026-11-02', phase: 'taper', type: 'lookahead' }),
];

const GOALLESS_SEASON: SeasonSummaryWeek[] = [
    week({ week_start: '2026-09-28', zone: 'general', phase: 'build' }),
    week({
        week_start: '2026-10-05',
        zone: 'general',
        phase: 'build',
        type: 'current',
    }),
    week({
        week_start: '2026-10-12',
        zone: 'general',
        phase: 'deload',
        type: 'lookahead',
    }),
];

export default {
    name: 'SeasonHeaderCard',
    description:
        "Plan's season card: the current phase leads, then what's next and adherence over one phase ribbon. With no race it invites one; for a week after a recorded race it shows the result.",
    usage: `<SeasonHeaderCard weekIndex={4} totalWeeks={12} adherencePct={83} weeks={seasonSummary} raceCard={raceCard} narration={null} />`,
    states: [
        {
            name: 'race season',
            render: () => (
                <SeasonHeaderCard
                    weekIndex={3}
                    totalWeeks={7}
                    adherencePct={83}
                    weeks={RACE_SEASON}
                    raceCard={{
                        state: 'ahead',
                        race_id: 1,
                        race_date: '2026-11-08',
                        distance_m: 10_000,
                        goal_time_sec: 3600,
                        name: 'City 10K',
                        days_to_race: 28,
                        finish_time_sec: null,
                    }}
                    narration={null}
                />
            ),
        },
        {
            name: 'no race',
            render: () => (
                <SeasonHeaderCard
                    weekIndex={2}
                    totalWeeks={4}
                    adherencePct={83}
                    weeks={GOALLESS_SEASON}
                    raceCard={null}
                    narration={null}
                />
            ),
        },
        {
            name: 'result recorded this week',
            render: () => (
                <SeasonHeaderCard
                    weekIndex={2}
                    totalWeeks={4}
                    adherencePct={83}
                    weeks={GOALLESS_SEASON}
                    raceCard={{
                        state: 'result_recorded',
                        race_id: 1,
                        race_date: '2026-10-04',
                        distance_m: 10_000,
                        goal_time_sec: 3600,
                        name: 'City 10K',
                        days_to_race: null,
                        finish_time_sec: 3521,
                    }}
                    narration={null}
                />
            ),
        },
    ],
} satisfies CatalogueEntry;
