import { Server } from 'lucide-react';

import type { DeploymentRow } from '@/pages/Narration/types';

import EmptyState from '@/components/narration/EmptyState';
import DataTable, { Td } from '@/components/ui/DataTable';
import { fmt, formatCost } from '@/pages/Narration/helpers';

const COLUMNS = [
    'deployment',
    'price in/out /1M',
    'calls',
    'prompt',
    'completion',
    'total',
    'cost',
];

export default function DeploymentTable({
    rows,
    currency,
}: Readonly<{ rows: DeploymentRow[]; currency: string }>) {
    return (
        <DataTable
            icon={Server}
            title="by deployment"
            subtitle="Cost per Azure model called."
            tone="accent"
            columns={COLUMNS}
            minWidth={640}
            rows={rows}
            rowKey={(row) => row.deployment}
            emptyState={<EmptyState />}
            renderRow={(row) => (
                <>
                    <Td className="font-medium text-foreground">
                        {row.deployment}
                    </Td>
                    <Td className="whitespace-nowrap text-text-2">
                        {row.inputPer1m === null || row.outputPer1m === null
                            ? '—'
                            : `${formatCost(row.inputPer1m, currency)} / ${formatCost(row.outputPer1m, currency)}`}
                    </Td>
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
