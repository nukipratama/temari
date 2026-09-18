import { ListOrdered } from 'lucide-react';

import type { CostChart, UsageRow } from '@/pages/Narration/types';

import { band } from '@/components/narration/chartBands';
import SectionHeading from '@/components/SectionHeading';
import { Card } from '@/components/ui/card';
import { fmt, formatCost } from '@/pages/Narration/helpers';

interface NarratorRankingProps {
    chart: CostChart;
    byKind: UsageRow[];
    currency: string;
}

/**
 * Ranked cost-and-calls list beside the daily chart, replacing the chart's own
 * wrapping legend. `chart.kinds` is already cost-descending and shares its
 * band colours with the stacked bars via {@link band}; tokens and calls are
 * joined in from the breakdown rows the chart itself has no need of, so a kind
 * that is most of the spend on a handful of calls reads differently from one
 * that got there on hundreds.
 */
export default function NarratorRanking({
    chart,
    byKind,
    currency,
}: Readonly<NarratorRankingProps>) {
    const rowsByKind = new Map(
        byKind.map((row) => [
            row.kind,
            { calls: row.calls, tokens: row.total },
        ]),
    );

    return (
        <div>
            <SectionHeading
                icon={ListOrdered}
                title="by narrator"
                subtitle="Cost and calls, ranked by spend."
                tone="accent"
            />

            <Card className="mt-4 bg-popover px-4 py-4">
                {chart.kinds.length === 0 ? (
                    <p className="py-6 text-center text-sm text-text-3">
                        No narrator has billed yet. The ranking fills in as
                        kinds appear.
                    </p>
                ) : (
                    <ul>
                        {chart.kinds.map((kind, index) => {
                            const stats = rowsByKind.get(kind.kind) ?? null;

                            return (
                                <li
                                    key={kind.kind}
                                    className="flex flex-col gap-0.5 border-b border-border/45 py-1.5 text-sm last:border-b-0"
                                >
                                    <div className="flex items-center gap-2">
                                        <span
                                            aria-hidden
                                            className={`h-2.5 w-2.5 shrink-0 rounded-sm ${band(index)}`}
                                        />
                                        <span className="min-w-0 flex-1 truncate text-text-2">
                                            {kind.label}
                                        </span>
                                        <span className="font-mono text-sm font-semibold text-foreground tabular-nums">
                                            {formatCost(kind.cost, currency)}
                                        </span>
                                    </div>
                                    <div className="pl-[18px] font-mono text-xs text-text-3 tabular-nums">
                                        {stats === null
                                            ? '—'
                                            : `${fmt(stats.tokens)} tok · ${fmt(stats.calls)} calls`}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </Card>
        </div>
    );
}
