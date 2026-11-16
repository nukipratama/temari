import { Lock } from 'lucide-react';

import { DeltaPair } from '@/components/plan/DeltaPair';
import Chip from '@/components/ui/Chip';
import { Icon } from '@/components/ui/Icon';
import { SESSION_TYPE_LABEL, TIME_TRIAL_LABEL, weekdayLabel } from '@/lib/plan';

export type NextWeekOutlook = {
    state: 'set' | 'one_more';
    egregious: boolean;
    hot_days: {
        date: string;
        session_type: string;
        over_cap_minutes: number;
    }[];
    drop: { date: string; session_type: string; time_trial: boolean };
    blocker: string | null;
};

function typeLabel(type: string): string {
    return SESSION_TYPE_LABEL[type] ?? type;
}

/**
 * Next week as this week's hot easy days leave it so far: the session it
 * loses, or would lose to one more, the days that counted, and whether it can
 * be brought back or which rule keeps it.
 */
export default function NextWeekCard({
    outlook,
}: Readonly<{ outlook: NextWeekOutlook }>) {
    const { drop, blocker, state } = outlook;
    const hotDays = outlook.hot_days
        .map(
            (day) =>
                `${weekdayLabel(day.date).toLowerCase()} ${typeLabel(day.session_type)} ${day.over_cap_minutes} min`,
        )
        .join(', ');

    return (
        <section
            aria-label="next week, as of now"
            className="rounded-md bg-muted pad-panel"
        >
            <p className="text-label-micro text-text-3">
                next week · as of now
            </p>
            <p className="mt-1 flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm">
                <span className="text-label-micro text-text-2">
                    {weekdayLabel(drop.date).toLowerCase()}
                </span>
                <DeltaPair
                    from={
                        drop.time_trial
                            ? TIME_TRIAL_LABEL
                            : typeLabel(drop.session_type)
                    }
                    to="easy"
                    direction="neutral"
                />
                {state === 'one_more' && <Chip>at risk</Chip>}
            </p>
            <p className="mt-1 text-xs leading-relaxed text-text-2">
                over your heart-rate cap: {hotDays}.{' '}
                {state === 'set'
                    ? "this week's runs can't undo it."
                    : 'one more easy day over it this week makes it so.'}
            </p>
            {blocker !== null ? (
                <p className="mt-2 inline-flex items-center gap-1.5 rounded-sm bg-card px-2.5 py-1 text-xs text-text-2">
                    <Icon icon={Lock} className="size-3" aria-hidden />
                    can't bring back: {blocker}
                </p>
            ) : (
                <p className="mt-2 text-xs text-text-2">
                    {state === 'set' ? 'you can' : 'you could'} bring it back on
                    the day.
                </p>
            )}
        </section>
    );
}
