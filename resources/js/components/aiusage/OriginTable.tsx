import type { OriginRow } from '@/pages/AiUsage/types';

import EmptyState from '@/components/aiusage/EmptyState';
import DataTable, { Td } from '@/components/ui/DataTable';
import { fmt, formatCost } from '@/pages/AiUsage/helpers';

const COLUMNS = ['Origin', 'Calls', 'Prompt', 'Completion', 'Total', 'Cost'];

/**
 * Spend by what started the call. The per-kind table answers "which narrator",
 * which cannot distinguish an ingest cascade from a user's "Reread" or the
 * hourly self-heal on the same narrator.
 */
export default function OriginTable({
    rows,
    currency,
}: Readonly<{ rows: OriginRow[]; currency: string }>) {
    return (
        <DataTable
            icon="mdi:play-circle-outline"
            title="Breakdown per Origin"
            subtitle="What started the call, as opposed to which narrator answered it."
            tone="accent"
            columns={COLUMNS}
            minWidth={560}
            rows={rows}
            rowKey={(row) => row.origin}
            emptyState={<EmptyState />}
            renderRow={(row) => (
                <>
                    <Td className="font-medium text-foreground">{row.label}</Td>
                    <Td>{fmt(row.calls)}</Td>
                    <Td>{fmt(row.prompt)}</Td>
                    <Td>{fmt(row.completion)}</Td>
                    <Td className="font-semibold text-foreground">
                        {fmt(row.total)}
                    </Td>
                    <Td className="font-semibold text-foreground">
                        {formatCost(row.cost, currency)}
                    </Td>
                </>
            )}
        />
    );
}
