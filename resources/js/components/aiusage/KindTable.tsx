import type { UsageRow } from '@/pages/AiUsage/types';

import EmptyState from '@/components/aiusage/EmptyState';
import DataTable, { Td } from '@/components/ui/DataTable';
import ProgressBar from '@/components/ui/ProgressBar';
import { cn } from '@/lib/cn';
import { fmt, formatCost } from '@/pages/AiUsage/helpers';

const COLUMNS = [
    'Kind',
    'Calls',
    'Prompt',
    'Completion',
    'Total',
    'Cost',
    'Latency (avg/max)',
    'Truncated',
];

export default function KindTable({
    rows,
    grandTotal,
    currency,
}: Readonly<{ rows: UsageRow[]; grandTotal: number; currency: string }>) {
    return (
        <DataTable
            icon="mdi:shape"
            title="Breakdown per Kind"
            subtitle="Analysis kinds eating the most tokens."
            tone="brand"
            columns={COLUMNS}
            minWidth={760}
            rows={rows}
            rowKey={(row) => row.kind}
            emptyState={<EmptyState />}
            renderRow={(row) => (
                <KindCells
                    row={row}
                    grandTotal={grandTotal}
                    currency={currency}
                />
            )}
        />
    );
}

/**
 * The agent line: how many model turns a block takes, how much of its input the
 * cache absorbed, and how much of its output went on thinking rather than
 * answering. Rendered under the kind name rather than as three more columns,
 * which the table has no room for.
 */
function AgentSummary({ row }: Readonly<{ row: UsageRow }>) {
    // typeof rather than a null check: these three arrive over the wire and are
    // absent entirely on a payload rendered before they existed.
    const parts = [
        typeof row.avg_steps === 'number'
            ? `${row.avg_steps.toFixed(1)} steps`
            : null,
        typeof row.cached_pct === 'number'
            ? `${row.cached_pct.toFixed(0)}% cache`
            : null,
        typeof row.reasoning_pct === 'number'
            ? `${row.reasoning_pct.toFixed(0)}% reasoning`
            : null,
    ].filter((part): part is string => part !== null);

    if (parts.length === 0) {
        return null;
    }

    return (
        <div className="mt-0.5 font-mono text-[11px] text-ink-2">
            {parts.join(' · ')}
        </div>
    );
}

function KindCells({
    row,
    grandTotal,
    currency,
}: Readonly<{ row: UsageRow; grandTotal: number; currency: string }>) {
    const share = grandTotal > 0 ? row.total / grandTotal : 0;
    const truncatedRate =
        row.calls > 0 ? (row.truncated_calls / row.calls) * 100 : 0;
    const latencyLabel =
        row.avg_latency_ms === null
            ? '—'
            : `${fmt(Math.round(row.avg_latency_ms / 1000))} / ${fmt(Math.round((row.max_latency_ms ?? row.avg_latency_ms) / 1000))} s`;

    return (
        <>
            <td className="px-5 py-3 font-medium text-ink">
                <div>{row.kind}</div>
                <AgentSummary row={row} />
                <ProgressBar
                    value={share}
                    size="sm"
                    ariaLabel={`${(share * 100).toFixed(1)}% of total`}
                    className="mt-1 max-w-[160px]"
                />
            </td>
            <Td>{fmt(row.calls)}</Td>
            <Td>{fmt(row.prompt)}</Td>
            <Td>{fmt(row.completion)}</Td>
            <Td className="font-semibold text-ink">{fmt(row.total)}</Td>
            <Td className="font-semibold text-ink">
                {formatCost(row.cost, currency)}
            </Td>
            <Td>{latencyLabel}</Td>
            <td
                className={cn(
                    'px-5 py-3 font-medium',
                    truncatedRate > 1 ? 'text-mood-gassed-ink' : 'text-ink-2',
                )}
            >
                {row.truncated_calls > 0
                    ? `${row.truncated_calls} (${truncatedRate.toFixed(1)}%)`
                    : '—'}
            </td>
        </>
    );
}
