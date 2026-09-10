import { ChartBar } from 'lucide-react';

import type { AthleteRow, CostChart } from '@/pages/Narration/types';

import EmptyState from '@/components/narration/EmptyState';
import SectionHeading from '@/components/SectionHeading';
import { Card } from '@/components/ui/card';
import {
    athleteLabel,
    formatCost,
    formatDayLabel,
    formatDayLabelShort,
} from '@/pages/Narration/helpers';

interface CostChartProps {
    chart: CostChart;
    currency: string;
    athletes: AthleteRow[];
    selected: number | null;
    onSelect: (athlete: number | null) => void;
}

/**
 * Stack colours, in the chart's own order (most expensive kind first). Cycled
 * rather than mapped per kind: the set of kinds in range is open, and the
 * legend beside the chart is what names each band.
 */
const BANDS = [
    'bg-horizon',
    'bg-leaf',
    'bg-ember',
    'bg-citrus',
    'bg-rarity-rare',
    'bg-mood-easy',
    'bg-mood-wobbly',
    'bg-mood-blazing',
] as const;

function band(index: number): string {
    return BANDS[index % BANDS.length];
}

export default function CostChart({
    chart,
    currency,
    athletes,
    selected,
    onSelect,
}: Readonly<CostChartProps>) {
    const { days, kinds } = chart;
    const peak = Math.max(...days.map((d) => d.cost), 0);
    const total = days.reduce((sum, d) => sum + d.cost, 0);

    return (
        <section className="mt-10">
            <SectionHeading
                icon={ChartBar}
                title="daily cost"
                subtitle="What each day cost, stacked by the narrator that billed it."
                tone="accent"
            />

            <Card className="mt-4 bg-popover px-4 py-4">
                <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
                    <label
                        htmlFor="athlete-filter"
                        className="flex flex-col gap-1 font-mono text-xs font-bold uppercase tracking-wider text-text-2"
                    >
                        athlete
                        <select
                            id="athlete-filter"
                            value={selected === null ? '' : String(selected)}
                            onChange={(e) =>
                                onSelect(
                                    e.target.value === ''
                                        ? null
                                        : Number(e.target.value),
                                )
                            }
                            className="focus-ring rounded-xl border border-border bg-muted px-3 py-2 text-sm font-medium text-foreground focus:border-leaf"
                        >
                            <option value="">All athletes</option>
                            {athletes.map((row) => (
                                <option key={row.user_id} value={row.user_id}>
                                    {athleteLabel(row.user_name, row.user_id)}
                                </option>
                            ))}
                        </select>
                    </label>

                    <span className="text-sm text-text-2">
                        Estimated cost:{' '}
                        <span className="font-semibold text-foreground tabular-nums">
                            {formatCost(total, currency)}
                        </span>
                    </span>
                </div>

                {days.length === 0 ? (
                    <EmptyState />
                ) : (
                    <>
                        <div className="flex gap-1.5" style={{ height: 180 }}>
                            {days.map((day) => (
                                <div
                                    key={day.day}
                                    className="group relative flex flex-1 flex-col justify-end"
                                    style={{ minWidth: 0 }}
                                >
                                    <div className="pointer-events-none absolute bottom-full left-1/2 z-10 mb-2 -translate-x-1/2 whitespace-nowrap rounded-lg border border-border bg-popover px-3 py-2 text-xs opacity-0 shadow-e2 transition-opacity group-hover:opacity-100">
                                        <div className="font-semibold text-foreground">
                                            {formatDayLabel(day.day)}
                                        </div>
                                        <div className="text-text-2 tabular-nums">
                                            {formatCost(day.cost, currency)}
                                        </div>
                                    </div>

                                    <div
                                        className="flex w-full flex-col-reverse overflow-hidden rounded-t-sm"
                                        style={{
                                            height: `${peak > 0 ? Math.max((day.cost / peak) * 100, 2) : 2}%`,
                                        }}
                                        aria-label={`${formatDayLabel(day.day)}: ${formatCost(day.cost, currency)}`}
                                    >
                                        {kinds.map((kind, index) => {
                                            const cost =
                                                day.byKind[kind.kind] ?? 0;
                                            if (cost <= 0 || day.cost <= 0) {
                                                return null;
                                            }

                                            return (
                                                <div
                                                    key={kind.kind}
                                                    className={band(index)}
                                                    style={{
                                                        height: `${(cost / day.cost) * 100}%`,
                                                    }}
                                                />
                                            );
                                        })}
                                    </div>
                                </div>
                            ))}
                        </div>

                        <div className="mt-2 flex gap-1.5">
                            {days.map((day) => (
                                <div
                                    key={day.day}
                                    className="flex-1 text-center"
                                >
                                    <span
                                        className="text-meta block truncate"
                                        title={formatDayLabel(day.day)}
                                    >
                                        {days.length <= 14
                                            ? formatDayLabelShort(day.day)
                                            : formatDayLabel(day.day)}
                                    </span>
                                </div>
                            ))}
                        </div>

                        <ul className="mt-4 flex flex-wrap gap-x-4 gap-y-2">
                            {kinds.map((kind, index) => (
                                <li
                                    key={kind.kind}
                                    className="flex items-center gap-1.5 text-xs text-text-2"
                                >
                                    <span
                                        aria-hidden
                                        className={`h-2.5 w-2.5 rounded-sm ${band(index)}`}
                                    />
                                    <span>{kind.label}</span>
                                    <span className="font-mono text-text-3 tabular-nums">
                                        {formatCost(kind.cost, currency)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </Card>
        </section>
    );
}
