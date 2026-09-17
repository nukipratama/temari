import type { Budget, CostChart } from '@/pages/Narration/types';

import ProgressBar from '@/components/ui/ProgressBar';
import { cn } from '@/lib/cn';
import { formatCost, median } from '@/pages/Narration/helpers';

interface TodayPanelProps {
    budget: Budget;
    chart: CostChart;
}

/**
 * Today's spend at display size against the app-wide ceiling, with four
 * labelled references beside it so "is that normal" is answered by
 * comparison rather than a lone percentage. Absorbs the old CeilingHeader;
 * the capped-athlete count, pause reason and recover action moved to
 * {@link FaultStrip}, since those are faults, not this panel's own reading.
 */
export default function TodayPanel({
    budget,
    chart,
}: Readonly<TodayPanelProps>) {
    const { todayCost, totalCeiling, currency } = budget;
    const hasCeiling = totalCeiling !== null && totalCeiling > 0;
    const ratio = hasCeiling ? todayCost / totalCeiling : 0;
    const over = hasCeiling && todayCost > totalCeiling;

    const days = chart.days;
    const medianCost = median(days.map((day) => day.cost));
    const yesterday = days.length > 1 ? days[days.length - 2].cost : null;
    const busiest =
        days.length > 1
            ? Math.max(...days.slice(0, -1).map((day) => day.cost))
            : null;

    return (
        <section className="mt-6 rounded-md border border-border bg-popover pad-card shadow-e1">
            <div className="grid gap-5 md:grid-cols-[minmax(220px,300px)_1fr] md:items-start">
                <div>
                    <span className="text-label-micro text-text-3">
                        today, app-wide
                    </span>
                    <div
                        className={cn(
                            'mt-1 font-mono text-display-xs font-bold tabular-nums',
                            over ? 'text-ember-ink' : 'text-foreground',
                        )}
                    >
                        {formatCost(todayCost, currency)}
                    </div>
                    {hasCeiling ? (
                        <>
                            <ProgressBar
                                value={ratio}
                                tone={over ? 'sky' : 'horizon'}
                                ariaLabel={`today against the app-wide ceiling: ${Math.round(ratio * 100)}% used`}
                                className="mt-3"
                            />
                            <p className="mt-2 font-mono text-xs text-text-2 tabular-nums">
                                {Math.round(ratio * 100)}% of the app-wide
                                ceiling
                            </p>
                        </>
                    ) : (
                        <p className="mt-3 text-xs text-text-3">
                            No app-wide ceiling set.
                        </p>
                    )}
                </div>

                <div className="grid grid-cols-2 gap-x-4 gap-y-4 sm:grid-cols-4">
                    <Fact
                        label="app-wide ceiling"
                        value={
                            hasCeiling
                                ? formatCost(totalCeiling, currency)
                                : 'none set'
                        }
                        note="enforced stop"
                    />
                    <Fact
                        label="median day, 30d"
                        value={
                            days.length > 0
                                ? formatCost(medianCost, currency)
                                : '—'
                        }
                        note={
                            days.length > 0 ? 'the normal day' : 'no days yet'
                        }
                    />
                    <Fact
                        label="yesterday"
                        value={
                            yesterday === null
                                ? '—'
                                : formatCost(yesterday, currency)
                        }
                        note="one day back"
                    />
                    <Fact
                        label="busiest day before today"
                        value={
                            busiest === null
                                ? '—'
                                : formatCost(busiest, currency)
                        }
                        note="the worst of the other days"
                    />
                </div>
            </div>

            <p className="mt-4 text-xs text-text-3">
                Estimate uses list price from config, not the final bill.
            </p>
        </section>
    );
}

function Fact({
    label,
    value,
    note,
}: Readonly<{ label: string; value: string; note: string }>) {
    return (
        <div className="flex min-w-0 flex-col gap-0.5">
            <span className="text-label-micro text-text-3">{label}</span>
            <span className="font-mono text-sm font-bold text-foreground tabular-nums">
                {value}
            </span>
            <span className="text-[0.6875rem] text-text-3">{note}</span>
        </div>
    );
}
