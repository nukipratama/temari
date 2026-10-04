import { Link } from '@inertiajs/react';

import type {
    ActiveRace,
    RaceAmbition,
    RaceSupport,
    TrainingLoad,
} from '@/types/inertia';

import {
    TRIGGER_CLASS,
    TRIGGER_TONE,
} from '@/components/temari/AnalysisStatus';
import Eyebrow from '@/components/ui/Eyebrow';
import { Stat } from '@/components/ui/StatTile';
import { cn } from '@/lib/cn';
import { formStatusWord } from '@/lib/formStatus';
import {
    daysUntilId,
    formatDurationHMS,
    formatNaiveMonthDayId,
    formatPace,
    paceSecPerKm,
    useTodayIso,
} from '@/lib/pace';
import { ambitionNote } from '@/lib/raceGoal';

interface RaceComparisonProps {
    activeRace: ActiveRace | null;
    /** The active race's ambition and support, from the same presenter as /race. */
    outlook: { ambition: RaceAmbition; support: RaceSupport } | null;
    load: TrainingLoad | null;
    className?: string;
}

/**
 * "vs race day" closes the page: the countdown moves into the eyebrow
 * ("vs race day · N days out"), then the target beside the time recent runs
 * support, the same figure and band sentence /race shows, and load balance
 * today. Long-term load appears once on the page, in its own section; with
 * no race set this section is a line and a "set a race" link (#967, #1275).
 */
export default function RaceComparison({
    activeRace,
    outlook,
    load,
    className,
}: Readonly<RaceComparisonProps>) {
    const today = useTodayIso();

    if (activeRace === null) {
        return (
            <section className={className}>
                <Eyebrow as="h2" token="small" tone="ink-2">
                    vs race day
                </Eyebrow>
                <p className="mt-3 text-sm leading-relaxed text-text-2">
                    no race set, so there&apos;s nothing to count down to. set
                    one and temari compares your target with what your recent
                    runs support.
                </p>
                <Link
                    href="/race"
                    className={cn(TRIGGER_CLASS, TRIGGER_TONE, 'mt-3.5')}
                >
                    set a race
                </Link>
            </section>
        );
    }

    const supportedSec = outlook?.ambition.supported_time_sec ?? null;
    const daysOut = daysUntilId(activeRace.race_date, today);
    const weeksOut = Math.floor(daysOut / 7);
    const paceSec = paceSecPerKm(
        activeRace.goal_time_sec,
        activeRace.distance_m,
    );

    return (
        <section className={className}>
            <Eyebrow as="h2" token="small" tone="ink-2">
                vs race day · {daysOut} days out
            </Eyebrow>
            <p className="mt-1 text-xs text-text-3">
                {activeRace.name ?? 'your race'},{' '}
                {formatNaiveMonthDayId(activeRace.race_date)}
                {daysOut > 0
                    ? `. ${weeksOut} full week${weeksOut === 1 ? '' : 's'} of training left`
                    : '.'}
            </p>
            <Stat
                className="mt-3"
                size="sm"
                label="your target"
                value={formatDurationHMS(activeRace.goal_time_sec)}
                sub={`${(activeRace.distance_m / 1000).toFixed(1)} km at ${
                    paceSec !== null ? `${formatPace(paceSec)}/km` : '—'
                }`}
            />
            {supportedSec !== null && (
                <Stat
                    className="mt-3"
                    size="sm"
                    label="supported by your recent runs"
                    value={formatDurationHMS(supportedSec)}
                    sub={
                        outlook?.ambition.supported_pace_sec_per_km != null
                            ? `${formatPace(outlook.ambition.supported_pace_sec_per_km)}/km`
                            : undefined
                    }
                />
            )}
            {outlook !== null && (
                <p className="mt-3 text-xs leading-relaxed text-text-2">
                    {ambitionNote(outlook.ambition, outlook.support)}
                </p>
            )}
            <hr className="my-4 border-dashed border-border" />
            <Stat
                size="sm"
                label="load balance today"
                value={
                    load === null
                        ? '—'
                        : load.form_status === null
                          ? 'learning'
                          : formStatusWord(load.form_status)
                }
                sub="where you are now, not a race-week forecast"
            />
        </section>
    );
}
