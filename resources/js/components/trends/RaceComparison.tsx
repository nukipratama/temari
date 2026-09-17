import { Link } from '@inertiajs/react';

import type { ActiveRace, TrainingLoad } from '@/types/inertia';

import Card from '@/components/ui/LegacyCard';
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
 * "vs race day" closes the page: days out, the target time and pace, and
 * where fitness sits today. With no race set it becomes "vs your own year"
 * (today against the highest CTL in 365 days) plus a "set a race" link
 * (direction A, #967).
 */
export default function RaceComparison({
    activeRace,
    trend,
    load,
    className,
}: Readonly<RaceComparisonProps>) {
    const now = ctlNow(trend);
    const peak = ctlPeak(trend);

    if (activeRace === null) {
        return (
            <section className={className}>
                <h2 className="font-serif text-headline-sm text-foreground">
                    vs your own year
                </h2>
                <p className="mt-1 text-xs text-text-3">
                    the highest your fitness got in 365 days.
                </p>
                <Card className="mt-2.5">
                    <p className="text-sm leading-relaxed text-text-2">
                        no race set, so there&apos;s nothing to count down to.
                        the comparison falls back to your own year.
                    </p>
                    <div className="mt-3.5 grid grid-cols-2 gap-3">
                        <Stat
                            label="best this year"
                            value={peak !== null ? peak.toFixed(1) : '—'}
                            sub="the highest fitness got in 365 days"
                        />
                        <Stat
                            label="today"
                            value={now !== null ? now.toFixed(1) : '—'}
                            delta={
                                now !== null && peak !== null ? (
                                    <StatDelta value={now - peak} />
                                ) : undefined
                            }
                            sub="where you sit against it"
                        />
                    </div>
                    <Link
                        href="/race"
                        className="focus-ring pressable pad-chip text-label-micro mt-3.5 inline-flex items-center gap-1 rounded-full bg-muted text-foreground transition-colors hover:bg-accent"
                    >
                        set a race
                    </Link>
                </Card>
            </section>
        );
    }

    const monthAgo = ctlDaysAgo(trend, DAYS_AGO);
    const daysOut = daysUntilId(activeRace.race_date);
    const weeksOut = Math.floor(daysOut / 7);
    const paceSec = paceSecPerKm(
        activeRace.goal_time_sec,
        activeRace.distance_m,
    );

    return (
        <section className={className}>
            <h2 className="font-serif text-headline-sm text-foreground">
                vs race day
            </h2>
            <p className="mt-1 text-xs text-text-3">
                {activeRace.name ?? 'your race'},{' '}
                {formatNaiveMonthDayId(activeRace.race_date)}.
            </p>
            <Card className="mt-2.5">
                <div className="grid grid-cols-2 gap-3">
                    <Stat
                        label="days out"
                        value={String(daysOut)}
                        sub={
                            daysOut > 0
                                ? `${weeksOut} full week${weeksOut === 1 ? '' : 's'} of training left`
                                : undefined
                        }
                    />
                    <Stat
                        label="target"
                        value={formatDurationHMS(activeRace.goal_time_sec)}
                        sub={`${(activeRace.distance_m / 1000).toFixed(1)} km at ${
                            paceSec !== null ? `${formatPace(paceSec)}/km` : '—'
                        }`}
                    />
                </div>
                <hr className="my-4 border-border" />
                <div className="grid grid-cols-2 gap-3">
                    <Stat
                        size="sm"
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
                        size="sm"
                        label="form today"
                        value={
                            load !== null
                                ? formStatusWord(load.form_status)
                                : '—'
                        }
                        sub="where you are now, not a race-week forecast"
                    />
                </div>
            </Card>
        </section>
    );
}
