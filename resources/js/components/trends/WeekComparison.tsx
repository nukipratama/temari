import type {
    FormStatus,
    TrainingLoad,
    WeekComparison as WeekComparisonPayload,
} from '@/types/inertia';

import MetricExplainer from '@/components/MetricExplainer';
import Chip from '@/components/ui/Chip';
import Eyebrow from '@/components/ui/Eyebrow';
import StatTile, { Stat, StatDelta } from '@/components/ui/StatTile';
import {
    formatSignedForm,
    formStatusMeaning,
    formStatusTone,
    formStatusWord,
} from '@/lib/formStatus';
import { ID_MONTH_SHORT, formatShortDateId } from '@/lib/pace';

const DAY_MS = 86_400_000;

function warmUpLine(knownFrom: string, asOf: string): string {
    const daysToGo = Math.max(
        1,
        Math.round((Date.parse(knownFrom) - Date.parse(asOf)) / DAY_MS),
    );

    return `still learning your load · ${daysToGo} ${daysToGo === 1 ? 'day' : 'days'} to go`;
}

function FormChip({ status }: Readonly<{ status: FormStatus }>) {
    return <Chip tone={formStatusTone(status)}>{formStatusWord(status)}</Chip>;
}

/** Plain-primary-label + jargon-behind-a-ⓘ for the three side-by-side cost
 *  tiles (voice-and-tone's jargon-accessibility tier). */
function TileLabel({
    plain,
    metricKey,
}: Readonly<{ plain: string; metricKey: 'trimp' | 'monotony' | 'strain' }>) {
    return (
        <span className="block min-h-[2lh]">
            {plain}
            <MetricExplainer metricKey={metricKey} size="xs" />
        </span>
    );
}

type WeeklyRange = { low: number; high: number } | null;

/** "over your last 7 days. a steady week for you sits around 400 to 500.":
 *  the window plus the athlete's own normal range, when there's enough
 *  history to size one. */
function trimpMeaning(weeklyTrimp: number | null, range: WeeklyRange): string {
    if (weeklyTrimp === null || range === null) {
        return 'heart rate and time, added up over your last 7 days.';
    }

    return `over your last 7 days. a steady week for you sits around ${range.low} to ${range.high}.`;
}

/** Same shape as {@link trimpMeaning}, for how varied the week was. */
function monotonyMeaning(monotony: number | null, range: WeeklyRange): string {
    if (monotony === null) {
        return "how varied your training's been. every run at the same effort pushes this up, so mix in an easy day to bring it down.";
    }

    if (range === null) {
        return "how varied your training's been over your last 7 days.";
    }

    return `over your last 7 days. a steady week for you sits around ${range.low.toFixed(1)} to ${range.high.toFixed(1)}.`;
}

/** Same shape as {@link trimpMeaning}, for the week's total cost. */
function strainMeaning(strain: number | null, range: WeeklyRange): string {
    if (strain === null) {
        return "the week's effort multiplied by how varied it was, the total cost you're carrying.";
    }

    if (range === null) {
        return "the week's effort multiplied by how varied it was, over your last 7 days.";
    }

    return `over your last 7 days. a steady week for you sits around ${range.low} to ${range.high}.`;
}

function dateParts(iso: string): { day: number; month: string; year: string } {
    const [year, month, day] = iso.split('-');

    return { day: Number(day), month: ID_MONTH_SHORT[Number(month) - 1], year };
}

function dateRangeLabel(
    range: WeekComparisonPayload['date_ranges']['this_week'],
): string {
    if (range.start === range.end) return formatShortDateId(range.end);

    const start = dateParts(range.start);
    const end = dateParts(range.end);

    if (start.year !== end.year)
        return `${formatShortDateId(range.start)}–${formatShortDateId(range.end)}`;
    if (start.month === end.month)
        return `${start.day}–${end.day} ${end.month} ${end.year}`;

    return `${start.day} ${start.month}–${end.day} ${end.month} ${end.year}`;
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
    const {
        this_week_km,
        last_week_km,
        this_week_runs,
        last_week_runs,
        date_ranges,
    } = weekComparison;

    return (
        <section className={className}>
            <Eyebrow as="h2" token="small" tone="ink-2">
                vs last week
            </Eyebrow>
            <p className="mt-1 text-xs text-text-3">
                this week · {dateRangeLabel(date_ranges.this_week)}, last week ·{' '}
                {dateRangeLabel(date_ranges.last_week)}
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
                        ? `${last_week_km.toFixed(1)} km last week`
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
                        ? `${last_week_runs} last week`
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
                            load balance as of{' '}
                            {formatShortDateId(date_ranges.load.end)}
                        </span>
                        {load.form_status === null ? (
                            <Chip>learning</Chip>
                        ) : (
                            <span className="inline-flex items-center gap-2.5">
                                <FormChip status={load.form_status} />
                                <span className="font-mono text-xs text-text-3">
                                    {formatSignedForm(load.form)}
                                </span>
                            </span>
                        )}
                    </div>
                    <p className="text-sm leading-relaxed text-foreground">
                        {load.form_status === null
                            ? warmUpLine(
                                  load.form_known_from,
                                  date_ranges.load.end,
                              )
                            : formStatusMeaning(load.form_status)}
                    </p>
                    <hr className="border-dashed border-border" />
                    <p className="text-xs text-text-3">
                        last 7 days · {dateRangeLabel(date_ranges.load)}
                    </p>
                    <div className="grid grid-cols-3 gap-2">
                        <StatTile
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
                        <StatTile
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
                        <StatTile
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
