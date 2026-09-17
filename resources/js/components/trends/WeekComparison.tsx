import type {
    FormStatus,
    TrainingLoad,
    WeekComparison as WeekComparisonPayload,
} from '@/types/inertia';

import Card from '@/components/ui/LegacyCard';
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

const TRIMP_MEANING = 'heart rate and time, added up over seven days.';
const MONOTONY_MEANING =
    'how same-y the week was. every run at one effort pushes it up.';
const STRAIN_MEANING =
    "the week's effort multiplied by how same-y it was, the total cost carried.";

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
 * "vs last week" — the widest of the three comparisons, since it carries
 * both halves of the page's question at once: km and runs are the gain,
 * form/TRIMP/monotony/strain are the cost, separated by a rule inside one
 * card (direction A, #967).
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
            <h2 className="font-serif text-headline-sm text-foreground">
                vs last week
            </h2>
            <p className="mt-1 text-xs text-text-3">
                through {weekday}, so it&apos;s the same slice of both weeks.
            </p>
            <Card className="mt-2.5">
                <div className="grid grid-cols-2 gap-3">
                    <Stat
                        label="km this week"
                        value={
                            this_week_km !== null
                                ? this_week_km.toFixed(1)
                                : '—'
                        }
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
                        label="runs this week"
                        value={
                            this_week_runs !== null
                                ? String(this_week_runs)
                                : '—'
                        }
                        delta={
                            this_week_runs !== null &&
                            last_week_runs !== null ? (
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
                </div>

                <hr className="my-4 border-border" />

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
                        <hr className="border-border" />
                        <div className="grid grid-cols-3 gap-3">
                            <Stat
                                size="sm"
                                label="weekly TRIMP"
                                value={
                                    load.weekly_trimp !== null
                                        ? String(Math.round(load.weekly_trimp))
                                        : '—'
                                }
                                sub={TRIMP_MEANING}
                            />
                            <Stat
                                size="sm"
                                label="monotony"
                                value={
                                    load.monotony !== null
                                        ? load.monotony.toFixed(1)
                                        : '—'
                                }
                                sub={MONOTONY_MEANING}
                            />
                            <Stat
                                size="sm"
                                label="strain"
                                value={
                                    load.strain !== null
                                        ? String(Math.round(load.strain))
                                        : '—'
                                }
                                sub={STRAIN_MEANING}
                            />
                        </div>
                    </div>
                )}
            </Card>
        </section>
    );
}
