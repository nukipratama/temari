import { Link } from '@inertiajs/react';
import { useMemo } from 'react';

import type { ActiveRace, TrainingLoad } from '@/types/inertia';

import { TRIGGER_CLASS, triggerTone } from '@/components/temari/AnalysisStatus';
import Eyebrow from '@/components/ui/Eyebrow';
import { cn } from '@/lib/cn';
import { formStatusWord } from '@/lib/formStatus';
import {
    daysUntilId,
    formatDurationHMS,
    formatNaiveMonthDayId,
    formatPace,
    paceSecPerKm,
} from '@/lib/pace';
import { ctlDaysAgo, ctlNow, ctlPeak } from '@/lib/trends';

import type { FitnessTrendPoint } from './panels/FitnessPanel';

import { Stat, StatDelta } from './Stat';

const DAYS_AGO = 30;

interface RaceComparisonProps {
    activeRace: ActiveRace | null;
    trend: ReadonlyArray<FitnessTrendPoint>;
    load: TrainingLoad | null;
    className?: string;
}

/**
 * "vs race day" closes the page: the countdown moves into the eyebrow
 * ("vs race day · N days out"), fitness now leads the section as its hero
 * number, and target time and form today are plain stat lines beneath it
 * — with only one secondary number at a time, per MASTER.md, neither pairs
 * into a tile. With no race set it becomes "vs your own year" (today's
 * fitness against the highest CTL in 365 days, the same hero/secondary
 * shape as MonthComparison) plus a "set a race" link (direction A, #967;
 * #1275).
 */
export default function RaceComparison({
    activeRace,
    trend,
    load,
    className,
}: Readonly<RaceComparisonProps>) {
    const now = useMemo(() => ctlNow(trend), [trend]);
    const peak = useMemo(() => ctlPeak(trend), [trend]);
    const monthAgo = useMemo(() => ctlDaysAgo(trend, DAYS_AGO), [trend]);

    if (activeRace === null) {
        return (
            <section className={className}>
                <Eyebrow as="h2" token="small" tone="ink-2">
                    vs your own year
                </Eyebrow>
                <p className="mt-1 text-xs text-text-3">
                    the highest your long-term load got in 365 days.
                </p>
                <p className="mt-3 text-sm leading-relaxed text-text-2">
                    no race set, so there&apos;s nothing to count down to. the
                    comparison falls back to your own year.
                </p>
                <Stat
                    className="mt-3.5"
                    label="today"
                    value={now !== null ? now.toFixed(1) : '—'}
                    delta={
                        now !== null && peak !== null ? (
                            <StatDelta value={now - peak} />
                        ) : undefined
                    }
                    sub="where you sit against it"
                />
                <Stat
                    className="mt-3"
                    size="sm"
                    label="best this year"
                    value={peak !== null ? peak.toFixed(1) : '—'}
                    sub="the highest your long-term load got in 365 days"
                />
                <Link
                    href="/race"
                    className={cn(TRIGGER_CLASS, triggerTone(false), 'mt-3.5')}
                >
                    set a race
                </Link>
            </section>
        );
    }

    const daysOut = daysUntilId(activeRace.race_date);
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
                label="fitness now"
                value={now !== null ? now.toFixed(1) : '—'}
                delta={
                    now !== null && monthAgo !== null ? (
                        <StatDelta value={now - monthAgo} />
                    ) : undefined
                }
                sub={
                    monthAgo !== null
                        ? `${monthAgo.toFixed(1)} a month ago`
                        : undefined
                }
            />
            <Stat
                className="mt-3"
                size="sm"
                label="target"
                value={formatDurationHMS(activeRace.goal_time_sec)}
                sub={`${(activeRace.distance_m / 1000).toFixed(1)} km at ${
                    paceSec !== null ? `${formatPace(paceSec)}/km` : '—'
                }`}
            />
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
