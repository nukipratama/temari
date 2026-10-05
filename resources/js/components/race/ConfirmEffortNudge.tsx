import { Link } from '@inertiajs/react';

import type { RaceAmbition } from '@/types/inertia';

import { cn } from '@/lib/cn';
import { formatNaiveMonthDayId } from '@/lib/pace';
import { raceDistanceLabel } from '@/lib/raceGoal';
import { activityUrl } from '@/lib/routes';

interface ConfirmEffortNudgeProps {
    ambition: RaceAmbition;
    className?: string;
}

/** Asks for a confirmed recent hard effort when the supported time rests only on unconfirmed records, or has gone stale. */
export default function ConfirmEffortNudge({
    ambition,
    className,
}: Readonly<ConfirmEffortNudgeProps>) {
    const basis = ambition.basis;
    if (
        !ambition.confirm_nudge ||
        basis === null ||
        ambition.supported_time_sec === null
    ) {
        return null;
    }

    const effort = `your ${raceDistanceLabel(basis.distance_m)} on ${formatNaiveMonthDayId(basis.performed_on)}`;
    const text =
        ambition.evidence_confidence === 'stale'
            ? `${effort} is the newest hard effort i have, and it's over 16 weeks old. a race or time trial would bring this up to date.`
            : `this rests on ${effort}, which i picked up from Strava. you haven't confirmed it as a hard effort yet.`;

    return (
        <p className={cn('text-xs leading-relaxed text-text-2', className)}>
            {text}
            {basis.activity_id !== null && (
                <>
                    {' '}
                    <Link
                        href={activityUrl({ activity_id: basis.activity_id })}
                        className="focus-ring font-semibold text-horizon-ink"
                    >
                        open the run
                    </Link>
                </>
            )}
        </p>
    );
}
