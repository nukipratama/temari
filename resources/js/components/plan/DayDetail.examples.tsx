import type { EditablePlanDay, PlanDay } from '@/lib/plan';

import { planDay } from '@/components/catalogue/fixtures';
import DayDetail, { DayHeadline } from '@/components/plan/DayDetail';
import { type CatalogueEntry } from '@/lib/catalogue';

function editable(day: PlanDay): EditablePlanDay {
    return {
        ...day,
        actions: { move: true, skip: true, restore: false },
        move_targets: [],
        move_warnings: {},
        made_up_on: null,
    };
}

const dropped = (override: PlanDay['override']) =>
    editable(
        planDay({
            date: '2026-11-19',
            distance_km: 8,
            asked_km: 8,
            dropped_from: { session_type: 'tempo', time_trial: false },
            override,
        }),
    );

function Panel({ day }: Readonly<{ day: EditablePlanDay }>) {
    return (
        <div className="max-w-sm">
            <DayHeadline day={day} />
            <div className="mt-3">
                <DayDetail
                    day={day}
                    weekDays={[day]}
                    onMove={() => {}}
                    onSkip={() => {}}
                    onUnskip={() => {}}
                />
            </div>
        </div>
    );
}

const REASON =
    'your easy runs ran past their heart-rate cap, so this week carries one less quality session.';

export default {
    name: 'DayDetail',
    description:
        'A changed day leads with was → now, one line of why, and its bring-back or the rule that locks it; the full reason sits behind "why?", move and skip behind "more actions".',
    usage: `<DayDetail day={day} weekDays={days} onMove={move} onSkip={skip} onUnskip={unskip} />`,
    states: [
        {
            name: 'dropped, can bring back',
            render: () => (
                <Panel
                    day={dropped({
                        kind: 'weekly_drop',
                        state: 'offered',
                        label: "bring back thursday's tempo",
                        reason: REASON,
                    })}
                />
            ),
        },
        {
            name: 'dropped, locked near the race',
            render: () => (
                <Panel
                    day={dropped({
                        kind: 'weekly_drop',
                        state: 'locked',
                        blocker: '2 weeks before race week',
                        reason: `${REASON} the weeks closest to a race stay as the plan set them.`,
                    })}
                />
            ),
        },
        {
            name: 'dropped, day passed',
            render: () => <Panel day={dropped(null)} />,
        },
    ],
} satisfies CatalogueEntry;
