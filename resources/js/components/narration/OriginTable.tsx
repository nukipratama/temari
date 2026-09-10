import { CirclePlay } from 'lucide-react';

import type { OriginRow } from '@/pages/Narration/types';

import EmptyState from '@/components/narration/EmptyState';
import DataTable, { Td } from '@/components/ui/DataTable';
import { fmt, formatCost } from '@/pages/Narration/helpers';

const COLUMNS = ['origin', 'calls', 'prompt', 'completion', 'total', 'Cost'];

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
            icon={CirclePlay}
            title="by origin"
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
