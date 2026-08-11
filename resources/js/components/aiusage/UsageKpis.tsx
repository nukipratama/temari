import type { PreviousTotals, UsageTotals } from '@/pages/AiUsage/types';

import KpiTile from '@/components/dashboard/KpiTile';
import { fmt, formatCost } from '@/pages/AiUsage/helpers';

interface UsageKpisProps {
    totals: UsageTotals;
    previousTotals: PreviousTotals | null;
    currency: string;
}

export default function UsageKpis({
    totals,
    previousTotals,
    currency,
}: Readonly<UsageKpisProps>) {
    const promptShare =
        totals.total > 0 ? Math.round((totals.prompt / totals.total) * 100) : 0;
    const avgPerCall =
        totals.calls > 0 ? Math.round(totals.total / totals.calls) : 0;
    const truncatedShare =
        totals.calls > 0
            ? Math.round((totals.truncated_calls / totals.calls) * 100)
            : 0;

    return (
        <section className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <KpiTile
                label="Total Tokens"
                value={fmt(totals.total)}
                sub={
                    <>
                        {totals.calls} call{totals.calls === 1 ? '' : 's'}
                        <DeltaChip
                            current={totals.total}
                            previous={previousTotals?.total ?? null}
                        />
                    </>
                }
            />
            <KpiTile
                label="Estimated Cost"
                value={formatCost(totals.cost, currency)}
                sub={
                    <>
                        {`${fmt(avgPerCall)} tokens/call`}
                        <DeltaChip
                            current={totals.cost}
                            previous={previousTotals?.cost ?? null}
                        />
                    </>
                }
            />
            <KpiTile
                label="Prompt"
                value={fmt(totals.prompt)}
                sub={`${promptShare}% of total`}
            />
            <KpiTile
                label="Truncated"
                value={`${truncatedShare}%`}
                sub={`${totals.truncated_calls} of ${totals.calls} call${totals.calls === 1 ? '' : 's'}`}
                tone={truncatedShare > 1 ? 'alert' : 'neutral'}
            />
        </section>
    );
}

/**
 * Small "vs previous period" delta next to a KPI. Hidden when there is no
 * comparable prior window (range=all) or the prior window had no data.
 */
function DeltaChip({
    current,
    previous,
}: Readonly<{ current: number; previous: number | null }>) {
    if (previous === null) {
        return null;
    }
    if (previous <= 0) {
        return current > 0 ? (
            <span className="ml-1.5 text-ink-3">· new</span>
        ) : null;
    }

    const pct = Math.round(((current - previous) / previous) * 100);
    let arrow = '·';
    if (pct > 0) {
        arrow = '▲';
    } else if (pct < 0) {
        arrow = '▼';
    }

    return (
        <span className="ml-1.5 text-ink-3">
            {arrow} {Math.abs(pct)}% vs prev
        </span>
    );
}
