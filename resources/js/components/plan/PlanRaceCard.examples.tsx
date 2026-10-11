import type { PlanRaceCard as PlanRaceCardPayload } from '@/types/inertia';

import PlanRaceCard from '@/components/plan/PlanRaceCard';
import { type CatalogueEntry } from '@/lib/catalogue';

const TODAY = '2026-10-11';

function card(overrides: Partial<PlanRaceCardPayload>): PlanRaceCardPayload {
    return {
        state: 'ahead',
        race_id: 1,
        race_date: '2026-12-06',
        distance_m: 10_000,
        goal_time_sec: 3600,
        name: 'City 10K',
        days_to_race: 56,
        finish_time_sec: null,
        ...overrides,
    };
}

const CANDIDATE = {
    activity_id: 1,
    name: 'Morning Run',
    distance_m: 10_120,
    elapsed_time_sec: 3521,
    started_at: '2026-12-06T06:30:00',
};

export default {
    name: 'PlanRaceCard',
    description:
        'The race on Plan, louder as race day nears: a quiet countdown, a citrus race week, a lime race-day hero, then an inline confirm once it has passed. Renders nothing with no race or once the result is recorded (the season card owns those).',
    usage: `<PlanRaceCard card={raceCard} today={today} supportedTimeSec={raceSupportedTimeSec} candidate={raceOutcomeCandidate} />`,
    states: [
        {
            name: 'race ahead',
            render: () => (
                <PlanRaceCard
                    card={card({})}
                    today={TODAY}
                    supportedTimeSec={3730}
                />
            ),
        },
        {
            name: 'race ahead, supported time loading',
            render: () => <PlanRaceCard card={card({})} today={TODAY} />,
        },
        {
            name: 'race week',
            render: () => (
                <PlanRaceCard
                    card={card({
                        state: 'week',
                        race_date: '2026-10-17',
                        days_to_race: 6,
                    })}
                    today={TODAY}
                />
            ),
        },
        {
            name: 'race day',
            render: () => (
                <PlanRaceCard
                    card={card({
                        state: 'day',
                        race_date: TODAY,
                        days_to_race: 0,
                    })}
                    today={TODAY}
                />
            ),
        },
        {
            name: 'result pending, matched run',
            render: () => (
                <PlanRaceCard
                    card={card({
                        state: 'result_pending',
                        race_date: '2026-10-04',
                        days_to_race: null,
                    })}
                    today={TODAY}
                    candidate={CANDIDATE}
                />
            ),
        },
        {
            name: 'result pending, no matched run',
            render: () => (
                <PlanRaceCard
                    card={card({
                        state: 'result_pending',
                        race_date: '2026-10-04',
                        days_to_race: null,
                    })}
                    today={TODAY}
                    candidate={null}
                />
            ),
        },
    ],
} satisfies CatalogueEntry;
