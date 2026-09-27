import type {
    FormStatus,
    TrainingLoad,
    WeekComparison as WeekComparisonPayload,
} from '@/types/inertia';

import MetricExplainer from '@/components/MetricExplainer';
import Eyebrow from '@/components/ui/Eyebrow';
import { cn } from '@/lib/cn';
import {
    formatSignedForm,
    formStatusMeaning,
    formStatusTone,
    formStatusWord,
} from '@/lib/formStatus';

import { Stat, StatDelta } from './Stat';

const CHIP_TONE: Record<string, string> = {
    positive: 'border-leaf/35 bg-leaf/18 text-leaf-ink',
    neutral: 'border-border bg-muted text-text-2',
    warning: 'border-ember/35 bg-ember/15 text-ember-ink',
};

const TILE = 'rounded-sm bg-secondary px-3 py-2.5';

function FormChip({ status }: Readonly<{ status: FormStatus }>) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border px-3 py-1 text-label-micro',
                CHIP_TONE[formStatusTone(status)],
            )}
        >
            {formStatusWord(status)}
        </span>
    );
}

/** Plain-primary-label + jargon-behind-a-ⓘ for the three side-by-side cost
 *  tiles (voice-and-tone's jargon-accessibility tier). */
function TileLabel({
    plain,
    metricKey,
}: Readonly<{ plain: string; metricKey: 'trimp' | 'monotony' | 'strain' }>) {
    return (
        <>
            {plain}
            <MetricExplainer metricKey={metricKey} size="xs" />
        </>
    );
}

type WeeklyRange = { low: number; high: number } | null;

/** "452 over your last 7 days. a steady week for you sits around 400 to
 *  500." — the number plus the athlete's own normal range, when there's
 *  enough history to size one (#1296). */
function trimpMeaning(weeklyTrimp: number | null, range: WeeklyRange): string {
    if (weeklyTrimp === null) {
        return 'heart rate and time, added up over your last 7 days.';
    }

    const value = Math.round(weeklyTrimp);
    if (range === null) {
        return `${value} over your last 7 days: heart rate and time, added up.`;
    }

    return `${value} over your last 7 days. a steady week for you sits around ${range.low} to ${range.high}.`;
}

/** Same shape as {@link trimpMeaning}: the number plus the athlete's own
 *  normal range for how varied the week was (#1296). */
function monotonyMeaning(monotony: number | null, range: WeeklyRange): string {
    if (monotony === null) {
        return "how varied your training's been. every run at the same effort pushes this up, so mix in an easy day to bring it down.";
    }

    const value = monotony.toFixed(1);
    if (range === null) {
        return `${value} over your last 7 days: how varied your training's been.`;
    }

    return `${value} over your last 7 days. a steady week for you sits around ${range.low.toFixed(1)} to ${range.high.toFixed(1)}.`;
}

/** Same shape as {@link trimpMeaning}: the number plus the athlete's own
 *  normal range for the week's total cost (#1296). */
function strainMeaning(strain: number | null, range: WeeklyRange): string {
    if (strain === null) {
        return "the week's effort multiplied by how varied it was, the total cost you're carrying.";
    }

    const value = Math.round(strain);
    if (range === null) {
        return `${value} over your last 7 days: the week's effort multiplied by how varied it was.`;
    }

    return `${value} over your last 7 days. a steady week for you sits around ${range.low} to ${range.high}.`;
}

/** Lowercase, matching UI chrome's own register — "wednesday", not "Wednesday". */
function todayWeekday(): string {
    return new Date()
        .toLocaleDateString('en-US', { weekday: 'long' })
        .toLowerCase();
}

interface WeekComparisonProps {
    weekComparison: WeekComparisonPayload;
    load: TrainingLoad | null;
    className?: string;
}

/**
 * "vs last week" carries both halves of the page's question: km leads as
 * the section's hero number with its delta beside it, runs is a plain stat
 * line beneath it, and TRIMP/monotony/strain — three side-by-side secondary
 * numbers — stay stat tiles per MASTER.md (direction A, #967; #1275).
 */
export default function WeekComparison({
    weekComparison,
    load,
    className,
}: Readonly<WeekComparisonProps>) {
    const weekday = todayWeekday();
    const { this_week_km, last_week_km, this_week_runs, last_week_runs } =
        weekComparison;

    return (
        <section className={className}>
            <Eyebrow as="h2" token="small" tone="ink-2">
                vs last week
            </Eyebrow>
            <p className="mt-1 text-xs text-text-3">
                through {weekday}, so it&apos;s the same slice of both weeks.
            </p>
            <Stat
                className="mt-3"
                label="km this week"
                value={this_week_km !== null ? this_week_km.toFixed(1) : '—'}
                delta={
                    this_week_km !== null && last_week_km !== null ? (
                        <StatDelta
                            value={this_week_km - last_week_km}
                            unit=" km"
                        />
                    ) : undefined
                }
                sub={
                    last_week_km !== null
                        ? `${last_week_km.toFixed(1)} km by ${weekday} last week`
                        : undefined
                }
            />
            <Stat
                className="mt-3"
                size="sm"
                label="runs this week"
                value={this_week_runs !== null ? String(this_week_runs) : '—'}
                delta={
                    this_week_runs !== null && last_week_runs !== null ? (
                        <StatDelta
                            value={this_week_runs - last_week_runs}
                            decimals={0}
                        />
                    ) : undefined
                }
                sub={
                    last_week_runs !== null
                        ? `${last_week_runs} by ${weekday} last week`
                        : undefined
                }
            />

            <hr className="my-4 border-dashed border-border" />

            {load === null ? (
                <p className="text-sm text-text-3">
                    not enough training history yet to read the cost side.
                </p>
            ) : (
                <div className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center gap-2.5">
                        <span className="text-label-micro text-text-3">
                            form
                        </span>
                        <FormChip status={load.form_status} />
                        <span className="font-mono text-xs text-text-3">
                            {formatSignedForm(load.form)}
                        </span>
                    </div>
                    <p className="text-sm leading-relaxed text-foreground">
                        {formStatusMeaning(load.form_status)}
                    </p>
                    <hr className="border-dashed border-border" />
                    <div className="grid grid-cols-3 gap-2">
                        <Stat
                            className={TILE}
                            size="sm"
                            label={<TileLabel plain="load" metricKey="trimp" />}
                            value={
                                load.weekly_trimp !== null
                                    ? String(Math.round(load.weekly_trimp))
                                    : '—'
                            }
                            sub={trimpMeaning(
                                load.weekly_trimp,
                                load.weekly_trimp_range,
                            )}
                        />
                        <Stat
                            className={TILE}
                            size="sm"
                            label={
                                <TileLabel
                                    plain="sameness"
                                    metricKey="monotony"
                                />
                            }
                            value={
                                load.monotony !== null
                                    ? load.monotony.toFixed(1)
                                    : '—'
                            }
                            sub={monotonyMeaning(
                                load.monotony,
                                load.monotony_range,
                            )}
                        />
                        <Stat
                            className={TILE}
                            size="sm"
                            label={
                                <TileLabel
                                    plain="total cost"
                                    metricKey="strain"
                                />
                            }
                            value={
                                load.strain !== null
                                    ? String(Math.round(load.strain))
                                    : '—'
                            }
                            sub={strainMeaning(load.strain, load.strain_range)}
                        />
                    </div>
                </div>
            )}
        </section>
    );
}
