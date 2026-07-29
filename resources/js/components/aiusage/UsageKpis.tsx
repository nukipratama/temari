import KpiTile from '@/components/dashboard/KpiTile';
import { fmt, formatCost } from '@/pages/AiUsage/helpers';
import type { PreviousTotals, UsageTotals } from '@/pages/AiUsage/types';

interface UsageKpisProps {
    totals: UsageTotals;
    previousTotals: PreviousTotals | null;
    currency: string;
}

export default function UsageKpis({ totals, previousTotals, currency }: Readonly<UsageKpisProps>) {
    const promptShare = totals.total > 0 ? Math.round((totals.prompt / totals.total) * 100) : 0;
    const avgPerCall = totals.calls > 0 ? Math.round(totals.total / totals.calls) : 0;
    const truncatedShare = totals.calls > 0 ? Math.round((totals.truncated_calls / totals.calls) * 100) : 0;

    return (
        <section className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <KpiTile
                label="Total Tokens"
                value={fmt(totals.total)}
                sub={
                    <>
                        {totals.calls} call
                        <DeltaChip current={totals.total} previous={previousTotals?.total ?? null} />
                    </>
                }
            />
            <KpiTile
                label="Estimasi Biaya"
                value={formatCost(totals.cost, currency)}
                sub={
                    <>
                        {`${fmt(avgPerCall)} token/call`}
                        <DeltaChip current={totals.cost} previous={previousTotals?.cost ?? null} />
                    </>
                }
            />
            <KpiTile label="Prompt" value={fmt(totals.prompt)} sub={`${promptShare}% dari total`} />
            <KpiTile
                label="Terpotong"
                value={`${truncatedShare}%`}
                sub={`${totals.truncated_calls} dari ${totals.calls} call`}
                tone={truncatedShare > 1 ? 'alert' : 'neutral'}
            />
        </section>
    );
}

/**
 * Small "vs periode sebelumnya" delta next to a KPI. Hidden when there is no
 * comparable prior window (range=all) or the prior window had no data.
 */
function DeltaChip({ current, previous }: Readonly<{ current: number; previous: number | null }>) {
    if (previous === null) {
        return null;
    }
    if (previous <= 0) {
        return current > 0 ? <span className="ml-1.5 text-ink-3">· baru</span> : null;
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
            {arrow} {Math.abs(pct)}% vs sblm
        </span>
    );
}
